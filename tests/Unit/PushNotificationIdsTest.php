<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\NotificationRepository;
use BCC\Trust\Core\Repositories\PushSubscriptionRepository;
use BCC\Trust\Core\Services\PushDispatcher;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-516: a push carries the ids of the bell rows it stands for, so opening
 * it can mark them read.
 *
 * The queue side (enqueue + the flush-time block re-check):
 *  - the payload's `notification_id` is kept beside its actor's events and
 *    taken out of the payload the wording is built from;
 *  - every event of an actor adds its id; no bell row (0, negative, absent,
 *    not an int) adds none;
 *  - a window holds at most MARK_READ_MANY_MAX ids; later events still
 *    count, their bells simply stay unread;
 *  - an actor removed at flush (blocked, or block state unreadable) takes
 *    their ids with them.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushNotificationIdsTest extends TestCase
{
    private const RECIPIENT = 6201;
    private const ACTOR     = 6202;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/push-block-gate-stubs.php';
        PushBlockWorld::reset();
        // PushSubscriptionRepository names its table from the prefix; nothing
        // here reaches a query (survivingEvents() is called directly).
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
        };
    }

    private function dispatcher(): PushDispatcher
    {
        return new PushDispatcher(new PushSubscriptionRepository());
    }

    /** @param mixed $bellId */
    private function enqueue(int $actorId, $bellId, string $handle = 'someone'): void
    {
        $payload = ['actor_handle' => $handle];
        if ($bellId !== 'absent') {
            $payload['notification_id'] = $bellId;
        }
        $this->dispatcher()->enqueue(self::RECIPIENT, 'mention', $payload, $actorId);
    }

    /** @return array<string, mixed> */
    private static function window(): array
    {
        $windows = array_values(PushBlockWorld::$transients);
        self::assertCount(1, $windows);
        return $windows[0];
    }

    /**
     * @param array<int|string, mixed>|null $actors
     * @return array{count: int, first_payload: array<string, mixed>, ids: list<int>}
     */
    private function survivors(?array $actors = null): array
    {
        $m = new \ReflectionMethod(PushDispatcher::class, 'survivingEvents');
        /** @var array{count: int, first_payload: array<string, mixed>, ids: list<int>} */
        return $m->invoke($this->dispatcher(), self::RECIPIENT, 'mention', $actors ?? self::window()['actors']);
    }

    public function testTheBellIdIsKeptBesideItsActorAndLeftOutOfTheWording(): void
    {
        $this->enqueue(self::ACTOR, 501, 'alice');

        $w = self::window();
        self::assertSame(
            [self::ACTOR => ['n' => 1, 'payload' => ['actor_handle' => 'alice'], 'ids' => [501]]],
            $w['actors']
        );
        self::assertSame(['actor_handle' => 'alice'], $w['first_payload']);
    }

    public function testEveryEventOfAnActorAddsItsId(): void
    {
        $this->enqueue(self::ACTOR, 501);
        $this->enqueue(self::ACTOR, 502);
        $this->enqueue(7001, 503);

        $actors = self::window()['actors'];
        self::assertSame(2, $actors[self::ACTOR]['n']);
        self::assertSame([501, 502], $actors[self::ACTOR]['ids']);
        self::assertSame([503], $actors[7001]['ids']);
        self::assertSame([501, 502, 503], $this->survivors()['ids']);
    }

    public function testAnEventWithNoBellRowAddsNoId(): void
    {
        $this->enqueue(self::ACTOR, 0);        // the bell is off, or PeepSo suppressed it
        $this->enqueue(self::ACTOR, -1);       // the bell write failed
        $this->enqueue(self::ACTOR, 'absent'); // a producer with no bell (dispute outcome)
        $this->enqueue(self::ACTOR, '77');     // never trusted: not an int

        $w = self::window();
        self::assertSame(4, $w['count']);
        self::assertSame([], $w['actors'][self::ACTOR]['ids']);
        self::assertSame(['actor_handle' => 'someone'], $w['actors'][self::ACTOR]['payload']);
        self::assertSame([], $this->survivors()['ids']);
    }

    public function testAWindowHoldsAtMostAsManyIdsAsMarkReadTakes(): void
    {
        $max = NotificationRepository::MARK_READ_MANY_MAX;
        for ($i = 1; $i <= $max - 1; $i++) {
            $this->enqueue(self::ACTOR, $i);
        }
        $this->enqueue(7001, $max);       // the last one that fits
        $this->enqueue(7001, $max + 1);   // past the cap: counted, not listed
        $this->enqueue(7002, $max + 2);   // a new actor past the cap: same

        $w = self::window();
        self::assertSame($max + 2, $w['count'], 'every event still counts');
        self::assertSame([$max], $w['actors'][7001]['ids']);
        self::assertSame([], $w['actors'][7002]['ids']);
        self::assertSame(range(1, $max), $this->survivors()['ids']);
    }

    public function testABlockedActorsIdsAreNotSent(): void
    {
        $this->enqueue(7001, 11);
        $this->enqueue(self::ACTOR, 12);
        $this->enqueue(self::ACTOR, 13);
        $this->enqueue(7003, 14);

        PushBlockWorld::$blocks[PushBlockWorld::key(self::RECIPIENT, self::ACTOR)] = true;

        $s = $this->survivors();
        self::assertSame(2, $s['count']);
        self::assertSame([11, 14], $s['ids']);
    }

    public function testAnActorWhoseBlockStateIsUnreadableTakesTheirIdsWithThem(): void
    {
        $this->enqueue(self::ACTOR, 21);
        $this->enqueue(7001, 22);

        PushBlockWorld::$blocks[PushBlockWorld::key(self::RECIPIENT, self::ACTOR)] = null;

        self::assertSame([22], $this->survivors()['ids']);
    }

    public function testSurvivingIdsAreDistinctPositiveIntsAndNeverMoreThanTheCap(): void
    {
        // A window as a tampered or older transient might hold it.
        $s = $this->survivors([
            0    => ['n' => 2, 'payload' => [], 'ids' => [5, 5, 0, -4, '6', null, 7]],
            7001 => ['n' => 1, 'payload' => [], 'ids' => range(100, 160)],
            7002 => ['n' => 1, 'payload' => []],               // no ids key at all
            7003 => ['n' => 1, 'payload' => [], 'ids' => 'x'], // not a list
        ]);

        self::assertSame(5, $s['count']);
        self::assertCount(NotificationRepository::MARK_READ_MANY_MAX, $s['ids']);
        self::assertSame([5, 7], array_slice($s['ids'], 0, 2));
        self::assertSame(range(100, 147), array_slice($s['ids'], 2));
    }
}
