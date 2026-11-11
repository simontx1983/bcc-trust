<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\ContentReportRepository;
use BCC\Trust\Core\Repositories\GifRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\PostShortcodeRepository;
use BCC\Trust\Core\Services\ModerationQueueService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-72 / F-111 — an admin RESTORE is the verdict "this content is fine", so
 * it must never read as an UPHELD report. Real SQL on both engines.
 *
 * Before the fix, restore wrote status 1 (the same value as "action taken").
 * ContentReportRepository::hasUpheldForTarget() — the Apprentice confirmation
 * predicate — then VOIDED the author's confirmation, and
 * countUpheldByReporterSince() credited the reporters. An earlier admin
 * Hide's report also stayed upheld after the restore, and undoing a
 * misclicked restore re-hid the post under the undoing admin with reason
 * 'admin_undo_restore', turning an auto-hide into an admin hide.
 *
 * Owns only rows keyed by its own (far-out-of-range) target ids, deleted in
 * setUp and tearDown.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(ModerationQueueService::class)]
#[CoversClass(ContentReportRepository::class)]
final class ModerationRestoreIntegrationTest extends TestCase
{
    private const TARGET  = 2100000001;
    private const ADMIN_1 = 920001;
    private const ADMIN_2 = 920002;

    private ModerationQueueService $service;
    private ContentReportRepository $reports;
    private HiddenActivityRepository $hidden;

    protected function setUp(): void
    {
        $this->cleanup();
        $GLOBALS['__bcc_test_actions'] = [];
        $this->reports = new ContentReportRepository();
        $this->hidden  = new HiddenActivityRepository();
        $this->service = new ModerationQueueService(
            $this->reports,
            $this->hidden,
            new PostShortcodeRepository(),
            new GifRepository()
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testRestoreAfterAnAdminHideLeavesNoUpheldReport(): void
    {
        $r1 = $this->report(930001);
        $r2 = $this->report(930002);

        $hide = $this->service->resolve($r1, ModerationQueueService::ACTION_HIDE, self::ADMIN_1);
        self::assertTrue($hide['ok'] ?? false);
        self::assertTrue($this->reports->hasUpheldForTarget('feed_item', self::TARGET), 'precondition: the hide upheld r1');

        $restore = $this->service->resolve($r2, ModerationQueueService::ACTION_RESTORE, self::ADMIN_2);
        self::assertTrue($restore['ok'] ?? false);

        self::assertSame(ModerationQueueService::STATUS_DISMISSED, $this->reportStatus($r2), 'the restored report is dismissed, never resolved');
        self::assertSame(ModerationQueueService::STATUS_DISMISSED, $this->reportStatus($r1), 'the earlier hide report is no longer upheld');
        self::assertFalse($this->reports->hasUpheldForTarget('feed_item', self::TARGET), 'nothing voids the author any more');
        self::assertSame(0, $this->reports->countUpheldByReporterSince(930001, '2000-01-01 00:00:00'));
        self::assertFalse($this->hidden->exists(self::TARGET));

        // The reversal listener gets every un-upheld id.
        self::assertSame(
            [self::TARGET, self::ADMIN_2, $r2, [$r1]],
            $this->lastAction('bcc_content_restored_by_admin')
        );
    }

    public function testRestoreOfAnAutoHiddenPostNeverReadsAsUpheld(): void
    {
        $r1 = $this->report(930001);
        $this->report(930002);
        $this->report(930003);
        self::assertTrue($this->hidden->add(self::TARGET, 0, 'autohide_threshold', $r1));

        $restore = $this->service->resolve($r1, ModerationQueueService::ACTION_RESTORE, self::ADMIN_1);
        self::assertTrue($restore['ok'] ?? false);

        self::assertSame(ModerationQueueService::STATUS_DISMISSED, $this->reportStatus($r1));
        self::assertFalse($this->reports->hasUpheldForTarget('feed_item', self::TARGET));
        self::assertSame(0, $this->reports->countUpheldByReporterSince(930001, '2000-01-01 00:00:00'));
    }

    public function testUndoingARestorePreservesTheOriginalAutoHide(): void
    {
        $r1 = $this->report(930001);
        self::assertTrue($this->hidden->add(self::TARGET, 0, 'autohide_threshold', $r1));

        $restore = $this->service->resolve($r1, ModerationQueueService::ACTION_RESTORE, self::ADMIN_1);
        $token   = (string) ($restore['undo']['token'] ?? '');
        self::assertNotSame('', $token, 'restore issued an undo token');

        $undo = $this->service->undo($token, self::ADMIN_1);
        self::assertTrue($undo['ok'] ?? false, 'undo succeeds: ' . json_encode($undo));
        self::assertTrue($undo['currently_hidden'] ?? false);

        $row = $this->hidden->findByActId(self::TARGET);
        self::assertNotNull($row);
        self::assertSame(0, (int) $row->hidden_by_user_id, 'still an AUTO-hide, not the undoing admin');
        self::assertSame('autohide_threshold', (string) $row->reason_code);
        self::assertSame($r1, (int) $row->related_report_id);
        self::assertSame(ModerationQueueService::STATUS_PENDING, $this->reportStatus($r1));
    }

    public function testUndoingARestoreReUpholdsTheSiblingsItDismissed(): void
    {
        $r1 = $this->report(930001);
        $r2 = $this->report(930002);
        $this->service->resolve($r1, ModerationQueueService::ACTION_HIDE, self::ADMIN_1);

        $restore = $this->service->resolve($r2, ModerationQueueService::ACTION_RESTORE, self::ADMIN_2);
        $undo    = $this->service->undo((string) ($restore['undo']['token'] ?? ''), self::ADMIN_2);
        self::assertTrue($undo['ok'] ?? false, 'undo succeeds: ' . json_encode($undo));

        self::assertSame(ModerationQueueService::STATUS_RESOLVED, $this->reportStatus($r1), 'the admin hide stands again');
        self::assertSame(ModerationQueueService::STATUS_PENDING, $this->reportStatus($r2));
        $row = $this->hidden->findByActId(self::TARGET);
        self::assertNotNull($row);
        self::assertSame(self::ADMIN_1, (int) $row->hidden_by_user_id, 'the ORIGINAL hider, not the undoing admin');
        self::assertSame('admin_action', (string) $row->reason_code);
        self::assertSame($r1, (int) $row->related_report_id);
    }

    public function testUndoingARestoreOfAVisiblePostDoesNotHideIt(): void
    {
        $r1 = $this->report(930001);

        $restore = $this->service->resolve($r1, ModerationQueueService::ACTION_RESTORE, self::ADMIN_1);
        $undo    = $this->service->undo((string) ($restore['undo']['token'] ?? ''), self::ADMIN_1);

        self::assertTrue($undo['ok'] ?? false, 'undo succeeds: ' . json_encode($undo));
        self::assertFalse($undo['currently_hidden'] ?? true);
        self::assertFalse($this->hidden->exists(self::TARGET), 'undo cannot hide what the restore never un-hid');
    }

    public function testTransitionStatusIsGuardedOnTheCurrentStatus(): void
    {
        $r1 = $this->report(930001);
        $r2 = $this->report(930002);
        $this->reports->updateStatus($r1, ModerationQueueService::STATUS_RESOLVED, self::ADMIN_1);

        // Only r1 is at status 1 — r2 (pending) must be left alone.
        self::assertSame(1, $this->reports->transitionStatus([$r1, $r2], 1, 2, self::ADMIN_2));
        self::assertSame(2, $this->reportStatus($r1));
        self::assertSame(0, $this->reportStatus($r2));
        self::assertSame(0, $this->reports->transitionStatus([], 1, 2, self::ADMIN_2));
    }

    private function report(int $reporter): int
    {
        $id = $this->reports->create($reporter, 'feed_item', self::TARGET, 'spam', '');
        self::assertGreaterThan(0, $id);
        return $id;
    }

    private function reportStatus(int $reportId): int
    {
        $row = $this->reports->findById($reportId);
        self::assertNotNull($row);
        return (int) $row->status;
    }

    /** @return list<mixed>|null */
    private function lastAction(string $hook): ?array
    {
        $found = null;
        foreach ($GLOBALS['__bcc_test_actions'] ?? [] as [$name, $args]) {
            if ($name === $hook) {
                $found = $args;
            }
        }
        return $found;
    }

    private function cleanup(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->prefix . 'bcc_content_reports WHERE target_kind = %s AND target_id = %d',
            'feed_item',
            self::TARGET
        ));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->prefix . 'bcc_hidden_activities WHERE act_id = %d',
            self::TARGET
        ));
    }
}
