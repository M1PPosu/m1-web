<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

final class ProjectedScoreVariant
{
    public static function apply(Builder|Relation $query, string $ruleset, ?string $variant): Builder|Relation
    {
        $sourceMode = SourceMode::sourceMode($ruleset, $variant);
        if ($sourceMode === null) {
            return $query->whereRaw('1 = 0');
        }

        $backend = config('m1pposu.private_server.backend') ?: 'bancho-py-ex';

        if ($variant !== null) {
            return $query->whereExists(fn ($subQuery) => self::mappingQuery($subQuery, $backend)
                ->where('source_mode', $sourceMode));
        }

        return $query->where(function ($variantQuery) use ($backend, $sourceMode) {
            $variantQuery
                ->whereNotExists(fn ($subQuery) => self::mappingQuery($subQuery, $backend))
                ->orWhereExists(fn ($subQuery) => self::mappingQuery($subQuery, $backend)
                    ->where(function ($mappingQuery) use ($sourceMode) {
                        $mappingQuery
                            ->whereNull('source_mode')
                            ->orWhere('source_mode', $sourceMode);
                    }));
        });
    }

    private static function mappingQuery($query, string $backend)
    {
        return $query
            ->select(DB::raw(1))
            ->from('m1pposu_external_scores')
            ->whereColumn('m1pposu_external_scores.score_id', 'scores.id')
            ->where('m1pposu_external_scores.backend', $backend);
    }
}
