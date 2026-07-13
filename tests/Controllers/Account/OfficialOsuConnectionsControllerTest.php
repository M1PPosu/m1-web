<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Controllers\Account;

use App\Models\M1pposuAccountImportRequest;
use App\Models\M1pposuAccountImportSnapshot;
use App\Models\M1pposuOfficialConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Queue;
use Tests\TestCase;

class OfficialOsuConnectionsControllerTest extends TestCase
{
    public function testDisconnectAllowedBeforeImport(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->createConnection($user);

        $this->withPersistentSession($this->createVerifiedSession($user))
            ->delete(route('account.official-osu.destroy'))
            ->assertNoContent();

        $this->assertDatabaseMissing('m1pposu_official_connections', [
            'user_id' => $user->getKey(),
        ]);
    }

    public function testDisconnectAfterImportRequiresConfirmation(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);
        M1pposuAccountImportRequest::create([
            'applied_at' => now(),
            'connection_id' => $connection->getKey(),
            'reviewed_at' => now(),
            'snapshot_id' => $snapshot->getKey(),
            'status' => M1pposuAccountImportRequest::STATUS_APPLIED,
            'restricted_at_request' => false,
            'user_id' => $user->getKey(),
            'official_user_id' => $connection->official_user_id,
        ]);

        $this->withPersistentSession($this->createVerifiedSession($user))
            ->delete(route('account.official-osu.destroy'))
            ->assertStatus(422);

        $this->assertDatabaseHas('m1pposu_official_connections', [
            'id' => $connection->getKey(),
            'user_id' => $user->getKey(),
        ]);
    }

    public function testConfirmedSelfRemoveAfterImportHidesImportAndBlocksManualReimport(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);
        $request = $this->createAppliedRequest($connection, $snapshot);
        $session = $this->createVerifiedSession($user);

        $this->withPersistentSession($session)
            ->withHeader('X-CSRF-TOKEN', $session->token())
            ->delete(route('account.official-osu.destroy'), ['confirmed' => 1])
            ->assertSuccessful()
            ->assertJson(['status' => M1pposuAccountImportRequest::STATUS_SELF_REMOVED]);

        $this->assertDatabaseHas('m1pposu_account_import_requests', [
            'id' => $request->getKey(),
            'status' => M1pposuAccountImportRequest::STATUS_SELF_REMOVED,
            'removed_by' => $user->getKey(),
        ]);
        $this->assertDatabaseHas('m1pposu_official_connections', [
            'id' => $connection->getKey(),
            'user_id' => $user->getKey(),
        ]);
        $this->assertFalse($user->fresh()->isRestricted());

        $this->withPersistentSession($session)
            ->withHeader('X-CSRF-TOKEN', $session->token())
            ->post(route('account.official-osu.import'), ['confirmed' => 1])
            ->assertStatus(422);
    }

    public function testRestrictedOfficialAccountCanAutoImportWithAdminFlag(): void
    {
        Queue::fake();
        config_set('m1pposu.official_osu.discord_webhook_url', 'https://discord.test/webhook');
        Http::fake([
            'https://discord.test/webhook' => Http::response([], 204),
        ]);

        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $connection->update(['restricted_at_connection' => true]);
        $this->createSnapshot($connection);
        $session = $this->createVerifiedSession($user);

        $this->withPersistentSession($session)
            ->withHeader('X-CSRF-TOKEN', $session->token())
            ->post(route('account.official-osu.import'), ['confirmed' => 1])
            ->assertSuccessful()
            ->assertJson(['status' => M1pposuAccountImportRequest::STATUS_APPLIED]);

        $this->assertDatabaseHas('m1pposu_account_import_requests', [
            'user_id' => $user->getKey(),
            'official_user_id' => $connection->official_user_id,
            'status' => M1pposuAccountImportRequest::STATUS_APPLIED,
            'restricted_at_request' => true,
        ]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->data()['embeds'][0]['color'] === 0x57F287);
    }

    public function testAppliedImportResetBlockedForNormalUser(): void
    {
        Queue::fake();

        $this->app->detectEnvironment(fn () => 'local');
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);
        $this->createAppliedRequest($connection, $snapshot);
        $session = $this->createVerifiedSession($user);

        $this->withPersistentSession($session)
            ->withHeader('X-CSRF-TOKEN', $session->token())
            ->delete(route('account.official-osu.reset'))
            ->assertStatus(403);

        $this->assertDatabaseHas('m1pposu_official_connections', [
            'id' => $connection->getKey(),
            'user_id' => $user->getKey(),
        ]);
    }

    public function testLocalAdminCanResetAppliedImport(): void
    {
        Queue::fake();

        $this->app->detectEnvironment(fn () => 'local');
        $user = User::factory()->withGroup('admin')->create();
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);
        $request = $this->createAppliedRequest($connection, $snapshot);
        $session = $this->createVerifiedSession($user);

        $this->withPersistentSession($session)
            ->withHeader('X-CSRF-TOKEN', $session->token())
            ->delete(route('account.official-osu.reset'))
            ->assertNoContent();

        $this->assertDatabaseMissing('m1pposu_official_connections', [
            'id' => $connection->getKey(),
        ]);
        $this->assertDatabaseMissing('m1pposu_account_import_snapshots', [
            'id' => $snapshot->getKey(),
        ]);
        $this->assertDatabaseMissing('m1pposu_account_import_requests', [
            'id' => $request->getKey(),
        ]);
    }

    public function testNormalUserCannotReimportAfterAppliedImport(): void
    {
        Queue::fake();

        $this->app->detectEnvironment(fn () => 'local');
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);
        $this->createAppliedRequest($connection, $snapshot);
        $session = $this->createVerifiedSession($user);

        $this->withPersistentSession($session)
            ->withHeader('X-CSRF-TOKEN', $session->token())
            ->post(route('account.official-osu.reimport'))
            ->assertStatus(403);
    }

    public function testAccountEditDoesNotExposeTechnicalImportPreview(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $this->createSnapshot($connection);

        $this->withPersistentSession($this->createVerifiedSession($user))
            ->get(route('account.edit'))
            ->assertSuccessful()
            ->assertDontSee('OAuth Applications')
            ->assertDontSee('json-authorized-clients')
            ->assertDontSee('json-own-clients')
            ->assertDontSee('Import details')
            ->assertDontSee('Rulesets')
            ->assertDontSee('Favourite beatmaps are available')
            ->assertDontSee('Most-played beatmaps are available')
            ->assertDontSee('official API limits')
            ->assertDontSee('import_preview', false);
    }

    public function testAccountEditDoesNotExposeAdminImportControls(): void
    {
        Queue::fake();

        $this->app->detectEnvironment(fn () => 'local');
        $admin = User::factory()->withGroup('admin')->create();
        $connection = $this->createConnection($admin);
        $snapshot = $this->createSnapshot($connection);
        $this->createAppliedRequest($connection, $snapshot);

        $this->withPersistentSession($this->createVerifiedSession($admin))
            ->get(route('account.edit'))
            ->assertSuccessful()
            ->assertDontSee('Reimport official data')
            ->assertDontSee('Reset official import link')
            ->assertDontSee('can_manage_import', false);
    }

    public function testAdminImportReviewRendersReadableDetailsWithoutConnectionSecrets(): void
    {
        Queue::fake();

        $admin = User::factory()->withGroup('admin')->create();
        $connection = $this->createConnection($admin);
        $connection->update(['refresh_token' => 'super-secret-refresh-token']);
        $snapshot = $this->createSnapshot($connection);
        $request = $this->createAppliedRequest($connection, $snapshot);

        $this->withPersistentSession($this->createVerifiedSession($admin))
            ->get(route('admin.official-import-requests.show', $request))
            ->assertSuccessful()
            ->assertSee('admin-official-import-requests')
            ->assertSee('OfficialUser')
            ->assertDontSee('super-secret-refresh-token')
            ->assertDontSee('refresh_token');
    }

    public function testNormalUserCannotAccessAdminImportReview(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);
        $request = $this->createAppliedRequest($connection, $snapshot);

        $this->withPersistentSession($this->createVerifiedSession($user))
            ->get(route('admin.official-import-requests.index'))
            ->assertStatus(403);

        $this->withPersistentSession($this->createVerifiedSession($user))
            ->get(route('admin.official-import-requests.show', $request))
            ->assertStatus(403);
    }

    public function testNormalUserCannotAccessAdminImportedAccounts(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->withPersistentSession($this->createVerifiedSession($user))
            ->get(route('admin.imported-accounts.index'))
            ->assertStatus(403);
    }

    public function testAdminImportedAccountsPageRemovalAndRestore(): void
    {
        Queue::fake();
        config_set('m1pposu.official_osu.discord_webhook_url', 'https://discord.test/webhook');
        Http::fake([
            'https://discord.test/webhook' => Http::response([], 204),
        ]);

        $admin = User::factory()->withGroup('admin')->create();
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);
        $request = $this->createAppliedRequest($connection, $snapshot);

        $adminSession = $this->createVerifiedSession($admin);

        $this->withPersistentSession($adminSession)
            ->get(route('admin.imported-accounts.index'))
            ->assertSuccessful()
            ->assertSee('Imported Accounts')
            ->assertSee('OfficialUser')
            ->assertSee('Action')
            ->assertSee('Remove')
            ->assertSee('Removal reason')
            ->assertDontSee('Last action')
            ->assertDontSee('Imported data summary');

        $this->withPersistentSession($adminSession)
            ->get("/admin/imported-accounts/{$request->getKey()}")
            ->assertRedirect(route('admin.imported-accounts.index'));

        $this->withPersistentSession($adminSession)
            ->withHeader('X-CSRF-TOKEN', $adminSession->token())
            ->post(route('admin.imported-accounts.remove', $request), [
                'confirmed' => 1,
                'reason' => 'official account mismatch',
            ])
            ->assertRedirect(route('admin.imported-accounts.index'));

        $this->assertDatabaseHas('m1pposu_account_import_requests', [
            'id' => $request->getKey(),
            'status' => M1pposuAccountImportRequest::STATUS_REMOVED_BY_STAFF,
            'removed_by' => $admin->getKey(),
            'remove_reason' => 'official account mismatch',
        ]);
        $this->assertTrue($user->fresh()->isRestricted());
        $this->assertSame(M1pposuAccountImportRequest::STATUS_REMOVED_BY_STAFF, $request->fresh()->status);

        $this->withPersistentSession($adminSession)
            ->get(route('admin.imported-accounts.index'))
            ->assertSuccessful()
            ->assertSee('Restore')
            ->assertSee('Restore reason');

        $this->withPersistentSession($adminSession)
            ->withHeader('X-CSRF-TOKEN', $adminSession->token())
            ->post(route('admin.imported-accounts.restore', $request), [
                'confirmed' => 1,
                'reason' => 'restored after review',
            ])
            ->assertRedirect(route('admin.imported-accounts.index'));

        $this->assertDatabaseHas('m1pposu_account_import_requests', [
            'id' => $request->getKey(),
            'status' => M1pposuAccountImportRequest::STATUS_APPLIED,
            'restored_by' => $admin->getKey(),
            'restore_reason' => 'restored after review',
        ]);
        $this->assertFalse($user->fresh()->isRestricted());

        $webhookRequests = collect(Http::recorded())
            ->filter(fn ($record) => $record[0]->url() === 'https://discord.test/webhook');
        $this->assertCount(2, $webhookRequests);
        Http::assertSent(fn ($request) => $request->url() === 'https://discord.test/webhook'
            && $request->data()['embeds'][0]['title'] === 'Official import removed'
            && $request->data()['embeds'][0]['color'] === 0xED4245);
        Http::assertSent(fn ($request) => $request->url() === 'https://discord.test/webhook'
            && $request->data()['embeds'][0]['title'] === 'Official import restored'
            && $request->data()['embeds'][0]['color'] === 0x57F287);
    }

    public function testNormalUserCannotRemoveOrRestoreImportedAccount(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);
        $request = $this->createAppliedRequest($connection, $snapshot);
        $session = $this->createVerifiedSession($user);

        $this->withPersistentSession($session)
            ->withHeader('X-CSRF-TOKEN', $session->token())
            ->post(route('admin.imported-accounts.remove', $request), [
                'confirmed' => 1,
                'reason' => 'not allowed',
            ])
            ->assertStatus(403);

        $this->withPersistentSession($session)
            ->withHeader('X-CSRF-TOKEN', $session->token())
            ->post(route('admin.imported-accounts.restore', $request), [
                'confirmed' => 1,
                'reason' => 'not allowed',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('m1pposu_account_import_requests', [
            'id' => $request->getKey(),
            'status' => M1pposuAccountImportRequest::STATUS_APPLIED,
        ]);
    }

    private function createConnection(User $user): M1pposuOfficialConnection
    {
        return M1pposuOfficialConnection::create([
            'avatar_url' => null,
            'connected_at' => now(),
            'cover_url' => null,
            'official_user_id' => 654321,
            'refresh_token' => null,
            'restricted_at_connection' => false,
            'token_metadata' => null,
            'user_id' => $user->getKey(),
            'username' => 'OfficialUser',
        ]);
    }

    private function createSnapshot(M1pposuOfficialConnection $connection): M1pposuAccountImportSnapshot
    {
        return M1pposuAccountImportSnapshot::create([
            'connection_id' => $connection->getKey(),
            'data' => [
                'user' => [
                    'id' => $connection->official_user_id,
                    'username' => $connection->username,
                ],
            ],
            'official_user_id' => $connection->official_user_id,
            'user_id' => $connection->user_id,
        ]);
    }

    private function createAppliedRequest(
        M1pposuOfficialConnection $connection,
        M1pposuAccountImportSnapshot $snapshot,
    ): M1pposuAccountImportRequest {
        return M1pposuAccountImportRequest::create([
            'applied_at' => now(),
            'connection_id' => $connection->getKey(),
            'reviewed_at' => now(),
            'snapshot_id' => $snapshot->getKey(),
            'status' => M1pposuAccountImportRequest::STATUS_APPLIED,
            'restricted_at_request' => false,
            'user_id' => $connection->user_id,
            'official_user_id' => $connection->official_user_id,
        ]);
    }
}
