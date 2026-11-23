<?php
/**
 * F-454: the §O4.1 caution/risky list's EXCLUDING reader,
 * ReputationRepository::getCautionAndRiskyUserIdsForExclusion().
 *
 * Every per-viewer feed, the permalink, the anonymous hot feed and member
 * suggestions exclude these members, so a read that did not run must never
 * read as "nobody is shadow-limited". Pinned here:
 *   - a successful read is remembered as the last-good list (autoload no),
 *     rewritten only when it changes or is over an hour old;
 *   - a failed read (loud or silent) serves that list while it is within
 *     its max age (24 h, or BCC_TRUST_CAUTION_LIST_MAX_AGE), and throws with
 *     none or past it, each with its `caution_list` event;
 *   - one row past the ceiling is read, so reaching it is recorded;
 *   - the fail-safe reader the inclusion-set worker keeps answers [] and
 *     logs, and never touches the last-good list.
 *
 * The read's ORDER BY is real SQL: CautionListOrderIntegrationTest.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Log\Logger;
use BCC\Core\Observability\DegradationMetrics;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Tests\Stubs\CautionListWpdb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReputationRepository::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CautionListFailClosedTest extends TestCase
{
    private const OPTION = ReputationRepository::CAUTION_LAST_GOOD_OPTION;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/caution-list-stubs.php';
        $GLOBALS['__caution_options']       = [];
        $GLOBALS['__caution_option_writes'] = [];
        DegradationMetrics::$recorded       = [];
        Logger::$errors                     = [];
        global $wpdb;
        $wpdb = new CautionListWpdb();
        $wpdb->owners = [9, 5, 2];
    }

    // ── a healthy read ──────────────────────────────────────────────────

    public function testAHealthyReadIsRememberedOnceAsTheLastGoodList(): void
    {
        $repo = new ReputationRepository();

        self::assertSame([9, 5, 2], $repo->getCautionAndRiskyUserIdsForExclusion());
        self::assertSame([9, 5, 2], $GLOBALS['__caution_options'][self::OPTION]['ids']);
        self::assertSame([['name' => self::OPTION, 'autoload' => false]], $GLOBALS['__caution_option_writes'], 'autoload no');
        self::assertSame([], DegradationMetrics::$recorded, 'a healthy read records nothing');

        $repo->getCautionAndRiskyUserIdsForExclusion();
        self::assertCount(1, $GLOBALS['__caution_option_writes'], 'unchanged and recent: no second write');
    }

    public function testAChangedListIsRewritten(): void
    {
        $repo = new ReputationRepository();
        $repo->getCautionAndRiskyUserIdsForExclusion();

        $this->db()->owners = [9, 2];
        self::assertSame([9, 2], $repo->getCautionAndRiskyUserIdsForExclusion());
        self::assertSame([9, 2], $GLOBALS['__caution_options'][self::OPTION]['ids']);
        self::assertCount(2, $GLOBALS['__caution_option_writes']);
    }

    /** So the max age measures the last successful read, not the last change. */
    public function testAnUnchangedListIsRefreshedHourlySoItsAgeMeasuresTheLastRead(): void
    {
        $repo = new ReputationRepository();
        $repo->getCautionAndRiskyUserIdsForExclusion();
        $GLOBALS['__caution_options'][self::OPTION]['read_at'] = microtime(true) - 3700;

        $repo->getCautionAndRiskyUserIdsForExclusion();

        self::assertCount(2, $GLOBALS['__caution_option_writes']);
        self::assertGreaterThan(microtime(true) - 60, $GLOBALS['__caution_options'][self::OPTION]['read_at']);
    }

    /** An empty table is an empty list, and a real one: remembered, no event. */
    public function testAGenuinelyEmptyListIsNotAFailure(): void
    {
        $this->db()->owners = [];

        self::assertSame([], (new ReputationRepository())->getCautionAndRiskyUserIdsForExclusion());
        self::assertSame([], DegradationMetrics::$recorded);
        self::assertSame([], $GLOBALS['__caution_options'][self::OPTION]['ids']);
    }

    // ── a failed read ───────────────────────────────────────────────────

    public function testAFailedReadServesAFreshLastGoodListAndLogsItsAge(): void
    {
        $repo = new ReputationRepository();
        $repo->getCautionAndRiskyUserIdsForExclusion();
        $GLOBALS['__caution_options'][self::OPTION]['read_at'] = microtime(true) - 600;
        $this->db()->owners   = [1, 2, 3, 4];
        $this->db()->failNext = 1;

        self::assertSame([9, 5, 2], $repo->getCautionAndRiskyUserIdsForExclusion(), 'the last-good list, never []');
        self::assertSame(['caution_list:list_read_failed', 'caution_list:served_last_good'], DegradationMetrics::$recorded);

        $served = array_values(array_filter(Logger::$errors, static fn (array $e): bool => str_contains($e[0], 'served the last-good list')));
        self::assertCount(1, $served);
        self::assertGreaterThanOrEqual(599, $served[0][1]['last_good_age_seconds']);
        self::assertSame(3, $served[0][1]['last_good_count']);
        self::assertSame([9, 5, 2], $GLOBALS['__caution_options'][self::OPTION]['ids'], 'not overwritten');
    }

    /** wpdb's silent failure (no error, stale rows) is a failure too. */
    public function testASilentFailureIsAFailureNotTheStaleRows(): void
    {
        $repo = new ReputationRepository();
        $repo->getCautionAndRiskyUserIdsForExclusion();
        $this->db()->failSilentNext = 1;

        self::assertSame([9, 5, 2], $repo->getCautionAndRiskyUserIdsForExclusion());
        self::assertContains('caution_list:list_read_failed', DegradationMetrics::$recorded);
    }

    public function testWithNoLastGoodListAFailedReadThrows(): void
    {
        $this->db()->failNext = 1;

        try {
            (new ReputationRepository())->getCautionAndRiskyUserIdsForExclusion();
            self::fail('a failed read with no last-good list must not answer');
        } catch (RepositoryException) {
            // the feeds' 503
        }
        self::assertSame(['caution_list:list_read_failed', 'caution_list:no_last_good'], DegradationMetrics::$recorded);
        self::assertSame([], $GLOBALS['__caution_option_writes'], 'nothing remembered from a failure');
    }

    public function testALastGoodListPastTheMaxAgeCountsAsNone(): void
    {
        $repo = new ReputationRepository();
        $repo->getCautionAndRiskyUserIdsForExclusion();
        $GLOBALS['__caution_options'][self::OPTION]['read_at'] = microtime(true) - (ReputationRepository::CAUTION_LAST_GOOD_MAX_AGE + 60);
        $this->db()->failNext = 1;

        try {
            $repo->getCautionAndRiskyUserIdsForExclusion();
            self::fail('an expired last-good list must not be served');
        } catch (RepositoryException) {
            // the feeds' 503
        }
        self::assertSame(['caution_list:list_read_failed', 'caution_list:last_good_expired'], DegradationMetrics::$recorded);
    }

    /** The boundary: just inside the max age is still served. */
    public function testALastGoodListJustInsideTheMaxAgeIsServed(): void
    {
        $repo = new ReputationRepository();
        $repo->getCautionAndRiskyUserIdsForExclusion();
        $GLOBALS['__caution_options'][self::OPTION]['read_at'] = microtime(true) - (ReputationRepository::CAUTION_LAST_GOOD_MAX_AGE - 60);
        $this->db()->failNext = 1;

        self::assertSame([9, 5, 2], $repo->getCautionAndRiskyUserIdsForExclusion());
    }

    /** A site can shorten the bound in wp-config. */
    public function testTheMaxAgeCanBeSetInConfig(): void
    {
        define('BCC_TRUST_CAUTION_LIST_MAX_AGE', 120);
        $repo = new ReputationRepository();
        $repo->getCautionAndRiskyUserIdsForExclusion();
        $GLOBALS['__caution_options'][self::OPTION]['read_at'] = microtime(true) - 200;
        $this->db()->failNext = 1;

        $this->expectException(RepositoryException::class);
        $repo->getCautionAndRiskyUserIdsForExclusion();
    }

    // ── the ceiling ─────────────────────────────────────────────────────

    public function testOneRowPastTheCeilingIsRead(): void
    {
        (new ReputationRepository())->getCautionAndRiskyUserIdsForExclusion();

        self::assertSame(ReputationRepository::CAUTION_LIST_CEILING + 1, $this->db()->lastLimit);
    }

    public function testReachingTheCeilingIsRecordedAndTheListCutToIt(): void
    {
        $this->db()->owners = range(1, ReputationRepository::CAUTION_LIST_CEILING + 1);

        $ids = (new ReputationRepository())->getCautionAndRiskyUserIdsForExclusion();

        self::assertCount(ReputationRepository::CAUTION_LIST_CEILING, $ids);
        self::assertSame(1, $ids[0], 'the first (most severe) are kept');
        self::assertSame(['caution_list:ceiling_reached'], DegradationMetrics::$recorded);
        self::assertNotSame([], array_filter(Logger::$errors, static fn (array $e): bool => str_contains($e[0], 'ceiling reached')));
    }

    /**
     * Security (F-454 review): the ceiling is separate from a FAILED read.
     * Only a read that succeeded and returned more than the ceiling is cut.
     * A read that did not run serves the last-good copy WHOLE, or throws,
     * and records no ceiling event, even when its stale rows (wpdb's silent
     * failure) are past the ceiling.
     */
    public function testAReadThatDidNotRunIsNeverCutOnlyServedOrRefused(): void
    {
        $repo = new ReputationRepository();
        $this->db()->owners = [9, 5, 2];
        $repo->getCautionAndRiskyUserIdsForExclusion();

        // The read that follows fails silently: its last_result would still
        // hold rows past the ceiling.
        $this->db()->owners = range(1, ReputationRepository::CAUTION_LIST_CEILING + 1);
        $repo->getCautionAndRiskyUserIdsForExclusion();
        DegradationMetrics::$recorded = [];
        $this->db()->failSilentNext = 1;

        self::assertSame(range(1, ReputationRepository::CAUTION_LIST_CEILING), $repo->getCautionAndRiskyUserIdsForExclusion(), 'the copy, whole');
        self::assertSame(['caution_list:list_read_failed', 'caution_list:served_last_good'], DegradationMetrics::$recorded, 'no ceiling event');

        $GLOBALS['__caution_options'] = [];
        DegradationMetrics::$recorded = [];
        $this->db()->failNext = 1;
        try {
            $repo->getCautionAndRiskyUserIdsForExclusion();
            self::fail('no copy: refused, never a partial list');
        } catch (RepositoryException) {
            self::assertNotContains('caution_list:ceiling_reached', DegradationMetrics::$recorded);
        }
    }

    /** P1: the risky members alone fill the ceiling. */
    public function testTheRiskyMembersAloneFillingTheCeilingIsTheP1Event(): void
    {
        $this->db()->owners = range(1, ReputationRepository::CAUTION_LIST_CEILING + 1);
        $this->db()->tiers  = array_fill_keys($this->db()->owners, 'risky');

        (new ReputationRepository())->getCautionAndRiskyUserIdsForExclusion();

        self::assertSame(['caution_list:ceiling_reached', 'caution_list_risky_ceiling:risky_fills_ceiling'], DegradationMetrics::$recorded);
    }

    /** The boundary: exactly the ceiling of risky members, the cut falls on caution. Still P1: no caution member is kept. */
    public function testExactlyTheCeilingOfRiskyMembersIsStillP1(): void
    {
        $this->db()->owners = range(1, ReputationRepository::CAUTION_LIST_CEILING + 1);
        $this->db()->tiers  = array_fill_keys(range(1, ReputationRepository::CAUTION_LIST_CEILING), 'risky');

        (new ReputationRepository())->getCautionAndRiskyUserIdsForExclusion();

        self::assertContains('caution_list_risky_ceiling:risky_fills_ceiling', DegradationMetrics::$recorded);
    }

    /** P2 only: the cut falls among caution-tier members. */
    public function testACutAmongCautionMembersIsP2Only(): void
    {
        $this->db()->owners = range(1, ReputationRepository::CAUTION_LIST_CEILING + 1);
        $this->db()->tiers  = array_fill_keys(range(1, 100), 'risky');

        (new ReputationRepository())->getCautionAndRiskyUserIdsForExclusion();

        self::assertSame(['caution_list:ceiling_reached'], DegradationMetrics::$recorded);
    }

    /** The P1 subsystem is added to bcc-core's P1 set once, keeping the rest. */
    public function testTheP1FilterAddsTheRiskySubsystemOnce(): void
    {
        $once = ReputationRepository::withRiskyCeilingAsP1(['account_security_mail', 'auth_mail']);

        self::assertSame(['account_security_mail', 'auth_mail', 'caution_list_risky_ceiling'], $once);
        self::assertSame($once, ReputationRepository::withRiskyCeilingAsP1($once), 'idempotent');
        self::assertSame(['caution_list_risky_ceiling'], ReputationRepository::withRiskyCeilingAsP1(null));
    }

    /**
     * F-546: the ceiling counts ROWS, before the dedupe. Duplicate owner rows
     * that bring the read past the ceiling record it, though the unique ids
     * do not exceed it, because more rows were left unread.
     */
    public function testDuplicateOwnerRowsCannotHideTheCeiling(): void
    {
        $this->db()->owners = [...range(1, ReputationRepository::CAUTION_LIST_CEILING), 1];

        $ids = (new ReputationRepository())->getCautionAndRiskyUserIdsForExclusion();

        self::assertCount(ReputationRepository::CAUTION_LIST_CEILING, $ids);
        self::assertSame(['caution_list:ceiling_reached'], DegradationMetrics::$recorded);
    }

    /** Exactly at the ceiling is not past it. */
    public function testExactlyTheCeilingIsNotRecorded(): void
    {
        $this->db()->owners = range(1, ReputationRepository::CAUTION_LIST_CEILING);

        self::assertCount(ReputationRepository::CAUTION_LIST_CEILING, (new ReputationRepository())->getCautionAndRiskyUserIdsForExclusion());
        self::assertSame([], DegradationMetrics::$recorded);
    }

    // ── the inclusion-set worker's reader ───────────────────────────────

    /** FAIL-SAFE by design (an inclusion set), but logged, and it never touches the last-good list. */
    public function testTheWorkersReaderAnswersEmptyAndLogs(): void
    {
        $this->db()->failNext = 1;

        self::assertSame([], (new ReputationRepository())->getCautionAndRiskyUserIds(50));
        self::assertNotSame([], Logger::$errors, 'logged, not silent');
        self::assertSame([], DegradationMetrics::$recorded);
        self::assertSame([], $GLOBALS['__caution_option_writes']);
    }

    public function testTheWorkersReaderKeepsItsOwnLimit(): void
    {
        self::assertSame([9, 5], (new ReputationRepository())->getCautionAndRiskyUserIds(2));
        self::assertSame(2, $this->db()->lastLimit);
    }

    private function db(): CautionListWpdb
    {
        /** @var CautionListWpdb $wpdb */
        global $wpdb;

        return $wpdb;
    }
}
