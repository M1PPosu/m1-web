<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use JsonException;
use RuntimeException;

final class LiveEventProcessor
{
    private const USER_CHANNELS = [
        'addpriv',
        'country_change',
        'givedonator',
        'name_change',
        'removepriv',
        'restrict',
        'unrestrict',
    ];

    public function __construct(private readonly LiveSynchronizer $synchronizer)
    {
    }

    public function handle(string $channel, string $payload): array
    {
        try {
            $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON received on {$channel}.", previous: $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException("Invalid event payload received on {$channel}.");
        }

        return match ($channel) {
            'ex:submit' => $this->synchronizer->syncScoreIds([$this->requiredId($data, 'id', $channel)]),
            'rank' => $this->synchronizer->syncMapIds([$this->requiredId($data, 'beatmap_id', $channel)]),
            'ex:map_status_change' => $this->synchronizer->syncMapIds($this->requiredIds($data, 'map_ids', $channel)),
            'wipe' => $this->synchronizer->syncWipe(
                $this->requiredId($data, 'id', $channel),
                $this->requiredNonNegativeInt($data, 'mode', $channel),
            ),
            'clan_change' => $this->synchronizer->syncClanChange(
                $this->requiredId($data, 'id', $channel),
                $this->requiredNonNegativeInt($data, 'affected_clan_id', $channel),
                (bool) ($data['deleted'] ?? false),
            ),
            default => in_array($channel, self::USER_CHANNELS, true)
                ? $this->synchronizer->syncUserIds([$this->requiredId($data, 'id', $channel)])
                : throw new RuntimeException("Unsupported private-server live channel: {$channel}."),
        };
    }

    private function requiredId(array $data, string $key, string $channel): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new RuntimeException("Missing positive integer {$key} on {$channel}.");
        }

        $value = (int) $value;
        if ($value <= 0) {
            throw new RuntimeException("Missing positive integer {$key} on {$channel}.");
        }

        return $value;
    }

    private function requiredNonNegativeInt(array $data, string $key, string $channel): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new RuntimeException("Missing non-negative integer {$key} on {$channel}.");
        }

        $value = (int) $value;
        if ($value < 0) {
            throw new RuntimeException("Missing non-negative integer {$key} on {$channel}.");
        }

        return $value;
    }

    private function requiredIds(array $data, string $key, string $channel): array
    {
        $values = $data[$key] ?? null;
        if (!is_array($values)) {
            throw new RuntimeException("Missing integer list {$key} on {$channel}.");
        }

        $ids = array_map(fn ($value) => $this->requiredId([$key => $value], $key, $channel), $values);
        if ($ids === []) {
            throw new RuntimeException("Missing integer list {$key} on {$channel}.");
        }

        return array_values(array_unique($ids));
    }
}
