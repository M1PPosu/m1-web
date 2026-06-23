<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Models\Chat\Channel;
use App\Models\M1pposuExternalUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class LiveChatSynchronizer
{
    private const EVENT_TABLE = 'm1pposu_live_chat_events';
    private const MAX_MESSAGE_LENGTH = 2000;
    private const PUBLIC_READ_PRIVILEGE = 1;

    public function sync(array $event): array
    {
        $eventId = $this->requiredUuid($event, 'event_id');
        $action = $this->requiredAction($event);
        $sourceUserId = $this->requiredPositiveInt($event, 'user_id');
        $channelData = $event['channel'] ?? null;
        if (!is_array($channelData)) {
            throw new RuntimeException('Missing chat channel data.');
        }

        $channelName = $this->requiredString($channelData, 'name', 50);
        $topic = $this->requiredString($channelData, 'topic', Channel::MAX_FIELD_LENGTHS['description'], true);
        $readPrivilege = $this->requiredNonNegativeInt($channelData, 'read_priv');
        $writePrivilege = $this->requiredNonNegativeInt($channelData, 'write_priv');
        $instance = $this->requiredBool($channelData, 'instance');

        if ($instance || $readPrivilege !== self::PUBLIC_READ_PRIVILEGE) {
            return ['chat_events' => 0, 'skipped' => 1];
        }

        if (!str_starts_with($channelName, '#')) {
            throw new RuntimeException("Invalid public chat channel name: {$channelName}.");
        }

        $mapping = M1pposuExternalUser::query()
            ->where('backend', $this->backend())
            ->where('external_user_id', (string) $sourceUserId)
            ->with('user')
            ->first();
        $user = $mapping?->user;
        if ($user === null) {
            Log::warning('Skipped private-server chat event for unmapped user.', [
                'event_id' => $eventId,
                'source_user_id' => $sourceUserId,
                'action' => $action,
                'channel' => $channelName,
            ]);

            return ['chat_events' => 0, 'skipped_unmapped_users' => 1];
        }

        $channel = $this->channel($channelName, $topic, $writePrivilege);

        return match ($action) {
            'join' => $this->join($channel, $user),
            'part' => $this->part($channel, $user),
            'message' => $this->message($event, $eventId, $channel, $user),
        };
    }

    private function channel(string $name, string $topic, int $writePrivilege): Channel
    {
        $channel = Channel::where('name', $name)->first();
        if ($channel !== null && !$channel->isPublic()) {
            throw new RuntimeException("Chat channel {$name} exists with incompatible type {$channel->type}.");
        }

        $channel ??= new Channel(['name' => $name, 'type' => Channel::TYPES['public']]);
        $channel->description = $topic;
        $channel->moderated = $writePrivilege !== self::PUBLIC_READ_PRIVILEGE;
        $channel->saveOrExplode();

        return $channel;
    }

    private function join(Channel $channel, User $user): array
    {
        $channel->addUser($user);

        return ['chat_events' => 1, 'joined' => 1, 'channel_id' => $channel->getKey()];
    }

    private function part(Channel $channel, User $user): array
    {
        $channel->removeUser($user);

        return ['chat_events' => 1, 'parted' => 1, 'channel_id' => $channel->getKey()];
    }

    private function message(array $event, string $eventId, Channel $channel, User $user): array
    {
        $content = $this->requiredString($event, 'message', self::MAX_MESSAGE_LENGTH);
        $occurredAt = $this->requiredTimestamp($event, 'occurred_at');
        $connection = DB::connection('mysql-chat');

        return $connection->transaction(function () use ($channel, $connection, $content, $eventId, $occurredAt, $user) {
            $processed = $connection->table(self::EVENT_TABLE)->where('event_id', $eventId)->first();
            if ($processed !== null) {
                return [
                    'chat_events' => 0,
                    'duplicate' => 1,
                    'message_id' => $processed->message_id === null ? null : (int) $processed->message_id,
                ];
            }

            $channel->addUser($user);
            $message = $channel->receiveMessage(
                $user,
                $content,
                false,
                $eventId,
                self::MAX_MESSAGE_LENGTH,
                false,
                $occurredAt,
            );

            $connection->table(self::EVENT_TABLE)->insert([
                'event_id' => $eventId,
                'action' => 'message',
                'message_id' => $message->getKey(),
                'created_at' => now(),
            ]);

            return [
                'chat_events' => 1,
                'messages' => 1,
                'channel_id' => $channel->getKey(),
                'message_id' => $message->getKey(),
            ];
        });
    }

    private function backend(): string
    {
        return (string) config('m1pposu.private_server.backend');
    }

    private function requiredAction(array $event): string
    {
        $action = $this->requiredString($event, 'action', 16);
        if (!in_array($action, ['join', 'part', 'message'], true)) {
            throw new RuntimeException("Unsupported chat action: {$action}.");
        }

        return $action;
    }

    private function requiredBool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new RuntimeException("Missing boolean chat field: {$key}.");
        }

        return $value;
    }

    private function requiredNonNegativeInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException("Missing non-negative integer chat field: {$key}.");
        }

        return $value;
    }

    private function requiredPositiveInt(array $data, string $key): int
    {
        $value = $this->requiredNonNegativeInt($data, $key);
        if ($value === 0) {
            throw new RuntimeException("Missing positive integer chat field: {$key}.");
        }

        return $value;
    }

    private function requiredString(array $data, string $key, int $maxLength, bool $allowEmpty = false): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || (!$allowEmpty && $value === '') || mb_strlen($value) > $maxLength) {
            throw new RuntimeException("Invalid chat string field: {$key}.");
        }

        return $value;
    }

    private function requiredTimestamp(array $data, string $key): CarbonImmutable
    {
        $value = $this->requiredString($data, $key, 64);

        try {
            return CarbonImmutable::parse($value);
        } catch (\Exception $e) {
            throw new RuntimeException("Invalid chat timestamp field: {$key}.", previous: $e);
        }
    }

    private function requiredUuid(array $data, string $key): string
    {
        $value = $this->requiredString($data, $key, 36);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new RuntimeException("Invalid chat UUID field: {$key}.");
        }

        return strtolower($value);
    }
}
