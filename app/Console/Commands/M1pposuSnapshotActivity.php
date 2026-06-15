<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Libraries\M1pposu\LivePresence;
use App\Models\BanchoStats;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class M1pposuSnapshotActivity extends Command
{
    private const CONNECTION = 'm1pposu-private-server-source';

    protected $description = 'Record live online activity or backfill recent activity from source score submissions.';
    protected $signature = 'm1pposu:activity:snapshot
        {--backfill-hours= : Backfill one-minute activity rows from real source scores}';

    public function handle(LivePresence $presence): int
    {
        $backfillHours = $this->option('backfill-hours');
        if ($backfillHours !== null) {
            $backfillHours = filter_var($backfillHours, FILTER_VALIDATE_INT);
            if ($backfillHours === false || $backfillHours < 1 || $backfillHours > 168) {
                $this->error('--backfill-hours must be between 1 and 168.');

                return static::FAILURE;
            }

            return $this->backfill($backfillHours);
        }

        $snapshot = $presence->snapshot();
        if (!$snapshot['available']) {
            $this->error('Bancho live presence is unavailable.');

            return static::FAILURE;
        }

        BanchoStats::create([
            'users_irc' => 0,
            'users_osu' => $snapshot['current_online'],
            'users_lazer' => 0,
            'multiplayer_games' => 0,
            'multiplayer_games_lazer' => 0,
            'date' => now(),
        ]);
        Cache::forget('current_stats:graph:v1');
        $this->info("Recorded {$snapshot['current_online']} online users.");

        return static::SUCCESS;
    }

    private function backfill(int $hours): int
    {
        $this->configureSource();
        $end = CarbonImmutable::now()->startOfMinute();
        $start = $end->subHours($hours);
        $counts = DB::connection(self::CONNECTION)
            ->table('scores')
            ->selectRaw("DATE_FORMAT(play_time, '%Y-%m-%d %H:%i:00') AS minute, COUNT(DISTINCT userid) AS users")
            ->whereBetween('play_time', [$start, $end])
            ->groupBy('minute')
            ->pluck('users', 'minute');
        $rows = [];

        for ($minute = $start; $minute->lt($end); $minute = $minute->addMinute()) {
            $date = $minute->toDateTimeString();
            $rows[] = [
                'users_irc' => 0,
                'users_osu' => (int) ($counts[$date] ?? 0),
                'users_lazer' => 0,
                'multiplayer_games' => 0,
                'multiplayer_games_lazer' => 0,
                'date' => $date,
            ];
        }

        DB::transaction(function () use ($rows, $start) {
            BanchoStats::where('date', '>=', $start)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                BanchoStats::insert($chunk);
            }
        });
        Cache::forget('current_stats:graph:v1');
        $this->info('Backfilled '.count($rows).' minute rows from real score activity.');

        return static::SUCCESS;
    }

    private function configureSource(): void
    {
        $database = config('m1pposu.private_server.database');
        Config::set('database.connections.'.self::CONNECTION, [
            ...config('database.connections.mysql'),
            'host' => $database['host'],
            'port' => $database['port'],
            'database' => $database['database'],
            'username' => $database['username'],
            'password' => $database['password'],
        ]);
        DB::purge(self::CONNECTION);
    }
}
