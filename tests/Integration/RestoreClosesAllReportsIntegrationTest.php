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

    private bool $activitiesWasOurs = false;
    private bool $seeded = false;

    private ModerationQueueService $service;
    private ContentReportRepository $reports;
    private HiddenActivityRepository $hidden;
    private AutoHideService $autoHide;

    protected function setUp(): void
    {
        // Separate process: these stubs must never reach a sibling suite.
        require_once dirname(__DIR__) . '/Stubs/moderation-integration-stubs.php';
        $this->buildFixture();
        $GLOBALS['__bcc_test_actions'] = [];
        $this->reports  = new ContentReportRepository();
        $this->hidden   = new HiddenActivityRepository();
        $this->autoHide = new AutoHideService($this->reports, $this->hidden);
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
        if ($this->seeded) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", self::POST_ID));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->posts} WHERE ID = %d", self::POST_ID));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'peepso_activities WHERE act_id = %d', self::ACT_ID));
            $this->seeded = false;
        }
        if ($this->activitiesWasOurs) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'peepso_activities');
            $this->activitiesWasOurs = false;
        }
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

    private function buildFixture(): void
    {
        global $wpdb;

        $acts = $wpdb->prefix . 'peepso_activities';
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $acts)) === $acts;
        if (!$exists) {
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE {$acts} (
                    act_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    act_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    act_module_id VARCHAR(20) NOT NULL DEFAULT '0',
                    act_external_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    act_access INT NOT NULL DEFAULT 10,
                    PRIMARY KEY (act_id)
                ) DEFAULT CHARSET=utf8mb4"
            ), 'create activities: ' . $wpdb->last_error);
            $this->activitiesWasOurs = true;
        }

        // Add only what the shared harness wp_posts lacks.
        $wanted = [
            'post_author'   => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'post_date_gmt' => "DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00'",
        ];
        $existing = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $wpdb->posts
        ));
        $adds = [];
        foreach ($wanted as $column => $definition) {
            if (!in_array($column, (array) $existing, true)) {
                $adds[] = "ADD COLUMN {$column} {$definition}";
            }
        }
        if ($adds !== []) {
            self::assertNotFalse($wpdb->query("ALTER TABLE {$wpdb->posts} " . implode(', ', $adds)), $wpdb->last_error);
        }

        foreach ([[$acts, 'act_id', self::ACT_ID], [$wpdb->posts, 'ID', self::POST_ID]] as [$table, $col, $id]) {
            $taken = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$col} = %d", $id));
            if ($taken > 0) {
                self::markTestSkipped("{$table}.{$col}={$id} already exists; refusing to overwrite a real row");
            }
        }

        self::assertNotFalse($wpdb->insert($wpdb->posts, [
            'ID' => self::POST_ID, 'post_type' => 'peepso-post', 'post_status' => 'publish', 'post_author' => 960001,
        ]), $wpdb->last_error);
        self::assertNotFalse($wpdb->insert($acts, [
            'act_id' => self::ACT_ID, 'act_module_id' => '1', 'act_external_id' => self::POST_ID, 'act_owner_id' => 960001,
        ]), $wpdb->last_error);
        $this->seeded = true;
    }
}
