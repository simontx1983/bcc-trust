<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security {
    // Namespace-scoped actor for RateLimiter, which calls
    // get_current_user_id() unqualified. Same function and same backing
    // global as RateLimiterSubnetBucketIntegrationTest, so whichever suite
    // loads first defines an identical function.
    if (!function_exists(__NAMESPACE__ . '\\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['bcc_itest_current_user'] ?? 0);
        }
    }
}

namespace BCC\Trust\Tests\Integration {

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
     *     below zero;
     *   - RateLimiter::allow() (votes, endorsements and the other action
     *     limits) is NOT opted out: its per-user, per-subnet and anonymous
     *     buckets all keep counting refused calls.
     */
    #[Group('integration')]
    #[CoversClass(RateLimiter::class)]
    final class RateLimitCountDeniedIntegrationTest extends TestCase
    {
        private const WINDOW = 60;

        /** Arbitrary fixed bucket index, so the test never depends on wall clock. */
        private const BUCKET = 31000000;

        private const ACTION = 'f290_probe';

        private const IP = '203.0.113.7';

        protected function setUp(): void
        {
            $wpdb = $GLOBALS['wpdb'];
            $wpdb->query('TRUNCATE TABLE `wp_options`');
            // allow() reads trust info for authenticated callers; none here, so
            // every account gets the base limit.
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_user_info`');
            $GLOBALS['__bcc_test_object_cache'] = [];
            $_SERVER['REMOTE_ADDR'] = self::IP;
            // Mid-bucket, with the previous bucket empty.
            RateLimiter::setClockForTesting(static fn (): int => (self::BUCKET * self::WINDOW) + 30);
        }

        protected function tearDown(): void
        {
            RateLimiter::setClockForTesting(null);
            unset($GLOBALS['bcc_itest_current_user'], $_SERVER['REMOTE_ADDR']);
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

        /** @return int how many of $tries RateLimiter::allow() let through */
        private function act(int $userId, string $ip, int $tries, int $limit): int
        {
            $GLOBALS['bcc_itest_current_user'] = $userId;
            $_SERVER['REMOTE_ADDR']            = $ip;
            $allowed = 0;
            for ($i = 0; $i < $tries; $i++) {
                $allowed += RateLimiter::allow(self::ACTION, $limit, self::WINDOW) ? 1 : 0;
            }

            return $allowed;
        }

        private static function subnetKey(): string
        {
            return self::ACTION . '_ip_' . RateLimiter::normalizeIpToSubnet(self::IP);
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

        public function testAnAccountsActionLimitStillCountsRefusedCalls(): void
        {
            self::assertSame(5, $this->act(9801, self::IP, 12, 5));

            self::assertSame(12, $this->storedCount(self::ACTION . '_u_9801'), 'the seven refused calls were recorded');
            self::assertSame(5, $this->storedCount(self::subnetKey()), 'F-155: the subnet is charged only for allowed calls');
        }

        public function testTheSubnetActionLimitStillCountsRefusedCalls(): void
        {
            // Limit 5 → subnet cap 20: four full accounts spend it.
            for ($u = 9811; $u <= 9814; $u++) {
                self::assertSame(5, $this->act($u, '203.0.113.' . ($u - 9800), 5, 5));
            }

            self::assertSame(0, $this->act(9815, '203.0.113.99', 3, 5), 'the subnet is spent');

            self::assertSame(23, $this->storedCount(self::subnetKey()), 'the three refused calls were recorded');
        }

        public function testAnAnonymousActionLimitStillCountsRefusedCalls(): void
        {
            // Anonymous callers are capped at 5 whatever the action's limit.
            self::assertSame(5, $this->act(0, self::IP, 9, 10));

            $anonKey = self::ACTION . '_anon_' . RateLimiter::normalizeIpToSubnet(self::IP);
            self::assertSame(9, $this->storedCount($anonKey), 'the four refused calls were recorded');
        }
    }
}
