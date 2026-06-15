<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Libraries\M1pposu\LiveSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class M1pposuLiveCatchup extends Command
{
    private const SCORE_CURSOR_KEY = 'm1pposu:live:score-cursor';
    private const USER_CURSOR_KEY = 'm1pposu:live:user-cursor';

    protected $description = 'Incrementally project new private-server scores and users using monotonic source IDs.';

    protected $signature = 'm1pposu:live:catchup
        {--dry-run : Inspect the next bounded source batches without projecting or advancing cursors}
        {--after-score-id= : Override the score cursor for a dry-run only}';

    public function handle(LiveSynchronizer $synchronizer): int
    {
        if (get_bool(config('m1pposu.private_server.live.enabled')) !== true) {
            $this->error('Private-server live integration is disabled.');

            return static::FAILURE;
        }

        $dryRun = get_bool($this->option('dry-run'));
        $overrideScoreCursor = $this->parseCursor($this->option('after-score-id'));
        if ($overrideScoreCursor === false || (!$dryRun && $overrideScoreCursor !== null)) {
            $this->error('--after-score-id must be a non-negative integer and may only be used with --dry-run.');

            return static::FAILURE;
        }

        $batchSize = (int) config('m1pposu.private_server.live.catchup_batch_size');
        $maxBatches = (int) config('m1pposu.private_server.live.catchup_max_batches');
        $reconcileWindow = (int) config('m1pposu.private_server.live.catchup_reconcile_window');

        try {
            $scoreCursor = $overrideScoreCursor
                ?? $this->storedCursor(self::SCORE_CURSOR_KEY, fn () => $synchronizer->initialScoreCursor());
            $userCursor = $this->storedCursor(self::USER_CURSOR_KEY, fn () => $synchronizer->initialUserCursor());
            $scoreCount = 0;
            $userCount = 0;

            $reconcileIds = $synchronizer->recentUnprojectedScoreIds($scoreCursor, $reconcileWindow, $batchSize);
            if (!$dryRun && $reconcileIds !== []) {
                $synchronizer->syncScoreIds($reconcileIds);
            }
            $scoreCount += count($reconcileIds);

            for ($batch = 0; $batch < $maxBatches; $batch++) {
                $scoreIds = $synchronizer->sourceScoreIdsAfter($scoreCursor, $batchSize);
                if ($scoreIds === []) {
                    break;
                }

                if (!$dryRun) {
                    $synchronizer->syncScoreIds($scoreIds);
                }
                $scoreCursor = max($scoreIds);
                $scoreCount += count($scoreIds);

                if (!$dryRun) {
                    Cache::forever(self::SCORE_CURSOR_KEY, $scoreCursor);
                }
            }

            for ($batch = 0; $batch < $maxBatches; $batch++) {
                $userIds = $synchronizer->sourceUserIdsAfter($userCursor, $batchSize);
                if ($userIds === []) {
                    break;
                }

                if (!$dryRun) {
                    $synchronizer->syncUserIds($userIds);
                }
                $userCursor = max($userIds);
                $userCount += count($userIds);

                if (!$dryRun) {
                    Cache::forever(self::USER_CURSOR_KEY, $userCursor);
                }
            }
        } catch (Throwable $e) {
            report($e);
            $this->error("Live catch-up failed: {$e->getMessage()}");

            return static::FAILURE;
        }

        $verb = $dryRun ? 'would inspect' : 'projected';
        $this->info("Live catch-up {$verb} {$scoreCount} score rows and {$userCount} user rows.");
        $this->line("Score cursor: {$scoreCursor}; user cursor: {$userCursor}.");

        return static::SUCCESS;
    }

    private function storedCursor(string $key, callable $initial): int
    {
        $value = Cache::get($key);

        return is_numeric($value) ? max(0, (int) $value) : max(0, (int) $initial());
    }

    private function parseCursor($value): int|false|null
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        return ctype_digit($value) ? (int) $value : false;
    }
}
