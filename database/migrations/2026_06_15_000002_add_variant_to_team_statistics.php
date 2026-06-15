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
        Schema::table('team_statistics', function (Blueprint $table) {
            $table->string('variant', 8)->default('')->after('ruleset_id');
            $table->dropPrimary();
            $table->dropIndex(['ruleset_id', 'performance']);
            $table->dropIndex(['ruleset_id', 'ranked_score']);
            $table->primary(['team_id', 'ruleset_id', 'variant']);
            $table->index(['ruleset_id', 'variant', 'performance']);
            $table->index(['ruleset_id', 'variant', 'ranked_score']);
        });
    }

    public function down(): void
    {
        DB::table('team_statistics')->where('variant', '<>', '')->delete();

        Schema::table('team_statistics', function (Blueprint $table) {
            $table->dropPrimary();
            $table->dropIndex(['ruleset_id', 'variant', 'performance']);
            $table->dropIndex(['ruleset_id', 'variant', 'ranked_score']);
            $table->dropColumn('variant');
            $table->primary(['team_id', 'ruleset_id']);
            $table->index(['ruleset_id', 'performance']);
            $table->index(['ruleset_id', 'ranked_score']);
        });
    }
};
