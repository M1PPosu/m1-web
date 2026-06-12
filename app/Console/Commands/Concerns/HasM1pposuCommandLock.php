<?php

declare(strict_types=1);

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Cache;
use Throwable;

trait HasM1pposuCommandLock
{
    protected function withM1pposuCommandLock(string $key, callable $callback, int $seconds = 21600): int
    {
        try {
            $lock = Cache::lock($key, $seconds);
        } catch (Throwable $e) {
            $this->error("Unable to create command lock: {$e->getMessage()}");

            return static::FAILURE;
        }

        if (!$lock->get()) {
            $this->error("Another {$this->getName()} process is already running.");

            return static::FAILURE;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
