<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Libraries\M1pposu\ScoreDerivedProjector;
use Illuminate\Console\Command;

class M1pposuProjectScoreDerivedData extends Command
{
    protected $description = 'Project private-server first places and recent score activity.';

    protected $signature = 'm1pposu:scores:project-derived
        {--all : Rebuild every first place and recent score event}
        {--score-id=* : Project exact source scores.id values}
        {--recent-days=30 : Recent score activity window used with --all}';

    public function handle(ScoreDerivedProjector $projector): int
    {
        $all = get_bool($this->option('all'));
        $scoreIds = array_values(array_unique(array_filter(array_map('intval', $this->option('score-id')))));

        if ($all === ($scoreIds !== [])) {
            $this->error('Use either --all or one or more --score-id values.');

            return static::FAILURE;
        }

        if ($all) {
            $recentDays = filter_var($this->option('recent-days'), FILTER_VALIDATE_INT);
            if ($recentDays === false || $recentDays < 1 || $recentDays > 90) {
                $this->error('--recent-days must be between 1 and 90.');

                return static::FAILURE;
            }

            $summary = $projector->backfill($recentDays);
        } else {
            $summary = $projector->projectScoreIds($scoreIds);
        }

        foreach ($summary as $name => $count) {
            $this->line(str_replace('_', ' ', $name).": {$count}");
        }

        return static::SUCCESS;
    }
}
