<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m1pposu_external_teams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('backend', 64);
            $table->string('external_team_id', 64);
            $table->string('external_name', 255)->nullable();
            $table->string('external_short_name', 64)->nullable();
            $table->timestampsTz();

            $table->unique(['backend', 'external_team_id'], 'm1pposu_external_teams_backend_external_unique');
            $table->unique('team_id', 'm1pposu_external_teams_team_unique');
            $table->index(['backend', 'external_name'], 'm1pposu_external_teams_backend_name_idx');
        });

        DB::statement('ALTER TABLE m1pposu_external_teams ROW_FORMAT=compressed');
    }

    public function down(): void
    {
        Schema::dropIfExists('m1pposu_external_teams');
    }
};
