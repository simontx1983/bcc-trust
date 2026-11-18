<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Disputes\Repositories\UserReportRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-310 (M1): the admin Penalize path must not report success when no
 * penalty landed. Real SQL for the two primitives it now relies on:
 *
 *   - ScoreRepository::applyPenalty() returns the rows it changed, so a
 *     page with no score row (0) reads as "nothing landed", not success;
 *   - UserReportRepository::reopenReport() gives back the `penalized` claim
 *     taken before the penalty, and only that claim.
 *
 * Owns rows keyed by its own far-out-of-range ids, deleted in setUp/tearDown.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PenalizeOutcomeIntegrationTest extends TestCase
{
    private const PAGE     = 2310000001;
    private const NO_PAGE  = 2310000002;
    private const OWNER    = 983101;
    private const REPORTED = 983102;
    private const REPORTER = 983103;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/score-override-stubs.php';
        // The report tables come from the disputes installer, as in
        // DisputesFailedReadNotCachedIntegrationTest (it reads DB_NAME).
        $wpdb = $GLOBALS['wpdb'];
        if (!defined('DB_NAME')) {
            define('DB_NAME', (string) $wpdb->get_var('SELECT DATABASE()'));
        }
        $table = UserReportRepository::user_reports_table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            \BCC\Trust\Disputes\Repositories\DisputeRepository::install();
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testAPenaltyOnAScoredPageReportsTheRowItChanged(): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_page_scores', [
            'page_id' => self::PAGE, 'page_owner_id' => self::OWNER,
        ]), $wpdb->last_error);

        self::assertSame(1, (new ScoreRepository())->applyPenalty(self::PAGE, -5.0));
    }

    public function testAPenaltyOnAPageWithNoScoreRowReportsZero(): void
    {
        self::assertSame(
            0,
            (new ScoreRepository())->applyPenalty(self::NO_PAGE, -5.0),
            'no score row means no penalty landed; the admin path must not call that success'
        );
    }

    public function testReopenGivesBackThePenalizedClaim(): void
    {
        $id = $this->openReport();
        self::assertTrue(UserReportRepository::updateReportStatus($id, 'penalized'), 'precondition: the claim');

        self::assertTrue(UserReportRepository::reopenReport($id, 'penalized'));
        self::assertSame('open', $this->reportStatus($id));
    }

    public function testReopenCannotUndoADifferentDecision(): void
    {
        $id = $this->openReport();
        self::assertTrue(UserReportRepository::updateReportStatus($id, 'dismissed'));

        self::assertFalse(UserReportRepository::reopenReport($id, 'penalized'), 'only the claim this request took');
        self::assertSame('dismissed', $this->reportStatus($id));
    }

    private function openReport(): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(UserReportRepository::user_reports_table(), [
            'reported_id' => self::REPORTED,
            'reporter_id' => self::REPORTER,
            'status'      => 'open',
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    private function reportStatus(int $id): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM `' . UserReportRepository::user_reports_table() . '` WHERE id = %d',
            $id
        ));
    }

    private function cleanup(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->prefix . 'bcc_trust_page_scores WHERE page_id IN (%d, %d)',
            self::PAGE,
            self::NO_PAGE
        ));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM `' . UserReportRepository::user_reports_table() . '` WHERE reported_id = %d',
            self::REPORTED
        ));
    }
}
