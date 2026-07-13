<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Models\M1pposuAccountImportRequest;
use App\Models\M1pposuOfficialConnection;
use App\Models\User;
use Http;
use Log;

class OfficialImportDiscordNotifier
{
    private const FIELD_VALUE_LIMIT = 1000;

    public function connectionEvent(
        string $event,
        M1pposuOfficialConnection $connection,
        ?M1pposuAccountImportRequest $request = null,
        ?User $actor = null,
        ?string $reason = null,
    ): void
    {
        $fields = [
            'event' => $event,
            'local_user' => "{$connection->user->username} ({$connection->user_id})",
            'official_user' => "{$connection->username} ({$connection->official_user_id})",
            'status' => $request?->status ?? ($connection->restricted_at_connection ? 'restricted' : 'connected'),
            'timestamp' => now()->toIso8601String(),
        ];

        if ($request !== null) {
            $fields['request_id'] = (string) $request->getKey();
            $fields['admin_url'] = in_array($request->status, M1pposuAccountImportRequest::IMPORTED_STATUSES, true)
                ? route('admin.imported-accounts.show', $request)
                : route('admin.official-import-requests.show', $request);
        }

        if ($actor !== null) {
            $fields['actor'] = "{$actor->username} ({$actor->getKey()})";
        }

        if (presence($reason) !== null) {
            $fields['reason'] = $reason;
        }

        $this->send($fields);
    }

    private function send(array $fields): void
    {
        $webhookUrl = presence(config('m1pposu.official_osu.discord_webhook_url'));
        if ($webhookUrl === null) {
            return;
        }

        $embedFields = collect($fields)
            ->map(fn ($value, $key) => [
                'name' => (string) $key,
                'value' => $this->safeFieldValue((string) $value),
                'inline' => false,
            ])
            ->values()
            ->all();

        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->post($webhookUrl, [
                    'allowed_mentions' => ['parse' => []],
                    'content' => $this->safeFieldValue("official osu! import: {$fields['event']}"),
                    'embeds' => [[
                        'fields' => $embedFields,
                        'timestamp' => now()->toIso8601String(),
                        'title' => 'Official osu! import',
                    ]],
                ]);

            if (!$response->successful()) {
                Log::warning('Official osu! import Discord webhook failed.', [
                    'status' => $response->status(),
                    'event' => $fields['event'] ?? null,
                    'request_id' => $fields['request_id'] ?? null,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Official osu! import Discord webhook exception.', [
                'class' => $exception::class,
                'event' => $fields['event'] ?? null,
                'request_id' => $fields['request_id'] ?? null,
            ]);
        }
    }

    private function safeFieldValue(string $value): string
    {
        $value = str_ireplace(['@everyone', '@here'], ['everyone', 'here'], $value);
        $value = preg_replace('/<@!?\d+>|<@&\d+>/', '[mention removed]', $value) ?? $value;

        return mb_substr($value, 0, self::FIELD_VALUE_LIMIT);
    }
}
