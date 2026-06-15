<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

$liveChannels = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('M1PP_PRIVATE_SERVER_LIVE_REDIS_CHANNELS', '')),
)));

return [
    'site_title' => presence(env('M1PP_SITE_TITLE')) ?? 'M1PPosu - [Beta]',

    'imported_assets_disk' => env('M1PP_IMPORTED_ASSETS_DISK', env('FILESYSTEM_DISK', 'local')),
    'imported_assets_base_url' => presence(env('M1PP_IMPORTED_ASSETS_BASE_URL')),

    'private_server' => [
        'enabled' => env('M1PP_PRIVATE_SERVER_ENABLED', false),
        'backend' => presence(env('M1PP_PRIVATE_SERVER_BACKEND')) ?? 'bancho-py-ex',
        'registration_enabled' => env('M1PP_PRIVATE_SERVER_REGISTRATION_ENABLED', env('M1PP_PRIVATE_SERVER_ENABLED', false)),

        'database' => [
            'host' => env('M1PP_PRIVATE_SERVER_DB_HOST'),
            'port' => env('M1PP_PRIVATE_SERVER_DB_PORT', '3306'),
            'database' => env('M1PP_PRIVATE_SERVER_DB_DATABASE'),
            'username' => env('M1PP_PRIVATE_SERVER_DB_USERNAME'),
            'password' => env('M1PP_PRIVATE_SERVER_DB_PASSWORD'),
        ],

        'presence' => [
            'base_url' => presence(env('M1PP_PRIVATE_SERVER_PRESENCE_BASE_URL')) ?? 'http://bancho:10000/v1',
            'host_header' => presence(env('M1PP_PRIVATE_SERVER_PRESENCE_HOST_HEADER')) ?? 'localhost',
            'timeout_seconds' => env('M1PP_PRIVATE_SERVER_PRESENCE_TIMEOUT_SECONDS', 2),
            'cache_seconds' => env('M1PP_PRIVATE_SERVER_PRESENCE_CACHE_SECONDS', 5),
        ],

        'live' => [
            'enabled' => env('M1PP_PRIVATE_SERVER_LIVE_ENABLED', false),
            'event_delay_seconds' => env('M1PP_PRIVATE_SERVER_LIVE_EVENT_DELAY_SECONDS', 2),
            'catchup_batch_size' => env('M1PP_PRIVATE_SERVER_LIVE_CATCHUP_BATCH_SIZE', 250),
            'catchup_max_batches' => env('M1PP_PRIVATE_SERVER_LIVE_CATCHUP_MAX_BATCHES', 4),
            'catchup_reconcile_window' => env('M1PP_PRIVATE_SERVER_LIVE_CATCHUP_RECONCILE_WINDOW', 5000),

            'redis' => [
                'host' => env('M1PP_PRIVATE_SERVER_LIVE_REDIS_HOST'),
                'port' => env('M1PP_PRIVATE_SERVER_LIVE_REDIS_PORT'),
                'database' => env('M1PP_PRIVATE_SERVER_LIVE_REDIS_DB', 0),
                'username' => env('M1PP_PRIVATE_SERVER_LIVE_REDIS_USERNAME'),
                'password' => env('M1PP_PRIVATE_SERVER_LIVE_REDIS_PASSWORD'),
                'channels' => $liveChannels,
            ],
        ],
    ],

    'beatmaps' => [
        'download_url' => env('M1PP_BEATMAP_DOWNLOAD_URL'),

        'covers' => [
            'cover' => env('M1PP_BEATMAPSET_COVER_URL'),
            'cover_2x' => env('M1PP_BEATMAPSET_COVER_2X_URL'),
            'card' => env('M1PP_BEATMAPSET_CARD_COVER_URL'),
            'card_2x' => env('M1PP_BEATMAPSET_CARD_COVER_2X_URL'),
            'list' => env('M1PP_BEATMAPSET_LIST_COVER_URL'),
            'list_2x' => env('M1PP_BEATMAPSET_LIST_COVER_2X_URL'),
            'slimcover' => env('M1PP_BEATMAPSET_SLIM_COVER_URL'),
            'slimcover_2x' => env('M1PP_BEATMAPSET_SLIM_COVER_2X_URL'),
        ],
    ],

    'users' => [
        'avatar_url' => env('M1PP_AVATAR_URL'),
        'cover_url' => env('M1PP_USER_COVER_URL'),
        'source_avatar_path' => env('M1PP_SOURCE_AVATAR_PATH'),
        'source_cover_path' => env('M1PP_SOURCE_USER_COVER_PATH'),
    ],

    'clans' => [
        'source_icon_path' => env('M1PP_SOURCE_CLAN_ICON_PATH'),
        'source_cover_path' => env('M1PP_SOURCE_CLAN_COVER_PATH'),
    ],

    'contact_email' => presence(env('M1PP_CONTACT_EMAIL')) ?? 'contact@m1pposu.dev',

    'community' => [
        'discord_url' => presence(env('M1PP_DISCORD_URL')) ?? 'https://discord.gg/2ujhGaZ6Z9',
    ],

    'features' => [
        'daily_challenge' => env('M1PP_FEATURE_DAILY_CHALLENGE', false),
        'featured_artists' => env('M1PP_FEATURE_FEATURED_ARTISTS', false),
        'lazer_toggle' => env('M1PP_FEATURE_LAZER_TOGGLE', false),
        'legacy_api_settings' => env('M1PP_FEATURE_LEGACY_API_SETTINGS', false),
        'livestreams' => env('M1PP_FEATURE_LIVESTREAMS', false),
        'oauth_settings' => env('M1PP_FEATURE_OAUTH_SETTINGS', false),
        'playlists' => env('M1PP_FEATURE_PLAYLISTS', false),
        'presence' => env('M1PP_FEATURE_PRESENCE', !env('M1PP_PRIVATE_SERVER_ENABLED', false)),
        'ranked_play' => env('M1PP_FEATURE_RANKED_PLAY', false),
        'store' => env('M1PP_FEATURE_STORE', false),
    ],

    'launcher' => [
        'download_url' => env('M1PP_LAUNCHER_DOWNLOAD_URL'),
        'download_windows_url' => env('M1PP_LAUNCHER_DOWNLOAD_WINDOWS_URL'),
        'info_url' => env('M1PP_LAUNCHER_DOWNLOAD_INFO_URL'),
    ],

    'legal' => [
        'non_affiliation' => env('M1PP_NON_AFFILIATION_TEXT', 'M1PPosu is not affiliated with ppy Pty Ltd, osu!, or osu.ppy.sh.'),
        'source_code_url' => presence(env('M1PP_SOURCE_CODE_URL')),
    ],

    'localization' => [
        'branding_fallback' => [
            'exact' => [
                'forum.topics.show.feature_vote.info.supporters',
                'layout.popup_user.links.legacy_score_only_toggle_tooltip',
                'page_title.store._',
                'users.card.gift_supporter',
                'users.disabled.reasons.tos._',
                'users.show.edit.hue.reset_no_supporter',
                'users.show.joined_at',
                'users.show.page.description',
                'users.store.from_web',
            ],
            'prefixes' => [
                'community.support.',
                'help.',
                'home.download.',
                'home.landing.',
                'home.user.',
                'layout.footer.',
                'layout.popup_login.register.',
                'legal.',
                'mail.donation_thanks.',
                'mail.forum_new_reply.',
                'mail.password_reset.',
                'mail.store_payment_completed.',
                'mail.supporter_gift.',
                'mail.user_email_updated.',
                'mail.user_force_reactivation.',
                'mail.user_notification_digest.',
                'mail.user_password_updated.',
                'mail.user_verification.',
            ],
        ],
    ],
];
