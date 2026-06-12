<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

class SourcePrivileges
{
    public const UNRESTRICTED = 1 << 0;

    public static function isRestricted($priv): ?bool
    {
        if (!is_numeric($priv)) {
            return null;
        }

        return (((int) $priv) & static::UNRESTRICTED) !== static::UNRESTRICTED;
    }
}
