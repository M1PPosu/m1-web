<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace Tests\Controllers;

use Tests\TestCase;

class HelpLegalControllerTest extends TestCase
{
    public function testContactPageUsesProductionSupportChannelsWithoutMissingForumLink(): void
    {
        config_set('m1pposu.contact_email', 'contact@m1pposu.dev');
        config_set('m1pposu.community.discord_url', 'https://discord.gg/2ujhGaZ6Z9');

        $this
            ->get(route('help.contact'))
            ->assertSuccessful()
            ->assertSee('mailto:contact@m1pposu.dev', false)
            ->assertSee('https://discord.gg/2ujhGaZ6Z9', false)
            ->assertDontSee(route('forum.forums.show', ['forum' => 0]), false);
    }

    public function testCustomHelpPagesRenderInFallbackEnglishForPolish(): void
    {
        $this
            ->withUnencryptedCookie('locale', 'pl')
            ->get(route('help.rules'))
            ->assertSuccessful()
            ->assertSee('Community rules')
            ->assertSee('M1PPosu');
    }

    public function testCustomHelpPagesRenderInFallbackEnglishForGerman(): void
    {
        $this
            ->withUnencryptedCookie('locale', 'de')
            ->get(route('help.report-abuse'))
            ->assertSuccessful()
            ->assertSee('How to send reports')
            ->assertSee('M1PPosu');
    }

    public function testCustomLegalPagesRenderInFallbackEnglish(): void
    {
        $this
            ->get(route('legal', ['locale' => 'pl', 'path' => 'Privacy']))
            ->assertSuccessful()
            ->assertSee('Privacy Policy')
            ->assertSee('M1PPosu');

        $this
            ->get(route('legal', ['locale' => 'de', 'path' => 'Terms']))
            ->assertSuccessful()
            ->assertSee('Terms of Service')
            ->assertSee('M1PPosu');
    }
}
