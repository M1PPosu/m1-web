<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $official_user_id
 * @property string $username
 * @property string|null $avatar_url
 * @property string|null $cover_url
 * @property bool $restricted_at_connection
 * @property string|null $refresh_token
 * @property array|null $token_metadata
 * @property \Carbon\Carbon $connected_at
 * @property-read User $user
 */
class M1pposuOfficialConnection extends Model
{
    protected $casts = [
        'connected_at' => 'datetime',
        'restricted_at_connection' => 'boolean',
        'refresh_token' => 'encrypted',
        'token_metadata' => 'array',
    ];

    public function importRequests(): HasMany
    {
        return $this->hasMany(M1pposuAccountImportRequest::class, 'connection_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(M1pposuAccountImportSnapshot::class, 'connection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function officialUrl(): string
    {
        return "https://osu.ppy.sh/users/{$this->official_user_id}";
    }
}
