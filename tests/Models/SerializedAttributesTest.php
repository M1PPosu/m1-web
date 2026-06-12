<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Models;

use App\Models\Forum\Forum;
use App\Models\Log;
use Tests\Models\Support\SerializedPayload;
use Tests\TestCase;

class SerializedAttributesTest extends TestCase
{
    public function testForumParentsRejectsSerializedObjects(): void
    {
        $forum = new Forum();
        $forum->setRawAttributes([
            'forum_parents' => serialize(new SerializedPayload()),
            'parent_id' => 1,
        ]);

        $this->assertSame([], $forum->forum_parents);
        $this->assertFalse(SerializedPayload::$wasInstantiated);
    }

    public function testLogDataRejectsSerializedObjects(): void
    {
        $log = new Log();
        $log->setRawAttributes(['log_data' => serialize(new SerializedPayload())]);

        $this->assertSame([], $log->log_data);
        $this->assertFalse(SerializedPayload::$wasInstantiated);
    }

    protected function setUp(): void
    {
        parent::setUp();

        SerializedPayload::$wasInstantiated = false;
    }
}
