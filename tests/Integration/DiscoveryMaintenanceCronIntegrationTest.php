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

    /*
     * The migration's return vocabulary.
     *
     * ⚠ Defined here rather than by requiring includes/database/migration-runner.php,
     * which would register every migration in the plugin and run none of them —
     * side effects this test has no business taking on.
     *
     * ⚠ That means the literals are duplicated, and a rename in the runner
     * would NOT break these assertions on its own: the test would compare its
     * own copy against itself and pass. `testTheMigrationVocabularyMatchesTheRunner`
     * below closes that hole by reading the runner source.
     */
    if (!defined('BCC_TRUST_MIGRATION_COMPLETE')) {
        define('BCC_TRUST_MIGRATION_COMPLETE', 'complete');
    }
    if (!defined('BCC_TRUST_MIGRATION_INCOMPLETE')) {
        define('BCC_TRUST_MIGRATION_INCOMPLETE', 'incomplete');
    }

    /*
     * ⚠ NO wp_schedule_single_event() SHIM HERE.
     *
     * tests/Integration/bootstrap.php already defines one for bcc-core's
     * AsyncDispatcher fallback, so a guarded definition at this point would
     * never load — and the first version of this file learned that the hard
     * way, by asserting a precondition that silently never held. The single
     * event the executor-queue case needs is written straight into the cron
     * option by DiscoveryMaintenanceCronIntegrationTest::queueSingleEvent().
     */

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
    use BccCronIntegrationHooks;
    use PHPUnit\Framework\TestCase;

    /**
     * S6 — the maintenance sweep is RETIRED, against the real cron
     * representation and a real MySQL.
     *
     * ── WHAT THIS FILE USED TO PROVE, AND WHY IT INVERTED ───────────────
     * PR 7A.1 wrote it to prove the sweep was actually SCHEDULED: PR 7A had
     * passed a full green suite while shipping a hook bound to an event
     * nothing created, precisely because nothing inspected that state. Every
     * case called `DiscoveryRunMaintenance::register()`.
     *
     * S6 deletes `register()`, so those cases cannot even load. What replaces
     * them is the same discipline pointed the other way: assert the STATE, in
     * the `cron` option itself, rather than trusting that a call was made.
     *
     * ── WHY THIS IS NOT A UNIT TEST ─────────────────────────────────────
     * The unit test reads source and asserts the registration sites are gone.
     * This one proves the migration removes a REAL event from the real cron
     * option, leaves sibling hooks and the executor queue untouched, and
     * writes nothing to the run ledger in a real database.
     *
     * The cron functions in the prologue are NOT method doubles — they are a
     * faithful reimplementation of the core `(timestamp -> hook ->
     * md5(args))` option shape, so the assertions below read actual
     * scheduled-event state. The migration under test is production code.
     */
    final class DiscoveryMaintenanceCronIntegrationTest extends TestCase
    {
        private const HOOK          = 'bcc_discovery_run_maintenance';
        private const EXECUTOR_HOOK = 'bcc_discovery_run_execute';

        protected function setUp(): void
        {
            parent::setUp();
            BccCronIntegrationHooks::reset();
            \update_option('cron', []);
            $GLOBALS['wpdb']->query('DELETE FROM `' . DiscoveryRunRepository::table() . '`');

            require_once dirname(__DIR__, 2) . '/includes/database/unschedule-discovery-maintenance.php';
        }

        protected function tearDown(): void
        {
            \update_option('cron', []);
            $GLOBALS['wpdb']->query('DELETE FROM `' . DiscoveryRunRepository::table() . '`');
            parent::tearDown();
        }

        /**
         * Put a SINGLE event into the cron option directly.
         *
         * ⚠ Deliberately not `wp_schedule_single_event()`. The integration
         * bootstrap already defines that function for bcc-core's
         * AsyncDispatcher fallback, so a guarded definition in this file's
         * prologue would never load and the bootstrap's version does not
         * write the `(timestamp → hook → md5(args))` shape these assertions
         * read. Building the row here makes the precondition independent of
         * which shim won, which is the whole point of a precondition.
         *
         * Core stores `'schedule' => false` and no `interval` for a single
         * event, and keys it by its ARGS — which is exactly why the executor
         * queue survives a no-argument `wp_clear_scheduled_hook()`.
         *
         * @param array<int, mixed> $args
         */
        private static function queueSingleEvent(int $timestamp, string $hook, array $args): void
        {
            $cron = \_get_cron_array();
            $cron[$timestamp][$hook][md5(serialize($args))] = [
                'schedule' => false,
                'args'     => $args,
            ];
            ksort($cron);
            \update_option('cron', $cron);
        }

        /**
         * @param array<int, mixed> $args
         * @return array{schedule: string|false, args: array<int, mixed>, interval?: int}|null
         */
        private function scheduledEvent(string $hook = self::HOOK, array $args = []): ?array
        {
            foreach (\_get_cron_array() as $hooks) {
                if (isset($hooks[$hook][md5(serialize($args))])) {
                    return $hooks[$hook][md5(serialize($args))];
                }
            }

            return null;
        }

        // ── The declaration moved ───────────────────────────────────────

        public function testTheHookIsNoLongerDeclaredRecurring(): void
        {
            /** @var array{recurring: array<string, array{interval: string}>, cleanup_only: list<string>} $lists */
            $lists = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';

            self::assertArrayNotHasKey(self::HOOK, $lists['recurring']);
            self::assertContains(self::HOOK, $lists['cleanup_only']);
        }

        /*
         * ⚠ NOT ASSERTED HERE: "every declared recurring hook has a
         * registered interval".
         *
         * The old `testEveryDeclaredRecurringHookCanBeScheduled` asserted it
         * for the ONE hook this file was about, which was legitimate because
         * that hook was in scope. Generalising it to the whole `recurring`
         * map is a genuinely useful invariant — a declared hook whose
         * interval nothing registers can never be scheduled and is reported
         * MISSING forever, which is the PR 7A failure class — but it cannot
         * be asserted honestly from this file: the intervals come from five
         * separate `cron_schedules` registrars (CronService,
         * PageReadModelSync, ChainRefreshService, DisputeScheduler and a
         * closure in Plugin.php), and the hand-written `wp_get_schedules()`
         * in the prologue above knows only the handful this file needs.
         *
         * Asserting it against that stub would measure the stub. It belongs in
         * a test that assembles the real registrars, and it is recorded as a
         * gap rather than faked here.
         */

        /**
         * The locally-defined status constants really are the runner's.
         *
         * Without this, the two copies would only ever be compared against
         * each other and a rename would go unnoticed here.
         */
        public function testTheMigrationVocabularyMatchesTheRunner(): void
        {
            $src = (string) file_get_contents(
                dirname(__DIR__, 2) . '/includes/database/migration-runner.php'
            );

            self::assertStringContainsString(
                "define('BCC_TRUST_MIGRATION_COMPLETE', '" . BCC_TRUST_MIGRATION_COMPLETE . "')",
                $src
            );
            self::assertStringContainsString(
                "define('BCC_TRUST_MIGRATION_INCOMPLETE', '" . BCC_TRUST_MIGRATION_INCOMPLETE . "')",
                $src
            );
        }

        // ── The migration, against the real cron option ─────────────────

        /**
         * THE POINT OF THE WHOLE STAGE.
         *
         * A code deletion stops future installs creating the event. It does
         * nothing for installs that already hold one, because the event is in
         * `wp_options.cron`, not in the code.
         */
        public function testTheMigrationClearsARealEventFromTheCronOption(): void
        {
            \wp_schedule_event(time(), 'bcc_five_minutes', self::HOOK);

            self::assertNotNull($this->scheduledEvent(), 'precondition: a real event exists');
            self::assertNotFalse(\wp_next_scheduled(self::HOOK));

            self::assertSame(
                BCC_TRUST_MIGRATION_COMPLETE,
                \bcc_trust_unschedule_discovery_maintenance()
            );

            self::assertNull($this->scheduledEvent(), 'gone from the cron array');
            self::assertFalse(\wp_next_scheduled(self::HOOK), 'and gone from the lookup');
        }

        public function testTheMigrationIsANoOpWhenNothingIsScheduled(): void
        {
            self::assertNull($this->scheduledEvent(), 'precondition: a fresh install');

            self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, \bcc_trust_unschedule_discovery_maintenance());
            self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, \bcc_trust_unschedule_discovery_maintenance());
        }

        /**
         * ISOLATION. The migration clears ONE hook by name. A sibling
         * five-minute job sharing the same timestamp bucket in the cron array
         * must be untouched — an over-broad clear would silently disarm
         * unrelated production work.
         */
        public function testTheMigrationLeavesSiblingHooksAlone(): void
        {
            $now = time();
            \wp_schedule_event($now, 'bcc_five_minutes', self::HOOK);
            \wp_schedule_event($now, 'bcc_five_minutes', 'bcc_trust_process_recalculations');
            \wp_schedule_event($now, 'bcc_five_minutes', 'bcc_helius_dedupe_sweep');

            \bcc_trust_unschedule_discovery_maintenance();

            self::assertFalse(\wp_next_scheduled(self::HOOK), 'the retired hook is cleared');
            self::assertNotFalse(
                \wp_next_scheduled('bcc_trust_process_recalculations'),
                'the recalc queue worker must survive'
            );
            self::assertNotFalse(
                \wp_next_scheduled('bcc_helius_dedupe_sweep'),
                'the Helius dedupe sweep must survive'
            );
        }

        /**
         * THE EXECUTOR QUEUE IS NOT DRAINED BY THIS MIGRATION.
         *
         * Its wp-cron fallback events carry `[$runId]`, so they are a
         * different event identity. The migration must leave every one of
         * them in place: freezing what creates work does not stop work
         * already queued, and a migration that appeared to have drained the
         * queue while leaving the events behind would be worse than one that
         * never claimed to.
         */
        public function testTheExecutorQueueSurvivesTheMigration(): void
        {
            \wp_schedule_event(time(), 'bcc_five_minutes', self::HOOK);
            self::queueSingleEvent(time() + 60, self::EXECUTOR_HOOK, [4242]);

            self::assertNotNull(
                $this->scheduledEvent(self::EXECUTOR_HOOK, [4242]),
                'precondition: a queued executor action exists'
            );

            \bcc_trust_unschedule_discovery_maintenance();

            self::assertFalse(\wp_next_scheduled(self::HOOK));
            self::assertNotNull(
                $this->scheduledEvent(self::EXECUTOR_HOOK, [4242]),
                'the argument-bearing executor event must still be there'
            );
        }

        // ── Deactivation still clears it ────────────────────────────────

        /**
         * Carried over: deactivation clears from the declared hook list,
         * which is why the hook moved to `cleanup_only` rather than being
         * deleted from the file outright.
         */
        public function testDeactivationClearsTheEventViaTheDeclaredList(): void
        {
            \wp_schedule_event(time(), 'bcc_five_minutes', self::HOOK);
            self::assertNotFalse(\wp_next_scheduled(self::HOOK));

            /** @var array{recurring: array<string, array{interval: string}>, cleanup_only: list<string>} $lists */
            $lists = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';

            // Exactly what bcc_trust_deactivate() does.
            foreach (array_merge(array_keys($lists['recurring']), $lists['cleanup_only']) as $hook) {
                \wp_clear_scheduled_hook((string) $hook);
            }

            self::assertFalse(
                \wp_next_scheduled(self::HOOK),
                'deactivation must clear the retired event via the declared list'
            );
            self::assertNull($this->scheduledEvent());
        }

        // ── And it is not an act of discovery ───────────────────────────

        /**
         * Asserted against a real table: the migration must not create,
         * delete or otherwise touch a discovery run.
         *
         * Retention stopping is a consequence of retiring the sweep — the
         * sweep was the only production caller of
         * `DiscoveryRunRepository::pruneTerminal()` — not something the
         * migration does on its way past.
         */
        public function testTheMigrationWritesNothingToTheLedger(): void
        {
            $table = DiscoveryRunRepository::table();

            $before = (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM `{$table}`");
            self::assertSame(0, $before);

            \wp_schedule_event(time(), 'bcc_five_minutes', self::HOOK);
            \bcc_trust_unschedule_discovery_maintenance();

            $after = (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM `{$table}`");
            self::assertSame(0, $after, 'the migration must not insert or remove a discovery run');

            // And it bound no handler on its way through.
            $hooks = array_column(BccCronIntegrationHooks::$actions, 'hook');
            self::assertNotContains(self::HOOK, $hooks, 'nothing may re-bind the retired sweep');
        }
    }
}
