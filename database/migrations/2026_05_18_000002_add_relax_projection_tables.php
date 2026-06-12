<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddRelaxProjectionTables extends Migration
{
    private const STATS_TABLES = [
        'osu_user_stats_rx',
        'osu_user_stats_taiko_rx',
        'osu_user_stats_fruits_rx',
    ];

    public function up()
    {
        foreach (self::STATS_TABLES as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->integer('user_id')->unsigned();
                $table->integer('count300')->default(0);
                $table->integer('count100')->default(0);
                $table->integer('count50')->default(0);
                $table->integer('countMiss')->default(0);
                $table->bigInteger('accuracy_total')->unsigned();
                $table->bigInteger('accuracy_count')->unsigned();
                $table->float('accuracy');
                $table->mediumInteger('playcount');
                $table->bigInteger('ranked_score');
                $table->bigInteger('total_score');
                $table->mediumInteger('x_rank_count');
                $table->mediumInteger('xh_rank_count')->default(0)->nullable();
                $table->mediumInteger('s_rank_count');
                $table->mediumInteger('sh_rank_count')->default(0)->nullable();
                $table->mediumInteger('a_rank_count');
                $table->mediumInteger('rank');
                $table->float('level')->unsigned();
                $table->mediumInteger('replay_popularity')->unsigned()->default(0);
                $table->mediumInteger('fail_count')->unsigned()->default(0);
                $table->mediumInteger('exit_count')->unsigned()->default(0);
                $table->smallInteger('max_combo')->unsigned()->default(0);
                $table->char('country_acronym', 2)->default('');
                $table->float('rank_score')->unsigned();
                $table->integer('rank_score_index')->unsigned();
                $table->float('accuracy_new')->unsigned();
                $table->timestamp('last_update')->useCurrent()->useCurrentOnUpdate();
                $table->timestamp('last_played')->useCurrent();
                $table->bigInteger('total_seconds_played')->default(0);

                $table->primary('user_id');
                $table->index('ranked_score', 'ranked_score');
                $table->index('playcount', 'playcount');
                $table->index('rank_score', 'rank_score');
                $table->index(['country_acronym', 'rank_score'], 'country_acronym_2');
                $table->index(['country_acronym', 'ranked_score'], 'country_ranked_score');
            });
        }

        Schema::table('m1pposu_external_scores', function (Blueprint $table) {
            $table->unsignedTinyInteger('source_mode')->nullable()->after('external_beatmap_md5');
            $table->index(['backend', 'source_mode'], 'm1pposu_external_scores_source_mode_idx');
        });

        DB::table('m1pposu_external_scores')
            ->join('scores', 'scores.id', '=', 'm1pposu_external_scores.score_id')
            ->whereNull('m1pposu_external_scores.source_mode')
            ->update(['m1pposu_external_scores.source_mode' => DB::raw('scores.ruleset_id')]);
    }

    public function down()
    {
        Schema::table('m1pposu_external_scores', function (Blueprint $table) {
            $table->dropIndex('m1pposu_external_scores_source_mode_idx');
            $table->dropColumn('source_mode');
        });

        foreach (array_reverse(self::STATS_TABLES) as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
}
