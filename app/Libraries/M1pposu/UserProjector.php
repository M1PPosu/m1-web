<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Libraries\UsernameValidation;
use App\Models\Count;
use App\Models\Country;
use App\Models\M1pposuExternalUser;
use App\Models\User;
use App\Models\UserAccountHistory;
use App\Models\UserStatistics\Model as UserStatisticsModel;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Locale;

class UserProjector
{
    public const SOURCE_STATS_COLUMNS = [
        'id',
        'mode',
        'tscore',
        'rscore',
        'pp',
        'plays',
        'playtime',
        'acc',
        'max_combo',
        'total_hits',
        'replay_views',
        'xh_count',
        'x_count',
        'sh_count',
        's_count',
        'a_count',
    ];

    public function sync(object $sourceUser, $sourceStatsRows, string $backend): array
    {
        $summary = [
            'created_user' => false,
            'updated_user' => false,
            'linked_external_mapping' => false,
            'stats_rows_updated' => 0,
            'supporter_user_updated' => false,
            'source_user_restricted' => false,
            'restriction_user_updated' => false,
            'source_user_silence_status' => 'none',
            'silence_user_updated' => false,
            'deferred' => [],
            'user' => null,
        ];

        $user = $this->findDestinationUser($sourceUser, $backend);

        if ($user === null) {
            $user = $this->createDestinationUser($sourceUser);
            $summary['created_user'] = true;
        } else {
            $summary['updated_user'] = $this->updateDestinationUser($user, $sourceUser, $summary);
        }

        $summary['linked_external_mapping'] = $this->syncExternalMapping($user, $sourceUser, $backend);
        $summary['restriction_user_updated'] = $this->syncRestrictionState($user, $sourceUser, $summary);
        $summary['silence_user_updated'] = $this->syncSilenceState($user, $sourceUser, $backend, $summary);
        $summary['supporter_user_updated'] = $this->syncSupporter($user, $sourceUser);

        foreach ($sourceStatsRows as $sourceStats) {
            if ($this->syncStatsRow($user, $sourceStats, $summary)) {
                $summary['stats_rows_updated']++;
            }
        }

        $summary['user'] = $user->fresh();

        return $summary;
    }
    public function findDestinationUser(object $sourceUser, string $backend): ?User
    {
        $mapping = M1pposuExternalUser::query()
            ->where('backend', $backend)
            ->where('external_user_id', (string) $sourceUser->id)
            ->first();

        if ($mapping === null) {
            return null;
        }

        $user = User::find($mapping->user_id);
        if ($user === null) {
            throw new \RuntimeException("source user {$sourceUser->id} has an external mapping to missing destination user {$mapping->user_id}");
        }

        return $user;
    }

    public function projectionFailureReason(object $sourceUser, string $backend): ?string
    {
        try {
            if ($this->findDestinationUser($sourceUser, $backend) !== null) {
                return null;
            }
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return $this->sourceUsernameFailureReason($sourceUser);
    }

    private function createDestinationUser(object $sourceUser): User
    {
        $this->assertSourceUsernameSupported($sourceUser);

        $sourceEmail = $this->validEmail($sourceUser->email);
        if ($sourceEmail !== null && User::where('user_email', $sourceEmail)->exists()) {
            $sourceEmail = null;
        }

        $params = [
            'username' => $sourceUser->name,
            'user_email' => $sourceEmail,
            'password' => Str::random(64),
            'country_acronym' => $this->sourceCountry($sourceUser->country, createMissing: true),
        ];

        if (($regdate = $this->sourceTimestamp($sourceUser->creation_time ?? null)) !== null) {
            $params['user_regdate'] = $regdate;
        }

        if (($lastVisit = $this->sourceTimestamp($sourceUser->latest_activity ?? null)) !== null) {
            $params['user_lastvisit'] = $lastVisit;
        }

        $group = app(ReferenceData::class)->defaultGroup();
        $params['group_id'] = $group->getKey();

        $user = new User(array_merge([
            'user_permissions' => '',
            'user_interests' => '',
            'user_occ' => '',
            'user_sig' => '',
            'user_regdate' => Carbon::now(),
            'user_lastvisit' => Carbon::now(),
        ], $params));

        $user->getConnection()->transaction(function () use ($group, $user) {
            User::findAndRenameUserForInactive($user->username);
            $user->saveOrExplode();
            $user->setDefaultGroup($group);
            Count::totalUsers()->incrementInstance('count');
        });

        return $user->fresh();
    }

    private function updateDestinationUser(User $user, object $sourceUser, array &$summary): bool
    {
        $sourceName = $this->nullableString($sourceUser->name ?? null);
        if ($sourceName !== null && $sourceName !== $user->username) {
            $usernameFailureReason = $this->sourceUsernameFailureReason($sourceUser);

            if ($usernameFailureReason !== null) {
                $this->addDeferred($summary, 'users.name', "{$usernameFailureReason}; username not updated");
            } else {
                $conflict = User::where('username', $sourceName)
                    ->where('user_id', '<>', $user->getKey())
                    ->exists();

                if ($conflict) {
                    $this->addDeferred($summary, 'users.name', 'source username already belongs to another destination user; username not updated');
                } else {
                    $user->username = $sourceName;
                }
            }
        }

        $sourceEmail = $this->validEmail($sourceUser->email ?? null);
        if ($sourceEmail !== null && $sourceEmail !== $user->user_email) {
            $conflict = User::where('user_email', $sourceEmail)
                ->where('user_id', '<>', $user->getKey())
                ->exists();

            if ($conflict) {
                $this->addDeferred($summary, 'users.email', 'source email already belongs to another destination user; email not updated');
            } else {
                $user->user_email = $sourceEmail;
            }
        } elseif ($this->nullableString($sourceUser->email ?? null) !== null && $sourceEmail === null) {
            $this->addDeferred($summary, 'users.email', 'invalid source email; email not updated');
        }

        if (($country = $this->sourceCountry($sourceUser->country ?? null, $summary, true)) !== null) {
            $user->country_acronym = $country;
        }

        if (($regdate = $this->sourceTimestamp($sourceUser->creation_time ?? null)) !== null) {
            $user->user_regdate = $regdate;
        }

        if (($lastVisit = $this->sourceTimestamp($sourceUser->latest_activity ?? null)) !== null) {
            $user->user_lastvisit = $lastVisit;
        }

        if (!$user->isDirty()) {
            return false;
        }

        $user->saveOrExplode();

        return true;
    }

    private function syncExternalMapping(User $user, object $sourceUser, string $backend): bool
    {
        $mapping = M1pposuExternalUser::query()
            ->where('backend', $backend)
            ->where('external_user_id', (string) $sourceUser->id)
            ->first();

        if ($mapping === null) {
            $mapping = new M1pposuExternalUser([
                'backend' => $backend,
                'external_user_id' => (string) $sourceUser->id,
            ]);
        }

        $mapping->user_id = $user->getKey();
        $mapping->external_username = $sourceUser->name;

        if (!$mapping->exists || $mapping->isDirty()) {
            $mapping->saveOrExplode();

            return true;
        }

        return false;
    }

    private function syncRestrictionState(User $user, object $sourceUser, array &$summary): bool
    {
        $isRestricted = SourcePrivileges::isRestricted($sourceUser->priv ?? null);

        if ($isRestricted === null) {
            $this->addDeferred($summary, 'users.priv', 'invalid value; restriction state not mapped');

            return false;
        }

        $summary['source_user_restricted'] = $isRestricted;
        $user->user_warnings = $isRestricted ? 1 : 0;

        if (!$user->isDirty('user_warnings')) {
            return false;
        }

        $user->saveOrExplode();

        return true;
    }

    private function syncSilenceState(User $user, object $sourceUser, string $backend, array &$summary): bool
    {
        $silenceEnd = $this->sourceTimestamp($sourceUser->silence_end ?? null);
        $status = $this->sourceSilenceStatus($silenceEnd);
        $summary['source_user_silence_status'] = $status;

        $mapping = M1pposuExternalUser::query()
            ->where('backend', $backend)
            ->where('external_user_id', (string) $sourceUser->id)
            ->first();

        if ($mapping === null) {
            return false;
        }

        $history = $this->sourceSilenceHistory($mapping);

        if ($status !== 'active') {
            return $this->clearSourceSilence($mapping, $history);
        }

        return $this->upsertSourceSilence($mapping, $history, $user, $silenceEnd);
    }

    private function clearSourceSilence(M1pposuExternalUser $mapping, ?UserAccountHistory $history): bool
    {
        $updated = false;

        if ($history !== null) {
            $history->delete();
            $updated = true;
        }

        if ($mapping->source_silence_account_history_id !== null) {
            $mapping->source_silence_account_history_id = null;
            $mapping->saveOrExplode();
            $updated = true;
        }

        return $updated;
    }

    private function sourceSilenceHistory(M1pposuExternalUser $mapping): ?UserAccountHistory
    {
        $historyId = $mapping->source_silence_account_history_id;

        return $historyId === null
            ? null
            : UserAccountHistory::find($historyId);
    }

    private function sourceSilenceStatus(?Carbon $silenceEnd): string
    {
        if ($silenceEnd === null) {
            return 'none';
        }

        return $silenceEnd->isFuture() ? 'active' : 'expired';
    }

    private function upsertSourceSilence(M1pposuExternalUser $mapping, ?UserAccountHistory $history, User $user, Carbon $silenceEnd): bool
    {
        $now = Carbon::now();
        $period = max(1, $silenceEnd->getTimestamp() - $now->getTimestamp());
        $updated = false;

        if ($history === null) {
            $history = new UserAccountHistory();
            $history->user_id = $user->getKey();
            $history->ban_status = UserAccountHistory::TYPES['silence'];
            $history->banner_id = null;
            $history->reason = null;
            $history->supporting_url = null;
            $history->permanent = false;
            $history->timestamp = $now;
            $history->period = $period;
            $history->saveOrExplode();

            $mapping->source_silence_account_history_id = $history->getKey();
            $mapping->saveOrExplode();

            return true;
        }

        $projectedEnd = $history->timestamp?->copy()->addSeconds($history->period);

        $history->user_id = $user->getKey();
        $history->ban_status = UserAccountHistory::TYPES['silence'];
        $history->banner_id = null;
        $history->reason = null;
        $history->supporting_url = null;
        $history->permanent = false;

        if ($projectedEnd?->getTimestamp() !== $silenceEnd->getTimestamp()) {
            $history->timestamp = $now;
            $history->period = $period;
        }

        if ($history->isDirty()) {
            $history->saveOrExplode();
            $updated = true;
        }

        if ($mapping->source_silence_account_history_id !== $history->getKey()) {
            $mapping->source_silence_account_history_id = $history->getKey();
            $mapping->saveOrExplode();
            $updated = true;
        }

        return $updated;
    }

    private function syncSupporter(User $user, object $sourceUser): bool
    {
        $donorEnd = $this->sourceTimestamp($sourceUser->donor_end ?? null);
        $isActiveSupporter = $donorEnd !== null && $donorEnd->isFuture();
        $supporterExpiry = $isActiveSupporter ? $donorEnd->toDateTimeString() : null;

        $user->osu_subscriber = $isActiveSupporter;
        $user->osu_subscriptionexpiry = $supporterExpiry;
        $user->support_length = 0;

        if (!$user->isDirty(['osu_subscriber', 'osu_subscriptionexpiry', 'support_length'])) {
            return false;
        }

        $user->saveOrExplode();

        return true;
    }

    private function syncStatsRow(User $user, object $sourceStats, array &$summary): bool
    {
        $modeInt = (int) $sourceStats->mode;
        $mode = SourceMode::mode($modeInt);

        if ($mode === null) {
            $this->addDeferred($summary, 'stats.mode', "unsupported mode {$sourceStats->mode}");

            return false;
        }

        $statsClass = UserStatisticsModel::getClass($mode['ruleset'], $mode['variant']);
        $stats = $statsClass::firstOrNew(['user_id' => $user->getKey()]);
        $isNew = !$stats->exists;

        $attributes = [
            'total_score' => (int) $sourceStats->tscore,
            'level' => ScoreLevel::fromTotalScore((int) $sourceStats->tscore),
            'ranked_score' => (int) $sourceStats->rscore,
            'rank_score' => (float) $sourceStats->pp,
            'playcount' => (int) $sourceStats->plays,
            'total_seconds_played' => (int) $sourceStats->playtime,
            'accuracy' => $this->normalAccuracy($sourceStats->acc),
            'accuracy_new' => (float) $sourceStats->acc,
            'max_combo' => (int) $sourceStats->max_combo,
            'replay_popularity' => (int) $sourceStats->replay_views,
            'xh_rank_count' => (int) $sourceStats->xh_count,
            'x_rank_count' => (int) $sourceStats->x_count,
            'sh_rank_count' => (int) $sourceStats->sh_count,
            's_rank_count' => (int) $sourceStats->s_count,
            'a_rank_count' => (int) $sourceStats->a_count,
            'rank' => 0,
        ];

        if ($isNew) {
            $attributes['rank_score_index'] = 0;
        }

        $stats->fill($attributes);

        if (($country = $this->sourceCountry($user->country_acronym ?? null, $summary)) !== null) {
            $stats->country_acronym = $country;
        }

        if (!$isNew && !$stats->isDirty()) {
            return false;
        }

        $stats->saveOrExplode();

        return true;
    }

    private function sourceCountry($value, ?array &$summary = null, bool $createMissing = false): ?string
    {
        $country = strtoupper(trim((string) $value));

        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            if ($summary !== null && $country !== '') {
                $this->addDeferred($summary, 'users.country', "invalid code {$country}; using ".Country::UNKNOWN);
            }

            return $this->unknownCountry();
        }

        if ($country === Country::UNKNOWN) {
            return $this->unknownCountry();
        }

        if (Country::where('acronym', $country)->exists()) {
            return $country;
        }

        if ($createMissing) {
            $countryName = Locale::getDisplayRegion("-{$country}", 'en');

            if (present($countryName) && $countryName !== $country) {
                Country::create([
                    'acronym' => $country,
                    'display' => 0,
                    'name' => $countryName,
                    'playcount' => 0,
                    'pp' => 0,
                    'rankedscore' => 0,
                    'shipping_rate' => 1,
                    'usercount' => 0,
                ]);

                app('countries')->resetMemoized();

                return $country;
            }
        }

        if ($summary !== null) {
            $this->addDeferred($summary, 'users.country', "unknown code {$country}; using ".Country::UNKNOWN);
        }

        return $this->unknownCountry();
    }

    private function unknownCountry(): string
    {
        static $ensured = false;

        if (!$ensured) {
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
            app('countries')->resetMemoized();
            $ensured = true;
        }

        return Country::UNKNOWN;
    }

    private function sourceTimestamp($value): ?Carbon
    {
        if (!is_numeric($value)) {
            return null;
        }

        $timestamp = (int) $value;
        if ($timestamp <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp);
    }

    private function validEmail($email): ?string
    {
        $email = $this->nullableString($email);

        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? $email
            : null;
    }

    private function normalAccuracy($accuracy): float
    {
        $accuracy = (float) $accuracy;

        return $accuracy > 1 ? $accuracy / 100 : $accuracy;
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function addDeferred(array &$summary, string $field, string $detail): void
    {
        $key = "{$field}: {$detail}";
        $summary['deferred'][$key] = ($summary['deferred'][$key] ?? 0) + 1;
    }

    private function assertSourceUsernameSupported(object $sourceUser): void
    {
        $reason = $this->sourceUsernameFailureReason($sourceUser);

        if ($reason !== null) {
            throw new \RuntimeException("source user {$sourceUser->id}: {$reason}");
        }
    }

    private function sourceUsernameFailureReason(object $sourceUser): ?string
    {
        $rawSourceName = (string) ($sourceUser->name ?? '');
        if ($rawSourceName !== trim($rawSourceName)) {
            return 'unsupported username: username has leading or trailing spaces';
        }

        $sourceName = $this->nullableString($rawSourceName);
        if ($sourceName === null) {
            return 'missing username';
        }

        foreach (
            [
                UsernameValidation::validateUsername($sourceName),
                UsernameValidation::validateAvailability($sourceName),
                UsernameValidation::validateUsersOfUsername($sourceName),
            ] as $errors
        ) {
            if ($errors->isAny()) {
                return 'unsupported username: '.$errors->toSentence('; ');
            }
        }

        return null;
    }
}
