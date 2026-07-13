<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Libraries\M1pposu;

use App\Libraries\M1pposu\OfficialAccountImportService;
use App\Libraries\M1pposu\OfficialOsuClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class OfficialAccountImportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3).'/app/helpers.php';
    }

    /**
     * @dataProvider restrictedUserDataProvider
     */
    public function testIsRestricted(array $officialUser, bool $expected): void
    {
        $service = new OfficialAccountImportService(new OfficialOsuClient());

        $this->assertSame($expected, $service->isRestricted($officialUser));
    }

    /**
     * @dataProvider scoreKindDataProvider
     */
    public function testOfficialUserScoresPaginateWithLegacyOnly(string $kind): void
    {
        $client = $this->clientWithResponses([
            $this->response($this->items(1, OfficialOsuClient::SCORE_PAGE_LIMIT)),
            $this->response($this->items(OfficialOsuClient::SCORE_PAGE_LIMIT + 1, 7)),
        ], $history);

        $scores = $client->userScores('access-token', 654321, $kind, 'osu');

        $this->assertCount(OfficialOsuClient::SCORE_PAGE_LIMIT + 7, $scores);
        $this->assertCount(2, $history);
        $this->assertSame("/api/v2/users/654321/scores/{$kind}", $history[0]['request']->getUri()->getPath());
        $this->assertSame('0', $this->query($history[0])['offset']);
        $this->assertSame((string) OfficialOsuClient::SCORE_PAGE_LIMIT, $this->query($history[1])['offset']);
        $this->assertSame((string) OfficialOsuClient::SCORE_PAGE_LIMIT, $this->query($history[0])['limit']);
        $this->assertSame('1', $this->query($history[0])['legacy_only']);
        $this->assertSame('0', $this->query($history[0])['include_fails']);
        $this->assertSame('osu', $this->query($history[0])['mode']);
    }

    public function testOfficialBeatmapsetsAndRecentActivityPaginate(): void
    {
        $beatmapsetClient = $this->clientWithResponses([
            $this->response($this->items(1, OfficialOsuClient::BEATMAPSET_PAGE_LIMIT)),
            $this->response($this->items(OfficialOsuClient::BEATMAPSET_PAGE_LIMIT + 1, 3)),
        ], $beatmapsetHistory);
        $activityClient = $this->clientWithResponses([
            $this->response($this->items(1, OfficialOsuClient::ACTIVITY_PAGE_LIMIT)),
            $this->response([]),
        ], $activityHistory);

        $this->assertCount(
            OfficialOsuClient::BEATMAPSET_PAGE_LIMIT + 3,
            $beatmapsetClient->userBeatmapsets('access-token', 654321, 'favourite'),
        );
        $this->assertCount(OfficialOsuClient::ACTIVITY_PAGE_LIMIT, $activityClient->userActivity('access-token', 654321));
        $this->assertSame('/api/v2/users/654321/beatmapsets/favourite', $beatmapsetHistory[0]['request']->getUri()->getPath());
        $this->assertSame((string) OfficialOsuClient::BEATMAPSET_PAGE_LIMIT, $this->query($beatmapsetHistory[1])['offset']);
        $this->assertSame('/api/v2/users/654321/recent_activity', $activityHistory[0]['request']->getUri()->getPath());
        $this->assertSame((string) OfficialOsuClient::ACTIVITY_PAGE_LIMIT, $this->query($activityHistory[1])['offset']);
    }

    public static function restrictedUserDataProvider(): array
    {
        return [
            'current restricted flag' => [['is_restricted' => true], true],
            'legacy restricted marker' => [['is_restricted_at' => true], true],
            'false restricted flag' => [['is_restricted' => false], false],
            'missing restricted flag' => [[], false],
        ];
    }

    public static function scoreKindDataProvider(): array
    {
        return [
            'best' => ['best'],
            'firsts' => ['firsts'],
            'recent' => ['recent'],
        ];
    }

    private function clientWithResponses(array $responses, ?array &$history): OfficialOsuClient
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new OfficialOsuClient(new Client(['handler' => $stack]));
    }

    private function items(int $start, int $count): array
    {
        return array_map(
            fn ($id) => ['id' => $id],
            range($start, $start + $count - 1),
        );
    }

    private function query(array $transaction): array
    {
        parse_str($transaction['request']->getUri()->getQuery(), $query);

        return $query;
    }

    private function response(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}
