<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Models\Beatmap;
use App\Models\BeatmapPlaycount;
use App\Models\Beatmapset;
use App\Models\Country;
use App\Models\M1pposuAccountImportRequest;
use App\Models\M1pposuImportedOfficialScoreSummary;
use App\Models\User;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use InvalidArgumentException;

class OfficialProfileImport
{
    public const FIELD_AVATAR = 'avatar';
    public const FIELD_COVER = 'cover';
    public const FIELD_USERPAGE = 'userpage';

    private const OFFICIAL_ASSET_HOSTS = ['a.ppy.sh', 'assets.ppy.sh', 'b.ppy.sh'];
    private const OFFICIAL_PAGE_HOSTS = ['osu.ppy.sh'];
    private const BEATMAPSET_STATUSES = ['graveyard', 'wip', 'pending', 'ranked', 'approved', 'qualified', 'loved'];
    private const RANKED_DATE_STATUSES = ['approved', 'loved', 'qualified', 'ranked'];
    private const SCORE_KINDS = ['best', 'firsts', 'recent'];

    private array $requestCache = [];

    public function forUser(User $user, ?string $mode = null): ?array
    {
        $request = $this->appliedRequest($user, ['snapshot']);

        if ($request === null || $request->snapshot === null) {
            return null;
        }

        $data = $request->snapshot->data;
        $profile = $data['user'] ?? [];
        $mode ??= $user->playmode;

        return [
            'applied_at' => json_time($request->applied_at ?? $request->updated_at),
            'official_user_id' => $request->official_user_id,
            'official_url' => "https://osu.ppy.sh/users/{$request->official_user_id}",
            'profile' => $this->profile($profile),
            'statistics' => $this->statistics($data['statistics'] ?? [], $mode),
        ];
    }

    public function avatarUrl(User $user): ?string
    {
        $profile = $this->importedProfile($user, self::FIELD_AVATAR);

        return $profile === null ? null : $this->officialAssetUrl($profile['avatar_url'] ?? null);
    }

    public function coverUrl(User $user): ?string
    {
        $profile = $this->importedProfile($user, self::FIELD_COVER);

        return $profile === null ? null : $this->officialAssetUrl($profile['cover_url'] ?? null);
    }

    public function isOverrideMarked(User $user, string $field): bool
    {
        $column = $this->overrideColumn($field);
        $connection = $user->relationLoaded('m1pposuOfficialConnection')
            ? $user->m1pposuOfficialConnection
            : $user->m1pposuOfficialConnection()->first();

        return $connection?->{$column} !== null;
    }

    public function markOverride(User $user, string $field): void
    {
        $column = $this->overrideColumn($field);

        $user->m1pposuOfficialConnection()->update([$column => Carbon::now()]);
        $user->unsetRelation('m1pposuLatestOfficialImportRequest');
        $user->unsetRelation('m1pposuOfficialConnection');
        unset($this->requestCache[$user->getKey()]);
    }

    public function userpage(User $user): ?array
    {
        $profile = $this->importedProfile($user, self::FIELD_USERPAGE);
        $page = $profile['page'] ?? null;
        if (!is_array($page)) {
            return null;
        }

        $html = get_string($page['html'] ?? null);
        $raw = get_string($page['raw'] ?? null);
        if ($html === null && $raw === null) {
            return null;
        }

        return [
            'html' => $html === null ? '' : app('clean-html')->purify($html),
            'raw' => $raw ?? '',
        ];
    }

    public function beatmapsetCount(User $user, string $type): int
    {
        return count($this->beatmapsetItems($user, $type));
    }

    public function beatmapsetItems(User $user, string $type, int $offset = 0, ?int $limit = null): array
    {
        $request = $this->appliedRequest($user);
        $data = $request?->snapshot?->data;
        if (!is_array($data)) {
            return [];
        }

        $items = match ($type) {
            'favourite' => $this->favouriteBeatmapsets($data['beatmapsets']['favourite'] ?? []),
            'most_played' => $this->mostPlayedBeatmaps($data['beatmapsets']['most_played'] ?? []),
            default => [],
        };

        return array_slice($items, $offset, $limit);
    }

    public function recentActivityCount(User $user): int
    {
        return count($this->recentActivityItems($user));
    }

    public function recentActivityItems(User $user, int $offset = 0, ?int $limit = null): array
    {
        $request = $this->appliedRequest($user);
        $data = $request?->snapshot?->data;
        if (!is_array($data)) {
            return [];
        }

        $activity = $data['recent_activity'] ?? [];
        if (!is_array($activity) || isset($activity['_error'])) {
            return [];
        }

        $items = array_values(array_filter(array_map(
            fn ($event) => is_array($event) ? $this->recentActivityEvent($user, $event) : null,
            $activity,
        )));

        return array_slice($items, $offset, $limit);
    }

    public function scoreCount(User $user, string $mode, string $kind, ?string $variant = null): int
    {
        if ($variant !== null || !Beatmap::isModeValid($mode) || !in_array($kind, self::SCORE_KINDS, true)) {
            return 0;
        }

        $request = $this->appliedRequest($user);

        return $request === null
            ? 0
            : M1pposuImportedOfficialScoreSummary::query()
                ->where('snapshot_id', $request->snapshot_id)
                ->where('mode', $mode)
                ->where('kind', $kind)
                ->count();
    }

    public function scoreItems(
        User $user,
        string $mode,
        string $kind,
        int $offset = 0,
        ?int $limit = null,
        ?string $variant = null,
    ): array {
        if ($variant !== null || !Beatmap::isModeValid($mode) || !in_array($kind, self::SCORE_KINDS, true)) {
            return [];
        }

        $request = $this->appliedRequest($user);
        if ($request === null) {
            return [];
        }

        $rows = M1pposuImportedOfficialScoreSummary
            ::where('snapshot_id', $request->snapshot_id)
            ->where('mode', $mode)
            ->where('kind', $kind)
            ->orderByRaw('pp IS NULL')
            ->orderByDesc('pp')
            ->orderByDesc('id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->when($offset > 0, fn ($query) => $query
                ->limit($limit ?? PHP_INT_MAX)
                ->offset($offset))
            ->get();

        $items = array_values(array_filter($rows->map(
            fn (M1pposuImportedOfficialScoreSummary $summary) => $this->score($summary, $user, $mode),
        )->all()));

        return $items;
    }

    private function appliedRequest(User $user, array $with = []): ?M1pposuAccountImportRequest
    {
        $userId = $user->getKey();
        if (!array_key_exists($userId, $this->requestCache)) {
            $request = $user->relationLoaded('m1pposuLatestOfficialImportRequest')
                ? $user->m1pposuLatestOfficialImportRequest
                : $user->m1pposuLatestOfficialImportRequest()->first();

            $this->requestCache[$userId] = $request?->status === M1pposuAccountImportRequest::ACTIVE_STATUS
                ? $request
                : null;
        }

        $request = $this->requestCache[$userId];
        if ($request !== null && $with !== []) {
            $request->loadMissing($with);
        }

        return $request;
    }

    private function importedProfile(User $user, string $field): ?array
    {
        $request = $this->appliedRequest($user, ['connection', 'snapshot']);
        $connection = $request?->connection;
        if ($connection === null || $connection->{$this->overrideColumn($field)} !== null) {
            return null;
        }

        $profile = $request->snapshot?->data['user'] ?? null;

        return is_array($profile) ? $profile : null;
    }

    private function overrideColumn(string $field): string
    {
        return match ($field) {
            self::FIELD_AVATAR => 'imported_avatar_overridden_at',
            self::FIELD_COVER => 'imported_cover_overridden_at',
            self::FIELD_USERPAGE => 'imported_userpage_overridden_at',
            default => throw new InvalidArgumentException("Unknown official profile field '{$field}'."),
        };
    }

    private function beatmap(array $beatmap): ?array
    {
        $id = get_int($beatmap['id'] ?? null);
        $beatmapsetId = get_int($beatmap['beatmapset_id'] ?? null);
        $difficultyRating = get_float($beatmap['difficulty_rating'] ?? null);
        $mode = get_string($beatmap['mode'] ?? null);
        $status = get_string($beatmap['status'] ?? null);
        $totalLength = get_int($beatmap['total_length'] ?? null);
        $userId = get_int($beatmap['user_id'] ?? null);
        $version = get_string($beatmap['version'] ?? null);

        if (
            $id === null
            || $beatmapsetId === null
            || $difficultyRating === null
            || !Beatmap::isModeValid($mode)
            || $status === null
            || $totalLength === null
            || $userId === null
            || $version === null
        ) {
            return null;
        }

        return [
            ...$beatmap,
            'beatmapset_id' => $beatmapsetId,
            'difficulty_rating' => $difficultyRating,
            'id' => $id,
            'mode' => $mode,
            'status' => $status,
            'total_length' => $totalLength,
            'url' => $this->officialPageUrl($beatmap['url'] ?? null, "https://osu.ppy.sh/beatmaps/{$id}"),
            'user_id' => $userId,
            'version' => $version,
        ];
    }

    private function beatmapset(array $beatmapset): ?array
    {
        $id = get_int($beatmapset['id'] ?? null);
        $artist = get_string($beatmapset['artist'] ?? null);
        $creator = get_string($beatmapset['creator'] ?? null);
        $title = get_string($beatmapset['title'] ?? null);
        $userId = get_int($beatmapset['user_id'] ?? null);

        if ($id === null || $artist === null || $creator === null || $title === null || $userId === null) {
            return null;
        }

        return [
            ...$beatmapset,
            'artist' => $artist,
            'artist_unicode' => get_string($beatmapset['artist_unicode'] ?? null) ?? $artist,
            'creator' => $creator,
            'id' => $id,
            'title' => $title,
            'title_unicode' => get_string($beatmapset['title_unicode'] ?? null) ?? $title,
            'url' => $this->officialPageUrl($beatmapset['url'] ?? null, "https://osu.ppy.sh/beatmapsets/{$id}"),
            'user_id' => $userId,
        ];
    }

    private function profile(array $profile): array
    {
        $countryCode = get_string($profile['country_code'] ?? null);
        $country = $countryCode === null ? null : app('countries')->byCode($countryCode);
        $badges = is_array($profile['badges'] ?? null) ? $profile['badges'] : [];

        return [
            'badges' => $this->badges($badges),
            'badges_count' => count($badges),
            'country' => $country === null || $country->acronym === Country::UNKNOWN ? null : [
                'code' => $country->acronym,
                'name' => $country->name,
            ],
            'is_supporter' => get_bool($profile['is_supporter'] ?? null) ?? false,
            'join_date' => isset($profile['join_date']) ? json_time(Carbon::parse($profile['join_date'])) : null,
            'title' => get_string($profile['title'] ?? null),
            'username' => get_string($profile['username'] ?? null),
        ];
    }

    private function badges(array $badges): array
    {
        return array_values(array_filter(array_map(function ($badge) {
            if (!is_array($badge)) {
                return null;
            }

            $imageUrl = $this->officialAssetUrl($badge['image_url'] ?? null);
            if ($imageUrl === null) {
                return null;
            }

            $image2xUrl = $this->officialAssetUrl($badge['image@2x_url'] ?? $badge['image_2x_url'] ?? null)
                ?? retinaify($imageUrl);

            return [
                'awarded_at' => get_string($badge['awarded_at'] ?? null),
                'description' => get_string($badge['description'] ?? $badge['name'] ?? null) ?? '',
                'image@2x_url' => $image2xUrl,
                'image_url' => $imageUrl,
                'url' => $this->officialPageUrl($badge['url'] ?? null, ''),
            ];
        }, $badges)));
    }

    private function favouriteBeatmapsets(mixed $beatmapsets): array
    {
        if (!is_array($beatmapsets) || isset($beatmapsets['_error'])) {
            return [];
        }

        $officialBeatmapsets = array_values(array_filter($beatmapsets, 'is_array'));
        $ids = array_values(array_filter(array_map(
            fn ($beatmapset) => is_array($beatmapset) ? get_int($beatmapset['id'] ?? null) : null,
            $officialBeatmapsets,
        )));
        if (empty($ids)) {
            return [];
        }

        $localBeatmapsets = Beatmapset
            ::whereIn('beatmapset_id', $ids)
            ->with('beatmaps')
            ->get()
            ->keyBy('beatmapset_id');

        $items = [];
        foreach ($officialBeatmapsets as $officialBeatmapset) {
            $id = get_int($officialBeatmapset['id'] ?? null);
            if ($id === null) {
                continue;
            }

            $beatmapset = $localBeatmapsets->get($id);
            if ($beatmapset !== null) {
                $items[] = json_item($beatmapset, 'Beatmapset', ['beatmaps']);
            } else {
                $items[] = $this->externalBeatmapset($officialBeatmapset);
            }
        }

        return array_values(array_filter($items));
    }

    private function mostPlayedBeatmaps(mixed $playcounts): array
    {
        if (!is_array($playcounts) || isset($playcounts['_error'])) {
            return [];
        }

        $rows = [];
        foreach ($playcounts as $playcount) {
            if (!is_array($playcount)) {
                continue;
            }

            $beatmapId = get_int($playcount['beatmap_id'] ?? $playcount['beatmap']['id'] ?? null);
            $count = get_int($playcount['count'] ?? null);
            if ($beatmapId !== null && $count !== null) {
                $rows[] = compact('beatmapId', 'count', 'playcount');
            }
        }

        if (empty($rows)) {
            return [];
        }

        $beatmaps = Beatmap
            ::whereIn('beatmap_id', array_column($rows, 'beatmapId'))
            ->with('beatmapset')
            ->get()
            ->keyBy('beatmap_id');

        $items = [];
        foreach ($rows as $row) {
            $beatmap = $beatmaps->get($row['beatmapId']);
            if ($beatmap === null || $beatmap->beatmapset === null) {
                $officialBeatmap = is_array($row['playcount']['beatmap'] ?? null)
                    ? $this->beatmap($row['playcount']['beatmap'])
                    : null;
                $officialBeatmapset = is_array($row['playcount']['beatmapset'] ?? null)
                    ? $this->externalBeatmapset(
                        $row['playcount']['beatmapset'],
                        $officialBeatmap === null ? [] : [$officialBeatmap],
                        false,
                    )
                    : null;

                if ($officialBeatmap === null || $officialBeatmapset === null) {
                    continue;
                }

                $items[] = [
                    'beatmap' => $officialBeatmap,
                    'beatmap_id' => $row['beatmapId'],
                    'beatmapset' => $officialBeatmapset,
                    'count' => $row['count'],
                ];

                continue;
            }

            $playcount = new BeatmapPlaycount([
                'beatmap_id' => $row['beatmapId'],
                'playcount' => $row['count'],
            ]);
            $playcount->setRelation('beatmap', $beatmap);

            $items[] = json_item($playcount, 'BeatmapPlaycount');
        }

        return array_values(array_filter($items));
    }

    private function beatmapsetCovers(int $id, mixed $covers): array
    {
        $covers = is_array($covers) ? $covers : [];

        return [
            'card' => $this->officialAssetUrl($covers['card'] ?? null, "https://assets.ppy.sh/beatmaps/{$id}/covers/card.jpg"),
            'cover' => $this->officialAssetUrl($covers['cover'] ?? null, "https://assets.ppy.sh/beatmaps/{$id}/covers/cover.jpg"),
            'list' => $this->officialAssetUrl($covers['list'] ?? null, "https://assets.ppy.sh/beatmaps/{$id}/covers/list.jpg"),
            'slimcover' => $this->officialAssetUrl($covers['slimcover'] ?? null, "https://assets.ppy.sh/beatmaps/{$id}/covers/slimcover.jpg"),
        ];
    }

    private function beatmapsetDisplayDate(string $status, ?string $lastUpdated, ?string $rankedDate): ?string
    {
        if (in_array($status, self::RANKED_DATE_STATUSES, true)) {
            return $rankedDate;
        }

        return $lastUpdated;
    }

    private function beatmapsetStatus(mixed $status): ?string
    {
        $status = get_string($status);

        return in_array($status, self::BEATMAPSET_STATUSES, true) ? $status : null;
    }

    private function externalBeatmapset(array $beatmapset, array $beatmaps = [], bool $requirePublicStats = true): ?array
    {
        $base = $this->beatmapset($beatmapset);
        if ($base === null) {
            return null;
        }

        $status = $this->beatmapsetStatus($beatmapset['status'] ?? null);
        $lastUpdated = $this->parseJsonTime($beatmapset['last_updated'] ?? null)
            ?? $this->parseJsonTime($beatmapset['submitted_date'] ?? null);
        $rankedDate = $this->parseJsonTime($beatmapset['ranked_date'] ?? null);
        if (
            $status === null
            || ($requirePublicStats && $this->beatmapsetDisplayDate($status, $lastUpdated, $rankedDate) === null)
        ) {
            return null;
        }

        $playCount = get_int($beatmapset['play_count'] ?? null);
        $favouriteCount = get_int($beatmapset['favourite_count'] ?? $beatmapset['favorite_count'] ?? null);
        if ($requirePublicStats && ($playCount === null || $favouriteCount === null)) {
            return null;
        }

        if (is_array($beatmapset['beatmaps'] ?? null)) {
            $beatmaps = array_values(array_filter(array_map(
                fn ($beatmap) => is_array($beatmap) ? $this->beatmap($beatmap) : null,
                $beatmapset['beatmaps'],
            )));
        }

        return [
            ...$base,
            'anime_cover' => get_bool($beatmapset['anime_cover'] ?? null) ?? false,
            'availability' => [
                'download_disabled' => true,
                'more_information' => null,
            ],
            'beatmaps' => $beatmaps,
            'bpm' => get_float($beatmapset['bpm'] ?? null) ?? 0.0,
            'can_be_hyped' => false,
            'covers' => $this->beatmapsetCovers($base['id'], $beatmapset['covers'] ?? null),
            'deleted_at' => null,
            'discussion_locked' => get_bool($beatmapset['discussion_locked'] ?? null) ?? false,
            'favourite_count' => $favouriteCount ?? 0,
            'has_favourited' => false,
            'hype' => null,
            'is_external' => true,
            'is_scoreable' => false,
            'last_updated' => $lastUpdated,
            'legacy_thread_url' => null,
            'nominations_summary' => null,
            'nsfw' => get_bool($beatmapset['nsfw'] ?? null) ?? false,
            'offset' => get_int($beatmapset['offset'] ?? null) ?? 0,
            'play_count' => $playCount ?? 0,
            'preview_url' => $this->officialAssetUrl($beatmapset['preview_url'] ?? null, ''),
            'ranked' => get_int($beatmapset['ranked'] ?? null) ?? 0,
            'ranked_date' => $rankedDate,
            'rating' => get_float($beatmapset['rating'] ?? null) ?? 0.0,
            'source' => get_string($beatmapset['source'] ?? null) ?? '',
            'spotlight' => get_bool($beatmapset['spotlight'] ?? null) ?? false,
            'status' => $status,
            'storyboard' => get_bool($beatmapset['storyboard'] ?? null) ?? false,
            'submitted_date' => $this->parseJsonTime($beatmapset['submitted_date'] ?? null),
            'tags' => get_string($beatmapset['tags'] ?? null) ?? '',
            'track_id' => get_int($beatmapset['track_id'] ?? null),
            'video' => get_bool($beatmapset['video'] ?? null) ?? false,
        ];
    }

    private function normalizeAchievement(array $achievement): ?array
    {
        $id = get_int($achievement['id'] ?? $achievement['achievement_id'] ?? null);
        $localAchievement = app('medals')->byId($id);
        if ($localAchievement !== null) {
            return json_item($localAchievement, 'Achievement');
        }

        $name = get_string($achievement['name'] ?? null);
        $slug = get_string($achievement['slug'] ?? null);
        $grouping = get_string($achievement['grouping'] ?? null);
        $iconUrl = $this->officialAssetUrl($achievement['icon_url'] ?? null);
        $ordering = get_int($achievement['ordering'] ?? null);
        $achievedCount = get_int($achievement['achieved_count'] ?? null);
        $mode = get_string($achievement['mode'] ?? null);

        if (
            $id === null
            || $name === null
            || $slug === null
            || $grouping === null
            || $iconUrl === null
            || $ordering === null
            || $achievedCount === null
            || ($mode !== null && !Beatmap::isModeValid($mode))
        ) {
            return null;
        }

        return [
            'achieved_count' => $achievedCount,
            'achieved_percent' => get_float($achievement['achieved_percent'] ?? null),
            'description' => get_string($achievement['description'] ?? null) ?? '',
            'grouping' => $grouping,
            'icon_url' => $iconUrl,
            'id' => $id,
            'instructions' => get_string($achievement['instructions'] ?? null),
            'mode' => $mode,
            'name' => $name,
            'ordering' => $ordering,
            'slug' => $slug,
        ];
    }

    private function officialAssetUrl(mixed $value, ?string $fallback = null): ?string
    {
        return $this->officialUrl($value, self::OFFICIAL_ASSET_HOSTS) ?? $fallback;
    }

    private function officialPageUrl(mixed $value, string $fallback): string
    {
        return $this->officialUrl($value, self::OFFICIAL_PAGE_HOSTS) ?? $fallback;
    }

    private function officialUrl(mixed $value, array $hosts): ?string
    {
        $url = get_string($value);
        if ($url === null) {
            return null;
        }

        if (starts_with($url, '//')) {
            $url = "https:{$url}";
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        return $scheme === 'https' && in_array($host, $hosts, true) ? $url : null;
    }

    private function normalizeMods(mixed $mods): ?array
    {
        if (!is_array($mods)) {
            return null;
        }

        $ret = [];
        foreach ($mods as $mod) {
            if (is_string($mod)) {
                $ret[] = ['acronym' => $mod];
            } elseif (is_array($mod) && get_string($mod['acronym'] ?? null) !== null) {
                $ret[] = $mod;
            } else {
                return null;
            }
        }

        return $ret;
    }

    private function parseJsonTime(mixed $time): ?string
    {
        $time = get_string($time);
        if ($time === null) {
            return null;
        }

        try {
            return json_time(Carbon::parse($time));
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function recentActivityEvent(User $user, array $event): ?array
    {
        if (($event['type'] ?? null) !== 'achievement') {
            return null;
        }

        $achievement = is_array($event['achievement'] ?? null)
            ? $this->normalizeAchievement($event['achievement'])
            : null;
        $createdAt = $this->parseJsonTime($event['created_at'] ?? $event['createdAt'] ?? null);
        $officialEventId = get_int($event['id'] ?? null);

        if ($achievement === null || $createdAt === null || $officialEventId === null) {
            return null;
        }

        return [
            'achievement' => $achievement,
            'created_at' => $createdAt,
            'id' => -$officialEventId,
            'type' => 'achievement',
            'user' => [
                'url' => route('users.show', ['user' => $user->getKey()]),
                'username' => $user->username,
            ],
        ];
    }

    private function score(M1pposuImportedOfficialScoreSummary $summary, User $user, string $mode): ?array
    {
        $score = $summary->data;
        if (!is_array($score)) {
            return null;
        }

        $officialScoreId = get_int($summary->official_score_id ?? $score['id'] ?? null);
        $accuracy = get_float($score['accuracy'] ?? $summary->accuracy);
        $beatmap = is_array($score['beatmap'] ?? null) ? $this->beatmap($score['beatmap']) : null;
        $beatmapset = is_array($score['beatmapset'] ?? null) ? $this->beatmapset($score['beatmapset']) : null;
        $endedAt = $this->parseJsonTime($score['ended_at'] ?? $score['created_at'] ?? null);
        $isPerfectCombo = get_bool($score['perfect'] ?? $score['is_perfect_combo'] ?? null);
        $maxCombo = get_int($score['max_combo'] ?? null);
        $mods = $this->normalizeMods($score['mods'] ?? null);
        $passed = get_bool($score['passed'] ?? null);
        $pp = get_float($score['pp'] ?? $summary->pp);
        $rank = get_string($score['rank'] ?? null);
        $totalScore = get_int($score['total_score'] ?? $score['score'] ?? $summary->total_score);

        if (
            $officialScoreId === null
            || $accuracy === null
            || $beatmap === null
            || $beatmapset === null
            || $endedAt === null
            || $isPerfectCombo === null
            || $maxCombo === null
            || $mods === null
            || $passed === null
            || $rank === null
            || $totalScore === null
        ) {
            return null;
        }

        return [
            'accuracy' => $accuracy,
            'beatmap' => $beatmap,
            'beatmap_id' => $beatmap['id'],
            'beatmapset' => $beatmapset,
            'best_id' => null,
            'build_id' => null,
            'current_user_attributes' => [],
            'ended_at' => $endedAt,
            'has_replay' => false,
            'id' => -$officialScoreId,
            'is_perfect_combo' => $isPerfectCombo,
            'legacy_perfect' => get_bool($score['perfect'] ?? $score['legacy_perfect'] ?? null) ?? $isPerfectCombo,
            'legacy_score_id' => null,
            'legacy_total_score' => $totalScore,
            'max_combo' => $maxCombo,
            'maximum_statistics' => [],
            'mods' => $mods,
            'passed' => $passed,
            'pp' => $pp,
            'rank' => $rank,
            'replay' => false,
            'ruleset_id' => Beatmap::MODES[$mode],
            'source' => [
                'backend' => 'official_osu',
                'display_name' => '',
                'external_id' => (string) $officialScoreId,
                'source_mode' => Beatmap::MODES[$mode],
            ],
            'started_at' => null,
            'statistics' => is_array($score['statistics'] ?? null) ? $score['statistics'] : [],
            'total_score' => $totalScore,
            'type' => 'm1pposu_official_import',
            'user_id' => $user->getKey(),
        ];
    }

    private function statistics(array $statistics, string $currentMode): array
    {
        $ret = [];

        foreach ($statistics as $mode => $stats) {
            if (!Beatmap::isModeValid($mode) || !is_array($stats) || isset($stats['_error'])) {
                continue;
            }

            $count300 = (int) ($stats['count_300'] ?? 0);
            $count100 = (int) ($stats['count_100'] ?? 0);
            $count50 = (int) ($stats['count_50'] ?? 0);

            $ret[$mode] = [
                'accuracy' => ((float) ($stats['hit_accuracy'] ?? 0)) / 100,
                'count_100' => $count100,
                'count_300' => $count300,
                'count_50' => $count50,
                'count_miss' => (int) ($stats['count_miss'] ?? 0),
                'grade_counts' => [
                    'a' => (int) ($stats['grade_counts']['a'] ?? 0),
                    's' => (int) ($stats['grade_counts']['s'] ?? 0),
                    'sh' => (int) ($stats['grade_counts']['sh'] ?? 0),
                    'ss' => (int) ($stats['grade_counts']['ss'] ?? 0),
                    'ssh' => (int) ($stats['grade_counts']['ssh'] ?? 0),
                ],
                'hit_accuracy' => (float) ($stats['hit_accuracy'] ?? 0),
                'level' => (float) ($stats['level'] ?? 1),
                'maximum_combo' => (int) ($stats['maximum_combo'] ?? 0),
                'play_count' => (int) ($stats['play_count'] ?? 0),
                'play_time' => (int) ($stats['play_time'] ?? 0),
                'ranked_score' => (int) ($stats['ranked_score'] ?? 0),
                'total_hits' => (int) ($stats['total_hits'] ?? ($count300 + $count100 + $count50)),
                'total_score' => (int) ($stats['total_score'] ?? 0),
            ];
        }

        return [
            'current' => $ret[$currentMode] ?? null,
            'modes' => $ret,
        ];
    }
}
