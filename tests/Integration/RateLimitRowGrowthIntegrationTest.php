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

    use BCC\Core\Cron\RateLimitRowCleanup;
    use BCC\Core\Repositories\DbMetricsRepository;
    use BCC\Trust\Core\Repositories\RateLimitRepository;
    use BCC\Trust\Core\Security\RateLimiter;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;

    /**
     * F-126: rate-limit rows grew without bound, and anonymous IPv4-mapped
     * clients shared one bucket. Against the REAL option-backed limiter on a
     * real server:
     *   - a fresh bucket writes ONE wp_options row, no _transient_timeout_*
     *     companion (WordPress only reaps those as a pair, so once the
     *     cleanup cron removed the main row nothing ever deleted them);
     *   - two anonymous IPv4-mapped clients (::ffff:a.b.c.d) on different
     *     networks get separate buckets; they all used to share "::";
     *   - with bcc-core's RateLimitRowCleanup present: every expired row of
     *     all three families is deleted and nothing live or unrelated is.
     *     (The cleanup it replaces selected by a '~' range that, under a
     *     unicode collation, matched none of these rows.) Skipped until that
     *     bcc-core change is on the checked-out sibling.
     */
    #[Group('integration')]
    #[CoversClass(RateLimiter::class)]
    #[CoversClass(RateLimitRepository::class)]
    final class RateLimitRowGrowthIntegrationTest extends TestCase
    {
        protected function setUp(): void
        {
            $wpdb = $GLOBALS['wpdb'];
            $wpdb->query("DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '\\_transient\\_bcc\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bcc\\_rl\\_%' OR option_name LIKE '\\_bcc\\_rl\\_%' OR option_name LIKE 'f126\\_%'");
            $GLOBALS['__bcc_test_object_cache'] = [];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['bcc_itest_current_user'], $_SERVER['REMOTE_ADDR']);
        }

        private static function countLike(string $prefix): int
        {
            $wpdb = $GLOBALS['wpdb'];
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            ));
        }

        public function testAFreshBucketWritesNoTimeoutCompanionRow(): void
        {
            self::assertTrue(RateLimiter::allowByKey('f126_probe', 5, 60));
            self::assertTrue(RateLimiter::allowByKey('f126_probe', 5, 60));

            self::assertSame(1, self::countLike('_transient_bcc_rl_'), 'one row per bucket');
            self::assertSame(0, self::countLike('_transient_timeout_bcc_rl_'), 'no companion that nothing would ever delete');
        }

        public function testAnonymousIpv4MappedClientsOnDifferentNetworksDoNotShareABucket(): void
        {
            $GLOBALS['bcc_itest_current_user'] = 0;
            $hit = static function (string $ip, int $tries): int {
                $_SERVER['REMOTE_ADDR'] = $ip;
                $allowed = 0;
                for ($i = 0; $i < $tries; $i++) {
                    if (RateLimiter::allow('f126_anon', 5, 86400)) {
                        $allowed++;
                    }
                }
                return $allowed;
            };

            self::assertSame(5, $hit('::ffff:203.0.113.7', 8), 'the first mapped client gets the anonymous 5');
            self::assertSame(5, $hit('::ffff:198.51.100.7', 8), 'a mapped client on another /24 has its own bucket (both were "::")');
            self::assertSame(0, $hit('203.0.113.99', 1), 'and a mapped client shares its IPv4 /24, like the plain address');
        }

        public function testTheCleanupCronDeletesEveryExpiredFamilyAndNothingElse(): void
        {
            if (!class_exists(RateLimitRowCleanup::class)) {
                self::markTestSkipped('needs bcc-core\'s RateLimitRowCleanup (F-126) on the sibling checkout');
            }

            $wpdb    = $GLOBALS['wpdb'];
            $past    = time() - 3600;
            $future  = time() + 3600;
            $rows = [
                // expired: must go
                ['_transient_bcc_rl_' . md5('a'), "4|{$past}"],
                ['_transient_bcc_rl_' . md5('b'), "9|{$past}"],
                ['_transient_timeout_bcc_rl_' . md5('a'), (string) $past],
                ['_transient_timeout_bcc_rl_' . md5('orphan'), (string) $past],
                ['_bcc_rl_' . md5('legacy'), "2|{$past}"],
                // live: must stay
                ['_transient_bcc_rl_' . md5('live'), "1|{$future}"],
                ['_transient_timeout_bcc_rl_' . md5('live'), (string) $future],
                // unrelated, expired-looking: must stay
                ['f126_bcc_rl_lookalike', "1|{$past}"],
            ];
            foreach ($rows as [$name, $value]) {
                self::assertNotFalse($wpdb->insert($wpdb->options, [
                    'option_name'  => $name,
                    'option_value' => $value,
                    'autoload'     => 'no',
                ]), $wpdb->last_error);
            }
            self::assertSame(4, DbMetricsRepository::countOptionsWithPrefix('_transient_bcc_rl_') + DbMetricsRepository::countOptionsWithPrefix('_bcc_rl_'), 'the health count sees the rows');

            $result = RateLimitRowCleanup::run();

            self::assertSame(['deleted' => 5, 'failed' => [], 'backlog' => false], $result);
            self::assertSame(1, self::countLike('_transient_bcc_rl_'));
            self::assertSame(1, self::countLike('_transient_timeout_bcc_rl_'));
            self::assertSame(0, self::countLike('_bcc_rl_'));
            self::assertSame(1, self::countLike('f126_bcc_rl_lookalike'));
        }
    }
}
