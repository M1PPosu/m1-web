<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Libraries\M1pposu;

use App\Libraries\M1pposu\OfficialAccountImportService;
use App\Libraries\M1pposu\OfficialImportDiscordNotifier;
use App\Libraries\M1pposu\OfficialOsuClient;
use App\Libraries\M1pposu\OfficialProfileImport;
use App\Models\Achievement;
use App\Models\Country;
use App\Models\M1pposuAccountImportRequest;
use App\Models\M1pposuAccountImportSnapshot;
use App\Models\M1pposuImportedOfficialScoreSummary;
use App\Models\M1pposuOfficialConnection;
use App\Models\Solo\Score;
use App\Models\User;
use App\Models\UserAccountHistory;
use App\Models\UserStatistics\Osu as OsuStatistics;
use App\Transformers\CurrentUserTransformer;
use App\Transformers\UserTransformer;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OfficialAccountImportServiceDbTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function testApplyImportsOfficialDataWithoutChangingNativeStatsOrScores(): void
    {
        $user = User::factory()->create(['username' => 'local_user']);
        $statistics = OsuStatistics::factory()->create([
            'user_id' => $user->getKey(),
            'rank' => 1234,
            'rank_score' => 4567.0,
            'playcount' => 89,
        ]);
        $connection = $this->createConnection($user, ['username' => 'OfficialUser']);
        $snapshot = $this->createSnapshot($connection);

        $nativeScoreCount = Score::where('user_id', $user->getKey())->count();

        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);

        $statistics->refresh();
        $user->refresh();
        $this->assertSame(1234, $statistics->rank);
        $this->assertEquals(4567.0, $statistics->rank_score);
        $this->assertSame(89, $statistics->playcount);
        $this->assertSame($nativeScoreCount, Score::where('user_id', $user->getKey())->count());
        $this->assertSame(['osu'], $result['imported_statistics']);
        $this->assertSame(['username'], $result['native_changes']);
        $this->assertSame(1, $result['imported_score_summaries']);
        $this->assertSame([], $result['blocked']);
        $this->assertSame('OfficialUser', $user->username);
        $this->assertSame('local_user', $user->username_previous);

        $summary = M1pposuImportedOfficialScoreSummary::where('snapshot_id', $snapshot->getKey())->sole();
        $this->assertSame($user->getKey(), $summary->user_id);
        $this->assertSame(654321, $summary->official_user_id);
        $this->assertSame('best', $summary->kind);
        $this->assertSame('osu', $summary->mode);
        $this->assertSame(9876, $summary->official_score_id);
        $this->assertSame(123, $summary->beatmap_id);
    }

    public function testApplyBlocksOfficialUsernameConflictWithoutChangingNativeStatsOrScores(): void
    {
        User::factory()->create(['username' => 'OfficialUser']);
        $user = User::factory()->create(['username' => 'local_user']);
        $statistics = OsuStatistics::factory()->create([
            'user_id' => $user->getKey(),
            'rank' => 1234,
            'rank_score' => 4567.0,
            'playcount' => 89,
        ]);
        $connection = $this->createConnection($user, ['username' => 'OfficialUser']);
        $snapshot = $this->createSnapshot($connection);

        $nativeScoreCount = Score::where('user_id', $user->getKey())->count();

        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);

        $statistics->refresh();
        $user->refresh();
        $this->assertSame(1234, $statistics->rank);
        $this->assertEquals(4567.0, $statistics->rank_score);
        $this->assertSame(89, $statistics->playcount);
        $this->assertSame($nativeScoreCount, Score::where('user_id', $user->getKey())->count());
        $this->assertSame([], $result['native_changes']);
        $this->assertArrayHasKey('username', $result['blocked']);
        $this->assertSame('local_user', $user->username);
    }

    public function testAppliedImportExposesVisibleProfileFieldsWithoutNativePp(): void
    {
        Country::factory()->create(['acronym' => 'US', 'name' => 'United States']);
        $this->ensureAchievement(1);

        $user = User::factory()->create(['username' => 'local_user']);
        OsuStatistics::factory()->create([
            'user_id' => $user->getKey(),
            'rank_score' => 12.0,
            'playcount' => 3,
        ]);
        $connection = $this->createConnection($user, [
            'avatar_url' => 'https://a.ppy.sh/654321',
            'cover_url' => 'https://assets.ppy.sh/user-profile-covers/654321/x.jpg',
            'username' => 'OfficialUser',
        ]);
        $snapshot = $this->createSnapshot($connection);

        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);
        app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);

        $json = json_item(
            $user->fresh(),
            (new UserTransformer())->setMode('osu'),
            ['account_history', 'official_import', 'statistics', 'user_achievements'],
        );
        $currentUserJson = json_item($user->fresh(), new CurrentUserTransformer());

        $this->assertSame('https://a.ppy.sh/654321', $currentUserJson['avatar_url']);
        $this->assertSame('https://a.ppy.sh/654321', $json['official_import']['profile']['avatar_url']);
        $this->assertSame('https://assets.ppy.sh/user-profile-covers/654321/x.jpg', $json['official_import']['profile']['cover_url']);
        $this->assertSame('US', $json['official_import']['profile']['country']['code']);
        $this->assertSame('2012-05-01T00:00:00+00:00', $json['official_import']['profile']['join_date']);
        $this->assertSame(1111111, $json['official_import']['statistics']['current']['ranked_score']);
        $this->assertSame(1000, $json['official_import']['statistics']['current']['play_count']);
        $this->assertArrayNotHasKey('official_pp', $json['official_import']['statistics']['current']);
        $this->assertArrayNotHasKey('score_counts', $json['official_import']);
        $this->assertArrayNotHasKey('scores', $json['official_import']);
        $this->assertArrayNotHasKey('beatmapsets', $json['official_import']);
        $this->assertArrayNotHasKey('recent_activity', $json['official_import']);
        $this->assertArrayNotHasKey('medals', $json['official_import']['profile']);
        $this->assertArrayNotHasKey('medals_count', $json['official_import']['profile']);
        $this->assertSame([], $json['user_achievements']);
        $this->assertSame(1111111, $json['statistics']['ranked_score']);
        $this->assertSame(1000, $json['statistics']['play_count']);
        $this->assertSame(620, $json['statistics']['total_hits']);
        $this->assertSame(4, $json['statistics']['grade_counts']['a']);
        $this->assertSame(3, $json['statistics']['grade_counts']['s']);
        $this->assertSame(12.0, $json['statistics']['pp']);
        $this->assertSame([], $json['account_history']);
        $this->assertDatabaseMissing('osu_user_banhistory', [
            'user_id' => $user->getKey(),
            'ban_status' => UserAccountHistory::TYPES['note'],
            'reason' => "Official osu! data imported from {$connection->official_user_id}. Native pp, ranks, scores, and leaderboards were not changed.",
        ]);
        $this->assertDatabaseMissing('osu_user_achievements', [
            'user_id' => $user->getKey(),
            'achievement_id' => 1,
        ]);
    }

    public function testAppliedImportKeepsOfficialMedalsOutOfPublicProfile(): void
    {
        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);
        $data = $snapshot->data;
        $data['user']['user_achievements'] = [
            ['achieved_at' => '2012-05-02T00:00:00+00:00', 'achievement_id' => 987654],
        ];
        $snapshot->update(['data' => $data]);

        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);
        app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);

        $json = json_item(
            $user->fresh(),
            (new UserTransformer())->setMode('osu'),
            ['official_import', 'user_achievements'],
        );

        $this->assertArrayNotHasKey('medals', $json['official_import']['profile']);
        $this->assertArrayNotHasKey('medals_count', $json['official_import']['profile']);
        $this->assertSame([], $json['user_achievements']);
        $this->assertSame(987654, $snapshot->fresh()->data['user']['user_achievements'][0]['achievement_id']);
    }

    public function testAppliedRequestCreationIsIdempotentForAnActiveImport(): void
    {
        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);
        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);

        $first = app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);
        $second = app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, M1pposuAccountImportRequest::where('user_id', $user->getKey())->count());
    }

    public function testAppliedImportExposesHeaderMarkerStateWithoutGrantingGroupOrStoredBadge(): void
    {
        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);

        $beforeImport = json_item(
            $user->fresh(),
            (new UserTransformer())->setMode('osu'),
            ['badges', 'groups', 'official_import'],
        );
        $this->assertSame([], $beforeImport['badges']);
        $this->assertNull($beforeImport['official_import']);
        $this->assertSame([], array_filter($beforeImport['groups'], fn ($group) => $group['identifier'] === 'official-imported'));

        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);
        app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);

        $afterImport = json_item(
            $user->fresh(),
            (new UserTransformer())->setMode('osu'),
            ['badges', 'groups', 'official_import'],
        );

        $this->assertSame([], $afterImport['badges']);
        $this->assertNotNull($afterImport['official_import']);
        $this->assertSame([], array_values(array_filter($afterImport['groups'], fn ($group) => $group['identifier'] === 'official-imported')));
        $this->assertFalse($user->fresh()->isAdmin());
        $this->assertFalse($user->fresh()->isSupporter());
        $this->assertDatabaseMissing('osu_badges', [
            'user_id' => $user->getKey(),
            'description' => 'Players who imported their official osu! accounts',
        ]);
    }

    public function testAdminRemoveHidesImportedProfileDataAndRestoreReturnsIt(): void
    {
        $admin = User::factory()->withGroup('admin')->create();
        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);

        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);
        $request = app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);

        $beforeRemove = json_item(
            $user->fresh(),
            (new UserTransformer())->setMode('osu'),
            ['groups', 'official_import'],
        );
        $this->assertNotNull($beforeRemove['official_import']);
        $this->assertSame([], array_values(array_filter($beforeRemove['groups'], fn ($group) => $group['identifier'] === 'official-imported')));
        $this->assertCount(1, app(OfficialProfileImport::class)->scoreItems($user->fresh(), 'osu', 'best'));

        app(OfficialAccountImportService::class)->removeByStaff($request, $admin, 'official account mismatch');

        $afterRemove = json_item(
            $user->fresh(),
            (new UserTransformer())->setMode('osu'),
            ['groups', 'official_import'],
        );
        $this->assertNull($afterRemove['official_import']);
        $this->assertSame([], array_values(array_filter($afterRemove['groups'], fn ($group) => $group['identifier'] === 'official-imported')));
        $this->assertSame([], app(OfficialProfileImport::class)->scoreItems($user->fresh(), 'osu', 'best'));
        $this->assertTrue($user->fresh()->isRestricted());

        app(OfficialAccountImportService::class)->restore($request->fresh(), $admin, 'restored after review');

        $afterRestore = json_item(
            $user->fresh(),
            (new UserTransformer())->setMode('osu'),
            ['groups', 'official_import'],
        );
        $this->assertNotNull($afterRestore['official_import']);
        $this->assertSame([], array_values(array_filter($afterRestore['groups'], fn ($group) => $group['identifier'] === 'official-imported')));
        $this->assertCount(1, app(OfficialProfileImport::class)->scoreItems($user->fresh(), 'osu', 'best'));
        $this->assertFalse($user->fresh()->isRestricted());
    }

    public function testAdminRestoreDoesNotClearUnrelatedRestriction(): void
    {
        $admin = User::factory()->withGroup('admin')->create();
        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);

        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);
        $request = app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);
        $removed = app(OfficialAccountImportService::class)->removeByStaff($request, $admin, 'official account mismatch');

        UserAccountHistory::create([
            'ban_status' => UserAccountHistory::TYPES['restriction'],
            'banner_id' => $admin->getKey(),
            'permanent' => true,
            'period' => 0,
            'reason' => 'Unrelated moderation restriction',
            'user_id' => $user->getKey(),
        ]);

        app(OfficialAccountImportService::class)->restore($removed, $admin, 'restore import only');

        $this->assertTrue($user->fresh()->isRestricted());
    }

    public function testSelfRemoveHidesImportedDataWithoutRestrictingUser(): void
    {
        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user, ['refresh_token' => 'refresh-token']);
        $snapshot = $this->createSnapshot($connection);

        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);
        $request = app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);

        app(OfficialAccountImportService::class)->selfRemove($request, $user);

        $json = json_item(
            $user->fresh(),
            (new UserTransformer())->setMode('osu'),
            ['groups', 'official_import'],
        );

        $this->assertNull($json['official_import']);
        $this->assertSame([], array_values(array_filter($json['groups'], fn ($group) => $group['identifier'] === 'official-imported')));
        $this->assertFalse($user->fresh()->isRestricted());
        $this->assertNull($connection->fresh()->refresh_token);
        $this->assertTrue($connection->fresh()->token_metadata['manual_reimport_blocked']);
    }

    public function testOfficialProfileImportSanitizesExternalUrlsAndHtml(): void
    {
        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user, [
            'avatar_url' => 'https://evil.test/avatar.png',
            'cover_url' => 'javascript:alert(1)',
        ]);
        $snapshot = $this->createSnapshot($connection);
        $data = $snapshot->data;
        $data['user']['avatar_url'] = 'https://evil.test/avatar.png';
        $data['user']['cover_url'] = 'javascript:alert(1)';
        $data['user']['page'] = ['html' => '<p>ok</p><script>alert(1)</script>'];
        $data['user']['badges'] = [
            [
                'description' => 'bad badge',
                'image_url' => 'https://evil.test/badge.png',
                'url' => 'https://evil.test/badge',
            ],
            [
                'description' => 'good badge',
                'image_url' => 'https://assets.ppy.sh/profile-badges/good.png',
                'url' => 'https://osu.ppy.sh/home/news/good',
            ],
        ];
        $data['recent_activity'][0]['achievement']['icon_url'] = 'http://assets.ppy.sh/medals/bad.png';
        $data['scores']['osu']['best'][0]['beatmap']['url'] = 'https://evil.test/beatmaps/123';
        $data['scores']['osu']['best'][0]['beatmapset']['url'] = 'https://evil.test/beatmapsets/456';
        $data['beatmapsets']['favourite'][0]['covers']['card'] = 'https://evil.test/card.jpg';
        $data['beatmapsets']['favourite'][0]['preview_url'] = '//b.ppy.sh/preview/111.mp3';
        $snapshot->update(['data' => $data]);

        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);
        app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);

        $json = json_item(
            $user->fresh(),
            (new UserTransformer())->setMode('osu'),
            ['official_import'],
        );
        $scores = app(OfficialProfileImport::class)->scoreItems($user->fresh(), 'osu', 'best');
        $favourites = app(OfficialProfileImport::class)->beatmapsetItems($user->fresh(), 'favourite');
        $activity = app(OfficialProfileImport::class)->recentActivityItems($user->fresh());

        $this->assertNull($json['official_import']['profile']['avatar_url']);
        $this->assertNull($json['official_import']['profile']['cover_url']);
        $this->assertStringContainsString('<p>ok</p>', $json['official_import']['profile']['page_html']);
        $this->assertStringNotContainsString('<script', $json['official_import']['profile']['page_html']);
        $this->assertCount(1, $json['official_import']['profile']['badges']);
        $this->assertSame('good badge', $json['official_import']['profile']['badges'][0]['description']);
        $this->assertSame('https://assets.ppy.sh/profile-badges/good.png', $json['official_import']['profile']['badges'][0]['image_url']);
        $this->assertSame('https://osu.ppy.sh/home/news/good', $json['official_import']['profile']['badges'][0]['url']);
        $this->assertSame('https://osu.ppy.sh/beatmaps/123', $scores[0]['beatmap']['url']);
        $this->assertSame('https://osu.ppy.sh/beatmapsets/456', $scores[0]['beatmapset']['url']);
        $this->assertSame('https://assets.ppy.sh/beatmaps/111/covers/card.jpg', $favourites[0]['covers']['card']);
        $this->assertSame('https://b.ppy.sh/preview/111.mp3', $favourites[0]['preview_url']);
        $this->assertSame([], $activity);
    }

    public function testLegacyImportAuditNotesAreHiddenFromPublicAccountStanding(): void
    {
        $user = User::factory()->create();

        UserAccountHistory::addNote($user, 'Official osu! data imported from 654321. Native pp, ranks, scores, and leaderboards were not changed.', $user);
        UserAccountHistory::addNote($user, 'Official osu! import link 654321 reset by local admin/dev testing control.', $user);

        $json = json_item(
            $user->fresh(),
            (new UserTransformer())->setMode('osu'),
            ['account_history'],
        );

        $this->assertSame([], $json['account_history']);
    }

    public function testImportedTopPlaysExposeReadOnlyProfileRowsWhenComplete(): void
    {
        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);

        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);
        app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);

        $items = app(OfficialProfileImport::class)->scoreItems($user->fresh(), 'osu', 'best');

        $this->assertCount(1, $items);
        $this->assertSame('m1pposu_official_import', $items[0]['type']);
        $this->assertSame('official_osu', $items[0]['source']['backend']);
        $this->assertSame('', $items[0]['source']['display_name']);
        $this->assertSame('9876', $items[0]['source']['external_id']);
        $this->assertSame('https://osu.ppy.sh/beatmaps/123', $items[0]['beatmap']['url']);
        $this->assertSame('Best Song', $items[0]['beatmapset']['title']);
        $this->assertSame('Insane', $items[0]['beatmap']['version']);
        $this->assertSame([['acronym' => 'HD']], $items[0]['mods']);
        $this->assertSame('S', $items[0]['rank']);
        $this->assertSame(0.9876, $items[0]['accuracy']);
        $this->assertSame(7654321, $items[0]['total_score']);
        $this->assertSame(321.45, $items[0]['pp']);
        $this->assertArrayNotHasKey('weight', $items[0]);
        $this->assertStringNotContainsString('official import', json_encode($items));
        $this->assertStringNotContainsString('imported', json_encode($items));
        $this->assertSame(0, Score::where('user_id', $user->getKey())->count());
    }

    public function testImportedTopPlaysExposeMoreThanTwentyFourProfileRows(): void
    {
        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);
        $data = $snapshot->data;
        $data['scores']['osu']['best'] = array_map(
            fn ($index) => $this->officialScore(9000 + $index, 1000 + $index, 2000 + $index),
            range(1, 30),
        );
        $snapshot->update(['data' => $data]);

        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);
        app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);

        $profileImport = app(OfficialProfileImport::class);
        $items = $profileImport->scoreItems($user->fresh(), 'osu', 'best');

        $this->assertSame(30, $result['imported_score_summaries']);
        $this->assertSame(30, $profileImport->scoreCount($user->fresh(), 'osu', 'best'));
        $this->assertCount(30, $items);
        $this->assertSame(9030, abs($items[0]['id']));
        $this->assertSame(9001, abs($items[29]['id']));
        $this->assertSame(0, Score::where('user_id', $user->getKey())->count());
    }

    public function testProfileTopPlaysMergeNativeAndImportedScoresByPpWithPagination(): void
    {
        config_set('m1pposu.private_server.enabled', true);

        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);
        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);
        app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);

        $higherNative = Score::factory()->create([
            'passed' => true,
            'pp' => 400.0,
            'preserve' => true,
            'rank' => 'A',
            'ranked' => true,
            'ruleset_id' => 0,
            'user_id' => $user->getKey(),
        ]);
        $lowerNative = Score::factory()->create([
            'passed' => true,
            'pp' => 200.0,
            'preserve' => true,
            'rank' => 'A',
            'ranked' => true,
            'ruleset_id' => 0,
            'user_id' => $user->getKey(),
        ]);

        $this->actAsScopedUser($user, ['public']);

        $this->assertSame(1, app(OfficialProfileImport::class)->scoreCount($user->fresh(), 'osu', 'best'));

        $response = $this->get("/api/v2/users/{$user->getKey()}/scores/best?mode=osu&limit=3")
            ->assertSuccessful();
        $this->assertSame(
            [400.0, 321.45, 200.0],
            array_map(fn ($score) => (float) $score['pp'], $response->json()),
            json_encode($response->json()),
        );
        $response
            ->assertJsonPath('0.id', $higherNative->getKey())
            ->assertJsonPath('1.id', -9876)
            ->assertJsonPath('1.source.backend', 'official_osu')
            ->assertJsonPath('2.id', $lowerNative->getKey());

        $this->get("/api/v2/users/{$user->getKey()}/scores/best?mode=osu&limit=1&offset=1")
            ->assertSuccessful()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', -9876);
    }

    public function testIncompleteImportedTopPlaysAreHiddenFromProfileRows(): void
    {
        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);
        $data = $snapshot->data;
        unset($data['scores']['osu']['best'][0]['beatmapset']['title']);
        $snapshot->update(['data' => $data]);

        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);
        app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);

        $this->assertSame([], app(OfficialProfileImport::class)->scoreItems($user->fresh(), 'osu', 'best'));
    }

    public function testImportedRecentActivityExposesRealAchievementEvents(): void
    {
        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);

        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);
        app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);

        $items = app(OfficialProfileImport::class)->recentActivityItems($user->fresh());

        $this->assertCount(1, $items);
        $this->assertSame('achievement', $items[0]['type']);
        $this->assertSame('Cyclone', $items[0]['achievement']['name']);
        $this->assertSame('osu-skill-cyclone', $items[0]['achievement']['slug']);
        $this->assertSame(route('users.show', ['user' => $user->getKey()]), $items[0]['user']['url']);
        $this->assertStringNotContainsString('official medal #', json_encode($items));
    }

    public function testImportedBeatmapsetsRenderOfficialMetadataWithoutLocalMapping(): void
    {
        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user);
        $snapshot = $this->createSnapshot($connection);

        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);
        app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);

        $profileImport = app(OfficialProfileImport::class);

        $favourites = $profileImport->beatmapsetItems($user->fresh(), 'favourite');
        $mostPlayed = $profileImport->beatmapsetItems($user->fresh(), 'most_played');

        $this->assertSame(1, $profileImport->beatmapsetCount($user->fresh(), 'favourite'));
        $this->assertSame(1, $profileImport->beatmapsetCount($user->fresh(), 'most_played'));
        $this->assertSame('Favourite Song', $favourites[0]['title']);
        $this->assertSame('https://osu.ppy.sh/beatmapsets/111', $favourites[0]['url']);
        $this->assertTrue($favourites[0]['is_external']);
        $this->assertSame('', $favourites[0]['source']);
        $this->assertSame(42, $mostPlayed[0]['count']);
        $this->assertSame('Most Played Song', $mostPlayed[0]['beatmapset']['title']);
        $this->assertSame('https://osu.ppy.sh/beatmaps/222', $mostPlayed[0]['beatmap']['url']);
        $this->assertTrue($mostPlayed[0]['beatmapset']['is_external']);
    }

    public function testCreateSnapshotFetchesEveryModeKindAndOfficialBeatmapCategory(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $client = new class extends OfficialOsuClient {
            public array $beatmapsetCalls = [];
            public array $meModes = [];
            public array $scoreCalls = [];

            public function me(string $accessToken, ?string $mode = null): array
            {
                $this->meModes[] = $mode;

                return [
                    'statistics' => [
                        'count_300' => 1,
                        'grade_counts' => [],
                        'hit_accuracy' => 99.0,
                    ],
                ];
            }

            public function userActivity(string $accessToken, int $officialUserId): array
            {
                return [];
            }

            public function userBeatmapsets(string $accessToken, int $officialUserId, string $type): array
            {
                $this->beatmapsetCalls[] = $type;

                return [];
            }

            public function userScores(string $accessToken, int $officialUserId, string $kind, string $mode): array
            {
                $this->scoreCalls[] = "{$mode}:{$kind}";

                return [['id' => count($this->scoreCalls)]];
            }
        };

        $snapshot = (new OfficialAccountImportService($client))->createSnapshot($connection, 'access-token', [
            'id' => $connection->official_user_id,
            'username' => $connection->username,
        ]);

        $this->assertSame(['osu', 'taiko', 'fruits', 'mania'], $client->meModes);
        $this->assertSame([
            'osu:best',
            'osu:recent',
            'osu:firsts',
            'taiko:best',
            'taiko:recent',
            'taiko:firsts',
            'fruits:best',
            'fruits:recent',
            'fruits:firsts',
            'mania:best',
            'mania:recent',
            'mania:firsts',
        ], $client->scoreCalls);
        $this->assertSame(['favourite', 'most_played'], $client->beatmapsetCalls);
        $this->assertSame(true, $snapshot->data['fetch_metadata']['score_legacy_only']);
        $this->assertSame(OfficialOsuClient::SCORE_PAGE_LIMIT, $snapshot->data['fetch_metadata']['score_page_limit_per_kind_mode']);
    }

    public function testCreateSnapshotStoresGenericFetchErrorWithoutExceptionMessage(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $client = new class extends OfficialOsuClient {
            public function me(string $accessToken, ?string $mode = null): array
            {
                return [
                    'statistics' => [
                        'count_300' => 1,
                        'grade_counts' => [],
                        'hit_accuracy' => 99.0,
                    ],
                ];
            }

            public function userActivity(string $accessToken, int $officialUserId): array
            {
                throw new \RuntimeException('secret access-token leaked');
            }

            public function userBeatmapsets(string $accessToken, int $officialUserId, string $type): array
            {
                return [];
            }

            public function userScores(string $accessToken, int $officialUserId, string $kind, string $mode): array
            {
                return [];
            }
        };

        $snapshot = (new OfficialAccountImportService($client))->createSnapshot($connection, 'access-token', [
            'id' => $connection->official_user_id,
            'username' => $connection->username,
        ]);

        $this->assertSame(['_error' => 'fetch_failed'], $snapshot->data['recent_activity']);
        $this->assertStringNotContainsString('secret access-token leaked', json_encode($snapshot->data));
    }

    public function testDiscordNotifierSendsSafePayloadAndDoesNotThrowOnFailure(): void
    {
        config_set('m1pposu.official_osu.discord_webhook_url', 'https://discord.test/webhook');
        Http::fake([
            'https://discord.test/webhook' => Http::response([], 500),
        ]);

        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user);

        app(OfficialImportDiscordNotifier::class)->connectionEvent('import applied', $connection, null, $user, '@everyone <@&123456789>');

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $fieldNames = collect($payload['embeds'][0]['fields'])->pluck('name')->all();
            $encodedPayload = json_encode($payload);

            return $request->url() === 'https://discord.test/webhook'
                && !array_key_exists('content', $payload)
                && $payload['embeds'][0]['color'] === 0x57F287
                && $payload['embeds'][0]['title'] === 'Import applied'
                && in_array('Local account', $fieldNames, true)
                && in_array('Official account', $fieldNames, true)
                && !str_contains($encodedPayload, '@everyone')
                && !str_contains($encodedPayload, '<@&123456789>')
                && !str_contains($encodedPayload, 'refresh_token')
                && !str_contains($encodedPayload, 'access_token');
        });
        Http::assertSentCount(1);
    }

    public function testDiscordNotifierFailureUsesOneRedEmbed(): void
    {
        config_set('m1pposu.official_osu.discord_webhook_url', 'https://discord.test/webhook');
        Http::fake([
            'https://discord.test/webhook' => Http::response([], 204),
        ]);

        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user);

        app(OfficialImportDiscordNotifier::class)->connectionEvent('import failed', $connection);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => !array_key_exists('content', $request->data())
            && $request->data()['embeds'][0]['color'] === 0xED4245
            && $request->data()['embeds'][0]['title'] === 'Import failed');
    }

    public function testDiscordNotifierUsesGreenForConnectionEventsAndRedForRemovalEvents(): void
    {
        config_set('m1pposu.official_osu.discord_webhook_url', 'https://discord.test/webhook');
        Http::fake([
            'https://discord.test/webhook' => Http::response([], 204),
        ]);

        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user);
        $events = [
            'Official account connected' => 0x57F287,
            'Official data imported' => 0x57F287,
            'Official data reimported' => 0x57F287,
            'Official import approved' => 0x57F287,
            'Official import restored' => 0x57F287,
            'Official import removed' => 0xED4245,
            'Official import removed by user' => 0xED4245,
            'Official account disconnected' => 0xED4245,
            'Official import link reset' => 0xED4245,
            'Official import failed' => 0xED4245,
            'Official import denied' => 0xED4245,
        ];

        foreach (array_keys($events) as $event) {
            app(OfficialImportDiscordNotifier::class)->connectionEvent($event, $connection);
        }

        $embeds = collect(Http::recorded())->map(fn ($record) => $record[0]->data()['embeds'][0]);
        $this->assertCount(count($events), $embeds);

        foreach (array_values($events) as $index => $color) {
            $this->assertSame($color, $embeds[$index]['color']);
        }
    }

    public function testRevokedOauthKeepsAppliedImportAndMarksReconnectNeeded(): void
    {
        $user = User::factory()->create(['username' => 'local_user']);
        $connection = $this->createConnection($user, [
            'refresh_token' => 'revoked-token',
            'username' => 'OfficialUser',
        ]);
        $snapshot = $this->createSnapshot($connection);
        $result = app(OfficialAccountImportService::class)->apply($snapshot, $user);
        $request = app(OfficialAccountImportService::class)->createAppliedRequest($connection, $snapshot, $user, $result);

        $service = new OfficialAccountImportService(new class extends \App\Libraries\M1pposu\OfficialOsuClient {
            public function tokenFromRefreshToken(string $refreshToken): array
            {
                throw new class extends \RuntimeException implements GuzzleException {
                };
            }
        });

        try {
            $service->refreshSnapshot($connection);
            $this->fail('Expected revoked official osu! token refresh to require reconnect.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $connection->refresh();
        $this->assertNull($connection->refresh_token);
        $this->assertTrue($connection->token_metadata['reconnect_needed']);
        $this->assertNotNull($connection->token_metadata['unavailable_at']);
        $this->assertDatabaseHas('m1pposu_account_import_snapshots', [
            'id' => $snapshot->getKey(),
        ]);
        $this->assertDatabaseHas('m1pposu_account_import_requests', [
            'id' => $request->getKey(),
            'status' => 'applied',
        ]);
    }

    private function createConnection(User $user, array $attributes = []): M1pposuOfficialConnection
    {
        return M1pposuOfficialConnection::create([
            'user_id' => $user->getKey(),
            'official_user_id' => 654321,
            'username' => $attributes['username'] ?? 'OfficialUser',
            'avatar_url' => $attributes['avatar_url'] ?? null,
            'cover_url' => $attributes['cover_url'] ?? null,
            'restricted_at_connection' => $attributes['restricted_at_connection'] ?? false,
            'refresh_token' => $attributes['refresh_token'] ?? null,
            'token_metadata' => null,
            'connected_at' => now(),
        ]);
    }

    private function ensureAchievement(int $id): void
    {
        Achievement::query()->updateOrCreate(
            ['achievement_id' => $id],
            [
                'achieved_count' => 0,
                'client_side' => false,
                'description' => 'Test medal',
                'enabled' => true,
                'grouping' => 'Test',
                'image' => null,
                'mode' => null,
                'name' => 'Test Medal',
                'ordering' => 0,
                'progression' => 0,
                'quest_instructions' => null,
                'quest_ordering' => null,
                'slug' => "test-medal-{$id}",
            ],
        );
        app('medals')->resetMemoized();
    }

    private function createSnapshot(M1pposuOfficialConnection $connection): M1pposuAccountImportSnapshot
    {
        return M1pposuAccountImportSnapshot::create([
            'connection_id' => $connection->getKey(),
            'user_id' => $connection->user_id,
            'official_user_id' => $connection->official_user_id,
            'data' => [
                'user' => [
                    'avatar_url' => $connection->avatar_url,
                    'badges' => [],
                    'country_code' => 'US',
                    'cover_url' => $connection->cover_url,
                    'id' => $connection->official_user_id,
                    'is_supporter' => true,
                    'join_date' => '2012-05-01T00:00:00+00:00',
                    'user_achievements' => [
                        ['achieved_at' => '2012-05-02T00:00:00+00:00', 'achievement_id' => 1],
                    ],
                    'username' => $connection->username,
                ],
                'statistics' => [
                    'osu' => [
                        'grade_counts' => ['a' => 4, 's' => 3, 'sh' => 2, 'ss' => 1, 'ssh' => 0],
                        'hit_accuracy' => 98.76,
                        'count_300' => 500,
                        'count_100' => 100,
                        'count_50' => 20,
                        'count_miss' => 5,
                        'maximum_combo' => 1234,
                        'pp' => 9999.0,
                        'play_count' => 1000,
                        'play_time' => 86400,
                        'ranked_score' => 1111111,
                        'total_score' => 2222222,
                    ],
                ],
                'scores' => [
                    'osu' => [
                        'best' => [
                            $this->officialScore(),
                        ],
                    ],
                ],
                'beatmapsets' => [
                    'favourite' => [
                        $this->officialBeatmapset(
                            111,
                            'Favourite Artist',
                            'Favourite Song',
                            'Favourite Mapper',
                            222,
                            ['beatmaps' => [$this->officialBeatmap(112, 111, 'Hard')]],
                        ),
                    ],
                    'most_played' => [
                        [
                            'beatmap_id' => 222,
                            'beatmap' => $this->officialBeatmap(222, 333, 'Normal'),
                            'beatmapset' => $this->officialBeatmapset(333, 'Most Played Artist', 'Most Played Song', 'Most Played Mapper', 444, [
                                'last_updated' => null,
                                'ranked_date' => null,
                            ]),
                            'count' => 42,
                        ],
                    ],
                ],
                'recent_activity' => [
                    [
                        'achievement' => [
                            'achieved_count' => 129299,
                            'achieved_percent' => 0.00454823073687149,
                            'description' => 'Clockwise or anticlockwise, that is the question.',
                            'grouping' => 'Skill & Dedication',
                            'icon_url' => 'https://assets.ppy.sh/medals/web/osu-skill-cyclone.png',
                            'id' => 354,
                            'instructions' => '<b>osu!(lazer) only</b><br><i>Reach 477 spins per minute on a spinner.</i>',
                            'mode' => 'osu',
                            'name' => 'Cyclone',
                            'ordering' => 7,
                            'slug' => 'osu-skill-cyclone',
                        ],
                        'created_at' => '2014-02-01T00:00:00+00:00',
                        'id' => 1026706789,
                        'type' => 'achievement',
                    ],
                ],
            ],
        ]);
    }

    private function officialBeatmap(int $id = 123, int $beatmapsetId = 456, string $version = 'Insane', string $mode = 'osu'): array
    {
        return [
            'beatmapset_id' => $beatmapsetId,
            'difficulty_rating' => 5.12,
            'id' => $id,
            'mode' => $mode,
            'status' => 'ranked',
            'total_length' => 120,
            'url' => "https://osu.ppy.sh/beatmaps/{$id}",
            'user_id' => 789,
            'version' => $version,
        ];
    }

    private function officialBeatmapset(
        int $id = 456,
        string $artist = 'Best Artist',
        string $title = 'Best Song',
        string $creator = 'Best Mapper',
        int $userId = 789,
        array $attributes = [],
    ): array {
        return [
            'artist' => $artist,
            'artist_unicode' => $artist,
            'covers' => [
                'card' => "https://assets.ppy.sh/beatmaps/{$id}/covers/card.jpg",
                'cover' => "https://assets.ppy.sh/beatmaps/{$id}/covers/cover.jpg",
                'list' => "https://assets.ppy.sh/beatmaps/{$id}/covers/list.jpg",
                'slimcover' => "https://assets.ppy.sh/beatmaps/{$id}/covers/slimcover.jpg",
            ],
            'creator' => $creator,
            'favourite_count' => 7,
            'id' => $id,
            'last_updated' => '2014-01-01T00:00:00+00:00',
            'play_count' => 123,
            'ranked_date' => '2014-01-02T00:00:00+00:00',
            'source' => '',
            'status' => 'ranked',
            'title' => $title,
            'title_unicode' => $title,
            'url' => "https://osu.ppy.sh/beatmapsets/{$id}",
            'user_id' => $userId,
            ...$attributes,
        ];
    }

    private function officialScore(int $scoreId = 9876, int $beatmapId = 123, int $beatmapsetId = 456): array
    {
        return [
            'accuracy' => 0.9876,
            'beatmap' => $this->officialBeatmap($beatmapId, $beatmapsetId),
            'beatmapset' => $this->officialBeatmapset($beatmapsetId),
            'ended_at' => '2014-01-01T00:00:00+00:00',
            'id' => $scoreId,
            'max_combo' => 1234,
            'mods' => ['HD'],
            'passed' => true,
            'perfect' => false,
            'pp' => 321.45,
            'rank' => 'S',
            'total_score' => 7654321,
        ];
    }
}
