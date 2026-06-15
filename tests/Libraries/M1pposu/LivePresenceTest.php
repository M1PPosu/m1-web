<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Libraries\M1pposu;

use App\Libraries\M1pposu\LivePresence;
use App\Models\M1pposuExternalUser;
use App\Models\User;
use App\Models\UserRelation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LivePresenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config_set('m1pposu.features.presence', true);
        config_set('m1pposu.private_server.backend', 'bancho-py-ex');
        config_set('m1pposu.private_server.presence.base_url', 'http://bancho:10000/v1');
        config_set('m1pposu.private_server.presence.host_header', 'localhost');
        config_set('m1pposu.private_server.presence.timeout_seconds', 2);
        config_set('m1pposu.private_server.presence.cache_seconds', 5);
        Cache::forget('m1pposu:live-presence:v1');
    }

    public function testMapsBanchoOnlinePlayersToWebUsers(): void
    {
        $user = User::factory()->create(['user_allow_viewonline' => true]);
        M1pposuExternalUser::create([
            'backend' => 'bancho-py-ex',
            'external_user_id' => '924',
            'external_username' => 'source-user',
            'user_id' => $user->getKey(),
        ]);

        Http::fake([
            'http://bancho:10000/v1/get_player_count' => Http::response([
                'status' => 'success',
                'counts' => ['online' => 1, 'total' => 1257],
            ]),
            'http://bancho:10000/v1/online' => Http::response([
                'status' => 'success',
                'players' => [['id' => 924, 'name' => 'source-user']],
                'bots' => [['id' => 1, 'name' => 'bot']],
            ]),
        ]);

        $snapshot = app(LivePresence::class)->snapshot();

        $this->assertTrue($snapshot['available']);
        $this->assertSame(1, $snapshot['current_online']);
        $this->assertSame(1257, $snapshot['total_users']);
        $this->assertSame([$user->getKey()], $snapshot['user_ids']);
        $this->assertTrue($user->isOnline());
        $this->assertSame([$user->getKey()], User::query()->online()->pluck('user_id')->all());

        $viewer = User::factory()->create();
        UserRelation::factory()->friend()->create([
            'user_id' => $viewer,
            'zebra_id' => $user,
        ]);
        $this->assertSame(1, $viewer->friends()->online()->count());

        Http::assertSent(fn ($request) => $request->hasHeader('Host', 'localhost'));
    }

    public function testZeroPlayersIsAvailable(): void
    {
        Http::fake([
            'http://bancho:10000/v1/get_player_count' => Http::response([
                'status' => 'success',
                'counts' => ['online' => 0, 'total' => 1257],
            ]),
            'http://bancho:10000/v1/online' => Http::response([
                'status' => 'success',
                'players' => [],
                'bots' => [['id' => 1, 'name' => 'bot']],
            ]),
        ]);

        $snapshot = app(LivePresence::class)->snapshot();

        $this->assertTrue($snapshot['available']);
        $this->assertSame(0, $snapshot['current_online']);
        $this->assertSame([], $snapshot['user_ids']);
    }

    public function testFailedRequestIsUnavailable(): void
    {
        Http::fake([
            'http://bancho:10000/v1/*' => Http::response([], 503),
        ]);

        $snapshot = app(LivePresence::class)->snapshot();

        $this->assertFalse($snapshot['available']);
        $this->assertSame(0, $snapshot['current_online']);
        $this->assertSame([], $snapshot['user_ids']);
    }
}
