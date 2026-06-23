<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Models;

use App\Models\Solo\Score;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $backend
 * @property string $external_beatmap_md5
 * @property string $external_score_id
 * @property string $external_user_id
 * @property int $id
 * @property int $score_id
 * @property int|null $source_mode
 */
class M1pposuExternalScore extends Model
{
    protected $table = 'm1pposu_external_scores';

    public function score(): BelongsTo
    {
        return $this->belongsTo(Score::class, 'score_id', 'id');
    }
}
