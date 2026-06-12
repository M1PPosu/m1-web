<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Models;

/**
 * @property string $backend
 * @property string $external_user_id
 * @property string|null $external_username
 * @property int $id
 * @property int|null $source_silence_account_history_id
 * @property User $user
 * @property int $user_id
 */
class M1pposuExternalUser extends Model
{
    protected $table = 'm1pposu_external_users';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
