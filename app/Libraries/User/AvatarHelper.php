<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\User;

use App\Libraries\ImageProcessor;
use App\Libraries\M1pposu\ImportedAssets;
use App\Libraries\M1pposu\UserAssets;
use App\Libraries\StorageUrl;
use App\Models\User;

class AvatarHelper
{
    private const DISK = 'avatar';

    public static function set(User $user, ?\SplFileInfo $src): bool
    {
        $id = $user->getKey();
        $storage = storage_disk(static::DISK);

        if ($src === null) {
            $storage->delete($id);
        } else {
            $srcPath = $src->getRealPath();
            $processor = new ImageProcessor($srcPath, [256, 256], 100000);
            $processor->process();

            $storage->putFileAs('/', $src, $id, 'public');
            $entry = $id.'_'.time().'.'.$processor->ext();
        }

        cache_proxy_purge(StorageUrl::make(static::DISK, (string) $id));

        return $user->update(['user_avatar' => $entry ?? '']);
    }

    public static function url(User $user): string
    {
        $value = $user->getRawAttribute('user_avatar');

        if (ImportedAssets::isMarker($value)) {
            return ImportedAssets::urlFromMarker($value)
                ?? UserAssets::avatarUrl($user)
                ?? $GLOBALS['cfg']['osu']['avatar']['default'];
        }

        if (present($value)) {
            return StorageUrl::make(static::DISK, strtr($value, '_', '?'));
        }

        return UserAssets::avatarUrl($user) ?? $GLOBALS['cfg']['osu']['avatar']['default'];
    }
}
