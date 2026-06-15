<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\HasM1pposuCommandLock;
use App\Models\Beatmap;
use App\Models\M1pposuExternalTeam;
use App\Models\M1pposuExternalUser;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\TeamStatistics;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class M1pposuSyncClans extends Command
{
    use HasM1pposuCommandLock;

    private const CONNECTION = 'm1pposu-private-server-source';
    private const DEFAULT_CHUNK_SIZE = 100;
    private const MAX_LIMIT = 1000;
    private const MAX_CHUNK_SIZE = 500;

    protected $signature = 'm1pposu:sync:clans
        {--clan-id= : Sync one source clan by source clans.id}
        {--limit= : Maximum source clans to scan}
        {--all : Sync every source clan using chunked processing}
        {--chunk-size= : Source clans to scan per chunk}
        {--dry-run : Show what would be synced without writing data}';

    protected $description = 'Sync private-server clans into osu-web teams.';

    public function handle(): int
    {
        return $this->withM1pposuCommandLock('m1pposu:sync:clans', fn () => $this->handleLocked());
    }

    private function handleLocked(): int
    {
        $dryRun = get_bool($this->option('dry-run'));
        $all = get_bool($this->option('all'));
        $clanId = $this->nullableString($this->option('clan-id'));
        $limit = $this->parseLimit($this->option('limit'));
        $chunkSize = $this->parseChunkSize($this->option('chunk-size'));

        if ($limit === false || $chunkSize === false) {
            return static::FAILURE;
        }

        if ($all && ($clanId !== null || $limit !== null)) {
            $this->error('Use --all by itself, not with --clan-id or --limit.');

            return static::FAILURE;
        }

        if ($clanId === null && $limit === null && !$all) {
            $this->error('Refusing to run an unbounded clan sync. Use --clan-id, --limit, or --all.');

            return static::FAILURE;
        }

        $config = $this->privateServerConfig();
        if (!$this->configureSource($config)) {
            return static::FAILURE;
        }

        $missing = $this->missingSourceColumns();
        if ($missing !== []) {
            foreach ($missing as $table => $columns) {
                $this->error("Source table {$table} is missing required columns: ".implode(', ', $columns));
            }

            return static::FAILURE;
        }

        $backend = $config['backend'] ?: 'bancho-py-ex';
        $summary = [
            'source_clans_scanned' => 0,
            'created_teams' => 0,
            'updated_teams' => 0,
            'linked_external_mappings' => 0,
            'members_synced' => 0,
            'members_removed' => 0,
            'team_statistics_updated' => 0,
            'skipped_clans' => 0,
            'skipped_members_missing_mapping' => 0,
            'skipped_members_restricted' => 0,
            'warnings' => [],
        ];

        try {
            $this->forEachClanBatch($clanId, $limit, $all, $chunkSize, function ($sourceClans) use ($backend, $dryRun, &$summary) {
                foreach ($sourceClans as $sourceClan) {
                    $this->processClan($sourceClan, $backend, $dryRun, $summary);
                }
            });
        } catch (Throwable $e) {
            $this->error("Unable to query source clans: {$e->getMessage()}");

            return static::FAILURE;
        }

        $this->printSummary($summary, $dryRun);

        return static::SUCCESS;
    }

    private function processClan(object $sourceClan, string $backend, bool $dryRun, array &$summary): void
    {
        $summary['source_clans_scanned']++;

        $sourceName = $this->nullableString($sourceClan->name ?? null);
        $sourceTag = $this->nullableString($sourceClan->tag ?? null);
        if ($sourceName === null || $sourceTag === null) {
            $summary['skipped_clans']++;
            $summary['warnings'][] = "source clan {$sourceClan->id}: missing name or tag";

            return;
        }

        $name = $this->normaliseTeamField($sourceName, Team::MAX_FIELD_LENGTHS['name'], "Clan {$sourceClan->id}");
        $tag = $this->normaliseTeamField($sourceTag, Team::MAX_FIELD_LENGTHS['short_name'], "C{$sourceClan->id}");
        [$name, $tag] = $this->avoidTeamCollisions((int) $sourceClan->id, $backend, $name, $tag);

        $memberPlan = $this->memberPlan((int) $sourceClan->id, $backend);
        $summary['skipped_members_missing_mapping'] += $memberPlan['skipped_missing_mapping'];
        $summary['skipped_members_restricted'] += $memberPlan['skipped_restricted'];

        if ($memberPlan['user_ids'] === []) {
            $summary['skipped_clans']++;
            $summary['warnings'][] = "source clan {$sourceClan->id}: no projected unrestricted members";

            return;
        }

        $leader = $this->mappedUnrestrictedUser((int) $sourceClan->owner, $backend);
        if ($leader === null) {
            $leader = User::find($memberPlan['user_ids'][0]);
            $summary['warnings'][] = "source clan {$sourceClan->id}: owner is unavailable; using first projected unrestricted member as team leader";
        }

        $mapping = M1pposuExternalTeam::query()
            ->where('backend', $backend)
            ->where('external_team_id', (string) $sourceClan->id)
            ->first();
        $team = $mapping === null ? null : Team::find($mapping->team_id);

        if ($dryRun) {
            $summary[$team === null ? 'created_teams' : 'updated_teams']++;
            if ($mapping === null) {
                $summary['linked_external_mappings']++;
            }
            $summary['members_synced'] += count($memberPlan['user_ids']);
            $summary['team_statistics_updated'] += count(TeamStatistics::supportedRulesetVariants());

            return;
        }

        try {
            DB::transaction(function () use ($backend, $leader, $mapping, &$summary, $sourceClan, $team, $name, $tag, $sourceName, $sourceTag, $memberPlan) {
                $isNew = $team === null;
                $team ??= new Team();
                $team->name = $name;
                $team->short_name = $tag;
                $team->leader_id = $leader->getKey();
                $team->is_open = false;
                $team->default_ruleset_id = $team->default_ruleset_id ?? Beatmap::MODES['osu'];
                if (($createdAt = $this->sourceDate($sourceClan->created_at ?? null)) !== null && !$team->exists) {
                    $team->created_at = $createdAt;
                }
                $summary['members_removed'] += TeamMember::query()
                    ->whereIn('user_id', $memberPlan['user_ids'])
                    ->when($team->exists, fn ($query) => $query->where('team_id', '<>', $team->getKey()))
                    ->delete();
                $team->saveOrExplode();

                $external = $mapping ?? new M1pposuExternalTeam([
                    'backend' => $backend,
                    'external_team_id' => (string) $sourceClan->id,
                ]);
                $external->team_id = $team->getKey();
                $external->external_name = $sourceName;
                $external->external_short_name = $sourceTag;
                if (!$external->exists || $external->isDirty()) {
                    $external->saveOrExplode();
                    $summary['linked_external_mappings']++;
                }

                $summary[$isNew ? 'created_teams' : 'updated_teams']++;
                $syncedUserIds = $this->syncMembers($team, $memberPlan['user_ids']);
                $summary['members_synced'] += count($syncedUserIds);
                $summary['members_removed'] += TeamMember::query()
                    ->where('team_id', $team->getKey())
                    ->whereNotIn('user_id', $syncedUserIds)
                    ->delete();

                foreach (TeamStatistics::supportedRulesetVariants() as $mode) {
                    TeamStatistics::createOrFirst([
                        'team_id' => $team->getKey(),
                        'ruleset_id' => $mode['rulesetId'],
                        'variant' => $mode['variant'],
                    ])->recalculate();
                    $summary['team_statistics_updated']++;
                }
            });
        } catch (Throwable $e) {
            $summary['skipped_clans']++;
            $summary['warnings'][] = "source clan {$sourceClan->id}: {$e->getMessage()}";
        }
    }

    private function syncMembers(Team $team, array $userIds): array
    {
        foreach ($userIds as $userId) {
            TeamMember::updateOrCreate(['user_id' => $userId], ['team_id' => $team->getKey()]);
        }

        return $userIds;
    }

    private function forEachClanBatch(?string $clanId, ?int $limit, bool $all, int $chunkSize, callable $callback): void
    {
        if ($clanId !== null) {
            $callback($this->sourceClansQuery()->where('id', $clanId)->get());

            return;
        }

        $lastId = 0;
        $remaining = $all ? null : $limit;

        while ($all || $remaining > 0) {
            $currentLimit = $remaining === null ? $chunkSize : min($chunkSize, $remaining);
            $sourceClans = $this->sourceClansQuery()
                ->where('id', '>', $lastId)
                ->limit($currentLimit)
                ->get();

            if ($sourceClans->isEmpty()) {
                return;
            }

            $callback($sourceClans);
            $lastId = (int) $sourceClans->last()->id;

            if ($remaining !== null) {
                $remaining -= $sourceClans->count();
            }
        }
    }

    private function sourceClansQuery()
    {
        return DB::connection(self::CONNECTION)
            ->table('clans')
            ->select(['id', 'name', 'tag', 'owner', 'created_at'])
            ->orderBy('id');
    }

    private function memberPlan(int $sourceClanId, string $backend): array
    {
        $sourceMembers = DB::connection(self::CONNECTION)
            ->table('users')
            ->select(['id', 'clan_priv'])
            ->where('clan_id', $sourceClanId)
            ->orderByDesc('clan_priv')
            ->orderBy('id')
            ->get();

        if ($sourceMembers->isEmpty()) {
            return [
                'user_ids' => [],
                'skipped_missing_mapping' => 0,
                'skipped_restricted' => 0,
            ];
        }

        $sourceUserIds = $sourceMembers->pluck('id')->map(fn ($id) => (string) $id)->all();
        $mappings = M1pposuExternalUser::query()
            ->where('backend', $backend)
            ->whereIn('external_user_id', $sourceUserIds)
            ->get()
            ->keyBy('external_user_id');

        $users = User::whereIn('user_id', $mappings->pluck('user_id')->all())->get()->keyBy('user_id');
        $userIds = [];
        $skippedMissingMapping = 0;
        $skippedRestricted = 0;

        foreach ($sourceMembers as $sourceMember) {
            $mapping = $mappings->get((string) $sourceMember->id);
            $user = $mapping === null ? null : $users->get($mapping->user_id);

            if ($user === null) {
                $skippedMissingMapping++;
            } elseif ($user->isRestricted()) {
                $skippedRestricted++;
            } else {
                $userIds[] = (int) $user->getKey();
            }
        }

        return [
            'user_ids' => array_values(array_unique($userIds)),
            'skipped_missing_mapping' => $skippedMissingMapping,
            'skipped_restricted' => $skippedRestricted,
        ];
    }

    private function mappedUnrestrictedUser(int $sourceUserId, string $backend): ?User
    {
        $mapping = M1pposuExternalUser::query()
            ->where('backend', $backend)
            ->where('external_user_id', (string) $sourceUserId)
            ->first();

        $user = $mapping === null ? null : User::find($mapping->user_id);

        return $user !== null && !$user->isRestricted() ? $user : null;
    }

    private function normaliseTeamField(string $value, int $maxLength, string $fallback): string
    {
        $value = preg_replace('/[^\x20-\x7e]+/', '', $value);
        $value = preg_replace('/  +/', ' ', trim($value ?? '')) ?? '';
        $value = $value === '' ? $fallback : $value;

        return mb_strimwidth($value, 0, $maxLength, '');
    }

    private function avoidTeamCollisions(int $sourceClanId, string $backend, string $name, string $tag): array
    {
        $mapping = M1pposuExternalTeam::query()
            ->where('backend', $backend)
            ->where('external_team_id', (string) $sourceClanId)
            ->first();
        $teamId = $mapping?->team_id;

        if ($this->teamFieldAvailable('name', $name, $teamId) && $this->teamFieldAvailable('short_name', $tag, $teamId)) {
            return [$name, $tag];
        }

        $suffix = (string) $sourceClanId;
        $namePrefix = mb_strimwidth($name, 0, Team::MAX_FIELD_LENGTHS['name'] - strlen($suffix) - 1, '');
        $tagPrefix = mb_strimwidth($tag, 0, Team::MAX_FIELD_LENGTHS['short_name'] - min(strlen($suffix), Team::MAX_FIELD_LENGTHS['short_name']), '');

        return [
            trim("{$namePrefix} {$suffix}"),
            $tagPrefix === '' ? mb_strimwidth($suffix, 0, Team::MAX_FIELD_LENGTHS['short_name'], '') : "{$tagPrefix}".mb_strimwidth($suffix, 0, Team::MAX_FIELD_LENGTHS['short_name'] - strlen($tagPrefix), ''),
        ];
    }

    private function teamFieldAvailable(string $field, string $value, ?int $teamId): bool
    {
        return !Team::whereNot('id', $teamId)->where($field, $value)->exists();
    }

    private function missingSourceColumns(): array
    {
        $required = [
            'clans' => ['id', 'name', 'tag', 'owner', 'created_at'],
            'users' => ['id', 'clan_id'],
        ];
        $missing = [];

        foreach ($required as $table => $columns) {
            try {
                $actual = Schema::connection(self::CONNECTION)->getColumnListing($table);
            } catch (Throwable) {
                $actual = [];
            }

            $diff = array_values(array_diff($columns, $actual));
            if ($diff !== []) {
                $missing[$table] = $diff;
            }
        }

        return $missing;
    }

    private function configureSource(array $config): bool
    {
        if (!get_bool($config['enabled'] ?? false)) {
            $this->error('Private-server source is disabled. Set M1PP_PRIVATE_SERVER_ENABLED=true before syncing.');

            return false;
        }

        $database = $config['database'] ?? [];
        $missing = array_keys(array_filter([
            'M1PP_PRIVATE_SERVER_DB_HOST' => $database['host'] ?? null,
            'M1PP_PRIVATE_SERVER_DB_DATABASE' => $database['database'] ?? null,
            'M1PP_PRIVATE_SERVER_DB_USERNAME' => $database['username'] ?? null,
        ], fn ($value) => !present($value)));

        if ($missing !== []) {
            $this->error('Missing private-server source DB config: '.implode(', ', $missing));

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

        try {
            DB::connection(self::CONNECTION)->getPdo();
        } catch (Throwable $e) {
            $this->error("Unable to connect to private-server source DB: {$e->getMessage()}");

            return false;
        }

        return true;
    }

    private function parseLimit($rawLimit): int|false|null
    {
        $limit = $this->nullableString($rawLimit);
        if ($limit === null) {
            return null;
        }

        if (!ctype_digit($limit)) {
            $this->error('--limit must be a positive integer.');

            return false;
        }

        $limit = (int) $limit;
        if ($limit <= 0 || $limit > self::MAX_LIMIT) {
            $this->error('--limit must be between 1 and '.self::MAX_LIMIT.'.');

            return false;
        }

        return $limit;
    }

    private function parseChunkSize($rawChunkSize): int|false
    {
        $chunkSize = $this->nullableString($rawChunkSize);
        if ($chunkSize === null) {
            return self::DEFAULT_CHUNK_SIZE;
        }

        if (!ctype_digit($chunkSize)) {
            $this->error('--chunk-size must be a positive integer.');

            return false;
        }

        $chunkSize = (int) $chunkSize;
        if ($chunkSize <= 0 || $chunkSize > self::MAX_CHUNK_SIZE) {
            $this->error('--chunk-size must be between 1 and '.self::MAX_CHUNK_SIZE.'.');

            return false;
        }

        return $chunkSize;
    }

    private function privateServerConfig(): array
    {
        return Config::get('m1pposu.private_server', []);
    }

    private function sourceDate($value): ?Carbon
    {
        return present($value) ? Carbon::parse($value) : null;
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function printSummary(array $summary, bool $dryRun): void
    {
        $this->line($dryRun ? 'Dry run complete; no clans were modified.' : 'Clan sync complete.');
        foreach (array_diff_key($summary, ['warnings' => true]) as $key => $value) {
            $this->line(str_replace('_', ' ', $key).": {$value}");
        }

        foreach (array_slice($summary['warnings'], 0, 25) as $warning) {
            $this->warn($warning);
        }

        if (count($summary['warnings']) > 25) {
            $this->warn('Additional warnings: '.(count($summary['warnings']) - 25));
        }
    }
}
