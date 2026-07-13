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
    private const COLOR_GREEN = 0x57F287;
    private const COLOR_RED = 0xED4245;

    public function connectionEvent(
        string $event,
        M1pposuOfficialConnection $connection,
        ?M1pposuAccountImportRequest $request = null,
        ?User $actor = null,
        ?string $reason = null,
    ): void
    {
        $fields = [
            'Local account' => $this->markdownLink(
                "{$connection->user->username} ({$connection->user_id})",
                route('users.show', ['user' => $connection->user_id]),
            ),
            'Official account' => $this->markdownLink(
                "{$connection->username} ({$connection->official_user_id})",
                "https://osu.ppy.sh/users/{$connection->official_user_id}",
            ),
            'Status' => $request?->status ?? ($connection->restricted_at_connection ? 'restricted' : 'connected'),
        ];

        if ($request !== null) {
            $fields['Import ID'] = "#{$request->getKey()}";
        }

        if ($actor !== null) {
            $fields['Actor'] = $this->markdownLink(
                "{$actor->username} ({$actor->getKey()})",
                route('users.show', ['user' => $actor->getKey()]),
            );
        }

        if (presence($reason) !== null) {
            $fields['Reason'] = $reason;
        }

        $this->send($event, $request?->getKey(), $fields);
    }

    private function send(string $event, ?int $requestId, array $fields): void
    {
        $webhookUrl = presence(config('m1pposu.official_osu.discord_webhook_url'));
        if ($webhookUrl === null) {
            return;
        }

        $embedFields = collect($fields)
            ->map(fn ($value, $key) => [
                'name' => (string) $key,
                'value' => $this->safeFieldValue((string) $value),
                'inline' => $key !== 'Reason',
            ])
            ->values()
            ->all();

        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->post($webhookUrl, [
                    'allowed_mentions' => ['parse' => []],
                    'embeds' => [[
                        'color' => $this->eventColor($event),
                        'fields' => $embedFields,
                        'footer' => ['text' => 'Official osu! import'],
                        'timestamp' => now()->toIso8601String(),
                        'title' => ucfirst($this->safeFieldValue($event)),
                    ]],
                ]);

            if (!$response->successful()) {
                Log::warning('Official osu! import Discord webhook failed.', [
                    'status' => $response->status(),
                    'event' => $event,
                    'request_id' => $requestId,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Official osu! import Discord webhook exception.', [
                'class' => $exception::class,
                'event' => $event,
                'request_id' => $requestId,
            ]);
        }
    }

    private function eventColor(string $event): int
    {
        $event = strtolower($event);

        foreach (['remove', 'reset', 'unlink', 'disconnect', 'fail', 'denied', 'error'] as $redEvent) {
            if (str_contains($event, $redEvent)) {
                return self::COLOR_RED;
            }
        }

        return self::COLOR_GREEN;
    }

    private function markdownLink(string $label, string $url): string
    {
        $label = str_replace(['[', ']'], ['\\[', '\\]'], $label);

        return "[{$label}]({$url})";
    }

    private function safeFieldValue(string $value): string
    {
        $value = str_ireplace(['@everyone', '@here'], ['everyone', 'here'], $value);
        $value = preg_replace('/<@!?\d+>|<@&\d+>/', '[mention removed]', $value) ?? $value;

        return mb_substr($value, 0, self::FIELD_VALUE_LIMIT);
    }
}
