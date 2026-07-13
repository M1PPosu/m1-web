<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use GuzzleHttp\Client;

class OfficialOsuClient
{
    public const ACTIVITY_PAGE_LIMIT = 100;
    public const BEATMAPSET_PAGE_LIMIT = 100;
    public const MAX_PAGES = 100;
    public const SCORE_PAGE_LIMIT = 100;

    private const API_BASE = 'https://osu.ppy.sh/api/v2';
    private const OAUTH_AUTHORIZE = 'https://osu.ppy.sh/oauth/authorize';
    private const OAUTH_TOKEN = 'https://osu.ppy.sh/oauth/token';
    private const SCOPES = 'identify public';

    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'connect_timeout' => 5,
            'timeout' => 20,
        ]);
    }

    public static function canAuthenticate(): bool
    {
        return presence(config('m1pposu.official_osu.oauth.client_id')) !== null
            && presence(config('m1pposu.official_osu.oauth.client_secret')) !== null;
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return static::OAUTH_AUTHORIZE.'?'.http_build_query([
            'client_id' => config('m1pposu.official_osu.oauth.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => static::SCOPES,
            'state' => $state,
        ]);
    }

    public function tokenFromCode(string $code, string $redirectUri): array
    {
        return $this->requestToken([
            'client_id' => config('m1pposu.official_osu.oauth.client_id'),
            'client_secret' => config('m1pposu.official_osu.oauth.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);
    }

    public function tokenFromRefreshToken(string $refreshToken): array
    {
        return $this->requestToken([
            'client_id' => config('m1pposu.official_osu.oauth.client_id'),
            'client_secret' => config('m1pposu.official_osu.oauth.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    public function me(string $accessToken, ?string $mode = null): array
    {
        $path = $mode === null ? '/me' : "/me/{$mode}";

        return $this->get($accessToken, $path);
    }

    public function userActivity(string $accessToken, int $officialUserId): array
    {
        return $this->getPaginated($accessToken, "/users/{$officialUserId}/recent_activity", [], static::ACTIVITY_PAGE_LIMIT);
    }

    public function userBeatmapsets(string $accessToken, int $officialUserId, string $type): array
    {
        return $this->getPaginated($accessToken, "/users/{$officialUserId}/beatmapsets/{$type}", [], static::BEATMAPSET_PAGE_LIMIT);
    }

    public function userScores(string $accessToken, int $officialUserId, string $kind, string $mode): array
    {
        return $this->getPaginated($accessToken, "/users/{$officialUserId}/scores/{$kind}", [
            'include_fails' => 0,
            'legacy_only' => 1,
            'mode' => $mode,
        ], static::SCORE_PAGE_LIMIT);
    }

    private function get(string $accessToken, string $path, array $query = []): array
    {
        $response = $this->http->request('GET', static::API_BASE.$path, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => "Bearer {$accessToken}",
            ],
            'query' => $query,
        ]);

        return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function getPaginated(string $accessToken, string $path, array $query, int $limit): array
    {
        $items = [];
        $seen = [];

        for ($page = 0; $page < static::MAX_PAGES; $page++) {
            $pageItems = $this->get($accessToken, $path, [
                ...$query,
                'limit' => $limit,
                'offset' => $page * $limit,
            ]);

            if (!$this->isList($pageItems)) {
                return $pageItems;
            }

            if ($pageItems === []) {
                break;
            }

            $newItems = 0;
            foreach ($pageItems as $item) {
                if (is_array($item)) {
                    $key = $this->pageItemKey($item);
                    if ($key !== null) {
                        if (isset($seen[$key])) {
                            continue;
                        }

                        $seen[$key] = true;
                    }
                }

                $items[] = $item;
                $newItems++;
            }

            if (count($pageItems) < $limit || $newItems === 0) {
                break;
            }
        }

        return $items;
    }

    private function isList(array $items): bool
    {
        return $items === [] || array_keys($items) === range(0, count($items) - 1);
    }

    private function pageItemKey(array $item): ?string
    {
        $id = $item['id']
            ?? $item['beatmap_id']
            ?? ($item['beatmap']['id'] ?? null)
            ?? ($item['beatmapset']['id'] ?? null);

        if ($id !== null) {
            return (string) $id;
        }

        try {
            return json_encode($item, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private function requestToken(array $formParams): array
    {
        $response = $this->http->request('POST', static::OAUTH_TOKEN, [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => $formParams,
        ]);

        return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
    }
}
