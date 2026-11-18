<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Security\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-290 follow-up — RateLimiter::allowByKey(..., $countDenied) and
 * releaseByKey(), against the real wp_options counter rows (real MySQL /
 * MariaDB, the clock frozen so no test sleeps).
 *
 *   - default (every existing caller): a denied call still uses a slot —
 *     pessimistic accounting, the row keeps growing;
 *   - countDenied = false (auth routes): a denied call gives its slot back,
 *     so the row stays at the limit however often a blocked caller retries,
 *     and a flooder still gets at most the limit in a window;
 *   - releaseByKey(): gives back one slot of the current bucket, never
 *     below zero.
 */
#[Group('integration')]
#[CoversClass(RateLimiter::class)]
final class RateLimitCountDeniedIntegrationTest extends TestCase
{
    private const WINDOW = 60;

    /** Arbitrary fixed bucket index, so the test never depends on wall clock. */
    private const BUCKET = 31000000;

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `wp_options`');
        // Mid-bucket, with the previous bucket empty.
        RateLimiter::setClockForTesting(static fn (): int => (self::BUCKET * self::WINDOW) + 30);
    }

    protected function tearDown(): void
    {
        RateLimiter::setClockForTesting(null);
    }

    private function key(): string
    {
        return 'f290_' . uniqid('', true);
    }

    /** The current bucket's stored count for $key. */
    private function storedCount(string $key): int
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            '_transient_bcc_rl_' . md5($key . '_b' . self::BUCKET)
        ));
        return $value === null ? 0 : (int) explode('|', (string) $value)[0];
    }

    public function testByDefaultADeniedCallStillUsesASlot(): void
    {
        $key = $this->key();
        $allowed = 0;
        for ($i = 0; $i < 5; $i++) {
            $allowed += RateLimiter::allowByKey($key, 2, self::WINDOW) ? 1 : 0;
        }

        self::assertSame(2, $allowed);
        self::assertSame(5, $this->storedCount($key), 'pessimistic: every call recorded');
    }

    public function testOptedOutADeniedCallGivesItsSlotBack(): void
    {
        $key = $this->key();
        $allowed = 0;
        for ($i = 0; $i < 12; $i++) {
            $allowed += RateLimiter::allowByKey($key, 3, self::WINDOW, false) ? 1 : 0;
        }

        self::assertSame(3, $allowed, 'a flooder still gets at most the limit in the window');
        self::assertSame(3, $this->storedCount($key), 'nine refused retries recorded nothing');
    }

    public function testReleaseGivesBackOneSlotAndNeverGoesBelowZero(): void
    {
        $key = $this->key();
        RateLimiter::allowByKey($key, 5, self::WINDOW, false);
        RateLimiter::allowByKey($key, 5, self::WINDOW, false);

        RateLimiter::releaseByKey($key, self::WINDOW);
        self::assertSame(1, $this->storedCount($key));

        RateLimiter::releaseByKey($key, self::WINDOW);
        RateLimiter::releaseByKey($key, self::WINDOW);
        self::assertSame(0, $this->storedCount($key), 'clamped at zero');

        self::assertTrue(RateLimiter::allowByKey($key, 1, self::WINDOW, false), 'a released slot is usable again');
    }

    public function testReleaseOnAKeyNeverSeenIsHarmless(): void
    {
        $key = $this->key();
        RateLimiter::releaseByKey($key, self::WINDOW);

        self::assertSame(0, $this->storedCount($key));
        self::assertTrue(RateLimiter::allowByKey($key, 1, self::WINDOW));
    }
}
