<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Models\M1pposuExternalUser;
use App\Models\User;

final class UserAssets
{
    private static array $mappings = [];

    public static function avatarUrl(User $user): ?string
    {
        return static::format(config('m1pposu.users.avatar_url'), $user);
    }

    public static function coverUrl(User $user): ?string
    {
        return static::format(config('m1pposu.users.cover_url'), $user);
    }

    private static function format($template, User $user): ?string
    {
        if (!present($template)) {
            return null;
        }

        $mapping = static::mapping($user);
        if ($mapping === null) {
            return null;
        }

        $url = strtr(trim((string) $template), [
            '{id}' => (string) $mapping->external_user_id,
            '{user_id}' => (string) $mapping->external_user_id,
            '{external_user_id}' => (string) $mapping->external_user_id,
            '{username}' => rawurlencode($mapping->external_username ?? $user->username),
            '{local_user_id}' => (string) $user->getKey(),
        ]);

        if (preg_match('/\{[^}]+\}/', $url) === 1 || preg_match('/[\x00-\x20]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            ? $url
            : null;
    }

    private static function mapping(User $user): ?M1pposuExternalUser
    {
        $userId = (int) $user->getKey();

        if (!array_key_exists($userId, static::$mappings)) {
            static::$mappings[$userId] = M1pposuExternalUser::query()
                ->where('backend', config('m1pposu.private_server.backend', 'bancho-py-ex'))
                ->where('user_id', $userId)
                ->first();
        }

        return static::$mappings[$userId];
    }
}
