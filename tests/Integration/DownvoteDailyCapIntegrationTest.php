<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security {
    // Namespace-scoped actor for RateLimiter (and AuditLogger), which call
    // get_current_user_id() unqualified. Same technique and same backing
    // global as AuditLoggerMetaWriteIntegrationTest, so whichever suite
    // loads first defines an identical function.
    if (!function_exists(__NAMESPACE__ . '\\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['bcc_itest_current_user'] ?? 0);
        }
    }
}

namespace BCC\Trust\Tests\Integration {

    use BCC\Trust\Core\DTO\VoteEligibilityCacheEntry;
    use BCC\Trust\Core\Exceptions\VoteEligibilityException;
    use BCC\Trust\Core\Repositories\ReputationRepository;
    use BCC\Trust\Core\Repositories\UserInfoRepository;
    use BCC\Trust\Core\Repositories\VoteRepository;
    use BCC\Trust\Core\Security\RateLimiter;
    use BCC\Trust\Core\Services\MemberSelfPageService;
    use BCC\Trust\Core\Services\Vote\VoteEligibilityChecker;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;

    /**
     * F-152 / F-153: the 3-per-day downvote cap, against the REAL
     * option-backed RateLimiter on MySQL.
     *
     * ## F-152: the cache bypass
     * The cap ran inside assertDownvoteEligibility(), which only runs on an
     * eligibility-cache MISS. A miss cached "eligible" for 120s, and nothing
     * on the vote path invalidates that entry, so every downvote inside the
     * TTL skipped the cap entirely. These tests SEED the cached "eligible"
     * verdict, which is exactly the state a real burst runs in, and require
     * the 4th downvote to be refused.
     *
     * ## F-153: resetIn() window
     * `downvote_daily` is not in RateLimiter::getLimits(), so resetIn() fell
     * back to the 60s DEFAULT_WINDOW, indexed a bucket that never exists, and
     * told a capped voter to "wait 0 seconds". The window is now passed through.
     *
     * The voter is elite, verified and trust_rank > 0.8, i.e. every boost the
     * limiter knows. That pins the cap at exactly 3 for the users it applies
     * to: 'downvote_daily' is not one of RateLimiter's BOOSTABLE_ACTIONS
     * (F-219), so no boost can raise it (F-153's "5-6/day" claim does not
     * reproduce).
     */
    #[Group('integration')]
    #[CoversClass(VoteEligibilityChecker::class)]
    #[CoversClass(RateLimiter::class)]
    final class DownvoteDailyCapIntegrationTest extends TestCase
    {
        private const VOTER = 9601;

        protected function setUp(): void
        {
            $wpdb = $GLOBALS['wpdb'];
            $wpdb->query("DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '\\_transient\\_bcc\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bcc\\_rl\\_%'");
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_votes`');
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_page_scores`');
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_user_info`');
            $GLOBALS['__bcc_test_object_cache'] = [];

            $GLOBALS['bcc_itest_current_user'] = self::VOTER;
            $_SERVER['REMOTE_ADDR']            = '203.0.113.7';

            // Every trust boost RateLimiter knows: verified, elite, trust_rank > 0.8.
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_user_info', [
                'user_id'      => self::VOTER,
                'user_login'   => 'voter' . self::VOTER,
                'user_email'   => 'voter' . self::VOTER . '@example.test',
                'display_name' => 'Voter',
                'fraud_score'  => 0,
                'trust_rank'   => 0.95,
                'is_verified'  => 1,
            ]), $wpdb->last_error);
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_page_scores', [
                'page_id'         => MemberSelfPageService::selfPageId(self::VOTER),
                'category_id'     => 0,
                'page_owner_id'   => self::VOTER,
                'reputation_tier' => 'elite',
            ]), $wpdb->last_error);
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['bcc_itest_current_user'], $_SERVER['REMOTE_ADDR']);
        }

        /** The state a real burst runs in: user-level eligibility already cached. */
        private function seedCachedEligible(int $voteType): void
        {
            wp_cache_set(
                'eligibility_' . self::VOTER . '_' . $voteType,
                new VoteEligibilityCacheEntry(is_eligible: true, reason: null),
                'bcc_trust',
                120
            );
        }

        private function checker(): VoteEligibilityChecker
        {
            // Only the general per-minute vote limiter is neutralised: it is a
            // separate counter, and leaving it live would make this test about
            // the wrong limit. The downvote cap under test is NOT overridden.
            return new class (new VoteRepository(), new ReputationRepository(), new UserInfoRepository()) extends VoteEligibilityChecker {
                protected function enforceRateLimit(): void {}
            };
        }

        /** Drive the voter-level gates (cache → duplicate → counters). */
        private function gates(VoteEligibilityChecker $checker, int $pageId, int $voteType): void
        {
            $m = new \ReflectionMethod(VoteEligibilityChecker::class, 'assertVoterGates');
            $m->setAccessible(true);
            $m->invoke($checker, self::VOTER, $pageId, $voteType);
        }

        public function testTheFourthDownvoteIsRefusedEvenWhileEligibilityIsCached(): void
        {
            $this->seedCachedEligible(-1);
            $checker = $this->checker();

            for ($i = 1; $i <= VoteEligibilityChecker::DOWNVOTE_DAILY_CAP; $i++) {
                $this->gates($checker, 990000 + $i, -1);
            }

            try {
                $this->gates($checker, 990099, -1);
                self::fail('the downvote past the daily cap must be refused, cached eligibility or not');
            } catch (VoteEligibilityException $e) {
                self::assertSame('Daily downvote limit reached.', $e->getMessage());
            }
        }

        public function testUpvotesDoNotSpendTheDownvoteCap(): void
        {
            $this->seedCachedEligible(1);
            $this->seedCachedEligible(-1);
            $checker = $this->checker();

            for ($i = 1; $i <= 5; $i++) {
                $this->gates($checker, 991000 + $i, 1);
            }
            for ($i = 1; $i <= VoteEligibilityChecker::DOWNVOTE_DAILY_CAP; $i++) {
                $this->gates($checker, 992000 + $i, -1);
            }

            $this->addToAssertionCount(1); // reaching here = all 3 downvotes allowed
        }

        public function testARefusedDuplicateDownvoteDoesNotSpendTheCap(): void
        {
            $this->seedCachedEligible(-1);
            $wpdb = $GLOBALS['wpdb'];
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_votes', [
                'voter_user_id' => self::VOTER,
                'page_id'       => 993000,
                'vote_type'     => -1,
                'weight'        => 1.0,
                'status'        => 1,
                'created_at'    => gmdate('Y-m-d H:i:s'),
            ]), $wpdb->last_error);

            $checker = $this->checker();
            for ($i = 0; $i < 3; $i++) {
                try {
                    $this->gates($checker, 993000, -1);
                    self::fail('a repeated downvote on the same page is a duplicate');
                } catch (VoteEligibilityException $e) {
                    self::assertSame('You have already voted on this page.', $e->getMessage());
                }
            }

            for ($i = 1; $i <= VoteEligibilityChecker::DOWNVOTE_DAILY_CAP; $i++) {
                $this->gates($checker, 994000 + $i, -1);
            }
            $this->addToAssertionCount(1);
        }

        public function testACappedVoterIsToldTheRealWaitNotZero(): void
        {
            for ($i = 0; $i < VoteEligibilityChecker::DOWNVOTE_DAILY_CAP; $i++) {
                self::assertTrue(RateLimiter::allow('downvote_daily', VoteEligibilityChecker::DOWNVOTE_DAILY_CAP, DAY_IN_SECONDS));
            }

            try {
                RateLimiter::enforce('downvote_daily', VoteEligibilityChecker::DOWNVOTE_DAILY_CAP, DAY_IN_SECONDS);
                self::fail('the 4th downvote_daily hit must be refused');
            } catch (\Exception $e) {
                self::assertSame(429, $e->getCode());
                self::assertMatchesRegularExpression('/wait (\d+) seconds/', $e->getMessage());
                preg_match('/wait (\d+) seconds/', $e->getMessage(), $m);
                // The 24h bucket expires (bucket + 2) windows out: always > 1 day away.
                self::assertGreaterThan(DAY_IN_SECONDS, (int) $m[1], 'reset time must come from the 24h bucket, not a 60s one');
            }
        }
    }
}
