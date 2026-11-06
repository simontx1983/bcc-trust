<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * BEHAVIOURAL regression test for the page-follow drain
 * (`PeepSoIntegration::migratePageFollowersToPeepSo`).
 *
 * The bug: the drain read ONE page of `bcc_page_follows` rows and then called
 * `deleteByPageId()`, which removes EVERY row for the page. So a row whose
 * PeepSo follow failed was counted as `errored` and then deleted anyway — the
 * follow was lost with no retry — and any row beyond the first read page was
 * destroyed without ever being migrated.
 *
 * These cases assert the fix by OUTCOME against an in-memory follow table,
 * not by reading the source: a handled row (migrated, or deliberately skipped
 * as a self-follow) is gone, and a FAILED row SURVIVES for a later attempt.
 *
 * ⚠ Stubs are required inside setUp(), not at file scope, because file-scope
 * declarations pollute the PHPUnit discovery parent and would hijack sibling
 * suites that use the real `Plugin` / `PageFollowRepository`.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
final class PageFollowDrainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wp/');
        }

        $GLOBALS['__bcc_drain'] = [
            'rows'                   => [],
            'fail_follow'            => [],
            'throw_follow'           => [],
            'fail_read'              => 0,
            'fail_delete'            => 0,
            'delete_matches_nothing' => false,
            'reads'                  => 0,
            'follows'                => 0,
            'delete_calls'           => [],
            'deleted'                => [],
            'by_page_id'             => 0,
            'meta'                   => [],
            'info'                   => [],
            'errors'                 => [],
        ];

        require_once __DIR__ . '/../Stubs/page-follow-drain-stubs.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_drain']);
        parent::tearDown();
    }

    /**
     * Seed the in-memory follow table. Ids start at 101 and ascend in the
     * order given, so a case can name a row by position.
     *
     * @param list<int> $viewerIds
     */
    private function seed(int $pageId, array $viewerIds): void
    {
        $id = 100;
        foreach ($viewerIds as $viewerId) {
            $id++;
            $GLOBALS['__bcc_drain']['rows'][$id] = (object) [
                'id'            => $id,
                'page_id'       => $pageId,
                'user_id'       => $viewerId,
                'tier_at_watch' => 'bronze',
            ];
        }
    }

    /** Run the real private drain against the stubbed repositories. */
    private function drain(int $pageId, int $newAuthorId): void
    {
        $class = \BCC\Trust\Core\Integration\PeepSoIntegration::class;

        $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        $method = new ReflectionMethod($class, 'migratePageFollowersToPeepSo');
        $method->setAccessible(true);
        $method->invoke($instance, $pageId, $newAuthorId);
    }

    /** @return list<int> surviving row ids, ascending */
    private function survivors(): array
    {
        $ids = array_map('intval', array_keys($GLOBALS['__bcc_drain']['rows']));
        sort($ids);

        return $ids;
    }

    public function testEverySuccessfullyTransferredFollowerIsDeleted(): void
    {
        $this->seed(7, [11, 12, 13]);

        $this->drain(7, 500);

        $this->assertSame([], $this->survivors(), 'migrated rows must be removed');
        $this->assertSame([101, 102, 103], $GLOBALS['__bcc_drain']['deleted']);
        $this->assertSame(
            [9011, 9012, 9013],
            $GLOBALS['__bcc_drain']['meta'],
            'each migrated row gets its watch-meta sidecar'
        );
    }

    public function testAnIntentionallySkippedSelfFollowIsAlsoDeleted(): void
    {
        // Row 102 is the claimer following their own page: a self-follow is
        // DELIBERATELY skipped (never attempted), which counts as handled.
        $this->seed(7, [11, 500, 13]);

        $this->drain(7, 500);

        $this->assertSame([], $this->survivors(), 'a skipped self-follow is handled, so it is removed');
        $this->assertContains(102, $GLOBALS['__bcc_drain']['deleted']);
        $this->assertSame(
            [9011, 9013],
            $GLOBALS['__bcc_drain']['meta'],
            'the self-follow must not produce a PeepSo follow or a meta row'
        );
    }

    public function testAFailedTransferIsRetainedWhileItsNeighboursAreDeleted(): void
    {
        $this->seed(7, [11, 12, 13]);
        // Viewer 12 => PeepSoFollowWriter::follow returns 0 (refused).
        $GLOBALS['__bcc_drain']['fail_follow'] = [12];

        $this->drain(7, 500);

        $this->assertSame(
            [102],
            $this->survivors(),
            'the failed row MUST survive for a later retry — this is the regression'
        );
        $this->assertNotContains(102, $GLOBALS['__bcc_drain']['deleted']);
        $this->assertContains(101, $GLOBALS['__bcc_drain']['deleted']);
        $this->assertContains(103, $GLOBALS['__bcc_drain']['deleted']);
    }

    public function testAThrowingTransferIsAlsoRetained(): void
    {
        $this->seed(7, [11, 12]);
        $GLOBALS['__bcc_drain']['throw_follow'] = [11];

        $this->drain(7, 500);

        $this->assertSame([101], $this->survivors(), 'an exception must not delete the row');
        $this->assertSame([102], $GLOBALS['__bcc_drain']['deleted']);
    }

    public function testAnErroredPassIsRetriedOnceAndThenStopsWithoutDeletingIt(): void
    {
        $this->seed(7, [11, 12]);
        $GLOBALS['__bcc_drain']['fail_follow'] = [12];

        $this->drain(7, 500);

        // Pass 1 handles row 101 and errors on 102, so the loop runs again.
        // Pass 2 handles nothing (only the failing row is left) and breaks —
        // it must NOT delete the row it could not handle.
        $this->assertSame(2, $GLOBALS['__bcc_drain']['reads'], 'an errored pass is retried exactly once');
        $this->assertSame([102], $this->survivors());
    }

    public function testACleanPassDoesNotReadASecondTime(): void
    {
        $this->seed(7, [11, 12]);

        $this->drain(7, 500);

        $this->assertSame(
            1,
            $GLOBALS['__bcc_drain']['reads'],
            'a short page with no errors must break after one pass'
        );
    }

    public function testTheDrainNeverCallsDeleteByPageId(): void
    {
        $this->seed(7, [11, 12, 13]);
        $GLOBALS['__bcc_drain']['fail_follow'] = [12];

        $this->drain(7, 500);

        $this->assertSame(
            0,
            $GLOBALS['__bcc_drain']['by_page_id'],
            'deleteByPageId() removes rows the drain never handled — it must not be used'
        );
    }

    public function testEveryFailedRowSurvivesWhenNothingCanBeMigrated(): void
    {
        $this->seed(7, [11, 12]);
        $GLOBALS['__bcc_drain']['fail_follow'] = [11, 12];

        $this->drain(7, 500);

        $this->assertSame([101, 102], $this->survivors(), 'a total failure deletes nothing');
        $this->assertSame([], $GLOBALS['__bcc_drain']['deleted']);
        $this->assertSame(
            1,
            $GLOBALS['__bcc_drain']['reads'],
            'the first pass handles nothing, so the loop breaks immediately'
        );
    }

    public function testAnEmptyFollowTableIsANoOp(): void
    {
        $this->drain(7, 500);

        $this->assertSame([], $GLOBALS['__bcc_drain']['deleted']);
        $this->assertSame([], $GLOBALS['__bcc_drain']['info'], 'nothing happened, so nothing is logged');
    }

    public function testRowsForOtherPagesAreUntouched(): void
    {
        $this->seed(7, [11]);
        $GLOBALS['__bcc_drain']['rows'][900] = (object) [
            'id'            => 900,
            'page_id'       => 8,
            'user_id'       => 11,
            'tier_at_watch' => 'bronze',
        ];

        $this->drain(7, 500);

        $this->assertSame([900], $this->survivors(), "another page's follows must survive");
    }

    // ------------------------------------------------- a failed DELETE

    /**
     * ⭐ THE AMPLIFICATION REGRESSION, on a FULL batch.
     *
     * A failed DELETE leaves every handled row in the table, so
     * `count($rows) < DRAIN_PAGE_SIZE` stays false and the loop re-reads the
     * SAME full page. Before the fix `deleteByIds()` returned 0 for a failed
     * query, which the caller could not tell from "matched nothing", so the
     * drain simply looped: 64 passes x 5000 rows = 320,000 PeepSo follow
     * calls inside one claim request, deleting nothing.
     *
     * The assertions that bite are the COUNTS: one read, one delete attempt,
     * exactly DRAIN_PAGE_SIZE follow calls.
     */
    public function testAFailedDeleteOnAFullBatchStopsAfterOnePassInsteadOfReprocessingIt(): void
    {
        $size = \BCC\Trust\Core\Repositories\PageFollowRepository::DRAIN_PAGE_SIZE;
        $this->seed(7, range(11, 10 + $size));
        $GLOBALS['__bcc_drain']['fail_delete'] = 1;

        // ⚠ The new author must sit OUTSIDE the seeded viewer range, or the
        // self-follow guard skips one row and the follow count is $size - 1 —
        // which is how this case first failed, by one.
        $this->drain(7, 999_999);

        $this->assertCount($size, $GLOBALS['__bcc_drain']['rows'], 'a failed delete removes nothing');
        $this->assertSame(
            1,
            $GLOBALS['__bcc_drain']['reads'],
            'the drain must NOT re-read a full page whose delete failed'
        );
        $this->assertSame(
            [$size],
            $GLOBALS['__bcc_drain']['delete_calls'],
            'exactly one delete was attempted'
        );
        $this->assertSame(
            $size,
            $GLOBALS['__bcc_drain']['follows'],
            'every row is migrated once, not once per pass'
        );
        $this->assertSame([], $GLOBALS['__bcc_drain']['deleted']);
    }

    public function testAFailedDeleteRetainsTheRowsAndLogsAnError(): void
    {
        $this->seed(7, [11, 12]);
        $GLOBALS['__bcc_drain']['fail_delete'] = 1;

        $this->drain(7, 500);

        $this->assertSame([101, 102], $this->survivors(), 'nothing may be lost when the delete fails');

        $messages = array_column($GLOBALS['__bcc_drain']['errors'], 'message');
        $this->assertNotSame([], $messages, 'a failed delete must not be silent');
        $this->assertStringContainsString('delete of handled rows FAILED', implode(' | ', $messages));
    }

    /**
     * ⭐ The distinction the old log could not draw: `deleteByIds()` returned
     * 0 both when the statement errored and when it matched nothing, so the
     * summary said `deleted: 0` either way — asserting success over a
     * complete failure.
     */
    public function testTheSummaryTellsADeleteFailureApartFromNothingMatched(): void
    {
        $this->seed(7, [11, 12]);
        $GLOBALS['__bcc_drain']['fail_delete'] = 1;

        $this->drain(7, 500);

        $ctx = $GLOBALS['__bcc_drain']['info'][0];
        $this->assertSame(0, $ctx['deleted']);
        $this->assertArrayHasKey('delete_failed', $ctx, 'the summary must name the failure');
        $this->assertSame(2, $ctx['delete_failed']);
        $this->assertSame(0, $ctx['errored'], 'both rows migrated fine — only the delete failed');
        $this->assertSame(2, $ctx['retained'], 'retained counts migrated-but-undeletable rows too');
    }

    public function testNothingMatchedIsReportedAsZeroDeletedWithNoFailure(): void
    {
        $this->seed(7, [11, 12]);
        // The statement runs and matches no rows (someone else removed them).
        $GLOBALS['__bcc_drain']['delete_matches_nothing'] = true;

        $this->drain(7, 500);

        $ctx = $GLOBALS['__bcc_drain']['info'][0];
        $this->assertSame(0, $ctx['deleted']);
        $this->assertSame(0, $ctx['delete_failed'], 'a clean zero is NOT a failure');
        $this->assertSame(0, $ctx['retained']);
        $this->assertSame([], $GLOBALS['__bcc_drain']['errors'], 'nothing matched is not an error');
    }

    // --------------------------------------------------- a failed READ

    /**
     * A failed read returned `[]`, which the loop took for "no followers
     * left" — so a broken query looked exactly like a completed drain.
     */
    public function testAFailedReadStopsTheDrainAndRetainsEveryRow(): void
    {
        $this->seed(7, [11, 12]);
        $GLOBALS['__bcc_drain']['fail_read'] = 1;

        $this->drain(7, 500);

        $this->assertSame([101, 102], $this->survivors(), 'a failed read must not be treated as success');
        $this->assertSame(1, $GLOBALS['__bcc_drain']['reads']);
        $this->assertSame(0, $GLOBALS['__bcc_drain']['follows'], 'nothing was migrated');
        $this->assertSame([], $GLOBALS['__bcc_drain']['delete_calls'], 'nothing was deleted');

        $messages = array_column($GLOBALS['__bcc_drain']['errors'], 'message');
        $this->assertStringContainsString('follow read FAILED', implode(' | ', $messages));
        $this->assertSame(
            [],
            $GLOBALS['__bcc_drain']['info'],
            'no "migration complete" line may contradict the error'
        );
    }

    public function testAReadThatFailsAfterProgressIsNamedInTheSummary(): void
    {
        // Row 102 errors, so pass 1 handles 101 and the loop runs again;
        // the second read is the one that fails.
        $this->seed(7, [11, 12]);
        $GLOBALS['__bcc_drain']['fail_follow'] = [12];
        $GLOBALS['__bcc_drain']['fail_read']   = 2;

        $this->drain(7, 500);

        $ctx = $GLOBALS['__bcc_drain']['info'][0];
        $this->assertTrue($ctx['read_failed'], 'the summary must say the read failed');
        $this->assertSame(1, $ctx['migrated']);
        $this->assertSame(1, $ctx['deleted']);
        $this->assertSame([102], $this->survivors());
    }

    public function testTheSummaryLogReportsRetainedRows(): void
    {
        $this->seed(7, [11, 12, 500]);
        $GLOBALS['__bcc_drain']['fail_follow'] = [12];

        $this->drain(7, 500);

        $this->assertCount(1, $GLOBALS['__bcc_drain']['info']);
        $ctx = $GLOBALS['__bcc_drain']['info'][0];

        $this->assertSame(1, $ctx['migrated']);
        $this->assertSame(1, $ctx['skipped']);
        $this->assertSame(1, $ctx['errored']);
        $this->assertSame(2, $ctx['deleted']);
        $this->assertArrayHasKey('retained', $ctx, 'the log must name what was left behind');
        $this->assertSame(1, $ctx['retained']);
        $this->assertSame(2, $ctx['passes']);
        $this->assertSame(0, $ctx['delete_failed'], 'the deletes all succeeded here');
        $this->assertFalse($ctx['read_failed']);
    }
}
