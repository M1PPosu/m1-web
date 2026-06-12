<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\HasM1pposuCommandLock;
use App\Models\M1pposuExternalUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class M1pposuSyncUserpages extends Command
{
    use HasM1pposuCommandLock;

    private const CONNECTION = 'm1pposu-private-server-source';
    private const DEFAULT_CHUNK_SIZE = 500;
    private const MAX_LIMIT = 1000;
    private const MAX_CHUNK_SIZE = 2000;

    protected $signature = 'm1pposu:sync:userpages
        {--external-id= : Sync one source userpage by source users.id}
        {--limit= : Maximum source userpages to scan}
        {--all : Sync every source userpage using chunked processing}
        {--chunk-size= : Source userpages to scan per chunk}
        {--preserve-existing : Do not overwrite existing web userpages}
        {--dry-run : Show what would be synced without writing data}';

    protected $description = 'Import private-server userpage text into osu-web forum-backed userpages.';

    public function handle(): int
    {
        return $this->withM1pposuCommandLock('m1pposu:sync:userpages', fn () => $this->handleLocked());
    }

    private function handleLocked(): int
    {
        $dryRun = get_bool($this->option('dry-run'));
        $preserveExisting = get_bool($this->option('preserve-existing'));
        $all = get_bool($this->option('all'));
        $externalId = $this->nullableString($this->option('external-id'));
        $limit = $this->parseLimit($this->option('limit'));
        $chunkSize = $this->parseChunkSize($this->option('chunk-size'));

        if ($limit === false || $chunkSize === false) {
            return static::FAILURE;
        }

        if ($all && ($externalId !== null || $limit !== null)) {
            $this->error('Use --all by itself, not with --external-id or --limit.');

            return static::FAILURE;
        }

        if ($externalId === null && $limit === null && !$all) {
            $this->error('Refusing to run an unbounded userpage sync. Use --external-id, --limit, or --all.');

            return static::FAILURE;
        }

        $config = Config::get('m1pposu.private_server', []);
        if (!$this->configureSource($config)) {
            return static::FAILURE;
        }

        $supportsUserpages = $this->hasSourceColumns('userpages', ['user_id', 'raw', 'html', 'raw_type']);
        $supportsUserContent = $this->hasSourceColumns('users', ['id', 'userpage_content']);

        if (!$supportsUserpages && !$supportsUserContent) {
            $this->error('Source database does not expose userpages.raw or users.userpage_content.');

            return static::FAILURE;
        }

        $backend = $config['backend'] ?: 'bancho-py-ex';
        $summary = [
            'source_userpages_scanned' => 0,
            'imported_new_userpages' => 0,
            'overwritten_existing_userpages' => 0,
            'skipped_existing_web_userpages' => 0,
            'skipped_missing_mapping' => 0,
            'skipped_empty_source_userpages' => 0,
            'skipped_unsafe_incompatible_source_format' => 0,
            'warnings' => [],
        ];

        try {
            $this->forEachSourceUserpageBatch($externalId, $limit, $all, $chunkSize, $supportsUserpages, $supportsUserContent, function ($rows) use ($backend, $dryRun, $preserveExisting, &$summary) {
                foreach ($rows as $row) {
                    $this->processUserpage($row, $backend, $dryRun, $preserveExisting, $summary);
                }
            });
        } catch (Throwable $e) {
            $this->error("Unable to query source userpages: {$e->getMessage()}");

            return static::FAILURE;
        }

        $this->printSummary($summary, $dryRun);

        return static::SUCCESS;
    }

    private function processUserpage(object $row, string $backend, bool $dryRun, bool $preserveExisting, array &$summary): void
    {
        $summary['source_userpages_scanned']++;

        $body = $this->sourceBody($row, $summary);
        if ($body === null) {
            return;
        }

        $mapping = M1pposuExternalUser::query()
            ->where('backend', $backend)
            ->where('external_user_id', (string) $row->user_id)
            ->first();
        $user = $mapping === null ? null : User::find($mapping->user_id);

        if ($user === null) {
            $summary['skipped_missing_mapping']++;

            return;
        }

        $hasExistingPage = $user->userPage !== null;
        if ($hasExistingPage && $preserveExisting) {
            $summary['skipped_existing_web_userpages']++;

            return;
        }

        if ($dryRun) {
            $summary[$hasExistingPage ? 'overwritten_existing_userpages' : 'imported_new_userpages']++;

            return;
        }

        try {
            $user->updatePage($body);
            $summary[$hasExistingPage ? 'overwritten_existing_userpages' : 'imported_new_userpages']++;
        } catch (Throwable $e) {
            $summary['warnings'][] = "source user {$row->user_id}: {$e->getMessage()}";
        }
    }

    private function sourceBody(object $row, array &$summary): ?string
    {
        $raw = $this->nullableString($row->raw ?? null);
        if ($raw !== null) {
            return $raw;
        }

        $fallback = $this->nullableString($row->userpage_content ?? null);
        if ($fallback !== null) {
            return $fallback;
        }

        $html = $this->nullableString($row->html ?? null);
        if ($html !== null) {
            $summary['skipped_unsafe_incompatible_source_format']++;

            return null;
        }

        $summary['skipped_empty_source_userpages']++;

        return null;
    }

    private function forEachSourceUserpageBatch(?string $externalId, ?int $limit, bool $all, int $chunkSize, bool $supportsUserpages, bool $supportsUserContent, callable $callback): void
    {
        if ($externalId !== null) {
            $callback($this->sourceUserpagesQuery($supportsUserpages, $supportsUserContent)->where('users.id', $externalId)->get());

            return;
        }

        $lastId = 0;
        $remaining = $all ? null : $limit;

        while ($all || $remaining > 0) {
            $currentLimit = $remaining === null ? $chunkSize : min($chunkSize, $remaining);
            $rows = $this->sourceUserpagesQuery($supportsUserpages, $supportsUserContent)
                ->where('users.id', '>', $lastId)
                ->limit($currentLimit)
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $callback($rows);
            $lastId = (int) $rows->last()->user_id;

            if ($remaining !== null) {
                $remaining -= $rows->count();
            }
        }
    }

    private function sourceUserpagesQuery(bool $supportsUserpages, bool $supportsUserContent)
    {
        $query = DB::connection(self::CONNECTION)
            ->table('users')
            ->select('users.id AS user_id')
            ->orderBy('users.id');

        if ($supportsUserpages) {
            $query
                ->leftJoin('userpages', 'userpages.user_id', '=', 'users.id')
                ->addSelect(['userpages.raw', 'userpages.html', 'userpages.raw_type']);
        } else {
            $query->addSelect(DB::raw('NULL AS raw'));
            $query->addSelect(DB::raw('NULL AS html'));
            $query->addSelect(DB::raw('NULL AS raw_type'));
        }

        if ($supportsUserContent) {
            $query->addSelect('users.userpage_content');
        } else {
            $query->addSelect(DB::raw('NULL AS userpage_content'));
        }

        return $query->where(function ($q) use ($supportsUserContent, $supportsUserpages) {
            if ($supportsUserpages) {
                $q
                    ->where(fn ($nested) => $nested->whereNotNull('userpages.raw')->where('userpages.raw', '<>', ''))
                    ->orWhere(fn ($nested) => $nested->whereNotNull('userpages.html')->where('userpages.html', '<>', ''));
            }

            if ($supportsUserContent) {
                $method = $supportsUserpages ? 'orWhere' : 'where';
                $q->{$method}(fn ($nested) => $nested->whereNotNull('users.userpage_content')->where('users.userpage_content', '<>', ''));
            }
        });
    }

    private function hasSourceColumns(string $table, array $columns): bool
    {
        try {
            $actual = Schema::connection(self::CONNECTION)->getColumnListing($table);
        } catch (Throwable) {
            return false;
        }

        return array_diff($columns, $actual) === [];
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
        $this->line($dryRun ? 'Dry run complete; no userpages were modified.' : 'Userpage sync complete.');
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
