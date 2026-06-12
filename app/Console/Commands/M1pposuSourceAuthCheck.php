<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Libraries\M1pposu\UserProjector;
use App\Models\M1pposuExternalUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

class M1pposuSourceAuthCheck extends Command
{
    private const CONNECTION = 'm1pposu-private-server-source';

    private const SOURCE_USER_COLUMNS = [
        'id',
        'name',
        'safe_name',
        'email',
        'priv',
        'pw_bcrypt',
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

    protected $signature = 'm1pposu:source:auth-check
        {--username= : Source username, safe name, or email to check}
        {--password-env=M1PP_SOURCE_AUTH_CHECK_PASSWORD : Environment variable containing the password for local testing}';

    protected $description = 'Check private-server source login and projection status without modifying data.';

    public function handle(): int
    {
        $login = trim((string) $this->option('username'));
        if ($login === '') {
            $this->error('Use --username with a source username, safe name, or email.');

            return static::FAILURE;
        }

        $config = config('m1pposu.private_server');
        if (get_bool($config['enabled'] ?? false) !== true) {
            $this->error('Private-server source is disabled.');

            return static::FAILURE;
        }

        $database = $config['database'] ?? [];
        if (!$this->hasRequiredConfig($database)) {
            $this->error('Private-server source database config is incomplete.');

            return static::FAILURE;
        }

        $this->configureConnection($database);

        try {
            DB::connection(static::CONNECTION)->getPdo();
        } catch (Throwable $e) {
            $this->error("Could not connect to private-server source database: {$e->getMessage()}");

            return static::FAILURE;
        }

        $sourceUser = $this->sourceUserForLogin($login);
        if ($sourceUser === null) {
            $this->error('Source user lookup did not return exactly one row.');

            return static::FAILURE;
        }

        $backend = $config['backend'] ?? 'bancho-py-ex';
        $mapping = M1pposuExternalUser::query()
            ->where('backend', $backend)
            ->where('external_user_id', (string) $sourceUser->id)
            ->first();

        $this->info('Source user found.');
        $this->line("Backend: {$backend}");
        $this->line("Source id: {$sourceUser->id}");
        $this->line('External mapping: '.($mapping === null ? 'missing' : "user_id {$mapping->user_id}"));

        $projector = app(UserProjector::class);
        $projectionFailureReason = $projector->projectionFailureReason($sourceUser, $backend);
        if ($projectionFailureReason !== null) {
            $this->warn("Projection would fail: {$projectionFailureReason}");
        } else {
            $projectionUser = $projector->findDestinationUser($sourceUser, $backend);
            $this->line('Projection would succeed: yes');
            $this->line('Projection target: '.($projectionUser === null ? 'new osu-web user would be created after successful auth' : "existing user_id {$projectionUser->getKey()} would be refreshed after successful auth"));
        }

        $password = $this->passwordForCheck();
        if ($password === null) {
            $this->line('Password check skipped. Set the configured password env var or run interactively to verify credentials.');

            return static::SUCCESS;
        }

        $this->line('Password check: '.($this->checkSourcePassword($sourceUser, $password) ? 'valid' : 'invalid'));

        return static::SUCCESS;
    }

    private function checkSourcePassword(object $sourceUser, string $password): bool
    {
        $hash = $sourceUser->pw_bcrypt ?? null;

        if (!is_string($hash) || $hash === '') {
            return false;
        }

        return password_verify(md5($password), $hash)
            || password_verify($password, $hash);
    }

    private function configureConnection(array $database): void
    {
        Config::set('database.connections.'.static::CONNECTION, [
            ...config('database.connections.mysql'),
            'host' => $database['host'],
            'port' => $database['port'],
            'database' => $database['database'],
            'username' => $database['username'],
            'password' => $database['password'],
        ]);

        DB::purge(static::CONNECTION);
    }

    private function hasRequiredConfig(array $database): bool
    {
        return present($database['host'] ?? null)
            && present($database['database'] ?? null)
            && present($database['username'] ?? null);
    }

    private function passwordForCheck(): ?string
    {
        $envName = trim((string) $this->option('password-env'));
        $password = $envName === '' ? false : getenv($envName);

        if (is_string($password) && $password !== '') {
            return $password;
        }

        if ($this->input->isInteractive() && $this->confirm('Check a password now?', false)) {
            $password = $this->secret('Source password');

            return is_string($password) && $password !== '' ? $password : null;
        }

        return null;
    }

    private function sourceUserForLogin(string $login): ?object
    {
        $query = DB::connection(static::CONNECTION)
            ->table('users')
            ->select(static::SOURCE_USER_COLUMNS)
            ->where(function ($query) use ($login) {
                $query
                    ->where('name', $login)
                    ->orWhere('safe_name', $login);

                if (str_contains($login, '@')) {
                    $query->orWhere('email', strtolower($login));
                }
            })
            ->limit(2);

        $matches = $query->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
