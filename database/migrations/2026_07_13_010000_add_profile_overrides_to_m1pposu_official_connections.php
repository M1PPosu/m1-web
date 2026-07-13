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
        Schema::table('m1pposu_official_connections', function (Blueprint $table) {
            $table->timestamp('imported_avatar_overridden_at')->nullable()->after('avatar_url');
            $table->timestamp('imported_cover_overridden_at')->nullable()->after('cover_url');
            $table->timestamp('imported_userpage_overridden_at')->nullable()->after('imported_cover_overridden_at');
        });

        $now = now();

        // Existing edit history is incomplete, so preserve any stored native customization.
        DB::table('m1pposu_official_connections as connection')
            ->join('phpbb_users as user', 'user.user_id', '=', 'connection.user_id')
            ->whereNotNull('user.user_avatar')
            ->where('user.user_avatar', '<>', '')
            ->update(['connection.imported_avatar_overridden_at' => $now]);

        DB::table('m1pposu_official_connections as connection')
            ->join('phpbb_users as user', 'user.user_id', '=', 'connection.user_id')
            ->where(function ($query) {
                $query
                    ->whereNotNull('user.custom_cover_filename')
                    ->orWhereNotNull('user.cover_preset_id');
            })
            ->update(['connection.imported_cover_overridden_at' => $now]);

        DB::table('m1pposu_official_connections as connection')
            ->join('phpbb_users as user', 'user.user_id', '=', 'connection.user_id')
            ->whereNotNull('user.userpage_post_id')
            ->where('user.userpage_post_id', '<>', 0)
            ->update(['connection.imported_userpage_overridden_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('m1pposu_official_connections', function (Blueprint $table) {
            $table->dropColumn([
                'imported_avatar_overridden_at',
                'imported_cover_overridden_at',
                'imported_userpage_overridden_at',
            ]);
        });
    }
};
