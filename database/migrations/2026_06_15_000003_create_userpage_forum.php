<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $forumId = (int) config('osu.user.user_page_forum_id');
        if (DB::table('phpbb_forums')->where('forum_id', $forumId)->exists()) {
            return;
        }

        DB::table('phpbb_forums')->insert([
            'forum_id' => $forumId,
            'parent_id' => 0,
            'left_id' => 1,
            'right_id' => 2,
            'forum_parents' => '',
            'forum_name' => 'User Pages',
            'forum_desc' => 'Private storage for profile userpages.',
            'forum_desc_bitfield' => '',
            'forum_desc_options' => 7,
            'forum_desc_uid' => '',
            'forum_link' => '',
            'forum_password' => '',
            'forum_style' => 0,
            'forum_image' => '',
            'forum_rules' => '',
            'forum_rules_link' => '',
            'forum_rules_bitfield' => '',
            'forum_rules_options' => 7,
            'forum_rules_uid' => '',
            'forum_topics_per_page' => 0,
            'forum_type' => 1,
            'forum_status' => 0,
            'forum_posts' => 0,
            'forum_topics' => 0,
            'forum_topics_real' => 0,
            'forum_last_post_id' => 0,
            'forum_last_poster_id' => 0,
            'forum_last_post_subject' => '',
            'forum_last_post_time' => 0,
            'forum_last_poster_name' => '',
            'forum_last_poster_colour' => '',
            'forum_flags' => 32,
            'display_on_index' => 0,
            'enable_indexing' => 0,
            'enable_icons' => 0,
            'enable_prune' => 0,
            'enable_sigs' => 0,
            'prune_next' => 0,
            'prune_days' => 0,
            'prune_viewed' => 0,
            'prune_freq' => 0,
            'moderator_groups' => null,
            'allow_topic_covers' => 0,
        ]);
    }

    public function down(): void
    {
    }
};
