<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Support\CronHealState;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function BCC\Trust\Core\Services\wp_clear_scheduled_hook;
use function BCC\Trust\Core\Services\wp_next_scheduled;
use function BCC\Trust\Core\Services\wp_schedule_event;
use function BCC\Trust\Core\Services\wp_schedule_single_event;

/**
 * Tests the TEST DOUBLE, not production code.
 *
 * A cron double that keys events by hook alone collapses every WordPress
 * identity for that hook into one entry. Tests written against it pass while
 * modelling behaviour WordPress does not have — the "green test proving
 * nothing" failure this repository has been bitten by before.
 *
 * The double is the evidence base for every later assertion about scheduling,
 * so its own fidelity has to be asserted rather than assumed. Each case below
 * pins one property of real WordPress cron:
 *
 *   - identity is (hook, serialized args), not hook;
 *   - wp_clear_scheduled_hook() only clears the identity it was asked for;
 *   - wp_schedule_single_event() rejects a same-identity duplicate inside the
 *     ten-minute window and returns false;
 *   - outside that window the same identity may hold several timestamps;
 *   - wp_next_scheduled() reports the earliest pending timestamp.
 */
#[CoversNothing]
final class CronStubFidelityTest extends TestCase
{
    private const HOOK = 'bcc_test_slice';

    protected function setUp(): void
    {
        parent::setUp();
        CronHealState::reset();
    }

    protected function tearDown(): void
    {
        CronHealState::$active = false;
        parent::tearDown();
    }

    // ── identity ────────────────────────────────────────────────────────

    public function testTheSameHookWithDifferentArgumentsIsTwoDistinctEvents(): void
    {
        self::assertTrue(wp_schedule_single_event(CronHealState::$now + 60, self::HOOK, [1]));
        self::assertTrue(wp_schedule_single_event(CronHealState::$now + 60, self::HOOK, [2]));

        self::assertSame(
            CronHealState::$now + 60,
            wp_next_scheduled(self::HOOK, [1]),
            'the event scheduled for job 1 must be findable by its own arguments'
        );
        self::assertSame(
            CronHealState::$now + 60,
            wp_next_scheduled(self::HOOK, [2]),
            'job 2 is a separate identity and must not have been rejected as a duplicate of job 1'
        );
        self::assertCount(
            2,
            CronHealState::$scheduled,
            'two argument lists must produce two stored events, not one collapsed entry'
        );
    }

    public function testAnEventWithArgumentsIsNotVisibleToANoArgumentLookup(): void
    {
        wp_schedule_single_event(CronHealState::$now + 60, self::HOOK, [1]);

        self::assertFalse(
            wp_next_scheduled(self::HOOK),
            'wp_next_scheduled($hook) must not see an event scheduled with arguments'
        );
    }

    public function testDistinctArgumentsAreNotCollapsedIntoOneFakeEntry(): void
    {
        foreach ([[1], [2], [3], ['a'], []] as $args) {
            /** @var array<int, mixed> $args */
            wp_schedule_single_event(CronHealState::$now + 60, self::HOOK, $args);
        }

        self::assertCount(
            5,
            CronHealState::$scheduled,
            'five WordPress identities must be five stored events'
        );
    }

    // ── clearing ────────────────────────────────────────────────────────

    public function testClearingRemovesOnlyTheRequestedHookAndArgumentCombination(): void
    {
        wp_schedule_single_event(CronHealState::$now + 60, self::HOOK, [1]);
        wp_schedule_single_event(CronHealState::$now + 60, self::HOOK, [2]);
        wp_schedule_event(CronHealState::$now + 60, 'hourly', 'bcc_test_other');

        self::assertSame(1, wp_clear_scheduled_hook(self::HOOK, [1]), 'one occurrence removed');

        self::assertFalse(wp_next_scheduled(self::HOOK, [1]), 'the cleared identity is gone');
        self::assertNotFalse(wp_next_scheduled(self::HOOK, [2]), 'a sibling argument list must survive');
        self::assertNotFalse(wp_next_scheduled('bcc_test_other'), 'an unrelated hook must survive');
    }

    public function testClearingWithoutArgumentsLeavesArgumentBearingEventsAlone(): void
    {
        wp_schedule_event(CronHealState::$now + 60, 'hourly', self::HOOK);
        wp_schedule_single_event(CronHealState::$now + 60, self::HOOK, [1]);

        self::assertSame(1, wp_clear_scheduled_hook(self::HOOK));

        self::assertFalse(wp_next_scheduled(self::HOOK), 'the no-argument event is cleared');
        self::assertNotFalse(
            wp_next_scheduled(self::HOOK, [1]),
            'wp_clear_scheduled_hook($hook) must not remove an event scheduled with arguments'
        );
    }

    public function testClearingSomethingUnscheduledRemovesNothingAndReportsZero(): void
    {
        self::assertSame(0, wp_clear_scheduled_hook('bcc_test_never_scheduled'));
    }

    // ── single-event duplicate rejection ────────────────────────────────

    public function testASecondSingleEventInsideTheTenMinuteWindowIsRejected(): void
    {
        self::assertTrue(wp_schedule_single_event(CronHealState::$now + 60, self::HOOK, [7]));

        self::assertFalse(
            wp_schedule_single_event(CronHealState::$now + 120, self::HOOK, [7]),
            'WordPress rejects a same-identity single event within ten minutes'
        );
        self::assertSame(
            [CronHealState::$now + 60],
            CronHealState::timestampsFor(self::HOOK, [7]),
            'the rejected event must not have been stored'
        );
    }

    public function testTheDuplicateWindowBoundaryIsInclusive(): void
    {
        $base = CronHealState::$now + 1000;
        wp_schedule_single_event($base, self::HOOK, [7]);

        self::assertFalse(
            wp_schedule_single_event($base + CronHealState::SINGLE_EVENT_DUPLICATE_WINDOW, self::HOOK, [7]),
            'exactly ten minutes away is still a duplicate'
        );
        self::assertTrue(
            wp_schedule_single_event($base + CronHealState::SINGLE_EVENT_DUPLICATE_WINDOW + 1, self::HOOK, [7]),
            'one second beyond the window is permitted'
        );
    }

    public function testDuplicateRejectionIsScopedToTheArgumentList(): void
    {
        wp_schedule_single_event(CronHealState::$now + 60, self::HOOK, [1]);

        self::assertTrue(
            wp_schedule_single_event(CronHealState::$now + 60, self::HOOK, [2]),
            'a different argument list is a different identity and is never a duplicate'
        );
    }

    public function testARejectedScheduleIsRecordedAsAttemptedButNotScheduled(): void
    {
        wp_schedule_single_event(CronHealState::$now + 60, self::HOOK, [7]);
        wp_schedule_single_event(CronHealState::$now + 90, self::HOOK, [7]);

        self::assertCount(2, CronHealState::$singleScheduleCalls, 'both attempts are visible');
        self::assertTrue(CronHealState::$singleScheduleCalls[0]['scheduled']);
        self::assertFalse(
            CronHealState::$singleScheduleCalls[1]['scheduled'],
            'the rejected attempt must be distinguishable from a successful one'
        );
    }

    // ── multiple timestamps ─────────────────────────────────────────────

    public function testOneIdentityMayHoldSeveralTimestampsOutsideTheWindow(): void
    {
        $first  = CronHealState::$now + 60;
        $second = $first + 3600;

        self::assertTrue(wp_schedule_single_event($first, self::HOOK, [7]));
        self::assertTrue(wp_schedule_single_event($second, self::HOOK, [7]));

        self::assertSame(
            [$first, $second],
            CronHealState::timestampsFor(self::HOOK, [7]),
            'WordPress permits the same identity at well-separated timestamps'
        );
    }

    public function testNextScheduledReportsTheEarliestPendingTimestamp(): void
    {
        $late  = CronHealState::$now + 7200;
        $early = CronHealState::$now + 60;

        wp_schedule_single_event($late, self::HOOK, [7]);
        wp_schedule_single_event($early, self::HOOK, [7]);

        self::assertSame($early, wp_next_scheduled(self::HOOK, [7]));
    }

    public function testClearingAnIdentityRemovesEveryPendingTimestamp(): void
    {
        wp_schedule_single_event(CronHealState::$now + 60, self::HOOK, [7]);
        wp_schedule_single_event(CronHealState::$now + 3660, self::HOOK, [7]);

        self::assertSame(2, wp_clear_scheduled_hook(self::HOOK, [7]), 'both occurrences are reported');
        self::assertFalse(wp_next_scheduled(self::HOOK, [7]));
    }

    // ── interoperability with the recurring shim ────────────────────────

    public function testRecurringAndSingleEventsShareOneStoreWithoutErasingEachOther(): void
    {
        wp_schedule_event(CronHealState::$now + 60, 'hourly', self::HOOK);
        wp_schedule_single_event(CronHealState::$now + 4000, self::HOOK);

        $event = CronHealState::$scheduled[CronHealState::eventKey(self::HOOK)];

        self::assertSame('hourly', $event['interval'], 'the recurring interval survives a later single event');
        self::assertSame([CronHealState::$now + 60, CronHealState::$now + 4000], $event['timestamps']);
    }
}
