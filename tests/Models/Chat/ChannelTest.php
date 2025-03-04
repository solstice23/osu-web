<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace Tests\Models\Chat;

use App\Events\ChatChannelEvent;
use App\Jobs\Notifications\ChannelAnnouncement;
use App\Libraries\BroadcastsPendingForTests;
use App\Models\Chat\Channel;
use App\Models\User;
use App\Models\UserRelation;
use Event;
use Illuminate\Filesystem\Filesystem;
use Queue;
use SplFileInfo;
use Storage;
use Tests\TestCase;

class ChannelTest extends TestCase
{
    public function testAnnouncementSendMessage()
    {
        Queue::fake();

        $user = User::factory()->withGroup('announce')->create();
        $otherUser = User::factory()->create();
        $channel = $this->createChannel([$user, $otherUser], 'announce');

        $channel->receiveMessage($user, 'test');

        Queue::assertPushed(ChannelAnnouncement::class);
    }

    public function testPublicChannelDoesNotShowUsers()
    {
        $user = User::factory()->create();
        $channel = $this->createChannel([$user], 'public');

        $this->assertSame(1, $channel->users()->count());
        $this->assertEmpty($channel->visibleUsers());
    }

    /**
     * @dataProvider channelWithBlockedUserVisibilityDataProvider
     */
    public function testChannelWithBlockedUserVisibility(?string $otherUserGroup, bool $expectVisible)
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->withGroup($otherUserGroup)->create();
        $channel = $this->createChannel([$user, $otherUser], 'pm');

        UserRelation::create([
            'user_id' => $user->getKey(),
            'zebra_id' => $otherUser->getKey(),
            'foe' => true,
        ]);

        $this->assertSame($expectVisible, $channel->isVisibleFor($user));
    }

    /**
     * @dataProvider channelCanMessageModeratedChannelDataProvider
     */
    public function testChannelCanMessageModeratedPmChannel(?string $group, bool $canMessage)
    {
        $user = User::factory()->withGroup($group)->create();
        $otherUser = User::factory()->create();
        $channel = $this->createChannel([$user, $otherUser], 'pm', true);

        $this->assertSame($canMessage, $channel->checkCanMessage($user)->can());
    }

    /**
     * @dataProvider channelCanMessageModeratedChannelDataProvider
     */
    public function testChannelCanMessageModeratedPublicChannel(?string $group, bool $canMessage)
    {
        $user = User::factory()->withGroup($group)->create();
        $channel = $this->createChannel([$user], 'public', true);

        $this->assertSame($canMessage, $channel->checkCanMessage($user)->can());
    }

    /**
     * @dataProvider channelCanMessageWhenBlockedDataProvider
     */
    public function testChannelCanMessagePmChannelWhenBlocked(?string $group, bool $canMessage)
    {
        $user = User::factory()->withGroup($group)->create();
        $otherUser = User::factory()->create();
        $channel = $this->createChannel([$user, $otherUser], 'pm');

        UserRelation::create([
            'user_id' => $user->getKey(),
            'zebra_id' => $otherUser->getKey(),
            'foe' => true,
        ]);

        // reset caches from previous steps.
        $user->refresh();
        $otherUser->refresh();
        app('OsuAuthorize')->resetCache();

        // this assertion to make sure the correct block direction is being tested.
        $this->assertTrue($user->hasBlocked($otherUser));
        $this->assertSame($canMessage, $channel->checkCanMessage($user)->can());
    }

    /**
     * @dataProvider channelCanMessageWhenBlockedDataProvider
     */
    public function testChannelCanMessagePmChannelWhenBlocking(?string $group, bool $canMessage)
    {
        $user = User::factory()->withGroup($group)->create();
        $otherUser = User::factory()->create();
        $channel = $this->createChannel([$user, $otherUser], 'pm');

        UserRelation::create([
            'user_id' => $otherUser->getKey(),
            'zebra_id' => $user->getKey(),
            'foe' => true,
        ]);

        // reset caches from previous steps.
        $user->refresh();
        $otherUser->refresh();
        app('OsuAuthorize')->resetCache();

        // this assertion to make sure the correct block direction is being tested.
        $this->assertTrue($otherUser->hasBlocked($user));
        $this->assertSame($canMessage, $channel->checkCanMessage($user)->can());
    }

    /**
     * @dataProvider channelCanMessageWhenBlockedDataProvider
     */
    public function testChannelCanMessagePmChannelWhenFriendsOnly(?string $group, bool $canMessage)
    {
        $user = User::factory()->withGroup($group)->create();
        $otherUser = User::factory()->create(['pm_friends_only' => true]);
        $channel = $this->createChannel([$user, $otherUser], 'pm');

        app('OsuAuthorize')->resetCache();

        $this->assertSame($canMessage, $channel->checkCanMessage($user)->can());
    }

    public function testCreateAnnouncement()
    {
        Event::fake(ChatChannelEvent::class);

        $users = User::factory()->count(2)->create();

        $channel = Channel::createAnnouncement($users, ['description' => 'channel', 'name' => 'the best']);

        $channel = $channel->fresh();

        $this->assertEmpty($users->diff($channel->users()), 'created channel has too many users.');
        $this->assertEmpty($channel->users()->diff($users), 'created channel is missing users.');
        $this->assertSame(Channel::TYPES['announce'], $channel->type);

        $broadcastsPending = app(BroadcastsPendingForTests::class)->dispatched(ChatChannelEvent::class, fn (ChatChannelEvent $event) => $event->action === 'join');
        $this->assertSame(2, count($broadcastsPending));
        Event::assertNotDispatched(ChatChannelEvent::class);
    }

    public function testPmChannelIcon()
    {
        Storage::fake('local-avatar');
        $this->beforeApplicationDestroyed(function () {
            (new Filesystem())->deleteDirectory(storage_path('framework/testing/disks/local-avatar'));
        });

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $testFile = new SplFileInfo(public_path('images/layout/avatar-guest.png'));
        $user->setAvatar($testFile);
        $otherUser->setAvatar($testFile);

        $channel = $this->createChannel([$user, $otherUser], 'pm');
        $this->assertSame($otherUser->user_avatar, $channel->displayIconFor($user));
        $this->assertSame($user->user_avatar, $channel->displayIconFor($otherUser));
    }

    public function testPmChannelName()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $channel = $this->createChannel([$user, $otherUser], 'pm');
        $this->assertSame($otherUser->username, $channel->displayNameFor($user));
        $this->assertSame($user->username, $channel->displayNameFor($otherUser));
    }

    public function channelCanMessageModeratedChannelDataProvider()
    {
        return [
            [null, false],
            ['admin', true],
            ['bng', false],
            ['bot', false],
            ['gmt', true],
            ['nat', true],
        ];
    }

    public function channelCanMessageWhenBlockedDataProvider()
    {
        return [
            [null, false],
            ['admin', true],
            ['bng', false],
            ['bot', true],
            ['gmt', true],
            ['nat', true],
        ];
    }

    public function channelWithBlockedUserVisibilityDataProvider()
    {
        return [
            [null, false],
            ['admin', true],
            ['bng', false],
            ['bot', true],
            ['gmt', true],
            ['nat', true],
        ];
    }

    private function createChannel(array $users, string $type, bool $moderated = false): Channel
    {
        $channel = Channel::factory()->type($type);
        if ($moderated) {
            $channel = $channel->moderated();
        }

        $channel = $channel->create();

        foreach ($users as $user) {
            $channel->addUser($user);
        }

        return $channel;
    }
}
