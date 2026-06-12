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
        Schema::table('m1pposu_external_users', function (Blueprint $table) {
            $table->unsignedInteger('source_silence_account_history_id')->nullable()->after('external_username');
            $table->index('source_silence_account_history_id', 'm1pposu_external_users_source_silence_idx');
        });
    }

    public function down(): void
    {
        Schema::table('m1pposu_external_users', function (Blueprint $table) {
            $table->dropIndex('m1pposu_external_users_source_silence_idx');
            $table->dropColumn('source_silence_account_history_id');
        });
    }
};
