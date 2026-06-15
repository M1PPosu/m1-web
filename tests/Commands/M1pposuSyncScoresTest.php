<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Commands;

use Tests\TestCase;

class M1pposuSyncScoresTest extends TestCase
{
    public function testFailedScoreProjectionRequiresExactScoreIds(): void
    {
        $this->artisan('m1pposu:sync:scores', [
            '--include-failed' => true,
            '--limit' => 1,
        ])
            ->expectsOutputToContain('--include-failed is only allowed with exact --score-id values.')
            ->assertFailed();
    }

    public function testFailOnSkipRequiresExactScoreIds(): void
    {
        $this->artisan('m1pposu:sync:scores', [
            '--fail-on-skip' => true,
            '--limit' => 1,
        ])
            ->expectsOutputToContain('--fail-on-skip is only allowed with exact --score-id values.')
            ->assertFailed();
    }
}
