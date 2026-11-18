<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\NotificationRepository;
use BCC\Trust\Core\Services\NotificationDispatcher;
use BCC\Trust\Core\Support\NotificationPrefs;
use BCC\Trust\Core\Support\NotificationType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FeedActivityFixture.php';
require_once __DIR__ . '/PeepSoBlocksTableFixture.php';

/**
 * F-73 (PR E) — the author is told when their post is hidden AUTOMATICALLY.
 *
 * Real NotificationDispatcher, real PeepSoNotificationWriter path into a real
 * `peepso_notifications` table (PeepSo's add_notification is the delegator
 * stubs' faithful copy, down to its 200-char message truncation), real
 * NotificationRepository digest query. Pins the product decisions:
 * bell only (no push job, no mail, excluded from the email digest), the
 * approved copy verbatim, and no reporter identity anywhere in the row.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(NotificationDispatcher::class)]
#[CoversClass(NotificationRepository::class)]
final class AutoHideAuthorNoticeIntegrationTest extends TestCase
{
    use FeedActivityFixture;
    use PeepSoBlocksTableFixture;

    private const ACT_ID   = 2500000001;
    private const POST_ID  = 2500000001;
    private const AUTHOR   = 975001;
    private const REPORTER = 975002;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/delegator-group-stubs.php';
        $GLOBALS['__bcc_mail']        = [];
        $GLOBALS['bcc_scheduled']     = [];
        $GLOBALS['__bcc_usermeta']    = [];
        $GLOBALS['__bcc_test_object_cache'] = [];

        // F-296: the digest read excludes blocked members, fail-closed.
        $this->ensurePeepSoBlocksTable();

        global $wpdb;
        self::assertNotFalse($wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}peepso_notifications` (
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
            )"
        ), $wpdb->last_error);
        $this->deleteOurNotices();

        $this->seedFeedActivity(self::ACT_ID, self::POST_ID, '1', self::AUTHOR);
    }

    protected function tearDown(): void
    {
        $this->deleteOurNotices();
        $this->removeFeedActivities();
        $this->removePeepSoBlocksTable();
    }

    public function testTheAuthorGetsExactlyTheApprovedNotice(): void
    {
        $this->autoHide();

        $rows = $this->notices();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame((string) self::AUTHOR, (string) $row->not_user_id, 'to the author');
        self::assertSame((string) self::AUTHOR, (string) $row->not_from_user_id, 'from the author: no reporter identity');
        self::assertSame(NotificationType::CONTENT_AUTO_HIDDEN, $row->not_type);
        self::assertSame((string) self::ACT_ID, (string) $row->not_act_id);
        self::assertSame(
            'One of your posts was hidden automatically after several members reported it. '
            . 'A moderator will review it, and if it breaks no rule it will be restored.',
            $row->not_message,
            'the approved copy, verbatim and untruncated (PeepSo cuts at 200)'
        );
        self::assertStringNotContainsString((string) self::REPORTER, $row->not_message);
    }

    public function testItIsBellOnlyNoPushNoMailAndNotInTheDigest(): void
    {
        NotificationPrefs::writePartial(self::AUTHOR, ['email_digest' => true]);

        $this->autoHide();

        self::assertSame([], $GLOBALS['__bcc_mail'], 'no mail');
        self::assertSame([], $GLOBALS['bcc_scheduled'], 'no job scheduled, which is how a push would go out');

        $repository = new NotificationRepository();
        self::assertSame([], $repository->findUnreadSince(self::AUTHOR, '2000-01-01 00:00:00'), 'the digest email reads this');
        self::assertCount(1, $repository->findForUser(self::AUTHOR, 10, 0), 'the bell does not');
    }

    public function testTheAuthorCanTurnItOff(): void
    {
        NotificationPrefs::writePartial(self::AUTHOR, ['bell' => [NotificationType::CONTENT_AUTO_HIDDEN => false]]);

        $this->autoHide();

        self::assertSame([], $this->notices());
    }

    public function testAMissingActivityNotifiesNobody(): void
    {
        Plugin::instance()->notificationDispatcher()->onContentAutoHidden(self::ACT_ID + 9);
        self::assertSame([], $this->notices());
    }

    /** Fire the real hook payload exactly as AutoHideService does. */
    private function autoHide(): void
    {
        Plugin::instance()->notificationDispatcher()->onContentAutoHidden(self::ACT_ID);
    }

    /** @return list<object> */
    private function notices(): array
    {
        global $wpdb;
        /** @var list<object> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT not_user_id, not_from_user_id, not_type, not_act_id, not_external_id, not_message
               FROM `{$wpdb->prefix}peepso_notifications` WHERE not_user_id = %d",
            self::AUTHOR
        ));
        return $rows ?: [];
    }

    private function deleteOurNotices(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->prefix}peepso_notifications` WHERE not_user_id = %d",
            self::AUTHOR
        ));
    }
}
