<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Libraries\M1pposu;

use App\Libraries\M1pposu\UserProjector;
use App\Models\Country;
use App\Models\Group;
use App\Models\M1pposuExternalUser;
use App\Models\User;
use Illuminate\Support\Collection;
use Tests\TestCase;

class UserProjectorTest extends TestCase
{
    public function testCreatesUserWhenReferenceRowsAreMissing(): void
    {
        Group::query()->delete();
        Country::query()->delete();
        app('groups')->resetMemoized();
        app('countries')->resetMemoized();

        $sourcePasswordHash = password_hash(md5('source-password'), PASSWORD_BCRYPT);
        $sourceUser = (object) [
            'id' => 1001,
            'name' => 'Source User',
            'email' => 'source-user@example.test',
            'country' => '??',
            'creation_time' => 1_700_000_000,
            'latest_activity' => 1_700_000_100,
            'priv' => 1,
            'pw_bcrypt' => $sourcePasswordHash,
            'silence_end' => null,
            'donor_end' => null,
        ];

        $summary = app(UserProjector::class)->sync($sourceUser, new Collection(), 'test-source');
        $user = $summary['user'];

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($summary['created_user']);
        $this->assertSame('Source User', $user->username);
        $this->assertSame('source-user@example.test', $user->user_email);
        $this->assertSame(Country::UNKNOWN, $user->country_acronym);
        $this->assertNotSame($sourcePasswordHash, $user->user_password);

        $defaultGroup = app('groups')->byIdentifier('default');
        $this->assertNotNull($defaultGroup);
        $this->assertSame($defaultGroup->getKey(), $user->group_id);
        $this->assertSame([$defaultGroup->getKey()], $user->userGroups()->pluck('group_id')->all());
        $this->assertEqualsCanonicalizing(Group::PRIV_IDENTIFIERS, Group::query()->pluck('identifier')->all());
        $this->assertTrue(Country::where('acronym', Country::UNKNOWN)->exists());

        $mapping = M1pposuExternalUser::where([
            'backend' => 'test-source',
            'external_user_id' => '1001',
        ])->first();

        $this->assertNotNull($mapping);
        $this->assertSame($user->getKey(), $mapping->user_id);
        $this->assertSame('Source User', $mapping->external_username);
    }
}
