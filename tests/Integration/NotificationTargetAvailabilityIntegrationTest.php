<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\NotificationRepository;
use BCC\Trust\Core\Services\NotificationViewService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FeedActivityFixture.php';

/**
 * F-353: the bell's "is the post gone" check over real rows.
 *
 * The moderation half used to read the WHOLE hidden list on every
 * notification-list read; it now reads only the page's act ids
 * (HiddenActivityRepository::hiddenAmong, an IN on the primary key). The
 * unit suites pin the failure paths against doubles. What only real SQL can
 * show: the scoped read returns exactly the hidden subset, and the bell
 * marks a hidden post and a missing post gone while a live one stays.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(HiddenActivityRepository::class)]
#[CoversClass(NotificationViewService::class)]
final class NotificationTargetAvailabilityIntegrationTest extends TestCase
{
    use FeedActivityFixture;

    private const LIVE_ACT    = 3530000001;
    private const HIDDEN_ACT  = 3530000002;
    private const MISSING_ACT = 3530000003; // never seeded
    private const AUTHOR      = 965301;

    protected function setUp(): void
    {
        // The real bcc_hidden_activities table (as RemovedPostLiveness uses it).
        require_once dirname(__DIR__) . '/Stubs/moderation-integration-stubs.php';

        $this->seedFeedActivity(self::LIVE_ACT, self::LIVE_ACT, '1', self::AUTHOR);
        $this->seedFeedActivity(self::HIDDEN_ACT, self::HIDDEN_ACT, '1', self::AUTHOR);

        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_hidden_activities', [
            'act_id'            => self::HIDDEN_ACT,
            'hidden_at'         => '2026-11-17 00:00:00',
            'hidden_by_user_id' => 1,
            'reason_code'       => 'f353_test',
        ]), $wpdb->last_error);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->prefix . 'bcc_hidden_activities WHERE act_id IN (%d, %d, %d)',
            self::LIVE_ACT,
            self::HIDDEN_ACT,
            self::MISSING_ACT
        ));
        $this->removeFeedActivities();
    }

    public function testTheScopedReadReturnsOnlyTheHiddenIdsAsked(): void
    {
        global $wpdb;
        $out = (new HiddenActivityRepository())->hiddenAmong([self::LIVE_ACT, self::HIDDEN_ACT, self::MISSING_ACT]);

        self::assertSame([self::HIDDEN_ACT], $out, (string) $wpdb->last_error);
    }

    public function testTheBellMarksHiddenAndMissingPostsGoneAndLeavesTheLiveOne(): void
    {
        $svc = new NotificationViewService(
            (new \ReflectionClass(NotificationRepository::class))->newInstanceWithoutConstructor(),
            new HiddenActivityRepository()
        );
        $m = new \ReflectionMethod(NotificationViewService::class, 'goneActIds');
        $m->setAccessible(true);

        self::assertSame(
            [self::HIDDEN_ACT => true, self::MISSING_ACT => true],
            $m->invoke($svc, [self::LIVE_ACT, self::HIDDEN_ACT, self::MISSING_ACT])
        );
    }
}
