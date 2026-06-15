<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Exceptions\ModelNotSavedException;
use App\Libraries\BBCodeForDB;
use App\Models\Forum\Post;
use App\Models\M1pposuExternalUser;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class UserpageBridge
{
    private const CONNECTION = 'm1pposu-private-server-source';
    private const LEGACY_SOURCE_MAX_LENGTH = 2048;
    private const SOURCE_MAX_LENGTH = 10000;

    public function write(User $user, string $body): void
    {
        if (mb_strlen($body) > self::SOURCE_MAX_LENGTH) {
            throw new ModelNotSavedException('Userpage content must be 10000 characters or fewer for the game server.');
        }

        $backend = (string) (config('m1pposu.private_server.backend') ?: 'bancho-py-ex');
        $externalUserId = M1pposuExternalUser::where('backend', $backend)
            ->where('user_id', $user->getKey())
            ->value('external_user_id');
        if ($externalUserId === null) {
            throw new RuntimeException('The user has no private-server account mapping.');
        }

        $database = config('m1pposu.private_server.database');
        Config::set('database.connections.'.self::CONNECTION, [
            ...config('database.connections.mysql'),
            'host' => $database['host'],
            'port' => $database['port'],
            'database' => $database['database'],
            'username' => $database['username'],
            'password' => $database['password'],
        ]);
        DB::purge(self::CONNECTION);

        $bbcode = new BBCodeForDB($body);
        $post = new Post();
        $post->setRawAttributes([
            'post_text' => $bbcode->generate(),
            'bbcode_uid' => $bbcode->uid,
        ]);
        $source = DB::connection(self::CONNECTION);

        $source->transaction(function () use ($body, $externalUserId, $post, $source) {
            $sourceUser = $source
                ->table('users')
                ->where('id', $externalUserId)
                ->lockForUpdate()
                ->first(['id']);
            if ($sourceUser === null) {
                throw new RuntimeException('The mapped private-server user no longer exists.');
            }

            $attributes = [
                'html' => $post->bodyHTML(['modifiers' => ['profile-page']]),
                'raw' => htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'raw_type' => 'tiptap',
            ];
            $userpage = $source->table('userpages')->where('user_id', $externalUserId)->first(['id']);

            if ($userpage === null) {
                $source->table('userpages')->insert([
                    'user_id' => $externalUserId,
                    ...$attributes,
                ]);
            } else {
                $source->table('userpages')->where('id', $userpage->id)->update($attributes);
            }

            $source->table('users')->where('id', $externalUserId)->update([
                'userpage_content' => mb_strlen($body) <= self::LEGACY_SOURCE_MAX_LENGTH ? $body : null,
            ]);
        });
    }
}
