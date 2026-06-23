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
        $schema = Schema::connection('mysql-chat');

        if ($schema->getColumnType('messages', 'content') !== 'text') {
            DB::connection('mysql-chat')->statement(
                'ALTER TABLE messages MODIFY content TEXT NOT NULL'
            );
        }

        if (!$schema->hasTable('m1pposu_live_chat_events')) {
            $schema->create('m1pposu_live_chat_events', function (Blueprint $table) {
                $table->uuid('event_id')->primary();
                $table->string('action', 16);
                $table->unsignedInteger('message_id')->nullable();
                $table->timestamp('created_at');

                $table->index('message_id');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysql-chat')->dropIfExists('m1pposu_live_chat_events');
        DB::connection('mysql-chat')->statement(
            "ALTER TABLE messages MODIFY content VARCHAR(1024) NOT NULL DEFAULT ''"
        );
    }
};
