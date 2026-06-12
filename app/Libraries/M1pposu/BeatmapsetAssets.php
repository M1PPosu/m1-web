<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Models\Beatmap;
use App\Models\Beatmapset;

final class BeatmapsetAssets
{
    private const COVER_CONFIG_KEYS = [
        'cover' => 'cover',
        'cover@2x' => 'cover_2x',
        'card' => 'card',
        'card@2x' => 'card_2x',
        'list' => 'list',
        'list@2x' => 'list_2x',
        'slimcover' => 'slimcover',
        'slimcover@2x' => 'slimcover_2x',
    ];

    public static function covers(Beatmapset $beatmapset): ?array
    {
        if (!static::isProjected($beatmapset)) {
            return null;
        }

        $urls = [];

        foreach (self::COVER_CONFIG_KEYS as $size => $configKey) {
            $url = static::format(static::coverTemplate($size, $configKey), $beatmapset);

            if ($url !== null) {
                $urls[$size] = $url;
            }
        }

        return $urls === [] ? null : $urls;
    }

    public static function downloadUrl(Beatmapset $beatmapset): ?string
    {
        if (!static::isProjected($beatmapset)) {
            return null;
        }

        return static::format(config('m1pposu.beatmaps.download_url'), $beatmapset);
    }

    private static function coverTemplate(string $size, string $configKey): ?string
    {
        $template = config("m1pposu.beatmaps.covers.{$configKey}");
        if (present($template) || !str_ends_with($size, '@2x')) {
            return $template;
        }

        $baseSize = substr($size, 0, -3);
        $baseTemplate = config('m1pposu.beatmaps.covers.'.self::COVER_CONFIG_KEYS[$baseSize]);

        return present($baseTemplate) ? static::make2xTemplate($baseTemplate) : null;
    }

    private static function format($template, Beatmapset $beatmapset): ?string
    {
        if (!present($template)) {
            return null;
        }

        $beatmapId = static::firstBeatmapId($beatmapset);
        $url = strtr(trim((string) $template), [
            '{id}' => (string) $beatmapset->getKey(),
            '{beatmapset_id}' => (string) $beatmapset->getKey(),
            '{beatmap_id}' => $beatmapId === null ? '' : (string) $beatmapId,
        ]);

        if ($beatmapId === null && str_contains((string) $template, '{beatmap_id}')) {
            return null;
        }

        if (preg_match('/\{[^}]+\}/', $url) === 1 || preg_match('/[\x00-\x20]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            ? $url
            : null;
    }

    private static function firstBeatmapId(Beatmapset $beatmapset): ?int
    {
        if (!$beatmapset->relationLoaded('beatmaps')) {
            return null;
        }

        $beatmap = $beatmapset->beatmaps->first();

        return $beatmap instanceof Beatmap ? (int) $beatmap->getKey() : null;
    }

    private static function isProjected(Beatmapset $beatmapset): bool
    {
        return get_bool(config('m1pposu.private_server.enabled')) === true
            && (int) $beatmapset->user_id === 0
            && (int) ($beatmapset->thread_id ?? 0) === 0;
    }

    private static function make2xTemplate(string $template): ?string
    {
        $ret = preg_replace('/(\.[^\/?.]+)(\?.*)?$/', '@2x$1$2', $template);

        return $ret === $template ? null : $ret;
    }
}
