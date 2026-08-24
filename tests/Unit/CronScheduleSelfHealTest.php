<?php

declare(strict_types=1);

// ── Collaborators CronService calls by fully-qualified name ─────────────────
//
// Guarded, because several other suites declare their own doubles for these
// and PHPUnit shares one process. Whichever file loads first wins; the two
// lock-specific tests below detect that and skip rather than assert against
// somebody else's double.
namespace BCC\Core\DB {

    use BCC\Trust\Tests\Support\CronHealState;

    if (!class_exists(__NAMESPACE__ . '\\AdvisoryLock', false)) {
        final class AdvisoryLock
        {
            public static function acquire(string $key, int $timeout = 0): bool
            {
                return CronHealState::$lockGranted;
            }

            public static function release(string $key): void
            {
                CronHealState::$lockReleases++;
            }
        }
    }
}

namespace BCC\Core\Log {

    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
            }

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
            }
        }
    }
}

namespace BCC\Trust\Core\Security {

    if (!class_exists(__NAMESPACE__ . '\\AuditLogger', false)) {
        final class AuditLogger
        {
            /** @param array<string, mixed> $meta */
            public static function log(string $action, int $targetId = 0, array $meta = [], string $actor = ''): void
            {
            }
        }
    }
}

// ── Tests ───────────────────────────────────────────────────────────────────
namespace BCC\Trust\Tests\Unit {

    use BCC\Trust\Core\Services\CronService;
    use BCC\Trust\Tests\Support\CronHealState;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;

    if (!defined('BCC_TRUST_VERSION')) {
        define('BCC_TRUST_VERSION', '9.9.9-test');
    }

    /**
     * `CronService::maybeReschedule()` must repair a broken schedule, not just
     * react to a version bump.
     *
     * ## The incident this encodes
     *
     * bcc-core hard-requires the GMP extension and returns early without it.
     * When a Local PHP version change dropped `extension=php_gmp.dll`, the
     * whole plugin stack went inert: its `cron_schedules` filter never
     * registered, so WP-Cron could not reschedule events whose interval slug
     * it no longer recognised. Fifteen recurring events fell out of the cron
     * array within fifteen seconds.
     *
     * Restoring the extension did not bring them back. `bcc_trust_cron_version`
     * still equalled `BCC_TRUST_VERSION`, so the old version-only gate skipped
     * `scheduleAll()` entirely, and two events — `bcc_trust_process_recalculations`
     * and `bcc_trust_weekly_slow_ring_scan` — stayed unscheduled indefinitely
     * because nothing else registers them.
     *
     * The second of those was invisible to the drift detector as well: it was
     * filed under `cleanup_only` in `includes/cron-hooks.php` while
     * `scheduleAll()` scheduled it weekly.
     */
    #[CoversClass(CronService::class)]
    final class CronScheduleSelfHealTest extends TestCase
    {
        protected function setUp(): void
        {
            CronHealState::reset(); // also flips $active on
        }

        /**
         * Hand the namespace back.
         *
         * The shims live in BCC\Trust\Core\Services, which every other service
         * in this plugin shares, so leaving them armed would feed this suite's
         * recorded state to unrelated tests running later in the same process.
         */
        protected function tearDown(): void
        {
            CronHealState::$active = false;
        }

        private function markVersionCurrent(): void
        {
            CronHealState::$options['bcc_trust_cron_version'] = BCC_TRUST_VERSION;
        }

        private function markHealthCheckedNow(): void
        {
            CronHealState::$options['bcc_trust_cron_health_checked_at'] = CronHealState::$now;
        }

        /**
         * Only our AdvisoryLock double answers to CronHealState::$lockGranted.
         * Several suites declare their own, all returning true unconditionally,
         * and whichever file PHPUnit loads first wins the name. Assert against
         * someone else's double and the result is meaningless, so check first.
         *
         * The load-bearing no-duplication guarantee does not depend on this:
         * testRepeatedInvocationsDoNotDuplicateEvents covers it through
         * wp_next_scheduled(), which holds whichever double is active.
         */
        private function requireOurAdvisoryLockDouble(): void
        {
            $granted                    = CronHealState::$lockGranted;
            CronHealState::$lockGranted = false;
            $honoursFlag                = \BCC\Core\DB\AdvisoryLock::acquire('bcc_probe', 0) === false;
            CronHealState::$lockGranted = $granted;

            if (!$honoursFlag) {
                self::markTestSkipped(
                    'another suite\'s AdvisoryLock double won the class name; lock behaviour is not observable here'
                );
            }
        }

        // ── 1. same version + missing hook → repaired ────────────────────

        public function testSameVersionWithAMissingOwnedHookRepairsIt(): void
        {
            $this->markVersionCurrent();
            CronHealState::scheduleEverything();
            unset(CronHealState::$scheduled['bcc_trust_process_recalculations']);

            CronService::maybeReschedule();

            self::assertArrayHasKey(
                'bcc_trust_process_recalculations',
                CronHealState::$scheduled,
                'a missing owned hook must be restored even when the version has not changed'
            );
            self::assertSame(
                'bcc_five_minutes',
                CronHealState::$scheduled['bcc_trust_process_recalculations']['interval'],
                'restored with its declared interval'
            );
        }

        public function testBothHooksLostInTheRealIncidentAreRestored(): void
        {
            $this->markVersionCurrent();
            CronHealState::scheduleEverything();
            unset(
                CronHealState::$scheduled['bcc_trust_process_recalculations'],
                CronHealState::$scheduled['bcc_trust_weekly_slow_ring_scan']
            );

            CronService::maybeReschedule();

            self::assertSame([], CronService::missingJobs(), 'nothing owned should remain unscheduled');
            self::assertSame(
                'bcc_weekly',
                CronHealState::$scheduled['bcc_trust_weekly_slow_ring_scan']['interval']
            );
        }

        // ── 2. same version + healthy → untouched ────────────────────────

        public function testHealthyScheduleIsLeftAloneAndNothingIsRescheduled(): void
        {
            $this->markVersionCurrent();
            CronHealState::scheduleEverything();
            $before = CronHealState::$scheduled;

            CronService::maybeReschedule();

            self::assertSame([], CronHealState::$scheduleCalls, 'a healthy schedule must trigger no wp_schedule_event()');
            self::assertSame([], CronHealState::$cleared, 'a healthy schedule must clear nothing');
            self::assertEquals($before, CronHealState::$scheduled, 'schedule must be byte-for-byte unchanged');
        }

        // ── 3. version change still schedules ────────────────────────────

        public function testVersionChangeSchedulesEverythingAndStampsTheVersion(): void
        {
            CronHealState::$options['bcc_trust_cron_version'] = '0.0.1-old';

            CronService::maybeReschedule();

            self::assertSame([], CronService::missingJobs(), 'every owned hook scheduled after a version change');
            self::assertSame(
                BCC_TRUST_VERSION,
                CronHealState::$options['bcc_trust_cron_version'],
                'the version stamp must advance'
            );
        }

        public function testFirstInstallWithNoVersionOptionSchedulesEverything(): void
        {
            CronService::maybeReschedule();

            self::assertSame([], CronService::missingJobs());
            self::assertSame(BCC_TRUST_VERSION, CronHealState::$options['bcc_trust_cron_version']);
        }

        // ── 4. canonical inventory ───────────────────────────────────────

        public function testTheWeeklySlowRingHookIsInTheCanonicalInventoryAsRecurring(): void
        {
            /** @var array{recurring: array<string, array{interval:string, description:string}>, cleanup_only: list<string>} $inv */
            $inv = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';

            self::assertArrayHasKey(
                'bcc_trust_weekly_slow_ring_scan',
                $inv['recurring'],
                'scheduleAll() schedules it weekly, so the drift detector must expect it'
            );
            self::assertSame('bcc_weekly', $inv['recurring']['bcc_trust_weekly_slow_ring_scan']['interval']);
            self::assertNotContains(
                'bcc_trust_weekly_slow_ring_scan',
                $inv['cleanup_only'],
                'a recurring hook must not also be filed as a fire-once drain'
            );
        }

        /** The drift that made the incident invisible: two lists, one truth. */
        public function testEveryOwnedJobIsInTheCanonicalInventoryWithTheSameInterval(): void
        {
            /** @var array{recurring: array<string, array{interval:string, description:string}>, cleanup_only: list<string>} $inv */
            $inv        = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';
            $mismatched = [];

            foreach (CronService::ownedJobs() as $hook => $interval) {
                if (!isset($inv['recurring'][$hook])) {
                    $mismatched[] = "{$hook}: scheduled by CronService but absent from cron-hooks.php recurring";
                    continue;
                }
                if ($inv['recurring'][$hook]['interval'] !== $interval) {
                    $mismatched[] = sprintf(
                        '%s: CronService says %s, cron-hooks.php says %s',
                        $hook,
                        $interval,
                        $inv['recurring'][$hook]['interval']
                    );
                }
            }

            self::assertSame([], $mismatched, implode("\n", $mismatched));
        }

        // ── 5. no duplicates under repeated / competing invocations ──────

        public function testRepeatedInvocationsDoNotDuplicateEvents(): void
        {
            CronHealState::$options['bcc_trust_cron_version'] = '0.0.1-old';
            CronService::maybeReschedule();
            $afterFirst = count(CronHealState::$scheduleCalls);

            // Same request cycle, called again and again.
            CronService::maybeReschedule();
            CronService::maybeReschedule();

            self::assertSame(
                $afterFirst,
                count(CronHealState::$scheduleCalls),
                'subsequent calls must not re-schedule anything'
            );
            foreach (CronService::ownedJobs() as $hook => $_) {
                $calls = array_filter(CronHealState::$scheduleCalls, static fn ($c) => $c['hook'] === $hook);
                self::assertLessThanOrEqual(1, count($calls), "{$hook} scheduled more than once");
            }
        }

        /**
         * A concurrent request that loses the advisory lock must not schedule.
         *
         * wp_next_scheduled() guards each individual event too, so a duplicate
         * is impossible either way — but the loser should do no work at all.
         */
        public function testALoserOfTheAdvisoryLockSchedulesNothing(): void
        {
            $this->requireOurAdvisoryLockDouble();
            $this->markVersionCurrent();
            CronHealState::scheduleEverything();
            unset(CronHealState::$scheduled['bcc_trust_process_recalculations']);
            CronHealState::$lockGranted = false;

            CronService::maybeReschedule();

            self::assertSame([], CronHealState::$scheduleCalls, 'the lock loser must not schedule');
            self::assertArrayNotHasKey('bcc_trust_process_recalculations', CronHealState::$scheduled);
        }

        public function testTheLockIsReleasedAfterARepair(): void
        {
            $this->requireOurAdvisoryLockDouble();
            $this->markVersionCurrent();
            CronHealState::scheduleEverything();
            unset(CronHealState::$scheduled['bcc_trust_feed_hot_warm']);

            CronService::maybeReschedule();

            self::assertSame(1, CronHealState::$lockReleases, 'the advisory lock must be released exactly once');
        }

        // ── 6. intentionally disabled hooks are not resurrected ──────────

        public function testAnOptedOutHookIsNeitherScheduledNorReportedMissing(): void
        {
            $this->markVersionCurrent();
            CronHealState::$disabled = ['bcc_trust_weekly_digest'];
            CronHealState::scheduleEverything(); // ownedJobs() already excludes it
            unset(CronHealState::$scheduled['bcc_trust_process_recalculations']);

            CronService::maybeReschedule();

            self::assertArrayNotHasKey(
                'bcc_trust_weekly_digest',
                CronHealState::$scheduled,
                'a hook removed from the owned set must never be resurrected'
            );
            self::assertArrayNotHasKey('bcc_trust_weekly_digest', CronService::missingJobs());
            self::assertArrayHasKey(
                'bcc_trust_process_recalculations',
                CronHealState::$scheduled,
                'opting one hook out must not stop the others being repaired'
            );
        }

        // ── throttling: the check must not run on every request ──────────

        public function testTheDriftCheckIsThrottled(): void
        {
            $this->markVersionCurrent();
            $this->markHealthCheckedNow();
            CronHealState::scheduleEverything();
            unset(CronHealState::$scheduled['bcc_trust_process_recalculations']);

            CronService::maybeReschedule();

            self::assertSame(
                [],
                CronHealState::$scheduleCalls,
                'within the throttle window the drift scan must not run'
            );
        }

        public function testTheDriftCheckRunsOnceTheThrottleWindowHasPassed(): void
        {
            $this->markVersionCurrent();
            CronHealState::$options['bcc_trust_cron_health_checked_at'] = CronHealState::$now - (2 * 3600);
            CronHealState::scheduleEverything();
            unset(CronHealState::$scheduled['bcc_trust_process_recalculations']);

            CronService::maybeReschedule();

            self::assertArrayHasKey('bcc_trust_process_recalculations', CronHealState::$scheduled);
        }

        public function testTheThrottleStampIsWrittenEvenWhenNothingIsWrong(): void
        {
            $this->markVersionCurrent();
            CronHealState::scheduleEverything();

            CronService::maybeReschedule();

            self::assertSame(
                CronHealState::$now,
                CronHealState::$options['bcc_trust_cron_health_checked_at'] ?? null,
                'the stamp must be written before the scan so a burst collapses to one check'
            );
        }

        /** A clock that jumped backwards must not park the check forever. */
        public function testAFutureStampDoesNotDisableTheCheck(): void
        {
            $this->markVersionCurrent();
            CronHealState::$options['bcc_trust_cron_health_checked_at'] = CronHealState::$now + 86_400;
            CronHealState::scheduleEverything();
            unset(CronHealState::$scheduled['bcc_trust_process_recalculations']);

            CronService::maybeReschedule();

            self::assertArrayHasKey('bcc_trust_process_recalculations', CronHealState::$scheduled);
        }

        // ── the version gate itself is not weakened ──────────────────────

        public function testVersionChangeBypassesTheThrottle(): void
        {
            CronHealState::$options['bcc_trust_cron_version']          = '0.0.1-old';
            CronHealState::$options['bcc_trust_cron_health_checked_at'] = CronHealState::$now;
            CronHealState::scheduleEverything();
            unset(CronHealState::$scheduled['bcc_trust_process_recalculations']);

            CronService::maybeReschedule();

            self::assertArrayHasKey(
                'bcc_trust_process_recalculations',
                CronHealState::$scheduled,
                'a version change must repair immediately regardless of the throttle'
            );
        }

        public function testRetiredHooksAreStillClearedOnAVersionChange(): void
        {
            CronHealState::$options['bcc_trust_cron_version'] = '0.0.1-old';
            CronHealState::$scheduled['bcc_trust_hourly_graph_update'] = ['interval' => 'hourly', 'next' => 1];

            CronService::maybeReschedule();

            self::assertContains('bcc_trust_hourly_graph_update', CronHealState::$cleared);
            self::assertArrayNotHasKey('bcc_trust_hourly_graph_update', CronHealState::$scheduled);
        }
    }
}
