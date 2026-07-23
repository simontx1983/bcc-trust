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
 * The bell list drops rows whose not_type is no longer in
 * NotificationType::ALL (NotificationViewService::shapeRow → null), so
 * the unread COUNT and the digest read must apply the same scope — or a
 * historical row of a retired type becomes a phantom: the badge says 1,
 * the list shows nothing, and the row can't be cleared row-by-row.
 * Seeds real rows and proves both scoped readers skip retired types
 * while findForUser (the raw feed the view service filters itself)
 * still returns them unchanged.
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

    private function seed(int $userId, string $type, string $ts = '2026-07-20 12:00:00'): void
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
                $ts,
                0
            )
        );
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

    public function testRawListStillReturnsLegacyRowsForViewSideFiltering(): void
    {
        $repo = new NotificationRepository();

        $this->seed(42, 'bcc_review');
        $this->seed(42, 'bcc_endorse');

        // findForUser stays deliberately unfiltered — the view service's
        // shapeRow drops invalid types itself; that contract is unchanged.
        self::assertCount(2, $repo->findForUser(42, 10, 0));
    }
}
