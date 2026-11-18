<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\NotificationDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * F-307: the author's bell text when an administrator takes their
 * announcement down or restores it. The wording was approved by the user
 * (2026-11-12) exactly as pinned here; a change needs a new approval.
 */
final class AnnouncementModeratedMessageTest extends TestCase
{
    public function testTheHiddenNoticeIsTheApprovedTextWithTheTitle(): void
    {
        self::assertSame(
            'A moderator hid your announcement "Maintenance window" because it breaks a site rule. It is no longer visible to others.',
            NotificationDispatcher::announcementModeratedMessage('Maintenance window', true)
        );
    }

    public function testTheRestoredNoticeIsTheApprovedTextWithTheTitle(): void
    {
        self::assertSame(
            'Your announcement "Maintenance window" was reviewed and restored.',
            NotificationDispatcher::announcementModeratedMessage('Maintenance window', false)
        );
    }

    public function testAnUntitledNoticeDropsTheQuotesRatherThanPrintingEmptyOnes(): void
    {
        self::assertSame(
            'A moderator hid your announcement because it breaks a site rule. It is no longer visible to others.',
            NotificationDispatcher::announcementModeratedMessage('', true)
        );
        self::assertSame(
            'Your announcement was reviewed and restored.',
            NotificationDispatcher::announcementModeratedMessage('', false)
        );
    }
}
