<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security {
    // Namespace-scoped actor for RateLimiter (and AuditLogger), which call
    // get_current_user_id() unqualified. Same technique and same backing
    // global as DownvoteDailyCapIntegrationTest, so whichever suite loads
    // first defines an identical function.
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
     * F-155: one flooding account locked its whole /24 out of watch, vote and
     * share. RateLimiter::allow() charged the shared subnet bucket for every
     * request, including the ones the account's own bucket had already
     * refused, so a single account past its limit kept filling the subnet
     * bucket until nobody else on it got through.
     *
     * Against the REAL option-backed limiter on a real server. Every account
     * here shares one /24; the action is ad hoc with an explicit limit and a
     * one-day window, so no bucket rolls over mid-test.
     */
    #[Group('integration')]
    #[CoversClass(RateLimiter::class)]
    final class RateLimiterSubnetBucketIntegrationTest extends TestCase
    {
        private const ACTION = 'f155_probe';
        private const WINDOW = 86400;

        protected function setUp(): void
        {
            $wpdb = $GLOBALS['wpdb'];
            $wpdb->query("DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '\\_transient\\_bcc\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bcc\\_rl\\_%'");
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_user_info`');
            $GLOBALS['__bcc_test_object_cache'] = [];
            $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['bcc_itest_current_user'], $_SERVER['REMOTE_ADDR']);
        }

        /** @return int how many of $tries were allowed */
        private function hit(int $userId, string $ip, int $tries, int $limit): int
        {
            $GLOBALS['bcc_itest_current_user'] = $userId;
            $_SERVER['REMOTE_ADDR']            = $ip;
            $allowed = 0;
            for ($i = 0; $i < $tries; $i++) {
                if (RateLimiter::allow(self::ACTION, $limit, self::WINDOW)) {
                    $allowed++;
                }
            }

            return $allowed;
        }

        public function testOneFloodingAccountDoesNotLockItsSubnetNeighboursOut(): void
        {
            // Limit 5 → subnet cap 20. The flooder sends 10× its limit.
            self::assertSame(5, $this->hit(9701, '203.0.113.7', 50, 5), 'the flooder gets its own 5 and no more');

            self::assertSame(5, $this->hit(9702, '203.0.113.99', 5, 5), 'a neighbour on the same /24 still gets its full limit');
        }

        public function testAPenalisedAccountIsNotLockedOutByOneOrdinaryNeighbour(): void
        {
            // Critical fraud score → 0.2× → its own limit is 4 of 20, and its
            // subnet cap used to be max(10, 16) = 16: below ONE ordinary
            // neighbour's normal 20.
            $wpdb = $GLOBALS['wpdb'];
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_user_info', [
                'user_id'      => 9711,
                'user_login'   => 'penalised9711',
                'user_email'   => 'penalised9711@example.test',
                'display_name' => 'Penalised',
                'fraud_score'  => BCC_TRUST_FRAUD_CRITICAL,
                'trust_rank'   => 0,
                'is_verified'  => 0,
            ]), $wpdb->last_error);

            self::assertSame(20, $this->hit(9712, '203.0.113.7', 20, 20), 'the neighbour uses exactly its allowance');

            self::assertSame(4, $this->hit(9711, '203.0.113.99', 10, 20), 'the penalised account still gets its own 4, and no more');
        }

        public function testManyAccountsOnOneSubnetAreStillCappedTogether(): void
        {
            // CRIT-2 must still hold: limit 5 → subnet cap 20 = four full
            // accounts. The fifth account on the /24 is refused outright.
            for ($u = 9721; $u <= 9724; $u++) {
                self::assertSame(5, $this->hit($u, '203.0.113.' . ($u - 9700), 5, 5));
            }

            self::assertSame(0, $this->hit(9725, '203.0.113.200', 5, 5), 'the subnet is spent');
            self::assertSame(5, $this->hit(9726, '198.51.100.4', 5, 5), 'another /24 is untouched');
        }
    }
}
