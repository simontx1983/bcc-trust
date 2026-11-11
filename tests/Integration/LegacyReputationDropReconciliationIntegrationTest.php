<?php

declare(strict_types=1);

namespace {
    // The migration files register themselves with add_action() at load time.
    // The integration bootstrap does not stub it; mirror the guarded stub other
    // migration suites use so loading the REAL production file is a no-op hook.
    if (!function_exists('add_action')) {
        function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool
        {
            return true;
        }
    }
}

namespace BCC\Trust\Tests\Integration {

    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;

    /**
     * F-148 — the retired bcc_trust_reputation table must never be dropped
     * before every member's legacy score is provably on their self-page.
     *
     * Before the fix, two independent holes compounded:
     *   1. seed-self-pages.php treated a FAILED read as "no rows"
     *      (`$rows ?: []`) and stamped `bcc_trust_self_pages_seeded`
     *      unconditionally;
     *   2. drop-legacy-reputation.php trusted that stamp as its ONLY
     *      interlock and dropped the table in the same request.
     *
     * Every case runs the REAL production functions against a REAL server.
     * Failure branches are reached with the shim's fault injection (a server
     * error on one specific statement), never with a mock — so a test cannot
     * assert the branch it wrote instead of the branch the code takes.
     */
    #[Group('integration')]
    #[Group('mariadb')]
    final class LegacyReputationDropReconciliationIntegrationTest extends TestCase
    {
        private const SEEDED_OPTION    = 'bcc_trust_self_pages_seeded';
        private const DROPPED_OPTION   = 'bcc_trust_reputation_dropped';
        private const REFUSED_TRANSIENT = 'bcc_trust_reputation_drop_refused';

        private string $legacy = '';
        private string $scores = '';

        protected function setUp(): void
        {
            parent::setUp();
            require_once dirname(__DIR__, 2) . '/includes/database/seed-self-pages.php';
            require_once dirname(__DIR__, 2) . '/includes/database/drop-legacy-reputation.php';

            global $wpdb;
            $wpdb->clearFaultInjection();
            $this->legacy = $wpdb->prefix . 'bcc_trust_reputation';
            $this->scores = $wpdb->prefix . 'bcc_trust_page_scores';

            unset(
                $GLOBALS['__bcc_test_options'][self::SEEDED_OPTION],
                $GLOBALS['__bcc_test_options'][self::DROPPED_OPTION],
                $GLOBALS['__bcc_test_transients'][self::REFUSED_TRANSIENT]
            );

            $wpdb->query("DROP TABLE IF EXISTS `{$this->legacy}`");
            $wpdb->query(
                "CREATE TABLE `{$this->legacy}` (
                    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                    reputation_score DECIMAL(6,2) NOT NULL DEFAULT 50.00
                 ) ENGINE=InnoDB"
            );
            self::assertSame('', $wpdb->last_error, 'fixture: legacy table must be creatable');
            $wpdb->query("DELETE FROM `{$this->scores}` WHERE page_id > " . self::selfPageBase());
        }

        protected function tearDown(): void
        {
            global $wpdb;
            $wpdb->clearFaultInjection();
            $wpdb->query("DROP TABLE IF EXISTS `{$this->legacy}`");
            $wpdb->query("DELETE FROM `{$this->scores}` WHERE page_id > " . self::selfPageBase());
            parent::tearDown();
        }

        // ── drop-legacy-reputation.php ─────────────────────────────────────

        public function testDropIsRefusedWhenAMemberHasNoSelfPage(): void
        {
            $this->seedLegacy(7, 82.0);
            $this->markSeeded(); // the stamp alone is exactly what F-148 says is not proof

            \bcc_trust_drop_legacy_reputation();

            $this->assertTableExists(true);
            self::assertFalse(\get_option(self::DROPPED_OPTION), 'a refused drop must not be stamped done');
            self::assertNotFalse(\get_transient(self::REFUSED_TRANSIENT), 'a refusal backs off for an hour');
        }

        public function testDropIsRefusedWhenASelfPageIsStillPristineForANonNeutralScore(): void
        {
            $this->seedLegacy(8, 20.0);
            $this->insertSelfPage(8, 0.0, 0.0); // row exists, but the score never landed
            $this->markSeeded();

            \bcc_trust_drop_legacy_reputation();

            $this->assertTableExists(true);
            self::assertFalse(\get_option(self::DROPPED_OPTION));
        }

        public function testDropProceedsOnceEveryScoreIsReconciled(): void
        {
            $this->seedLegacy(9, 82.0);
            $this->seedLegacy(10, 50.0); // neutral seeds to an all-zero row — that IS reconciled
            $this->insertSelfPage(9, 16.0, 0.0);
            $this->insertSelfPage(10, 0.0, 0.0);
            $this->markSeeded();

            \bcc_trust_drop_legacy_reputation();

            $this->assertTableExists(false);
            self::assertNotFalse(\get_option(self::DROPPED_OPTION));
        }

        public function testDropIsRefusedWhenTheReconciliationCheckItselfFails(): void
        {
            global $wpdb;
            $this->seedLegacy(9, 82.0);
            $this->insertSelfPage(9, 16.0, 0.0); // would reconcile — but the check cannot answer
            $this->markSeeded();

            $wpdb->failQueriesMatching = '/LEFT JOIN\s+\S*bcc_trust_page_scores/i';
            \bcc_trust_drop_legacy_reputation();
            self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
            $wpdb->clearFaultInjection();

            $this->assertTableExists(true);
            self::assertFalse(\get_option(self::DROPPED_OPTION), 'unknown must be treated as unreconciled');
        }

        public function testAFailedDropIsNotStampedDone(): void
        {
            global $wpdb;
            $this->seedLegacy(9, 82.0);
            $this->insertSelfPage(9, 16.0, 0.0);
            $this->markSeeded();

            $wpdb->failQueriesMatching = '/^\s*DROP TABLE/i';
            \bcc_trust_drop_legacy_reputation();
            self::assertGreaterThan(0, $wpdb->injectedFailures);
            $wpdb->clearFaultInjection();

            self::assertFalse(\get_option(self::DROPPED_OPTION), 'a DROP that errored must retry, not be marked done');
        }

        // ── seed-self-pages.php ────────────────────────────────────────────

        public function testAFailedLegacyReadDoesNotStampTheSeedComplete(): void
        {
            global $wpdb;
            $this->seedLegacy(11, 82.0);

            $wpdb->failQueriesMatching = '/SELECT user_id, reputation_score/i';
            \bcc_trust_seed_self_pages();
            self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
            $wpdb->clearFaultInjection();

            self::assertFalse(\get_option(self::SEEDED_OPTION), 'a failed read is not "no rows"');

            // And so the drop's interlock still holds the table.
            \bcc_trust_drop_legacy_reputation();
            $this->assertTableExists(true);
        }

        /**
         * Anti-vacuity: the seed CAN complete and stamp, and what it writes is
         * exactly what the drop's reconciliation accepts — so the two halves
         * agree on what "relocated" means.
         */
        public function testAHealthySeedStampsAndThenAllowsTheDrop(): void
        {
            $this->seedLegacy(12, 82.0);
            $this->seedLegacy(13, 50.0);

            \bcc_trust_seed_self_pages();
            self::assertNotFalse(\get_option(self::SEEDED_OPTION), 'a clean full walk stamps the seed');
            self::assertSame(0, \bcc_trust_count_unreconciled_reputation($this->legacy));

            \bcc_trust_drop_legacy_reputation();
            $this->assertTableExists(false);
            self::assertNotFalse(\get_option(self::DROPPED_OPTION));
        }

        // ── #396 review follow-up: the refusal must not be permanent ───────

        /**
         * A KNOWN shortfall clears the seed stamp, so the idempotent seed
         * walks again and the system converges instead of refusing hourly
         * forever. End-to-end: refuse → re-seed → drop.
         */
        public function testAKnownShortfallClearsTheSeedStampAndTheSystemSelfHeals(): void
        {
            $this->seedLegacy(14, 82.0);
            $this->markSeeded(); // stamped, but member 14 was never relocated

            \bcc_trust_drop_legacy_reputation();
            $this->assertTableExists(true);
            self::assertFalse(\get_option(self::SEEDED_OPTION), 'a known shortfall must clear the seed stamp');

            \bcc_trust_seed_self_pages(); // the next request's seed re-walks
            self::assertNotFalse(\get_option(self::SEEDED_OPTION));

            unset($GLOBALS['__bcc_test_transients'][self::REFUSED_TRANSIENT]); // the hour passes
            \bcc_trust_drop_legacy_reputation();
            $this->assertTableExists(false);
            self::assertNotFalse(\get_option(self::DROPPED_OPTION));
        }

        /** An UNKNOWN answer (the check failed) leaves the seed stamp alone. */
        public function testAnUnknownRefusalKeepsTheSeedStamp(): void
        {
            global $wpdb;
            $this->seedLegacy(15, 82.0);
            $this->markSeeded();

            $wpdb->failQueriesMatching = '/LEFT JOIN\s+\S*bcc_trust_page_scores/i';
            \bcc_trust_drop_legacy_reputation();
            self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
            $wpdb->clearFaultInjection();

            $this->assertTableExists(true);
            self::assertNotFalse(\get_option(self::SEEDED_OPTION), 'a failed check is not evidence the copy failed');
        }

        /**
         * The seed stores |delta| / scale in DECIMAL(5,2). A delta that rounds
         * to 0.00 there leaves a correctly-seeded row all-zero; the predicate
         * must accept that row rather than refuse forever. Whatever the
         * configured scale, the expectation follows the 0.006 threshold.
         */
        public function testASubRoundingDeltaFollowsTheStorageThreshold(): void
        {
            $scale = \BCC\Trust\Core\Services\TrustScoreService::weightScoreScale();
            $delta = max(0.01, round(0.005 * $scale, 2)); // smallest 2dp delta at/under the rounding point
            $this->seedLegacy(16, 50.0 + $delta);
            $this->insertSelfPage(16, 0.0, 0.0); // what a seed of this delta can leave stored

            $expected = ($delta / $scale) >= 0.006 ? 1 : 0;
            self::assertSame(
                $expected,
                \bcc_trust_count_unreconciled_reputation($this->legacy),
                "delta {$delta} at scale {$scale}"
            );
        }

        // ── helpers ────────────────────────────────────────────────────────

        private static function selfPageBase(): int
        {
            return \BCC\Trust\Core\Services\MemberSelfPageService::selfPageId(1) - 1;
        }

        private function seedLegacy(int $userId, float $score): void
        {
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$this->legacy}` (user_id, reputation_score) VALUES (%d, %f)",
                $userId,
                $score
            ));
            self::assertSame('', $wpdb->last_error, 'fixture: legacy row insert');
        }

        private function insertSelfPage(int $userId, float $positive, float $negative): void
        {
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$this->scores}`
                    (page_id, category_id, page_owner_id, total_score, positive_score, negative_score,
                     vote_count, unique_voters, confidence_score, reputation_tier, endorsement_count,
                     onchain_bonus, contribution_bonus, penalty_adjustment, last_calculated_at)
                 VALUES (%d, 0, %d, 50, %f, %f, 0, 0, 0, 'neutral', 0, 0, 0, 0, UTC_TIMESTAMP())",
                self::selfPageBase() + $userId,
                $userId,
                $positive,
                $negative
            ));
            self::assertSame('', $wpdb->last_error, 'fixture: self-page insert');
        }

        private function markSeeded(): void
        {
            \update_option(self::SEEDED_OPTION, time(), false);
        }

        private function assertTableExists(bool $expected): void
        {
            global $wpdb;
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->legacy));
            self::assertSame($expected, $found === $this->legacy);
        }
    }
}
