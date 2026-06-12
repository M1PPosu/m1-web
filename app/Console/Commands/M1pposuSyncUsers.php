<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\HasM1pposuCommandLock;
use App\Libraries\M1pposu\SourceMode;
use App\Libraries\M1pposu\SourcePrivileges;
use App\Libraries\M1pposu\UserProjector;
use App\Libraries\UsernameValidation;
use App\Models\Country;
use App\Models\M1pposuExternalUser;
use App\Models\User;
use App\Models\UserAccountHistory;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Locale;
use Throwable;

class M1pposuSyncUsers extends Command
{
    use HasM1pposuCommandLock;

    private const CONNECTION = 'm1pposu-private-server-source';
    private const DEFAULT_CHUNK_SIZE = 500;
    private const MAX_BATCH_LIMIT = 1000;
    private const MAX_CHUNK_SIZE = 2000;

    private const SOURCE_USER_COLUMNS = [
        'id',
        'name',
        'safe_name',
        'email',
        'priv',
        'country',
        'silence_end',
        'donor_end',
        'creation_time',
        'latest_activity',
        'preferred_mode',
        'custom_badge_name',
        'custom_badge_icon',
        'userpage_content',
    ];

    private const SOURCE_STATS_COLUMNS = [
        'id',
        'mode',
        'tscore',
        'rscore',
        'pp',
        'plays',
        'playtime',
        'acc',
        'max_combo',
        'total_hits',
        'replay_views',
        'xh_count',
        'x_count',
        'sh_count',
        's_count',
        'a_count',
    ];

    protected $signature = 'm1pposu:sync:users
        {--username= : Sync one source user by exact username}
        {--external-id= : Sync one source user by source users.id}
        {--limit= : Maximum source users to scan for a batch sync}
        {--all : Sync every source user using chunked processing}
        {--chunk-size= : Source users to scan per chunk when using --all or --limit}
        {--dry-run : Show what would be synced without writing data}';

    protected $description = 'Sync private-server users and basic statistics into osu-web projection tables.';

    public function handle(): int
    {
        return $this->withM1pposuCommandLock('m1pposu:sync:users', fn () => $this->handleLocked());
    }

    private function handleLocked(): int
    {
        $dryRun = get_bool($this->option('dry-run'));
        $all = get_bool($this->option('all'));
        $username = $this->nullableString($this->option('username'));
        $externalId = $this->nullableString($this->option('external-id'));
        $limit = $this->parseLimit($this->option('limit'));
        $chunkSize = $this->parseChunkSize($this->option('chunk-size'));

        if ($username !== null && $externalId !== null) {
            $this->error('Use either --username or --external-id, not both.');

            return 1;
        }

        if ($all && ($username !== null || $externalId !== null || $limit !== null)) {
            $this->error('Use --all by itself, not with --username, --external-id, or --limit.');

            return 1;
        }

        if ($limit === false || $chunkSize === false) {
            return 1;
        }

        if ($username === null && $externalId === null && $limit === null && !$all) {
            $this->error('Refusing to run an unbounded sync. Use --username, --external-id, --limit, or --all.');

            return 1;
        }

        $privateServerConfig = $this->privateServerConfig();

        if (!get_bool($privateServerConfig['enabled'] ?? false)) {
            $this->error('Private-server source is disabled. Set M1PP_PRIVATE_SERVER_ENABLED=true before syncing.');

            return 1;
        }

        $missing = $this->missingDatabaseConfig($privateServerConfig);
        if ($missing !== []) {
            $this->error('Missing private-server source DB config: '.implode(', ', $missing));
            $this->line('Required env keys: M1PP_PRIVATE_SERVER_DB_HOST, M1PP_PRIVATE_SERVER_DB_DATABASE, M1PP_PRIVATE_SERVER_DB_USERNAME.');

            return 1;
        }

        $this->configureConnection($privateServerConfig);

        try {
            DB::connection(self::CONNECTION)->getPdo();
        } catch (Throwable $e) {
            $this->error("Unable to connect to private-server source DB: {$e->getMessage()}");
            $this->printConnectionHint($privateServerConfig['database'] ?? [], $e);

            return 1;
        }

        if (!$this->validateSourceSchema()) {
            return 1;
        }

        $backend = $privateServerConfig['backend'] ?: 'bancho-py-ex';
        $summary = $this->emptySummary();

        try {
            $this->forEachSourceUserBatch(
                $username,
                $externalId,
                $limit,
                $all,
                $chunkSize,
                function ($sourceUsers) use ($backend, $dryRun, &$summary) {
                    $sourceStatsByUserId = $this->sourceStatsRowsForUsers($sourceUsers->pluck('id')->all());

                    foreach ($sourceUsers as $sourceUser) {
                        $this->processSourceUser(
                            $sourceUser,
                            $sourceStatsByUserId[(int) $sourceUser->id] ?? collect(),
                            $backend,
                            $dryRun,
                            $summary
                        );
                    }
                },
            );
        } catch (Throwable $e) {
            $this->error("Unable to query source users: {$e->getMessage()}");

            return 1;
        }

        $this->printSummary($summary, $dryRun);

        return 0;
    }

    private function processSourceUser(object $sourceUser, $sourceStatsRows, string $backend, bool $dryRun, array &$summary): void
    {
        $summary['source_users_scanned']++;

        try {
            $this->recordSourceRestriction($sourceUser, $summary);
            $this->recordSourceSilence($sourceUser, $summary);
            $this->recordDeferredSourceFields($sourceUser, $summary);

            foreach ($sourceStatsRows as $sourceStats) {
                $this->recordDeferredStatsFields($sourceStats, $summary);
            }

            if ($dryRun) {
                $this->planSourceUser($sourceUser, $sourceStatsRows, $backend, $summary);
            } else {
                DB::transaction(function () use ($sourceUser, $sourceStatsRows, $backend, &$summary) {
                    $this->mergeProjectionResult(app(UserProjector::class)->sync($sourceUser, $sourceStatsRows, $backend), $summary);
                });
            }
        } catch (Throwable $e) {
            $summary['skipped_users']++;
            $summary['warnings'][] = "source user {$sourceUser->id}: {$this->exceptionMessage($e)}";
        }
    }

    private function planSourceUser(object $sourceUser, $sourceStatsRows, string $backend, array &$summary): void
    {
        $user = $this->findDestinationUser($sourceUser, $backend);

        if ($user === null) {
            if (!$this->canCreateUser($sourceUser, $summary, true)) {
                $summary['skipped_users']++;

                return;
            }

            $summary['created_users']++;
            $summary['linked_external_mappings']++;
        } else {
            $summary['updated_users']++;

            if (!$this->hasExternalMapping($sourceUser, $backend)) {
                $summary['linked_external_mappings']++;
            }
        }

        $summary['stats_rows_updated'] += $this->supportedStatsRowsCount($sourceStatsRows, $summary);

        if ($this->hasSupporterChange($sourceUser, $user)) {
            $summary['supporter_users_updated']++;
        }

        if ($this->hasRestrictionStateChange($sourceUser, $user, $summary)) {
            $summary['restriction_state_updated']++;
        }

        if ($this->hasSilenceStateChange($sourceUser, $user, $backend)) {
            $summary['silence_state_updated']++;
        }
    }

    private function mergeProjectionResult(array $result, array &$summary): void
    {
        if ($result['created_user'] ?? false) {
            $summary['created_users']++;
        }

        if ($result['updated_user'] ?? false) {
            $summary['updated_users']++;
        }

        if ($result['linked_external_mapping'] ?? false) {
            $summary['linked_external_mappings']++;
        }

        if ($result['supporter_user_updated'] ?? false) {
            $summary['supporter_users_updated']++;
        }

        if ($result['restriction_user_updated'] ?? false) {
            $summary['restriction_state_updated']++;
        }

        if ($result['silence_user_updated'] ?? false) {
            $summary['silence_state_updated']++;
        }

        $summary['stats_rows_updated'] += $result['stats_rows_updated'] ?? 0;

        foreach (($result['deferred'] ?? []) as $detail => $count) {
            $summary['deferred'][$detail] = ($summary['deferred'][$detail] ?? 0) + $count;
        }
    }

    private function forEachSourceUserBatch(?string $username, ?string $externalId, ?int $limit, bool $all, int $chunkSize, callable $callback): void
    {
        if ($username !== null) {
            $callback($this->sourceUsersQuery()->where('name', $username)->get());

            return;
        }

        if ($externalId !== null) {
            $callback($this->sourceUsersQuery()->where('id', $externalId)->get());

            return;
        }

        $lastId = 0;
        $remaining = $all ? null : $limit;

        while ($all || $remaining > 0) {
            $currentLimit = $remaining === null ? $chunkSize : min($chunkSize, $remaining);
            $sourceUsers = $this->sourceUsersQuery()
                ->where('id', '>', $lastId)
                ->limit($currentLimit)
                ->get();

            if ($sourceUsers->isEmpty()) {
                return;
            }

            $callback($sourceUsers);
            $lastId = (int) $sourceUsers->last()->id;

            if ($remaining !== null) {
                $remaining -= $sourceUsers->count();
            }
        }
    }

    private function sourceUsersQuery()
    {
        return DB::connection(self::CONNECTION)
            ->table('users')
            ->select(self::SOURCE_USER_COLUMNS)
            ->orderBy('id');
    }

    private function sourceStatsRowsForUsers(array $sourceUserIds)
    {
        $sourceUserIds = array_values(array_unique(array_map('intval', $sourceUserIds)));

        if ($sourceUserIds === []) {
            return [];
        }

        return DB::connection(self::CONNECTION)
            ->table('stats')
            ->select(UserProjector::SOURCE_STATS_COLUMNS)
            ->whereIn('id', $sourceUserIds)
            ->orderBy('id')
            ->orderBy('mode')
            ->get()
            ->groupBy(fn ($sourceStats) => (int) $sourceStats->id)
            ->all();
    }
    private function findDestinationUser(object $sourceUser, string $backend): ?User
    {
        $mapping = M1pposuExternalUser::query()
            ->where('backend', $backend)
            ->where('external_user_id', (string) $sourceUser->id)
            ->first();

        if ($mapping === null) {
            return null;
        }

        $user = User::find($mapping->user_id);
        if ($user === null) {
            throw new \RuntimeException("source user {$sourceUser->id} has an external mapping to missing destination user {$mapping->user_id}");
        }

        return $user;
    }

    private function canCreateUser(object $sourceUser, array &$summary, bool $dryRun): bool
    {
        $usernameFailureReason = $this->sourceUsernameFailureReason($sourceUser);
        if ($usernameFailureReason !== null) {
            $summary['warnings'][] = "source user {$sourceUser->id}: {$usernameFailureReason}";

            return false;
        }

        if ($this->validEmail($sourceUser->email ?? null) === null) {
            $this->addDeferred($summary, 'users.email', 'missing or invalid source email; projected user will use no local email');
        }

        $this->sourceCountry($sourceUser->country ?? null, $summary, true, $dryRun);

        return true;
    }

    private function hasSupporterChange(object $sourceUser, ?User $user): bool
    {
        $donorEnd = $this->sourceTimestamp($sourceUser->donor_end ?? null);

        if ($user === null) {
            return $donorEnd !== null;
        }

        $isActiveSupporter = $donorEnd !== null && $donorEnd->isFuture();
        $targetExpiry = $isActiveSupporter ? $donorEnd : null;
        $currentExpiry = $user->osu_subscriptionexpiry;

        return get_bool($user->osu_subscriber) !== $isActiveSupporter
            || ($currentExpiry?->timestamp !== $targetExpiry?->timestamp)
            || $user->support_length !== 0;
    }

    private function hasRestrictionStateChange(object $sourceUser, ?User $user, array &$summary): bool
    {
        $isRestricted = SourcePrivileges::isRestricted($sourceUser->priv ?? null);

        if ($isRestricted === null) {
            return false;
        }

        return $user === null
            ? $isRestricted
            : get_bool($user->user_warnings) !== $isRestricted;
    }

    private function hasSilenceStateChange(object $sourceUser, ?User $user, string $backend): bool
    {
        $status = $this->sourceSilenceStatus($sourceUser);
        $mapping = M1pposuExternalUser::query()
            ->where('backend', $backend)
            ->where('external_user_id', (string) $sourceUser->id)
            ->first();

        if ($status !== 'active') {
            return $mapping?->source_silence_account_history_id !== null;
        }

        if ($user === null || $mapping === null || $mapping->source_silence_account_history_id === null) {
            return true;
        }

        $history = UserAccountHistory::find($mapping->source_silence_account_history_id);
        if ($history === null) {
            return true;
        }

        $silenceEnd = $this->sourceTimestamp($sourceUser->silence_end ?? null);
        $projectedEnd = $history->timestamp?->copy()->addSeconds($history->period);

        return $history->user_id !== $user->getKey()
            || $history->ban_status !== UserAccountHistory::TYPES['silence']
            || get_bool($history->permanent)
            || $history->banner_id !== null
            || $history->reason !== null
            || $history->supporting_url !== null
            || $projectedEnd?->getTimestamp() !== $silenceEnd?->getTimestamp();
    }

    private function supportedStatsRowsCount($sourceStatsRows, array &$summary): int
    {
        $count = 0;

        foreach ($sourceStatsRows as $sourceStats) {
            $modeInt = (int) $sourceStats->mode;
            if (SourceMode::mode($modeInt) === null) {
                $this->addDeferred($summary, 'stats.mode', "unsupported mode {$sourceStats->mode}");

                continue;
            }

            $count++;
        }

        return $count;
    }

    private function recordSourceRestriction(object $sourceUser, array &$summary): void
    {
        if (SourcePrivileges::isRestricted($sourceUser->priv ?? null) === true) {
            $summary['restricted_source_users']++;
        }
    }

    private function recordSourceSilence(object $sourceUser, array &$summary): void
    {
        $summary["source_silence_{$this->sourceSilenceStatus($sourceUser)}"]++;
    }

    private function recordDeferredSourceFields(object $sourceUser, array &$summary): void
    {
        if ($this->nullableString($sourceUser->safe_name ?? null) !== null) {
            $this->addDeferred($summary, 'users.safe_name', 'username_clean is computed by osu-web');
        }

        if (SourcePrivileges::isRestricted($sourceUser->priv ?? null) === null) {
            $this->addDeferred($summary, 'users.priv', 'invalid value; restriction state not mapped');
        }

        if (
            $this->nullableString($sourceUser->custom_badge_name ?? null) !== null
            || $this->nullableString($sourceUser->custom_badge_icon ?? null) !== null
        ) {
            $this->addDeferred($summary, 'users.custom_badge', 'custom badge projection not mapped yet');
        }

        if ($this->nullableString($sourceUser->userpage_content ?? null) !== null) {
            $this->addDeferred($summary, 'users.userpage_content', 'profile page content projection not mapped yet');
        }

        if ($this->nullableString($sourceUser->preferred_mode ?? null) !== null) {
            $this->addDeferred($summary, 'users.preferred_mode', 'preferred play mode projection not mapped yet');
        }
    }

    private function recordDeferredStatsFields(object $sourceStats, array &$summary): void
    {
        if ((int) $sourceStats->total_hits > 0) {
            $this->addDeferred($summary, 'stats.total_hits', 'osu-web stores hit result buckets, not a single total_hits value');
        }

        if ((int) $sourceStats->mode === 3) {
            $this->addDeferred($summary, 'stats.mania_variants', '4k/7k variant stats are not available in the confirmed source stats row');
        }
    }

    private function validateSourceSchema(): bool
    {
        foreach (
            [
            'users' => self::SOURCE_USER_COLUMNS,
            'stats' => UserProjector::SOURCE_STATS_COLUMNS,
            ] as $table => $requiredColumns
        ) {
            try {
                $columns = collect(DB::connection(self::CONNECTION)->select("SHOW COLUMNS FROM {$table}"))
                    ->pluck('Field')
                    ->all();
            } catch (Throwable $e) {
                $this->error("Unable to inspect source table {$table}: {$e->getMessage()}");

                return false;
            }

            $missing = array_values(array_diff($requiredColumns, $columns));
            if ($missing !== []) {
                $this->error("Source table {$table} is missing required columns: ".implode(', ', $missing));

                return false;
            }
        }

        return true;
    }

    private function privateServerConfig(): array
    {
        return Config::get('m1pposu.private_server', []);
    }

    private function missingDatabaseConfig(array $privateServerConfig): array
    {
        $databaseConfig = $privateServerConfig['database'] ?? [];
        $required = ['host', 'database', 'username'];

        return array_values(array_filter($required, fn ($key) => blank($databaseConfig[$key] ?? null)));
    }

    private function configureConnection(array $privateServerConfig): void
    {
        $databaseConfig = $privateServerConfig['database'];

        Config::set('database.connections.'.self::CONNECTION, [
            'driver' => $databaseConfig['driver'] ?? 'mysql',
            'host' => $databaseConfig['host'],
            'port' => $databaseConfig['port'] ?? 3306,
            'database' => $databaseConfig['database'],
            'username' => $databaseConfig['username'],
            'password' => $databaseConfig['password'] ?? '',
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);

        DB::purge(self::CONNECTION);
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
        if ($limit <= 0 || $limit > self::MAX_BATCH_LIMIT) {
            $this->error('--limit must be between 1 and '.self::MAX_BATCH_LIMIT.'.');

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

    private function printConnectionHint(array $database, Throwable $e): void
    {
        $host = strtolower((string) ($database['host'] ?? ''));

        if ($host === 'mysql' && str_contains($e->getMessage(), 'php_network_getaddresses')) {
            $this->line('For the local Docker dump workflow, use M1PP_PRIVATE_SERVER_DB_HOST=db.');
        }
    }

    private function sourceCountry($value, ?array &$summary = null, bool $createMissing = false, bool $dryRun = false): ?string
    {
        $country = strtoupper(trim((string) $value));

        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            if ($summary !== null && $country !== '') {
                $this->addDeferred($summary, 'users.country', "invalid code {$country}; using ".Country::UNKNOWN);
            }

            return Country::UNKNOWN;
        }

        if ($country === Country::UNKNOWN) {
            return Country::UNKNOWN;
        }

        if (Country::where('acronym', $country)->exists()) {
            return $country;
        }

        if ($createMissing) {
            $countryName = Locale::getDisplayRegion("-{$country}", 'en');

            if (present($countryName) && $countryName !== $country) {
                if ($dryRun) {
                    $this->addDeferred($summary, 'users.country', "reference row would be created for {$country}");
                } else {
                    Country::create([
                        'acronym' => $country,
                        'display' => 0,
                        'name' => $countryName,
                        'playcount' => 0,
                        'pp' => 0,
                        'rankedscore' => 0,
                        'shipping_rate' => 1,
                        'usercount' => 0,
                    ]);

                    app('countries')->resetMemoized();
                }

                return $country;
            }
        }

        if ($summary !== null) {
            $this->addDeferred($summary, 'users.country', "unknown code {$country}; using ".Country::UNKNOWN);
        }

        return Country::UNKNOWN;
    }

    private function sourceTimestamp($value): ?Carbon
    {
        if (!is_numeric($value)) {
            return null;
        }

        $timestamp = (int) $value;
        if ($timestamp <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp);
    }

    private function validEmail($email): ?string
    {
        $email = $this->nullableString($email);

        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? $email
            : null;
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function hasExternalMapping(object $sourceUser, string $backend): bool
    {
        return M1pposuExternalUser::query()
            ->where('backend', $backend)
            ->where('external_user_id', (string) $sourceUser->id)
            ->exists();
    }

    private function emptySummary(): array
    {
        return [
            'source_users_scanned' => 0,
            'created_users' => 0,
            'updated_users' => 0,
            'skipped_users' => 0,
            'linked_external_mappings' => 0,
            'stats_rows_updated' => 0,
            'supporter_users_updated' => 0,
            'restricted_source_users' => 0,
            'restriction_state_updated' => 0,
            'source_silence_none' => 0,
            'source_silence_expired' => 0,
            'source_silence_active' => 0,
            'silence_state_updated' => 0,
            'deferred' => [],
            'errors' => [],
            'warnings' => [],
        ];
    }

    private function addDeferred(array &$summary, string $field, string $detail): void
    {
        $key = "{$field}: {$detail}";
        $summary['deferred'][$key] = ($summary['deferred'][$key] ?? 0) + 1;
    }

    private function exceptionMessage(Throwable $e): string
    {
        $message = trim($e->getMessage());

        return $message === ''
            ? get_class($e)
            : get_class($e).": {$message}";
    }

    private function sourceSilenceStatus(object $sourceUser): string
    {
        $silenceEnd = $this->sourceTimestamp($sourceUser->silence_end ?? null);

        if ($silenceEnd === null) {
            return 'none';
        }

        return $silenceEnd->isFuture() ? 'active' : 'expired';
    }

    private function printSummary(array $summary, bool $dryRun): void
    {
        $this->info($dryRun ? 'User sync dry-run summary:' : 'User sync summary:');
        $this->table(['Metric', 'Count'], [
            ['source users scanned', $summary['source_users_scanned']],
            [$dryRun ? 'users that would be created' : 'created users', $summary['created_users']],
            [$dryRun ? 'users that would be updated' : 'updated users', $summary['updated_users']],
            ['skipped users', $summary['skipped_users']],
            [$dryRun ? 'external mappings that would be linked' : 'linked external mappings', $summary['linked_external_mappings']],
            [$dryRun ? 'stats rows that would be updated' : 'stats rows updated', $summary['stats_rows_updated']],
            [$dryRun ? 'supporter users that would be updated' : 'supporter users updated', $summary['supporter_users_updated']],
            ['restricted source users', $summary['restricted_source_users']],
            [$dryRun ? 'restriction states that would be updated' : 'restriction states updated', $summary['restriction_state_updated']],
            ['source users with no silence', $summary['source_silence_none']],
            ['source users with expired silence', $summary['source_silence_expired']],
            ['source users with active silence', $summary['source_silence_active']],
            [$dryRun ? 'silence states that would be updated' : 'silence states updated', $summary['silence_state_updated']],
            ['warnings', count($summary['warnings'])],
            ['errors', count($summary['errors'])],
        ]);

        if ($summary['deferred'] !== []) {
            $this->warn('Deferred fields:');
            $rows = [];
            foreach ($summary['deferred'] as $detail => $count) {
                $rows[] = [$detail, $count];
            }
            $this->table(['Field / reason', 'Count'], $rows);
        }

        if ($summary['errors'] !== []) {
            $this->error('Errors:');
            foreach ($summary['errors'] as $error) {
                $this->line("- {$error}");
            }
        }

        if ($summary['warnings'] !== []) {
            $this->warn('Warnings:');
            foreach ($summary['warnings'] as $warning) {
                $this->line("- {$warning}");
            }
        }
    }

    private function sourceUsernameFailureReason(object $sourceUser): ?string
    {
        $rawSourceName = (string) ($sourceUser->name ?? '');
        if ($rawSourceName !== trim($rawSourceName)) {
            return 'unsupported username: username has leading or trailing spaces';
        }

        $sourceName = $this->nullableString($rawSourceName);
        if ($sourceName === null) {
            return 'missing username';
        }

        foreach (
            [
            UsernameValidation::validateUsername($sourceName),
            UsernameValidation::validateAvailability($sourceName),
            UsernameValidation::validateUsersOfUsername($sourceName),
            ] as $errors
        ) {
            if ($errors->isAny()) {
                return 'unsupported username: '.$errors->toSentence('; ');
            }
        }

        return null;
    }
}
