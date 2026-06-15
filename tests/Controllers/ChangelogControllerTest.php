<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Controllers;

use Tests\TestCase;

class ChangelogControllerTest extends TestCase
{
    public function testGithubWebhookIsUnavailableWithoutSecret(): void
    {
        config_set('osu.changelog.github_token', null);
        $GLOBALS['cfg']['osu']['changelog']['github_token'] = null;

        $this->postJson('/home/changelog/github', [], [
            'X-Hub-Signature' => 'sha256='.hash_hmac('sha256', '', ''),
        ])->assertNotFound();
    }
}
