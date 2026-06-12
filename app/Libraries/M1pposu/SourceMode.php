<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Models\Beatmap;

final class SourceMode
{
    public const VARIANT_RELAX = 'rx';
    public const VARIANT_AUTOPILOT = 'ap';

    private const MODES = [
        0 => ['ruleset' => 'osu', 'variant' => null],
        1 => ['ruleset' => 'taiko', 'variant' => null],
        2 => ['ruleset' => 'fruits', 'variant' => null],
        3 => ['ruleset' => 'mania', 'variant' => null],
        4 => ['ruleset' => 'osu', 'variant' => self::VARIANT_RELAX],
        5 => ['ruleset' => 'taiko', 'variant' => self::VARIANT_RELAX],
        6 => ['ruleset' => 'fruits', 'variant' => self::VARIANT_RELAX],
        8 => ['ruleset' => 'osu', 'variant' => self::VARIANT_AUTOPILOT],
    ];

    public static function mode(int|string $sourceMode): ?array
    {
        return self::MODES[(int) $sourceMode] ?? null;
    }

    public static function ruleset(int|string $sourceMode): ?string
    {
        return self::mode($sourceMode)['ruleset'] ?? null;
    }

    public static function rulesetId(int|string $sourceMode): ?int
    {
        $ruleset = self::ruleset($sourceMode);

        return $ruleset === null ? null : Beatmap::modeInt($ruleset);
    }

    public static function sourceMode(string $ruleset, ?string $variant): ?int
    {
        foreach (self::MODES as $sourceMode => $mode) {
            if ($mode['ruleset'] === $ruleset && $mode['variant'] === $variant) {
                return $sourceMode;
            }
        }

        return null;
    }

    public static function variant(int|string $sourceMode): ?string
    {
        return self::mode($sourceMode)['variant'] ?? null;
    }

    public static function supportedSourceModes(): array
    {
        return array_keys(self::MODES);
    }
}
