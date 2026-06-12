<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\HasM1pposuCommandLock;
use App\Libraries\ImageProcessor;
use App\Libraries\M1pposu\ImportedAssets;
use App\Libraries\User\Cover as UserCover;
use App\Models\M1pposuExternalTeam;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class M1pposuSyncClanAssets extends Command
{
    use HasM1pposuCommandLock;

    private const CONNECTION = 'm1pposu-private-server-source';
    private const DEFAULT_CHUNK_SIZE = 100;
    private const MAX_LIMIT = 1000;
    private const MAX_CHUNK_SIZE = 500;
    private const SOURCE_EXTENSIONS = ['', 'png', 'jpg', 'jpeg', 'gif'];
    private const TYPES = ['icons', 'covers'];

    protected $signature = 'm1pposu:sync:clan-assets
        {type : icons, covers, or all}
        {--clan-id= : Import assets for one source clans.id}
        {--limit= : Maximum source clans to scan}
        {--all : Import assets for every source clan using chunked processing}
        {--chunk-size= : Source clans to scan per chunk}
        {--dry-run : Show what would be imported without writing files or teams}';

    protected $description = 'Import private-server clan images into osu-web team flags and headers.';

    public function handle(): int
    {
        $types = $this->assetTypes();

        return $types === null
            ? static::FAILURE
            : $this->withM1pposuCommandLock('m1pposu:sync:clan-assets', fn () => $this->handleLocked($types));
    }

    private function handleLocked(array $types): int
    {
        $dryRun = get_bool($this->option('dry-run'));
        $all = get_bool($this->option('all'));
        $clanId = $this->nullableString($this->option('clan-id'));
        $limit = $this->parseLimit($this->option('limit'));
        $chunkSize = $this->parseChunkSize($this->option('chunk-size'));

        if ($limit === false || $chunkSize === false) {
            return static::FAILURE;
        }

        if ($all && ($clanId !== null || $limit !== null)) {
            $this->error('Use --all by itself, not with --clan-id or --limit.');

            return static::FAILURE;
        }

        if ($clanId === null && $limit === null && !$all) {
            $this->error('Refusing to run an unbounded clan asset sync. Use --clan-id, --limit, or --all.');

            return static::FAILURE;
        }

        $config = Config::get('m1pposu.private_server', []);
        if (!$this->configureSource($config)) {
            return static::FAILURE;
        }

        $missing = $this->missingSourceColumns();
        if ($missing !== []) {
            foreach ($missing as $table => $columns) {
                $this->error("Source table {$table} is missing required columns: ".implode(', ', $columns));
            }

            return static::FAILURE;
        }

        try {
            ImportedAssets::publicBaseUrl();
            \Storage::disk(ImportedAssets::diskName());
        } catch (Throwable $e) {
            $this->error("Imported asset storage is not configured correctly: {$e->getMessage()}");

            return static::FAILURE;
        }

        $summary = [
            'source_clans_scanned' => 0,
            'icons_imported' => 0,
            'covers_imported' => 0,
            'unchanged_assets' => 0,
            'skipped_missing_source_file' => 0,
            'skipped_invalid_source_file' => 0,
            'skipped_missing_team_mapping' => 0,
            'skipped_missing_destination_team' => 0,
            'skipped_unconfigured_source_path' => 0,
            'skipped_unsupported_destination_image_type' => 0,
            'warnings' => [],
        ];

        $sourcePaths = [];
        foreach ($types as $type) {
            $sourcePaths[$type] = $this->configuredSourcePath($type, $summary);
        }

        $backend = $config['backend'] ?: 'bancho-py-ex';

        try {
            $this->forEachClanBatch($clanId, $limit, $all, $chunkSize, function ($sourceClans) use ($backend, $dryRun, &$summary, $sourcePaths, $types) {
                foreach ($sourceClans as $sourceClan) {
                    $this->processClan($sourceClan, $backend, $types, $sourcePaths, $dryRun, $summary);
                }
            });
        } catch (Throwable $e) {
            $this->error("Unable to query source clans: {$e->getMessage()}");

            return static::FAILURE;
        }

        $this->printSummary($summary, $dryRun);

        return static::SUCCESS;
    }

    private function processClan(object $sourceClan, string $backend, array $types, array $sourcePaths, bool $dryRun, array &$summary): void
    {
        $summary['source_clans_scanned']++;

        $mapping = M1pposuExternalTeam::query()
            ->where('backend', $backend)
            ->where('external_team_id', (string) $sourceClan->id)
            ->first();

        if ($mapping === null) {
            $summary['skipped_missing_team_mapping']++;

            return;
        }

        $team = Team::find($mapping->team_id);
        if ($team === null) {
            $summary['skipped_missing_destination_team']++;

            return;
        }

        foreach ($types as $type) {
            $sourcePath = $sourcePaths[$type];
            if ($sourcePath === null) {
                $summary['skipped_unconfigured_source_path']++;

                continue;
            }

            $sourceFile = $this->sourceFile($sourcePath, $sourceClan, $mapping);
            if ($sourceFile === null) {
                $summary['skipped_missing_source_file']++;

                continue;
            }

            $this->processAsset($team, $type, $sourceFile, (int) $sourceClan->id, $dryRun, $summary);
        }
    }

    private function processAsset(Team $team, string $type, string $sourceFile, int $sourceClanId, bool $dryRun, array &$summary): void
    {
        $tmpFile = null;

        try {
            [$tmpFile, $extension] = $this->prepareImage($type, $sourceFile);
            $path = $type === 'icons'
                ? ImportedAssets::teamFlagPath($team, $extension)
                : ImportedAssets::teamHeaderPath($team, $extension);
            $currentMarker = $type === 'icons'
                ? $team->getRawAttribute('flag_file')
                : $team->getRawAttribute('header_file');

            if (
                ImportedAssets::pathFromMarker($currentMarker) === $path
                && ImportedAssets::matchesFile($path, $tmpFile)
            ) {
                $summary['unchanged_assets']++;

                return;
            }

            if ($dryRun) {
                $summary["{$type}_imported"]++;

                return;
            }

            ImportedAssets::putPublicFile($path, $tmpFile);
            if ($type === 'icons') {
                $team->setAttribute('flag_file', ImportedAssets::marker($path));
            } else {
                $team->setAttribute('header_file', ImportedAssets::marker($path));
            }
            $team->saveOrExplode();
            ImportedAssets::deleteMarkerIfDifferent(is_string($currentMarker) ? $currentMarker : null, $path);
            $summary["{$type}_imported"]++;
        } catch (Throwable $e) {
            $summary['skipped_invalid_source_file']++;
            $summary['warnings'][] = "source clan {$sourceClanId} {$type}: {$e->getMessage()}";
        } finally {
            if ($tmpFile !== null) {
                @unlink($tmpFile);
            }
        }
    }

    private function prepareImage(string $type, string $sourceFile): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'm1pposu-clan-asset-');
        if ($tmpFile === false || !copy($sourceFile, $tmpFile)) {
            throw new RuntimeException('Unable to prepare temporary clan asset file.');
        }

        try {
            $processor = $type === 'icons'
                ? new ImageProcessor($tmpFile, Team::FLAG_MAX_DIMENSIONS, 200_000)
                : new ImageProcessor($tmpFile, UserCover::CUSTOM_COVER_MAX_DIMENSIONS, UserCover::CUSTOM_COVER_MAX_FILESIZE);
            $processor->process();

            return [$tmpFile, $processor->ext()];
        } catch (Throwable $e) {
            @unlink($tmpFile);

            throw $e;
        }
    }

    private function sourceFile(string $sourcePath, object $sourceClan, M1pposuExternalTeam $mapping): ?string
    {
        $basePath = realpath($sourcePath);
        if ($basePath === false) {
            return null;
        }

        foreach ($this->candidateBasenames($sourceClan, $mapping) as $basename) {
            foreach (self::SOURCE_EXTENSIONS as $extension) {
                $filename = $extension === '' ? $basename : "{$basename}.{$extension}";
                $path = "{$basePath}/{$filename}";

                if (is_file($path)) {
                    $realPath = realpath($path);
                    if ($realPath !== false && str_starts_with($realPath, $basePath.DIRECTORY_SEPARATOR)) {
                        return $realPath;
                    }
                }
            }
        }

        return null;
    }

    private function candidateBasenames(object $sourceClan, M1pposuExternalTeam $mapping): array
    {
        $candidates = [
            (string) $sourceClan->id,
            (string) $mapping->external_team_id,
            $this->nullableString($sourceClan->tag ?? null),
            $mapping->external_short_name,
        ];

        $ret = [];
        foreach ($candidates as $candidate) {
            $candidate = $candidate === null ? null : $this->safeFilenameSegment((string) $candidate);
            if ($candidate !== null) {
                $ret[] = $candidate;
            }
        }

        return array_values(array_unique($ret));
    }

    private function safeFilenameSegment(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || $value === '.' || $value === '..') {
            return null;
        }

        return preg_match('/[\/\\\\\x00-\x1f]/', $value) === 1 ? null : $value;
    }

    private function configuredSourcePath(string $type, array &$summary): ?string
    {
        $key = $type === 'icons'
            ? 'm1pposu.clans.source_icon_path'
            : 'm1pposu.clans.source_cover_path';
        $env = $type === 'icons'
            ? 'M1PP_SOURCE_CLAN_ICON_PATH'
            : 'M1PP_SOURCE_CLAN_COVER_PATH';
        $path = $this->nullableString(Config::get($key));

        if ($path === null) {
            $summary['warnings'][] = "{$env} is not configured; {$type} will be skipped.";

            return null;
        }

        $path = rtrim($path, '\\/');
        if (!is_dir($path)) {
            $summary['warnings'][] = "{$env} is not a readable directory: {$path}";

            return null;
        }

        return $path;
    }

    private function forEachClanBatch(?string $clanId, ?int $limit, bool $all, int $chunkSize, callable $callback): void
    {
        if ($clanId !== null) {
            $callback($this->sourceClansQuery()->where('id', $clanId)->get());

            return;
        }

        $lastId = 0;
        $remaining = $all ? null : $limit;

        while ($all || $remaining > 0) {
            $currentLimit = $remaining === null ? $chunkSize : min($chunkSize, $remaining);
            $sourceClans = $this->sourceClansQuery()
                ->where('id', '>', $lastId)
                ->limit($currentLimit)
                ->get();

            if ($sourceClans->isEmpty()) {
                return;
            }

            $callback($sourceClans);
            $lastId = (int) $sourceClans->last()->id;

            if ($remaining !== null) {
                $remaining -= $sourceClans->count();
            }
        }
    }

    private function sourceClansQuery()
    {
        return DB::connection(self::CONNECTION)
            ->table('clans')
            ->select(['id', 'name', 'tag'])
            ->orderBy('id');
    }

    private function missingSourceColumns(): array
    {
        $required = [
            'clans' => ['id', 'name', 'tag'],
        ];
        $missing = [];

        foreach ($required as $table => $columns) {
            try {
                $actual = Schema::connection(self::CONNECTION)->getColumnListing($table);
            } catch (Throwable) {
                $actual = [];
            }

            $diff = array_values(array_diff($columns, $actual));
            if ($diff !== []) {
                $missing[$table] = $diff;
            }
        }

        return $missing;
    }

    private function configureSource(array $config): bool
    {
        if (!get_bool($config['enabled'] ?? false)) {
            $this->error('Private-server source is disabled. Set M1PP_PRIVATE_SERVER_ENABLED=true before syncing.');

            return false;
        }

        $database = $config['database'] ?? [];
        $missing = array_keys(array_filter([
            'M1PP_PRIVATE_SERVER_DB_HOST' => $database['host'] ?? null,
            'M1PP_PRIVATE_SERVER_DB_DATABASE' => $database['database'] ?? null,
            'M1PP_PRIVATE_SERVER_DB_USERNAME' => $database['username'] ?? null,
        ], fn ($value) => !present($value)));

        if ($missing !== []) {
            $this->error('Missing private-server source DB config: '.implode(', ', $missing));

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

    private function assetTypes(): ?array
    {
        $type = $this->argument('type');
        if ($type === 'all') {
            return self::TYPES;
        }

        if (!in_array($type, self::TYPES, true)) {
            $this->error('type must be icons, covers, or all.');

            return null;
        }

        return [$type];
    }

    private function parseLimit($rawLimit): int|false|null
    {
        $limit = $this->nullableString($rawLimit);
        if ($limit === null) {
            return null;
        }

        if (!ctype_digit($limit)) {
            $this->error('--limit must be a positive integer.');

            return false;
        }

        $limit = (int) $limit;
        if ($limit <= 0 || $limit > self::MAX_LIMIT) {
            $this->error('--limit must be between 1 and '.self::MAX_LIMIT.'.');

            return false;
        }

        return $limit;
    }

    private function parseChunkSize($rawChunkSize): int|false
    {
        $chunkSize = $this->nullableString($rawChunkSize);
        if ($chunkSize === null) {
            return self::DEFAULT_CHUNK_SIZE;
        }

        if (!ctype_digit($chunkSize)) {
            $this->error('--chunk-size must be a positive integer.');

            return false;
        }

        $chunkSize = (int) $chunkSize;
        if ($chunkSize <= 0 || $chunkSize > self::MAX_CHUNK_SIZE) {
            $this->error('--chunk-size must be between 1 and '.self::MAX_CHUNK_SIZE.'.');

            return false;
        }

        return $chunkSize;
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function printSummary(array $summary, bool $dryRun): void
    {
        $this->line($dryRun ? 'Dry run complete; no clan assets were modified.' : 'Clan asset sync complete.');
        $this->line('target disk: '.ImportedAssets::diskName());
        $this->line('target key layouts: m1pposu/teams/flags/{local_team_id}.{ext}, m1pposu/teams/headers/{local_team_id}.{ext}');
        $this->line('public base URL: '.(ImportedAssets::publicBaseUrl() ?? '(filesystem disk URL)'));
        foreach (array_diff_key($summary, ['warnings' => true]) as $key => $value) {
            $this->line(str_replace('_', ' ', $key).": {$value}");
        }

        foreach (array_slice($summary['warnings'], 0, 25) as $warning) {
            $this->warn($warning);
        }

        if (count($summary['warnings']) > 25) {
            $this->warn('Additional warnings: '.(count($summary['warnings']) - 25));
        }
    }
}
