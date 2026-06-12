<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Libraries\M1pposu;

use App\Libraries\M1pposu\ImportedAssets;
use App\Libraries\User\AvatarHelper;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportedAssetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('m1pposu-assets-test');
        config()->set('m1pposu.imported_assets_disk', 'm1pposu-assets-test');
        config()->set('m1pposu.imported_assets_base_url', 'https://assets.example.test');
    }

    public function testImportedAssetUrlsAreUsedByModels(): void
    {
        $user = new User();
        $user->setRawAttributes([
            'user_id' => 123,
            'user_avatar' => ImportedAssets::marker('m1pposu/users/avatars/123.png'),
            'cover_preset_id' => null,
            'custom_cover_filename' => ImportedAssets::marker('m1pposu/users/covers/123.jpeg'),
        ]);

        $team = new Team();
        $team->setRawAttributes([
            'id' => 45,
            'flag_file' => ImportedAssets::marker('m1pposu/teams/flags/45.png'),
            'header_file' => ImportedAssets::marker('m1pposu/teams/headers/45.jpeg'),
        ]);

        $this->assertSame('https://assets.example.test/m1pposu/users/avatars/123.png', AvatarHelper::url($user));
        $this->assertSame('https://assets.example.test/m1pposu/users/covers/123.jpeg', $user->cover()->url());
        $this->assertSame('https://assets.example.test/m1pposu/teams/flags/45.png', $team->flagUrl());
        $this->assertSame('https://assets.example.test/m1pposu/teams/headers/45.jpeg', $team->headerUrl());
    }

    public function testStorageIsIdempotentAndRejectsTraversalMarkers(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'm1pposu-asset-test-');
        file_put_contents($source, 'asset-data');

        try {
            $path = 'm1pposu/users/avatars/123.png';
            ImportedAssets::putPublicFile($path, $source);

            $this->assertTrue(ImportedAssets::matchesFile($path, $source));
            $this->assertSame($path, ImportedAssets::pathFromMarker(ImportedAssets::marker($path)));
            $this->assertNull(ImportedAssets::pathFromMarker('m1pposu-imported:../../.env'));
            Storage::disk('m1pposu-assets-test')->assertExists($path);
        } finally {
            @unlink($source);
        }
    }
}
