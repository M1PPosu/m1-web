<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\M1pposuExternalUser;

class ExternalUsersController extends Controller
{
    public function show(string $externalUserId)
    {
        $userId = M1pposuExternalUser::query()
            ->where('backend', config('m1pposu.private_server.backend'))
            ->where('external_user_id', $externalUserId)
            ->value('user_id');

        abort_if($userId === null, 404);

        return redirect()->route('users.show', ['user' => $userId]);
    }
}
