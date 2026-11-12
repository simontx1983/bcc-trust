<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Security\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-129 — the bucketed sliding window rounded the PREVIOUS bucket's weighted
 * contribution down to a whole request, so a contribution below 1 vanished.
 *
 * Old arithmetic:
 *     $effective = $curCount + (int) (($prevCount * $weightPct) / 100);
 *
 * With prev_count = 1 and weight 50%, that floors to 0 — the previous window
 * is erased and a second request fires inside what should be one window. At an
 * adjusted limit of 1 it is a literal doubling, and limit 1 is reachable by
 * design: getAdjustedLimit() ends in max(1, …) and critical_risk multiplies by
 * 0.2, so the base-5 actions (flag, login) land on 1 for the highest-risk
 * accounts.
 *
 * These cases run through the real option-backed counter against MySQL, with
 * the clock frozen at chosen offsets so no test sleeps. Measured: three of
 * the six fail on the pre-fix arithmetic (limit 1, limit 10, limit 0); the
 * two marked REGRESSION GUARD pass either way
 * and exist so a later change cannot quietly trade the boundary-spike
 * protection away for this fix.
 */
#[Group('integration')]
#[CoversClass(RateLimiter::class)]
final class RateLimitWindowRoundingIntegrationTest extends TestCase
{
    private const WINDOW = 60;

    /** Arbitrary fixed bucket index, so the test never depends on wall clock. */
    private const BUCKET = 30000000;

    private int $clockNow = 0;

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `wp_options`');
        $this->clockNow = $this->tAt(0, 0);
        RateLimiter::setClockForTesting(fn (): int => $this->clockNow);
    }

    protected function tearDown(): void
    {
        // A leaked fake clock would silently corrupt every later test.
        RateLimiter::setClockForTesting(null);
    }

    /** Absolute timestamp: $elapsed seconds into bucket BUCKET+$offset. */
    private function tAt(int $offset, int $elapsed): int
    {
        return ((self::BUCKET + $offset) * self::WINDOW) + $elapsed;
    }

    private function key(): string
    {
        return 'f129_' . uniqid('', true);
    }

    /**
     * The headline defect: two requests 31s apart, limit 1, window 60.
     *
     * Pre-fix this asserted false — 1 × 50% floored to 0, so the second
     * request saw an empty previous bucket.
     */
    public function testASecondRequestInsideOneWindowIsDeniedAtLimitOne(): void
    {
        $key = $this->key();

        $this->clockNow = $this->tAt(0, 59);
        self::assertTrue(
            RateLimiter::allowByKey($key, 1, self::WINDOW),
            'the first request must be allowed — otherwise this test proves nothing'
        );

        // 31 seconds later, still well inside a 60-second window.
        $this->clockNow = $this->tAt(1, 30);
        self::assertFalse(
            RateLimiter::allowByKey($key, 1, self::WINDOW),
            'a second request 31s after the first must be denied at limit 1'
        );
    }

    /**
     * Anti-vacuity for the case above: the fix must not simply deny
     * everything once a key has been seen.
     */
    public function testARequestAfterTheWindowHasPassedIsAllowed(): void
    {
        $key = $this->key();

        $this->clockNow = $this->tAt(0, 59);
        self::assertTrue(RateLimiter::allowByKey($key, 1, self::WINDOW), 'first request');

        // Two bucket flips on: the bucket holding the first request is no
        // longer the "previous" bucket, so its count is out of scope.
        $this->clockNow = $this->tAt(2, 0);
        self::assertTrue(
            RateLimiter::allowByKey($key, 1, self::WINDOW),
            'once the window has genuinely passed the request must be allowed again'
        );
    }

    /**
     * The discriminating case at a realistic limit: a previous-bucket count
     * of 1 must still cost half a slot at 50% weight.
     *
     * Pre-fix, 1 × 50% floored to 0 and all 10 slots were available, so 11
     * requests landed inside one window. The exact arithmetic leaves 9.
     */
    public function testASinglePreviousHitStillConsumesWeightAtLimitTen(): void
    {
        $key = $this->key();

        $this->clockNow = $this->tAt(0, 59);
        self::assertTrue(RateLimiter::allowByKey($key, 10, self::WINDOW), 'one hit in the first bucket');

        // Half-way into the next bucket: weight is 50%, so the single prior
        // hit counts as 0.5 and only 9 whole requests fit under the limit.
        $this->clockNow = $this->tAt(1, 30);
        for ($i = 1; $i <= 9; $i++) {
            self::assertTrue(
                RateLimiter::allowByKey($key, 10, self::WINDOW),
                "request {$i} of 9 must be allowed"
            );
        }

        self::assertFalse(
            RateLimiter::allowByKey($key, 10, self::WINDOW),
            'the 10th must be denied: 9 + the previous bucket\'s 0.5 exceeds 10'
        );
    }

    /**
     * A limit of 0 must deny.
     *
     * Pre-fix the [0, 2*limit] clamp collapsed effective to 0 for limit 0 and
     * `0 <= 0` ALLOWED every request. No caller passes 0 today
     * (getAdjustedLimit floors at 1, the per-IP cap at 10), so this was
     * latent rather than live — but it is the clamp's only real effect, and
     * dropping the clamp is part of this fix.
     */
    public function testALimitOfZeroDeniesEveryRequest(): void
    {
        $key = $this->key();

        $this->clockNow = $this->tAt(0, 10);
        self::assertFalse(RateLimiter::allowByKey($key, 0, self::WINDOW), 'limit 0 must deny');
        self::assertFalse(RateLimiter::allowByKey($key, 0, self::WINDOW), 'and keep denying');
    }

    /**
     * REGRESSION GUARD (passes pre- and post-fix).
     *
     * A fresh key with no previous bucket must get exactly its limit — the
     * fix must not tighten the ordinary path.
     */
    public function testAFreshKeyGetsExactlyItsLimit(): void
    {
        $key = $this->key();
        $this->clockNow = $this->tAt(0, 0);

        for ($i = 1; $i <= 30; $i++) {
            self::assertTrue(
                RateLimiter::allowByKey($key, 30, self::WINDOW),
                "request {$i} of 30 must be allowed on a fresh key"
            );
        }

        self::assertFalse(
            RateLimiter::allowByKey($key, 30, self::WINDOW),
            'the 31st must be denied'
        );
    }

    /**
     * REGRESSION GUARD (passes pre- and post-fix).
     *
     * The reason this class buckets at all: a full previous bucket at the
     * instant of a flip carries 100% weight, so the limit cannot be served
     * twice in two seconds across the boundary.
     */
    public function testAFullPreviousBucketStillBlocksTheBoundarySpike(): void
    {
        $key = $this->key();

        // Fill bucket 0 right at its end.
        $this->clockNow = $this->tAt(0, 59);
        for ($i = 1; $i <= 10; $i++) {
            self::assertTrue(RateLimiter::allowByKey($key, 10, self::WINDOW), "fill {$i} of 10");
        }

        // One second later the bucket flips; weight is 100%.
        $this->clockNow = $this->tAt(1, 0);
        self::assertFalse(
            RateLimiter::allowByKey($key, 10, self::WINDOW),
            'a full previous bucket at 100% weight must block the boundary spike'
        );
    }
}
