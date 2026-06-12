<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Libraries;

use App\Models\BanchoStats;
use App\Models\Count;
use Auth;
use Cache;

class CurrentStats
{
    public bool $available;
    public int $currentOnline;
    public int $currentGames;
    public array $graphData;
    public int $onlineFriends;
    public int $totalUsers;

    public function __construct()
    {
        $presenceEnabled = get_bool(config('m1pposu.features.presence') ?? false);
        $data = Cache::remember('current_stats:v2:'.($presenceEnabled ? '1' : '0'), 300, function () use ($presenceEnabled) {
            $stats = $presenceEnabled ? BanchoStats::stats() : [];
            $latest = array_last($stats);

            return [
                'available' => $latest !== null,
                'currentOnline' => $latest['users'] ?? 0,
                'currentGames' => ($latest['multiplayer_games'] ?? 0) + ($latest['multiplayer_games_lazer'] ?? 0),
                'graphData' => array_to_graph_json($stats, 'users'),
                'totalUsers' => Count::totalUsers()->count,
            ];
        });

        $this->available = $data['available'];
        $this->onlineFriends = $this->available && Auth::user()
            ? Auth::user()->friends()->online()->count()
            : 0;
        $this->currentOnline = $data['currentOnline'];
        $this->currentGames = $data['currentGames'];
        $this->graphData = $data['graphData'];
        $this->totalUsers = $data['totalUsers'];
    }
}
