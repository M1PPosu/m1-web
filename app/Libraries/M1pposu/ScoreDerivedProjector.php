<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Models\Event;
use App\Models\Solo\Score;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final class ScoreDerivedProjector
{
    private const CONNECTION = 'm1pposu-private-server-source';

    private bool $sourceConfigured = false;

    public function projectScoreIds(array $scoreIds): array
    {
        $scoreIds = $this->positiveIds($scoreIds);
        if ($scoreIds === []) {
            return ['leaders' => 0, 'events' => 0];
        }

        $this->configureSource();
        $scores = DB::connection(self::CONNECTION)
            ->table('scores')
            ->select(['id', 'map_md5', 'mode', 'status', 'play_time'])
            ->whereIn('id', $scoreIds)
            ->get();

        $leaders = $this->projectMapModes($scores->map(fn ($score) => [
            'map_md5' => strtolower((string) $score->map_md5),
            'source_mode' => (int) $score->mode,
        ])->all());
        $events = 0;

        foreach ($scores as $score) {
            if ((int) $score->status === 2 && $this->projectRankEvent($score)) {
                $events++;
            }
        }

        return ['leaders' => $leaders, 'events' => $events];
    }

    public function backfill(int $recentDays): array
    {
        $this->configureSource();

        $mapModes = DB::connection(self::CONNECTION)
            ->table('scores')
            ->join('users', 'users.id', '=', 'scores.userid')
            ->select(['scores.map_md5', 'scores.mode AS source_mode'])
            ->where('scores.status', 2)
            ->whereIn('scores.mode', SourceMode::supportedSourceModes())
            ->whereRaw('(users.priv & 1) = 1')
            ->distinct()
            ->get()
            ->map(fn ($row) => [
                'map_md5' => strtolower((string) $row->map_md5),
                'source_mode' => (int) $row->source_mode,
            ])
            ->all();

        $leaders = $this->projectMapModes($mapModes);
        $eventScoreIds = DB::connection(self::CONNECTION)
            ->table('scores')
            ->join('users', 'users.id', '=', 'scores.userid')
            ->where('scores.status', 2)
            ->whereIn('scores.mode', SourceMode::supportedSourceModes())
            ->where('scores.play_time', '>=', now()->subDays($recentDays))
            ->whereRaw('(users.priv & 1) = 1')
            ->orderBy('scores.id')
            ->pluck('scores.id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $events = 0;

        foreach (array_chunk($eventScoreIds, 500) as $chunk) {
            $events += $this->projectScoreIds($chunk)['events'];
        }

        return [
            'leaders' => $leaders,
            'recent_scores' => count($eventScoreIds),
            'events' => $events,
        ];
    }

    private function projectMapModes(array $mapModes): int
    {
        $projected = 0;
        $unique = [];

        foreach ($mapModes as $mapMode) {
            $sourceMode = (int) $mapMode['source_mode'];
            $mapMd5 = strtolower((string) $mapMode['map_md5']);
            if (SourceMode::mode($sourceMode) !== null && preg_match('/^[a-f0-9]{32}$/', $mapMd5) === 1) {
                $unique["{$mapMd5}:{$sourceMode}"] = compact('mapMd5', 'sourceMode');
            }
        }

        foreach ($unique as ['mapMd5' => $mapMd5, 'sourceMode' => $sourceMode]) {
            if ($this->projectLeader($mapMd5, $sourceMode)) {
                $projected++;
            }
        }

        return $projected;
    }

    private function projectLeader(string $mapMd5, int $sourceMode): bool
    {
        $metric = $sourceMode >= 4 ? 'scores.pp' : 'scores.score';
        $leader = DB::connection(self::CONNECTION)
            ->table('scores')
            ->join('users', 'users.id', '=', 'scores.userid')
            ->select(['scores.id'])
            ->where('scores.map_md5', $mapMd5)
            ->where('scores.mode', $sourceMode)
            ->where('scores.status', 2)
            ->whereRaw('(users.priv & 1) = 1')
            ->orderByDesc($metric)
            ->orderBy('scores.id')
            ->first();
        $beatmapId = DB::table('osu_beatmaps')->where('checksum', $mapMd5)->value('beatmap_id');

        if ($beatmapId === null) {
            return false;
        }

        $mapping = $leader === null ? null : DB::table('m1pposu_external_scores')
            ->where('backend', $this->backend())
            ->where('external_score_id', (string) $leader->id)
            ->where('source_mode', $sourceMode)
            ->first(['score_id']);

        return DB::transaction(function () use ($beatmapId, $sourceMode, $leader, $mapping) {
            $existing = DB::table('m1pposu_score_leaders')
                ->where('beatmap_id', $beatmapId)
                ->where('source_mode', $sourceMode)
                ->lockForUpdate()
                ->first();

            if ($leader === null) {
                if ($existing !== null) {
                    DB::table('m1pposu_score_leaders')
                        ->where('beatmap_id', $beatmapId)
                        ->where('source_mode', $sourceMode)
                        ->delete();
                }

                return false;
            }

            if ($mapping === null) {
                return false;
            }

            $score = Score::find((int) $mapping->score_id);
            if ($score === null) {
                return false;
            }

            $attributes = [
                'score_id' => $score->getKey(),
                'user_id' => $score->user_id,
                'updated_at' => now(),
            ];

            if ($existing === null) {
                DB::table('m1pposu_score_leaders')->insert([
                    'beatmap_id' => $beatmapId,
                    'source_mode' => $sourceMode,
                    ...$attributes,
                    'created_at' => now(),
                ]);
            } elseif ((int) $existing->score_id !== $score->getKey()) {
                DB::table('m1pposu_score_leaders')
                    ->where('beatmap_id', $beatmapId)
                    ->where('source_mode', $sourceMode)
                    ->update($attributes);
            }

            return true;
        });
    }

    private function projectRankEvent(object $sourceScore): bool
    {
        $sourceMode = (int) $sourceScore->mode;
        $metric = $sourceMode >= 4 ? 'pp' : 'score';
        $scoreValue = DB::connection(self::CONNECTION)
            ->table('scores')
            ->where('id', $sourceScore->id)
            ->value($metric);
        $position = DB::connection(self::CONNECTION)
            ->table('scores')
            ->join('users', 'users.id', '=', 'scores.userid')
            ->where('scores.map_md5', $sourceScore->map_md5)
            ->where('scores.mode', $sourceMode)
            ->where('scores.status', 2)
            ->whereRaw('(users.priv & 1) = 1')
            ->where("scores.{$metric}", '>', $scoreValue)
            ->count() + 1;

        if ($position > 50) {
            return false;
        }

        $eventKey = "score:{$sourceScore->id}:rank";
        if ($this->eventExists($eventKey)) {
            return false;
        }

        $mapping = DB::table('m1pposu_external_scores')
            ->where('backend', $this->backend())
            ->where('external_score_id', (string) $sourceScore->id)
            ->where('source_mode', $sourceMode)
            ->first(['score_id']);
        $score = $mapping === null
            ? null
            : Score::with(['beatmap.beatmapset', 'user'])->find((int) $mapping->score_id);
        $mode = SourceMode::mode($sourceMode);

        if ($score === null || $score->beatmap === null || $score->user === null || $mode === null) {
            return false;
        }

        DB::transaction(function () use ($eventKey, $mode, $position, $score, $sourceScore) {
            if ($this->eventExists($eventKey, true)) {
                return;
            }

            $event = Event::generate('rank', [
                'beatmap' => $score->beatmap,
                'ruleset' => $mode['ruleset'],
                'variant' => $mode['variant'],
                'user' => $score->user,
                'position_after' => $position,
                'rank' => $score->rank,
                'legacy_score_event' => null,
                'date' => Carbon::parse((string) $sourceScore->play_time),
            ]);

            DB::table('m1pposu_external_events')->insert([
                'backend' => $this->backend(),
                'external_event_key' => $eventKey,
                'event_id' => $event->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return true;
    }

    private function eventExists(string $eventKey, bool $lock = false): bool
    {
        $query = DB::table('m1pposu_external_events')
            ->where('backend', $this->backend())
            ->where('external_event_key', $eventKey);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->exists();
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
}
