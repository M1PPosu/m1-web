<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Models\M1pposuExternalUser;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class LivePresence
{
    private const CACHE_KEY = 'm1pposu:live-presence:v1';

    public function isUserOnline(User $user): bool
    {
        if ($user->hide_presence) {
            return false;
        }

        return in_array($user->getKey(), $this->snapshot()['user_ids'], true);
    }

    /**
     * @return array{available: bool, current_online: int, total_users: int|null, user_ids: int[]}
     */
    public function snapshot(): array
    {
        if (!get_bool(config('m1pposu.features.presence') ?? false)) {
            return $this->unavailable();
        }

        $cacheSeconds = (int) config('m1pposu.private_server.presence.cache_seconds', 5);

        return Cache::remember(self::CACHE_KEY, $cacheSeconds, function (): array {
            try {
                return $this->fetch();
            } catch (Throwable $e) {
                Log::warning('Bancho live presence request failed.', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                return $this->unavailable();
            }
        });
    }

    /**
     * @return array{available: bool, current_online: int, total_users: int|null, user_ids: int[]}
     */
    private function fetch(): array
    {
        $baseUrl = rtrim((string) config('m1pposu.private_server.presence.base_url'), '/');
        $hostHeader = (string) config('m1pposu.private_server.presence.host_header');
        $timeout = (float) config('m1pposu.private_server.presence.timeout_seconds', 2);

        $request = Http::acceptJson()
            ->withHeaders(['Host' => $hostHeader])
            ->connectTimeout(min($timeout, 1.0))
            ->timeout($timeout);

        $countResponse = $request->get("{$baseUrl}/get_player_count");
        $onlineResponse = $request->get("{$baseUrl}/online");

        if (!$countResponse->successful() || !$onlineResponse->successful()) {
            throw new RuntimeException(sprintf(
                'Bancho API returned HTTP %d and %d.',
                $countResponse->status(),
                $onlineResponse->status(),
            ));
        }

        $counts = $countResponse->json();
        $online = $onlineResponse->json();

        if (
            ($counts['status'] ?? null) !== 'success'
            || !is_array($counts['counts'] ?? null)
            || filter_var($counts['counts']['online'] ?? null, FILTER_VALIDATE_INT) === false
            || filter_var($counts['counts']['total'] ?? null, FILTER_VALIDATE_INT) === false
            || ($online['status'] ?? null) !== 'success'
            || !is_array($online['players'] ?? null)
        ) {
            throw new RuntimeException('Bancho API returned an invalid presence payload.');
        }

        $externalUserIds = collect($online['players'])
            ->pluck('id')
            ->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false)
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();

        $userIds = $externalUserIds === []
            ? []
            : M1pposuExternalUser::query()
                ->where('backend', config('m1pposu.private_server.backend'))
                ->whereIn('external_user_id', $externalUserIds)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

        return [
            'available' => true,
            'current_online' => max(0, (int) $counts['counts']['online']),
            'total_users' => max(0, (int) $counts['counts']['total']),
            'user_ids' => $userIds,
        ];
    }

    /**
     * @return array{available: false, current_online: 0, total_users: null, user_ids: array{}}
     */
    private function unavailable(): array
    {
        return [
            'available' => false,
            'current_online' => 0,
            'total_users' => null,
            'user_ids' => [],
        ];
    }
}
