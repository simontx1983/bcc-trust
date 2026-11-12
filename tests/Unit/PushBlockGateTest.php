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
            public int $subscriptionLookups = 0;
            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }
            /** @return list<object> no devices: flush stops right after deciding to send */
            public function get_results(string $sql): array
            {
                $this->subscriptionLookups++;
                return [];
            }
        };
    }

    private function dispatcher(): PushDispatcher
    {
        return new PushDispatcher(new PushSubscriptionRepository());
    }

    private function enqueueFrom(int $actorId, string $handle): void
    {
        $this->dispatcher()->enqueue(self::RECIPIENT, 'mention', ['actor_handle' => $handle], $actorId);
    }

    /** @return array{count: int, first_payload: array<string, mixed>} */
    private function survivors(): array
    {
        $queued = array_values(PushBlockWorld::$transients)[0];
        $m = new \ReflectionMethod(PushDispatcher::class, 'survivingEvents');
        /** @var array{count: int, first_payload: array<string, mixed>} */
        return $m->invoke($this->dispatcher(), self::RECIPIENT, 'mention', $queued['actors']);
    }

    private function flushQueue(): int
    {
        $this->dispatcher()->flush(self::RECIPIENT, 'mention');
        return $GLOBALS['wpdb']->subscriptionLookups;
    }

    // ── F-189: a block created AFTER an event was queued still stops it ──

    public function testABlockCreatedInsideTheWindowRemovesThatActorAtFlush(): void
    {
        $this->enqueueFrom(7001, 'first');
        $this->enqueueFrom(self::ACTOR, 'later_blocked');
        $this->enqueueFrom(self::ACTOR, 'later_blocked');
        $this->enqueueFrom(7003, 'third');
        // The recipient blocks ACTOR after those events were queued.
        PushBlockWorld::$blocks[PushBlockWorld::key(self::RECIPIENT, self::ACTOR)] = true;

        $s = $this->survivors();
        self::assertSame(2, $s['count'], 'the blocked actor\'s 2 events are removed');
        self::assertSame('first', $s['first_payload']['actor_handle'] ?? null);
    }

    public function testWhenTheFirstActorIsBlockedThePushNamesTheNextOne(): void
    {
        $this->enqueueFrom(self::ACTOR, 'now_blocked');
        $this->enqueueFrom(7002, 'still_fine');
        PushBlockWorld::$blocks[PushBlockWorld::key(self::RECIPIENT, self::ACTOR)] = true;

        $s = $this->survivors();
        self::assertSame(1, $s['count']);
        self::assertSame('still_fine', $s['first_payload']['actor_handle'] ?? null, 'a blocked person is never named in the push');
    }

    public function testAnUnreadableBlockStateAtFlushFailsClosed(): void
    {
        $this->enqueueFrom(self::ACTOR, 'unknown_now');
        PushBlockWorld::$blocks[PushBlockWorld::key(self::RECIPIENT, self::ACTOR)] = null;

        self::assertSame(0, $this->survivors()['count']);
        self::assertContains('[PushDispatcher] flush dropped an actor: block state unreadable', PushBlockWorld::$warnings);
    }

    public function testIfEveryActorIsBlockedNothingIsSent(): void
    {
        $this->enqueueFrom(self::ACTOR, 'blocked');
        PushBlockWorld::$blocks[PushBlockWorld::key(self::RECIPIENT, self::ACTOR)] = true;

        self::assertSame(0, $this->flushQueue(), 'flush stops before even looking up devices');
    }

    public function testAnUnblockedWindowStillSends(): void
    {
        $this->enqueueFrom(self::ACTOR, 'fine');
        $this->enqueueFrom(0, 'system');

        self::assertSame(1, $this->flushQueue(), 'control: flush goes on to send');
        $asked = array_filter(PushBlockWorld::$asked, static fn (array $p): bool => $p[1] === 0);
        self::assertSame([], $asked, 'a system event is never looked up');
    }

    public function testAnActorBeyondTheTrackedCapIsNotCounted(): void
    {
        for ($a = 1; $a <= 50; $a++) {
            $this->enqueueFrom(8000 + $a, 'a' . $a);
        }
        $this->enqueueFrom(9999, 'fifty_first');

        $queued = array_values(PushBlockWorld::$transients)[0];
        self::assertCount(50, $queued['actors']);
        self::assertSame(50, $queued['count'], 'an event that could not be re-checked at flush is not counted');
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
