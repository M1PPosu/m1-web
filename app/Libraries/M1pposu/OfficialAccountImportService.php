<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Models\Beatmap;
use App\Models\M1pposuAccountImportRequest;
use App\Models\M1pposuAccountImportSnapshot;
use App\Models\M1pposuImportedOfficialScoreSummary;
use App\Models\M1pposuOfficialConnection;
use App\Models\User;
use App\Models\UserAccountHistory;
use Carbon\Carbon;
use DB;
use GuzzleHttp\Exception\GuzzleException;
use Log;

class OfficialAccountImportService
{
    private const MODES = ['osu', 'taiko', 'fruits', 'mania'];
    private const SCORE_KINDS = ['best', 'recent', 'firsts'];
    private const STAFF_REMOVAL_RESTRICTION_REASON = 'Official account import removed by staff';
    private const STAFF_RESTORE_NOTE_PREFIX = 'Official osu! import link restored by staff.';
    private const STAFF_REMOVAL_NOTE_PREFIX = 'Official osu! import link removed by staff.';
    private const SELF_REMOVAL_NOTE_PREFIX = 'Official osu! import link self-removed by user.';

    public function __construct(private OfficialOsuClient $client)
    {
    }

    public function createOrUpdateConnection(User $user, array $token, array $officialUser): M1pposuOfficialConnection
    {
        return DB::transaction(function () use ($user, $token, $officialUser) {
            $officialUserId = (int) $officialUser['id'];

            $existingLocal = $user->m1pposuOfficialConnection()->lockForUpdate()->first();
            abort_if(
                $existingLocal !== null && $existingLocal->official_user_id !== $officialUserId,
                422,
                osu_trans('accounts.official_osu.error.local_already_linked'),
            );

            $existingOfficial = M1pposuOfficialConnection
                ::where('official_user_id', $officialUserId)
                ->lockForUpdate()
                ->first();
            abort_if(
                $existingOfficial !== null && $existingOfficial->user_id !== $user->getKey(),
                422,
                osu_trans('accounts.official_osu.error.official_already_linked'),
            );

            return M1pposuOfficialConnection::updateOrCreate(
                ['user_id' => $user->getKey()],
                [
                    'official_user_id' => $officialUserId,
                    'username' => $officialUser['username'],
                    'avatar_url' => $officialUser['avatar_url'] ?? null,
                    'cover_url' => $officialUser['cover_url'] ?? ($officialUser['cover']['url'] ?? null),
                    'restricted_at_connection' => $this->isRestricted($officialUser),
                    'refresh_token' => $token['refresh_token'] ?? $existingLocal?->refresh_token,
                    'token_metadata' => [
                        'expires_in' => $token['expires_in'] ?? null,
                        'scope' => $token['scope'] ?? null,
                        'token_type' => $token['token_type'] ?? null,
                    ],
                    'connected_at' => $existingLocal?->connected_at ?? Carbon::now(),
                ],
            );
        });
    }

    public function createSnapshot(M1pposuOfficialConnection $connection, string $accessToken, array $officialUser): M1pposuAccountImportSnapshot
    {
        $data = [
            'import_scope' => [
                'profile',
                'statistics',
                'official_score_summaries',
                'recent_activity',
                'favourite_beatmaps',
                'most_played_beatmaps',
            ],
            'user' => $this->normalizeUser($officialUser),
            'statistics' => [],
            'scores' => [],
            'beatmapsets' => [],
            'recent_activity' => $this->safe(fn () => $this->client->userActivity($accessToken, $connection->official_user_id)),
            'captured_at' => Carbon::now()->toIso8601String(),
            'fetch_metadata' => [
                'source' => 'official_osu_api_v2',
                'activity_page_limit' => OfficialOsuClient::ACTIVITY_PAGE_LIMIT,
                'beatmapset_page_limit_per_type' => OfficialOsuClient::BEATMAPSET_PAGE_LIMIT,
                'max_pages_per_endpoint' => OfficialOsuClient::MAX_PAGES,
                'score_legacy_only' => true,
                'score_page_limit_per_kind_mode' => OfficialOsuClient::SCORE_PAGE_LIMIT,
                'limitations' => [
                    'official API does not expose complete historical ranked scores through this importer',
                    'official favorites/history are limited to the available paginated endpoint responses fetched here',
                    'imported scores are archived as official summaries and never inserted into native score tables',
                ],
            ],
        ];

        foreach (self::MODES as $mode) {
            $modeUser = $this->safe(fn () => $this->client->me($accessToken, $mode));
            $data['statistics'][$mode] = $this->normalizeStatistics($modeUser['statistics'] ?? null);

            foreach (self::SCORE_KINDS as $kind) {
                $data['scores'][$mode][$kind] = $this->safe(fn () => $this->client->userScores($accessToken, $connection->official_user_id, $kind, $mode));
            }
        }

        foreach (['favourite', 'most_played'] as $type) {
            $data['beatmapsets'][$type] = $this->safe(fn () => $this->client->userBeatmapsets($accessToken, $connection->official_user_id, $type));
        }

        return M1pposuAccountImportSnapshot::create([
            'connection_id' => $connection->getKey(),
            'user_id' => $connection->user_id,
            'official_user_id' => $connection->official_user_id,
            'data' => $data,
        ]);
    }

    public function createPendingReview(M1pposuOfficialConnection $connection, M1pposuAccountImportSnapshot $snapshot): M1pposuAccountImportRequest
    {
        return DB::transaction(function () use ($connection, $snapshot) {
            M1pposuOfficialConnection::whereKey($connection->getKey())->lockForUpdate()->firstOrFail();

            $existing = M1pposuAccountImportRequest
                ::where('user_id', $connection->user_id)
                ->where('official_user_id', $connection->official_user_id)
                ->where('status', M1pposuAccountImportRequest::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->update([
                    'connection_id' => $connection->getKey(),
                    'snapshot_id' => $snapshot->getKey(),
                    'restricted_at_request' => $connection->restricted_at_connection,
                ]);

                return $existing;
            }

            return M1pposuAccountImportRequest::create([
                'connection_id' => $connection->getKey(),
                'snapshot_id' => $snapshot->getKey(),
                'user_id' => $connection->user_id,
                'official_user_id' => $connection->official_user_id,
                'status' => M1pposuAccountImportRequest::STATUS_PENDING,
                'restricted_at_request' => $connection->restricted_at_connection,
            ]);
        });
    }

    public function createAppliedRequest(
        M1pposuOfficialConnection $connection,
        M1pposuAccountImportSnapshot $snapshot,
        ?User $actor,
        array $result,
        bool $allowNewAppliedRequest = false,
    ): M1pposuAccountImportRequest
    {
        return DB::transaction(function () use ($allowNewAppliedRequest, $actor, $connection, $result, $snapshot) {
            M1pposuOfficialConnection::whereKey($connection->getKey())->lockForUpdate()->firstOrFail();

            $existing = M1pposuAccountImportRequest
                ::where('user_id', $connection->user_id)
                ->where('official_user_id', $connection->official_user_id)
                ->where('status', M1pposuAccountImportRequest::STATUS_APPLIED)
                ->lockForUpdate()
                ->latest('applied_at')
                ->latest('id')
                ->first();

            if ($existing !== null && !$allowNewAppliedRequest) {
                return $existing;
            }

            $now = Carbon::now();

            return M1pposuAccountImportRequest::create([
                'applied_at' => $now,
                'connection_id' => $connection->getKey(),
                'decision_note' => $this->formatResultNote($result),
                'reviewed_at' => $now,
                'reviewed_by' => $actor?->getKey(),
                'snapshot_id' => $snapshot->getKey(),
                'user_id' => $connection->user_id,
                'official_user_id' => $connection->official_user_id,
                'status' => M1pposuAccountImportRequest::STATUS_APPLIED,
                'restricted_at_request' => $connection->restricted_at_connection,
            ]);
        });
    }

    public function refreshSnapshot(M1pposuOfficialConnection $connection): M1pposuAccountImportSnapshot
    {
        abort_if($connection->refresh_token === null, 422, osu_trans('accounts.official_osu.error.reconnect_required'));

        try {
            $token = $this->client->tokenFromRefreshToken($connection->refresh_token);
        } catch (GuzzleException $exception) {
            $connection->update([
                'refresh_token' => null,
                'token_metadata' => array_merge($connection->token_metadata ?? [], [
                    'reconnect_needed' => true,
                    'unavailable_at' => Carbon::now()->toIso8601String(),
                ]),
            ]);

            Log::warning('Official osu! refresh token is unavailable.', [
                'class' => $exception::class,
                'connection_id' => $connection->getKey(),
                'official_user_id' => $connection->official_user_id,
                'user_id' => $connection->user_id,
            ]);

            abort(422, osu_trans('accounts.official_osu.error.reconnect_required'));
        }

        $officialUser = $this->client->me($token['access_token']);

        $connection->update([
            'username' => $officialUser['username'],
            'avatar_url' => $officialUser['avatar_url'] ?? null,
            'cover_url' => $officialUser['cover_url'] ?? ($officialUser['cover']['url'] ?? null),
            'restricted_at_connection' => $this->isRestricted($officialUser),
            'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
            'token_metadata' => [
                'expires_in' => $token['expires_in'] ?? null,
                'reconnect_needed' => false,
                'scope' => $token['scope'] ?? null,
                'token_type' => $token['token_type'] ?? null,
            ],
        ]);

        return $this->createSnapshot($connection, $token['access_token'], $officialUser);
    }

    public function apply(M1pposuAccountImportSnapshot $snapshot, ?User $actor = null): array
    {
        return DB::transaction(function () use ($snapshot, $actor) {
            $user = $snapshot->user()->lockForUpdate()->firstOrFail();
            $data = $snapshot->data;
            $result = [
                'imported_statistics' => array_keys(array_filter($data['statistics'] ?? [], 'is_array')),
                'native_changes' => [],
                'blocked' => [],
                'imported_score_summaries' => 0,
            ];

            $officialUsername = get_string($data['user']['username'] ?? null);
            if ($officialUsername !== null && User::cleanUsername($officialUsername) !== $user->username_clean) {
                $errors = $user->validateChangeUsername($officialUsername, 'admin');
                if ($errors->isEmpty()) {
                    $user->changeUsername($officialUsername, 'admin');
                    $result['native_changes'][] = 'username';
                } else {
                    $result['blocked']['username'] = 'Official username is unavailable or cannot be applied safely.';
                }
            }

            $result['imported_score_summaries'] = $this->storeScoreSummaries($snapshot);

            return $result;
        });
    }

    public function hasSelfRemovedImport(M1pposuOfficialConnection $connection): bool
    {
        return M1pposuAccountImportRequest
            ::where('user_id', $connection->user_id)
            ->where('official_user_id', $connection->official_user_id)
            ->where('status', M1pposuAccountImportRequest::STATUS_SELF_REMOVED)
            ->exists();
    }

    public function removeByStaff(M1pposuAccountImportRequest $request, User $actor, string $reason): M1pposuAccountImportRequest
    {
        return $this->removeImport(
            $request,
            $actor,
            M1pposuAccountImportRequest::STATUS_REMOVED_BY_STAFF,
            $reason,
            true,
        );
    }

    public function restore(M1pposuAccountImportRequest $request, User $actor, string $reason): M1pposuAccountImportRequest
    {
        return DB::transaction(function () use ($actor, $reason, $request) {
            $request = $request->lockSelf();
            abort_unless($request !== null, 404);

            if ($request->status === M1pposuAccountImportRequest::STATUS_APPLIED) {
                return $request;
            }

            abort_unless(in_array($request->status, M1pposuAccountImportRequest::REMOVED_STATUSES, true), 422);

            $user = $request->user()->lockForUpdate()->firstOrFail();
            $note = UserAccountHistory::addNote(
                $user,
                $this->reasonWithNote(self::STAFF_RESTORE_NOTE_PREFIX, $reason),
                $actor,
            );

            if ($this->canClearImportRemovalRestriction($request, $user)) {
                $user->user_warnings = 0;
                $user->save();
            }

            $request->update([
                'restored_at' => Carbon::now(),
                'restored_by' => $actor->getKey(),
                'restore_account_history_id' => $note->getKey(),
                'restore_reason' => $this->auditReason($reason),
                'status' => M1pposuAccountImportRequest::STATUS_APPLIED,
            ]);

            return $request->fresh(['connection.user', 'removedBy', 'restoredBy', 'user']);
        });
    }

    public function selfRemove(M1pposuAccountImportRequest $request, User $actor): M1pposuAccountImportRequest
    {
        return $this->removeImport(
            $request,
            $actor,
            M1pposuAccountImportRequest::STATUS_SELF_REMOVED,
            'Self-removal requested from account settings.',
            false,
        );
    }

    public function isRestricted(array $officialUser): bool
    {
        return \get_bool($officialUser['is_restricted'] ?? null)
            ?? \get_bool($officialUser['is_restricted_at'] ?? null)
            ?? false;
    }

    private function normalizeUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'avatar_url' => $user['avatar_url'] ?? null,
            'cover_url' => $user['cover_url'] ?? ($user['cover']['url'] ?? null),
            'country_code' => $user['country_code'] ?? null,
            'badges' => $user['badges'] ?? [],
            'is_restricted' => $this->isRestricted($user),
            'is_supporter' => get_bool($user['is_supporter'] ?? null),
            'join_date' => $user['join_date'] ?? null,
            'page' => $user['page'] ?? null,
            'profile_colour' => $user['profile_colour'] ?? null,
            'title' => $user['title'] ?? null,
            'user_achievements' => $user['user_achievements'] ?? [],
        ];
    }

    private function normalizeStatistics(?array $statistics): ?array
    {
        if ($statistics === null) {
            return null;
        }

        $level = $statistics['level'] ?? [];

        return [
            'count_100' => (int) ($statistics['count_100'] ?? 0),
            'count_300' => (int) ($statistics['count_300'] ?? 0),
            'count_50' => (int) ($statistics['count_50'] ?? 0),
            'count_miss' => (int) ($statistics['count_miss'] ?? 0),
            'pp' => (float) ($statistics['pp'] ?? 0),
            'play_time' => (int) ($statistics['play_time'] ?? 0),
            'ranked_score' => (int) ($statistics['ranked_score'] ?? 0),
            'hit_accuracy' => (float) ($statistics['hit_accuracy'] ?? 0),
            'play_count' => (int) ($statistics['play_count'] ?? 0),
            'total_score' => (int) ($statistics['total_score'] ?? 0),
            'total_hits' => (int) (
                $statistics['total_hits']
                ?? (($statistics['count_300'] ?? 0) + ($statistics['count_100'] ?? 0) + ($statistics['count_50'] ?? 0))
            ),
            'maximum_combo' => (int) ($statistics['maximum_combo'] ?? 0),
            'level' => (int) ($level['current'] ?? 1) + ((float) ($level['progress'] ?? 0) / 100),
            'grade_counts' => [
                'ssh' => (int) ($statistics['grade_counts']['ssh'] ?? 0),
                'ss' => (int) ($statistics['grade_counts']['ss'] ?? 0),
                'sh' => (int) ($statistics['grade_counts']['sh'] ?? 0),
                's' => (int) ($statistics['grade_counts']['s'] ?? 0),
                'a' => (int) ($statistics['grade_counts']['a'] ?? 0),
            ],
        ];
    }

    private function safe(callable $callback): array
    {
        try {
            return $callback();
        } catch (\Throwable $exception) {
            Log::warning('Official osu! import snapshot fetch failed.', [
                'class' => $exception::class,
            ]);

            return [
                '_error' => 'fetch_failed',
            ];
        }
    }

    private function formatResultNote(array $result): string
    {
        return "Imported {$result['imported_score_summaries']} official score summaries. Native pp/rank/leaderboards unchanged.";
    }

    private function auditReason(string $reason): string
    {
        return mb_substr(trim($reason), 0, 8000);
    }

    private function canClearImportRemovalRestriction(M1pposuAccountImportRequest $request, User $user): bool
    {
        if (
            $request->status !== M1pposuAccountImportRequest::STATUS_REMOVED_BY_STAFF
            || $request->restricted_before_removal
            || $request->removal_account_history_id === null
            || (int) $user->user_warnings !== 1
        ) {
            return false;
        }

        $history = $request->removalAccountHistory;
        if (
            $history === null
            || $history->user_id !== $user->getKey()
            || $history->ban_status !== UserAccountHistory::TYPES['restriction']
            || $history->reason !== self::STAFF_REMOVAL_RESTRICTION_REASON
        ) {
            return false;
        }

        return !UserAccountHistory
            ::where('user_id', $user->getKey())
            ->where('ban_status', UserAccountHistory::TYPES['restriction'])
            ->where('ban_id', '<>', $request->removal_account_history_id)
            ->where('timestamp', '>=', $request->removed_at ?? Carbon::now())
            ->exists();
    }

    private function removeImport(
        M1pposuAccountImportRequest $request,
        User $actor,
        string $status,
        string $reason,
        bool $restrictUser,
    ): M1pposuAccountImportRequest {
        return DB::transaction(function () use ($actor, $reason, $request, $restrictUser, $status) {
            $request = $request->lockSelf();
            abort_unless($request !== null, 404);

            if ($request->status === $status) {
                return $request;
            }

            abort_unless(
                $request->status === M1pposuAccountImportRequest::STATUS_APPLIED
                    || in_array($request->status, M1pposuAccountImportRequest::REMOVED_STATUSES, true),
                422,
            );

            if (in_array($request->status, M1pposuAccountImportRequest::REMOVED_STATUSES, true)) {
                return $request;
            }

            $user = $request->user()->lockForUpdate()->firstOrFail();
            $connection = $request->connection()->lockForUpdate()->first();
            $restrictedBeforeRemoval = $user->isRestricted();
            $restrictionHistory = null;
            $notePrefix = $status === M1pposuAccountImportRequest::STATUS_REMOVED_BY_STAFF
                ? self::STAFF_REMOVAL_NOTE_PREFIX
                : self::SELF_REMOVAL_NOTE_PREFIX;

            UserAccountHistory::addNote(
                $user,
                $this->reasonWithNote($notePrefix, $reason),
                $actor,
            );

            if ($restrictUser && !$restrictedBeforeRemoval) {
                $user->user_warnings = 1;
                $user->save();

                $restrictionHistory = UserAccountHistory::create([
                    'ban_status' => UserAccountHistory::TYPES['restriction'],
                    'banner_id' => $actor->getKey(),
                    'permanent' => true,
                    'period' => 0,
                    'reason' => self::STAFF_REMOVAL_RESTRICTION_REASON,
                    'user_id' => $user->getKey(),
                ]);
            }

            if ($connection !== null) {
                $metadata = $connection->token_metadata ?? [];
                $metadata['import_removed_at'] = Carbon::now()->toIso8601String();
                $metadata['import_removed_status'] = $status;
                if ($status === M1pposuAccountImportRequest::STATUS_SELF_REMOVED) {
                    $metadata['manual_reimport_blocked'] = true;
                }

                $connection->update([
                    'refresh_token' => null,
                    'token_metadata' => $metadata,
                ]);
            }

            $request->update([
                'removed_at' => Carbon::now(),
                'removed_by' => $actor->getKey(),
                'remove_reason' => $this->auditReason($reason),
                'removal_account_history_id' => $restrictionHistory?->getKey(),
                'restricted_before_removal' => $restrictedBeforeRemoval,
                'status' => $status,
            ]);

            return $request->fresh(['connection.user', 'removedBy', 'restoredBy', 'user']);
        });
    }

    private function reasonWithNote(string $prefix, string $reason): string
    {
        $reason = $this->auditReason($reason);

        return $reason === ''
            ? $prefix
            : "{$prefix} Reason: {$reason}";
    }

    private function storeScoreSummaries(M1pposuAccountImportSnapshot $snapshot): int
    {
        M1pposuImportedOfficialScoreSummary::where('snapshot_id', $snapshot->getKey())->delete();
        $count = 0;

        foreach ($snapshot->data['scores'] ?? [] as $mode => $groups) {
            if (!Beatmap::isModeValid($mode) || !is_array($groups)) {
                continue;
            }

            foreach ($groups as $kind => $scores) {
                if (!in_array($kind, self::SCORE_KINDS, true) || !is_array($scores) || isset($scores['_error'])) {
                    continue;
                }

                foreach ($scores as $score) {
                    if (!is_array($score)) {
                        continue;
                    }

                    M1pposuImportedOfficialScoreSummary::create([
                        'snapshot_id' => $snapshot->getKey(),
                        'user_id' => $snapshot->user_id,
                        'official_user_id' => $snapshot->official_user_id,
                        'kind' => $kind,
                        'mode' => $mode,
                        'official_score_id' => get_int($score['id'] ?? null),
                        'beatmap_id' => get_int($score['beatmap']['id'] ?? $score['beatmap_id'] ?? null),
                        'pp' => get_float($score['pp'] ?? null),
                        'accuracy' => get_float($score['accuracy'] ?? null),
                        'total_score' => get_int($score['total_score'] ?? $score['score'] ?? null),
                        'data' => $score,
                    ]);
                    $count++;
                }
            }
        }

        return $count;
    }
}
