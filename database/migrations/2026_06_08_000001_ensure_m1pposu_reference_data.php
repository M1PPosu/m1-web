<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

use App\Libraries\M1pposu\ReferenceData;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        app(ReferenceData::class)->ensure();
    }

    public function down(): void
    {
        // Reference rows may be in active use and are intentionally preserved.
    }
};
