<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use RuntimeException;

final class RuntimeConfiguration
{
    public static function validate(): void
    {
        static::validatePrivateServer();
        static::validateStore();

        if (app()->environment('production')) {
            static::validateProduction();
        }
    }

    private static function validatePrivateServer(): void
    {
        $config = config('m1pposu.private_server');
        $enabled = get_bool($config['enabled'] ?? false) === true;
        $registrationEnabled = get_bool($config['registration_enabled'] ?? false) === true;

        if ($registrationEnabled && !$enabled) {
            throw new RuntimeException(
                'M1PP_PRIVATE_SERVER_REGISTRATION_ENABLED requires M1PP_PRIVATE_SERVER_ENABLED=true.'
            );
        }

        if (!$enabled) {
            return;
        }

        $backend = $config['backend'] ?? null;
        if ($backend !== 'bancho-py-ex') {
            throw new RuntimeException('M1PP_PRIVATE_SERVER_BACKEND must be bancho-py-ex.');
        }

        $database = $config['database'] ?? [];
        static::requireValues([
            'M1PP_PRIVATE_SERVER_DB_HOST' => $database['host'] ?? null,
            'M1PP_PRIVATE_SERVER_DB_DATABASE' => $database['database'] ?? null,
            'M1PP_PRIVATE_SERVER_DB_USERNAME' => $database['username'] ?? null,
            'M1PP_PRIVATE_SERVER_DB_PASSWORD' => $database['password'] ?? null,
            'M1PP_BEATMAP_DOWNLOAD_URL' => config('m1pposu.beatmaps.download_url'),
            'M1PP_AVATAR_URL' => config('m1pposu.users.avatar_url'),
        ], 'Private-server integration is enabled but required configuration is missing');

        $port = filter_var($database['port'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new RuntimeException('M1PP_PRIVATE_SERVER_DB_PORT must be an integer between 1 and 65535.');
        }

        static::validateTemplate(
            'M1PP_BEATMAP_DOWNLOAD_URL',
            (string) config('m1pposu.beatmaps.download_url'),
            ['{id}', '{beatmapset_id}'],
        );
        static::validateTemplate(
            'M1PP_AVATAR_URL',
            (string) config('m1pposu.users.avatar_url'),
            ['{id}', '{user_id}', '{external_user_id}'],
        );

        static::validatePrivateServerLive($config['live'] ?? []);
        static::validatePrivateServerPresence($config['presence'] ?? []);
    }

    private static function validatePrivateServerPresence(array $config): void
    {
        if (get_bool(config('m1pposu.features.presence')) !== true) {
            return;
        }

        static::requireValues([
            'M1PP_PRIVATE_SERVER_PRESENCE_BASE_URL' => $config['base_url'] ?? null,
            'M1PP_PRIVATE_SERVER_PRESENCE_HOST_HEADER' => $config['host_header'] ?? null,
        ], 'Private-server presence integration is enabled but required configuration is missing');

        static::validateUrl(
            'M1PP_PRIVATE_SERVER_PRESENCE_BASE_URL',
            (string) $config['base_url'],
            false,
        );
        static::validateIntegerRange(
            'M1PP_PRIVATE_SERVER_PRESENCE_CACHE_SECONDS',
            $config['cache_seconds'] ?? null,
            1,
            30,
        );

        $timeout = filter_var($config['timeout_seconds'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($timeout === false || $timeout < 0.1 || $timeout > 10) {
            throw new RuntimeException('M1PP_PRIVATE_SERVER_PRESENCE_TIMEOUT_SECONDS must be between 0.1 and 10.');
        }

        if (preg_match('/[\r\n]/', (string) $config['host_header']) === 1) {
            throw new RuntimeException('M1PP_PRIVATE_SERVER_PRESENCE_HOST_HEADER must not contain line breaks.');
        }
    }

    private static function validatePrivateServerLive(array $config): void
    {
        if (get_bool($config['enabled'] ?? false) !== true) {
            return;
        }

        $redis = $config['redis'] ?? [];
        static::requireValues([
            'M1PP_PRIVATE_SERVER_LIVE_REDIS_HOST' => $redis['host'] ?? null,
            'M1PP_PRIVATE_SERVER_LIVE_REDIS_PORT' => $redis['port'] ?? null,
            'M1PP_PRIVATE_SERVER_LIVE_REDIS_CHANNELS' => $redis['channels'] ?? null,
        ], 'Private-server live integration is enabled but required configuration is missing');

        static::validateIntegerRange(
            'M1PP_PRIVATE_SERVER_LIVE_REDIS_PORT',
            $redis['port'] ?? null,
            1,
            65535,
        );
        static::validateIntegerRange(
            'M1PP_PRIVATE_SERVER_LIVE_REDIS_DB',
            $redis['database'] ?? null,
            0,
            255,
        );
        static::validateIntegerRange(
            'M1PP_PRIVATE_SERVER_LIVE_CATCHUP_BATCH_SIZE',
            $config['catchup_batch_size'] ?? null,
            1,
            1000,
        );
        static::validateIntegerRange(
            'M1PP_PRIVATE_SERVER_LIVE_CATCHUP_MAX_BATCHES',
            $config['catchup_max_batches'] ?? null,
            1,
            20,
        );
        static::validateIntegerRange(
            'M1PP_PRIVATE_SERVER_LIVE_CATCHUP_RECONCILE_WINDOW',
            $config['catchup_reconcile_window'] ?? null,
            100,
            100000,
        );

        $eventDelay = filter_var($config['event_delay_seconds'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($eventDelay === false || $eventDelay < 0 || $eventDelay > 30) {
            throw new RuntimeException('M1PP_PRIVATE_SERVER_LIVE_EVENT_DELAY_SECONDS must be between 0 and 30.');
        }

        $channels = $redis['channels'];
        if (!is_array($channels) || !in_array('ex:submit', $channels, true)) {
            throw new RuntimeException('M1PP_PRIVATE_SERVER_LIVE_REDIS_CHANNELS must include ex:submit.');
        }

        $supportedChannels = [
            'addpriv',
            'country_change',
            'clan_change',
            'ex:map_status_change',
            'ex:submit',
            'givedonator',
            'name_change',
            'rank',
            'removepriv',
            'restrict',
            'unrestrict',
            'wipe',
        ];
        $unsupportedChannels = array_values(array_diff($channels, $supportedChannels));
        if ($unsupportedChannels !== []) {
            throw new RuntimeException(
                'M1PP_PRIVATE_SERVER_LIVE_REDIS_CHANNELS contains unsupported channels: '.
                implode(', ', $unsupportedChannels).'.'
            );
        }
    }

    private static function validateProduction(): void
    {
        static::requireValues([
            'APP_KEY' => config('app.key'),
            'APP_NAME' => config('app.name'),
            'APP_URL' => config('app.url'),
            'M1PP_CONTACT_EMAIL' => config('m1pposu.contact_email'),
            'M1PP_SITE_TITLE' => config('m1pposu.site_title'),
            'M1PP_DISCORD_URL' => config('m1pposu.community.discord_url'),
            'LANDING_VIDEO_URL' => config('osu.landing.video_url'),
            'OSU_URL_DOWNLOAD_VIDEO' => config('osu.urls.download_video'),
        ], 'Production configuration is incomplete');

        static::validateUrl('APP_URL', (string) config('app.url'), true);
        static::validateUrl('M1PP_DISCORD_URL', (string) config('m1pposu.community.discord_url'), true);

        if (config('m1pposu.contact_email') !== 'contact@m1pposu.dev') {
            throw new RuntimeException('M1PP_CONTACT_EMAIL must be contact@m1pposu.dev in production.');
        }

        if (config('m1pposu.community.discord_url') !== 'https://discord.gg/2ujhGaZ6Z9') {
            throw new RuntimeException('M1PP_DISCORD_URL must use the official M1PPosu invite in production.');
        }

        if (get_bool(config('app.debug')) !== false) {
            throw new RuntimeException('APP_DEBUG must be false in production.');
        }

        if (get_bool(config('session.secure')) !== true) {
            throw new RuntimeException('SESSION_SECURE_COOKIE must be true in production.');
        }

        $databasePassword = (string) config('database.connections.mysql.password');
        static::requireValues([
            'DB_PASSWORD' => $databasePassword,
        ], 'Production database configuration is incomplete');
        if (in_array(strtolower(trim($databasePassword)), ['change-me', 'password', 'secret'], true)) {
            throw new RuntimeException('DB_PASSWORD must not use a placeholder value in production.');
        }

        $contactEmail = config('m1pposu.contact_email');
        if (present($contactEmail) && !is_valid_email_format((string) $contactEmail)) {
            throw new RuntimeException('M1PP_CONTACT_EMAIL must be a valid email address when configured.');
        }

        if (get_bool(config('osu.is_development_deploy')) === true) {
            throw new RuntimeException('IS_DEVELOPMENT_DEPLOY must be false in production.');
        }

        $disk = (string) config('m1pposu.imported_assets_disk');
        if (!array_key_exists($disk, config('filesystems.disks', []))) {
            throw new RuntimeException("M1PP_IMPORTED_ASSETS_DISK references an unknown filesystem disk: {$disk}.");
        }

        static::validateLocalPublicAsset('LANDING_VIDEO_URL', (string) config('osu.landing.video_url'));
        static::validateLocalPublicAsset('OSU_URL_DOWNLOAD_VIDEO', (string) config('osu.urls.download_video'));
    }

    private static function validateStore(): void
    {
        if (get_bool(config('m1pposu.features.store')) !== true) {
            return;
        }

        static::requireValues([
            'PAYPAL_CLIENT_ID' => config('payments.paypal.client_id'),
            'PAYPAL_CLIENT_SECRET' => config('payments.paypal.client_secret'),
            'PAYPAL_URL' => config('payments.paypal.url'),
        ], 'The store is enabled but PayPal is not configured');

        static::validateUrl('PAYPAL_URL', (string) config('payments.paypal.url'), true);
    }

    private static function requireValues(array $values, string $message): void
    {
        $missing = array_keys(array_filter($values, fn ($value) => !present($value)));
        if ($missing !== []) {
            throw new RuntimeException($message.': '.implode(', ', $missing).'.');
        }
    }

    private static function validateLocalPublicAsset(string $key, string $value): void
    {
        if (!str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return;
        }

        $path = public_path(ltrim($value, '/'));
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("{$key} references a missing or unreadable public asset: {$value}.");
        }
    }

    private static function validateTemplate(string $key, string $value, array $placeholders): void
    {
        static::validateUrl($key, $value, true);

        foreach ($placeholders as $placeholder) {
            if (str_contains($value, $placeholder)) {
                return;
            }
        }

        throw new RuntimeException("{$key} must contain one of these placeholders: ".implode(', ', $placeholders).'.');
    }

    private static function validateIntegerRange(string $key, mixed $value, int $minimum, int $maximum): void
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);
        if ($validated === false) {
            throw new RuntimeException("{$key} must be an integer between {$minimum} and {$maximum}.");
        }
    }

    private static function validateUrl(string $key, string $value, bool $requireHttps): void
    {
        $parts = parse_url($value);
        if ($parts === false) {
            $schemeDescription = $requireHttps ? 'HTTPS' : 'HTTP(S)';
            throw new RuntimeException("{$key} must be an absolute {$schemeDescription} URL without credentials or a fragment.");
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (
            !present($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || ($requireHttps && $scheme !== 'https')
            || (!$requireHttps && !in_array($scheme, ['http', 'https'], true))
        ) {
            $schemeDescription = $requireHttps ? 'HTTPS' : 'HTTP(S)';
            throw new RuntimeException("{$key} must be an absolute {$schemeDescription} URL without credentials or a fragment.");
        }
    }
}
