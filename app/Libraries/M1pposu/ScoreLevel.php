<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

final class ScoreLevel
{
    private const SCORE_PER_LEVEL_AFTER_100 = 99999999999;
    private const SCORE_AT_LEVEL_100 = 26931190827;

    public static function fromTotalScore(int $totalScore): float
    {
        $totalScore = max(0, $totalScore);

        if ($totalScore >= self::SCORE_AT_LEVEL_100) {
            return 100 + (($totalScore - self::SCORE_AT_LEVEL_100) / self::SCORE_PER_LEVEL_AFTER_100);
        }

        $thresholds = self::thresholdsThroughLevel100();
        for ($level = 1; $level < 100; $level++) {
            $nextThreshold = $thresholds[$level + 1];
            if ($totalScore < $nextThreshold) {
                return $level + (($totalScore - $thresholds[$level]) / ($nextThreshold - $thresholds[$level]));
            }
        }

        return 100.0;
    }

    /**
     * osu! rounds each score difference between levels before accumulating it.
     *
     * @return array<int, int>
     */
    private static function thresholdsThroughLevel100(): array
    {
        static $thresholds;

        if ($thresholds !== null) {
            return $thresholds;
        }

        $thresholds = [1 => 0];
        for ($level = 1; $level < 100; $level++) {
            $thresholds[$level + 1] = $thresholds[$level] + (int) round(
                self::rawScoreForLevel($level + 1) - self::rawScoreForLevel($level)
            );
        }

        return $thresholds;
    }

    private static function rawScoreForLevel(int $level): float
    {
        return 5000 / 3 * ((4 * $level ** 3) - (3 * $level ** 2) - $level)
            + (1.25 * 1.8 ** ($level - 60));
    }
}
