<?php

declare(strict_types=1);

namespace {

    /** Records every add_action() and every wp_schedule_event() call. */
    if (!class_exists('BccCronIntegrationHooks', false)) {
        final class BccCronIntegrationHooks
        {
            /** @var list<array{hook: string, id: string, priority: int, accepted: int}> */
            public static array $actions = [];

            /**
             * Every wp_schedule_event() invocation.
             *
             * ⚠ Counting CALLS, not resulting rows, is the only way to see a
             * missing idempotency guard. WordPress keys the cron array by
             * (timestamp, hook, args), so three unguarded schedules within the
             * same second overwrite ONE slot and leave a single event behind —
             * faithful to core, and enough to make a "just count the events"
             * assertion pass against code with no guard at all. A mutation
             * control caught exactly that.
             *
             * @var list<array{timestamp: int, recurrence: string, hook: string}>
             */
            public static array $scheduleCalls = [];

            public static function reset(): void
            {
                self::$actions       = [];
                self::$scheduleCalls = [];
            }
        }
    }

    /*
     * WordPress' real cron representation.
     *
     * ⚠ These are NOT method doubles. WordPress keeps scheduled events in the
     * `cron` OPTION, shaped
     *
     *     [ timestamp ][ hook ][ md5(serialize(args)) ] =
     *         ['schedule' => <recurrence>, 'args' => [...], 'interval' => <seconds>]
     *
     * and `wp_next_scheduled()` is a lookup over exactly that. Reproducing the
     * structure — rather than stubbing AsyncDispatcher — is what lets this test
     * inspect the ACTUAL scheduled-event state: the assertions below read the
     * option, not a spy. The class under test and `AsyncDispatcher` are both
     * real production code here.
     */
    if (!function_exists('add_action')) {
        /** @param mixed $callback */
        function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool
        {
            $id = is_array($callback) && count($callback) === 2
                ? (is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0]) . '::' . (string) $callback[1]
                : (is_string($callback) ? $callback : 'closure#' . spl_object_id($callback));

            BccCronIntegrationHooks::$actions[] = [
                'hook'     => $hook,
                'id'       => $id,
                'priority' => $priority,
                'accepted' => $acceptedArgs,
            ];

            return true;
        }
    }

    if (!function_exists('wp_get_schedules')) {
        /** @return array<string, array{interval: int, display: string}> */
        function wp_get_schedules(): array
        {
            return [
                'bcc_one_minute'   => ['interval' => 60, 'display' => 'Every minute'],
                'bcc_five_minutes' => ['interval' => 300, 'display' => 'Every five minutes'],
                'hourly'           => ['interval' => 3600, 'display' => 'Hourly'],
                'twicedaily'       => ['interval' => 43200, 'display' => 'Twice daily'],
                'daily'            => ['interval' => 86400, 'display' => 'Daily'],
            ];
        }
    }

    if (!function_exists('_get_cron_array')) {
        /** @return array<int, array<string, array<string, array{schedule: string|false, args: array<int, mixed>, interval?: int}>>> */
        function _get_cron_array(): array
        {
            $cron = get_option('cron', []);

            return is_array($cron) ? $cron : [];
        }
    }

    if (!function_exists('wp_next_scheduled')) {
        /**
         * @param array<int, mixed> $args
         * @return int|false
         */
        function wp_next_scheduled(string $hook, array $args = [])
        {
            $key  = md5(serialize($args));
            $best = false;

            foreach (_get_cron_array() as $timestamp => $hooks) {
                if (isset($hooks[$hook][$key]) && ($best === false || $timestamp < $best)) {
                    $best = (int) $timestamp;
                }
            }

            return $best;
        }
    }

    if (!function_exists('wp_schedule_event')) {
        /**
         * Core does NOT deduplicate recurring events — callers guard with
         * wp_next_scheduled(), and AsyncDispatcher::registerRecurring() is
         * that guard. Deduplicating here would make the idempotency
         * assertions pass whether or not the guard existed.
         *
         * @param array<int, mixed> $args
         */
        function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
        {
            BccCronIntegrationHooks::$scheduleCalls[] = [
                'timestamp'  => $timestamp,
                'recurrence' => $recurrence,
                'hook'       => $hook,
            ];

            $schedules = wp_get_schedules();
            if (!isset($schedules[$recurrence])) {
                return false; // core refuses an unregistered recurrence
            }

            $cron = _get_cron_array();
            $cron[$timestamp][$hook][md5(serialize($args))] = [
                'schedule' => $recurrence,
                'args'     => $args,
                'interval' => $schedules[$recurrence]['interval'],
            ];
            ksort($cron);
            update_option('cron', $cron);

            return true;
        }
    }

    if (!function_exists('wp_clear_scheduled_hook')) {
        /** @param array<int, mixed> $args */
        function wp_clear_scheduled_hook(string $hook, array $args = []): int
        {
            $key     = md5(serialize($args));
            $cron    = _get_cron_array();
            $cleared = 0;

            foreach ($cron as $timestamp => $hooks) {
                if (isset($hooks[$hook][$key])) {
                    unset($cron[$timestamp][$hook][$key]);
                    $cleared++;
                    if ($cron[$timestamp][$hook] === []) {
                        unset($cron[$timestamp][$hook]);
                    }
                    if ($cron[$timestamp] === []) {
                        unset($cron[$timestamp]);
                    }
                }
            }
            update_option('cron', $cron);

            return $cleared;
        }
    }
}

namespace BCC\Trust\Tests\Integration {

    use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
    use BCC\Trust\Onchain\Workers\DiscoveryRunMaintenance;
    use BccCronIntegrationHooks;
    use PHPUnit\Framework\TestCase;

    /**
     * PR 7A.1 — the maintenance sweep is actually SCHEDULED.
     *
     * ── WHY THIS IS NOT A UNIT TEST ─────────────────────────────────────
     * The unit test proves `register()` behaves correctly. This one proves
     * the *state* that behaviour is supposed to produce actually exists in
     * WordPress' own representation — the `cron` option — and that arming
     * the sweep writes nothing to the ledger in a real MySQL. PR 7A passed
     * a full green suite while shipping a hook that was never scheduled,
     * precisely because nothing inspected that state.
     *
     * `AsyncDispatcher` is the real bcc-core class here (the integration
     * bootstrap autoloads BCC\Core\* from the adjacent checkout), so the
     * idempotency guard under test is production code.
     */
    final class DiscoveryMaintenanceCronIntegrationTest extends TestCase
    {
        private const HOOK = 'bcc_discovery_run_maintenance';

        protected function setUp(): void
        {
            parent::setUp();
            BccCronIntegrationHooks::reset();
            \update_option('cron', []);
            $GLOBALS['wpdb']->query('DELETE FROM `' . DiscoveryRunRepository::table() . '`');
        }

        protected function tearDown(): void
        {
            \update_option('cron', []);
            $GLOBALS['wpdb']->query('DELETE FROM `' . DiscoveryRunRepository::table() . '`');
            parent::tearDown();
        }

        /** @return array{schedule: string|false, args: array<int, mixed>, interval?: int}|null */
        private function scheduledEvent(): ?array
        {
            foreach (\_get_cron_array() as $hooks) {
                if (isset($hooks[self::HOOK][md5(serialize([]))])) {
                    return $hooks[self::HOOK][md5(serialize([]))];
                }
            }

            return null;
        }

        public function testTheEventIsAbsentBeforeRegistration(): void
        {
            self::assertNull($this->scheduledEvent(), 'precondition: the PR 7A state');
            self::assertFalse(\wp_next_scheduled(self::HOOK));
        }

        public function testRegistrationWritesARealEventIntoTheCronOption(): void
        {
            DiscoveryRunMaintenance::register();

            $event = $this->scheduledEvent();
            self::assertNotNull($event, 'the event must exist in WordPress\' own cron array');
            self::assertSame([], $event['args']);

            $next = \wp_next_scheduled(self::HOOK);
            self::assertIsInt($next);
            self::assertGreaterThan(0, $next);
        }

        public function testTheRecurrenceIsBccFiveMinutes(): void
        {
            DiscoveryRunMaintenance::register();

            $event = $this->scheduledEvent();
            self::assertNotNull($event);
            self::assertSame('bcc_five_minutes', $event['schedule']);
            self::assertSame(300, $event['interval'] ?? null, 'five minutes, in seconds, from wp_get_schedules()');
        }

        public function testRepeatedRegistrationLeavesExactlyOneEvent(): void
        {
            DiscoveryRunMaintenance::register();
            DiscoveryRunMaintenance::register();
            DiscoveryRunMaintenance::register();

            $occurrences = 0;
            foreach (\_get_cron_array() as $hooks) {
                foreach ($hooks[self::HOOK] ?? [] as $_) {
                    $occurrences++;
                }
            }

            self::assertSame(1, $occurrences, 'exactly one pending event');

            // ⚠ The event count ALONE is not enough. Core keys the cron array
            // by (timestamp, hook, args), so three unguarded schedules inside
            // one second collapse to a single slot and the count above stays 1
            // even with no guard at all — a mutation control proved it. The
            // guard is only observable in the CALL count.
            self::assertCount(
                1,
                BccCronIntegrationHooks::$scheduleCalls,
                'registerRecurring must short-circuit before calling wp_schedule_event again'
            );
            self::assertSame(self::HOOK, BccCronIntegrationHooks::$scheduleCalls[0]['hook']);
        }

        public function testRepeatedRegistrationBindsOneSubscriber(): void
        {
            DiscoveryRunMaintenance::register();
            DiscoveryRunMaintenance::register();

            $ids = [];
            foreach (BccCronIntegrationHooks::$actions as $a) {
                if ($a['hook'] === self::HOOK) {
                    $ids[$a['id']] = true;
                }
            }

            self::assertSame(
                [DiscoveryRunMaintenance::class . '::handleSweep'],
                array_keys($ids)
            );
        }

        public function testEveryDeclaredRecurringHookCanBeScheduled(): void
        {
            /** @var array{recurring: array<string, array{interval: string}>} $lists */
            $lists = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';

            self::assertArrayHasKey(self::HOOK, $lists['recurring']);

            $interval = $lists['recurring'][self::HOOK]['interval'];
            self::assertArrayHasKey(
                $interval,
                \wp_get_schedules(),
                'a declared hook whose interval is unregistered can never be scheduled'
            );

            DiscoveryRunMaintenance::register();

            // The production symptom: declared hooks that are not scheduled
            // are what the drift detector reports as MISSING.
            self::assertNotFalse(
                \wp_next_scheduled(self::HOOK),
                'the declared hook must no longer be missing'
            );
        }

        public function testDeactivationClearsTheEvent(): void
        {
            DiscoveryRunMaintenance::register();
            self::assertNotFalse(\wp_next_scheduled(self::HOOK));

            /** @var array{recurring: array<string, array{interval: string}>, cleanup_only: list<string>} $lists */
            $lists = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';

            // Exactly what bcc_trust_deactivate() does.
            foreach (array_merge(array_keys($lists['recurring']), $lists['cleanup_only']) as $hook) {
                \wp_clear_scheduled_hook((string) $hook);
            }

            self::assertFalse(
                \wp_next_scheduled(self::HOOK),
                'deactivation must clear the maintenance event via the declared list'
            );
            self::assertNull($this->scheduledEvent());
        }

        /**
         * Arming the sweep is not an act of discovery.
         *
         * Asserted against a real table: with zero runs, registration must not
         * create one, and must not touch the executor.
         */
        public function testRegistrationWritesNothingToTheLedger(): void
        {
            $table  = DiscoveryRunRepository::table();
            $before = (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM `{$table}`");
            self::assertSame(0, $before);

            DiscoveryRunMaintenance::register();

            $after = (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM `{$table}`");
            self::assertSame(0, $after, 'registration must not insert a discovery run');

            self::assertFalse(
                \wp_next_scheduled('bcc_discovery_run_execute'),
                'the executor is a one-shot and must never be scheduled'
            );

            $hooks = array_column(BccCronIntegrationHooks::$actions, 'hook');
            self::assertSame([self::HOOK], array_values(array_unique($hooks)));
        }
    }
}
