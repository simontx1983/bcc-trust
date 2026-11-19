<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\PostViewRecorder;
use BCC\Trust\Tests\Support\CoreSibling;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The view beacon and a SECRET page's announcement.
 *
 * The beacon answers 204 whatever happens, so it was never an existence
 * oracle; but a stranger's view of a notice they may not see still counted
 * toward its reach. Now it is ignored exactly as an unknown post is, before
 * anything is written. The page rule is AnnouncementPageAccessTest's; this
 * pins its wiring, on both entry points.
 *
 * The real PostViewRecorder, FeedItemGate and bcc-core FeedItemNormalizer;
 * the activity read (tests/Stubs/feed-item-gate-stubs.php) and the page rule
 * (tests/Stubs/announcement-page-access-stub.php) are doubled.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PostViewRecorderSecretNoticeTest extends TestCase
{
    private const NOTICE_ACT = 9205;
    private const VIEWER     = 5;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/feed-item-gate-stubs.php';
        require_once __DIR__ . '/../Stubs/announcement-page-access-stub.php';
        require_once CoreSibling::require('src/Feed/FeedItemNormalizer.php');

        $GLOBALS['__bcc_fig_comment_acts']  = [];
        $GLOBALS['__bcc_fig_module_by_act'] = [self::NOTICE_ACT => '205'];
        // The viewer is the notice's author, so a view that gets PAST the
        // page check stops at the author rule, before any write.
        $GLOBALS['__bcc_fig_author'] = self::VIEWER;
        $GLOBALS['__bcc_apa']        = ['hidden' => [], 'calls' => []];
    }

    public function testAHiddenNoticesViewIsIgnoredLikeAnUnknownPost(): void
    {
        $GLOBALS['__bcc_apa']['hidden'] = [self::NOTICE_ACT];

        self::assertSame('ignored_unknown_post', PostViewRecorder::record(self::NOTICE_ACT, 'feed', self::VIEWER));
        self::assertSame('ignored_unknown_post', PostViewRecorder::recordProfileVisit(self::NOTICE_ACT, 'profile', self::VIEWER));
        self::assertSame(
            [self::NOTICE_ACT . ':' . self::VIEWER, self::NOTICE_ACT . ':' . self::VIEWER],
            $GLOBALS['__bcc_apa']['calls'],
            'asked for the beacon viewer, on both entry points'
        );
    }

    public function testANoticeTheViewerMaySeeGetsPastThePageCheck(): void
    {
        self::assertSame('ignored_author', PostViewRecorder::record(self::NOTICE_ACT, 'feed', self::VIEWER));
        self::assertSame('ignored_author', PostViewRecorder::recordProfileVisit(self::NOTICE_ACT, 'profile', self::VIEWER));
    }
}
