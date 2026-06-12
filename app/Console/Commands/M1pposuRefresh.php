<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class M1pposuRefresh extends Command
{
    private const DEFAULT_CHUNK_SIZE = 500;
    private const MAX_CHUNK_SIZE = 5000;
    private const LOCK_SECONDS = 21600;

    protected $description = 'Run the private-server projection refresh flow.';

    protected $signature = 'm1pposu:refresh
        {--users : Refresh projected users and stats}
        {--maps : Refresh projected map metadata needed for score display}
        {--scores : Refresh projected profile scores}
        {--userpages : Import source-authoritative userpages}
        {--clans : Refresh projected clans and team membership}
        {--clan-assets : Import source clan icons/covers when configured}
        {--avatars : Import source avatars when M1PP_SOURCE_AVATAR_PATH is configured}
        {--covers : Import source profile covers when M1PP_SOURCE_USER_COVER_PATH is configured}
        {--rankings : Refresh projected ranking fields}
        {--chunk-size= : Source rows to scan per chunk for source sync steps}
        {--dry-run : Show what would be refreshed without writing data}';

    public function handle(): int
    {
        $lock = null;

        try {
            $lock = Cache::lock('m1pposu:refresh', self::LOCK_SECONDS);
        } catch (Throwable $e) {
            $this->error("Unable to create refresh lock: {$e->getMessage()}");

            return static::FAILURE;
        }

        if (!$lock->get()) {
            $this->error('Another m1pposu refresh is already running.');

            return static::FAILURE;
        }

        try {
            return $this->runRefresh();
        } finally {
            $lock->release();
        }
    }

    private function runRefresh(): int
    {
        $chunkSize = $this->parseChunkSize($this->option('chunk-size'));
        if ($chunkSize === false) {
            return static::FAILURE;
        }

        $dryRun = get_bool($this->option('dry-run'));
        $selected = [
            'users' => get_bool($this->option('users')),
            'maps' => get_bool($this->option('maps')),
            'scores' => get_bool($this->option('scores')),
            'userpages' => get_bool($this->option('userpages')),
            'clans' => get_bool($this->option('clans')),
            'clan-assets' => get_bool($this->option('clan-assets')),
            'avatars' => get_bool($this->option('avatars')),
            'covers' => get_bool($this->option('covers')),
            'rankings' => get_bool($this->option('rankings')),
        ];

        if (!in_array(true, $selected, true)) {
            $selected = array_fill_keys(array_keys($selected), true);
            $selected['clan-assets'] = present(config('m1pposu.clans.source_icon_path')) || present(config('m1pposu.clans.source_cover_path'));
            $selected['avatars'] = present(config('m1pposu.users.source_avatar_path'));
            $selected['covers'] = present(config('m1pposu.users.source_cover_path'));
        }

        $steps = [
            'users' => ['m1pposu:sync:users', ['--all' => true, '--chunk-size' => $chunkSize]],
            'maps' => ['m1pposu:sync:maps', ['--all' => true, '--chunk-size' => $chunkSize]],
            'scores' => ['m1pposu:sync:scores', ['--all' => true, '--chunk-size' => $chunkSize]],
            'userpages' => ['m1pposu:sync:userpages', ['--all' => true, '--chunk-size' => $chunkSize]],
            'clans' => ['m1pposu:sync:clans', ['--all' => true, '--chunk-size' => min($chunkSize, 500)]],
            'clan-assets' => ['m1pposu:sync:clan-assets', ['type' => 'all', '--all' => true, '--chunk-size' => min($chunkSize, 500)]],
            'avatars' => ['m1pposu:sync:user-assets', ['type' => 'avatars', '--all' => true, '--chunk-size' => $chunkSize]],
            'covers' => ['m1pposu:sync:user-assets', ['type' => 'covers', '--all' => true, '--chunk-size' => $chunkSize]],
            'rankings' => ['m1pposu:rankings:refresh', []],
        ];

        foreach ($steps as $name => [$command, $arguments]) {
            if (!$selected[$name]) {
                continue;
            }

            if ($dryRun) {
                $arguments['--dry-run'] = true;
            }

            $this->line("Running {$command}...");
            $result = $this->call($command, $arguments);

            if ($result !== static::SUCCESS) {
                $this->error("Refresh stopped because {$command} failed.");

                return $result;
            }
        }

        $this->info($dryRun ? 'Refresh dry-run completed.' : 'Refresh completed.');

        return static::SUCCESS;
    }

    private function parseChunkSize($value): int|false
    {
        if ($value === null || trim((string) $value) === '') {
            return self::DEFAULT_CHUNK_SIZE;
        }

        if (!ctype_digit((string) $value)) {
            $this->error('--chunk-size must be a positive integer.');

            return false;
        }

        $value = (int) $value;
        if ($value <= 0 || $value > self::MAX_CHUNK_SIZE) {
            $this->error('--chunk-size must be between 1 and '.self::MAX_CHUNK_SIZE.'.');

            return false;
        }

        return $value;
    }
}
