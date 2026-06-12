<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class M1pposuSourceImportDump extends Command
{
    private const OUTPUT_LIMIT = 8000;

    protected $signature = 'm1pposu:source:import-dump
        {dump : Path to a .sql or .sql.gz dump inside the container}
        {--database= : Separate source database to create/import into}
        {--dry-run : Validate the dump path and target database without importing}';

    protected $description = 'Import a private-server SQL dump into a separate local source database.';

    public function handle(): int
    {
        $dumpPath = $this->resolveDumpPath((string) $this->argument('dump'));
        $targetDatabase = trim((string) $this->option('database'));
        $dryRun = get_bool($this->option('dry-run'));

        if ($targetDatabase === '') {
            $this->error('The --database option is required.');

            return static::FAILURE;
        }

        if (!$this->validateTargetDatabase($targetDatabase)) {
            return static::FAILURE;
        }

        if (!$this->validateDumpPath($dumpPath)) {
            return static::FAILURE;
        }

        if (!$this->validateDumpSqlSafety($dumpPath)) {
            return static::FAILURE;
        }

        $databaseConfig = Config::get('database.connections.mysql');
        $this->line("Dump file: {$dumpPath}");
        $this->line("Target source database: {$targetDatabase}");
        $this->line("MySQL server: {$databaseConfig['host']}:{$databaseConfig['port']}");
        $this->line("MySQL user: {$databaseConfig['username']}");

        if ($dryRun) {
            $this->info('Dry run passed. No database was created and no dump was imported.');

            return static::SUCCESS;
        }

        try {
            $this->createTargetDatabase($targetDatabase);
            $this->importDump($dumpPath, $targetDatabase, $databaseConfig);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return static::FAILURE;
        }

        $this->info("Imported dump into separate source database {$targetDatabase}.");
        $this->line('No osu-web application tables were modified by this command.');

        return static::SUCCESS;
    }

    private function resolveDumpPath(string $path): string
    {
        return Str::startsWith($path, DIRECTORY_SEPARATOR)
            ? $path
            : base_path($path);
    }

    private function validateDumpPath(string $path): bool
    {
        if (!is_file($path)) {
            $this->error("Dump file does not exist: {$path}");

            return false;
        }

        if (!is_readable($path)) {
            $this->error("Dump file is not readable: {$path}");

            return false;
        }

        $lowerPath = strtolower($path);
        if (!Str::endsWith($lowerPath, ['.sql', '.sql.gz'])) {
            $this->error('Dump file must end with .sql or .sql.gz.');

            return false;
        }

        if (Str::endsWith($lowerPath, '.sql.gz') && !function_exists('gzopen')) {
            $this->error('PHP zlib support is required to import .sql.gz dumps.');

            return false;
        }

        return true;
    }

    private function validateTargetDatabase(string $database): bool
    {
        if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $database)) {
            $this->error('Target database may only contain letters, numbers, and underscores.');

            return false;
        }

        $forbiddenDatabases = $this->forbiddenDatabaseNames();
        if (in_array(strtolower($database), $forbiddenDatabases, true)) {
            $this->error("Refusing to import a source dump into protected database {$database}.");
            $this->line('Use a separate source database name such as banchopy_source.');

            return false;
        }

        return true;
    }

    private function forbiddenDatabaseNames(): array
    {
        $ret = [
            'information_schema',
            'mysql',
            'osu',
            'performance_schema',
            'sys',
        ];

        foreach (Config::get('database.connections') as $connection) {
            if (($connection['driver'] ?? null) === 'mysql' && present($connection['database'] ?? null)) {
                $ret[] = strtolower($connection['database']);
            }
        }

        return array_values(array_unique($ret));
    }

    private function createTargetDatabase(string $database): void
    {
        DB::statement("CREATE DATABASE IF NOT EXISTS {$this->quoteIdentifier($database)} CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    }

    private function importDump(string $dumpPath, string $targetDatabase, array $databaseConfig): void
    {
        $command = [
            'mysql',
            '--protocol=TCP',
            "--host={$databaseConfig['host']}",
            "--port={$databaseConfig['port']}",
            "--user={$databaseConfig['username']}",
            '--default-character-set=utf8mb4',
            '--binary-mode=1',
            $targetDatabase,
        ];

        $env = null;
        if (present($databaseConfig['password'] ?? null)) {
            $env = [
                'MYSQL_PWD' => $databaseConfig['password'],
                'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            ];
        }

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, base_path(), $env);

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start mysql client.');
        }

        $output = '';
        $errorOutput = '';

        try {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $this->streamDumpToProcess($dumpPath, $pipes[0], $pipes[1], $pipes[2], $output, $errorOutput);

            fclose($pipes[0]);

            stream_set_blocking($pipes[1], true);
            stream_set_blocking($pipes[2], true);
            $this->appendOutput($output, stream_get_contents($pipes[1]) ?: '');
            $this->appendOutput($errorOutput, stream_get_contents($pipes[2]) ?: '');
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);
        } catch (RuntimeException $e) {
            proc_terminate($process);

            throw $e;
        }

        if ($exitCode !== 0) {
            $message = "mysql import failed with exit code {$exitCode}.";
            if ($errorOutput !== '') {
                $message .= "\n".$errorOutput;
            }

            throw new RuntimeException($message);
        }
    }

    private function validateDumpSqlSafety(string $dumpPath): bool
    {
        $handle = $this->openDumpHandle($dumpPath);
        if ($handle === false) {
            $this->error("Could not open dump file: {$dumpPath}");

            return false;
        }

        $isGzip = Str::endsWith(strtolower($dumpPath), '.sql.gz');
        $scan = '';

        try {
            while (!$this->dumpEof($handle, $isGzip)) {
                $chunk = $this->readDumpChunk($handle, $isGzip);

                if ($chunk === false) {
                    $this->error("Could not read dump file: {$dumpPath}");

                    return false;
                }

                $scan .= $chunk;

                if (preg_match('/(?:^|[;\r\n])\s*(?:use|create\s+database|drop\s+database|alter\s+database)\b/i', $scan)) {
                    $this->error('Dump contains database-level SQL statements such as USE or CREATE DATABASE.');
                    $this->line('Re-export without mysqldump --databases so the dump imports only into the explicit --database target.');

                    return false;
                }

                $scan = substr($scan, -128);
            }
        } finally {
            $isGzip ? gzclose($handle) : fclose($handle);
        }

        return true;
    }

    private function streamDumpToProcess(string $dumpPath, $stdin, $stdout, $stderr, string &$output, string &$errorOutput): void
    {
        $isGzip = Str::endsWith(strtolower($dumpPath), '.sql.gz');
        $handle = $this->openDumpHandle($dumpPath);

        if ($handle === false) {
            throw new RuntimeException("Could not open dump file: {$dumpPath}");
        }

        try {
            while (!$this->dumpEof($handle, $isGzip)) {
                $chunk = $this->readDumpChunk($handle, $isGzip);

                if ($chunk === false) {
                    throw new RuntimeException("Could not read dump file: {$dumpPath}");
                }

                if ($chunk === '') {
                    continue;
                }

                $this->writeChunk($stdin, $chunk);
                $this->appendOutput($output, stream_get_contents($stdout) ?: '');
                $this->appendOutput($errorOutput, stream_get_contents($stderr) ?: '');
            }
        } finally {
            $isGzip ? gzclose($handle) : fclose($handle);
        }
    }

    private function dumpEof($handle, bool $isGzip): bool
    {
        return $isGzip ? gzeof($handle) : feof($handle);
    }

    private function openDumpHandle(string $dumpPath)
    {
        return Str::endsWith(strtolower($dumpPath), '.sql.gz')
            ? gzopen($dumpPath, 'rb')
            : fopen($dumpPath, 'rb');
    }

    private function readDumpChunk($handle, bool $isGzip): string|false
    {
        return $isGzip ? gzread($handle, 1024 * 1024) : fread($handle, 1024 * 1024);
    }

    private function writeChunk($stdin, string $chunk): void
    {
        $offset = 0;
        $length = strlen($chunk);

        while ($offset < $length) {
            $written = fwrite($stdin, substr($chunk, $offset));

            if ($written === false) {
                throw new RuntimeException('Could not write dump data to mysql client.');
            }

            if ($written === 0) {
                usleep(10000);
                continue;
            }

            $offset += $written;
        }
    }

    private function appendOutput(string &$buffer, string $chunk): void
    {
        if ($chunk === '' || strlen($buffer) >= static::OUTPUT_LIMIT) {
            return;
        }

        $buffer .= substr($chunk, 0, static::OUTPUT_LIMIT - strlen($buffer));
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
