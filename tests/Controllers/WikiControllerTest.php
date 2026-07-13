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
            ->assertSee('Visual content guidelines')
            ->assertSee('Readability and safety')
            ->assertSee('Repeated or severe violations may lead to account restrictions.')
            ->assertSee('id="notes"', false)
            ->assertDontSee('This page is a local M1PPosu policy page')
            ->assertDontSee('Visual content considerations')
            ->assertDontSee('Skin elements');
    }
}
