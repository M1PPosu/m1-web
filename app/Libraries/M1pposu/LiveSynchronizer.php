<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Libraries\Search\ScoreSearch;
use App\Models\Beatmap;
use App\Models\M1pposuExternalTeam;
use App\Models\M1pposuExternalUser;
use App\Models\TeamMember;
use App\Models\TeamStatistics;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class LiveSynchronizer
{
    private const CONNECTION = 'm1pposu-private-server-source';
    private const LOCK_SECONDS = 300;
    private const PROCESS_HISTORY_TABLE = 'solo_scores_process_history';
    private const SCORE_COMMAND_CHUNK = 1000;
    private const SCORE_RETRY_DELAYS_MICROSECONDS = [0, 250_000, 1_000_000, 3_000_000];

    private bool $sourceConfigured = false;

    public function __construct(private readonly ScoreDerivedProjector $scoreDerivedProjector)
    {
    }

    public function initialScoreCursor(): int
    {
        return (int) (DB::table('m1pposu_external_scores')
            ->where('backend', $this->backend())
            ->selectRaw('MAX(CAST(external_score_id AS UNSIGNED)) AS max_id')
            ->value('max_id') ?? 0);
    }

    public function initialUserCursor(): int
    {
        return (int) (DB::table('m1pposu_external_users')
            ->where('backend', $this->backend())
            ->selectRaw('MAX(CAST(external_user_id AS UNSIGNED)) AS max_id')
            ->value('max_id') ?? 0);
    }

    public function sourceScoreIdsAfter(int $afterId, int $limit): array
    {
        $this->configureSource();

        return DB::connection(self::CONNECTION)
            ->table('scores')
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function recentUnprojectedScoreIds(int $throughId, int $window, int $limit): array
    {
        $this->configureSource();

        $sourceIds = DB::connection(self::CONNECTION)
            ->table('scores')
            ->join('users', 'users.id', '=', 'scores.userid')
            ->join('maps', 'maps.md5', '=', 'scores.map_md5')
            ->whereBetween('scores.id', [max(1, $throughId - $window + 1), $throughId])
            ->whereIn('scores.status', [0, 1, 2])
            ->whereIn('scores.mode', SourceMode::supportedSourceModes())
            ->whereRaw('(users.priv & 1) = 1')
            ->orderBy('scores.id')
            ->pluck('scores.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($sourceIds === []) {
            return [];
        }

        $projectedIds = DB::table('m1pposu_external_scores')
            ->where('backend', $this->backend())
            ->whereIn('external_score_id', array_map('strval', $sourceIds))
            ->pluck('external_score_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_slice(array_values(array_diff($sourceIds, $projectedIds)), 0, $limit);
    }

    public function sourceUserIdsAfter(int $afterId, int $limit): array
    {
        $this->configureSource();

        return DB::connection(self::CONNECTION)
            ->table('users')
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function syncScoreIds(array $scoreIds): array
    {
        return $this->withProjectionLock(function () use ($scoreIds) {
            $scoreIds = $this->positiveIds($scoreIds);
            if ($scoreIds === []) {
                return $this->emptySummary();
            }

            $sourceScores = $this->sourceScoresWithRetry($scoreIds);

            $userIds = $sourceScores->pluck('userid')->map(fn ($id) => (int) $id)->unique()->values()->all();
            $this->syncUsersUnlocked($userIds);

            $sourceMaps = DB::connection(self::CONNECTION)
                ->table('maps')
                ->select(['id', 'md5'])
                ->whereIn('md5', $sourceScores->pluck('map_md5')->unique()->all())
                ->get();
            $mapIds = $sourceMaps->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            $this->syncMapsUnlocked($mapIds);

            $projectedRelatedScoreIds = $this->projectedRelatedScoreIds($sourceScores);
            $allScoreIds = array_values(array_unique([
                ...$scoreIds,
                ...$projectedRelatedScoreIds,
            ]));
            $this->syncScoresUnlocked($allScoreIds);
            $projectedScoreIds = $this->projectedExternalScoreIds($allScoreIds);
            $missingScoreIds = array_values(array_diff($scoreIds, $projectedScoreIds));
            if ($missingScoreIds !== []) {
                throw new RuntimeException(
                    'Score projection completed without mappings for source scores: '.implode(', ', $missingScoreIds).'.'
                );
            }
            $derived = $this->scoreDerivedProjector->projectScoreIds($scoreIds);

            $sourceModes = $sourceScores->pluck('mode')->map(fn ($mode) => (int) $mode)->unique()->values()->all();
            $this->refreshRankingsForSourceModes($sourceModes);
            $this->recalculateTeams($userIds, $sourceModes);

            return [
                'source_scores' => $sourceScores->count(),
                'users' => count($userIds),
                'maps' => count($mapIds),
                'projected_scores' => count($projectedScoreIds),
                ...$derived,
            ];
        });
    }

    public function syncUserIds(array $userIds): array
    {
        return $this->withProjectionLock(function () use ($userIds) {
            $userIds = $this->positiveIds($userIds);
            $this->syncUsersUnlocked($userIds);
            $this->runCommand('m1pposu:rankings:refresh');
            $this->recalculateTeams($userIds, SourceMode::supportedSourceModes());

            return ['users' => count($userIds)];
        });
    }

    public function syncMapStatusChange(array $mapIds, ?string $type): array
    {
        $summary = $this->syncMapIds($mapIds);

        if (in_array($type, ['rank', 'love'], true)) {
            $summary['beatmapsets_ranked_at_touched'] = $this->touchRankedBeatmapsets($mapIds);
        }

        return $summary;
    }

    public function syncMapIds(array $mapIds): array
    {
        return $this->withProjectionLock(function () use ($mapIds) {
            $mapIds = $this->positiveIds($mapIds);
            if ($mapIds === []) {
                return ['maps' => 0, 'projected_scores' => 0];
            }

            $this->configureSource();
            $sourceMaps = DB::connection(self::CONNECTION)
                ->table('maps')
                ->select(['id', 'md5'])
                ->whereIn('id', $mapIds)
                ->get();

            $this->syncMapsUnlocked($sourceMaps->pluck('id')->map(fn ($id) => (int) $id)->all());

            $mappings = DB::table('m1pposu_external_scores')
                ->where('backend', $this->backend())
                ->whereIn('external_beatmap_md5', $sourceMaps->pluck('md5')->map(fn ($md5) => strtolower((string) $md5))->all())
                ->get(['external_score_id', 'source_mode']);
            $scoreIds = $mappings->pluck('external_score_id')->map(fn ($id) => (int) $id)->all();
            $this->syncScoresUnlocked($scoreIds);
            $this->refreshRankingsForSourceModes(
                $mappings->pluck('source_mode')->map(fn ($mode) => (int) $mode)->unique()->values()->all(),
            );

            return [
                'maps' => $sourceMaps->count(),
                'projected_scores' => count($scoreIds),
            ];
        });
    }

    public function syncWipe(int $userId, int $sourceMode): array
    {
        return $this->withProjectionLock(function () use ($userId, $sourceMode) {
            $this->configureSource();
            $this->syncUsersUnlocked([$userId]);

            $mappings = DB::table('m1pposu_external_scores')
                ->where('backend', $this->backend())
                ->where('external_user_id', (string) $userId)
                ->where('source_mode', $sourceMode)
                ->get(['id', 'score_id', 'external_score_id']);
            $externalScoreIds = $mappings->pluck('external_score_id')->map(fn ($id) => (int) $id)->all();
            $existingSourceIds = [];

            foreach (array_chunk($externalScoreIds, self::SCORE_COMMAND_CHUNK) as $chunk) {
                $existingSourceIds = [
                    ...$existingSourceIds,
                    ...DB::connection(self::CONNECTION)->table('scores')->whereIn('id', $chunk)->pluck('id')->map(fn ($id) => (int) $id)->all(),
                ];
            }

            $missingMappings = $mappings->reject(
                fn ($mapping) => in_array((int) $mapping->external_score_id, $existingSourceIds, true),
            );
            $scoreIds = $missingMappings->pluck('score_id')->map(fn ($id) => (int) $id)->all();

            if ($scoreIds !== []) {
                DB::transaction(function () use ($missingMappings, $scoreIds) {
                    DB::table('m1pposu_external_scores')->whereIn('id', $missingMappings->pluck('id')->all())->delete();
                    DB::table(self::PROCESS_HISTORY_TABLE)->whereIn('score_id', $scoreIds)->delete();
                    DB::table('scores')->whereIn('id', $scoreIds)->delete();
                });
                ScoreSearch::queueForIndex(null, $scoreIds);
            }

            $this->refreshRankingsForSourceModes([$sourceMode]);
            $this->recalculateTeams([$userId], [$sourceMode]);

            return ['users' => 1, 'scores_removed' => count($scoreIds)];
        });
    }

    public function syncClanChange(int $sourceUserId, int $sourceClanId, bool $deleted): array
    {
        return $this->withProjectionLock(function () use ($sourceUserId, $sourceClanId, $deleted) {
            $this->syncUsersUnlocked([$sourceUserId]);

            if ($sourceClanId > 0 && !$deleted) {
                $this->runCommand('m1pposu:sync:clans', ['--clan-id' => (string) $sourceClanId]);
            } elseif ($sourceClanId > 0) {
                $mapping = M1pposuExternalTeam::where('backend', $this->backend())
                    ->where('external_team_id', (string) $sourceClanId)
                    ->first();
                $team = $mapping?->team;
                if ($team !== null) {
                    $team->delete();
                }
                $mapping?->delete();
            } else {
                $webUserId = M1pposuExternalUser::where('backend', $this->backend())
                    ->where('external_user_id', (string) $sourceUserId)
                    ->value('user_id');
                if ($webUserId !== null) {
                    TeamMember::where('user_id', $webUserId)->delete();
                }
            }

            return [
                'users' => 1,
                'clans' => $sourceClanId > 0 ? 1 : 0,
                'deleted' => $deleted,
            ];
        });
    }

    private function syncUsersUnlocked(array $userIds): void
    {
        foreach ($this->positiveIds($userIds) as $userId) {
            $this->runCommand('m1pposu:sync:users', ['--external-id' => (string) $userId]);
        }
    }

    private function touchRankedBeatmapsets(array $mapIds): int
    {
        $mapIds = $this->positiveIds($mapIds);
        if ($mapIds === []) {
            return 0;
        }

        $this->configureSource();

        $beatmapsetIds = DB::connection(self::CONNECTION)
            ->table('maps')
            ->whereIn('id', $mapIds)
            ->pluck('set_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($beatmapsetIds === []) {
            return 0;
        }

        return DB::table('osu_beatmapsets')
            ->whereIn('beatmapset_id', $beatmapsetIds)
            ->where('approved', '>', 0)
            ->update(['approved_date' => now()->toDateTimeString()]);
    }

    private function syncMapsUnlocked(array $mapIds): void
    {
        foreach ($this->positiveIds($mapIds) as $mapId) {
            $this->runCommand('m1pposu:sync:maps', ['--beatmap-id' => (string) $mapId]);
        }
    }

    private function syncScoresUnlocked(array $scoreIds): void
    {
        foreach (array_chunk($this->positiveIds($scoreIds), self::SCORE_COMMAND_CHUNK) as $chunk) {
            $this->runCommand('m1pposu:sync:scores', [
                '--score-id' => array_map('strval', $chunk),
                '--include-failed' => true,
                '--fail-on-skip' => true,
            ]);
        }
    }

    private function sourceScoresWithRetry(array $scoreIds): Collection
    {
        $this->configureSource();
        $sourceScores = collect();

        foreach (self::SCORE_RETRY_DELAYS_MICROSECONDS as $delay) {
            if ($delay > 0) {
                usleep($delay);
            }

            $sourceScores = DB::connection(self::CONNECTION)
                ->table('scores')
                ->select(['id', 'userid', 'map_md5', 'mode'])
                ->whereIn('id', $scoreIds)
                ->orderBy('id')
                ->get();

            if ($sourceScores->count() === count($scoreIds)) {
                return $sourceScores;
            }
        }

        $foundIds = $sourceScores->pluck('id')->map(fn ($id) => (int) $id)->all();
        $missingIds = array_values(array_diff($scoreIds, $foundIds));

        throw new RuntimeException(
            'Source scores were not visible after bounded read-after-write retries: '.implode(', ', $missingIds).'.'
        );
    }

    private function projectedExternalScoreIds(array $scoreIds): array
    {
        return DB::table('m1pposu_external_scores')
            ->where('backend', $this->backend())
            ->whereIn('external_score_id', array_map('strval', $this->positiveIds($scoreIds)))
            ->pluck('external_score_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function projectedRelatedScoreIds(Collection $sourceScores): array
    {
        $ids = [];
        $tuples = [];

        foreach ($sourceScores as $score) {
            $key = "{$score->userid}:".strtolower((string) $score->map_md5).":{$score->mode}";
            $tuples[$key] = [
                'user_id' => (string) $score->userid,
                'map_md5' => strtolower((string) $score->map_md5),
                'mode' => (int) $score->mode,
            ];
        }

        foreach ($tuples as $tuple) {
            $ids = [
                ...$ids,
                ...DB::table('m1pposu_external_scores')
                    ->where('backend', $this->backend())
                    ->where('external_user_id', $tuple['user_id'])
                    ->where('external_beatmap_md5', $tuple['map_md5'])
                    ->where('source_mode', $tuple['mode'])
                    ->pluck('external_score_id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
            ];
        }

        return $ids;
    }

    private function refreshRankingsForSourceModes(array $sourceModes): void
    {
        $modes = [];
        foreach (array_unique(array_map('intval', $sourceModes)) as $sourceMode) {
            $mode = SourceMode::mode($sourceMode);
            if ($mode !== null) {
                $modes[$mode['ruleset'].':'.($mode['variant'] ?? 'standard')] = $mode;
            }
        }

        foreach ($modes as $mode) {
            $arguments = ['--mode' => $mode['ruleset']];
            if ($mode['variant'] !== null) {
                $arguments['--variant'] = $mode['variant'];
            }
            $this->runCommand('m1pposu:rankings:refresh', $arguments);
        }
    }

    private function recalculateTeams(array $sourceUserIds, array $sourceModes): void
    {
        $sourceUserIds = array_map('strval', $this->positiveIds($sourceUserIds));
        if ($sourceUserIds === []) {
            return;
        }

        $userIds = DB::table('m1pposu_external_users')
            ->where('backend', $this->backend())
            ->whereIn('external_user_id', $sourceUserIds)
            ->pluck('user_id')
            ->all();
        $teamIds = TeamMember::whereIn('user_id', $userIds)->pluck('team_id')->unique()->all();
        $modes = collect($sourceModes)
            ->map(fn ($sourceMode) => SourceMode::mode((int) $sourceMode))
            ->filter()
            ->map(fn ($mode) => [
                'ruleset_id' => Beatmap::modeInt($mode['ruleset']),
                'variant' => $mode['variant'] ?? '',
            ])
            ->unique(fn ($mode) => "{$mode['ruleset_id']}:{$mode['variant']}")
            ->values()
            ->all();

        if ($teamIds === [] || $modes === []) {
            return;
        }

        foreach ($modes as $mode) {
            foreach ($teamIds as $teamId) {
                TeamStatistics::firstOrCreate([
                    'team_id' => $teamId,
                    'ruleset_id' => $mode['ruleset_id'],
                    'variant' => $mode['variant'],
                ])->recalculate();
            }
        }
    }

    private function configureSource(): void
    {
        if ($this->sourceConfigured) {
            return;
        }

        $database = config('m1pposu.private_server.database');
        Config::set('database.connections.'.self::CONNECTION, [
            ...config('database.connections.mysql'),
            'host' => $database['host'],
            'port' => $database['port'],
            'database' => $database['database'],
            'username' => $database['username'],
            'password' => $database['password'],
        ]);
        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getPdo();
        $this->sourceConfigured = true;
    }

    private function withProjectionLock(callable $callback): array
    {
        $lock = Cache::lock('m1pposu:live:projection', self::LOCK_SECONDS);
        if (!$lock->get()) {
            throw new RuntimeException('Another private-server live projection batch is already running.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function runCommand(string $command, array $arguments = []): void
    {
        $output = new BufferedOutput();

        try {
            $exitCode = Artisan::call($command, ['--no-interaction' => true, ...$arguments], $output);
        } catch (Throwable $e) {
            throw new RuntimeException("{$command} failed: {$e->getMessage()}", previous: $e);
        }

        if ($exitCode !== 0) {
            $message = trim($output->fetch());
            throw new RuntimeException($message === '' ? "{$command} failed." : "{$command} failed: {$message}");
        }
    }

    private function positiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn ($id) => $id > 0,
        )));
    }

    private function backend(): string
    {
        return (string) (config('m1pposu.private_server.backend') ?: 'bancho-py-ex');
    }

    private function emptySummary(): array
    {
        return [
            'source_scores' => 0,
            'users' => 0,
            'maps' => 0,
            'projected_scores' => 0,
        ];
    }
}
