<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\NotificationRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression net for retired bell types (v1.50 endorse retirement; same
 * class as the pull→watch rename's `bcc_card_pulled` leftovers).
 *
 * All three readers are scoped to `not_type IN (NotificationType::ALL)`
 * at the SQL boundary. Post-query filtering alone (shapeRow → null)
 * was not enough on the LIST path: rows of retired types consumed
 * LIMIT slots before the filter ran, producing short pages — and a
 * page whose raw rows were ALL retired returned `items: []` with
 * `has_more: true` but a null cursor, a pagination dead-end that hid
 * every older valid notification. On the COUNT path they inflated the
 * badge for rows the list never renders. Seeds real rows, interleaved
 * and read/unread, and proves pages stay full, cursors advance, and
 * counts match what renders.
 */
#[Group('integration')]
#[CoversClass(NotificationRepository::class)]
final class NotificationLegacyTypeIntegrationTest extends TestCase
{
    private const DDL =
        "CREATE TABLE IF NOT EXISTS `wp_peepso_notifications` (
            not_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            not_user_id BIGINT UNSIGNED NOT NULL,
            not_from_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            not_module_id INT NOT NULL,
            not_external_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            not_act_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            not_type VARCHAR(64) NOT NULL,
            not_message TEXT NOT NULL,
            not_timestamp DATETIME NOT NULL,
            not_read TINYINT NOT NULL DEFAULT 0,
            PRIMARY KEY (not_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";

    protected function setUp(): void
    {
        if (!defined('BCC_NOTIFICATION_MODULE_ID')) {
            // Mirrors includes/config/limits.php — every repo query is
            // scoped to this module id.
            define('BCC_NOTIFICATION_MODULE_ID', 9000);
        }
        $GLOBALS['wpdb']->query(self::DDL);
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `wp_peepso_notifications`');
    }

    private function seed(int $userId, string $type, int $read = 0): void
    {
        $GLOBALS['wpdb']->query(
            $GLOBALS['wpdb']->prepare(
                'INSERT INTO `wp_peepso_notifications`
                    (not_user_id, not_from_user_id, not_module_id, not_external_id, not_act_id, not_type, not_message, not_timestamp, not_read)
                 VALUES (%d, %d, %d, %d, %d, %s, %s, %s, %d)',
                $userId,
                2,
                BCC_NOTIFICATION_MODULE_ID,
                0,
                0,
                $type,
                'msg: ' . $type,
                '2026-07-20 12:00:00',
                $read
            )
        );
    }

    /** @param list<object> $rows @return list<string> */
    private static function types(array $rows): array
    {
        return array_map(static fn(object $r): string => (string) $r->not_type, $rows);
    }

    public function testRetiredTypesDoNotInflateUnreadBadge(): void
    {
        $repo = new NotificationRepository();

        $this->seed(42, 'bcc_review');      // live type
        $this->seed(42, 'bcc_endorse');     // retired v1.50
        $this->seed(42, 'bcc_card_pulled'); // retired by the pull→watch rename

        self::assertSame(1, $repo->countUnreadForUser(42), 'badge counts only renderable types');
    }

    public function testDigestReadSkipsRetiredTypes(): void
    {
        $repo = new NotificationRepository();

        $this->seed(42, 'bcc_review');
        $this->seed(42, 'bcc_endorse');

        $rows = $repo->findUnreadSince(42, '2026-07-01 00:00:00', 25);
        self::assertCount(1, $rows);
        self::assertSame('bcc_review', $rows[0]->not_type);
    }

    /**
     * The full pagination matrix: retired rows interleaved with valid
     * ones (insertion order = not_id order; list reads newest-first).
     * Retired rows — unread AND read — must never consume page slots,
     * shorten pages, or stall the cursor.
     */
    public function testRetiredRowsCannotEatPageSlotsOrStallPagination(): void
    {
        $repo = new NotificationRepository();

        $this->seed(42, 'bcc_review');            // oldest valid
        $this->seed(42, 'bcc_endorse');           // retired, unread
        $this->seed(42, 'bcc_rank_up');           // valid
        $this->seed(42, 'bcc_card_pulled');       // retired, unread
        $this->seed(42, 'bcc_endorse', 1);        // retired, READ
        $this->seed(42, 'bcc_welcome');           // newest valid
        $this->seed(42, 'bcc_card_pulled');       // retired — newest raw rows…
        $this->seed(42, 'bcc_endorse');           // …form an all-retired head

        // First raw page would be entirely retired without the SQL
        // predicate; with it, even limit=1 returns a valid row.
        $solo = $repo->findForUser(42, 1, 0);
        self::assertSame(['bcc_welcome'], self::types($solo), 'all-retired head must not yield an empty page');

        // Full first page: exactly the two newest VALID rows.
        $page1 = $repo->findForUser(42, 2, 0);
        self::assertSame(['bcc_welcome', 'bcc_rank_up'], self::types($page1), 'retired rows must not consume page slots');

        // Second page via the exclusive cursor reaches the valid row
        // that sits beyond several retired ones.
        $cursor = (int) $page1[1]->not_id;
        $page2  = $repo->findForUser(42, 2, $cursor);
        self::assertSame(['bcc_review'], self::types($page2), 'cursor page must surface valid rows past retired ones');

        // And the badge agrees with what actually renders: 3 unread
        // valid rows (review, rank_up, welcome).
        self::assertSame(3, $repo->countUnreadForUser(42));
    }
}
