<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\ContentReportRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Services\AutoHideService;
use BCC\Trust\Rank\Services\RankCredibilityGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FeedActivityFixture.php';

/**
 * F-73 (report side) — only CREDIBLE reporters count toward auto-hide.
 * Real SQL, real AutoHideService; the credibility predicate is the one
 * double (its own rules are pinned by RankCredibilityGateTest).
 *
 * Before the fix the three-report threshold counted every report equally,
 * so three throwaway accounts could auto-hide any new member's first post —
 * the opening move of the F-71/F-72/F-73 chain.
 */
#[Group('integration')]
#[Group('mariadb')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
#[CoversClass(AutoHideService::class)]
final class CredibleReporterAutoHideIntegrationTest extends TestCase
{
    use FeedActivityFixture;

    private const ACT_ID  = 2300000001;
    private const POST_ID = 2300000001;

    /** Reporter ids 980001..980099 are credible; 990001.. are not. */
    private const CREDIBLE_BASE = 980000;
    private const THROWAWAY_BASE = 990000;

    private ContentReportRepository $reports;
    private HiddenActivityRepository $hidden;
    private AutoHideService $autoHide;

    /** @var list<int> */
    public static array $credibilityChecks = [];

    protected function setUp(): void
    {
        // Separate process: these stubs must never reach a sibling suite.
        require_once dirname(__DIR__) . '/Stubs/moderation-integration-stubs.php';
        $this->seedFeedActivity(self::ACT_ID, self::POST_ID);
        self::$credibilityChecks = [];
        $this->reports  = new ContentReportRepository();
        $this->hidden   = new HiddenActivityRepository();
        $this->autoHide = new AutoHideService(
            $this->reports,
            $this->hidden,
            new class extends RankCredibilityGate {
                public function isCredible(
                    int $userId,
                    bool $requireRankState = true,
                    bool $requireTierNeutral = true,
                    bool $requireNotSuspended = true,
                    bool $requireNotInRecovery = false,
                    bool $requireFraudClear = true
                ): bool {
                    CredibleReporterAutoHideIntegrationTest::$credibilityChecks[] = $userId;
                    // The production composite with every default flag on.
                    return $requireRankState && $requireTierNeutral && $requireNotSuspended && $requireFraudClear
                        && $userId > 980000 && $userId < 990000;
                }
            }
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

    public function testThreeThrowawayAccountsCannotHideAPost(): void
    {
        $this->report(self::THROWAWAY_BASE + 1, self::THROWAWAY_BASE + 2, self::THROWAWAY_BASE + 3);

        self::assertFalse($this->hidden->exists(self::ACT_ID), 'the F-73 chain opener is closed');
        self::assertSame(3, $this->reports->countPendingForTarget('feed_item', self::ACT_ID), 'every report still files and queues');
    }

    public function testThrowawaysDoNotTopUpCredibleReporters(): void
    {
        $this->report(
            self::CREDIBLE_BASE + 1,
            self::CREDIBLE_BASE + 2,
            self::THROWAWAY_BASE + 1,
            self::THROWAWAY_BASE + 2,
            self::THROWAWAY_BASE + 3,
            self::THROWAWAY_BASE + 4
        );
        self::assertFalse($this->hidden->exists(self::ACT_ID), '2 credible + 4 throwaway is still below 3 credible');
    }

    public function testThreeCredibleReportersStillHide(): void
    {
        $this->report(self::THROWAWAY_BASE + 1, self::CREDIBLE_BASE + 1, self::CREDIBLE_BASE + 2, self::CREDIBLE_BASE + 3);

        self::assertTrue($this->hidden->exists(self::ACT_ID), 'the ordinary policy holds for credible reporters');
        $row = $this->hidden->findByActId(self::ACT_ID);
        self::assertNotNull($row);
        self::assertSame(AutoHideService::REASON_AUTOHIDE_THRESHOLD, (string) $row->reason_code);
    }

    public function testCredibilityIsNotConsultedBelowTheRawThreshold(): void
    {
        $this->report(self::CREDIBLE_BASE + 1, self::CREDIBLE_BASE + 2);
        self::assertSame([], self::$credibilityChecks, 'cheap pre-filter: under 3 pending reports, no per-reporter checks');
    }

    private function report(int ...$reporters): void
    {
        foreach ($reporters as $reporter) {
            $id = $this->reports->create($reporter, 'feed_item', self::ACT_ID, 'spam', '');
            self::assertGreaterThan(0, $id);
            $this->autoHide->onContentReported($reporter, 'feed_item', self::ACT_ID, $id, 'spam');
        }
    }
}
