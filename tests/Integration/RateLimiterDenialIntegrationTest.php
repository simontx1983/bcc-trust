<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security {
    // Namespace-scoped actor for RateLimiter (and AuditLogger), which call
    // get_current_user_id() unqualified. Same technique and same backing
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
     * F-391: a 429 from RateLimiter::allow() (watch, unwatch, user status,
     * X share) carried no Retry-After. The REST Envelope only knew bcc-core's
     * Throttle denials. allow() now records its own, {limit, window, at,
     * retry_after}, in the shape Throttle uses (F-289), and the Envelope
     * picks it up (EnvelopeMetaTest).
     *
     * `retry_after` is the time until a retry passes EVERY bucket allow()
     * checks: for a member, their own bucket and their /24's, whichever is
     * longer. Each wait comes from retryAfterByKey(), whose exactness
     * RateLimitRetryAfterIntegrationTest proves; this pins which buckets
     * allow() asks about and what it records.
     *
     * Against the REAL option-backed limiter. The action is ad hoc with an
     * explicit limit and a one-day window, and the limiter's clock is pinned,
     * so no bucket rolls over and every wait is deterministic.
     */
    #[Group('integration')]
    #[CoversClass(RateLimiter::class)]
    final class RateLimiterDenialIntegrationTest extends TestCase
    {
        private const ACTION = 'f391_probe';
        private const WINDOW = 86400;
        private const LIMIT  = 5;
        /** normalizeIpToSubnet()'s /24 for every address used here. */
        private const SUBNET = '203.0.113.0';

        /** 13:20 into a day-long bucket, so the waits are not whole windows. */
        private int $t;

        protected function setUp(): void
        {
            $wpdb = $GLOBALS['wpdb'];
            $wpdb->query("DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '\\_transient\\_bcc\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bcc\\_rl\\_%'");
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_user_info`');
            $GLOBALS['__bcc_test_object_cache'] = [];
            $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

            $this->t = ((int) floor(time() / self::WINDOW)) * self::WINDOW + 48000;
            $t = $this->t;
            RateLimiter::setClockForTesting(static fn (): int => $t);
            (new \ReflectionProperty(RateLimiter::class, 'lastDenial'))->setValue(null, null);
        }

        protected function tearDown(): void
        {
            RateLimiter::setClockForTesting(null);
            (new \ReflectionProperty(RateLimiter::class, 'lastDenial'))->setValue(null, null);
            unset($GLOBALS['bcc_itest_current_user'], $_SERVER['REMOTE_ADDR']);
        }

        /** @return int how many of $tries were allowed */
        private function hit(int $userId, string $ip, int $tries): int
        {
            $GLOBALS['bcc_itest_current_user'] = $userId;
            $_SERVER['REMOTE_ADDR']            = $ip;
            $allowed = 0;
            for ($i = 0; $i < $tries; $i++) {
                if (RateLimiter::allow(self::ACTION, self::LIMIT, self::WINDOW)) {
                    $allowed++;
                }
            }

            return $allowed;
        }

        private static function userWait(int $userId): int
        {
            return RateLimiter::retryAfterByKey(self::ACTION . '_u_' . $userId, self::LIMIT, self::WINDOW);
        }

        private static function subnetWait(): int
        {
            // Limit 5, no trust row: the subnet cap is max(10, 5 × 4, 5 + 5 + 1) = 20.
            return RateLimiter::retryAfterByKey(self::ACTION . '_ip_' . self::SUBNET, 20, self::WINDOW);
        }

        public function testAnAllowedCallRecordsNothing(): void
        {
            self::assertSame(1, $this->hit(9801, '203.0.113.7', 1));
            self::assertNull(RateLimiter::lastDenial());
        }

        public function testAMemberOverTheirOwnLimitRecordsTheTimeLeftOnIt(): void
        {
            $before = time();
            self::assertSame(self::LIMIT, $this->hit(9802, '203.0.113.7', self::LIMIT + 1), 'the 6th call is refused');
            $after = time();

            $denial = RateLimiter::lastDenial();
            self::assertNotNull($denial);
            self::assertSame(self::LIMIT, $denial['limit'], 'the member bucket refused');
            self::assertSame(self::WINDOW, $denial['window']);
            self::assertGreaterThanOrEqual($before, $denial['at']);
            self::assertLessThanOrEqual($after, $denial['at']);

            $wait = self::userWait(9802);
            self::assertGreaterThan(self::subnetWait(), $wait, 'precondition: the member bucket is the longer wait');
            self::assertSame($wait, $denial['retry_after']);
            // 6 counted, limit 5, 48000 s into the day. In the next bucket a
            // retry is call 1 and this bucket weighs in whole percents:
            // 1 + 6 × w <= 5 means w <= 66 %, so 34 % in, 29376 s.
            self::assertSame((self::WINDOW - 48000) + 29376, $denial['retry_after']);
        }

        public function testASubnetRefusalRecordsTheSubnetBucket(): void
        {
            // Four neighbours use their whole limit: the /24's cap of 20 is full.
            foreach ([9811, 9812, 9813, 9814] as $id) {
                self::assertSame(self::LIMIT, $this->hit($id, '203.0.113.' . ($id - 9800), self::LIMIT));
            }
            self::assertNull(RateLimiter::lastDenial(), 'nobody was refused yet');

            self::assertSame(0, $this->hit(9815, '203.0.113.99', 1), 'a fresh member is refused by the subnet');

            $denial = RateLimiter::lastDenial();
            self::assertNotNull($denial);
            self::assertSame(20, $denial['limit'], 'the subnet bucket refused');
            self::assertSame(self::subnetWait(), $denial['retry_after']);
            self::assertSame(0, self::userWait(9815), 'their own bucket has room');
        }

        /**
         * The member's own bucket refuses, but the subnet is fuller still: a
         * retry after the member's wait would hit the subnet, so the wait is
         * the subnet's.
         */
        public function testTheLongerOfTheTwoBucketsIsTheWait(): void
        {
            foreach ([9821, 9822, 9823, 9824] as $id) {
                $this->hit($id, '203.0.113.' . ($id - 9800), self::LIMIT);
            }
            // Six more neighbours each try 5 times. Their own buckets allow
            // it, the subnet refuses, and every refusal counts on the subnet.
            foreach ([9825, 9826, 9827, 9828, 9829, 9830] as $id) {
                self::assertSame(0, $this->hit($id, '203.0.113.' . ($id - 9800), self::LIMIT));
            }
            // The member: 5 subnet refusals, then their own bucket is full.
            self::assertSame(0, $this->hit(9831, '203.0.113.31', self::LIMIT + 1));

            $denial = RateLimiter::lastDenial();
            self::assertNotNull($denial);
            self::assertSame(self::LIMIT, $denial['limit'], 'the member bucket refused this one');
            self::assertGreaterThan(self::userWait(9831), self::subnetWait(), 'precondition: the subnet is the longer wait');
            self::assertSame(self::subnetWait(), $denial['retry_after']);
        }

        public function testAnAnonymousRefusalRecordsItsBucket(): void
        {
            // Anonymous: capped at 5 and keyed on the /24, not a member id.
            self::assertSame(self::LIMIT, $this->hit(0, '203.0.113.7', self::LIMIT + 1));

            $denial = RateLimiter::lastDenial();
            self::assertNotNull($denial);
            self::assertSame(self::LIMIT, $denial['limit']);
            self::assertSame(
                RateLimiter::retryAfterByKey(self::ACTION . '_anon_' . self::SUBNET, self::LIMIT, self::WINDOW),
                $denial['retry_after']
            );
            self::assertGreaterThan(0, $denial['retry_after']);
        }
    }
}
