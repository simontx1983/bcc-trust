<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\ContentReportRepository;
use BCC\Trust\Core\Repositories\GifRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\PostShortcodeRepository;
use BCC\Trust\Core\Services\AutoHideService;
use BCC\Trust\Core\Services\ModerationQueueService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FeedActivityFixture.php';

/**
 * F-73 (restore side) — a restore closes EVERY pending report on the target
 * and starts a persisted re-hide cooldown. Real SQL, real AutoHideService.
 *
 * Before the fix a restore closed only the clicked report. The other pending
 * reports still counted toward the auto-hide threshold, so ONE new report
 * re-hid a post an admin had just judged fine.
 *
 * ⚠ Fixture hygiene (same rules as AnnouncementAutoHideExclusionIntegrationTest):
 *   - `peepso_activities` is created only when absent and dropped only when
 *     this suite created it;
 *   - `wp_posts` is the SHARED harness table — only columns it lacks are
 *     added (other suites widen it too);
 *   - only rows with this suite's ids are inserted and deleted; an id that is
 *     already taken SKIPS the test rather than overwriting a real row.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(ModerationQueueService::class)]
#[CoversClass(AutoHideService::class)]
final class RestoreClosesAllReportsIntegrationTest extends TestCase
{
    private const ACT_ID  = 2200000001;
    private const POST_ID = 2200000001;
    private const ADMIN   = 940001;

    use FeedActivityFixture;

    private ModerationQueueService $service;
    private ContentReportRepository $reports;
    private HiddenActivityRepository $hidden;
    private AutoHideService $autoHide;

    protected function setUp(): void
    {
        // Separate process: these stubs must never reach a sibling suite.
        require_once dirname(__DIR__) . '/Stubs/moderation-integration-stubs.php';
        $this->seedFeedActivity(self::ACT_ID, self::POST_ID);
        $GLOBALS['__bcc_test_actions'] = [];
        $this->reports  = new ContentReportRepository();
        $this->hidden   = new HiddenActivityRepository();
        // Every reporter here is credible: this suite pins the restore /
        // cooldown behaviour; reporter credibility is pinned separately by
        // CredibleReporterAutoHideIntegrationTest.
        $this->autoHide = new AutoHideService(
            $this->reports,
            $this->hidden,
            new class extends \BCC\Trust\Rank\Services\RankCredibilityGate {
                public function isCredible(
                    int $userId,
                    bool $requireRankState = true,
                    bool $requireTierNeutral = true,
                    bool $requireNotSuspended = true,
                    bool $requireNotInRecovery = false,
                    bool $requireFraudClear = true
                ): bool {
                    return $userId > 0;
                }
            }
        );
        $this->service  = new ModerationQueueService(
            $this->reports,
            $this->hidden,
            new PostShortcodeRepository(),
            new GifRepository()
        );
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->prefix . 'bcc_content_reports WHERE target_kind = %s AND target_id = %d',
            'feed_item',
            self::ACT_ID
        ));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'bcc_hidden_activities WHERE act_id = %d', self::ACT_ID));
        $this->removeFeedActivities();
    }

    public function testRestoreClosesEveryPendingReportOnTheTarget(): void
    {
        [$r1, $r2, $r3] = $this->reportAndAutoHide([950001, 950002, 950003]);
        self::assertTrue($this->hidden->exists(self::ACT_ID), 'precondition: three reports auto-hid the post');

        $restore = $this->service->resolve($r1, ModerationQueueService::ACTION_RESTORE, self::ADMIN);
        self::assertTrue($restore['ok'] ?? false);

        foreach ([$r1, $r2, $r3] as $id) {
            self::assertSame(ModerationQueueService::STATUS_DISMISSED, $this->reportStatus($id), "report {$id} closed");
        }
        self::assertSame(0, $this->reports->countPendingForTarget('feed_item', self::ACT_ID));
        self::assertTrue(AutoHideService::inRehideCooldown(self::ACT_ID), 'the restore started the cooldown');
    }

    public function testFreshReportsCannotReHideARestoredPostDuringTheCooldown(): void
    {
        [$r1] = $this->reportAndAutoHide([950001, 950002, 950003]);
        $this->service->resolve($r1, ModerationQueueService::ACTION_RESTORE, self::ADMIN);

        // Even a full fresh threshold of new reports is withheld…
        $this->reportAndAutoHide([950004, 950005, 950006]);
        self::assertFalse($this->hidden->exists(self::ACT_ID), 'auto-hide is withheld during the cooldown');
        // …while the reports themselves still file and queue for admins.
        self::assertSame(3, $this->reports->countPendingForTarget('feed_item', self::ACT_ID));
    }

    public function testOneNewReportCannotReHideEvenWithoutTheCooldown(): void
    {
        [$r1] = $this->reportAndAutoHide([950001, 950002, 950003]);
        $this->service->resolve($r1, ModerationQueueService::ACTION_RESTORE, self::ADMIN);
        $this->expireCooldown();

        // The closed reports no longer count: one report is one, not four.
        $this->reportAndAutoHide([950004]);
        self::assertFalse($this->hidden->exists(self::ACT_ID));
    }

    public function testAfterTheCooldownAFreshThresholdHidesAgain(): void
    {
        [$r1] = $this->reportAndAutoHide([950001, 950002, 950003]);
        $this->service->resolve($r1, ModerationQueueService::ACTION_RESTORE, self::ADMIN);
        $this->expireCooldown();

        $this->reportAndAutoHide([950004, 950005, 950006]);
        self::assertTrue($this->hidden->exists(self::ACT_ID), 'the cooldown ends; the ordinary policy resumes');
    }

    public function testUndoingTheRestoreReopensTheReportsAndEndsTheCooldown(): void
    {
        [$r1, $r2, $r3] = $this->reportAndAutoHide([950001, 950002, 950003]);
        $restore = $this->service->resolve($r1, ModerationQueueService::ACTION_RESTORE, self::ADMIN);

        $undo = $this->service->undo((string) ($restore['undo']['token'] ?? ''), self::ADMIN);
        self::assertTrue($undo['ok'] ?? false, 'undo succeeds: ' . json_encode($undo));

        foreach ([$r1, $r2, $r3] as $id) {
            self::assertSame(ModerationQueueService::STATUS_PENDING, $this->reportStatus($id), "report {$id} reopened");
        }
        self::assertFalse(AutoHideService::inRehideCooldown(self::ACT_ID));
        self::assertTrue($this->hidden->exists(self::ACT_ID), 'the auto-hide is back');
    }

    public function testADismissStillClosesOnlyTheClickedReport(): void
    {
        $r1 = $this->reports->create(950001, 'feed_item', self::ACT_ID, 'spam', '');
        $r2 = $this->reports->create(950002, 'feed_item', self::ACT_ID, 'spam', '');

        $this->service->resolve($r1, ModerationQueueService::ACTION_DISMISS, self::ADMIN);

        self::assertSame(ModerationQueueService::STATUS_DISMISSED, $this->reportStatus($r1));
        self::assertSame(ModerationQueueService::STATUS_PENDING, $this->reportStatus($r2));
        self::assertFalse(AutoHideService::inRehideCooldown(self::ACT_ID), 'a dismiss starts no cooldown');
    }

    /**
     * File one report per reporter and run the real auto-hide subscriber
     * after each, exactly as the bcc_content_reported hook would.
     *
     * @param list<int> $reporters
     * @return list<int>
     */
    private function reportAndAutoHide(array $reporters): array
    {
        $ids = [];
        foreach ($reporters as $reporter) {
            $id = $this->reports->create($reporter, 'feed_item', self::ACT_ID, 'spam', '');
            self::assertGreaterThan(0, $id);
            $this->autoHide->onContentReported($reporter, 'feed_item', self::ACT_ID, $id, 'spam');
            $ids[] = $id;
        }
        return $ids;
    }

    private function expireCooldown(): void
    {
        update_post_meta(self::POST_ID, AutoHideService::META_REHIDE_COOLDOWN_UNTIL, (string) (time() - 1));
        self::assertFalse(AutoHideService::inRehideCooldown(self::ACT_ID));
    }

    private function reportStatus(int $reportId): int
    {
        $row = $this->reports->findById($reportId);
        self::assertNotNull($row);
        return (int) $row->status;
    }
}
