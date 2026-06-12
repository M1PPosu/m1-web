<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Http\Controllers\Ranking;

use App\Http\Controllers\Controller;
use App\Libraries\M1pposu\ProjectedScoreVariant;
use App\Libraries\M1pposu\SourceMode;
use App\Libraries\Score\TopPlays;
use App\Models\Beatmap;
use App\Models\Beatmapset;
use App\Models\Solo\Score;
use App\Transformers\ScoreTransformer;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TopPlaysController extends Controller
{
    const int PAGE_SIZE = 100;
    const int PAGES = 10; // top 1000

    public function show(?string $rulesetName = null): Response
    {
        if ($rulesetName === null) {
            return ujs_redirect(route('rankings.top-plays', ['mode' => default_mode()]));
        }

        $rulesetId = Beatmap::MODES[$rulesetName] ?? abort(422, 'invalid ruleset parameter');
        $variant = $this->variantFromRequest($rulesetName);
        $page = \Number::clamp(get_int(\Request::input('page')) ?? 1, 1, static::PAGES);

        if (get_bool(config('m1pposu.private_server.enabled') ?? false)) {
            [$scores, $scoresJson, $lastUpdate] = $this->projectedScores($rulesetName, $variant, $page);

            return ext_view('rankings.top_plays', compact(
                'lastUpdate',
                'rulesetName',
                'scores',
                'scoresJson',
                'variant',
            ));
        }

        $data = new TopPlays($rulesetId)->get();

        if (isset($data)) {
            $lastUpdate = parse_time_to_carbon($data['time']);

            $scores = Score
                    ::whereIntegerInRaw('id', array_slice($data['ids'], 0, (int) ($page * static::PAGE_SIZE * 1.5)))
                    ->with('user.team')
                    ->with('beatmap.beatmapset')
                    ->whereHas('user', fn ($q) => $q->default())
                    ->whereHas('beatmap.beatmapset')
                    ->orderByDesc('pp')
                    ->paginate(static::PAGE_SIZE, ['*'], 'page', $page, static::PAGE_SIZE * static::PAGES);

            $scoresJson = json_collection(
                $scores,
                new ScoreTransformer(),
                ['beatmap', 'beatmapset', 'user.country', 'user.team'],
            );
        } else {
            $lastUpdate = null;
            $scores = null;
            $scoresJson = null;
        }

        return ext_view('rankings.top_plays', compact(
            'lastUpdate',
            'rulesetName',
            'scores',
            'scoresJson',
            'variant',
        ));
    }

    private function projectedScores(string $rulesetName, ?string $variant, int $page): array
    {
        $rankedBeatmapStates = $this->rankedBeatmapStates();

        $eligibleScores = Score::query()
            ->select([
                'scores.id',
                'scores.user_id',
                'scores.beatmap_id',
                'scores.pp',
                'scores.total_score',
            ])
            ->selectRaw('ROW_NUMBER() OVER (
                PARTITION BY scores.user_id, scores.beatmap_id
                ORDER BY scores.pp DESC, scores.total_score DESC, scores.id ASC
            ) AS m1pposu_score_rank')
            ->forRuleset($rulesetName)
            ->where('preserve', true)
            ->where('ranked', true)
            ->where('pp', '>', 0)
            ->whereHas('user', fn ($query) => $query->default())
            ->whereHas('beatmap', fn ($query) => $query->whereIn('approved', $rankedBeatmapStates))
            ->whereHas('beatmap.beatmapset', fn ($query) => $query->whereIn('approved', $rankedBeatmapStates));

        ProjectedScoreVariant::apply($eligibleScores, $rulesetName, $variant);

        $dedupedScores = DB::query()
            ->fromSub($eligibleScores->toBase(), 'm1pposu_top_plays')
            ->where('m1pposu_score_rank', 1);

        $total = min(static::PAGE_SIZE * static::PAGES, (clone $dedupedScores)->count());

        $ids = (clone $dedupedScores)
            ->orderByDesc('pp')
            ->orderByDesc('total_score')
            ->orderBy('id')
            ->limit(static::PAGE_SIZE)
            ->offset(static::PAGE_SIZE * ($page - 1))
            ->pluck('id')
            ->all();

        $items = Score::whereKey($ids)
            ->with('user.country', 'user.team', 'beatmap.beatmapset')
            ->orderByField('id', $ids)
            ->get();

        $scores = new LengthAwarePaginator(
            $items,
            $total,
            static::PAGE_SIZE,
            $page,
            [
                'path' => route('rankings.top-plays', ['mode' => $rulesetName, 'variant' => $variant]),
            ],
        );

        $scoresJson = json_collection(
            $scores,
            new ScoreTransformer(),
            ['beatmap', 'beatmapset', 'user.country', 'user.team'],
        );

        return [$scores, $scoresJson, now()];
    }

    private function variantFromRequest(string $rulesetName): ?string
    {
        $variant = get_string(\Request::input('variant'));
        if ($variant === 'all') {
            $variant = null;
        }

        if (!Beatmap::isVariantValid($rulesetName, $variant) || SourceMode::sourceMode($rulesetName, $variant) === null) {
            abort(422, 'invalid variant parameter');
        }

        return $variant;
    }

    private function rankedBeatmapStates(): array
    {
        return [
            Beatmapset::STATES['ranked'],
            Beatmapset::STATES['approved'],
        ];
    }
}
