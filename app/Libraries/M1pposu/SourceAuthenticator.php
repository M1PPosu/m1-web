<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class SourceAuthenticator
{
    private const CONNECTION = 'm1pposu-private-server-source';

    private const SOURCE_USER_COLUMNS = [
        'id',
        'name',
        'safe_name',
        'email',
        'priv',
        'pw_bcrypt',
        'country',
        'silence_end',
        'donor_end',
        'creation_time',
        'latest_activity',
        'preferred_mode',
        'custom_badge_name',
        'custom_badge_icon',
        'userpage_content',
    ];

    public function attempt(string $login, string $password): ?User
    {
        $config = config('m1pposu.private_server');

        if (get_bool($config['enabled'] ?? false) !== true || !$this->hasRequiredConfig($config['database'] ?? [])) {
            return null;
        }

        $this->configureConnection($config['database']);

        $sourceUser = $this->sourceUserForLogin($login);
        if ($sourceUser === null || !$this->checkSourcePassword($sourceUser, $password)) {
            return null;
        }

        $sourceStatsRows = DB::connection(static::CONNECTION)
            ->table('stats')
            ->select(UserProjector::SOURCE_STATS_COLUMNS)
            ->where('id', $sourceUser->id)
            ->orderBy('mode')
            ->get();

        $result = DB::transaction(fn () => app(UserProjector::class)->sync(
            $sourceUser,
            $sourceStatsRows,
            $config['backend'] ?? 'bancho-py-ex',
        ));

        return $result['user'] ?? null;
    }

    private function sourceUserForLogin(string $login): ?object
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }

        $query = DB::connection(static::CONNECTION)
            ->table('users')
            ->select(static::SOURCE_USER_COLUMNS)
            ->where(function ($query) use ($login) {
                $query
                    ->where('name', $login)
                    ->orWhere('safe_name', $login);

                if (str_contains($login, '@')) {
                    $query->orWhere('email', strtolower($login));
                }
            })
            ->limit(2);

        $matches = $query->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function checkSourcePassword(object $sourceUser, string $password): bool
    {
        $hash = $sourceUser->pw_bcrypt ?? null;

        if (!is_string($hash) || $hash === '') {
            return false;
        }

        return password_verify(md5($password), $hash)
            || password_verify($password, $hash);
    }

    private function configureConnection(array $database): void
    {
        Config::set('database.connections.'.static::CONNECTION, [
            ...config('database.connections.mysql'),
            'host' => $database['host'],
            'port' => $database['port'],
            'database' => $database['database'],
            'username' => $database['username'],
            'password' => $database['password'],
        ]);

        DB::purge(static::CONNECTION);
    }

    private function hasRequiredConfig(array $database): bool
    {
        return present($database['host'] ?? null)
            && present($database['database'] ?? null)
            && present($database['username'] ?? null);
    }
}
