<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Libraries\M1pposu;

use App\Libraries\M1pposu\LiveEventProcessor;
use App\Libraries\M1pposu\LiveSynchronizer;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LiveEventProcessorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testRoutesScoreEventByExactSourceId(): void
    {
        $synchronizer = Mockery::mock(LiveSynchronizer::class);
        $synchronizer->shouldReceive('syncScoreIds')->once()->with([123])->andReturn(['source_scores' => 1]);

        $result = new LiveEventProcessor($synchronizer)->handle('ex:submit', '{"id":123,"player_id":9}');

        $this->assertSame(['source_scores' => 1], $result);
    }

    public function testRoutesTargetedUserAndMapEvents(): void
    {
        $synchronizer = Mockery::mock(LiveSynchronizer::class);
        $synchronizer->shouldReceive('syncUserIds')->once()->with([44])->andReturn(['users' => 1]);
        $synchronizer->shouldReceive('syncMapIds')->once()->with([7, 8])->andReturn(['maps' => 2]);

        $processor = new LiveEventProcessor($synchronizer);

        $this->assertSame(['users' => 1], $processor->handle('name_change', '{"id":44,"name":"updated"}'));
        $this->assertSame(['maps' => 2], $processor->handle('ex:map_status_change', '{"map_ids":[7,8]}'));
    }

    public function testRoutesClanChangeEvent(): void
    {
        $synchronizer = Mockery::mock(LiveSynchronizer::class);
        $synchronizer
            ->shouldReceive('syncClanChange')
            ->once()
            ->with(44, 12, false)
            ->andReturn(['clans' => 1]);

        $result = new LiveEventProcessor($synchronizer)
            ->handle('clan_change', '{"id":44,"clan_id":12,"affected_clan_id":12,"clan_priv":3,"deleted":false}');

        $this->assertSame(['clans' => 1], $result);
    }

    public function testRejectsInvalidEventPayload(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing positive integer id');

        new LiveEventProcessor(Mockery::mock(LiveSynchronizer::class))
            ->handle('ex:submit', '{"id":0}');
    }
}
