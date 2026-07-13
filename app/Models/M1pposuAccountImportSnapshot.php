<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $connection_id
 * @property int $user_id
 * @property int $official_user_id
 * @property array $data
 * @property-read M1pposuOfficialConnection $connection
 * @property-read User $user
 */
class M1pposuAccountImportSnapshot extends Model
{
    protected $casts = [
        'data' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(M1pposuOfficialConnection::class, 'connection_id');
    }

    public function scoreSummaries(): HasMany
    {
        return $this->hasMany(M1pposuImportedOfficialScoreSummary::class, 'snapshot_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
