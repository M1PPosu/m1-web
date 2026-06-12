<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireM1pposuFeature
{
    public function handle(Request $request, Closure $next, string $feature)
    {
        abort_unless(get_bool(config("m1pposu.features.{$feature}") ?? false), 404);

        return $next($request);
    }
}
