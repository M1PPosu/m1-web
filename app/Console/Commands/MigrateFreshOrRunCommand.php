<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateFreshOrRunCommand extends Command
{
    protected $signature = 'migrate:fresh-or-run';

    protected $description = 'Initialize empty databases or run pending migrations without overwriting an untracked schema';

    protected $migrator;

    public function __construct()
    {
        $this->migrator = app('migration.repository');

        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->migrator->repositoryExists() && $this->migrator->getLastBatchNumber() !== null) {
            return $this->migrate();
        }

        $existingTables = $this->existingTablesWithoutMigrationHistory();

        if ($existingTables !== []) {
            $this->error(
                'Refusing automatic database initialization: migration history is missing or empty, '
                .'but application tables already exist.',
            );
            $this->line('Existing tables include: '.implode(', ', array_slice($existingTables, 0, 12)));
            $this->line(
                'The databases and Docker volumes were preserved. Back up and repair the schema/migration history '
                .'manually before retrying.',
            );
            $this->line(
                'For disposable local Docker data only, an explicit `docker compose down -v` followed by '
                .'`docker compose up -d` creates a clean database.',
            );

            return self::FAILURE;
        }

        return $this->fresh();
    }

    private function existingTablesWithoutMigrationHistory(): array
    {
        $defaultConnection = config('database.default');
        $migrationTable = config('database.migrations');
        $tables = [];

        foreach (array_keys(config('database.connections')) as $connectionName) {
            foreach (DB::connection($connectionName)->getSchemaBuilder()->getTableListing() as $tableName) {
                if ($connectionName === $defaultConnection && $tableName === $migrationTable) {
                    continue;
                }

                $tables[] = "{$connectionName}.{$tableName}";
            }
        }

        sort($tables);

        return $tables;
    }

    private function fresh(): int
    {
        $this->info('Application databases are empty. Initializing the schema and Elasticsearch indexes.');

        return $this->call('migrate:fresh', ['--no-interaction' => true]);
    }

    private function migrate(): int
    {
        $this->info('Running pending migrations...');

        return $this->call('migrate', ['--step' => true]);
    }
}
