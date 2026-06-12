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
        Schema::create('m1pposu_external_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('backend', 64);
            $table->string('external_user_id', 191);
            $table->string('external_username')->nullable();
            $table->timestamps();

            $table->unique(['backend', 'external_user_id']);
            $table->unique(['backend', 'user_id']);
            $table->index('external_username');
        });
        DB::statement('ALTER TABLE m1pposu_external_users ROW_FORMAT=compressed');
    }

    public function down(): void
    {
        Schema::dropIfExists('m1pposu_external_users');
    }
};
