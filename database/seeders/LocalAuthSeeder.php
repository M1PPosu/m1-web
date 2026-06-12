<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Database\Seeders;

use App\Libraries\M1pposu\ReferenceData;
use App\Libraries\UserRegistration;
use App\Models\Beatmap;
use App\Models\Count;
use App\Models\Country;
use App\Models\Forum\Forum;
use App\Models\SupporterTag;
use App\Models\User;
use App\Models\UserDonation;
use App\Models\UserGroupEvent;
use App\Models\UserStatistics\Model as UserStatisticsModel;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use RuntimeException;

class LocalAuthSeeder extends Seeder
{
    private const DEFAULT_COUNTRY = 'US';
    private const DEFAULT_EMAIL = 'dev@m1pposu.local';
    private const DEFAULT_GROUP_IDENTIFIERS = ['admin', 'dev', 'gmt', 'bng'];
    private const DEFAULT_SUPPORTER_LENGTH_MONTHS = 1;
    private const DEFAULT_USERNAME = 'dev';
    private const LEGACY_LOCAL_EMAIL = 'local_user@example.test';
    private const LEGACY_LOCAL_USERNAME = 'local_user';
    private const LOCAL_BADGE_GROUP_ATTRIBUTES = [
        'admin' => [
            'colour' => 'FF66AA',
            'display_order' => 10,
            'group_colour' => 'FF66AA',
        ],
        'dev' => [
            'colour' => 'A84DFF',
            'display_order' => 20,
            'group_colour' => 'A84DFF',
        ],
        'bng' => [
            'colour' => '88B300',
            'display_order' => 30,
            'group_colour' => '88B300',
        ],
    ];
    private const LOCAL_SUPPORTER_TRANSACTION_ID = 'local-auth-supporter';

    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LocalAuthSeeder may only be run in local or testing environments.');
        }

        app(ReferenceData::class)->ensure();
        $this->ensureCountries();
        $this->ensureGroups();
        $this->ensureUserPageForum();

        $username = env('M1PP_LOCAL_DEV_USERNAME', env('LOCAL_AUTH_USERNAME', self::DEFAULT_USERNAME));
        $email = env('M1PP_LOCAL_DEV_EMAIL', env('LOCAL_AUTH_EMAIL', self::DEFAULT_EMAIL));
        $password = env('M1PP_LOCAL_DEV_PASSWORD', env('LOCAL_AUTH_PASSWORD'));
        if (!present($password)) {
            throw new RuntimeException('Set M1PP_LOCAL_DEV_PASSWORD before running LocalAuthSeeder.');
        }

        $this->resetLocalUsers($username, $email);

        $user = $this->ensureUser($username, $email, $password);
        $this->ensureLocalGroups($user);
        $user = $this->ensureLocalSupporter($user);
        $this->ensureUserStatistics($user);
        $this->ensureConfiguredStaffUsers($user, $password);
        Count::totalUsers()->update(['count' => User::where('user_type', 0)->count()]);

        $this->command?->info("Local auth user ready: {$user->username} ({$user->user_email}), id {$user->getKey()}.");
    }

    private function ensureCountries(): void
    {
        Country::firstOrCreate([
            'acronym' => Country::UNKNOWN,
        ], [
            'display' => 0,
            'name' => 'Unknown',
            'playcount' => 0,
            'pp' => 0,
            'rankedscore' => 0,
            'shipping_rate' => 1,
            'usercount' => 0,
        ]);

        Country::firstOrCreate([
            'acronym' => self::DEFAULT_COUNTRY,
        ], [
            'display' => 2,
            'name' => 'United States',
            'playcount' => 0,
            'pp' => 0,
            'rankedscore' => 0,
            'shipping_rate' => 1,
            'usercount' => 0,
        ]);

        app('countries')->resetMemoized();
    }

    private function ensureGroups(): void
    {
        foreach (self::LOCAL_BADGE_GROUP_ATTRIBUTES as $identifier => $attributes) {
            $group = app('groups')->byIdentifier($identifier)
                ?? throw new RuntimeException("Required local group '{$identifier}' is missing.");

            foreach ($attributes as $attribute => $value) {
                if (!present($group->getRawAttribute($attribute))) {
                    $group->{$attribute} = $value;
                }
            }

            if ($group->isDirty()) {
                $group->saveOrExplode();
            }
        }

        app('groups')->resetMemoized();
    }

    private function ensureUserPageForum(): void
    {
        Forum::firstOrCreate([
            'forum_id' => $GLOBALS['cfg']['osu']['user']['user_page_forum_id'],
        ], [
            'display_on_index' => false,
            'enable_indexing' => false,
            'forum_desc' => '',
            'forum_name' => 'User Pages',
            'forum_parents' => [],
            'forum_rules' => '',
            'forum_type' => 1,
        ]);
    }

    private function ensureUser(string $username, string $email, string $password): User
    {
        $user = User::where('username', $username)
            ->orWhere('user_email', $email)
            ->first();

        if ($user === null) {
            $registration = new UserRegistration([
                'country_acronym' => self::DEFAULT_COUNTRY,
                'password' => $password,
                'user_email' => $email,
                'username' => $username,
            ]);
            $registration->save();

            return $registration->user()->fresh();
        }

        $user->fill([
            'country_acronym' => self::DEFAULT_COUNTRY,
            'password' => $password,
            'user_email' => $email,
        ]);
        $user->saveOrExplode();

        return $user->fresh();
    }

    private function ensureLocalGroups(User $user): User
    {
        $user->addToGroup(app('groups')->byIdentifier('default'));

        foreach ($this->localGroupIdentifiers() as $identifier) {
            $group = app('groups')->byIdentifier($identifier);
            $playmodes = $group->has_playmodes ? array_keys(Beatmap::MODES) : null;

            $user->addToGroup($group, $playmodes);
        }

        $defaultGroupIdentifier = in_array('admin', $this->localGroupIdentifiers(), true) ? 'admin' : 'default';
        $user->setDefaultGroup(app('groups')->byIdentifier($defaultGroupIdentifier));

        return $user->fresh();
    }

    private function ensureConfiguredStaffUsers(User $localUser, string $password): void
    {
        foreach ($this->localStaffUsernames() as $username) {
            $user = User::where('username', $username)->first();

            if ($user === null) {
                $user = $this->ensureUser($username, $this->localStaffEmail($username), $password);
            }

            if ($user->getKey() === $localUser->getKey()) {
                continue;
            }

            $this->ensureLocalGroups($user);
            $this->ensureUserStatistics($user);
            $user->password = $password;
            $user->saveOrExplode();

            $this->command?->info("Local staff groups ready: {$user->username}, id {$user->getKey()}.");
        }
    }

    private function localStaffEmail(string $username): string
    {
        $safeUsername = strtolower((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $username));
        $safeUsername = trim($safeUsername, '.-_');

        if (!present($safeUsername)) {
            $safeUsername = 'staff';
        }

        return "{$safeUsername}@m1pposu.local";
    }

    private function ensureLocalSupporter(User $user): User
    {
        $duration = self::DEFAULT_SUPPORTER_LENGTH_MONTHS;

        $donation = UserDonation::firstOrNew([
            'transaction_id' => self::LOCAL_SUPPORTER_TRANSACTION_ID,
            'user_id' => $user->getKey(),
        ]);
        $donation->fill([
            'amount' => SupporterTag::getMinimumDonation($duration),
            'cancel' => false,
            'length' => $duration,
            'target_user_id' => $user->getKey(),
            'timestamp' => $donation->exists ? $donation->timestamp : Carbon::now(),
        ]);
        $donation->saveOrExplode();

        $user = $user->fresh();
        $expiry = Carbon::now()->floorSecond()->addHours($duration * 730);
        if ($user->osu_subscriptionexpiry === null || $user->osu_subscriptionexpiry->lessThan($expiry)) {
            $user->osu_subscriptionexpiry = $expiry;
        }
        $user->osu_subscriber = true;
        $user->refreshSupportLength();
        $user->saveOrExplode();

        return $user->fresh();
    }

    private function ensureUserStatistics(User $user): void
    {
        foreach (Beatmap::MODES as $ruleset => $_rulesetId) {
            $this->ensureStatistics($user, $ruleset);

            foreach (Beatmap::VARIANTS[$ruleset] ?? [] as $variant) {
                $this->ensureStatistics($user, $ruleset, $variant);
            }
        }
    }

    private function ensureStatistics(User $user, string $ruleset, ?string $variant = null): void
    {
        UserStatisticsModel::getClass($ruleset, $variant)::firstOrCreate([
            'user_id' => $user->getKey(),
        ], [
            'country_acronym' => $user->country_acronym,
        ]);
    }

    private function localGroupIdentifiers(): array
    {
        $value = env('LOCAL_AUTH_GROUPS');

        if (!present($value)) {
            return self::DEFAULT_GROUP_IDENTIFIERS;
        }

        return array_values(array_filter(explode(' ', $value), 'present'));
    }

    private function localStaffUsernames(): array
    {
        $value = env('M1PP_LOCAL_STAFF_USERNAMES', env('LOCAL_AUTH_STAFF_USERNAMES'));

        if (!present($value)) {
            return [];
        }

        return array_values(array_filter(explode(' ', $value), 'present'));
    }

    private function resetLocalUsers(string $username, string $email): void
    {
        if (get_bool(env('LOCAL_AUTH_RESET_USERS')) !== true) {
            return;
        }

        $usernames = array_values(array_unique([$username, self::LEGACY_LOCAL_USERNAME]));
        $emails = array_values(array_unique([$email, self::LEGACY_LOCAL_EMAIL]));

        User::whereIn('username', $usernames)
            ->orWhereIn('user_email', $emails)
            ->get()
            ->each(fn (User $user) => $this->deleteLocalUser($user));
    }

    private function deleteLocalUser(User $user): void
    {
        $user->resetSessions();
        $this->deleteUserStatistics($user);
        $user->userProfileCustomization()->delete();
        UserGroupEvent::where('user_id', $user->getKey())
            ->orWhere('actor_id', $user->getKey())
            ->delete();
        UserDonation::where('user_id', $user->getKey())
            ->orWhere('target_user_id', $user->getKey())
            ->delete();
        $user->userGroups()->delete();
        $user->forceDelete();
    }

    private function deleteUserStatistics(User $user): void
    {
        foreach (Beatmap::MODES as $ruleset => $_rulesetId) {
            $this->deleteStatistics($user, $ruleset);

            foreach (Beatmap::VARIANTS[$ruleset] ?? [] as $variant) {
                $this->deleteStatistics($user, $ruleset, $variant);
            }
        }
    }

    private function deleteStatistics(User $user, string $ruleset, ?string $variant = null): void
    {
        UserStatisticsModel::getClass($ruleset, $variant)::where('user_id', $user->getKey())->delete();
    }
}
