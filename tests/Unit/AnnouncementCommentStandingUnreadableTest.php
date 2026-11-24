<?php
/**
 * F-469: commenting on an announcement with a standing that could not be
 * READ is retryable. The real AnnouncementCommentService::create() answers
 * 503 `bcc_unavailable` with reason `unavailable` and the "could not be
 * checked" message, and writes nothing. A member whose standing WAS read and
 * is below Neutral still gets the 403 `suspended` refusal (the control).
 *
 * The harness is SecretPageAnnouncementsTest's (an open page and its
 * announcement), with a scripted viewer standing.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Announcements\AnnouncementCapabilityResolver as Caps;
use BCC\Trust\Core\Services\AnnouncementCommentService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AnnouncementCommentStandingUnreadableTest extends TestCase
{
    private const VIEWER   = 5;
    private const OWNER    = 70;
    private const OPEN     = 901;
    private const ANN_OPEN = 5002;

    protected function setUp(): void
    {
        // The scripted standing FIRST, so the harness's stand-in is skipped.
        require_once dirname(__DIR__) . '/Stubs/announcement-standing-scripted-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/announcement-endpoint-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/profile-access-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/secret-page-announcement-stubs.php';

        $GLOBALS['__bcc_options']        = ['bcc_announcements_enabled' => '1'];
        $GLOBALS['__bcc_current_user']   = self::VIEWER;
        $GLOBALS['__bcc_profile_access'] = ['viewer_id' => self::VIEWER, 'is_admin' => false];
        $GLOBALS['__bcc_posts'] = [
            self::OPEN     => \bcc_ann_post(self::OPEN, 'peepso-page', self::OWNER, 0, 'open-page'),
            self::ANN_OPEN => \bcc_ann_post(self::ANN_OPEN, 'bcc_announcement', self::OWNER, self::OPEN),
        ];
        $GLOBALS['__bcc_ann'] = [
            'rows'   => [self::OPEN => [\bcc_ann_row(self::ANN_OPEN, 'open notice')]],
            'public' => [self::OPEN . ':' . self::ANN_OPEN => \bcc_ann_row(self::ANN_OPEN, 'open notice')],
            'secret' => [],
            'calls'  => [],
        ];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__bcc_options'],
            $GLOBALS['__bcc_current_user'],
            $GLOBALS['__bcc_profile_access'],
            $GLOBALS['__bcc_posts'],
            $GLOBALS['__bcc_ann'],
            $GLOBALS['__bcc_viewer_standing']
        );
    }

    public function testAnUnreadableStandingIsA503AndNothingIsWritten(): void
    {
        $GLOBALS['__bcc_viewer_standing'] = self::standing(known: false);

        // The repository double has no create(): reaching the write would
        // error, so a clean 503 is also proof nothing was written.
        $result = (new AnnouncementCommentService())->create(self::ANN_OPEN, self::VIEWER, 'hello', false);

        self::assertSame(503, $result['status'] ?? null);
        self::assertSame(AnnouncementCommentService::ERR_UNAVAILABLE, $result['code'] ?? null);
        self::assertSame(Caps::REASON_STANDING_UNREADABLE, $result['reason'] ?? null);
        self::assertSame(Caps::STANDING_UNREADABLE_HINT, $result['message'] ?? null);
    }

    public function testAStandingBelowNeutralIsStillTheForbiddenSuspendedRefusal(): void
    {
        $GLOBALS['__bcc_viewer_standing'] = self::standing(known: true);

        $result = (new AnnouncementCommentService())->create(self::ANN_OPEN, self::VIEWER, 'hello', false);

        self::assertSame(403, $result['status'] ?? null);
        self::assertSame(AnnouncementCommentService::ERR_FORBIDDEN, $result['code'] ?? null);
        self::assertSame(Caps::REASON_SUSPENDED, $result['reason'] ?? null);
    }

    /** @return array<string, bool|int> */
    private static function standing(bool $known): array
    {
        return [
            'authenticated'  => true,
            'suspended'      => false,
            'fraud_locked'   => false,
            'good_standing'  => false,
            'standing_known' => $known,
            'user_id'        => self::VIEWER,
        ];
    }
}
