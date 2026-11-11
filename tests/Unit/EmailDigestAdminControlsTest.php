<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\NotificationRepository;
use BCC\Trust\Core\Services\DigestScheduler;
use BCC\Trust\Core\Services\DigestService;
use BCC\Trust\Core\Support\DigestSettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Admin-controlled email digest (replaces PeepSo's Email Digest add-on).
 *
 * Pins: on/off and the schedule; consent (only opt-ins, nothing inferred);
 * suspended/banned members skipped; paged recipients (no silent cap);
 * RFC 8058 one-click headers; the run record; preview/test never reach
 * anyone but the admin.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmailDigestAdminControlsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/digest-env-stubs.php';
        require_once __DIR__ . '/../Stubs/digest-core-stubs.php';
        $GLOBALS['wpdb']           = new \DigestFakeWpdb();
        $GLOBALS['__dg_suspended'] = [];
    }

    private static function service(): DigestService
    {
        return new DigestService(new NotificationRepository());
    }

    private static function unread(string $msg): object
    {
        return (object) ['not_message' => $msg];
    }

    /** Opted-in member with an email and $n unread rows. */
    private static function member(int $id, int $n = 1): void
    {
        $GLOBALS['__dg_optins'][] = $id;
        $GLOBALS['__dg_users'][$id] = 'm' . $id . '@example.test';
        $GLOBALS['__dg_unread'][$id] = $n > 0
            ? array_map(static fn ($i) => self::unread('note ' . $i), range(1, $n))
            : [];   // range(1, 0) counts DOWN — [1, 0]
    }

    /** @return list<string> */
    private static function recipients(): array
    {
        return array_map(static fn ($m) => $m['to'], $GLOBALS['__dg_mail']);
    }

    // ── settings ────────────────────────────────────────────────────────

    public function testNeverSavedMeansEnabledOnTheInheritedSchedule(): void
    {
        $s = DigestSettings::get();
        self::assertTrue($s['enabled'], 'the digest ran before the page existed');
        self::assertFalse($s['custom_schedule']);
        self::assertSame(DigestSettings::WINDOW_DEFAULT, $s['window_days']);
        self::assertSame(DigestSettings::ITEMS_DEFAULT, $s['max_items']);
    }

    public function testSaveClampsEveryNumberAndMarksTheScheduleChosen(): void
    {
        $s = DigestSettings::save([
            'enabled' => false, 'weekday' => 9, 'hour' => -3, 'window_days' => 999, 'max_items' => 1,
        ]);
        self::assertSame(
            ['enabled' => false, 'custom_schedule' => true, 'weekday' => 6, 'hour' => 0,
             'window_days' => DigestSettings::WINDOW_MAX, 'max_items' => DigestSettings::ITEMS_MIN],
            $s
        );
        self::assertSame($s, DigestSettings::get());
    }

    public function testMaxItemsStaysUnderTheRepositoryCapWithTheSentinel(): void
    {
        // findUnreadSince() clamps to 50; the digest asks for max + 1.
        self::assertLessThan(50, DigestSettings::ITEMS_MAX + 1);
    }

    public function testNextRunIsTheChosenLocalWeekdayAndHour(): void
    {
        $tz  = new \DateTimeZone('America/Chicago');
        // Wed 2026-11-11 10:00 Chicago.
        $now = (new \DateTimeImmutable('2026-11-11 10:00', $tz))->getTimestamp();

        $mon9 = DigestSettings::nextRunTimestamp(1, 9, $now, $tz);
        self::assertSame('2026-11-16 09:00 Mon', (new \DateTimeImmutable('@' . $mon9))->setTimezone($tz)->format('Y-m-d H:i D'));

        // Same weekday, later hour → today.
        $wed15 = DigestSettings::nextRunTimestamp(3, 15, $now, $tz);
        self::assertSame('2026-11-11 15:00', (new \DateTimeImmutable('@' . $wed15))->setTimezone($tz)->format('Y-m-d H:i'));

        // Same weekday, hour already passed (or exactly now) → next week.
        $wed10 = DigestSettings::nextRunTimestamp(3, 10, $now, $tz);
        self::assertSame('2026-11-18 10:00', (new \DateTimeImmutable('@' . $wed10))->setTimezone($tz)->format('Y-m-d H:i'));
    }

    public function testRunHistoryKeepsTheNewestEight(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            DigestSettings::recordRun(['started_at' => $i]);
        }
        $runs = DigestSettings::runs();
        self::assertCount(DigestSettings::MAX_RUNS, $runs);
        self::assertSame(10, $runs[0]['started_at']);
        self::assertSame(3, $runs[7]['started_at']);
    }

    // ── scheduling ──────────────────────────────────────────────────────

    public function testSavingOffUnschedulesAndHidesTheHookFromCronOwnershipAndDrift(): void
    {
        $GLOBALS['__dg_cron'][DigestScheduler::HOOK] = 123;
        DigestSettings::save(['enabled' => false]);
        DigestScheduler::apply();

        self::assertArrayNotHasKey(DigestScheduler::HOOK, $GLOBALS['__dg_cron']);
        self::assertArrayNotHasKey(DigestScheduler::HOOK, DigestScheduler::filterOwnedJobs([DigestScheduler::HOOK => 'bcc_weekly']));
        self::assertArrayNotHasKey(DigestScheduler::HOOK, DigestScheduler::filterExpectedHooks([DigestScheduler::HOOK => []]));

        // The daily ensure() must not resurrect it.
        DigestScheduler::ensure();
        self::assertArrayNotHasKey(DigestScheduler::HOOK, $GLOBALS['__dg_cron']);
    }

    public function testSavingOnSchedulesTheChosenSlotAndEnsureRestoresItThere(): void
    {
        DigestSettings::save(['enabled' => true, 'weekday' => 1, 'hour' => 9]);
        DigestScheduler::apply();
        $slot = $GLOBALS['__dg_cron'][DigestScheduler::HOOK] ?? null;
        self::assertIsInt($slot);
        self::assertSame(DigestSettings::nextRunTimestamp(1, 9), $slot);

        unset($GLOBALS['__dg_cron'][DigestScheduler::HOOK]);
        DigestScheduler::ensure();
        self::assertSame($slot, $GLOBALS['__dg_cron'][DigestScheduler::HOOK] ?? null, 're-added at the chosen time, not time()');
    }

    public function testNeverSavedLeavesCronServiceInChargeOfTheHook(): void
    {
        $jobs = [DigestScheduler::HOOK => 'bcc_weekly'];
        self::assertSame($jobs, DigestScheduler::filterOwnedJobs($jobs));
        self::assertSame([DigestScheduler::HOOK => []], DigestScheduler::filterExpectedHooks([DigestScheduler::HOOK => []]));

        DigestScheduler::ensure();
        self::assertSame([], $GLOBALS['__dg_cron'], 'ensure() acts only after a save');
    }

    public function testRealignPutsADriftedSlotBackOnTheChosenLocalHour(): void
    {
        DigestSettings::save(['enabled' => true, 'weekday' => 1, 'hour' => 9]);
        $want = DigestSettings::nextRunTimestamp(1, 9);

        // WP-cron's "last + 7 days" across a DST change: an hour off.
        $GLOBALS['__dg_cron'][DigestScheduler::HOOK] = $want + 3600;
        DigestScheduler::realign();
        self::assertSame($want, $GLOBALS['__dg_cron'][DigestScheduler::HOOK]);

        // Already right: untouched.
        DigestScheduler::realign();
        self::assertSame($want, $GLOBALS['__dg_cron'][DigestScheduler::HOOK]);
    }

    public function testRealignDoesNothingBeforeASaveOrWhileOff(): void
    {
        $GLOBALS['__dg_cron'][DigestScheduler::HOOK] = 12345;
        DigestScheduler::realign();
        self::assertSame(12345, $GLOBALS['__dg_cron'][DigestScheduler::HOOK], 'never saved: CronService owns it');

        DigestSettings::save(['enabled' => false]);
        DigestScheduler::realign();
        self::assertSame(12345, $GLOBALS['__dg_cron'][DigestScheduler::HOOK], 'off: realign does not schedule');
    }

    public function testTheOptInCountIsNullNotZeroWhenTheReadFails(): void
    {
        self::member(1);
        self::member(2);
        self::assertSame(2, \BCC\Trust\Core\Support\NotificationPrefs::countEmailDigestOptIns());

        $GLOBALS['__dg_count_fail'] = true;
        self::assertNull(\BCC\Trust\Core\Support\NotificationPrefs::countEmailDigestOptIns());
    }

    // ── sending ─────────────────────────────────────────────────────────

    public function testACronRunWhileSwitchedOffSendsNothingAndRecordsNothing(): void
    {
        self::member(1);
        DigestSettings::save(['enabled' => false]);

        self::assertSame(0, self::service()->sendWeeklyDigest(DigestService::TRIGGER_CRON));
        self::assertSame([], $GLOBALS['__dg_mail']);
        self::assertSame([], DigestSettings::runs());
    }

    public function testOnlyOptedInMembersInGoodStandingWithUnreadNotificationsAreMailed(): void
    {
        self::member(1);          // gets it
        self::member(2);          // suspended
        self::member(3);          // banned
        self::member(4, 0);       // nothing unread
        self::member(5);          // no email
        $GLOBALS['__dg_users'][5]  = '';
        $GLOBALS['__dg_suspended'] = [2];
        $GLOBALS['__dg_banned']    = [3];
        $GLOBALS['__dg_users'][99] = 'not-opted-in@example.test';   // never opted in
        $GLOBALS['__dg_unread'][99] = [self::unread('x')];

        $sent = self::service()->sendWeeklyDigest();

        self::assertSame(1, $sent);
        self::assertSame(['m1@example.test'], self::recipients());

        $run = DigestSettings::runs()[0];
        self::assertSame(
            ['trigger' => 'cron', 'opted_in' => 5, 'excluded' => 2, 'sent' => 1, 'no_unread' => 1, 'no_email' => 1, 'failed' => 0],
            array_intersect_key($run, array_flip(['trigger', 'opted_in', 'excluded', 'sent', 'no_unread', 'no_email', 'failed']))
        );
    }

    public function testRecipientsArePagedPastOneBatchNotSilentlyCapped(): void
    {
        for ($id = 1; $id <= 1203; $id++) {
            self::member($id);
        }

        self::assertSame(1203, self::service()->sendWeeklyDigest());
        self::assertSame(1203, DigestSettings::runs()[0]['opted_in']);
    }

    public function testEveryDigestCarriesOneClickUnsubscribeHeadersMatchingTheBodyLink(): void
    {
        self::member(1);
        self::service()->sendWeeklyDigest();

        $mail = $GLOBALS['__dg_mail'][0];
        self::assertContains('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $mail['headers']);
        $list = array_values(array_filter($mail['headers'], static fn ($h) => str_starts_with($h, 'List-Unsubscribe: <')));
        self::assertCount(1, $list);
        $url = substr($list[0], strlen('List-Unsubscribe: <'), -1);
        self::assertStringContainsString('digest/unsubscribe?uid=1', $url);
        self::assertStringContainsString($url, $mail['body'], 'header and body link are the same signed URL');
    }

    public function testAFailedSendIsCountedNotSwallowed(): void
    {
        self::member(1);
        $GLOBALS['__dg_mail_ok'] = false;

        self::assertSame(0, self::service()->sendWeeklyDigest(DigestService::TRIGGER_MANUAL));
        $run = DigestSettings::runs()[0];
        self::assertSame(1, $run['failed']);
        self::assertSame('manual', $run['trigger']);
    }

    public function testMaxItemsCapsTheEmailAndNotesTheOverflow(): void
    {
        DigestSettings::save(['max_items' => 5]);
        self::member(1, 9);
        self::service()->sendWeeklyDigest(DigestService::TRIGGER_MANUAL);

        $mail = $GLOBALS['__dg_mail'][0];
        self::assertStringContainsString('— 5 notifications', $mail['subject']);
        self::assertSame(5, substr_count($mail['body'], "\n- note "));
        self::assertStringContainsString('capped at 5', $mail['body']);
    }

    // ── preview / test ──────────────────────────────────────────────────

    public function testPreviewSendsNothing(): void
    {
        self::member(1, 2);
        $p = self::service()->previewFor(1);

        self::assertNotNull($p);
        self::assertSame(2, $p['count']);
        self::assertSame([], $GLOBALS['__dg_mail']);
    }

    public function testTheTestEmailGoesOnlyToTheAdminEvenWithNothingUnread(): void
    {
        self::member(2);                                   // another member: must not be mailed
        $GLOBALS['__dg_users'][7] = 'admin@example.test';  // the admin, not opted in, nothing unread

        self::assertSame(DigestService::RESULT_SENT, self::service()->sendTestTo(7));
        self::assertSame(['admin@example.test'], self::recipients());
        self::assertStringStartsWith('[TEST] ', $GLOBALS['__dg_mail'][0]['subject']);
        self::assertSame([], DigestSettings::runs(), 'a test is not a run');
    }
}
