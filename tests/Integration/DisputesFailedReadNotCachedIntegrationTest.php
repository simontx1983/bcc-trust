<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Disputes\Repositories\DisputeAdminRepository;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use BCC\Trust\Disputes\Repositories\UserReportRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-101 (Disputes): a FAILED read is never cached as "absent".
 *
 * Real SQL errors (the table is renamed away), then the table is restored
 * and read again: the second read must see the rows. ⚠ get_results()
 * returns [] — not null — on a SQL error, so getByReporterPaginated()'s
 * is_array() guard never caught one.
 */
#[Group('integration')]
#[CoversNothing]
final class DisputesFailedReadNotCachedIntegrationTest extends TestCase
{
    private const REPORTER = 990201;
    private const VOTER    = 990202;
    private const PAGE     = 990203;

    /** @var list<string> */
    private array $broken = [];

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        // The installer reads DB_NAME (wp-config defines it; the harness does not).
        if (!defined('DB_NAME')) {
            define('DB_NAME', (string) $wpdb->get_var('SELECT DATABASE()'));
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', DisputeRepository::disputes_table())) !== DisputeRepository::disputes_table()) {
            DisputeRepository::install();
        }
        $GLOBALS['__bcc_test_object_cache'] = [];
        $wpdb->query('DELETE FROM `' . DisputeRepository::disputes_table() . '`');
        $wpdb->query('DELETE FROM `' . UserReportRepository::user_reports_table() . '`');
    }

    protected function tearDown(): void
    {
        foreach ($this->broken as $t) {
            $this->restore($t);
        }
        $this->broken = [];
        $GLOBALS['__bcc_test_object_cache'] = [];
    }

    private function break(string $table): void
    {
        $GLOBALS['wpdb']->query("RENAME TABLE `{$table}` TO `{$table}_away`");
        $this->broken[] = $table;
    }

    private function restore(string $table): void
    {
        $wpdb   = $GLOBALS['wpdb'];
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s',
            $table . '_away'
        ));
        if ($exists === 1) {
            $wpdb->query("RENAME TABLE `{$table}_away` TO `{$table}`");
        }
        $wpdb->last_error = '';
    }

    private function seedDispute(): int
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert(DisputeRepository::disputes_table(), [
            'vote_id' => 1, 'page_id' => self::PAGE, 'reporter_id' => self::REPORTER, 'voter_id' => self::VOTER,
            'reason' => 'F-101 fixture', 'status' => 'reviewing', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    public function testAFailedDisputeByIdReadIsNotCached(): void
    {
        $id = $this->seedDispute();

        $this->break(DisputeRepository::disputes_table());
        self::assertNull(DisputeRepository::getDisputeById($id));
        $this->restore(DisputeRepository::disputes_table());

        self::assertNotNull(DisputeRepository::getDisputeById($id), 'force-resolve must not 404 for TTL_WARM');
    }

    public function testFailedReporterReadsAreNotCached(): void
    {
        $this->seedDispute();

        $this->break(DisputeRepository::disputes_table());
        self::assertSame(0, DisputeRepository::countByReporter(self::REPORTER));
        self::assertSame([], DisputeRepository::getByReporterPaginated(self::REPORTER, 10, 0));
        $this->restore(DisputeRepository::disputes_table());

        self::assertSame(1, DisputeRepository::countByReporter(self::REPORTER), 'the zero count was not cached');
        self::assertCount(1, DisputeRepository::getByReporterPaginated(self::REPORTER, 10, 0), 'the empty page was not cached');
    }

    public function testAFailedDisputeStatusCountReadIsNotCached(): void
    {
        $this->seedDispute();

        $this->break(DisputeRepository::disputes_table());
        self::assertSame([], DisputeAdminRepository::getDisputeStatusCounts());
        $this->restore(DisputeRepository::disputes_table());

        self::assertSame(['reviewing' => 1], DisputeAdminRepository::getDisputeStatusCounts());
    }

    public function testAFailedReportStatusCountReadIsNotCached(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert(UserReportRepository::user_reports_table(), [
            'reported_id' => self::VOTER, 'reporter_id' => self::REPORTER, 'status' => 'pending',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);

        $this->break(UserReportRepository::user_reports_table());
        self::assertSame([], UserReportRepository::getReportStatusCounts());
        $this->restore(UserReportRepository::user_reports_table());

        self::assertSame(['pending' => 1], UserReportRepository::getReportStatusCounts());
    }
}
