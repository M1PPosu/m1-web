<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Exceptions\ValidationException;
use App\Libraries\UserRegistration;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class SourceRegistrar
{
    private const CONNECTION = 'm1pposu-private-server-source';

    private const SOURCE_USER_COLUMNS = [
        'id',
        'name',
        'safe_name',
        'email',
        'priv',
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

    public function active(): bool
    {
        return get_bool(config('m1pposu.private_server.enabled') ?? false);
    }

    public function enabled(): bool
    {
        return $this->active()
            && get_bool(config('m1pposu.private_server.registration_enabled') ?? false);
    }

    public function assertValid(UserRegistration $registration): void
    {
        $this->configureConnection();

        $user = $registration->user();
        $safeName = $this->safeName($user->username);
        $email = $this->sourceEmail($user->user_email);

        $sourceUserExists = DB::connection(static::CONNECTION)
            ->table('users')
            ->where(function ($query) use ($user, $safeName) {
                $query
                    ->where('name', $user->username)
                    ->orWhere('safe_name', $safeName);
            })
            ->exists();

        if ($sourceUserExists) {
            $user->validationErrors()->add('username', '.username_in_use');
        }

        $sourceEmailExists = DB::connection(static::CONNECTION)
            ->table('users')
            ->where('email', $email)
            ->exists();

        if ($sourceEmailExists) {
            $user->validationErrors()->add('user_email', '.email_already_used');
        }

        if ($user->validationErrors()->isAny()) {
            throw new ValidationException($user->validationErrors());
        }
    }

    public function register(UserRegistration $registration, ?string $countryCode): User
    {
        $this->assertValid($registration);

        try {
            $sourceUser = DB::connection(static::CONNECTION)->transaction(
                fn () => $this->createSourceUser($registration->user(), $countryCode),
            );
        } catch (\Exception $e) {
            if (is_sql_unique_exception($e)) {
                $registration->user()->validationErrors()->add('username', '.unknown_duplicate');
                throw new ValidationException($registration->user()->validationErrors(), $e);
            }

            throw $e;
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
            config('m1pposu.private_server.backend') ?? 'bancho-py-ex',
        ));

        return $result['user'];
    }

    private function configureConnection(): void
    {
        $database = config('m1pposu.private_server.database');

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

    private function createSourceUser(User $user, ?string $countryCode): object
    {
        $now = time();
        $sourceUserId = DB::connection(static::CONNECTION)
            ->table('users')
            ->insertGetId([
                'name' => $user->username,
                'safe_name' => $this->safeName($user->username),
                'email' => $this->sourceEmail($user->user_email),
                'priv' => SourcePrivileges::UNRESTRICTED,
                'pw_bcrypt' => password_hash(md5($user->password), PASSWORD_BCRYPT),
                'country' => $this->sourceCountry($countryCode),
                'creation_time' => $now,
                'latest_activity' => $now,
            ]);

        DB::connection(static::CONNECTION)
            ->table('stats')
            ->insert($this->initialStatsRows((int) $sourceUserId));

        return DB::connection(static::CONNECTION)
            ->table('users')
            ->select(static::SOURCE_USER_COLUMNS)
            ->where('id', $sourceUserId)
            ->first();
    }

    private function initialStatsRows(int $sourceUserId): array
    {
        return array_map(
            fn (int $mode) => ['id' => $sourceUserId, 'mode' => $mode],
            SourceMode::supportedSourceModes(),
        );
    }

    private function safeName(string $username): string
    {
        return strtolower(str_replace(' ', '_', trim($username)));
    }

    private function sourceCountry(?string $countryCode): string
    {
        $country = strtoupper(trim((string) $countryCode));

        if ($country === 'T1' || !preg_match('/^[A-Z]{2}$/', $country) || Country::where('acronym', $country)->doesntExist()) {
            return strtolower(Country::UNKNOWN);
        }

        return strtolower($country);
    }

    private function sourceEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
