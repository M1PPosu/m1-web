<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Throwable;

final class ImportedAssets
{
    private const MARKER_PREFIX = 'm1pposu-imported:';
    private const PATH_PATTERN = '/\Am1pposu\/(?:[A-Za-z0-9._-]+\/)*[A-Za-z0-9._-]+\z/';

    public static function deleteMarkerIfDifferent(?string $marker, string $newPath): void
    {
        $oldPath = static::pathFromMarker($marker);
        if ($oldPath !== null && $oldPath !== $newPath) {
            Storage::disk(static::diskName())->delete($oldPath);
        }
    }

    public static function diskName(): string
    {
        $disk = trim((string) Config::get('m1pposu.imported_assets_disk'));

        return $disk === '' ? (string) Config::get('filesystems.default', 'local') : $disk;
    }

    public static function isMarker(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::MARKER_PREFIX);
    }

    public static function marker(string $path): string
    {
        return self::MARKER_PREFIX.static::validatedPath($path);
    }

    public static function matchesFile(string $path, string $sourcePath): bool
    {
        $path = static::validatedPath($path);
        if (!is_file($sourcePath)) {
            return false;
        }

        try {
            $stream = Storage::disk(static::diskName())->readStream($path);
            if (!is_resource($stream)) {
                return false;
            }

            try {
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                $storedHash = hash_final($hash);
            } finally {
                fclose($stream);
            }

            return hash_equals((string) hash_file('sha256', $sourcePath), $storedHash);
        } catch (Throwable) {
            return false;
        }
    }

    public static function pathFromMarker(mixed $value): ?string
    {
        if (!static::isMarker($value)) {
            return null;
        }

        $path = substr($value, strlen(self::MARKER_PREFIX));

        return static::isValidPath($path) ? $path : null;
    }

    public static function publicBaseUrl(): ?string
    {
        $disk = static::diskName();
        $baseUrl = presence(Config::get('m1pposu.imported_assets_base_url'))
            ?? presence(Config::get("filesystems.disks.{$disk}.base_url"))
            ?? presence(Config::get("filesystems.disks.{$disk}.url"));

        if ($baseUrl === null) {
            return null;
        }

        $baseUrl = rtrim((string) $baseUrl, '/');
        $parts = parse_url($baseUrl);
        if (
            $parts === false
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !present($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('Imported asset base URL must be an absolute HTTP(S) URL without embedded credentials.');
        }

        return $baseUrl;
    }

    public static function putPublicFile(string $path, string $sourcePath): void
    {
        $path = static::validatedPath($path);
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException('Imported asset source file is not readable.');
        }

        $storage = Storage::disk(static::diskName());
        $stream = fopen($sourcePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open imported asset source file.');
        }

        $options = $storage->getAdapter() instanceof LocalFilesystemAdapter
            ? ['visibility' => 'public', 'directory_visibility' => 'public']
            : [];

        try {
            $stored = $storage->put($path, $stream, $options);
        } finally {
            fclose($stream);
        }

        if (!$stored) {
            throw new RuntimeException("Unable to store imported asset at {$path}.");
        }
    }

    public static function teamFlagPath(Team $team, string $extension): string
    {
        return 'm1pposu/teams/flags/'.static::modelId($team->getKey()).'.'.static::extension($extension);
    }

    public static function teamHeaderPath(Team $team, string $extension): string
    {
        return 'm1pposu/teams/headers/'.static::modelId($team->getKey()).'.'.static::extension($extension);
    }

    public static function url(string $path): string
    {
        $path = static::validatedPath($path);
        $baseUrl = static::publicBaseUrl();

        return $baseUrl === null
            ? Storage::disk(static::diskName())->url($path)
            : "{$baseUrl}/{$path}";
    }

    public static function urlFromMarker(mixed $value): ?string
    {
        $path = static::pathFromMarker($value);

        return $path === null ? null : static::url($path);
    }

    public static function userAvatarPath(User $user, string $extension): string
    {
        return 'm1pposu/users/avatars/'.static::modelId($user->getKey()).'.'.static::extension($extension);
    }

    public static function userCoverPath(User $user, string $extension): string
    {
        return 'm1pposu/users/covers/'.static::modelId($user->getKey()).'.'.static::extension($extension);
    }

    private static function extension(string $extension): string
    {
        $extension = strtolower(ltrim(trim($extension), '.'));
        if (!preg_match('/\A[a-z0-9]{1,8}\z/', $extension)) {
            throw new RuntimeException('Imported asset extension is invalid.');
        }

        return $extension;
    }

    private static function isValidPath(string $path): bool
    {
        return preg_match(self::PATH_PATTERN, $path) === 1
            && !str_contains($path, '/./')
            && !str_contains($path, '/../')
            && !str_ends_with($path, '/.')
            && !str_ends_with($path, '/..');
    }

    private static function modelId(mixed $id): string
    {
        $id = get_int($id);
        if ($id === null || $id <= 0) {
            throw new RuntimeException('Imported assets require a saved model with a positive integer ID.');
        }

        return (string) $id;
    }

    private static function validatedPath(string $path): string
    {
        if (!static::isValidPath($path)) {
            throw new RuntimeException('Imported asset path is invalid.');
        }

        return $path;
    }
}
