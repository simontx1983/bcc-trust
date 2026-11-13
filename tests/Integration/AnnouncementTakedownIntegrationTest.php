<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Announcements\AnnouncementPostType;
use BCC\Trust\Core\Announcements\AnnouncementVisibility as Vis;
use BCC\Trust\Core\Repositories\ContentReportRepository;
use BCC\Trust\Core\Repositories\GifRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\PeepSoActivityWriter;
use BCC\Trust\Core\Repositories\PostShortcodeRepository;
use BCC\Trust\Core\Services\AnnouncementModerationService;
use BCC\Trust\Core\Services\ModerationQueueService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FeedActivityFixture.php';

/**
 * F-307 — an administrator can take a reported announcement down, and
 * restore it. Real SQL: the notice, its marker, its feed projection's hidden
 * row and the reports are all real rows.
 *
 * Before: the queue force-dismissed every announcement report whatever the
 * admin chose, and a report on the notice's FEED projection hid only the
 * feed copy while the notice stayed up on its page and permalink.
 *
 * Fixture hygiene: own ids only; a taken id SKIPS; the shared wp_posts table
 * only gains columns it lacks.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(ModerationQueueService::class)]
#[CoversClass(AnnouncementModerationService::class)]
final class AnnouncementTakedownIntegrationTest extends TestCase
{
    private const PAGE_ID   = 2307000001;
    private const NOTICE_ID = 2307000002;
    private const ACT_ID    = 2307000003;
    private const AUTHOR    = 963071;
    private const ADMIN     = 943071;

    use FeedActivityFixture;

    private ModerationQueueService $service;
    private ContentReportRepository $reports;
    private HiddenActivityRepository $hidden;
    private AnnouncementModerationService $takedown;

    protected function setUp(): void
    {
        // Separate process: these stubs must never reach a sibling suite.
        require_once dirname(__DIR__) . '/Stubs/moderation-integration-stubs.php';
        require_once __DIR__ . '/announcement-takedown-integration-stubs.php';
        $GLOBALS['__bcc_test_actions'] = [];

        // The notice's projection: module 205, external id = the notice.
        $this->seedFeedActivity(self::ACT_ID, self::NOTICE_ID, '205', self::AUTHOR);
        $this->ensurePostParentColumn();
        global $wpdb;
        $wpdb->update($wpdb->posts, [
            'post_type'     => AnnouncementPostType::POST_TYPE,
            'post_parent'   => self::PAGE_ID,
            'post_title'    => 'Maintenance window',
            'post_content'  => 'Nodes restart at noon.',
            'post_date_gmt' => '2026-01-01 00:00:00',
        ], ['ID' => self::NOTICE_ID]);
        $taken = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", self::PAGE_ID));
        if ($taken > 0) {
            self::markTestSkipped('page id taken; refusing to overwrite a real row');
        }
        self::assertNotFalse($wpdb->insert($wpdb->posts, [
            'ID' => self::PAGE_ID, 'post_type' => 'peepso-page', 'post_status' => 'publish', 'post_name' => 'val-slug',
        ]), $wpdb->last_error);

        $this->reports  = new ContentReportRepository();
        $this->hidden   = new HiddenActivityRepository();
        $this->takedown = new AnnouncementModerationService($this->hidden, new PeepSoActivityWriter());
        $this->service  = new ModerationQueueService(
            $this->reports,
            $this->hidden,
            new PostShortcodeRepository(),
            new GifRepository(),
            $this->takedown
        );
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ([['announcement', self::NOTICE_ID], ['feed_item', self::ACT_ID]] as [$kind, $id]) {
            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . $wpdb->prefix . 'bcc_content_reports WHERE target_kind = %s AND target_id = %d',
                $kind,
                $id
            ));
        }
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'bcc_hidden_activities WHERE act_id = %d', self::ACT_ID));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->posts} WHERE ID = %d", self::PAGE_ID));
        $this->removeFeedActivities();
    }

    public function testHideTakesTheNoticeDownAndUpholdsEveryReportOnIt(): void
    {
        $r1 = $this->report(953071, 'announcement', self::NOTICE_ID);
        $r2 = $this->report(953072, 'announcement', self::NOTICE_ID);
        $r3 = $this->report(953073, 'feed_item', self::ACT_ID);

        $hide = $this->service->resolve($r1, ModerationQueueService::ACTION_HIDE, self::ADMIN);
        self::assertTrue($hide['ok'] ?? false, json_encode($hide) ?: '');
        self::assertSame(ModerationQueueService::ACTION_HIDE, $hide['action'], 'never silently rewritten to a dismiss');
        self::assertTrue($hide['currently_hidden']);

        self::assertTrue($this->takedown->isHidden(self::NOTICE_ID), 'the marker every announcement read checks is set');
        $row = $this->hidden->findByActId(self::ACT_ID);
        self::assertNotNull($row, 'the feed projection is hidden too');
        self::assertSame(AnnouncementModerationService::REASON_TAKEDOWN, (string) $row->reason_code);
        foreach ([$r1, $r2, $r3] as $id) {
            self::assertSame(ModerationQueueService::STATUS_RESOLVED, $this->reportStatus($id), "report {$id} upheld");
        }
        self::assertContains('bcc_announcement_moderation_hidden', $this->firedHooks());
    }

    /** The two report paths must not diverge: a projection report takes the SAME takedown. */
    public function testAReportOnTheFeedProjectionTakesTheNoticeDownToo(): void
    {
        $onNotice     = $this->report(953071, 'announcement', self::NOTICE_ID);
        $onProjection = $this->report(953072, 'feed_item', self::ACT_ID);

        $hide = $this->service->resolve($onProjection, ModerationQueueService::ACTION_HIDE, self::ADMIN);
        self::assertTrue($hide['ok'] ?? false, json_encode($hide) ?: '');

        self::assertTrue($this->takedown->isHidden(self::NOTICE_ID), 'the notice itself is down, not only its feed copy');
        self::assertSame(ModerationQueueService::STATUS_RESOLVED, $this->reportStatus($onNotice), 'the report on the notice is upheld too');
    }

    public function testRestoreLiftsTheTakedownAndClosesEveryReportAsNotUpheld(): void
    {
        $r1 = $this->report(953071, 'announcement', self::NOTICE_ID);
        $r2 = $this->report(953072, 'announcement', self::NOTICE_ID);
        $this->service->resolve($r1, ModerationQueueService::ACTION_HIDE, self::ADMIN);
        $r3 = $this->report(953073, 'feed_item', self::ACT_ID);

        $restore = $this->service->resolve($r2, ModerationQueueService::ACTION_RESTORE, self::ADMIN);
        self::assertTrue($restore['ok'] ?? false, json_encode($restore) ?: '');
        self::assertFalse($restore['currently_hidden']);

        self::assertFalse($this->takedown->isHidden(self::NOTICE_ID));
        self::assertSame('', (string) get_post_meta(self::NOTICE_ID, Vis::META_MODERATION_HIDDEN_BY, true), 'the whole marker is cleared');
        self::assertFalse($this->hidden->exists(self::ACT_ID), 'the projection is visible again');
        foreach ([$r1, $r2, $r3] as $id) {
            self::assertSame(ModerationQueueService::STATUS_DISMISSED, $this->reportStatus($id), "report {$id} closed as not upheld");
        }
        self::assertContains('bcc_announcement_moderation_restored', $this->firedHooks());
    }

    public function testRestoringANoticeThatIsUpIsRefusedAndChangesNothing(): void
    {
        $r1 = $this->report(953071, 'announcement', self::NOTICE_ID);

        $restore = $this->service->resolve($r1, ModerationQueueService::ACTION_RESTORE, self::ADMIN);

        self::assertSame('bcc_invalid_request', $restore['error'] ?? null);
        self::assertSame(ModerationQueueService::STATUS_PENDING, $this->reportStatus($r1), 'the report is left as it was');
    }

    public function testRestoreLeavesAFeedHideItDidNotWrite(): void
    {
        // The projection was already hidden for another reason before the takedown.
        self::assertTrue($this->hidden->add(self::ACT_ID, self::ADMIN, 'admin_action', null));
        $r1 = $this->report(953071, 'announcement', self::NOTICE_ID);
        $this->service->resolve($r1, ModerationQueueService::ACTION_HIDE, self::ADMIN);
        $r2 = $this->report(953072, 'announcement', self::NOTICE_ID);

        $this->service->resolve($r2, ModerationQueueService::ACTION_RESTORE, self::ADMIN);

        self::assertFalse($this->takedown->isHidden(self::NOTICE_ID));
        self::assertTrue($this->hidden->exists(self::ACT_ID), 'an unrelated feed hide is not the takedown\'s to remove');
    }

    public function testUndoingATakedownPutsTheNoticeBackAndReopensTheReports(): void
    {
        $r1 = $this->report(953071, 'announcement', self::NOTICE_ID);
        $r2 = $this->report(953072, 'feed_item', self::ACT_ID);
        $hide = $this->service->resolve($r1, ModerationQueueService::ACTION_HIDE, self::ADMIN);

        $undo = $this->service->undo((string) ($hide['undo']['token'] ?? ''), self::ADMIN);
        self::assertTrue($undo['ok'] ?? false, 'undo succeeds: ' . json_encode($undo));
        self::assertFalse($undo['currently_hidden']);

        self::assertFalse($this->takedown->isHidden(self::NOTICE_ID));
        self::assertFalse($this->hidden->exists(self::ACT_ID));
        foreach ([$r1, $r2] as $id) {
            self::assertSame(ModerationQueueService::STATUS_PENDING, $this->reportStatus($id), "report {$id} reopened");
        }
    }

    public function testUndoingARestorePutsTheTakedownBack(): void
    {
        $r1 = $this->report(953071, 'announcement', self::NOTICE_ID);
        $this->service->resolve($r1, ModerationQueueService::ACTION_HIDE, self::ADMIN);
        $r2 = $this->report(953072, 'announcement', self::NOTICE_ID);
        $restore = $this->service->resolve($r2, ModerationQueueService::ACTION_RESTORE, self::ADMIN);

        $undo = $this->service->undo((string) ($restore['undo']['token'] ?? ''), self::ADMIN);
        self::assertTrue($undo['ok'] ?? false, 'undo succeeds: ' . json_encode($undo));
        self::assertTrue($undo['currently_hidden']);
        self::assertTrue($this->takedown->isHidden(self::NOTICE_ID));
        self::assertTrue($this->hidden->exists(self::ACT_ID));
    }

    public function testDismissLeavesTheNoticeUp(): void
    {
        $r1 = $this->report(953071, 'announcement', self::NOTICE_ID);

        $dismiss = $this->service->resolve($r1, ModerationQueueService::ACTION_DISMISS, self::ADMIN);

        self::assertTrue($dismiss['ok'] ?? false, json_encode($dismiss) ?: '');
        self::assertFalse($dismiss['currently_hidden']);
        self::assertFalse($this->takedown->isHidden(self::NOTICE_ID));
        self::assertSame(ModerationQueueService::STATUS_DISMISSED, $this->reportStatus($r1));
    }

    private function report(int $reporter, string $kind, int $targetId): int
    {
        $id = $this->reports->create($reporter, $kind, $targetId, 'spam', '');
        self::assertGreaterThan(0, $id);
        return $id;
    }

    private function reportStatus(int $reportId): int
    {
        $row = $this->reports->findById($reportId);
        self::assertNotNull($row);
        return (int) $row->status;
    }

    /** @return list<string> */
    private function firedHooks(): array
    {
        return array_map(static fn (array $a): string => (string) $a[0], $GLOBALS['__bcc_test_actions']);
    }

    private function ensurePostParentColumn(): void
    {
        global $wpdb;
        $cols = (array) $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $wpdb->posts
        ));
        if (!in_array('post_parent', $cols, true)) {
            self::assertNotFalse(
                $wpdb->query("ALTER TABLE {$wpdb->posts} ADD COLUMN post_parent BIGINT UNSIGNED NOT NULL DEFAULT 0"),
                $wpdb->last_error
            );
        }
    }
}
