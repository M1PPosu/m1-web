<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Models\M1pposuExternalUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Config;
use DB;
use Illuminate\Database\QueryException;
use Log;
use PDOException;

class PrivateProfileData
{
    private const CONNECTION = 'm1pposu-private-server-source';
    private const RANK_HISTORY_DAYS = 90;

    private array $profileCache = [];
    private bool $sourceConfigured = false;

    public function rankHistory(User $user, string $ruleset, ?string $variant): ?array
    {
        $sourceMode = SourceMode::sourceMode($ruleset, $variant);
        $sourceUserId = $this->sourceUserId($user);
        if ($sourceMode === null || $sourceUserId === null || !$this->configureSource()) {
            return null;
        }

        $cacheKey = "rank:{$sourceUserId}:{$sourceMode}";

        return $this->profileCache[$cacheKey] ??= $this->readRankHistory(
            $sourceUserId,
            $sourceMode,
            $ruleset,
        );
    }

    public function statistics(User $user, string $ruleset, ?string $variant): ?array
    {
        $sourceMode = SourceMode::sourceMode($ruleset, $variant);
        $sourceUserId = $this->sourceUserId($user);
        if ($sourceMode === null || $sourceUserId === null || !$this->configureSource()) {
            return null;
        }

        $cacheKey = "stats:{$sourceUserId}:{$sourceMode}";

        return $this->profileCache[$cacheKey] ??= $this->readStatistics($sourceUserId, $sourceMode);
    }

    private function configureSource(): bool
    {
        if ($this->sourceConfigured) {
            return true;
        }

        $database = config('m1pposu.private_server.database');
        if (
            !get_bool(config('m1pposu.private_server.enabled') ?? false)
            || presence($database['host'] ?? null) === null
            || presence($database['database'] ?? null) === null
            || presence($database['username'] ?? null) === null
        ) {
            return false;
        }

        Config::set('database.connections.'.self::CONNECTION, [
            ...config('database.connections.mysql'),
            'host' => $database['host'],
            'port' => $database['port'],
            'database' => $database['database'],
            'username' => $database['username'],
            'password' => $database['password'],
        ]);
        DB::purge(self::CONNECTION);
        $this->sourceConfigured = true;

        return true;
    }

    private function readRankHistory(int $sourceUserId, int $sourceMode, string $ruleset): ?array
    {
        $today = CarbonImmutable::today();
        $start = $today->subDays(self::RANK_HISTORY_DAYS - 1);

        try {
            $rows = DB::connection(self::CONNECTION)
                ->table('sh_rank_cache')
                ->select(['date', 'rank'])
                ->where('user_id', $sourceUserId)
                ->where('mode', $sourceMode)
                ->whereBetween('date', [$start->toDateString(), $today->toDateString()])
                ->orderBy('date')
                ->get();
        } catch (QueryException|PDOException $exception) {
            $this->logReadFailure('rank_history', $sourceUserId, $sourceMode, $exception);

            return null;
        }

        if ($rows->isEmpty()) {
            return null;
        }

        $ranksByDate = $rows->mapWithKeys(fn ($row) => [(string) $row->date => (int) $row->rank]);
        $data = [];
        for ($date = $start; $date->lessThanOrEqualTo($today); $date = $date->addDay()) {
            $data[] = $ranksByDate[$date->toDateString()] ?? 0;
        }

        return ['data' => $data, 'mode' => $ruleset];
    }

    private function readStatistics(int $sourceUserId, int $sourceMode): ?array
    {
        try {
            $row = DB::connection(self::CONNECTION)
                ->table('stats')
                ->select(['plays', 'total_hits'])
                ->where('id', $sourceUserId)
                ->where('mode', $sourceMode)
                ->first();
        } catch (QueryException|PDOException $exception) {
            $this->logReadFailure('statistics', $sourceUserId, $sourceMode, $exception);

            return null;
        }

        return $row === null
            ? null
            : ['play_count' => (int) $row->plays, 'total_hits' => (int) $row->total_hits];
    }

    private function sourceUserId(User $user): ?int
    {
        if (!get_bool(config('m1pposu.private_server.enabled') ?? false)) {
            return null;
        }

        $cacheKey = "user:{$user->getKey()}";
        if (array_key_exists($cacheKey, $this->profileCache)) {
            return $this->profileCache[$cacheKey];
        }

        $externalUserId = M1pposuExternalUser::query()
            ->where('backend', config('m1pposu.private_server.backend') ?? 'bancho-py-ex')
            ->where('user_id', $user->getKey())
            ->value('external_user_id');

        return $this->profileCache[$cacheKey] = get_int($externalUserId);
    }

    private function logReadFailure(string $source, int $sourceUserId, int $sourceMode, \Throwable $exception): void
    {
        Log::warning('Private profile data read failed.', [
            'class' => $exception::class,
            'source' => $source,
            'source_mode' => $sourceMode,
            'source_user_id' => $sourceUserId,
        ]);
    }
}
