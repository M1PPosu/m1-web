<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Commands;

use App\Models\M1pposuExternalUser;
use App\Models\M1pposuOfficialConnection;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class M1pposuSyncUserAssetsTest extends TestCase
{
    private string $sourceDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDirectory = sys_get_temp_dir().'/m1pposu-user-assets-'.bin2hex(random_bytes(8));
        (new Filesystem())->makeDirectory($this->sourceDirectory);

        Storage::fake('m1pposu-user-assets-test');
        config()->set('m1pposu.imported_assets_disk', 'm1pposu-user-assets-test');
        config()->set('m1pposu.private_server.backend', 'test-source');
        config()->set('m1pposu.users.source_avatar_path', $this->sourceDirectory);
        config()->set('m1pposu.users.source_cover_path', $this->sourceDirectory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->sourceDirectory);

        parent::tearDown();
    }

    public function testAvatarSyncDoesNotReplaceMarkedManualCustomization(): void
    {
        $user = User::factory()->create(['user_avatar' => 'native-avatar.png']);
        $this->createConnection($user, ['imported_avatar_overridden_at' => now()]);
        $this->createMapping($user);

        $this->artisan('m1pposu:sync:user-assets', [
            'type' => 'avatars',
            '--external-id' => '1001',
        ])
            ->expectsOutputToContain('skipped native customization: 1')
            ->assertSuccessful();

        $this->assertSame('native-avatar.png', $user->fresh()->getRawAttribute('user_avatar'));
    }

    public function testExistingNativeAvatarWithoutOverrideDoesNotBlockSync(): void
    {
        $user = User::factory()->create(['user_avatar' => 'native-avatar.png']);
        $this->createConnection($user);
        $this->createMapping($user);

        $this->artisan('m1pposu:sync:user-assets', [
            'type' => 'avatars',
            '--external-id' => '1001',
        ])
            ->expectsOutputToContain('skipped native customization: 0')
            ->expectsOutputToContain('skipped missing source file: 1')
            ->assertSuccessful();
    }

    public function testCoverSyncDoesNotReplaceMarkedManualCustomization(): void
    {
        $user = User::factory()->create([
            'cover_preset_id' => null,
            'custom_cover_filename' => 'native-cover.jpg',
        ]);
        $this->createConnection($user, ['imported_cover_overridden_at' => now()]);
        $this->createMapping($user);

        $this->artisan('m1pposu:sync:user-assets', [
            'type' => 'covers',
            '--external-id' => '1001',
        ])
            ->expectsOutputToContain('skipped native customization: 1')
            ->assertSuccessful();

        $user->refresh();
        $this->assertNull($user->getRawAttribute('cover_preset_id'));
        $this->assertSame('native-cover.jpg', $user->getRawAttribute('custom_cover_filename'));
    }

    private function createMapping(User $user): void
    {
        M1pposuExternalUser::create([
            'backend' => 'test-source',
            'external_user_id' => '1001',
            'external_username' => 'source-user',
            'user_id' => $user->getKey(),
        ]);
    }

    private function createConnection(User $user, array $attributes = []): void
    {
        M1pposuOfficialConnection::create([
            'user_id' => $user->getKey(),
            'official_user_id' => 990000001,
            'username' => 'OfficialUser',
            'avatar_url' => null,
            'cover_url' => null,
            'restricted_at_connection' => false,
            'refresh_token' => null,
            'token_metadata' => null,
            'connected_at' => now(),
            ...$attributes,
        ]);
    }
}
