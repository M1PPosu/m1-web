<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\HasM1pposuCommandLock;
use App\Libraries\M1pposu\SourceMode;
use App\Libraries\Search\ScoreSearch;
use App\Models\Beatmap;
use App\Models\Beatmapset;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

class M1pposuSyncScores extends Command
{
    use HasM1pposuCommandLock;

    private const CONNECTION = 'm1pposu-private-server-source';
    private const DEFAULT_CHUNK_SIZE = 1000;
    private const MAX_BATCH_LIMIT = 10000;
    private const MAX_CHUNK_SIZE = 5000;

    private const SOURCE_SCORE_COLUMNS = [
        'id',
        'map_md5',
        'score',
        'pp',
        'acc',
        'max_combo',
        'mods',
        'n300',
        'n100',
        'n50',
        'nmiss',
        'ngeki',
        'nkatu',
        'grade',
        'status',
        'mode',
        'play_time',
        'time_elapsed',
        'userid',
        'perfect',
    ];

    private const STATUS_FAILED = 0;
    private const STATUS_SUBMITTED = 1;
    private const STATUS_BEST = 2;
    private const SUPPORTED_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_BEST,
    ];

    protected $description = 'Sync private-server scores needed for user profile recent and top play surfaces.';

    protected $signature = 'm1pposu:sync:scores
        {--username= : Sync scores for one source username}
        {--external-user-id= : Sync scores for one source users.id}
        {--limit= : Maximum source scores to scan}
        {--all : Sync every source score using chunked processing}
        {--chunk-size= : Source scores to scan per chunk when using --all or --limit}
        {--dry-run : Show what would be synced without writing data}';

    public function handle(): int
    {
        return $this->withM1pposuCommandLock('m1pposu:sync:scores', fn () => $this->handleLocked());
    }

    private function handleLocked(): int
    {
        $dryRun = get_bool($this->option('dry-run'));
        $all = get_bool($this->option('all'));
        $username = $this->nullableString($this->option('username'));
        $externalUserId = $this->nullableString($this->option('external-user-id'));
        $limit = $this->parsePositiveInt($this->option('limit'), '--limit', self::MAX_BATCH_LIMIT, true);
        $chunkSize = $this->parsePositiveInt($this->option('chunk-size'), '--chunk-size', self::MAX_CHUNK_SIZE, true) ?? self::DEFAULT_CHUNK_SIZE;

        if ($limit === false || $chunkSize === false) {
            return static::FAILURE;
        }

        if ($username !== null && $externalUserId !== null) {
            $this->error('Use either --username or --external-user-id, not both.');

            return static::FAILURE;
        }

        if ($all && ($username !== null || $externalUserId !== null || $limit !== null)) {
            $this->error('Use --all by itself, not with --username, --external-user-id, or --limit.');

            return static::FAILURE;
        }

        if ($username === null && $externalUserId === null && $limit === null && !$all) {
            $this->error('Refusing to run an unbounded score sync. Use --username, --external-user-id, --limit, or --all.');

            return static::FAILURE;
        }

        if (!$this->configureSource()) {
            return static::FAILURE;
        }

        if (!$this->validateSourceSchema()) {
            return static::FAILURE;
        }

        $backend = config('m1pposu.private_server.backend') ?: 'bancho-py-ex';
        $sourceUserId = $username === null ? $externalUserId : $this->sourceUserIdForUsername($username);

        if ($username !== null && $sourceUserId === null) {
            $this->error('Source user was not found.');

            return static::FAILURE;
        }

        $summary = $this->emptySummary();

        try {
            $this->forEachSourceScoreBatch($sourceUserId, $limit, $all, $chunkSize, function (Collection $sourceScores) use ($backend, $dryRun, &$summary) {
                $this->processSourceScoreBatch($sourceScores, $backend, $dryRun, $summary);
            });
        } catch (Throwable $e) {
            $this->error("Unable to query source scores: {$e->getMessage()}");

            return static::FAILURE;
        }

        $this->printSummary($summary, $dryRun);

        return static::SUCCESS;
    }

    private function processSourceScoreBatch(Collection $sourceScores, string $backend, bool $dryRun, array &$summary): void
    {
        if ($sourceScores->isEmpty()) {
            return;
        }

        $beatmaps = $this->destinationBeatmaps($sourceScores->pluck('map_md5')->all());
        $users = $this->destinationUsers($sourceScores->pluck('userid')->all(), $backend);
        $existingMappings = $this->existingScoreMappings($sourceScores->pluck('id')->all(), $backend);
        $existingScores = $dryRun ? $this->existingScores($existingMappings) : [];
        $queuedScoreIds = [];

        foreach ($sourceScores as $sourceScore) {
            $summary['source_scores_scanned']++;

            try {
                $reason = $this->unsupportedSourceScoreReason($sourceScore);
                if ($reason !== null) {
                    $this->skipScore($sourceScore, $summary, $reason);

                    continue;
                }

                $beatmap = $beatmaps[strtolower((string) $sourceScore->map_md5)] ?? null;
                if ($beatmap === null) {
                    $this->skipScore($sourceScore, $summary, 'missing projected beatmap');

                    continue;
                }

                $user = $users[(string) $sourceScore->userid] ?? null;
                if ($user === null) {
                    $this->skipScore($sourceScore, $summary, 'missing projected unrestricted user');

                    continue;
                }

                if ($dryRun) {
                    $this->planSourceScore(
                        $sourceScore,
                        $beatmap,
                        $user,
                        $existingMappings[(string) $sourceScore->id] ?? null,
                        $existingScores,
                        $summary,
                    );
                } else {
                    $scoreId = DB::transaction(function () use ($sourceScore, $beatmap, $user, $existingMappings, $backend, &$summary) {
                        return $this->syncScore(
                            $sourceScore,
                            $beatmap,
                            $user,
                            $existingMappings[(string) $sourceScore->id] ?? null,
                            $backend,
                            $summary,
                        );
                    });

                    $queuedScoreIds[] = $scoreId;
                }
            } catch (Throwable $e) {
                $this->skipScore($sourceScore, $summary, $this->exceptionMessage($e));
            }
        }

        if (!$dryRun && $queuedScoreIds !== []) {
            ScoreSearch::queueForIndex(null, array_values(array_unique($queuedScoreIds)));
        }
    }

    private function syncScore(object $sourceScore, object $beatmap, object $user, ?object $mapping, string $backend, array &$summary): int
    {
        $attributes = $this->scoreAttributes($sourceScore, $beatmap, $user);

        if ($mapping === null) {
            $scoreId = DB::table('scores')->insertGetId($attributes);
            $this->upsertScoreProcessHistory($scoreId);
            DB::table('m1pposu_external_scores')->insert([
                'score_id' => $scoreId,
                'backend' => $backend,
                'external_score_id' => (string) $sourceScore->id,
                'external_user_id' => (string) $sourceScore->userid,
                'external_beatmap_md5' => strtolower((string) $sourceScore->map_md5),
                'source_mode' => (int) $sourceScore->mode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $summary['scores_created']++;

            return $scoreId;
        }

        $scoreId = (int) $mapping->score_id;
        $existing = DB::table('scores')->where('id', $scoreId)->first();

        if ($existing === null) {
            $scoreId = DB::table('scores')->insertGetId($attributes);
            DB::table('m1pposu_external_scores')->where('id', $mapping->id)->update([
                'score_id' => $scoreId,
                'external_user_id' => (string) $sourceScore->userid,
                'external_beatmap_md5' => strtolower((string) $sourceScore->map_md5),
                'source_mode' => (int) $sourceScore->mode,
                'updated_at' => now(),
            ]);
            $this->upsertScoreProcessHistory($scoreId);
            $summary['scores_created']++;

            return $scoreId;
        }

        if ($this->hasChanged($existing, $attributes)) {
            DB::table('scores')->where('id', $scoreId)->update($attributes);
            $this->upsertScoreProcessHistory($scoreId);
            $summary['scores_updated']++;
        }

        if (
            $mapping->external_user_id !== (string) $sourceScore->userid
            || $mapping->external_beatmap_md5 !== strtolower((string) $sourceScore->map_md5)
            || (int) $mapping->source_mode !== (int) $sourceScore->mode
        ) {
            DB::table('m1pposu_external_scores')->where('id', $mapping->id)->update([
                'external_user_id' => (string) $sourceScore->userid,
                'external_beatmap_md5' => strtolower((string) $sourceScore->map_md5),
                'source_mode' => (int) $sourceScore->mode,
                'updated_at' => now(),
            ]);
        }

        return $scoreId;
    }

    private function planSourceScore(object $sourceScore, object $beatmap, object $user, ?object $mapping, array $existingScores, array &$summary): void
    {
        if ($mapping === null) {
            $summary['scores_created']++;

            return;
        }

        $existing = $existingScores[(int) $mapping->score_id] ?? null;
        if ($existing === null) {
            $summary['scores_created']++;

            return;
        }

        if ($this->hasChanged($existing, $this->scoreAttributes($sourceScore, $beatmap, $user))) {
            $summary['scores_updated']++;
        }
    }

    private function scoreAttributes(object $sourceScore, object $beatmap, object $user): array
    {
        $endedAt = Carbon::parse((string) $sourceScore->play_time);
        $startedAt = $endedAt->copy()->subMilliseconds(max(0, (int) $sourceScore->time_elapsed));
        $isBest = (int) $sourceScore->status === self::STATUS_BEST;
        $isRanked = $isBest && $this->isRankedBeatmap($beatmap);
        $rulesetId = SourceMode::rulesetId((int) $sourceScore->mode);

        return [
            'user_id' => (int) $user->user_id,
            'ruleset_id' => $rulesetId,
            'beatmap_id' => (int) $beatmap->beatmap_id,
            'has_replay' => false,
            'preserve' => $isBest,
            'ranked' => $isRanked,
            'rank' => $this->normalRank($sourceScore->grade),
            'passed' => true,
            'accuracy' => $this->normalAccuracy($sourceScore->acc),
            'max_combo' => max(0, (int) $sourceScore->max_combo),
            'total_score' => max(0, (int) $sourceScore->score),
            'data' => json_encode([
                'maximum_statistics' => [],
                'mods' => $this->modsData((int) $sourceScore->mods),
                'statistics' => $this->statisticsData($sourceScore),
                'total_score_without_mods' => max(0, (int) $sourceScore->score),
            ]),
            'pp' => max(0, (float) $sourceScore->pp),
            'legacy_score_id' => null,
            'legacy_total_score' => max(0, (int) $sourceScore->score),
            'started_at' => $startedAt->toDateTimeString(),
            'ended_at' => $endedAt->toDateTimeString(),
            'unix_updated_at' => $endedAt->getTimestamp(),
            'build_id' => null,
        ];
    }

    private function isRankedBeatmap(object $beatmap): bool
    {
        return in_array((int) $beatmap->approved, [
            Beatmapset::STATES['ranked'],
            Beatmapset::STATES['approved'],
        ], true);
    }

    private function upsertScoreProcessHistory(int $scoreId): void
    {
        $existing = DB::table('score_process_history')->where('score_id', $scoreId)->first();
        $attributes = [
            'processed_version' => 1,
            'processed_at' => now(),
        ];

        if ($existing === null) {
            DB::table('score_process_history')->insert(['score_id' => $scoreId, ...$attributes]);
        } else {
            DB::table('score_process_history')->where('score_id', $scoreId)->update($attributes);
        }
    }

    private function destinationBeatmaps(array $mapMd5s): array
    {
        $mapMd5s = array_values(array_unique(array_map(fn ($md5) => strtolower((string) $md5), $mapMd5s)));

        if ($mapMd5s === []) {
            return [];
        }

        return DB::table('osu_beatmaps')
            ->select(['beatmap_id', 'checksum', 'approved'])
            ->whereIn('checksum', $mapMd5s)
            ->get()
            ->keyBy(fn ($beatmap) => strtolower((string) $beatmap->checksum))
            ->all();
    }

    private function destinationUsers(array $sourceUserIds, string $backend): array
    {
        $sourceUserIds = array_values(array_unique(array_map(fn ($id) => (string) $id, $sourceUserIds)));

        if ($sourceUserIds === []) {
            return [];
        }

        return DB::table('m1pposu_external_users')
            ->join('phpbb_users', 'phpbb_users.user_id', '=', 'm1pposu_external_users.user_id')
            ->select([
                'm1pposu_external_users.external_user_id',
                'phpbb_users.user_id',
            ])
            ->where('m1pposu_external_users.backend', $backend)
            ->whereIn('m1pposu_external_users.external_user_id', $sourceUserIds)
            ->where('phpbb_users.user_warnings', 0)
            ->where('phpbb_users.user_type', 0)
            ->get()
            ->keyBy(fn ($mapping) => (string) $mapping->external_user_id)
            ->all();
    }

    private function existingScoreMappings(array $sourceScoreIds, string $backend): array
    {
        $sourceScoreIds = array_values(array_unique(array_map(fn ($id) => (string) $id, $sourceScoreIds)));

        if ($sourceScoreIds === []) {
            return [];
        }

        return DB::table('m1pposu_external_scores')
            ->where('backend', $backend)
            ->whereIn('external_score_id', $sourceScoreIds)
            ->get()
            ->keyBy(fn ($mapping) => (string) $mapping->external_score_id)
            ->all();
    }

    private function existingScores(array $mappings): array
    {
        $scoreIds = collect($mappings)
            ->pluck('score_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($scoreIds === []) {
            return [];
        }

        return DB::table('scores')
            ->whereIn('id', $scoreIds)
            ->get()
            ->keyBy('id')
            ->all();
    }

    private function unsupportedSourceScoreReason(object $sourceScore): ?string
    {
        if (SourceMode::mode((int) $sourceScore->mode) === null) {
            return "unsupported mode {$sourceScore->mode}";
        }

        if (!in_array((int) $sourceScore->status, self::SUPPORTED_STATUSES, true)) {
            return (int) $sourceScore->status === self::STATUS_FAILED
                ? 'failed score not projected in this profile slice'
                : "unsupported status {$sourceScore->status}";
        }

        if (!preg_match('/^[a-f0-9]{32}$/', strtolower((string) $sourceScore->map_md5))) {
            return 'missing or invalid map md5';
        }

        if ($this->normalRank($sourceScore->grade) === null) {
            return "unsupported grade {$sourceScore->grade}";
        }

        return null;
    }

    private function forEachSourceScoreBatch(?string $sourceUserId, ?int $limit, bool $all, int $chunkSize, callable $callback): void
    {
        $lastId = 0;
        $remaining = $all || $sourceUserId !== null ? null : $limit;

        while ($all || $sourceUserId !== null || $remaining > 0) {
            $currentLimit = $remaining === null ? $chunkSize : min($chunkSize, $remaining);
            $query = $this->sourceScoresQuery()
                ->where('id', '>', $lastId)
                ->limit($currentLimit);

            if ($sourceUserId !== null) {
                $query->where('userid', $sourceUserId);
            }

            $sourceScores = $query->get();

            if ($sourceScores->isEmpty()) {
                return;
            }

            $callback($sourceScores);
            $lastId = (int) $sourceScores->last()->id;

            if ($remaining !== null) {
                $remaining -= $sourceScores->count();
            }
        }
    }

    private function sourceScoresQuery()
    {
        return DB::connection(self::CONNECTION)
            ->table('scores')
            ->select(self::SOURCE_SCORE_COLUMNS)
            ->orderBy('id');
    }

    private function sourceUserIdForUsername(string $username): ?string
    {
        $sourceUser = DB::connection(self::CONNECTION)
            ->table('users')
            ->select('id')
            ->where('name', $username)
            ->first();

        return $sourceUser === null ? null : (string) $sourceUser->id;
    }

    private function configureSource(): bool
    {
        $config = config('m1pposu.private_server');

        if (get_bool($config['enabled'] ?? false) !== true) {
            $this->error('Private-server source is disabled. Set M1PP_PRIVATE_SERVER_ENABLED=true before syncing.');

            return false;
        }

        $database = $config['database'] ?? [];
        $missing = array_filter([
            'M1PP_PRIVATE_SERVER_DB_HOST' => $database['host'] ?? null,
            'M1PP_PRIVATE_SERVER_DB_DATABASE' => $database['database'] ?? null,
            'M1PP_PRIVATE_SERVER_DB_USERNAME' => $database['username'] ?? null,
        ], fn ($value) => !present($value));

        if ($missing !== []) {
            $this->error('Missing private-server source DB config: '.implode(', ', array_keys($missing)));

            return false;
        }

        Config::set('database.connections.'.self::CONNECTION, [
            ...config('database.connections.mysql'),
            'host' => $database['host'],
            'port' => $database['port'],
            'database' => $database['database'],
            'username' => $database['username'],
            'password' => $database['password'],
        ]);

        DB::purge(self::CONNECTION);

        try {
            DB::connection(self::CONNECTION)->getPdo();
        } catch (Throwable $e) {
            $this->error("Unable to connect to private-server source DB: {$e->getMessage()}");

            return false;
        }

        return true;
    }

    private function validateSourceSchema(): bool
    {
        foreach (
            [
            'scores' => self::SOURCE_SCORE_COLUMNS,
            'users' => ['id', 'name'],
            ] as $table => $requiredColumns
        ) {
            try {
                $columns = collect(DB::connection(self::CONNECTION)->select("SHOW COLUMNS FROM {$table}"))
                    ->pluck('Field')
                    ->all();
            } catch (Throwable $e) {
                $this->error("Unable to inspect source table {$table}: {$e->getMessage()}");

                return false;
            }

            $missing = array_values(array_diff($requiredColumns, $columns));
            if ($missing !== []) {
                $this->error("Source table {$table} is missing required columns: ".implode(', ', $missing));

                return false;
            }
        }

        return true;
    }

    private function normalAccuracy($accuracy): float
    {
        $accuracy = (float) $accuracy;

        return max(0.0, min(1.0, $accuracy > 1 ? $accuracy / 100 : $accuracy));
    }

    private function normalRank($rank): ?string
    {
        $rank = strtoupper(trim((string) $rank));

        return in_array($rank, ['A', 'B', 'C', 'D', 'S', 'SH', 'X', 'XH', 'F'], true)
            ? $rank
            : null;
    }

    private function modsData(int $mods): array
    {
        return array_map(
            fn ($acronym) => ['acronym' => $acronym],
            app('mods')->bitsetToIds($mods),
        );
    }

    private function statisticsData(object $sourceScore): array
    {
        return match (SourceMode::rulesetId((int) $sourceScore->mode)) {
            Beatmap::MODES['taiko'] => [
                'great' => (int) $sourceScore->n300,
                'ok' => (int) $sourceScore->n100,
                'miss' => (int) $sourceScore->nmiss,
            ],
            Beatmap::MODES['fruits'] => [
                'great' => (int) $sourceScore->n300,
                'large_tick_hit' => (int) $sourceScore->n100,
                'small_tick_hit' => (int) $sourceScore->n50,
                'small_tick_miss' => (int) $sourceScore->nkatu,
                'miss' => (int) $sourceScore->nmiss,
            ],
            Beatmap::MODES['mania'] => [
                'perfect' => (int) $sourceScore->ngeki,
                'great' => (int) $sourceScore->n300,
                'good' => (int) $sourceScore->nkatu,
                'ok' => (int) $sourceScore->n100,
                'meh' => (int) $sourceScore->n50,
                'miss' => (int) $sourceScore->nmiss,
            ],
            default => [
                'great' => (int) $sourceScore->n300,
                'ok' => (int) $sourceScore->n100,
                'meh' => (int) $sourceScore->n50,
                'miss' => (int) $sourceScore->nmiss,
            ],
        };
    }

    private function parsePositiveInt($value, string $option, int $max, bool $nullable): int|false|null
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return $nullable ? null : false;
        }

        if (!ctype_digit($value)) {
            $this->error("{$option} must be a positive integer.");

            return false;
        }

        $value = (int) $value;
        if ($value <= 0 || $value > $max) {
            $this->error("{$option} must be between 1 and {$max}.");

            return false;
        }

        return $value;
    }

    private function hasChanged(object $existing, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            $existingValue = $existing->{$key} ?? null;

            if ($existingValue === null && $value === null) {
                continue;
            }

            if ($key === 'data') {
                if (json_decode((string) $existingValue, true) !== json_decode((string) $value, true)) {
                    return true;
                }

                continue;
            }

            if (is_bool($value)) {
                if ((bool) $existingValue !== $value) {
                    return true;
                }

                continue;
            }

            if ((is_int($value) || is_float($value)) && is_numeric($existingValue)) {
                $tolerance = $key === 'pp' ? 0.1 : 0.0001;
                if (abs((float) $existingValue - (float) $value) > $tolerance) {
                    return true;
                }

                continue;
            }

            if ((string) ($existingValue ?? '') !== (string) ($value ?? '')) {
                return true;
            }
        }

        return false;
    }

    private function skipScore(object $sourceScore, array &$summary, string $reason): void
    {
        $summary['skipped_scores']++;
        $summary['skip_reasons'][$reason] = ($summary['skip_reasons'][$reason] ?? 0) + 1;

        if (count($summary['warnings']) < 25) {
            $summary['warnings'][] = "source score {$sourceScore->id}: {$reason}";
        }
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function exceptionMessage(Throwable $e): string
    {
        $previous = $e->getPrevious();
        $message = trim($previous?->getMessage() ?? $e->getMessage());

        return $message === '' ? get_class($e) : get_class($e).": {$message}";
    }

    private function emptySummary(): array
    {
        return [
            'source_scores_scanned' => 0,
            'scores_created' => 0,
            'scores_updated' => 0,
            'skipped_scores' => 0,
            'skip_reasons' => [],
            'warnings' => [],
        ];
    }

    private function printSummary(array $summary, bool $dryRun): void
    {
        $this->info($dryRun ? 'Score sync dry-run summary:' : 'Score sync summary:');
        $this->table(['Metric', 'Count'], [
            ['source scores scanned', $summary['source_scores_scanned']],
            [$dryRun ? 'scores that would be created' : 'scores created', $summary['scores_created']],
            [$dryRun ? 'scores that would be updated' : 'scores updated', $summary['scores_updated']],
            ['skipped scores', $summary['skipped_scores']],
            ['warning samples', count($summary['warnings'])],
        ]);

        if ($summary['skip_reasons'] !== []) {
            $this->line('Skip reasons:');
            $this->table(
                ['Reason', 'Count'],
                collect($summary['skip_reasons'])
                    ->sortDesc()
                    ->map(fn ($count, $reason) => [$reason, $count])
                    ->values()
                    ->all(),
            );
        }

        if ($summary['warnings'] !== []) {
            $this->warn('Warning samples:');
            foreach ($summary['warnings'] as $warning) {
                $this->line("- {$warning}");
            }
        }
    }
}
