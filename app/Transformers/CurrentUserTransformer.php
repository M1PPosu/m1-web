<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Transformers;

use App\Libraries\M1pposu\OfficialProfileImport;
use App\Models\User;

class CurrentUserTransformer extends UserTransformer
{
    public function __construct()
    {
        $this->defaultIncludes = [
            ...$this->defaultIncludes,
            'blocks',
            'follow_user_mapping',
            'friends',
            'groups',
            'is_admin',
            'team',
            'unread_pm_count',
            'user_preferences',
        ];
    }

    public function transform(User $user)
    {
        $ret = parent::transform($user);
        $officialImport = app(OfficialProfileImport::class)->forUser($user, $user->playmode);

        if (present($officialImport['profile']['avatar_url'] ?? null)) {
            $ret['avatar_url'] = $officialImport['profile']['avatar_url'];
        }

        return $ret;
    }
}
