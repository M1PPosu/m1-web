<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Controllers;

use Tests\TestCase;

class WikiControllerTest extends TestCase
{
    public function testVisualContentRulesUseLocalM1pposuOverride(): void
    {
        $this
            ->get('/wiki/en/Rules/Visual_content_considerations')
            ->assertSuccessful()
            ->assertSee('M1PPosu visual content guidelines')
            ->assertSee('This page is a local M1PPosu policy page')
            ->assertSee('id="notes"', false)
            ->assertDontSee('Visual content considerations')
            ->assertDontSee('Skin elements');
    }
}
