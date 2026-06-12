<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Models\Count;
use App\Models\Country;
use App\Models\Group;
use RuntimeException;

class ReferenceData
{
    private const GROUPS_WITH_RULESETS = ['bng', 'bng_limited'];

    public function defaultGroup(): Group
    {
        $this->ensure();

        return app('groups')->byIdentifier('default')
            ?? throw new RuntimeException('Required default user group could not be initialized.');
    }

    public function ensure(): void
    {
        $this->ensureGroups();
        $this->ensureUnknownCountry();
        Count::totalUsers();
    }

    private function ensureGroups(): void
    {
        foreach (Group::PRIV_IDENTIFIERS as $identifier) {
            Group::firstOrCreate([
                'identifier' => $identifier,
            ], [
                'group_desc' => '',
                'group_name' => $identifier,
                'group_type' => 2,
                'has_playmodes' => in_array($identifier, self::GROUPS_WITH_RULESETS, true),
                'short_name' => $identifier,
            ]);
        }

        app('groups')->resetMemoized();
    }

    private function ensureUnknownCountry(): void
    {
        Country::firstOrCreate([
            'acronym' => Country::UNKNOWN,
        ], [
            'display' => 0,
            'name' => 'Unknown',
            'playcount' => 0,
            'pp' => 0,
            'rankedscore' => 0,
            'shipping_rate' => 1,
            'usercount' => 0,
        ]);

        app('countries')->resetMemoized();
    }
}
