<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\PushSubscriptionRepository;
use BCC\Trust\Core\Services\PushDispatcher;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-74: a push never crosses a block.
 *
 * Before: the bell went through PeepSo, which applies blocks, but the
 * parallel push went through PushDispatcher, which did not. A member who
 * blocked someone still got that person's mentions, comments, reviews and
 * vouches on their phone.
 *
 * Now, for an event with a person behind it, enqueue() drops the push when
 * either side has blocked the other, AND when the block table cannot be
 * read (fail closed, as F-99). System events (actor 0) are not gated.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushBlockGateTest extends TestCase
{
    private const RECIPIENT = 6101;
    private const ACTOR     = 6102;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/push-block-gate-stubs.php';
        PushBlockWorld::reset();
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
        };
    }

    private function enqueue(int $actorId): void
    {
        (new PushDispatcher(new PushSubscriptionRepository()))
            ->enqueue(self::RECIPIENT, 'mention', ['actor_handle' => 'someone'], $actorId);
    }

    public function testNoBlockQueuesThePush(): void
    {
        $this->enqueue(self::ACTOR);

        self::assertCount(1, PushBlockWorld::$scheduled, 'control: an unblocked push is queued');
        self::assertSame([[self::RECIPIENT, self::ACTOR]], PushBlockWorld::$asked);
    }

    public function testABlockInEitherDirectionDropsThePush(): void
    {
        PushBlockWorld::$blocks[PushBlockWorld::key(self::RECIPIENT, self::ACTOR)] = true;

        $this->enqueue(self::ACTOR);

        self::assertSame([], PushBlockWorld::$scheduled);
        self::assertSame([], PushBlockWorld::$transients, 'nothing queued, nothing counted');
    }

    public function testAnUnreadableBlockTableDropsThePushAndSaysSo(): void
    {
        PushBlockWorld::$blocks[PushBlockWorld::key(self::RECIPIENT, self::ACTOR)] = null;

        $this->enqueue(self::ACTOR);

        self::assertSame([], PushBlockWorld::$scheduled, 'fail closed');
        self::assertSame(['[PushDispatcher] enqueue dropped: block state unreadable'], PushBlockWorld::$warnings);
    }

    public function testABlockedActorCannotAddToAnOpenWindow(): void
    {
        $this->enqueue(7001); // an unblocked actor opens the debounce window
        PushBlockWorld::$blocks[PushBlockWorld::key(self::RECIPIENT, self::ACTOR)] = true;
        $this->enqueue(self::ACTOR);

        $queued = array_values(PushBlockWorld::$transients);
        self::assertCount(1, $queued);
        self::assertSame(1, $queued[0]['count'], 'the blocked actor is not counted into the push');
    }

    public function testASystemEventWithNoActorIsNotGated(): void
    {
        $this->enqueue(0);

        self::assertCount(1, PushBlockWorld::$scheduled);
        self::assertSame([], PushBlockWorld::$asked, 'no actor, no block lookup');
    }
}
