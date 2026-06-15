<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Libraries\M1pposu\LiveEventProcessor;
use Illuminate\Console\Command;
use Redis;
use Throwable;

class M1pposuLiveListen extends Command
{
    protected $description = 'Consume private-server Redis events and immediately refresh affected projections.';

    protected $signature = 'm1pposu:live:listen';

    public function handle(LiveEventProcessor $processor): int
    {
        $config = config('m1pposu.private_server.live');
        if (get_bool($config['enabled'] ?? false) !== true) {
            $this->error('Private-server live integration is disabled.');

            return static::FAILURE;
        }

        $redisConfig = $config['redis'];
        $redis = new Redis();

        try {
            $redis->connect((string) $redisConfig['host'], (int) $redisConfig['port'], 5.0);
            $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

            if (present($redisConfig['password'] ?? null)) {
                $auth = present($redisConfig['username'] ?? null)
                    ? [(string) $redisConfig['username'], (string) $redisConfig['password']]
                    : (string) $redisConfig['password'];
                if ($redis->auth($auth) !== true) {
                    throw new \RuntimeException('Redis authentication failed.');
                }
            }

            if ($redis->select((int) $redisConfig['database']) !== true) {
                throw new \RuntimeException('Redis database selection failed.');
            }

            $channels = $redisConfig['channels'];
            $delayMicroseconds = (int) round((float) $config['event_delay_seconds'] * 1_000_000);
            $this->info('Listening for private-server live events on: '.implode(', ', $channels));

            $redis->subscribe($channels, function (Redis $redis, string $channel, string $payload) use ($delayMicroseconds, $processor) {
                if ($delayMicroseconds > 0) {
                    usleep($delayMicroseconds);
                }

                try {
                    $summary = $processor->handle($channel, $payload);
                    $this->line("Processed {$channel}: ".json_encode($summary, JSON_UNESCAPED_SLASHES));
                } catch (Throwable $e) {
                    report($e);
                    $this->error("Unable to process {$channel}: {$e->getMessage()}");
                }
            });
        } catch (Throwable $e) {
            $this->error("Live Redis listener failed: {$e->getMessage()}");

            return static::FAILURE;
        }

        return static::SUCCESS;
    }
}
