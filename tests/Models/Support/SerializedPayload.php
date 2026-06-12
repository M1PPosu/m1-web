<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Models\Support;

class SerializedPayload
{
    public static bool $wasInstantiated = false;

    public function __wakeup(): void
    {
        static::$wasInstantiated = true;
    }
}
