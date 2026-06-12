<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Libraries\M1pposu;

use App\Libraries\M1pposu\ScoreLevel;
use PHPUnit\Framework\TestCase;

class ScoreLevelTest extends TestCase
{
    public function testLevelBoundaries(): void
    {
        $this->assertSame(1.0, ScoreLevel::fromTotalScore(0));
        $this->assertSame(2.0, ScoreLevel::fromTotalScore(30000));
        $this->assertSame(100.0, ScoreLevel::fromTotalScore(26931190827));
        $this->assertSame(101.0, ScoreLevel::fromTotalScore(126931190826));
    }

    public function testLevelProgress(): void
    {
        $this->assertEqualsWithDelta(1.5, ScoreLevel::fromTotalScore(15000), 0.000001);
        $this->assertEqualsWithDelta(100.5, ScoreLevel::fromTotalScore(76931190826), 0.000001);
    }
}
