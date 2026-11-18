<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Security\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-289 — RateLimiter::retryAfterByKey(), against the real wp_options counter
 * rows (real MySQL / MariaDB, the clock frozen so no test sleeps).
 *
 * The property it pins: after a deny, a retry at now + retryAfterByKey() is
 * ALLOWED, and a retry at any earlier second is REFUSED. Retry-After is then
 * the exact wait, not the window — which for this weighted sliding window can
 * be shorter, or nearly twice as long.
 *
 * Every probe passes countDenied = false, so a refused probe gives its slot
 * back and the probes never change the state they measure.
 */
#[Group('integration')]
#[CoversClass(RateLimiter::class)]
final class RateLimitRetryAfterIntegrationTest extends TestCase
{
    /** Arbitrary fixed bucket index, so the test never depends on wall clock. */
    private const BUCKET = 31000000;

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `wp_options`');
    }

    protected function tearDown(): void
    {
        RateLimiter::setClockForTesting(null);
    }

    private static function at(int $t): void
    {
        RateLimiter::setClockForTesting(static fn (): int => $t);
    }

    /** The start of the frozen bucket, for $window. */
    private static function t0(int $window): int
    {
        return self::BUCKET * $window;
    }

    private function key(): string
    {
        return 'f289_' . uniqid('', true);
    }

    /** @return list<array{0: string, 1: string}> every wp_options row, in name order */
    private static function optionRows(): array
    {
        $rows = $GLOBALS['wpdb']->get_results('SELECT option_name, option_value FROM wp_options ORDER BY option_name', ARRAY_N);
        return array_map(static fn (array $r): array => [(string) $r[0], (string) $r[1]], is_array($rows) ? $rows : []);
    }

    /** @return int how many of $calls were allowed */
    private static function calls(string $key, int $limit, int $window, int $calls, bool $countDenied): int
    {
        $allowed = 0;
        for ($i = 0; $i < $calls; $i++) {
            $allowed += RateLimiter::allowByKey($key, $limit, $window, $countDenied) ? 1 : 0;
        }
        return $allowed;
    }

    /**
     * At $now (just after a deny), retryAfterByKey() is the first second a
     * retry passes: refused at now, a second before, and in between; allowed
     * at now + it. Returns it.
     */
    private function assertExactWait(string $key, int $limit, int $window, int $now, string $case = ''): int
    {
        self::at($now);
        $rows = self::optionRows();
        $wait = RateLimiter::retryAfterByKey($key, $limit, $window);
        self::assertSame($rows, self::optionRows(), $case . ' asking is read-only: no row written, no call counted');
        self::assertGreaterThanOrEqual(1, $wait, $case . ' a deny just happened, so the wait is at least 1 s');
        self::assertLessThanOrEqual(2 * $window, $wait, $case . ' the refused bucket stops weighing after two windows');

        foreach (array_unique([$now, $now + intdiv($wait, 2), $now + $wait - 1]) as $t) {
            if ($t >= $now + $wait) {
                continue;
            }
            self::at($t);
            self::assertFalse(
                RateLimiter::allowByKey($key, $limit, $window, false),
                $case . ' a retry ' . ($t - $now) . ' s in, before the advertised ' . $wait . ' s, must still be refused'
            );
        }

        self::at($now + $wait);
        self::assertTrue(
            RateLimiter::allowByKey($key, $limit, $window, false),
            $case . ' a retry at the advertised ' . $wait . ' s must be allowed'
        );

        return $wait;
    }

    /**
     * Later in the SAME bucket: the previous bucket's weighted share fades.
     * limit 5, window 60: five calls at -30 s, then one 10 s into the bucket.
     */
    public function testTheWaitCanEndInTheSameBucket(): void
    {
        foreach ([true => 14, false => 2] as $countDenied => $expected) {
            $key = $this->key();
            self::at(self::t0(60) - 30);
            self::assertSame(5, self::calls($key, 5, 60, 5, true));

            $now = self::t0(60) + 10;
            self::at($now);
            self::assertSame(0, self::calls($key, 5, 60, 1, (bool) $countDenied));

            // Counted (the default), the refused call makes the retry the
            // bucket's 2nd: 200 + 5·w ≤ 500 needs w ≤ 60, from 24 s in.
            // Not counted (auth routes), the retry is the 1st: w ≤ 80, 12 s in.
            self::assertSame($expected, $this->assertExactWait($key, 5, 60, $now, $countDenied ? 'counted:' : 'not counted:'));
            self::assertNotSame(60, $expected, 'the window is not the wait');
        }
    }

    /** In the NEXT bucket, sooner than a window. limit 3, window 60, all at 50 s in. */
    public function testTheWaitCanEndInTheNextBucketSoonerThanAWindow(): void
    {
        $key = $this->key();
        $now = self::t0(60) + 50;
        self::at($now);
        self::assertSame(3, self::calls($key, 3, 60, 4, true));

        // Next bucket: 100 + 4·w ≤ 300 needs w ≤ 50, from 30 s in → 10 + 30.
        self::assertSame(40, $this->assertExactWait($key, 3, 60, $now));
    }

    /**
     * LONGER than a window — where the old Retry-After (the window) sent a
     * client back too early, and on a counting bucket that retry extended
     * the block. limit 3, window 60: a flood of 23 calls 5 s in.
     */
    public function testTheWaitCanBeLongerThanTheWindow(): void
    {
        $key = $this->key();
        $now = self::t0(60) + 5;
        self::at($now);
        self::assertSame(3, self::calls($key, 3, 60, 23, true));

        // Next bucket: 100 + 23·w ≤ 300 needs w ≤ 8, from 56 s in → 55 + 56.
        $wait = $this->assertExactWait($key, 3, 60, $now);
        self::assertSame(111, $wait);
        self::assertGreaterThan(60, $wait);
    }

    /**
     * Two buckets on, when even the next bucket's lightest weight is too
     * much: limit 1, window 60, two calls 10 s in → 2·60 − 10.
     */
    public function testTheWaitCanRunToTheBucketAfterNext(): void
    {
        $key = $this->key();
        $now = self::t0(60) + 10;
        self::at($now);
        self::assertSame(1, self::calls($key, 1, 60, 2, true));

        self::assertSame(110, $this->assertExactWait($key, 1, 60, $now));
    }

    /**
     * The property across limits, windows, both countDenied modes, a
     * previous bucket, a flood and the second within the bucket (seeded, so
     * a failure reproduces).
     */
    public function testTheWaitIsExactAcrossShapes(): void
    {
        mt_srand(289);
        $checked = 0;
        for ($case = 0; $case < 60; $case++) {
            $limit       = [1, 2, 3, 5, 10, 30][mt_rand(0, 5)];
            $window      = [7, 60, 900, 3600][mt_rand(0, 3)];
            $countDenied = mt_rand(0, 1) === 1;
            $prevCalls   = mt_rand(0, 2 * $limit);
            $flood       = [0, 0, 1, 5][mt_rand(0, 3)];

            $key = $this->key();
            self::at(self::t0($window) - mt_rand(1, $window));
            self::calls($key, $limit, $window, $prevCalls, $countDenied);

            $now = self::t0($window) + mt_rand(0, $window - 1);
            self::at($now);
            // Call until one is refused, then flood a little more.
            $denied = false;
            for ($i = 0; $i < 3 * $limit + 3 && !$denied; $i++) {
                $denied = !RateLimiter::allowByKey($key, $limit, $window, $countDenied);
            }
            if (!$denied) {
                continue;
            }
            self::calls($key, $limit, $window, $flood, $countDenied);

            $label = sprintf('case %d (limit %d, window %d, %s, prev %d, flood %d, %d s in):',
                $case, $limit, $window, $countDenied ? 'counted' : 'not counted', $prevCalls, $flood, $now - self::t0($window));
            $this->assertExactWait($key, $limit, $window, $now, $label);
            $checked++;
        }
        self::assertGreaterThan(40, $checked, 'most shapes reach a deny');
    }

    /**
     * A bucket that would let a call through NOW answers 0, never a negative
     * wait — e.g. another request gave a slot back between the deny and the
     * question. limit 5, window 60: five calls at -30 s, asked 50 s in, when
     * the previous bucket weighs only 17%.
     */
    public function testABucketWithRoomAnswersZero(): void
    {
        $key = $this->key();
        self::at(self::t0(60) - 30);
        self::assertSame(5, self::calls($key, 5, 60, 5, true));

        self::at(self::t0(60) + 50);
        self::assertSame(0, RateLimiter::retryAfterByKey($key, 5, 60));
        self::assertTrue(RateLimiter::allowByKey($key, 5, 60, false), 'and a call now is indeed allowed');
    }

    /** A failed bucket read answers the window — the value Retry-After always carried. */
    public function testAFailedReadAnswersTheWindow(): void
    {
        global $wpdb;
        $key = $this->key();
        self::at(self::t0(60) + 10);
        self::assertSame(1, self::calls($key, 1, 60, 2, true));

        $options       = $wpdb->options;
        $wpdb->options = 'wp_f289_no_such_table';
        try {
            $wait = RateLimiter::retryAfterByKey($key, 1, 60);
        } finally {
            $wpdb->options = $options;
        }

        self::assertSame(60, $wait);
        self::assertSame(110, RateLimiter::retryAfterByKey($key, 1, 60), 'and the real answer once the read works');
    }
}
