<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Middleware;

use App\Http\Middleware\RequestCost;
use App\Libraries\RateLimiter;
use App\Models\OAuth\Token;
use App\Models\User;
use Closure;
use LaravelRedis;
use Route;
use Tests\TestCase;

class ThrottleRequestsTest extends TestCase
{
    const LIMIT = 60;

    protected Token $token;

    /**
     * @dataProvider throttleDataProvider
     */
    public function testThrottle(array $middlewares, int $remaining, ?Closure $action = null)
    {
        $action ??= (fn () => []);

        Route::get('api/test-throttle', $action)->middleware(['api', 'require-scopes'])->middleware($middlewares);

        $this->getJson('api/test-throttle')
            ->assertHeader('X-Ratelimit-Limit', static::LIMIT)
            ->assertHeader('X-Ratelimit-Remaining', $remaining);
    }

    public function testThrottleMultipleRequests()
    {
        Route::get('api/test-throttle', fn () => [])->middleware(['api', 'require-scopes'])->middleware('throttle:60,10');

        $this->getJson('api/test-throttle');
        $this->getJson('api/test-throttle')
            ->assertHeader('X-Ratelimit-Limit', static::LIMIT)
            ->assertHeader('X-Ratelimit-Remaining', 58);
    }

    public function testThrottleIsDisabledInLocalEnvironment()
    {
        $this->app->detectEnvironment(fn () => 'local');

        Route::get('api/test-local-throttle', fn () => [])
            ->middleware(['api', 'require-scopes'])
            ->middleware('throttle:1,10');

        for ($i = 0; $i < 3; $i++) {
            $response = $this->getJson('api/test-local-throttle')->assertSuccessful();

            $this->assertFalse($response->headers->has('X-Ratelimit-Limit'));
            $this->assertFalse($response->headers->has('X-Ratelimit-Remaining'));
        }

        $limiter = app(RateLimiter::class);
        $key = 'test-local-direct-limit:'.$this->token->getKey();
        $limiter->clear($key);

        $this->assertSame(0, $limiter->hit($key, 600));
        $this->assertSame(0, $limiter->attempts($key));
        $this->assertFalse($limiter->tooManyAttempts($key, 1));
    }

    public static function throttleDataProvider()
    {
        return [
            'throttle' => [['throttle:60,10'], 59],
            'request-cost specified' => [['request-cost:5', 'throttle:60,10'], 55],
            'request-cost after throttle order does not matter' => [['throttle:60,10', 'request-cost:5'], 55],
            'setCost' => [['throttle:60,10'], 58, fn () => RequestCost::setCost(2)],
            'setCost overrides default' => [['throttle:60,10', 'request-cost:5'], 58, fn () => RequestCost::setCost(2)],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Using token so we can get the key name and remove the keys from redis on cleanup.
        $this->token = $this->createToken(User::factory()->create(), ['*']);
        $this->actingWithToken($this->token);
    }

    protected function tearDown(): void
    {
        if ($GLOBALS['cfg']['cache']['default'] === 'redis') {
            $key = $GLOBALS['cfg']['cache']['prefix'].':'.sha1($this->token->getKey());
            LaravelRedis::del($key);
            LaravelRedis::del("{$key}:timer");
        }

        parent::tearDown();
    }
}
