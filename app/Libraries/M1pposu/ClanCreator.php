<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Exceptions\ModelNotSavedException;
use App\Models\M1pposuExternalTeam;
use App\Models\M1pposuExternalUser;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Redis;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

final class ClanCreator
{
    private const CONNECTION = 'm1pposu-private-server-source';
    private const SOURCE_NAME_MAX_LENGTH = 16;
    private const SOURCE_TAG_MAX_LENGTH = 6;

    public function create(Team $team, User $leader): Team
    {
        if (!$team->isValid()) {
            throw new ModelNotSavedException($team->validationErrors()->toSentence());
        }

        if (mb_strlen($team->name) > self::SOURCE_NAME_MAX_LENGTH) {
            $team->validationErrors()->addTranslated(
                'name',
                'Clan name must be 16 characters or fewer for the game server.',
            );
        }
        if (mb_strlen($team->short_name) > self::SOURCE_TAG_MAX_LENGTH) {
            $team->validationErrors()->addTranslated(
                'short_name',
                'Clan tag must be 6 characters or fewer for the game server.',
            );
        }
        if (!$team->validationErrors()->isEmpty()) {
            throw new ModelNotSavedException($team->validationErrors()->toSentence());
        }

        $this->configureSource();
        $backend = $this->backend();
        $externalUserId = M1pposuExternalUser::where('backend', $backend)
            ->where('user_id', $leader->getKey())
            ->value('external_user_id');
        if ($externalUserId === null) {
            throw new RuntimeException('The team leader has no private-server user mapping.');
        }

        $clanId = DB::connection(self::CONNECTION)->transaction(function () use ($externalUserId, $team) {
            $sourceUser = DB::connection(self::CONNECTION)
                ->table('users')
                ->where('id', $externalUserId)
                ->lockForUpdate()
                ->first(['id', 'clan_id']);
            if ($sourceUser === null) {
                throw new RuntimeException('The mapped private-server user no longer exists.');
            }

            if ((int) $sourceUser->clan_id !== 0) {
                $existing = DB::connection(self::CONNECTION)
                    ->table('clans')
                    ->where('id', $sourceUser->clan_id)
                    ->where('owner', $sourceUser->id)
                    ->where('name', $team->name)
                    ->where('tag', $team->short_name)
                    ->first();
                if ($existing !== null) {
                    return (int) $existing->id;
                }

                throw new RuntimeException('The private-server user already belongs to a different clan.');
            }

            if (DB::connection(self::CONNECTION)->table('clans')->where('name', $team->name)->exists()) {
                $team->validationErrors()->addTranslated('name', 'This clan name is already used on the game server.');
            }
            if (DB::connection(self::CONNECTION)->table('clans')->where('tag', $team->short_name)->exists()) {
                $team->validationErrors()->addTranslated('short_name', 'This clan tag is already used on the game server.');
            }
            if (!$team->validationErrors()->isEmpty()) {
                throw new ModelNotSavedException($team->validationErrors()->toSentence());
            }

            $clanId = DB::connection(self::CONNECTION)->table('clans')->insertGetId([
                'name' => $team->name,
                'tag' => $team->short_name,
                'owner' => $sourceUser->id,
                'created_at' => now(),
            ]);
            DB::connection(self::CONNECTION)->table('users')->where('id', $sourceUser->id)->update([
                'clan_id' => $clanId,
                'clan_priv' => 3,
            ]);

            return (int) $clanId;
        });

        $this->syncClan($clanId);
        $projected = M1pposuExternalTeam::where('backend', $backend)
            ->where('external_team_id', (string) $clanId)
            ->first()
            ?->team;
        if ($projected === null) {
            throw new RuntimeException("Source clan {$clanId} was created but its web projection is missing.");
        }

        $this->publishClanChange((int) $externalUserId, $clanId, false);

        return $projected;
    }

    private function syncClan(int $clanId): void
    {
        $output = new BufferedOutput();
        $exitCode = Artisan::call('m1pposu:sync:clans', [
            '--clan-id' => (string) $clanId,
            '--no-interaction' => true,
        ], $output);
        if ($exitCode !== 0) {
            throw new RuntimeException(trim($output->fetch()) ?: "Unable to project source clan {$clanId}.");
        }
    }

    private function publishClanChange(int $userId, int $clanId, bool $deleted): void
    {
        $config = config('m1pposu.private_server.live.redis');
        $redis = new Redis();
        $redis->connect((string) $config['host'], (int) $config['port'], 5.0);

        if (present($config['password'] ?? null)) {
            $auth = present($config['username'] ?? null)
                ? [(string) $config['username'], (string) $config['password']]
                : (string) $config['password'];
            if ($redis->auth($auth) !== true) {
                throw new RuntimeException('Private-server Redis authentication failed.');
            }
        }
        if ($redis->select((int) $config['database']) !== true) {
            throw new RuntimeException('Private-server Redis database selection failed.');
        }

        $redis->publish('clan_change', json_encode([
            'id' => $userId,
            'clan_id' => $clanId,
            'affected_clan_id' => $clanId,
            'clan_priv' => 3,
            'deleted' => $deleted,
        ], JSON_THROW_ON_ERROR));
    }

    private function configureSource(): void
    {
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
        DB::connection(self::CONNECTION)->getPdo();
    }

    private function backend(): string
    {
        return (string) (config('m1pposu.private_server.backend') ?: 'bancho-py-ex');
    }
}
