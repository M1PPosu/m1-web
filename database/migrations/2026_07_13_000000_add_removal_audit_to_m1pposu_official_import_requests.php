<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('m1pposu_account_import_requests', function (Blueprint $table) {
            $table->unsignedInteger('removed_by')->nullable()->after('failure_reason');
            $table->timestamp('removed_at')->nullable()->after('removed_by');
            $table->text('remove_reason')->nullable()->after('removed_at');
            $table->boolean('restricted_before_removal')->default(false)->after('remove_reason');
            $table->unsignedInteger('removal_account_history_id')->nullable()->after('restricted_before_removal');
            $table->unsignedInteger('restored_by')->nullable()->after('removal_account_history_id');
            $table->timestamp('restored_at')->nullable()->after('restored_by');
            $table->text('restore_reason')->nullable()->after('restored_at');
            $table->unsignedInteger('restore_account_history_id')->nullable()->after('restore_reason');

            $table->index(['status', 'applied_at'], 'm1pposu_import_status_applied_idx');

            $table->foreign('removed_by', 'm1pposu_import_removed_by_fk')
                ->references('user_id')
                ->on('phpbb_users');
            $table->foreign('restored_by', 'm1pposu_import_restored_by_fk')
                ->references('user_id')
                ->on('phpbb_users');
            $table->foreign('removal_account_history_id', 'm1pposu_import_removal_history_fk')
                ->references('ban_id')
                ->on('osu_user_banhistory');
            $table->foreign('restore_account_history_id', 'm1pposu_import_restore_history_fk')
                ->references('ban_id')
                ->on('osu_user_banhistory');
        });
    }

    public function down(): void
    {
        Schema::table('m1pposu_account_import_requests', function (Blueprint $table) {
            $table->dropForeign('m1pposu_import_removed_by_fk');
            $table->dropForeign('m1pposu_import_restored_by_fk');
            $table->dropForeign('m1pposu_import_removal_history_fk');
            $table->dropForeign('m1pposu_import_restore_history_fk');
            $table->dropIndex('m1pposu_import_status_applied_idx');
            $table->dropColumn([
                'removed_by',
                'removed_at',
                'remove_reason',
                'restricted_before_removal',
                'removal_account_history_id',
                'restored_by',
                'restored_at',
                'restore_reason',
                'restore_account_history_id',
            ]);
        });
    }
};
