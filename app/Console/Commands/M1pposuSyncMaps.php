<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\HasM1pposuCommandLock;
use App\Models\Beatmap;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

class M1pposuSyncMaps extends Command
{
    use HasM1pposuCommandLock;

    private const CONNECTION = 'm1pposu-private-server-source';
    private const DEFAULT_CHUNK_SIZE = 1000;
    private const MAX_BATCH_LIMIT = 5000;
    private const MAX_CHUNK_SIZE = 5000;

    private const SOURCE_MAP_COLUMNS = [
        'id',
        'set_id',
        'status',
        'md5',
        'artist',
        'title',
        'version',
        'creator',
        'filename',
        'last_update',
        'total_length',
        'max_combo',
        'plays',
        'passes',
        'mode',
        'bpm',
        'cs',
        'ar',
        'od',
        'hp',
        'diff',
    ];

    private const STATUS_MAP = [
        0 => 0, // pending
        2 => 1, // ranked
        3 => 2, // approved
        4 => 3, // qualified
        5 => 4, // loved
    ];

    protected $description = 'Sync private-server beatmap metadata required for projected score display.';

    protected $signature = 'm1pposu:sync:maps
        {--beatmap-id= : Sync one source map by maps.id}
        {--limit= : Maximum source maps to scan}
        {--all : Sync every source map using chunked processing}
        {--chunk-size= : Source maps to scan per chunk when using --all or --limit}
        {--dry-run : Show what would be synced without writing data}';

    public function handle(): int
    {
        return $this->withM1pposuCommandLock('m1pposu:sync:maps', fn () => $this->handleLocked());
    }

    private function handleLocked(): int
    {
        $dryRun = get_bool($this->option('dry-run'));
        $all = get_bool($this->option('all'));
        $beatmapId = $this->nullableString($this->option('beatmap-id'));
        $limit = $this->parsePositiveInt($this->option('limit'), '--limit', self::MAX_BATCH_LIMIT, true);
        $chunkSize = $this->parsePositiveInt($this->option('chunk-size'), '--chunk-size', self::MAX_CHUNK_SIZE, true) ?? self::DEFAULT_CHUNK_SIZE;

        if ($limit === false || $chunkSize === false) {
            return static::FAILURE;
        }

        if ($all && ($beatmapId !== null || $limit !== null)) {
            $this->error('Use --all by itself, not with --beatmap-id or --limit.');

            return static::FAILURE;
        }

        if ($beatmapId === null && $limit === null && !$all) {
            $this->error('Refusing to run an unbounded map sync. Use --beatmap-id, --limit, or --all.');

            return static::FAILURE;
        }

        if (!$this->configureSource()) {
            return static::FAILURE;
        }

        if (!$this->validateSourceSchema()) {
            return static::FAILURE;
        }

        $summary = $this->emptySummary();

        if (!$dryRun) {
            $this->ensureReferenceRows();
        }

        try {
            $this->forEachSourceMapBatch($beatmapId, $limit, $all, $chunkSize, function (Collection $sourceMaps) use ($dryRun, &$summary) {
                $this->processSourceMapBatch($sourceMaps, $dryRun, $summary);
            });
        } catch (Throwable $e) {
            $this->error("Unable to query source maps: {$e->getMessage()}");

            return static::FAILURE;
        }

        $this->printSummary($summary, $dryRun);

        return static::SUCCESS;
    }

    private function processSourceMapBatch(Collection $sourceMaps, bool $dryRun, array &$summary): void
    {
        $supportedMaps = collect();

        foreach ($sourceMaps as $sourceMap) {
            $summary['source_maps_scanned']++;

            $reason = $this->unsupportedSourceMapReason($sourceMap);
            if ($reason !== null) {
                $summary['skipped_maps']++;
                $summary['warnings'][] = "source map {$sourceMap->id}: {$reason}";

                continue;
            }

            $supportedMaps->push($sourceMap);
        }

        if ($supportedMaps->isEmpty()) {
            return;
        }

        $setData = $this->sourceSetData($supportedMaps->pluck('set_id')->unique()->all());
        $creatorUserIds = $this->creatorUserIds($supportedMaps, $setData);

        try {
            $beatmapsetRows = $this->beatmapsetRows($supportedMaps, $setData, $creatorUserIds);
            $beatmapRows = $supportedMaps
                ->map(fn ($sourceMap) => ['beatmap_id' => (int) $sourceMap->id, ...$this->beatmapAttributes($sourceMap, $creatorUserIds)])
                ->values()
                ->all();

            if ($dryRun) {
                $this->summarizeRows('osu_beatmapsets', 'beatmapset_id', $beatmapsetRows, $summary['beatmapsets_created'], $summary['beatmapsets_updated']);
                $this->summarizeRows('osu_beatmaps', 'beatmap_id', $beatmapRows, $summary['beatmaps_created'], $summary['beatmaps_updated']);
            } else {
                DB::transaction(function () use ($beatmapsetRows, $beatmapRows, &$summary) {
                    $this->upsertChangedRows('osu_beatmapsets', 'beatmapset_id', $beatmapsetRows, $summary['beatmapsets_created'], $summary['beatmapsets_updated']);
                    $this->upsertChangedRows('osu_beatmaps', 'beatmap_id', $beatmapRows, $summary['beatmaps_created'], $summary['beatmaps_updated']);
                });
            }
        } catch (Throwable $e) {
            $summary['skipped_maps'] += $supportedMaps->count();
            $summary['warnings'][] = 'source map batch: '.$this->exceptionMessage($e);
        }
    }

    private function beatmapsetRows(Collection $supportedMaps, array $setData, array $creatorUserIds): array
    {
        $rows = [];

        foreach ($supportedMaps->pluck('set_id')->unique() as $setId) {
            $setMaps = $setData[(int) $setId] ?? $supportedMaps->where('set_id', $setId)->values();
            $sourceMap = $setMaps
                ->sortByDesc(fn ($map) => self::STATUS_MAP[(int) $map->status] ?? -1)
                ->first();

            if ($sourceMap !== null) {
                $rows[] = ['beatmapset_id' => (int) $setId, ...$this->beatmapsetAttributes($sourceMap, $setMaps, $creatorUserIds)];
            }
        }

        return $rows;
    }

    private function summarizeRows(string $table, string $key, array $rows, int &$created, int &$updated): void
    {
        if ($rows === []) {
            return;
        }

        $existing = DB::table($table)
            ->whereIn($key, array_column($rows, $key))
            ->get()
            ->keyBy($key);

        foreach ($rows as $row) {
            $existingRow = $existing[$row[$key]] ?? null;

            if ($existingRow === null) {
                $created++;
            } elseif ($this->hasChanged($existingRow, $row)) {
                $updated++;
            }
        }
    }

    private function upsertChangedRows(string $table, string $key, array $rows, int &$created, int &$updated): void
    {
        if ($rows === []) {
            return;
        }

        $existing = DB::table($table)
            ->whereIn($key, array_column($rows, $key))
            ->get()
            ->keyBy($key);

        $changedRows = [];
        foreach ($rows as $row) {
            $existingRow = $existing[$row[$key]] ?? null;

            if ($existingRow !== null) {
                $row = $this->preserveBeatmapsetRankedDate($table, $existingRow, $row);
            }

            if ($existingRow === null) {
                $created++;
                $changedRows[] = $row;
            } elseif ($this->hasChanged($existingRow, $row)) {
                $updated++;
                $changedRows[] = $row;
            }
        }

        if ($changedRows !== []) {
            DB::table($table)->upsert(
                $changedRows,
                [$key],
                array_values(array_diff(array_keys($changedRows[0]), [$key])),
            );
        }
    }

    private function beatmapsetAttributes(object $sourceMap, Collection $setMaps, array $creatorUserIds): array
    {
        $approved = $setMaps
            ->map(fn ($map) => self::STATUS_MAP[(int) $map->status] ?? null)
            ->filter(fn ($status) => $status !== null)
            ->max() ?? self::STATUS_MAP[(int) $sourceMap->status];

        $difficultyNames = $setMaps
            ->map(fn ($map) => "{$this->truncate($map->version, 80)}@{$map->mode}")
            ->implode(',');

        $approvedDate = $approved > 0 ? $this->sourceTimestamp($sourceMap->last_update) : null;

        return [
            'user_id' => $this->creatorUserId($sourceMap, $creatorUserIds) ?? 0,
            'thread_id' => 0,
            'artist' => $this->truncate($sourceMap->artist, 80),
            'artist_unicode' => $this->truncate($sourceMap->artist, 80),
            'title' => $this->truncate($sourceMap->title, 80),
            'title_unicode' => $this->truncate($sourceMap->title, 80),
            'creator' => $this->truncate($sourceMap->creator, 80),
            'source' => '',
            'tags' => '',
            'video' => false,
            'storyboard' => false,
            'epilepsy' => false,
            'bpm' => (float) $sourceMap->bpm,
            'versions_available' => min(255, $setMaps->count()),
            'approved' => $approved,
            'approved_date' => $approvedDate,
            'submit_date' => $this->sourceTimestamp($sourceMap->last_update),
            'last_update' => $this->sourceTimestamp($sourceMap->last_update),
            'filename' => $this->truncate("{$sourceMap->set_id} {$sourceMap->artist} - {$sourceMap->title}.osz", 120),
            'active' => true,
            'rating' => 0,
            'offset' => 0,
            'displaytitle' => $this->truncate($sourceMap->title, 200),
            'genre_id' => 1,
            'language_id' => 1,
            'star_priority' => 0,
            'filesize' => 0,
            'filesize_novideo' => null,
            'download_disabled' => true,
            'download_disabled_url' => null,
            'thread_icon_date' => null,
            'favourite_count' => 0,
            'play_count' => $setMaps->sum(fn ($map) => (int) $map->plays),
            'difficulty_names' => $this->truncate($difficultyNames, 2048),
            'cover_updated_at' => null,
            'discussion_enabled' => false,
            'discussion_locked' => false,
            'deleted_at' => null,
            'hype' => 0,
            'nominations' => 0,
            'previous_queue_duration' => 0,
            'queued_at' => null,
            'storyboard_hash' => null,
            'nsfw' => false,
            'anime_cover' => false,
            'track_id' => null,
            'spotlight' => false,
            'comment_locked' => false,
            'eligible_main_rulesets' => null,
        ];
    }

    private function ensureReferenceRows(): void
    {
        DB::table('osu_genres')->upsert(
            [['genre_id' => 1, 'name' => 'Unspecified']],
            ['genre_id'],
            ['name'],
        );

        DB::table('osu_languages')->upsert(
            [['language_id' => 1, 'name' => 'Unspecified', 'display_order' => 14]],
            ['language_id'],
            ['name', 'display_order'],
        );
    }

    private function beatmapAttributes(object $sourceMap, array $creatorUserIds): array
    {
        return [
            'beatmapset_id' => (int) $sourceMap->set_id,
            'user_id' => $this->creatorUserId($sourceMap, $creatorUserIds) ?? 0,
            'filename' => $this->truncate($sourceMap->filename, 150),
            'checksum' => strtolower((string) $sourceMap->md5),
            'version' => $this->truncate($sourceMap->version, 80),
            'total_length' => max(0, (int) $sourceMap->total_length),
            'hit_length' => max(0, (int) $sourceMap->total_length),
            'countTotal' => 0,
            'countNormal' => 0,
            'countSlider' => 0,
            'countSpinner' => 0,
            'diff_drain' => max(0, (float) $sourceMap->hp),
            'diff_size' => max(0, (float) $sourceMap->cs),
            'diff_overall' => max(0, (float) $sourceMap->od),
            'diff_approach' => max(0, (float) $sourceMap->ar),
            'playmode' => (int) $sourceMap->mode,
            'approved' => self::STATUS_MAP[(int) $sourceMap->status],
            'last_update' => $this->sourceTimestamp($sourceMap->last_update),
            'difficultyrating' => (float) $sourceMap->diff,
            'max_combo' => max(0, (int) $sourceMap->max_combo),
            'playcount' => max(0, (int) $sourceMap->plays),
            'passcount' => max(0, (int) $sourceMap->passes),
            'youtube_preview' => null,
            'score_version' => 1,
            'osu_file_version' => 14,
            'deleted_at' => null,
            'bpm' => (float) $sourceMap->bpm,
        ];
    }

    private function creatorUserIds(Collection $supportedMaps, array $setData): array
    {
        $sourceMaps = $supportedMaps->concat(
            collect($setData)->flatMap(fn (Collection $setMaps) => $setMaps)
        );

        $creatorNames = $sourceMaps
            ->map(fn ($sourceMap) => $this->creatorName($sourceMap))
            ->filter(fn (?string $creator) => $creator !== null)
            ->unique()
            ->values()
            ->all();

        if ($creatorNames === []) {
            return [];
        }

        return DB::table('m1pposu_external_users')
            ->where('backend', $this->backend())
            ->whereIn('external_username', $creatorNames)
            ->pluck('user_id', 'external_username')
            ->mapWithKeys(fn ($userId, $externalUsername) => [$this->creatorKey($externalUsername) => (int) $userId])
            ->all();
    }

    private function creatorUserId(object $sourceMap, array $creatorUserIds): ?int
    {
        $creator = $this->creatorName($sourceMap);

        return $creator === null ? null : ($creatorUserIds[$this->creatorKey($creator)] ?? null);
    }

    private function creatorName(object $sourceMap): ?string
    {
        return presence(trim((string) $sourceMap->creator));
    }

    private function forEachSourceMapBatch(?string $beatmapId, ?int $limit, bool $all, int $chunkSize, callable $callback): void
    {
        if ($beatmapId !== null) {
            $callback($this->sourceMapsQuery()->where('id', $beatmapId)->get());

            return;
        }

        $lastId = 0;
        $remaining = $all ? null : $limit;

        while ($all || $remaining > 0) {
            $currentLimit = $remaining === null ? $chunkSize : min($chunkSize, $remaining);
            $sourceMaps = $this->sourceMapsQuery()
                ->where('id', '>', $lastId)
                ->limit($currentLimit)
                ->get();

            if ($sourceMaps->isEmpty()) {
                return;
            }

            $callback($sourceMaps);
            $lastId = (int) $sourceMaps->last()->id;

            if ($remaining !== null) {
                $remaining -= $sourceMaps->count();
            }
        }
    }

    private function sourceMapsQuery()
    {
        return DB::connection(self::CONNECTION)
            ->table('maps')
            ->select(self::SOURCE_MAP_COLUMNS)
            ->orderBy('id');
    }

    private function sourceSetData(array $setIds): array
    {
        $setIds = array_values(array_unique(array_map('intval', $setIds)));

        if ($setIds === []) {
            return [];
        }

        return DB::connection(self::CONNECTION)
            ->table('maps')
            ->select(self::SOURCE_MAP_COLUMNS)
            ->whereIn('set_id', $setIds)
            ->whereIn('mode', array_values(Beatmap::MODES))
            ->whereIn('status', array_keys(self::STATUS_MAP))
            ->orderBy('set_id')
            ->orderBy('mode')
            ->orderBy('diff')
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($sourceMap) => (int) $sourceMap->set_id)
            ->all();
    }

    private function unsupportedSourceMapReason(object $sourceMap): ?string
    {
        $id = (int) $sourceMap->id;
        $setId = (int) $sourceMap->set_id;

        if ($id <= 0 || $id > 16777215) {
            return 'source map id is outside osu-web beatmap_id range';
        }

        if ($setId <= 0 || $setId > 16777215) {
            return 'source mapset id is outside osu-web beatmapset_id range';
        }

        if (!in_array((int) $sourceMap->mode, array_values(Beatmap::MODES), true)) {
            return "unsupported mode {$sourceMap->mode}";
        }

        if (!array_key_exists((int) $sourceMap->status, self::STATUS_MAP)) {
            return "unsupported status {$sourceMap->status}";
        }

        if (!preg_match('/^[a-f0-9]{32}$/', strtolower((string) $sourceMap->md5))) {
            return 'missing or invalid md5';
        }

        return null;
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
        try {
            $columns = collect(DB::connection(self::CONNECTION)->select('SHOW COLUMNS FROM maps'))
                ->pluck('Field')
                ->all();
        } catch (Throwable $e) {
            $this->error("Unable to inspect source table maps: {$e->getMessage()}");

            return false;
        }

        $missing = array_values(array_diff(self::SOURCE_MAP_COLUMNS, $columns));
        if ($missing !== []) {
            $this->error('Source table maps is missing required columns: '.implode(', ', $missing));

            return false;
        }

        return true;
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

    private function preserveBeatmapsetRankedDate(string $table, object $existing, array $row): array
    {
        if ($table !== 'osu_beatmapsets') {
            return $row;
        }

        if ((int) ($existing->approved ?? 0) <= 0 || (int) ($row['approved'] ?? 0) <= 0) {
            return $row;
        }

        $existingDate = $existing->approved_date ?? null;
        if ($existingDate === null || $existingDate === '') {
            return $row;
        }

        $incomingDate = $row['approved_date'] ?? null;
        if ($incomingDate === null || $incomingDate === '') {
            $row['approved_date'] = (string) $existingDate;

            return $row;
        }

        if (Carbon::parse((string) $existingDate)->greaterThan(Carbon::parse((string) $incomingDate))) {
            $row['approved_date'] = Carbon::parse((string) $existingDate)->toDateTimeString();
        }

        return $row;
    }

    private function hasChanged(object $existing, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            $existingValue = $existing->{$key} ?? null;

            if ($existingValue === null && $value === null) {
                continue;
            }

            if (is_bool($value)) {
                if ((bool) $existingValue !== $value) {
                    return true;
                }

                continue;
            }

            if ((is_int($value) || is_float($value)) && is_numeric($existingValue)) {
                $tolerance = is_float($value) ? 0.01 : 0.0001;
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

    private function sourceTimestamp($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateTimeString();
        }

        return Carbon::parse((string) $value)->toDateTimeString();
    }

    private function truncate($value, int $length): string
    {
        return mb_substr(trim((string) $value), 0, $length);
    }

    private function backend(): string
    {
        return (string) (config('m1pposu.private_server.backend') ?: 'bancho-py-ex');
    }

    private function creatorKey(string $creator): string
    {
        return mb_strtolower(trim($creator));
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
            'source_maps_scanned' => 0,
            'beatmapsets_created' => 0,
            'beatmapsets_updated' => 0,
            'beatmaps_created' => 0,
            'beatmaps_updated' => 0,
            'skipped_maps' => 0,
            'warnings' => [],
        ];
    }

    private function printSummary(array $summary, bool $dryRun): void
    {
        $this->info($dryRun ? 'Map sync dry-run summary:' : 'Map sync summary:');
        $this->table(['Metric', 'Count'], [
            ['source maps scanned', $summary['source_maps_scanned']],
            [$dryRun ? 'beatmapsets that would be created' : 'beatmapsets created', $summary['beatmapsets_created']],
            [$dryRun ? 'beatmapsets that would be updated' : 'beatmapsets updated', $summary['beatmapsets_updated']],
            [$dryRun ? 'beatmaps that would be created' : 'beatmaps created', $summary['beatmaps_created']],
            [$dryRun ? 'beatmaps that would be updated' : 'beatmaps updated', $summary['beatmaps_updated']],
            ['skipped maps', $summary['skipped_maps']],
            ['warnings', count($summary['warnings'])],
        ]);

        if ($summary['warnings'] !== []) {
            $this->warn('Warnings:');
            foreach ($summary['warnings'] as $warning) {
                $this->line("- {$warning}");
            }
        }
    }
}
