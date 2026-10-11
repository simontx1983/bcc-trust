<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Support\NotificationPrefs;
use BCC\Trust\Core\Support\PushMetrics;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * "No push was sent" and "no counter was recorded" are different facts.
 *
 * ── THE COLLAPSE ────────────────────────────────────────────────────────
 * readBucket() returns 0 for a bucket that is ABSENT and 0 for a bucket
 * that genuinely counted nothing, so the Push tab rendered a full table of
 * zeros for an hour in which nothing was recorded at all — before the
 * hour's first push, on a fresh install, or after the two-hour TTL expired.
 *
 * ── WHY readSnapshot() IS UNTOUCHED ─────────────────────────────────────
 * It is public and returns a plain outcome→type→int map. Tracing callers
 * first found exactly two, both in the admin Push tab, so reshaping it
 * would be safe today — which is not a reason to change a public contract.
 * readSnapshotForAdmin() is additive and admin-specific, and these tests
 * assert the old shape still holds.
 *
 * ── ⚠ AND THE REASON THIS IS NOT THE VOTE RINGS BUG ─────────────────────
 * increment() prefers wp_cache_incr when wp_using_ext_object_cache() and
 * otherwise falls back to a TRANSIENT; readBucket() mirrors that order. So
 * these counters survive a host with no persistent object cache. The Vote
 * Rings snapshot has no such fallback, which is why its tab can never show
 * a ring on such a host. The two look identical from the outside, so the
 * difference is asserted here rather than assumed: the transient path is
 * exercised directly.
 */
#[Group('integration')]
#[Group('mariadb')]
final class PushMetricsPresenceIntegrationTest extends TestCase
{
    private int $now;
    private string $hour;
    private string $eventType;

    protected function setUp(): void
    {
        $this->now       = time();
        $this->hour      = gmdate('YmdH', $this->now);
        $this->eventType = (string) array_values(NotificationPrefs::PUSH_TYPES)[0];

        $this->clearAllBuckets();
    }

    protected function tearDown(): void
    {
        $this->clearAllBuckets();
    }

    private function clearAllBuckets(): void
    {
        foreach (PushMetrics::ALL_OUTCOMES as $outcome) {
            foreach (NotificationPrefs::PUSH_TYPES as $eventType) {
                $key = 'bcc_push_' . $outcome . '_' . $eventType . '_' . $this->hour;
                delete_transient($key);
                wp_cache_delete($key, 'bcc_push_metrics');
            }
        }
    }

    private function key(string $outcome): string
    {
        return 'bcc_push_' . $outcome . '_' . $this->eventType . '_' . $this->hour;
    }

    // ── 0. ⚠⚠ ABSENT IS DISTINGUISHABLE FROM ZERO
    // ═══════════════════════════════════════════════════════════════════

    public function testAnHourWithNothingRecordedIsNotAvailable(): void
    {
        $read = PushMetrics::readSnapshotForAdmin($this->now);

        self::assertFalse($read['available'], 'a table of zeros must not be printable from this');
    }

    public function testAnHourWithNothingRecordedStillReportsZeroBuckets(): void
    {
        // The numbers are still there and still zero — `available` is the
        // only new information, so nothing downstream has to change shape.
        $read = PushMetrics::readSnapshotForAdmin($this->now);

        self::assertSame(0, $read['buckets'][PushMetrics::OUTCOME_ATTEMPT][$this->eventType]);
    }

    public function testASingleRecordedCounterMakesTheHourAvailable(): void
    {
        // ⚠ ANTI-VACUITY, and the control that gives every false its meaning:
        // if `available` were hardcoded false the case above would pass
        // forever.
        set_transient($this->key(PushMetrics::OUTCOME_ATTEMPT), 7, 7200);

        $read = PushMetrics::readSnapshotForAdmin($this->now);

        self::assertTrue($read['available']);
        self::assertSame(7, $read['buckets'][PushMetrics::OUTCOME_ATTEMPT][$this->eventType]);
    }

    public function testARecordedZeroIsAvailable(): void
    {
        // ⚠ THE CASE THE WHOLE DISTINCTION EXISTS FOR. A bucket that was
        // written and holds 0 is DATA; an absent bucket is not. Both read as
        // the integer 0, and only `available` separates them.
        set_transient($this->key(PushMetrics::OUTCOME_FAILURE), 0, 7200);

        $read = PushMetrics::readSnapshotForAdmin($this->now);

        self::assertTrue($read['available'], 'a recorded zero is still a measurement');
        self::assertSame(0, $read['buckets'][PushMetrics::OUTCOME_FAILURE][$this->eventType]);
    }

    public function testPresenceIsPerHour(): void
    {
        // Recording in this hour must not make a different hour available.
        set_transient($this->key(PushMetrics::OUTCOME_ATTEMPT), 3, 7200);

        self::assertTrue(PushMetrics::readSnapshotForAdmin($this->now)['available']);
        self::assertFalse(
            PushMetrics::readSnapshotForAdmin($this->now - 7200)['available'],
            'two hours back has its own buckets'
        );
    }

    // ── 1. THE TRANSIENT FALLBACK, WHICH RINGS DOES NOT HAVE
    // ═══════════════════════════════════════════════════════════════════

    public function testTheTransientPathIsWhatIsRead(): void
    {
        // ⚠ Exercised directly, because it is the difference between this
        // subsystem and the Vote Rings snapshot. No object cache is involved:
        // a transient alone must be enough for the reader to find a counter.
        self::assertFalse(wp_using_ext_object_cache(), 'precondition: no external object cache here');

        set_transient($this->key(PushMetrics::OUTCOME_SUCCESS), 4, 7200);

        $read = PushMetrics::readSnapshotForAdmin($this->now);

        self::assertTrue($read['available']);
        self::assertSame(4, $read['buckets'][PushMetrics::OUTCOME_SUCCESS][$this->eventType]);
    }

    public function testAnIncrementSurvivesWithoutAnObjectCache(): void
    {
        // End to end through the production writer: record, then read back.
        // This is precisely what the rings snapshot cannot do.
        PushMetrics::recordAttempt($this->eventType);

        $read = PushMetrics::readSnapshotForAdmin($this->now);

        self::assertTrue($read['available'], 'the writer and the reader must meet');
        self::assertGreaterThan(0, $read['buckets'][PushMetrics::OUTCOME_ATTEMPT][$this->eventType]);
    }

    // ── 2. THE PUBLIC CONTRACT IS UNCHANGED
    // ═══════════════════════════════════════════════════════════════════

    public function testReadSnapshotStillReturnsThePlainMap(): void
    {
        set_transient($this->key(PushMetrics::OUTCOME_ATTEMPT), 5, 7200);

        $snapshot = PushMetrics::readSnapshot($this->now);

        self::assertArrayNotHasKey('available', $snapshot, 'no verdict key on the public shape');
        self::assertArrayNotHasKey('buckets', $snapshot, 'and no wrapper either');
        self::assertSame(5, $snapshot[PushMetrics::OUTCOME_ATTEMPT][$this->eventType]);
    }

    public function testBothReadersAgreeOnTheNumbers(): void
    {
        // The admin reader must not quietly diverge from the public one.
        set_transient($this->key(PushMetrics::OUTCOME_TOMBSTONE), 2, 7200);

        $plain = PushMetrics::readSnapshot($this->now);
        $admin = PushMetrics::readSnapshotForAdmin($this->now);

        self::assertSame($plain, $admin['buckets'], 'same numbers, one extra fact');
    }
}
