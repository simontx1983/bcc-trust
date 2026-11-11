<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-149 — the dispute-panel cleanup must void ONLY panel-era open disputes.
 *
 * Before the fix the migration ran
 *   UPDATE bcc_disputes SET status = 'dismissed' WHERE status = 'reviewing'
 * and counted itself complete only when no 'reviewing' row remained. A LIVE
 * dispute is also 'reviewing' — what distinguishes it is the poll the engine
 * opened for it (poll_type/subject_type 'dispute', subject_id = dispute id).
 * On any environment where the migration had not yet been stamped done, every
 * real open dispute would have been silently dismissed.
 *
 * The REAL migration runs against a REAL server. Fixture tables carry only the
 * columns the migration reads and writes; the panel table and panel columns are
 * deliberately absent so the case isolates the voiding step.
 */
#[Group('integration')]
#[Group('mariadb')]
final class DisputePanelCleanupScopeIntegrationTest extends TestCase
{
    private string $disputes = '';
    private string $polls    = '';
    private string $panel    = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('DB_NAME')) {
            // The migration's INFORMATION_SCHEMA probe is scoped by DB_NAME,
            // which the integration bootstrap does not define.
            define('DB_NAME', getenv('BCC_TEST_DB_NAME') ?: 'bcc_test');
        }
        require_once dirname(__DIR__, 2) . '/includes/database/migration-runner.php';
        require_once dirname(__DIR__, 2) . '/includes/database/cleanup-dispute-panel.php';

        global $wpdb;
        $wpdb->clearFaultInjection();
        $this->disputes = $wpdb->prefix . 'bcc_disputes';
        $this->polls    = $wpdb->prefix . 'bcc_trust_polls';
        $this->panel    = $wpdb->prefix . 'bcc_dispute_panel';

        $this->dropFixtures();
        $wpdb->query(
            "CREATE TABLE `{$this->disputes}` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                status VARCHAR(20) NOT NULL DEFAULT 'reviewing',
                resolved_at DATETIME NULL
             ) ENGINE=InnoDB"
        );
        $wpdb->query(
            "CREATE TABLE `{$this->polls}` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                poll_type VARCHAR(20) NOT NULL,
                subject_type VARCHAR(20) NOT NULL,
                subject_id BIGINT UNSIGNED NOT NULL,
                KEY idx_subject (subject_type, subject_id)
             ) ENGINE=InnoDB"
        );
        self::assertSame('', $wpdb->last_error, 'fixture tables must be creatable');
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->dropFixtures();
        parent::tearDown();
    }

    public function testALiveDisputeWithAPollIsNeverDismissed(): void
    {
        $live = $this->insertDispute('reviewing');
        $this->insertPoll('dispute', 'dispute', $live);

        $result = \bcc_trust_cleanup_dispute_panel();

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, $result);
        self::assertSame('reviewing', $this->statusOf($live), 'a dispute the poll engine owns must survive the cleanup');
    }

    public function testAPanelEraDisputeWithNoPollIsDismissed(): void
    {
        $panelEra = $this->insertDispute('reviewing');

        $result = \bcc_trust_cleanup_dispute_panel();

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, $result);
        self::assertSame('dismissed', $this->statusOf($panelEra));
    }

    /**
     * Both kinds at once — the scope is per row, not all-or-nothing. A poll
     * for a DIFFERENT subject or type must not shield a panel-era dispute.
     */
    public function testOnlyThePollLessRowsAreVoidedInAMixedTable(): void
    {
        $live      = $this->insertDispute('reviewing');
        $panelEra  = $this->insertDispute('reviewing');
        $wrongType = $this->insertDispute('reviewing');
        $closed    = $this->insertDispute('upheld');
        $this->insertPoll('dispute', 'dispute', $live);
        $this->insertPoll('finding', 'dispute', $wrongType); // not a dispute poll

        \bcc_trust_cleanup_dispute_panel();

        self::assertSame('reviewing', $this->statusOf($live));
        self::assertSame('dismissed', $this->statusOf($panelEra));
        self::assertSame('dismissed', $this->statusOf($wrongType), 'only a dispute-type poll proves a live dispute');
        self::assertSame('upheld', $this->statusOf($closed), 'non-reviewing rows are never touched');
    }

    /** A failed inventory read fails closed — nothing is voided, retry next request. */
    public function testAFailedInventoryReadVoidsNothing(): void
    {
        global $wpdb;
        $panelEra = $this->insertDispute('reviewing');

        $wpdb->failQueriesMatching = '/SELECT COUNT\(\*\) FROM \S*bcc_disputes/i';
        $result = \bcc_trust_cleanup_dispute_panel();
        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
        $wpdb->clearFaultInjection();

        self::assertSame(BCC_TRUST_MIGRATION_INCOMPLETE, $result);
        self::assertSame('reviewing', $this->statusOf($panelEra));
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function insertDispute(string $status): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("INSERT INTO `{$this->disputes}` (status) VALUES (%s)", $status));
        self::assertSame('', $wpdb->last_error, 'fixture: dispute insert');
        return (int) $wpdb->insert_id;
    }

    private function insertPoll(string $pollType, string $subjectType, int $subjectId): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$this->polls}` (poll_type, subject_type, subject_id) VALUES (%s, %s, %d)",
            $pollType,
            $subjectType,
            $subjectId
        ));
        self::assertSame('', $wpdb->last_error, 'fixture: poll insert');
    }

    private function statusOf(int $id): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM `{$this->disputes}` WHERE id = %d", $id));
    }

    private function dropFixtures(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS `{$this->disputes}`");
        $wpdb->query("DROP TABLE IF EXISTS `{$this->polls}`");
        $wpdb->query("DROP TABLE IF EXISTS `{$this->panel}`");
    }
}
