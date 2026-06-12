<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Models\Traits;

use App\Libraries\M1pposu\ProjectedScoreVariant;
use App\Libraries\M1pposu\SourceMode;
use App\Libraries\Score\FetchDedupedScores;
use App\Libraries\Search\ScoreSearchParams;
use App\Models\Beatmap;
use App\Models\Solo\Score;
use Illuminate\Database\Eloquent\Collection;

trait UserScoreable
{
    private array $beatmapBestScoreIds = [];

    public function aggregatedScoresBest(string $mode, int $size, ?string $variant = null): array
    {
        if ($variant !== null) {
            return [];
        }

        return (new FetchDedupedScores('beatmap_id', ScoreSearchParams::fromArray([
            'exclude_without_pp' => true,
            'limit' => $size,
            'ruleset_id' => Beatmap::MODES[$mode],
            'sort' => 'pp_desc',
            'user_id' => $this->getKey(),
        ]), "aggregatedScoresBest_{$mode}"))->all(['beatmap_id', 'id']);
    }

    public function beatmapBestScoreIds(string $mode, ?string $variant = null)
    {
        $cacheKey = $variant === null ? $mode : "{$mode}:{$variant}";

        if (
            get_bool(config('m1pposu.private_server.enabled') ?? false)
            && SourceMode::sourceMode($mode, $variant) !== null
        ) {
            return $this->beatmapBestScoreIds[$cacheKey] ??= $this->projectedBeatmapBestScoreIds($mode, $variant);
        }

        // aggregations do not support regular pagination.
        // always fetching 200 to cache; we're not supporting beyond 200, either.
        $ids = cache_remember_mutexed(
            "search-cache:beatmapBestScoresSolo-v2:{$this->getKey()}:{$cacheKey}",
            $GLOBALS['cfg']['osu']['scores']['es_cache_duration'],
            [],
            fn () => array_column($this->aggregatedScoresBest($mode, 200, $variant), 'id'),
            function () {
                // TODO: propagate a more useful message back to the client
                // for now we just mark the exception as handled.
                return true;
            }
        );

        return $this->beatmapBestScoreIds[$cacheKey] ??= $ids;
    }

    private function projectedBeatmapBestScoreIds(string $mode, ?string $variant): array
    {
        $scores = Score::where('user_id', $this->getKey())
            ->where('ruleset_id', Beatmap::MODES[$mode])
            ->where('preserve', true)
            ->where('ranked', true)
            ->where('pp', '>', 0)
            ->whereHas('beatmap.beatmapset')
            ->orderByDesc('pp')
            ->orderByDesc('total_score')
            ->orderBy('id')
            ->limit(1000)
            ->when(
                SourceMode::sourceMode($mode, $variant) !== null,
                fn ($query) => ProjectedScoreVariant::apply($query, $mode, $variant),
            )
            ->get(['scores.id', 'scores.beatmap_id']);

        $ids = [];
        foreach ($scores as $score) {
            if (!isset($ids[$score->beatmap_id])) {
                $ids[$score->beatmap_id] = $score->id;

                if (count($ids) >= 200) {
                    break;
                }
            }
        }

        return array_values($ids);
    }

    public function beatmapBestScores(string $mode, int $limit, int $offset, array $with, ?string $variant = null): Collection
    {
        $ids = array_slice($this->beatmapBestScoreIds($mode, $variant), $offset, $limit);
        $results = Score::whereKey($ids)->orderByField('id', $ids)->default()->get();

        $results->load($with);
        // make outdated index less obvious
        $results = $results->sortByDesc('pp');

        // fill in positions for weighting
        // also preload the user relation
        $position = $offset;
        foreach ($results as $result) {
            $result->weight = pow(0.95, $position);
            $result->setRelation('user', $this);
            $position++;
        }

        return $results;
    }
}
