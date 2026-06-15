<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Http\Controllers;

use App\Enums\Ruleset;
use App\Exceptions\InvariantException;
use App\Libraries\BeatmapDifficultyAttributes;
use App\Libraries\Beatmapset\ChangeBeatmapOwners;
use App\Libraries\M1pposu\ProjectedScoreVariant;
use App\Libraries\Score\BeatmapScores;
use App\Libraries\Score\UserRank;
use App\Libraries\Search\ScoreSearch;
use App\Libraries\Search\ScoreSearchParams;
use App\Models\Beatmap;
use App\Models\Solo\Score as SoloScore;
use App\Models\User;
use App\Transformers\BeatmapTransformer;
use App\Transformers\ScoreTransformer;
use DB;

/**
 * @group Beatmaps
 */
class BeatmapsController extends Controller
{
    const DEFAULT_API_INCLUDES = ['beatmapset.ratings', 'current_user_playcount', 'failtimes', 'max_combo', 'owners'];
    const DEFAULT_SCORE_INCLUDES = ['user', 'user.country', 'user.cover', 'user.team'];

    public function __construct()
    {
        parent::__construct();

        $this->middleware('require-scopes:public');
    }

    private static function assertSupporterOnlyOptions(?User $currentUser, string $type, array $mods): void
    {
        $isSupporter = $currentUser !== null && $currentUser->isSupporter();
        if (in_array($type, ScoreSearchParams::SUPPORTER_TYPES, true) && !$isSupporter) {
            throw new InvariantException(osu_trans('errors.supporter_only'));
        }
        if (!empty($mods) && !is_api_request() && !$isSupporter) {
            throw new InvariantException(osu_trans('errors.supporter_only'));
        }
    }

    // TODO: move this to scores() and remove soloScores(). Probably sometime after October 2025.
    private static function beatmapScores(string $id, ?bool $legacyFormat, ?bool $isLegacy): array
    {
        $beatmap = Beatmap::findOrFail($id);
        if ($beatmap->approved <= 0) {
            return ['scores' => []];
        }

        $params = get_params(request()->all(), null, [
            'limit:int',
            'mode',
            'mods:string[]',
            'type:string',
            'variant:string',
        ], ['null_missing' => true]);

        $rulesetId = static::getRulesetId($params['mode']) ?? $beatmap->playmode;
        $ruleset = Beatmap::modeStr($rulesetId) ?? throw new InvariantException('invalid mode specified');
        $variant = in_array($params['variant'], [null, '', 'all'], true) ? null : $params['variant'];
        if (!Beatmap::isVariantValid($ruleset, $variant)) {
            throw new InvariantException('invalid variant specified');
        }

        $mods = array_values(array_filter($params['mods'] ?? []));
        $type = presence($params['type'], 'global');
        $currentUser = \Auth::user();

        static::assertSupporterOnlyOptions($currentUser, $type, $mods);

        if (get_bool(config('m1pposu.private_server.enabled') ?? false)) {
            return static::privateServerBeatmapScores(
                $beatmap,
                $legacyFormat,
                $ruleset,
                $variant,
                $params['limit'],
                $mods,
                $type,
                $currentUser,
            );
        }

        $esFetch = new BeatmapScores([
            'beatmap_ids' => [$beatmap->getKey()],
            'is_legacy' => $isLegacy,
            'limit' => $params['limit'],
            'mods' => $mods,
            'ruleset_id' => $rulesetId,
            'type' => $type,
            'user' => $currentUser,
        ]);
        $scores = $esFetch->all()->loadMissing(['beatmap', 'user.country', 'user.team']);
        $userScore = $esFetch->userBest();
        $scoreTransformer = new ScoreTransformer($legacyFormat);

        $results = [
            'score_count' => UserRank::getCount($esFetch->baseParams),
            'scores' => json_collection(
                $scores,
                $scoreTransformer,
                static::DEFAULT_SCORE_INCLUDES
            ),
        ];

        if (isset($userScore)) {
            $results['user_score'] = [
                'position' => $esFetch->rank($userScore),
                'score' => json_item($userScore, $scoreTransformer, static::DEFAULT_SCORE_INCLUDES),
            ];
            // TODO: remove this old camelCased json field
            $results['userScore'] = $results['user_score'];
        }

        return $results;
    }

    private static function privateServerBeatmapScores(
        Beatmap $beatmap,
        ?bool $legacyFormat,
        string $ruleset,
        ?string $variant,
        ?int $limit,
        array $mods,
        string $type,
        ?User $currentUser,
    ): array {
        $limit = \Number::clamp($limit ?? 50, 1, $GLOBALS['cfg']['osu']['beatmaps']['max_scores']);
        $baseQuery = static::privateServerBeatmapScoreQuery($beatmap, $ruleset, $variant, $mods, $type, $currentUser);
        $scoreTransformer = new ScoreTransformer($legacyFormat);

        $rankedScores = static::privateServerDedupedScoresQuery(clone $baseQuery, $variant);
        $scoreIds = (clone $rankedScores)
            ->limit($limit)
            ->pluck('id')
            ->all();

        $scores = SoloScore::whereKey($scoreIds)
            ->with(['beatmap', 'user.country', 'user.team'])
            ->get()
            ->sortBy(fn ($score) => array_search($score->getKey(), $scoreIds, true))
            ->values();

        $results = [
            'score_count' => (clone $baseQuery)->distinct('scores.user_id')->count('scores.user_id'),
            'scores' => json_collection(
                $scores,
                $scoreTransformer,
                static::DEFAULT_SCORE_INCLUDES
            ),
        ];

        if ($currentUser !== null) {
            $userScore = (clone $baseQuery)
                ->where('scores.user_id', $currentUser->getKey())
                ->orderByDesc('scores.legacy_total_score')
                ->orderByDesc('scores.pp')
                ->orderBy('scores.id')
                ->first();

            if ($userScore !== null) {
                $results['user_score'] = [
                    'position' => static::privateServerBeatmapScoreRank($rankedScores, $userScore, $variant),
                    'score' => json_item($userScore->loadMissing(['beatmap', 'user.country', 'user.team']), $scoreTransformer, static::DEFAULT_SCORE_INCLUDES),
                ];
                $results['userScore'] = $results['user_score'];
            }
        }

        return $results;
    }

    private static function privateServerBeatmapScoreQuery(Beatmap $beatmap, string $ruleset, ?string $variant, array $mods, string $type, ?User $currentUser)
    {
        $query = SoloScore::query()
            ->where('scores.beatmap_id', $beatmap->getKey())
            ->where('scores.ruleset_id', Beatmap::MODES[$ruleset])
            ->where('scores.passed', true)
            ->where('scores.ranked', true)
            ->where('scores.preserve', true)
            ->visibleUsers();

        ProjectedScoreVariant::apply($query, $ruleset, $variant);
        static::applyPrivateServerScoreType($query, $type, $currentUser);
        static::applyPrivateServerScoreMods($query, $mods);

        return $query;
    }

    private static function privateServerDedupedScoresQuery($query, ?string $variant)
    {
        $metric = $variant === null ? 'legacy_total_score' : 'pp';
        $rankedScoreRows = $query->select([
            'scores.id',
            'scores.user_id',
            'scores.legacy_total_score',
            'scores.pp',
            DB::raw("ROW_NUMBER() OVER (PARTITION BY scores.user_id ORDER BY scores.{$metric} DESC, scores.id ASC) AS user_score_rank"),
        ]);

        return DB::query()
            ->fromSub($rankedScoreRows->toBase(), 'leaderboard_scores')
            ->where('user_score_rank', 1)
            ->orderByDesc($metric)
            ->orderBy('id');
    }

    private static function privateServerBeatmapScoreRank($rankedScores, SoloScore $score, ?string $variant): int
    {
        $metric = $variant === null ? 'legacy_total_score' : 'pp';
        $metricValue = $score->$metric;

        return (clone $rankedScores)
            ->where(function ($query) use ($metric, $metricValue, $score) {
                $query
                    ->where($metric, '>', $metricValue)
                    ->orWhere(function ($scoreQuery) use ($metric, $metricValue, $score) {
                        $scoreQuery
                            ->where($metric, $metricValue)
                            ->where('id', '<', $score->getKey());
                    });
            })
            ->count() + 1;
    }

    private static function applyPrivateServerScoreMods($query, array $mods): void
    {
        $mods = array_values(array_unique(array_filter($mods, fn ($mod) => preg_match('/^[A-Z0-9]+$/', $mod) === 1)));

        if ($mods === []) {
            return;
        }

        if (in_array('NM', $mods, true)) {
            $query->whereRaw("JSON_LENGTH(JSON_EXTRACT(data, '$.mods')) = 0");
            $mods = array_values(array_diff($mods, ['NM']));
        }

        foreach ($mods as $mod) {
            $query->whereRaw("JSON_CONTAINS(JSON_EXTRACT(data, '$.mods'), JSON_OBJECT('acronym', ?))", [$mod]);
        }
    }

    private static function applyPrivateServerScoreType($query, string $type, ?User $currentUser): void
    {
        if ($currentUser === null) {
            if ($type !== 'global') {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        match ($type) {
            'country' => $query->whereHas('user', fn ($userQuery) => $userQuery->where('country_acronym', $currentUser->country_acronym)),
            'friend' => $query->whereIn('scores.user_id', [...$currentUser->friends()->allRelatedIds(), $currentUser->getKey()]),
            'team' => $query->whereIn('scores.user_id', $currentUser->team?->members()->pluck('user_id')->all() ?? []),
            default => null,
        };
    }

    private static function getRulesetId(?string $rulesetName): ?int
    {
        if ($rulesetName === null) {
            return null;
        }

        return Ruleset::tryFromName($rulesetName)?->value
            ?? throw new InvariantException('invalid mode specified');
    }

    /**
     * Get Beatmap Attributes
     *
     * Returns difficulty attributes of beatmap with specific mode and mods combination.
     *
     * ---
     *
     * ### Response format
     *
     * Field      | Type
     * ---------- | ----
     * Attributes | [DifficultyAttributes](#beatmapdifficultyattributes)
     *
     * @urlParam beatmap integer required Beatmap id. Example: 2
     * @bodyParam mods integer|string[]|Mod[] Mod combination. Can be either a bitset of mods, array of mod acronyms, or array of mods. Defaults to no mods. Example: 1
     * @bodyParam ruleset Ruleset Ruleset of the difficulty attributes. Only valid if it's the beatmap ruleset or the beatmap can be converted to the specified ruleset. Defaults to ruleset of the specified beatmap. Example: osu
     * @bodyParam ruleset_id integer The same as `ruleset` but in integer form. No-example
     *
     * @response {
     *   "attributes": {
     *       "max_combo": 100,
     *       ...
     *   }
     * }
     */
    public function attributes($id)
    {
        $beatmap = Beatmap::whereHas('beatmapset')->findOrFail($id);

        $params = get_params(request()->all(), null, [
            'mods:any',
            'ruleset:string',
            'ruleset_id:int',
        ], ['null_missing' => true]);

        $rulesetId = $params['ruleset_id'];
        abort_if(
            $rulesetId !== null && Beatmap::modeStr($rulesetId) === null,
            422,
            'invalid ruleset_id specified'
        );

        if ($rulesetId === null && $params['ruleset'] !== null) {
            $rulesetId = Beatmap::modeInt($params['ruleset']);
            abort_if($rulesetId === null, 422, 'invalid ruleset specified');
        }

        if ($rulesetId === null) {
            $rulesetId = $beatmap->playmode;
        } else {
            abort_if(
                !$beatmap->canBeConvertedTo($rulesetId),
                422,
                "specified beatmap can't be converted to the specified ruleset"
            );
        }

        if (isset($params['mods'])) {
            if (is_numeric($params['mods'])) {
                $params['mods'] = app('mods')->bitsetToIds((int) $params['mods']);
            }
            if (is_array($params['mods'])) {
                if (count($params['mods']) > 0 && is_string(array_first($params['mods']))) {
                    $params['mods'] = array_map(fn ($m) => ['acronym' => $m], $params['mods']);
                }

                $mods = app('mods')->parseInputArray($rulesetId, $params['mods']);
            } else {
                abort(422, 'invalid mods specified');
            }
        }

        return ['attributes' => BeatmapDifficultyAttributes::get($beatmap->getKey(), $rulesetId, $mods ?? [])];
    }

    /**
     * Get Beatmaps
     *
     * Returns a list of beatmaps.
     *
     * ---
     *
     * ### Response format
     *
     * Field    | Type                                  | Description
     * -------- | ------------------------------------- | -----------
     * beatmaps | [BeatmapExtended](#beatmapextended)[] | Includes `beatmapset` (with `ratings`), `failtimes`, `max_combo`, and `owners`.
     *
     * @queryParam ids[] integer Beatmap IDs to be returned. Specify once for each beatmap ID requested. Up to 50 beatmaps can be requested at once. Example: 1
     *
     * @response {
     *   "beatmaps": [
     *     {
     *       "id": 1,
     *       // Other Beatmap attributes...
     *     }
     *   ]
     * }
     */
    public function index()
    {
        $ids = array_slice(get_arr(request('ids'), 'get_int') ?? [], 0, 50);

        if (count($ids) > 0) {
            $beatmaps = Beatmap
                ::whereIn('beatmap_id', $ids)
                ->whereHas('beatmapset')
                ->withUserPlaycount(\Auth::id())
                ->with([
                    'beatmapOwners.user',
                    'beatmapset',
                    'beatmapset.userRatings' => fn ($q) => $q->select('beatmapset_id', 'rating'),
                    'failtimes',
                ])->withMaxCombo()
                ->orderBy('beatmap_id')
                ->get();
        }

        return [
            'beatmaps' => json_collection($beatmaps ?? [], new BeatmapTransformer(), static::DEFAULT_API_INCLUDES),
        ];
    }

    /**
     * Lookup Beatmap
     *
     * Returns beatmap.
     *
     * ---
     *
     * ### Response format
     *
     * See [Get Beatmap](#get-beatmap)
     *
     * @queryParam checksum A beatmap checksum.
     * @queryParam filename A filename to lookup.
     * @queryParam id A beatmap ID to lookup.
     *
     * @response "See Beatmap object section"
     */
    public function lookup()
    {
        static $keyMap = [
            'checksum' => 'checksum',
            'filename' => 'filename',
            'id' => 'beatmap_id',
        ];

        $params = get_params(request()->all(), null, ['checksum:string', 'filename:string', 'id:int']);

        foreach ($params as $key => $value) {
            $beatmap = Beatmap
                ::whereHas('beatmapset')
                ->withUserPlaycount(\Auth::id())
                ->firstWhere($keyMap[$key], $value);

            if ($beatmap !== null) {
                break;
            }
        }

        if (!isset($beatmap)) {
            abort(404);
        }

        return json_item($beatmap, new BeatmapTransformer(), static::DEFAULT_API_INCLUDES);
    }

    /**
     * Get Beatmap
     *
     * Gets beatmap data for the specified beatmap ID.
     *
     * ---
     *
     * ### Response format
     *
     * Returns [BeatmapExtended](#beatmapextended) object.
     * Following attributes are included in the response object when applicable,
     *
     * Attribute  | Notes
     * ---------- | -----
     * beatmapset | Includes ratings property.
     * failtimes  | |
     * max_combo  | |
     *
     * @urlParam beatmap integer required The ID of the beatmap.
     *
     * @response "See Beatmap object section."
     */
    public function show($id)
    {
        $beatmap = Beatmap
            ::whereHas('beatmapset')
            ->withUserPlaycount(\Auth::id())
            ->findOrFail($id);

        if (is_api_request()) {
            return json_item($beatmap, new BeatmapTransformer(), static::DEFAULT_API_INCLUDES);
        }

        $beatmapset = $beatmap->beatmapset;

        if ($beatmapset === null) {
            abort(404);
        }

        $beatmapRuleset = $beatmap->mode;
        if ($beatmapRuleset === 'osu') {
            $params = get_params(request()->all(), null, [
                'm:int', // legacy parameter
                'mode', // legacy parameter
                'ruleset',
            ], ['null_missing' => true]);

            $ruleset = (
                Ruleset::tryFromName($params['ruleset'])
                ?? Ruleset::tryFromName($params['mode'])
                ?? Ruleset::tryFrom($params['m'] ?? Ruleset::NULL)
            )?->legacyName();
        }

        $ruleset ??= $beatmapRuleset;

        return ujs_redirect(route('beatmapsets.show', ['beatmapset' => $beatmapset->getKey()]).'#'.$ruleset.'/'.$beatmap->getKey());
    }

    /**
     * Get Beatmap scores
     *
     * Returns the top scores for a beatmap. Depending on user preferences, this may only show legacy scores.
     *
     * ---
     *
     * ### Response Format
     *
     * Returns [BeatmapScores](#beatmapscores). `Score` object inside includes `user` and the included `user` includes `country` and `cover`.
     *
     * @urlParam beatmap integer required Id of the [Beatmap](#beatmap).
     *
     * @queryParam legacy_only integer Whether or not to exclude lazer scores. Defaults to 0. Example: 0
     * @queryParam mode The [Ruleset](#ruleset) to get scores for.
     * @queryParam mods An array of matching Mods, or none // TODO.
     * @queryParam type Beatmap score ranking type // TODO.
     */
    public function scores($id)
    {
        return static::beatmapScores(
            $id,
            null,
            // TODO: change to imported name after merge with other PRs
            \App\Libraries\Search\ScoreSearchParams::showLegacyForUser(\Auth::user()),
        );
    }

    /**
     * Get Beatmap scores (non-legacy)
     *
     * Returns the top scores for a beatmap.
     *
     * This endpoint is deprecated. Use [Get Beatmap scores](#get-beatmap-scores) with appropriate api version header instead.
     *
     * ---
     *
     * ### Response Format
     *
     * Returns [BeatmapScores](#beatmapscores). `Score` object inside includes `user` and the included `user` includes `country` and `cover`.
     *
     * @urlParam beatmap integer required Id of the [Beatmap](#beatmap).
     *
     * @queryParam mode The [Ruleset](#ruleset) to get scores for.
     * @queryParam mods An array of matching Mods, or none // TODO.
     * @queryParam type Beatmap score ranking type // TODO.
     */
    public function soloScores($id)
    {
        return static::beatmapScores($id, false, null);
    }

    public function updateOwner($id)
    {
        $beatmap = Beatmap::findOrFail($id);
        $newUserIds = get_arr(request('user_ids'), 'get_int');

        (new ChangeBeatmapOwners($beatmap, $newUserIds ?? [], \Auth::user()))->handle();

        return $beatmap->beatmapset->defaultDiscussionJson();
    }

    /**
     * Get a User Beatmap score
     *
     * Return a [User](#user)'s score on a Beatmap
     *
     * ---
     *
     * ### Response Format
     *
     * Returns [BeatmapUserScore](#beatmapuserscore)
     *
     * The position returned depends on the requested mode and mods.
     *
     * @urlParam beatmap integer required Id of the [Beatmap](#beatmap).
     * @urlParam user integer required Id of the [User](#user).
     *
     * @queryParam legacy_only integer Whether or not to exclude lazer scores. Defaults to 0. Example: 0
     * @queryParam mode The [Ruleset](#ruleset) to get scores for.
     * @queryParam mods An array of matching Mods, or none // TODO.
     */
    public function userScore($beatmapId, $userId)
    {
        $beatmap = Beatmap::scoreable()->findOrFail($beatmapId);

        $params = get_params(request()->all(), null, [
            'mode:string',
            'mods:string[]',
        ]);

        $rulesetId = static::getRulesetId($params['mode'] ?? null) ?? $beatmap->playmode;
        $mods = array_values(array_filter($params['mods'] ?? []));

        $baseParams = ScoreSearchParams::fromArray([
            'beatmap_ids' => [$beatmap->getKey()],
            'is_legacy' => ScoreSearchParams::showLegacyForUser(\Auth::user()),
            'limit' => 1,
            'mods' => $mods,
            'ruleset_id' => $rulesetId,
            'sort' => 'score_desc',
            'user_id' => (int) $userId,
        ]);
        $score = (new ScoreSearch($baseParams))->records()->first();
        abort_if($score === null, 404);

        $rankParams = clone $baseParams;
        $rankParams->beforeScore = $score;
        $rankParams->userId = null;
        $rank = UserRank::getRank($rankParams);

        return [
            'position' => $rank,
            'score' => json_item(
                $score,
                new ScoreTransformer(),
                ['beatmap.owners', ...static::DEFAULT_SCORE_INCLUDES]
            ),
        ];
    }

    /**
     * Get a User Beatmap scores
     *
     * Return a [User](#user)'s scores on a Beatmap
     *
     * ---
     *
     * ### Response Format
     *
     * Field  | Type
     * ------ | ----
     * scores | [Score](#score)[]
     *
     * @urlParam beatmap integer required Id of the [Beatmap](#beatmap).
     * @urlParam user integer required Id of the [User](#user).
     *
     * @queryParam legacy_only integer Whether or not to exclude lazer scores. Defaults to 0. Example: 0
     * @queryParam mode (deprecated) The [Ruleset](#ruleset) to get scores for. Defaults to beatmap ruleset. No-example
     * @queryParam ruleset The [Ruleset](#ruleset) to get scores for. Defaults to beatmap ruleset. Example: osu
     */
    public function userScoreAll($beatmapId, $userId)
    {
        $beatmap = Beatmap::scoreable()->findOrFail($beatmapId);
        $ruleset = presence(get_string(request('ruleset'))) ?? presence(get_string(request('mode')));
        if ($ruleset !== null) {
            $rulesetId = Beatmap::modeInt($ruleset) ?? abort(404, 'unknown ruleset name');
        }
        $params = ScoreSearchParams::fromArray([
            'beatmap_ids' => [$beatmap->getKey()],
            'is_legacy' => ScoreSearchParams::showLegacyForUser(\Auth::user()),
            'ruleset_id' => $rulesetId ?? $beatmap->playmode,
            'sort' => 'score_desc',
            'user_id' => (int) $userId,
        ]);
        $scores = (new ScoreSearch($params))->records();

        return [
            'scores' => json_collection($scores, new ScoreTransformer()),
        ];
    }
}
