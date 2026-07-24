<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The refactored canonical-handle backfill's STATUS contract. It no longer
 * self-completes (the runner owns that); it processes one bounded batch and
 * returns COMPLETE only when the eligible set fully drains with no
 * unassignable users, else INCOMPLETE (retryable).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CanonicalHandlesStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/canonical-backfill-stubs.php';
        require_once __DIR__ . '/../../includes/database/backfill-canonical-handles.php';
        $GLOBALS['__bcc_ch_pages']    = [];
        $GLOBALS['__bcc_ch_validate'] = null;   // null → all handles valid
        $GLOBALS['__bcc_ch_available'] = null;  // null → all handles available
        $GLOBALS['__bcc_ch_assigned'] = [];
    }

    // Drained with every user assigned → COMPLETE.
    public function testDrainedWithNoSkipsReportsComplete(): void
    {
        $GLOBALS['__bcc_ch_pages'] = [
            0   => [11, 22],
            200 => [], // drained
        ];

        $status = bcc_trust_backfill_canonical_handles();

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, $status);
        self::assertArrayHasKey(11, $GLOBALS['__bcc_ch_assigned']);
        self::assertArrayHasKey(22, $GLOBALS['__bcc_ch_assigned']);
    }

    // A user that cannot be assigned any handle (validation rejects every
    // candidate, incl. the member-{id} fallback) → INCOMPLETE, retryable.
    public function testUnassignableUserLeavesIncomplete(): void
    {
        $GLOBALS['__bcc_ch_pages']    = [0 => [11], 200 => []];
        $GLOBALS['__bcc_ch_validate'] = static fn(string $h): string => 'reserved'; // nothing valid

        $status = bcc_trust_backfill_canonical_handles();

        self::assertSame(BCC_TRUST_MIGRATION_INCOMPLETE, $status);
        self::assertSame([], $GLOBALS['__bcc_ch_assigned'], 'no handle assigned when all rejected');
    }

    // Batch cap reached with full batches throughout → INCOMPLETE (not a
    // false complete).
    public function testBatchCapReachedReturnsIncomplete(): void
    {
        $full = range(1, 200);
        $pages = [];
        for ($offset = 0; $offset <= 50 * 200; $offset += 200) {
            // fresh ids per page so each batch is "full" and progresses
            $pages[$offset] = array_map(static fn(int $n): int => $offset + $n, $full);
        }
        $GLOBALS['__bcc_ch_pages'] = $pages;

        $status = bcc_trust_backfill_canonical_handles();

        self::assertSame(BCC_TRUST_MIGRATION_INCOMPLETE, $status, 'cap hit must not report complete');
    }
}
