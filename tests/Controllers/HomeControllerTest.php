<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace Tests\Controllers;

use App\Models\User;
use Tests\TestCase;

class HomeControllerTest extends TestCase
{
    public function testBbcodePreview()
    {
        $text = 'test';

        $this
            ->post(route('bbcode-preview'), ['text' => 'test'])
            ->assertStatus(200);
    }

    public function testDownloadQuotaCheckApi()
    {
        $user = User::factory()->create();

        $this->actAsScopedUser($user);
        $this
            ->get(route('api.download-quota-check'))
            ->assertSuccessful()
            ->assertJson(['quota_used' => 0]);
    }

    public function testRoot()
    {
        $this
            ->get(route('home'))
            ->assertStatus(200)
            ->assertSee('<title>M1PPosu - [Beta]</title>', false)
            ->assertSee('<meta property="og:site_name" content="M1PPosu - [Beta]">', false);
    }

    public function testSupportRedirectsToCommunityWhileStoreDisabled(): void
    {
        config_set('m1pposu.features.store', false);
        config_set('m1pposu.community.discord_url', 'https://discord.gg/jTcgGNFj9g');

        $this->get(route('support-the-game'))
            ->assertRedirect('https://discord.gg/jTcgGNFj9g');
    }

    public function testStoreAndPaymentsAreUnavailableWhileDisabled(): void
    {
        config_set('m1pposu.features.store', false);

        $this->get(route('store.products.index'))->assertNotFound();
        $this->post(route('payments.shopify.callback'))->assertNotFound();
    }
}
