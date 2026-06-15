<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Libraries;

use App\Libraries\M1pposu\LivePresence;
use App\Models\BanchoStats;
use App\Models\Count;
use Auth;
use Cache;

class CurrentStats
{
    public bool $available;
    public int $currentOnline;
    public ?int $currentGames;
    public array $graphData;
    public int $onlineFriends;
    public int $totalUsers;

    public function __construct()
    {
        $presence = app(LivePresence::class)->snapshot();
        $graphData = Cache::remember('current_stats:graph:v1', 300, function () {
            return array_to_graph_json(BanchoStats::stats(), 'users');
        });

        if ($presence['available']) {
            $graphData[] = [
                'x' => count($graphData),
                'y' => $presence['current_online'],
            ];
        }

        $this->available = $presence['available'];
        $this->onlineFriends = $this->available && Auth::user()
            ? Auth::user()->friends()->online()->count()
            : 0;
        $this->currentOnline = $presence['current_online'];
        $this->currentGames = null;
        $this->graphData = $graphData;
        $this->totalUsers = $presence['total_users']
            ?? Cache::remember('current_stats:total-users:v1', 300, fn () => Count::totalUsers()->count);
    }
}
