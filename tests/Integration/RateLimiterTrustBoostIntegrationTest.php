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
    use BCC\Trust\Core\Services\MemberSelfPageService;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;

    /**
     * F-219: the verified / trusted / elite rate-limit boosts never applied.
     * getAdjustedLimit() returned base × min(risk, trust) with risk <= 1.0 and
     * trust >= 1.0, so the boost could only ever lose the min.
     *
     * Now, against the REAL option-backed limiter and the real user_info /
     * page_scores rows on a real server:
     *   - an unpenalised user gets the boost on a BOOSTABLE_ACTIONS action;
     *   - a risk penalty still wins outright, whatever the boosts;
     *   - a fixed cap (downvote_daily) or an unlisted action is never boosted;
     *   - the shared /24 cap stays max(10, 4 × base): a boost raises the
     *     account's own bucket, never the subnet's (CRIT-2's flood bound).
     *
     * One-day window and explicit limits, so no bucket rolls over mid-test and
     * the sliding window's previous bucket is always empty.
     */
    #[Group('integration')]
    #[CoversClass(RateLimiter::class)]
    final class RateLimiterTrustBoostIntegrationTest extends TestCase
    {
        private const WINDOW = 86400;

        protected function setUp(): void
        {
            $wpdb = $GLOBALS['wpdb'];
            $wpdb->query("DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '\\_transient\\_bcc\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bcc\\_rl\\_%'");
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_user_info`');
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_page_scores`');
            $GLOBALS['__bcc_test_object_cache'] = [];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['bcc_itest_current_user'], $_SERVER['REMOTE_ADDR']);
        }

        private function seed(int $userId, int $fraudScore, float $trustRank, bool $verified, ?string $tier): void
        {
            $wpdb = $GLOBALS['wpdb'];
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_user_info', [
                'user_id'      => $userId,
                'user_login'   => 'f219u' . $userId,
                'user_email'   => 'f219u' . $userId . '@example.test',
                'display_name' => 'F219',
                'fraud_score'  => $fraudScore,
                'trust_rank'   => $trustRank,
                'is_verified'  => $verified ? 1 : 0,
            ]), $wpdb->last_error);
            if ($tier !== null) {
                self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_page_scores', [
                    'page_id'         => MemberSelfPageService::selfPageId($userId),
                    'category_id'     => 0,
                    'page_owner_id'   => $userId,
                    'reputation_tier' => $tier,
                ]), $wpdb->last_error);
            }
        }

        /** @return int how many of $tries were allowed */
        private function hit(int $userId, string $ip, string $action, int $tries, int $limit): int
        {
            $GLOBALS['bcc_itest_current_user'] = $userId;
            $_SERVER['REMOTE_ADDR']            = $ip;
            $allowed = 0;
            for ($i = 0; $i < $tries; $i++) {
                if (RateLimiter::allow($action, $limit, self::WINDOW)) {
                    $allowed++;
                }
            }

            return $allowed;
        }

        /**
         * A distinct user per row: ReputationRepository memoizes tiers for the
         * whole request (here, the whole PHPUnit process).
         *
         * @return array<string, array{int, int, float, bool, ?string, int}> user, fraud, rank, verified, tier, expected of base 10
         */
        public static function boosts(): array
        {
            return [
                'no boost'                   => [9901, 0, 0.0, false, null, 10],
                'verified (1.2×)'            => [9902, 0, 0.0, true, null, 12],
                'trusted tier (1.3×)'        => [9903, 0, 0.0, false, 'trusted', 13],
                'elite tier (1.5×)'          => [9904, 0, 0.0, false, 'elite', 15],
                'trust_rank > 0.8 (1.2×)'    => [9905, 0, 0.95, false, null, 12],
                'every boost (2.16×)'        => [9906, 0, 0.95, true, 'elite', 22],
                'critical risk beats boosts' => [9907, BCC_TRUST_FRAUD_CRITICAL, 0.95, true, 'elite', 2],
                'low risk beats boosts'      => [9908, BCC_TRUST_FRAUD_LOW, 0.95, true, 'elite', 8],
            ];
        }

        #[DataProvider('boosts')]
        public function testABoostRaisesAnUnpenalisedUsersLimitAndAPenaltyStillWins(
            int $userId,
            int $fraud,
            float $rank,
            bool $verified,
            ?string $tier,
            int $expected
        ): void {
            $this->seed($userId, $fraud, $rank, $verified, $tier);

            self::assertSame($expected, $this->hit($userId, '203.0.113.7', 'watch', 40, 10));
        }

        /** @return array<string, array{int, string, int}> */
        public static function unboostedActions(): array
        {
            return [
                'the daily downvote cap' => [9911, 'downvote_daily', 3],
                'an unlisted action'     => [9912, 'f219_unlisted', 10],
                'an OAuth callback'      => [9913, 'x_callback', 10],
            ];
        }

        #[DataProvider('unboostedActions')]
        public function testAFixedCapOrUnlistedActionIsNeverBoosted(int $userId, string $action, int $limit): void
        {
            $this->seed($userId, 0, 0.95, true, 'elite');

            self::assertSame($limit, $this->hit($userId, '203.0.113.7', $action, 40, $limit));
        }

        public function testABoostedFlooderStillLeavesItsNeighboursTheRestOfTheSubnet(): void
        {
            // Base 5 → subnet cap max(10, 20) = 20; the elite account's own
            // bucket is round(5 × 2.16) = 11.
            $this->seed(9921, 0, 0.95, true, 'elite');

            self::assertSame(11, $this->hit(9921, '203.0.113.7', 'watch', 50, 5), 'the boosted account gets its boost and no more');
            self::assertSame(5, $this->hit(9922, '203.0.113.20', 'watch', 5, 5), 'a neighbour still gets its full limit (16 of 20)');
            self::assertSame(4, $this->hit(9923, '203.0.113.30', 'watch', 5, 5), 'and the subnet stops at 20, not 4 × 11');
            self::assertSame(0, $this->hit(9924, '203.0.113.40', 'watch', 5, 5));
        }

        public function testABoostDoesNotRaiseTheSubnetCap(): void
        {
            // CRIT-2: four ordinary accounts spend the /24's 20. A boosted
            // account's subnet cap is still 20, not 4 × its own 11 = 44.
            $this->seed(9931, 0, 0.95, true, 'elite');
            for ($u = 9932; $u <= 9935; $u++) {
                self::assertSame(5, $this->hit($u, '203.0.113.' . ($u - 9900), 'watch', 5, 5));
            }

            self::assertSame(0, $this->hit(9931, '203.0.113.200', 'watch', 1, 5), 'the subnet is spent for boosted accounts too');
            // The refused try still spent one of its own 11 (pessimistic accounting).
            self::assertSame(10, $this->hit(9931, '198.51.100.4', 'watch', 20, 5), 'its boost is intact on another /24');
        }
    }
}
