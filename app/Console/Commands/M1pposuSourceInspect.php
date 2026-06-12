<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Libraries\M1pposu\SourceMode;
use App\Libraries\M1pposu\SourcePrivileges;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

class M1pposuSourceInspect extends Command
{
    protected $description = 'Inspect the configured private-server source database schema without importing data.';

    protected $signature = 'm1pposu:source:inspect';

    private const CONNECTION = 'm1pposu-private-server-source';

    public function handle(): int
    {
        $config = config('m1pposu.private_server');

        if (get_bool($config['enabled'] ?? false) !== true) {
            $this->error('Private-server source is disabled.');
            $this->line('Set M1PP_PRIVATE_SERVER_ENABLED=true and configure the source database before inspecting.');

            return static::FAILURE;
        }

        $missing = $this->missingDatabaseConfig($config['database'] ?? []);
        if ($missing !== []) {
            $this->error('Private-server source database config is incomplete.');
            foreach ($missing as $key) {
                $this->line("- {$key}");
            }

            return static::FAILURE;
        }

        $this->configureConnection($config['database']);

        try {
            $connection = DB::connection(static::CONNECTION);
            $connection->getPdo();
        } catch (Throwable $e) {
            $this->error('Could not connect to private-server source database.');
            $this->line($e->getMessage());
            $this->printConnectionHint($config['database'], $e);

            return static::FAILURE;
        }

        $backend = $config['backend'] ?? 'bancho-py-ex';
        $databaseName = (string) ($config['database']['database'] ?? '');
        $this->info("Connected to {$backend} source database.");
        $this->line("Source database: {$databaseName}");
        $this->line('Source mode: '.$this->sourceModeLabel($databaseName));

        $tables = $this->tableNames();
        $this->line('Tables found: '.count($tables));

        $candidates = $this->candidateTables($tables);
        if ($candidates === []) {
            $this->warn('No candidate user/stat/score/beatmap/restriction tables were detected by name.');

            return static::SUCCESS;
        }

        $rows = [];
        foreach ($candidates as $table => $categories) {
            $rows[] = [
                implode(', ', $categories),
                $table,
                implode(', ', array_slice($this->columnNames($table), 0, 24)),
            ];
        }

        $this->table(['candidate', 'table', 'columns'], $rows);
        $this->printPrivilegeSummary($tables);
        $this->printSilenceSummary($tables);
        $this->printSourceModeSummary($tables);
        $this->line('No data was imported or modified.');

        return static::SUCCESS;
    }

    private function configureConnection(array $database): void
    {
        Config::set('database.connections.'.static::CONNECTION, [
            ...config('database.connections.mysql'),
            'host' => $database['host'],
            'port' => $database['port'],
            'database' => $database['database'],
            'username' => $database['username'],
            'password' => $database['password'],
        ]);

        DB::purge(static::CONNECTION);
    }

    private function candidateTables(array $tables): array
    {
        $patterns = [
            'users' => ['user', 'account'],
            'stats' => ['stat', 'performance'],
            'scores' => ['score'],
            'beatmaps' => ['beatmap', 'map', 'mapset'],
            'restrictions' => ['ban', 'restrict', 'silence', 'privilege', 'permission', 'group'],
        ];

        $ret = [];
        foreach ($tables as $table) {
            $tableLower = strtolower($table);
            foreach ($patterns as $category => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($tableLower, $needle)) {
                        $ret[$table][] = $category;
                        break;
                    }
                }
            }
        }

        return $ret;
    }

    private function printPrivilegeSummary(array $tables): void
    {
        if (!in_array('users', $tables, true) || !in_array('priv', $this->columnNames('users'), true)) {
            return;
        }

        $rows = DB::connection(static::CONNECTION)
            ->table('users')
            ->select('priv', DB::raw('COUNT(*) AS users'))
            ->groupBy('priv')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('priv')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                $row->priv,
                $row->users,
                match (SourcePrivileges::isRestricted($row->priv)) {
                    true => 'restricted',
                    false => 'unrestricted',
                    null => 'unknown',
                },
            ])
            ->all();

        if ($rows === []) {
            return;
        }

        $this->line('Source users.priv values detected:');
        $this->line('Restriction projection uses bancho.py-ex Privileges.UNRESTRICTED (1 << 0). Values without that bit are restricted.');
        $this->table(['priv', 'users', 'projected status'], $rows);
    }

    private function printSilenceSummary(array $tables): void
    {
        if (!in_array('users', $tables, true) || !in_array('silence_end', $this->columnNames('users'), true)) {
            return;
        }

        $rows = DB::connection(static::CONNECTION)
            ->table('users')
            ->selectRaw("
                CASE
                    WHEN silence_end = 0 THEN 'none'
                    WHEN silence_end > UNIX_TIMESTAMP() THEN 'active'
                    ELSE 'expired'
                END AS status,
                COUNT(*) AS users
            ")
            ->groupBy('status')
            ->orderByRaw("FIELD(status, 'active', 'expired', 'none')")
            ->get()
            ->map(fn ($row) => [$row->status, $row->users])
            ->all();

        if ($rows === []) {
            return;
        }

        $this->line('Source users.silence_end status:');
        $this->table(['status', 'users'], $rows);
    }

    private function printSourceModeSummary(array $tables): void
    {
        foreach (['stats', 'scores'] as $table) {
            if (!in_array($table, $tables, true) || !in_array('mode', $this->columnNames($table), true)) {
                continue;
            }

            $rows = DB::connection(static::CONNECTION)
                ->table($table)
                ->select('mode', DB::raw('COUNT(*) AS source_rows'))
                ->groupBy('mode')
                ->orderBy('mode')
                ->get()
                ->map(function ($row) {
                    $sourceMode = SourceMode::mode($row->mode);
                    $label = $sourceMode === null
                        ? 'unsupported/deferred'
                        : $sourceMode['ruleset'].($sourceMode['variant'] === null ? ' standard' : " {$sourceMode['variant']}");

                    return [$row->mode, $label, $row->source_rows];
                })
                ->all();

            if ($rows !== []) {
                $this->line("Source {$table}.mode values detected:");
                $this->table(['mode', 'projection', 'rows'], $rows);
            }
        }
    }

    private function columnNames(string $table): array
    {
        return array_map(
            fn ($column) => $column->Field,
            DB::connection(static::CONNECTION)->select("SHOW COLUMNS FROM {$this->quoteIdentifier($table)}")
        );
    }

    private function missingDatabaseConfig(array $database): array
    {
        $required = [
            'M1PP_PRIVATE_SERVER_DB_HOST' => $database['host'] ?? null,
            'M1PP_PRIVATE_SERVER_DB_DATABASE' => $database['database'] ?? null,
            'M1PP_PRIVATE_SERVER_DB_USERNAME' => $database['username'] ?? null,
        ];

        return array_keys(array_filter($required, fn ($value) => !present($value)));
    }

    private function printConnectionHint(array $database, Throwable $e): void
    {
        $host = strtolower((string) ($database['host'] ?? ''));

        if ($host === 'mysql' && str_contains($e->getMessage(), 'php_network_getaddresses')) {
            $this->line('For the local Docker dump workflow, use M1PP_PRIVATE_SERVER_DB_HOST=db.');
        }
    }

    private function sourceModeLabel(string $database): string
    {
        $database = strtolower(trim($database));

        if ($database === 'banchopy') {
            return 'live source database';
        }

        if ($database === 'banchopy_source' || str_ends_with($database, '_source')) {
            return 'local/imported dump source database';
        }

        return 'custom source database; verify whether this is live or an imported dump';
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function tableNames(): array
    {
        return array_map(
            fn ($row) => array_values((array) $row)[0],
            DB::connection(static::CONNECTION)->select('SHOW TABLES')
        );
    }
}
