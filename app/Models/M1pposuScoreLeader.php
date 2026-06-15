<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Models;

use App\Libraries\M1pposu\SourceMode;
use App\Models\Solo\Score;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class M1pposuScoreLeader extends Model
{
    protected $primaryKey = 'score_id';
    protected $table = 'm1pposu_score_leaders';

    public function scopeRulesetVariant(Builder $query, string $ruleset, ?string $variant): Builder
    {
        $sourceMode = SourceMode::sourceMode($ruleset, $variant);

        return $sourceMode === null
            ? $query->whereRaw('1 = 0')
            : $query->where('source_mode', $sourceMode);
    }

    public function beatmap(): BelongsTo
    {
        return $this->belongsTo(Beatmap::class, 'beatmap_id');
    }

    public function score(): BelongsTo
    {
        return $this->belongsTo(Score::class, 'score_id', 'id');
    }
}
