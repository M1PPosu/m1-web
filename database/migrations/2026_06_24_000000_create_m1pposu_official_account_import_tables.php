<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m1pposu_official_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('official_user_id');
            $table->string('username', 50);
            $table->string('avatar_url', 2048)->nullable();
            $table->string('cover_url', 2048)->nullable();
            $table->boolean('restricted_at_connection')->default(false);
            $table->text('refresh_token')->nullable();
            $table->json('token_metadata')->nullable();
            $table->timestamp('connected_at');
            $table->timestamps();

            $table->unique('user_id');
            $table->unique('official_user_id');
            $table->foreign('user_id')->references('user_id')->on('phpbb_users')->cascadeOnDelete();
        });

        Schema::create('m1pposu_account_import_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('m1pposu_official_connections')->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('official_user_id');
            $table->json('data');
            $table->timestamps();

            $table->index(['user_id', 'official_user_id']);
            $table->foreign('user_id')->references('user_id')->on('phpbb_users')->cascadeOnDelete();
        });

        Schema::create('m1pposu_account_import_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('m1pposu_official_connections')->cascadeOnDelete();
            $table->foreignId('snapshot_id')->constrained('m1pposu_account_import_snapshots')->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('official_user_id');
            $table->string('status', 20)->default('pending');
            $table->boolean('restricted_at_request')->default(false);
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'official_user_id', 'status'], 'm1pposu_import_request_lookup');
            $table->foreign('user_id')->references('user_id')->on('phpbb_users')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('user_id')->on('phpbb_users')->nullOnDelete();
        });

        Schema::create('m1pposu_imported_official_score_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('m1pposu_account_import_snapshots')->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('official_user_id');
            $table->string('kind', 20);
            $table->string('mode', 20);
            $table->unsignedBigInteger('official_score_id')->nullable();
            $table->unsignedInteger('beatmap_id')->nullable();
            $table->float('pp')->nullable();
            $table->float('accuracy')->nullable();
            $table->unsignedBigInteger('total_score')->nullable();
            $table->json('data');
            $table->timestamps();

            $table->index(['user_id', 'kind', 'mode'], 'm1pposu_imported_score_user_kind_mode');
            $table->index(['official_user_id', 'official_score_id'], 'm1pposu_official_score_summary_score');
            $table->foreign('user_id')->references('user_id')->on('phpbb_users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m1pposu_imported_official_score_summaries');
        Schema::dropIfExists('m1pposu_account_import_requests');
        Schema::dropIfExists('m1pposu_account_import_snapshots');
        Schema::dropIfExists('m1pposu_official_connections');
    }
};
