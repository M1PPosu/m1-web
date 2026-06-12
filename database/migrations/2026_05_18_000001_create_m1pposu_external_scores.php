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
        Schema::create('m1pposu_external_scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('score_id')->unique();
            $table->string('backend', 64);
            $table->string('external_score_id', 64);
            $table->string('external_user_id', 64);
            $table->char('external_beatmap_md5', 32);
            $table->timestamps();

            $table->unique(['backend', 'external_score_id']);
            $table->index(['backend', 'external_user_id']);
            $table->index(['backend', 'external_beatmap_md5']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m1pposu_external_scores');
    }
};
