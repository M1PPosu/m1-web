<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m1pposu_score_leaders', function (Blueprint $table) {
            $table->unsignedMediumInteger('beatmap_id');
            $table->unsignedTinyInteger('source_mode');
            $table->unsignedBigInteger('score_id');
            $table->unsignedInteger('user_id');
            $table->timestamps();

            $table->primary(['beatmap_id', 'source_mode']);
            $table->unique('score_id');
            $table->index(['user_id', 'source_mode']);
        });

        Schema::create('m1pposu_external_events', function (Blueprint $table) {
            $table->id();
            $table->string('backend', 64);
            $table->string('external_event_key', 191);
            $table->unsignedInteger('event_id');
            $table->timestamps();

            $table->unique(['backend', 'external_event_key']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m1pposu_external_events');
        Schema::dropIfExists('m1pposu_score_leaders');
    }
};
