<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\HasM1pposuCommandLock;
use App\Libraries\M1pposu\SourceMode;
use App\Models\Beatmap;
use App\Models\CountryStatistics;
use App\Models\UserStatistics\Model as UserStatisticsModel;
use App\Singletons\UserCountByRuleset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class M1pposuRankingsRefresh extends Command
{
    use HasM1pposuCommandLock;

    private const MODES = ['osu', 'taiko', 'fruits', 'mania'];
    private const VARIANT_MODES = [
        SourceMode::VARIANT_RELAX => ['osu', 'taiko', 'fruits'],
        SourceMode::VARIANT_AUTOPILOT => ['osu'],
    ];
    private const TYPES = ['performance', 'score'];

    protected $description = 'Refresh projected private-server ranking fields for supported rulesets.';

    protected $signature = 'm1pposu:rankings:refresh
        {--mode= : Refresh one supported ruleset: osu, taiko, fruits, mania}
        {--type= : Refresh one ranking type: performance or score}
        {--variant= : Refresh one supported variant: rx or ap}
        {--dry-run : Show what would be refreshed without writing data}';

    public function handle(): int
    {
        return $this->withM1pposuCommandLock('m1pposu:rankings:refresh', fn () => $this->handleLocked());
    }

    private function handleLocked(): int
    {
        $dryRun = get_bool($this->option('dry-run'));
        $mode = $this->nullableString($this->option('mode'));
        $type = $this->nullableString($this->option('type'));
        $variant = $this->nullableString($this->option('variant'));

        if ($mode !== null && !in_array($mode, self::MODES, true)) {
            $this->error('--mode must be one of: '.implode(', ', self::MODES).'.');

            return static::FAILURE;
        }

        if ($type !== null && !in_array($type, self::TYPES, true)) {
            $this->error('--type must be one of: '.implode(', ', self::TYPES).'.');

            return static::FAILURE;
        }

        if ($variant !== null && !array_key_exists($variant, self::VARIANT_MODES)) {
            $this->error('--variant must be one of: '.implode(', ', array_keys(self::VARIANT_MODES)).'.');

            return static::FAILURE;
        }

        $modeVariants = $this->modeVariants($mode, $variant);
        if ($modeVariants === false) {
            return static::FAILURE;
        }

        $refreshPerformance = $type === null || $type === 'performance';
        $refreshScore = $type === null || $type === 'score';

        $rows = [];
        foreach ($modeVariants as [$ruleset, $variant]) {
            $statsClass = UserStatisticsModel::getClass($ruleset, $variant);
            $statsTable = (new $statsClass())->getTable();
            $rulesetId = Beatmap::modeInt($ruleset);

            $performanceUsers = $this->eligibleRankingQuery($statsTable)
                ->count();
            $scoreUsers = $this->eligibleRankingQuery($statsTable)
                ->where('stats.ranked_score', '>', 0)
                ->count();
            $countries = $variant === null ? $this->eligibleCountries($statsTable) : [];

            if (!$dryRun) {
                if ($refreshPerformance) {
                    $this->refreshPerformanceRanks($statsTable);
                }

                if ($variant === null && ($refreshPerformance || $refreshScore)) {
                    $this->refreshCountryStatistics($rulesetId, $countries);
                }
            }

            $rows[] = [
                $ruleset,
                $variant ?? 'standard',
                $performanceUsers,
                $scoreUsers,
                count($countries),
            ];
        }

        if (!$dryRun) {
            UserCountByRuleset::update();
        }

        $this->info($dryRun ? 'Ranking refresh dry-run summary:' : 'Ranking refresh summary:');
        $this->table(
            ['ruleset', 'variant', 'performance-ranked users', 'score users', 'countries refreshed'],
            $rows,
        );

        if ($refreshScore) {
            $this->line('Score rankings use ranked_score directly at query time; no separate score rank field is stored.');
        }

        if ($dryRun) {
            $this->line('No ranking data was modified.');
        }

        return static::SUCCESS;
    }

    private function modeVariants(?string $mode, ?string $variant): array|false
    {
        $modes = $mode === null
            ? ($variant === null ? self::MODES : self::VARIANT_MODES[$variant])
            : [$mode];
        $modeVariants = [];

        foreach ($modes as $ruleset) {
            if ($variant === null) {
                $modeVariants[] = [$ruleset, null];

                foreach (self::VARIANT_MODES as $supportedVariant => $supportedModes) {
                    if (in_array($ruleset, $supportedModes, true)) {
                        $modeVariants[] = [$ruleset, $supportedVariant];
                    }
                }
            } elseif (in_array($ruleset, self::VARIANT_MODES[$variant], true)) {
                $modeVariants[] = [$ruleset, $variant];
            } else {
                $this->error("Variant {$variant} is not available for {$ruleset}.");

                return false;
            }
        }

        return $modeVariants;
    }

    private function eligibleRankingQuery(string $statsTable)
    {
        return DB::table("{$statsTable} AS stats")
            ->join('phpbb_users AS users', 'users.user_id', '=', 'stats.user_id')
            ->where('stats.rank_score', '>', 0)
            ->where('users.user_warnings', 0)
            ->where('users.user_type', 0);
    }

    private function eligibleCountries(string $statsTable): array
    {
        return $this->eligibleRankingQuery($statsTable)
            ->whereNotNull('stats.country_acronym')
            ->where('stats.country_acronym', '<>', '')
            ->distinct()
            ->orderBy('stats.country_acronym')
            ->pluck('stats.country_acronym')
            ->all();
    }

    private function refreshCountryStatistics(int $rulesetId, array $countries): void
    {
        foreach ($countries as $country) {
            CountryStatistics::recalculate($country, $rulesetId);
        }

        $staleQuery = CountryStatistics::where('mode', $rulesetId);

        if ($countries === []) {
            $staleQuery->delete();
        } else {
            $staleQuery->whereNotIn('country_code', $countries)->delete();
        }
    }

    private function refreshPerformanceRanks(string $statsTable): void
    {
        $statsTable = $this->quoteIdentifier($statsTable);
        $usersTable = $this->quoteIdentifier('phpbb_users');

        DB::update("
            UPDATE {$statsTable} AS stats
            LEFT JOIN (
                SELECT ranked_stats.user_id,
                    ROW_NUMBER() OVER (
                        ORDER BY ranked_stats.rank_score DESC,
                            ranked_stats.ranked_score DESC,
                            ranked_stats.user_id ASC
                    ) AS rank_index
                FROM {$statsTable} AS ranked_stats
                INNER JOIN {$usersTable} AS users ON users.user_id = ranked_stats.user_id
                WHERE ranked_stats.rank_score > 0
                    AND users.user_warnings = 0
                    AND users.user_type = 0
            ) AS ranked ON ranked.user_id = stats.user_id
            SET stats.rank_score_index = COALESCE(ranked.rank_index, 0)
        ");
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
