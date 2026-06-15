<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        if (get_bool(config('m1pposu.private_server.enabled')) === true) {
            $schedule->command('m1pposu:refresh --no-interaction')
                ->dailyAt('02:30')
                ->withoutOverlapping(360)
                ->onOneServer();

            if (get_bool(config('m1pposu.private_server.live.enabled')) === true) {
                $schedule->command('m1pposu:live:catchup --no-interaction')
                    ->everyMinute()
                    ->withoutOverlapping(10)
                    ->onOneServer();

                $schedule->command('m1pposu:sync:clans --all --no-interaction')
                    ->everyFiveMinutes()
                    ->withoutOverlapping(30)
                    ->onOneServer();

                $schedule->command('m1pposu:sync:userpages --all --no-interaction')
                    ->everyTenMinutes()
                    ->withoutOverlapping(30)
                    ->onOneServer();

                $schedule->command('m1pposu:activity:snapshot --no-interaction')
                    ->everyMinute()
                    ->withoutOverlapping(5)
                    ->onOneServer();
            }

            $schedule->command('m1pposu:chat:ensure-defaults --no-interaction')
                ->dailyAt('03:10')
                ->withoutOverlapping(10)
                ->onOneServer();
        }

        $schedule->command('archive-forum-topics')
            ->cron('*/30 * * * *')
            ->withoutOverlapping(120)
            ->onOneServer();

        $schedule->command('store:cleanup-stale-orders')
            ->daily()
            ->onOneServer();

        $schedule->command('store:expire-products')
            ->hourly()
            ->onOneServer();

        $schedule->command('builds:update-propagation-history')
            ->everyThirtyMinutes()
            ->onOneServer();

        $schedule->command('forum:topic-cover-cleanup --no-interaction')
            ->daily()
            ->onOneServer();

        $schedule->command('rankings:recalculate-country-stats')
            ->cron('25 0,3,6,9,12,15,18,21 * * *')
            ->onOneServer();

        $schedule->command('rankings:recalculate-team-stats')
            ->cron('25 0,3,6,9,12,15,18,21 * * *')
            ->onOneServer();

        $schedule->command('rankings:recalculate-top-plays')
            ->cron('35 0,3,6,9,12,15,18,21 * * *')
            ->onOneServer();

        $schedule->command('modding:rank')
            ->cron('*/20 * * * *')
            ->withoutOverlapping(120)
            ->onOneServer();

        $schedule->command('oauth:delete-expired-tokens')
            ->cron('14 1 * * *')
            ->onOneServer();

        $schedule->command('notifications:news-published')
            ->everyThirtyMinutes()
            ->withoutOverlapping(120)
            ->onOneServer();

        $schedule->command('notifications:send-mail')
            ->hourly()
            ->withoutOverlapping(120)
            ->onOneServer();

        $schedule->command('user-notifications:cleanup')
            ->everyThirtyMinutes()
            ->withoutOverlapping(120)
            ->onOneServer();

        $schedule->command('notifications:cleanup')
            ->cron('15,45 * * * *')
            ->withoutOverlapping(120)
            ->onOneServer();

        $schedule->command('chat:expire-ack')
            ->everyFiveMinutes()
            ->withoutOverlapping(30)
            ->onOneServer();

        $schedule->command('daily-challenge:create-next')
            ->cron('5 0 * * *')
            ->onOneServer();

        $schedule->command('daily-challenge:user-stats-calculate')
            ->cron('10 0 * * *')
            ->onOneServer();

        $schedule->command('user-count-by-ruleset:recalculate')
            ->cron('20 0 * * *')
            ->withoutOverlapping(120)
            ->onOneServer();
    }
}
