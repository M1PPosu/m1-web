<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Imported official score metadata kept separate from native score tables.
 */
class M1pposuImportedOfficialScoreSummary extends Model
{
    protected $casts = [
        'data' => 'array',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(M1pposuAccountImportSnapshot::class, 'snapshot_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
