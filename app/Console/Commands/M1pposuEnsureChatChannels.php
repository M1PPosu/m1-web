<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Chat\Channel;
use Illuminate\Console\Command;

class M1pposuEnsureChatChannels extends Command
{
    private const CHANNELS = [
        '#m1pposu' => 'M1PPosu server discussion',
        '#general' => 'General discussion',
        '#osu' => 'osu! discussion',
        '#english' => 'English language discussion',
        '#polski' => 'Polski kanal dyskusyjny',
        '#deutsch' => 'Deutschsprachiger Chat',
        '#espanol' => 'Canal de conversacion en espanol',
        '#francais' => 'Canal de discussion francophone',
        '#portugues' => 'Canal de conversa em portugues',
        '#russian' => 'Russian language discussion',
        '#turkish' => 'Turkish language discussion',
        '#indonesian' => 'Indonesian language discussion',
        '#japanese' => 'Japanese language discussion',
        '#korean' => 'Korean language discussion',
        '#chinese' => 'Chinese language discussion',
    ];

    protected $description = 'Ensure the production public chat channels exist.';
    protected $signature = 'm1pposu:chat:ensure-defaults';

    public function handle(): int
    {
        foreach (self::CHANNELS as $name => $description) {
            $existing = Channel::where('name', $name)->first();
            if ($existing !== null && !$existing->isPublic()) {
                $this->error("Chat channel {$name} already exists with type {$existing->type}.");

                return static::FAILURE;
            }

            $channel = $existing ?? new Channel(['name' => $name]);
            $channel->type = Channel::TYPES['public'];
            $channel->description = $description;
            $channel->saveOrExplode();
            $this->line(($existing === null ? 'Created ' : 'Verified ').$name);
        }

        return static::SUCCESS;
    }
}
