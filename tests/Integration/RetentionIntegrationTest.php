<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\ContentReportRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end retention test against a real MySQL: seed real rows, run the real
 * batched DELETE, and assert what's left. The unit test (RetentionCleanupTest)
 * locks the SQL shape; this proves the actual database behaviour — most
 * importantly that a PENDING report is NEVER deleted.
 */
#[Group('integration')]
#[CoversClass(ContentReportRepository::class)]
final class RetentionIntegrationTest extends TestCase
{
    private function table(): string
    {
        return $GLOBALS['wpdb']->prefix . 'bcc_content_reports';
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `' . $this->table() . '`');
    }

    private function seed(int $reporter, int $status, ?string $resolvedAt): void
    {
        $GLOBALS['wpdb']->insert($this->table(), [
            'target_kind'      => 'feed_item',
            'target_id'        => 1000 + $reporter,
            'reporter_user_id' => $reporter,
            'reason_code'      => 'spam',
            'comment'          => null,
            'status'           => $status,
            'resolved_by'      => $status === 0 ? null : 9,
            'resolved_at'      => $resolvedAt,
            'created_at'       => date('Y-m-d H:i:s', strtotime('-200 days')),
        ]);
    }

    private function rowCount(string $where = '1'): int
    {
        return (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM `' . $this->table() . "` WHERE {$where}");
    }

    public function testDeletesOldClosedButNeverPending(): void
    {
        $old    = date('Y-m-d H:i:s', strtotime('-100 days')); // past the 90d horizon
        $recent = date('Y-m-d H:i:s', strtotime('-10 days'));  // inside the horizon

        $this->seed(1, 0, null);    // PENDING        → must survive
        $this->seed(2, 1, $old);    // resolved old   → delete
        $this->seed(3, 2, $old);    // dismissed old  → delete
        $this->seed(4, 1, $recent); // resolved recent→ survive

        self::assertSame(4, $this->rowCount(), 'four rows seeded');

        $deleted = (new ContentReportRepository())->cleanupResolved();

        self::assertSame(2, $deleted, 'exactly the two old closed rows are deleted');
        self::assertSame(1, $this->rowCount('status = 0'), 'PENDING report is never deleted');
        self::assertSame(1, $this->rowCount('reporter_user_id = 4'), 'recent resolved report survives');
        self::assertSame(2, $this->rowCount(), 'only pending + recent-resolved remain');
    }
}
