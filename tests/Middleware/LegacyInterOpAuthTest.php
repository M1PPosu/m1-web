<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Middleware;

use App\Http\Middleware\LegacyInterOpAuth;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LegacyInterOpAuthTest extends TestCase
{
    public function testMissingSecretDisablesInteropRoutes(): void
    {
        config_set('osu.legacy.shared_interop_secret', null);
        $request = Request::create(
            'https://m1pposu.example/_lio/news?timestamp='.time(),
            'GET',
            server: ['HTTP_X_LIO_SIGNATURE' => 'forged'],
        );

        try {
            (new LegacyInterOpAuth())->handle($request, fn () => response()->noContent());
            $this->fail('Expected the interop request to be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame('unconfigured_secret', $e->getMessage());
        }
    }
}
