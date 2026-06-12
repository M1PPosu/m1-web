<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\HasM1pposuCommandLock;
use App\Libraries\ImageProcessor;
use App\Libraries\M1pposu\ImportedAssets;
use App\Libraries\User\Cover as UserCover;
use App\Models\M1pposuExternalUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Throwable;

class M1pposuSyncUserAssets extends Command
{
    use HasM1pposuCommandLock;

    private const DEFAULT_CHUNK_SIZE = 500;
    private const MAX_LIMIT = 1000;
    private const MAX_CHUNK_SIZE = 2000;
    private const SOURCE_EXTENSIONS = ['', 'jpg', 'jpeg', 'png', 'gif', 'webp'];

    protected $signature = 'm1pposu:sync:user-assets
        {type : avatars or covers}
        {--external-id= : Import assets for one source users.id}
        {--username= : Import assets for one source username}
        {--limit= : Maximum projected users to scan}
        {--all : Import assets for every projected user using chunked processing}
        {--chunk-size= : Projected users to scan per chunk}
        {--dry-run : Show what would be imported without writing files or users}';

    protected $description = 'Import private-server profile avatars or covers from configured source file storage.';

    public function handle(): int
    {
        $type = $this->assetType();

        return $type === null
            ? static::FAILURE
            : $this->withM1pposuCommandLock("m1pposu:sync:user-assets:{$type}", fn () => $this->handleLocked($type));
    }

    private function handleLocked(string $type): int
    {
        $dryRun = get_bool($this->option('dry-run'));
        $all = get_bool($this->option('all'));
        $externalId = $this->nullableString($this->option('external-id'));
        $username = $this->nullableString($this->option('username'));
        $limit = $this->parseLimit($this->option('limit'));
        $chunkSize = $this->parseChunkSize($this->option('chunk-size'));

        if ($limit === false || $chunkSize === false) {
            return static::FAILURE;
        }

        if ($externalId !== null && $username !== null) {
            $this->error('Use either --external-id or --username, not both.');

            return static::FAILURE;
        }

        if ($all && ($externalId !== null || $username !== null || $limit !== null)) {
            $this->error('Use --all by itself, not with --external-id, --username, or --limit.');

            return static::FAILURE;
        }

        if ($externalId === null && $username === null && $limit === null && !$all) {
            $this->error('Refusing to run an unbounded user asset sync. Use --external-id, --username, --limit, or --all.');

            return static::FAILURE;
        }

        $sourcePath = $this->sourcePath($type);
        if ($sourcePath === null) {
            $env = $type === 'avatars' ? 'M1PP_SOURCE_AVATAR_PATH' : 'M1PP_SOURCE_USER_COVER_PATH';
            $this->error("Source {$type} path is not configured. Set {$env} to a path visible inside the php container.");

            return static::FAILURE;
        }

        if (!is_dir($sourcePath)) {
            $this->error("Source {$type} path is not a readable directory: {$sourcePath}");

            return static::FAILURE;
        }

        try {
            ImportedAssets::publicBaseUrl();
            \Storage::disk(ImportedAssets::diskName());
        } catch (Throwable $e) {
            $this->error("Imported asset storage is not configured correctly: {$e->getMessage()}");

            return static::FAILURE;
        }

        $summary = [
            'users_scanned' => 0,
            "{$type}_imported" => 0,
            "{$type}_unchanged" => 0,
            'skipped_missing_source_file' => 0,
            'skipped_invalid_file' => 0,
            'skipped_missing_destination_user' => 0,
            'warnings' => [],
        ];

        $this->forEachMappingBatch($externalId, $username, $limit, $all, $chunkSize, function ($mappings) use ($dryRun, $sourcePath, $type, &$summary) {
            foreach ($mappings as $mapping) {
                $this->processMapping($mapping, $type, $sourcePath, $dryRun, $summary);
            }
        });

        $this->printSummary($summary, $type, $dryRun);

        return static::SUCCESS;
    }

    private function processMapping(M1pposuExternalUser $mapping, string $type, string $sourcePath, bool $dryRun, array &$summary): void
    {
        $summary['users_scanned']++;

        $user = User::find($mapping->user_id);
        if ($user === null) {
            $summary['skipped_missing_destination_user']++;

            return;
        }

        $file = $this->sourceFile($sourcePath, (string) $mapping->external_user_id);
        if ($file === null) {
            $summary['skipped_missing_source_file']++;

            return;
        }

        $tmpFile = null;
        try {
            [$tmpFile, $path] = $this->prepareImage($type, $file, $user);
            $currentMarker = $type === 'avatars'
                ? $user->getRawAttribute('user_avatar')
                : $user->getRawAttribute('custom_cover_filename');

            if (
                ImportedAssets::pathFromMarker($currentMarker) === $path
                && ImportedAssets::matchesFile($path, $tmpFile)
            ) {
                $summary["{$type}_unchanged"]++;

                return;
            }

            if ($dryRun) {
                $summary["{$type}_imported"]++;

                return;
            }

            ImportedAssets::putPublicFile($path, $tmpFile);
            if ($type === 'avatars') {
                $user->setAttribute('user_avatar', ImportedAssets::marker($path));
            } else {
                $user->setAttribute('cover_preset_id', null);
                $user->setAttribute('custom_cover_filename', ImportedAssets::marker($path));
            }
            $user->saveOrExplode();
            ImportedAssets::deleteMarkerIfDifferent(is_string($currentMarker) ? $currentMarker : null, $path);

            $summary["{$type}_imported"]++;
        } catch (Throwable $e) {
            $summary['skipped_invalid_file']++;
            $summary['warnings'][] = "source user {$mapping->external_user_id}: {$e->getMessage()}";
        } finally {
            if ($tmpFile !== null) {
                @unlink($tmpFile);
            }
        }
    }

    private function prepareImage(string $type, string $sourceFile, User $user): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'm1pposu-user-asset-');
        if ($tmpFile === false || !copy($sourceFile, $tmpFile)) {
            throw new RuntimeException('Unable to prepare temporary asset file.');
        }

        try {
            $processor = $type === 'avatars'
                ? new ImageProcessor($tmpFile, [256, 256], 100_000)
                : new ImageProcessor($tmpFile, UserCover::CUSTOM_COVER_MAX_DIMENSIONS, UserCover::CUSTOM_COVER_MAX_FILESIZE);
            $processor->process();
            $path = $type === 'avatars'
                ? ImportedAssets::userAvatarPath($user, $processor->ext())
                : ImportedAssets::userCoverPath($user, $processor->ext());

            return [$tmpFile, $path];
        } catch (Throwable $e) {
            @unlink($tmpFile);

            throw $e;
        }
    }

    private function forEachMappingBatch(?string $externalId, ?string $username, ?int $limit, bool $all, int $chunkSize, callable $callback): void
    {
        if ($externalId !== null || $username !== null) {
            $query = $this->mappingQuery();
            if ($externalId !== null) {
                $query->where('external_user_id', $externalId);
            }
            if ($username !== null) {
                $query->where('external_username', $username);
            }
            $callback($query->get());

            return;
        }

        $lastId = 0;
        $remaining = $all ? null : $limit;

        while ($all || $remaining > 0) {
            $currentLimit = $remaining === null ? $chunkSize : min($chunkSize, $remaining);
            $mappings = $this->mappingQuery()
                ->where('id', '>', $lastId)
                ->limit($currentLimit)
                ->get();

            if ($mappings->isEmpty()) {
                return;
            }

            $callback($mappings);
            $lastId = (int) $mappings->last()->id;

            if ($remaining !== null) {
                $remaining -= $mappings->count();
            }
        }
    }

    private function mappingQuery()
    {
        return M1pposuExternalUser::query()
            ->where('backend', Config::get('m1pposu.private_server.backend') ?: 'bancho-py-ex')
            ->orderBy('id');
    }

    private function sourceFile(string $basePath, string $externalUserId): ?string
    {
        if (!ctype_digit($externalUserId)) {
            return null;
        }

        $realBasePath = realpath($basePath);
        if ($realBasePath === false) {
            return null;
        }

        foreach (self::SOURCE_EXTENSIONS as $extension) {
            $filename = $extension === '' ? $externalUserId : "{$externalUserId}.{$extension}";
            $path = "{$realBasePath}/{$filename}";

            if (is_file($path)) {
                $realPath = realpath($path);
                if ($realPath !== false && str_starts_with($realPath, $realBasePath.DIRECTORY_SEPARATOR)) {
                    return $realPath;
                }
            }
        }

        return null;
    }

    private function sourcePath(string $type): ?string
    {
        $path = $type === 'avatars'
            ? Config::get('m1pposu.users.source_avatar_path')
            : Config::get('m1pposu.users.source_cover_path');

        return present($path) ? rtrim((string) $path, '\\/') : null;
    }

    private function assetType(): ?string
    {
        $type = $this->argument('type');
        if (!in_array($type, ['avatars', 'covers'], true)) {
            $this->error('type must be either avatars or covers.');

            return null;
        }

        return $type;
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

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function printSummary(array $summary, string $type, bool $dryRun): void
    {
        $this->line($dryRun ? "Dry run complete; no {$type} were modified." : ucfirst($type).' sync complete.');
        $this->line('target disk: '.ImportedAssets::diskName());
        $this->line('target key layout: '.($type === 'avatars'
            ? 'm1pposu/users/avatars/{local_user_id}.{ext}'
            : 'm1pposu/users/covers/{local_user_id}.{ext}'));
        $this->line('public base URL: '.(ImportedAssets::publicBaseUrl() ?? '(filesystem disk URL)'));
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
