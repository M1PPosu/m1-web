<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

/**
 * @property int $screenshot_id
 * @property int $user_id
 * @property User $user
 * @property Carbon $timestamp
 * @property int $hits
 * @property Carbon $last_access
 * @property bool $deleted
 */
class Screenshot extends Model
{
    public $timestamps = false;
    protected $table = 'osu_screenshots';
    protected $primaryKey = 'screenshot_id';

    protected $casts = [
        'timestamp' => 'datetime',
        'last_access' => 'datetime',
        'deleted' => 'boolean',
    ];

    public static function isLegacyId(int $id): bool
    {
        return $id < $GLOBALS['cfg']['osu']['screenshots']['legacy_id_cutoff'];
    }

    private static function hashForId(int $id): string
    {
        $secret = $GLOBALS['cfg']['osu']['screenshots']['shared_secret'];
        if (!present($secret)) {
            throw new RuntimeException('SCREENSHOTS_SHARED_SECRET or APP_KEY must be configured.');
        }

        return substr(hash_hmac('sha256', (string) $id, $secret), 0, 16);
    }

    public static function lookup(int $id, ?string $hash): ?self
    {
        if ($hash === null) {
            if (!self::isLegacyId($id)) {
                return null;
            }
        } else if (!hash_equals(self::hashForId($id), $hash)) {
            return null;
        }

        return self::findOrFail($id);
    }

    public function store($file): void
    {
        $this->storage()->putFileAs('/', $file, "{$this->getKey()}.jpg");
    }

    public function fetch(): ?string
    {
        return $this->storage()->get("{$this->getKey()}.jpg");
    }

    public function url(): string
    {
        return route('screenshots.show', [
            'screenshot' => $this->getKey(),
            'hash' => $this->hash(),
        ]);
    }

    public function hash(): string
    {
        return self::hashForId($this->getKey());
    }

    private function storage(): Filesystem
    {
        return storage_disk('screenshot');
    }
}
