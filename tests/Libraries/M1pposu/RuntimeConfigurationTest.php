<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Libraries\M1pposu;

use App\Libraries\M1pposu\RuntimeConfiguration;
use RuntimeException;
use Tests\TestCase;

class RuntimeConfigurationTest extends TestCase
{
    public function testDisabledIntegrationsDoNotRequireCredentials(): void
    {
        config_set('m1pposu.private_server.enabled', false);
        config_set('m1pposu.private_server.registration_enabled', false);
        config_set('m1pposu.features.store', false);

        RuntimeConfiguration::validate();

        $this->addToAssertionCount(1);
    }

    public function testEnabledPrivateServerRequiresCompleteConfiguration(): void
    {
        config_set('m1pposu.private_server.enabled', true);
        config_set('m1pposu.private_server.registration_enabled', true);
        config_set('m1pposu.private_server.database.host', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('M1PP_PRIVATE_SERVER_DB_HOST');

        RuntimeConfiguration::validate();
    }

    public function testEnabledPrivateServerRejectsUnknownBackend(): void
    {
        config_set('m1pposu.private_server.enabled', true);
        config_set('m1pposu.private_server.registration_enabled', false);
        config_set('m1pposu.private_server.backend', 'unknown');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be bancho-py-ex');

        RuntimeConfiguration::validate();
    }

    public function testEnabledLiveIntegrationRequiresRedisConfiguration(): void
    {
        $this->configurePrivateServer();
        config_set('m1pposu.private_server.live.enabled', true);
        config_set('m1pposu.private_server.live.redis.host', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('M1PP_PRIVATE_SERVER_LIVE_REDIS_HOST');

        RuntimeConfiguration::validate();
    }

    public function testEnabledLiveIntegrationRequiresScoreChannel(): void
    {
        $this->configurePrivateServer();
        config_set('m1pposu.private_server.live.enabled', true);
        config_set('m1pposu.private_server.live.redis.host', 'source-redis');
        config_set('m1pposu.private_server.live.redis.port', 6379);
        config_set('m1pposu.private_server.live.redis.database', 0);
        config_set('m1pposu.private_server.live.redis.channels', ['name_change']);
        config_set('m1pposu.private_server.live.event_delay_seconds', 2);
        config_set('m1pposu.private_server.live.catchup_batch_size', 250);
        config_set('m1pposu.private_server.live.catchup_max_batches', 4);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must include ex:submit');

        RuntimeConfiguration::validate();
    }

    public function testEnabledPresenceRequiresBanchoApiConfiguration(): void
    {
        $this->configurePrivateServer();
        config_set('m1pposu.features.presence', true);
        config_set('m1pposu.private_server.presence.base_url', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('M1PP_PRIVATE_SERVER_PRESENCE_BASE_URL');

        RuntimeConfiguration::validate();
    }

    public function testRegistrationCannotBeEnabledWithoutPrivateServer(): void
    {
        config_set('m1pposu.private_server.enabled', false);
        config_set('m1pposu.private_server.registration_enabled', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires M1PP_PRIVATE_SERVER_ENABLED=true');

        RuntimeConfiguration::validate();
    }

    public function testEnabledStoreRequiresPaypalConfiguration(): void
    {
        config_set('m1pposu.private_server.enabled', false);
        config_set('m1pposu.private_server.registration_enabled', false);
        config_set('m1pposu.features.store', true);
        config_set('payments.paypal.client_id', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PAYPAL_CLIENT_ID');

        RuntimeConfiguration::validate();
    }

    public function testProductionRejectsDebugMode(): void
    {
        $this->configureProduction();
        config_set('app.debug', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG must be false');

        RuntimeConfiguration::validate();
    }

    public function testProductionRequiresSecureSessionCookies(): void
    {
        $this->configureProduction();
        config_set('session.secure', false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SESSION_SECURE_COOKIE must be true');

        RuntimeConfiguration::validate();
    }

    public function testProductionRejectsPlaceholderDatabasePassword(): void
    {
        $this->configureProduction();
        config_set('database.connections.mysql.password', 'change-me');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB_PASSWORD must not use a placeholder');

        RuntimeConfiguration::validate();
    }

    private function configureProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        config_set('app.debug', false);
        config_set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config_set('app.name', 'M1PPosu');
        config_set('app.url', 'https://m1pposu.example');
        config_set('database.connections.mysql.password', 'test-production-password');
        config_set('m1pposu.features.store', false);
        config_set('m1pposu.imported_assets_disk', 'local');
        config_set('m1pposu.private_server.enabled', false);
        config_set('m1pposu.private_server.registration_enabled', false);
        config_set('m1pposu.contact_email', 'contact@m1pposu.dev');
        config_set('m1pposu.site_title', 'M1PPosu');
        config_set('m1pposu.community.discord_url', 'https://discord.gg/2ujhGaZ6Z9');
        config_set('osu.is_development_deploy', false);
        config_set('osu.landing.video_url', 'https://assets.example/landing.mp4');
        config_set('osu.urls.download_video', 'https://assets.example/download.mp4');
        config_set('session.secure', true);
    }

    private function configurePrivateServer(): void
    {
        config_set('m1pposu.private_server.enabled', true);
        config_set('m1pposu.private_server.registration_enabled', false);
        config_set('m1pposu.private_server.backend', 'bancho-py-ex');
        config_set('m1pposu.features.presence', false);
        config_set('m1pposu.private_server.database.host', 'source-db');
        config_set('m1pposu.private_server.database.port', 3306);
        config_set('m1pposu.private_server.database.database', 'bancho');
        config_set('m1pposu.private_server.database.username', 'reader');
        config_set('m1pposu.private_server.database.password', 'password');
        config_set('m1pposu.beatmaps.download_url', 'https://assets.example/{id}');
        config_set('m1pposu.users.avatar_url', 'https://assets.example/{user_id}');
    }
}
