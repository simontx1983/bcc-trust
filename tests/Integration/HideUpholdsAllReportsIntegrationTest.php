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
 * F-306 — an admin HIDE is the verdict "this content breaks a rule", so it
 * upholds EVERY pending report on the target, the mirror of F-73's restore.
 * Real SQL, real AutoHideService.
 *
 * Before the fix a hide closed only the clicked report. The others stayed
 * pending on a now-hidden post, where the queue offers only Restore and
 * Dismiss, so the only way to clear them recorded them as NOT upheld. An
 * auto-hidden post had the same problem for all of its reports.
 *
 * Fixture hygiene follows RestoreClosesAllReportsIntegrationTest: own ids
 * only, an id already taken SKIPS, shared tables are never dropped.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(ModerationQueueService::class)]
final class HideUpholdsAllReportsIntegrationTest extends TestCase
{
    private const ACT_ID  = 2306000001;
    private const POST_ID = 2306000001;
    private const ADMIN   = 943061;

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
        $this->service = new ModerationQueueService(
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

    public function testHideUpholdsEveryPendingReportOnTheTarget(): void
    {
        [$r1, $r2] = $this->report([953061, 953062]);
        self::assertFalse($this->hidden->exists(self::ACT_ID), 'precondition: two reports stay under the auto-hide threshold');

        $hide = $this->service->resolve($r1, ModerationQueueService::ACTION_HIDE, self::ADMIN);
        self::assertTrue($hide['ok'] ?? false, json_encode($hide) ?: '');
        self::assertTrue($hide['currently_hidden'] ?? false);

        foreach ([$r1, $r2] as $id) {
            self::assertSame(ModerationQueueService::STATUS_RESOLVED, $this->reportStatus($id), "report {$id} upheld");
        }
        self::assertSame(0, $this->reports->countPendingForTarget('feed_item', self::ACT_ID));
        self::assertTrue($this->reports->hasUpheldForTarget('feed_item', self::ACT_ID));

        // The Rank listener credits every upheld reporter: the hook carries the siblings.
        self::assertSame([[self::ACT_ID, self::ADMIN, $r1, [$r2]]], $this->firedArgs('bcc_content_hidden_by_admin'));
    }

    public function testHideNeverTouchesAReportAnotherModeratorAlreadyClosed(): void
    {
        [$r1, $r2, $r3] = $this->report([953061, 953062, 953063]);
        $this->service->resolve($r1, ModerationQueueService::ACTION_DISMISS, self::ADMIN);
        $GLOBALS['__bcc_test_actions'] = [];

        $this->service->resolve($r2, ModerationQueueService::ACTION_HIDE, self::ADMIN);

        self::assertSame(ModerationQueueService::STATUS_DISMISSED, $this->reportStatus($r1), 'a dismissed report stays dismissed');
        self::assertSame(ModerationQueueService::STATUS_RESOLVED, $this->reportStatus($r2));
        self::assertSame(ModerationQueueService::STATUS_RESOLVED, $this->reportStatus($r3));
    }

    public function testUpholdingAnAutoHiddenPostClosesEveryReportAndKeepsTheAutoHide(): void
    {
        [$r1, $r2, $r3] = $this->reportAndAutoHide([953061, 953062, 953063]);
        $before = $this->hidden->findByActId(self::ACT_ID);
        self::assertNotNull($before, 'precondition: three reports auto-hid the post');

        $hide = $this->service->resolve($r2, ModerationQueueService::ACTION_HIDE, self::ADMIN);
        self::assertTrue($hide['ok'] ?? false, json_encode($hide) ?: '');

        foreach ([$r1, $r2, $r3] as $id) {
            self::assertSame(ModerationQueueService::STATUS_RESOLVED, $this->reportStatus($id), "report {$id} upheld");
        }
        $after = $this->hidden->findByActId(self::ACT_ID);
        self::assertNotNull($after);
        self::assertSame((string) $before->reason_code, (string) $after->reason_code, 'the auto-hide stays an auto-hide');
    }

    public function testUndoingAnUpholdKeepsTheAutoHideAndReopensEveryReport(): void
    {
        [$r1, $r2, $r3] = $this->reportAndAutoHide([953061, 953062, 953063]);
        $hide = $this->service->resolve($r1, ModerationQueueService::ACTION_HIDE, self::ADMIN);

        $undo = $this->service->undo((string) ($hide['undo']['token'] ?? ''), self::ADMIN);
        self::assertTrue($undo['ok'] ?? false, 'undo succeeds: ' . json_encode($undo));
        self::assertTrue($undo['currently_hidden'] ?? false, 'undo never un-hides a post this hide did not hide');
        self::assertTrue($this->hidden->exists(self::ACT_ID));

        foreach ([$r1, $r2, $r3] as $id) {
            self::assertSame(ModerationQueueService::STATUS_PENDING, $this->reportStatus($id), "report {$id} reopened");
        }
        // The Rank listener reverses every reopened report's credit.
        $undone = $this->firedArgs('bcc_content_moderation_undone');
        self::assertCount(1, $undone);
        self::assertSame($r1, $undone[0][0]);
        self::assertEqualsCanonicalizing([$r2, $r3], $undone[0][3]);
    }

    public function testUndoingAFreshHideUnhidesAndReopensEveryReport(): void
    {
        [$r1, $r2] = $this->report([953061, 953062]);
        $hide = $this->service->resolve($r2, ModerationQueueService::ACTION_HIDE, self::ADMIN);

        $undo = $this->service->undo((string) ($hide['undo']['token'] ?? ''), self::ADMIN);
        self::assertTrue($undo['ok'] ?? false, 'undo succeeds: ' . json_encode($undo));
        self::assertFalse($undo['currently_hidden'] ?? true);
        self::assertFalse($this->hidden->exists(self::ACT_ID), 'the hide this action created is removed');
        foreach ([$r1, $r2] as $id) {
            self::assertSame(ModerationQueueService::STATUS_PENDING, $this->reportStatus($id), "report {$id} reopened");
        }
    }

    /**
     * @param list<int> $reporters
     * @return list<int>
     */
    private function report(array $reporters): array
    {
        $ids = [];
        foreach ($reporters as $reporter) {
            $id = $this->reports->create($reporter, 'feed_item', self::ACT_ID, 'spam', '');
            self::assertGreaterThan(0, $id);
            $ids[] = $id;
        }
        return $ids;
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

    /** @return list<list<mixed>> the args of every firing of $hook */
    private function firedArgs(string $hook): array
    {
        $out = [];
        foreach ($GLOBALS['__bcc_test_actions'] as [$name, $args]) {
            if ($name === $hook) {
                $out[] = $args;
            }
        }
        return $out;
    }

    private function reportStatus(int $reportId): int
    {
        $row = $this->reports->findById($reportId);
        self::assertNotNull($row);
        return (int) $row->status;
    }
}
