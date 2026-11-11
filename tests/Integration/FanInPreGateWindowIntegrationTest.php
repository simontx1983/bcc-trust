<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security {
    // AuditLogger (written on a block) calls get_current_user_id()
    // unqualified. Same technique and backing global as
    // DownvoteDailyCapIntegrationTest, so whichever suite loads first
    // defines an identical function.
    if (!function_exists(__NAMESPACE__ . '\\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['bcc_itest_current_user'] ?? 0);
        }
    }
}

namespace BCC\Trust\Tests\Integration {

    use BCC\Trust\Core\Exceptions\VoteEligibilityException;
    use BCC\Trust\Core\Repositories\VoteRepository;
    use BCC\Trust\Core\Services\VoteService;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;

    /**
     * F-105 (user decision): the synchronous fan-in pre-gate counts distinct
     * voters over BCC_TRUST_FAN_IN_PRE_GATE_WINDOW_MINUTES (5), not 24 hours.
     *
     * Before the fix the gate read getRecentVotersWithRegistration($pageId, 1),
     * a ONE-DAY window, so any page with 15+ voters in a day refused every
     * account younger than 30 days for the rest of that day: ordinary
     * popularity locked newcomers out. A burst (15+ distinct voters inside
     * 5 minutes) must still be refused.
     */
    #[Group('integration')]
    #[CoversClass(VoteService::class)]
    #[CoversClass(VoteRepository::class)]
    final class FanInPreGateWindowIntegrationTest extends TestCase
    {
        private const PAGE  = 970001;
        private const VOTER = 970999;

        protected function setUp(): void
        {
            $wpdb = $GLOBALS['wpdb'];
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_votes`');
            $GLOBALS['__bcc_test_object_cache'] = [];
            $GLOBALS['bcc_itest_current_user']  = self::VOTER;

            (new \ReflectionProperty(VoteService::class, 'requestCache'))->setValue(null, []);
        }

        protected function tearDown(): void
        {
            (new \ReflectionProperty(VoteService::class, 'requestCache'))->setValue(null, []);
            unset($GLOBALS['bcc_itest_current_user']);
        }

        /** A vote cast $secondsAgo before now, in the site-local frame created_at is written in. */
        private function vote(int $voterId, int $secondsAgo, int $status = 1, int $categoryId = 0): void
        {
            $wpdb = $GLOBALS['wpdb'];
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_votes', [
                'voter_user_id' => $voterId,
                'page_id'       => self::PAGE,
                'category_id'   => $categoryId,
                'vote_type'     => 1,
                'weight'        => 1.0,
                'vested_weight' => 1.0,
                'status'        => $status,
                'created_at'    => gmdate('Y-m-d H:i:s', (int) current_time('timestamp') - $secondsAgo),
            ]), $wpdb->last_error);
        }

        private function since(int $minutes): string
        {
            return gmdate('Y-m-d H:i:s', (int) current_time('timestamp') - $minutes * 60);
        }

        private function runGate(): void
        {
            $ref = new \ReflectionClass(VoteService::class);
            $svc = $ref->newInstanceWithoutConstructor();
            // getVoterUserData() is request-cached; `false` (no user row)
            // gives an account age of 0 days, i.e. a young account the gate
            // applies to. Seeded HERE, not in setUp(): the cache entry lives
            // only 1 second, and inserting the fixture rows can outlast it.
            $ref->getProperty('requestCache')->setValue(null, ['userdata:' . self::VOTER => ['value' => false, 'cached_at' => time()]]);
            $ref->getProperty('voteRepo')->setValue($svc, new VoteRepository());
            $ref->getMethod('runFanInPreGate')->invoke($svc, self::VOTER, self::PAGE);
        }

        public function testCountsDistinctActiveVotersInsideTheWindowOnly(): void
        {
            $this->vote(1, 60);                       // in window
            $this->vote(2, 120);                      // in window
            $this->vote(3, 120);                      // in window, category 0 …
            $this->vote(3, 120, 1, 7);                // … and a legacy per-category row: still ONE voter
            $this->vote(4, 60, 0);                    // retracted: not counted
            $this->vote(5, 60, 3);                    // moderation-removed: not counted
            $this->vote(6, 10 * 60);                  // outside 5 minutes
            $this->vote(7, 6 * 3600);                 // earlier today

            $repo = new VoteRepository();
            self::assertSame(3, $repo->countRecentDistinctVoters(self::PAGE, $this->since(5)));
            self::assertSame(0, $repo->countRecentDistinctVoters(0, $this->since(5)));
            self::assertSame(0, $repo->countRecentDistinctVoters(self::PAGE, ''));
        }

        public function testABurstOfFifteenVotersInFiveMinutesBlocksAYoungAccount(): void
        {
            for ($v = 1; $v <= 15; $v++) {
                $this->vote($v, 30 + $v * 10);       // all within the last ~3 minutes
            }

            $this->expectException(VoteEligibilityException::class);
            $this->runGate();
        }

        public function testAPopularDayWithAQuietLastFiveMinutesDoesNotBlock(): void
        {
            // 14 voters inside the window (one under the threshold) plus 40
            // more spread across the day. A 24-hour window counts 54 and
            // blocks; the 5-minute window counts 14 and lets the vote in.
            for ($v = 1; $v <= 14; $v++) {
                $this->vote($v, 60);
            }
            for ($v = 100; $v < 140; $v++) {
                $this->vote($v, 10 * 60 + ($v - 100) * 1800);
            }

            $this->runGate();
            $this->addToAssertionCount(1); // reaching here = not blocked
        }
    }
}
