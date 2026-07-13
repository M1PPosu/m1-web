<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Controllers;

use App\Mail\UserEmailUpdated;
use App\Mail\UserPasswordUpdated;
use App\Models\Country;
use App\Models\M1pposuOfficialConnection;
use App\Models\User;
use App\Models\UserCoverPreset;
use App\Models\UserProfileCustomization;
use App\Models\WeakPassword;
use Database\Factories\UserFactory;
use Hash;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mail;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    private $user;

    /**
     * Checks whether an OK status is returned when the
     * profile order update request is valid.
     */
    public function testValidProfileOrderChangeRequest()
    {
        $newOrder = UserProfileCustomization::SECTIONS;
        seeded_shuffle($newOrder);

        $this->actingAsVerified($this->user())
            ->json('PUT', route('account.options'), [
                'order' => $newOrder,
            ])
            ->assertJsonFragment(['profile_order' => $newOrder]);
    }

    public function testDuplicatesInProfileOrder()
    {
        $newOrder = UserProfileCustomization::SECTIONS;

        $newOrderWithDuplicate = $newOrder;
        $newOrderWithDuplicate[] = $newOrder[0];

        $this->actingAsVerified($this->user())
            ->json('PUT', route('account.options'), [
                'order' => $newOrderWithDuplicate,
            ])
            ->assertJsonFragment(['profile_order' => $newOrder]);
    }

    public function testInvalidIdsInProfileOrder()
    {
        $newOrder = UserProfileCustomization::SECTIONS;

        $newOrderWithInvalid = $newOrder;
        $newOrderWithInvalid[] = 'test';

        $this->actingAsVerified($this->user())
            ->json('PUT', route('account.options'), [
                'order' => $newOrderWithInvalid,
            ])
            ->assertJsonFragment(['profile_order' => $newOrder]);
    }

    /**
     * @dataProvider dataProviderForUpdateCountry
     * @group RequiresScoreIndexer
     *
     * More complete tests are done through CountryChange and CountryChangeTarget.
     */
    public function testUpdateCountry(?string $historyCountry, ?string $targetCountry, bool $success): void
    {
        $user = $this->user();
        foreach (array_unique([$historyCountry, $targetCountry]) as $country) {
            if ($country !== null) {
                Country::factory()->create(['acronym' => $country]);
            }
        }
        if ($historyCountry !== null) {
            UserFactory::createRecentCountryHistory($user, $historyCountry, null);
        }

        $resultCountry = $success ? $targetCountry : $user->country_acronym;

        $this->actingAsVerified($user)
            ->json('PUT', route('account.country', ['country_acronym' => $targetCountry]))
            ->assertStatus($success ? 200 : 403);

        $this->assertSame($user->fresh()->country_acronym, $resultCountry);
    }

    public function testUpdateEmail()
    {
        $newEmail = 'new-'.$this->user->user_email;

        Mail::fake();

        $this->actingAsVerified($this->user())
            ->json('PUT', route('account.email'), [
                'user' => [
                    'current_password' => 'password',
                    'user_email' => $newEmail,
                    'user_email_confirmation' => $newEmail,
                ],
            ])
            ->assertSuccessful();

        $this->assertSame($newEmail, $this->user->fresh()->user_email);

        Mail::assertQueued(UserEmailUpdated::class, 2);
    }

    public function testUpdateEmailLocked()
    {
        $newEmail = 'new-'.$this->user->user_email;
        $this->user->update(['lock_email_changes' => true]);

        $this->actingAsVerified($this->user())
            ->json('PUT', route('account.email'), [
                'user' => [
                    'current_password' => 'password',
                    'user_email' => $newEmail,
                    'user_email_confirmation' => $newEmail,
                ],
            ])
            ->assertStatus(403);
    }

    public function testUpdateEmailInvalidPassword()
    {
        $newEmail = 'new-'.$this->user->user_email;

        $this->actingAsVerified($this->user())
            ->json('PUT', route('account.email'), [
                'user' => [
                    'current_password' => 'password1',
                    'user_email' => $newEmail,
                    'user_email_confirmation' => $newEmail,
                ],
            ])
            ->assertStatus(422);
    }

    public function testUpdatePassword()
    {
        $newPassword = 'newpassword';

        Mail::fake();

        $this->actingAsVerified($this->user())
            ->json('PUT', route('account.password'), [
                'user' => [
                    'current_password' => 'password',
                    'password' => $newPassword,
                    'password_confirmation' => $newPassword,
                ],
            ])
            ->assertSuccessful();

        $this->assertTrue(Hash::check($newPassword, $this->user->fresh()->user_password));

        Mail::assertQueued(UserPasswordUpdated::class);
    }

    public function testUpdatePasswordInvalidCurrentPassword()
    {
        $this->actingAsVerified($this->user())
            ->json('PUT', route('account.password'), [
                'user' => [
                    'current_password' => 'notpassword',
                    'password' => 'newpassword',
                    'password_confirmation' => 'newpassword',
                ],
            ])
            ->assertStatus(422);
    }

    public function testUpdatePasswordInvalidPasswordConfirmation()
    {
        $this->actingAsVerified($this->user())
            ->json('PUT', route('account.password'), [
                'user' => [
                    'current_password' => 'password',
                    'password' => 'newpassword',
                    'password_confirmation' => 'oldpassword',
                ],
            ])
            ->assertStatus(422);
    }

    public function testUpdatePasswordUsernameAsPassword()
    {
        $this->actingAsVerified($this->user())
            ->json('PUT', route('account.password'), [
                'user' => [
                    'current_password' => 'password',
                    'password' => $this->user->username,
                    'password_confirmation' => $this->user->username,
                ],
            ])
            ->assertStatus(422);
    }

    public function testUpdatePasswordShortPassword()
    {
        $this->actingAsVerified($this->user())
            ->json('PUT', route('account.password'), [
                'user' => [
                    'current_password' => 'password',
                    'password' => '1234567',
                    'password_confirmation' => '1234567',
                ],
            ])
            ->assertStatus(422);
    }

    public function testUpdatePasswordWeakPassword()
    {
        $weakPassword = 'weakpassword';

        WeakPassword::add($weakPassword);

        $this->actingAsVerified($this->user())
            ->json('PUT', route('account.password'), [
                'user' => [
                    'current_password' => 'password',
                    'password' => $weakPassword,
                    'password_confirmation' => $weakPassword,
                ],
            ])
            ->assertStatus(422);
    }

    public function testUpdateDefaultPlaymodeVariant(): void
    {
        $this->actingAsVerified($this->user())
            ->json('PUT', route('account.options'), [
                'user' => [
                    'playmode' => 'osu',
                    'playmode_variant' => 'rx',
                ],
            ])
            ->assertSuccessful()
            ->assertJsonPath('playmode', 'osu')
            ->assertJsonPath('playmode_variant', 'rx');

        $this->assertSame('rx', $this->user->fresh()->playmode_variant);
    }

    public function testUpdateDefaultPlaymodeRejectsUnsupportedVariant(): void
    {
        $this->actingAsVerified($this->user())
            ->json('PUT', route('account.options'), [
                'user' => [
                    'playmode' => 'mania',
                    'playmode_variant' => '4k',
                ],
            ])
            ->assertStatus(422);
    }

    public function testAvatarUpdateMarksOnlyOfficialAvatarOverride(): void
    {
        Storage::fake('local-avatar');
        $connection = $this->createOfficialConnection();

        $this->actingAsVerified($this->user())
            ->post(route('account.avatar'), [
                'avatar_file' => UploadedFile::fake()->image('avatar.png', 128, 128),
            ])
            ->assertSuccessful();

        $connection->refresh();
        $this->assertNotNull($connection->imported_avatar_overridden_at);
        $this->assertNull($connection->imported_cover_overridden_at);
        $this->assertNull($connection->imported_userpage_overridden_at);
    }

    public function testCoverUpdateMarksOnlyOfficialCoverOverride(): void
    {
        $connection = $this->createOfficialConnection();
        $preset = UserCoverPreset::create([
            'active' => true,
            'filename' => 'preset.jpg',
        ]);

        $this->actingAsVerified($this->user())
            ->post(route('account.cover'), ['cover_id' => $preset->getKey()])
            ->assertSuccessful();

        $connection->refresh();
        $this->assertNull($connection->imported_avatar_overridden_at);
        $this->assertNotNull($connection->imported_cover_overridden_at);
        $this->assertNull($connection->imported_userpage_overridden_at);
    }

    public static function dataProviderForUpdateCountry(): array
    {
        return [
            ['_A', '_A', true],
            ['_B', '_A', false],
            [null, null, false],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function user()
    {
        // To reset all the verify toggles.
        return $this->user->fresh();
    }

    private function createOfficialConnection(): M1pposuOfficialConnection
    {
        return M1pposuOfficialConnection::create([
            'user_id' => $this->user->getKey(),
            'official_user_id' => 990000001,
            'username' => 'OfficialUser',
            'avatar_url' => 'https://a.ppy.sh/990000001',
            'cover_url' => 'https://assets.ppy.sh/user-profile-covers/990000001/cover.jpg',
            'restricted_at_connection' => false,
            'refresh_token' => null,
            'token_metadata' => null,
            'connected_at' => now(),
        ]);
    }
}
