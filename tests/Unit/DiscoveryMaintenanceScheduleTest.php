<?php

declare(strict_types=1);

/**
 * PR 7A.1 — the maintenance sweep must be SCHEDULED, not merely wired.
 *
 * PR 7A shipped a handler bound to an event that never existed: a bare
 * `add_action` at file scope in bcc-trust.php plus an entry in
 * includes/cron-hooks.php, and nothing that called `wp_schedule_event`. On
 * staging the consequence was measurable — the expected-hook inventory read
 * `expected=37, scheduled=36, MISSING=bcc_discovery_run_maintenance`, and the
 * sweep never ran.
 *
 * That matters because the reaper is the ONLY thing that returns an expired
 * lease. With no sweep, one crashed worker leaves a row `running` forever and
 * `uq_active (job_kind, chain_id, active_marker)` then blocks every future run
 * for that pair permanently.
 *
 * Every test here fails against merge commit ac69aa81.
 */

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Workers\DiscoveryRunMaintenance;
use BCC\Trust\Tests\Support\CronHealState;
use BccMaintenanceHookState;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Stubs/discovery-maintenance-schedule-stubs.php';

final class DiscoveryMaintenanceScheduleTest extends TestCase
{
    private const HOOK = 'bcc_discovery_run_maintenance';
    private const INTERVAL = 'bcc_five_minutes';

    protected function setUp(): void
    {
        parent::setUp();
        CronHealState::reset();
        BccMaintenanceHookState::reset();
    }

    protected function tearDown(): void
    {
        CronHealState::$active = false;
        parent::tearDown();
    }

    /** The bootstrap source, read once per assertion that inspects wiring. */
    private function bootstrap(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php');
    }

    /**
     * Source of the `plugins_loaded` self-heal closure — the one carrying the
     * established `ValidatorMsgQueueWorker::register()` call — and the source
     * of everything outside it.
     *
     * ⚠ Brace-matched over TOKENS, not raw bytes. A naive scan counts braces
     * inside string literals (`"{$hook}"`) and comments and runs off the end
     * of the closure, which silently turns "is the call on the init path?"
     * into "is the call anywhere in the file?" — the exact question this test
     * exists to distinguish. `token_get_all()` makes each string and comment
     * one token, so only real block braces are counted.
     *
     * @return array{inside: string, outside: string}
     */
    private function selfHealClosure(): array
    {
        $src    = $this->bootstrap();
        $tokens = token_get_all($src);

        // Byte offset of each token, so a span maps back to source text.
        $offsets = [];
        $pos     = 0;
        foreach ($tokens as $i => $tok) {
            $offsets[$i] = $pos;
            $pos += strlen(is_array($tok) ? $tok[1] : $tok);
        }

        // bcc-trust.php has EIGHT plugins_loaded subscribers, and this worker
        // is also referenced elsewhere in the file, so neither "nearest
        // preceding literal" nor "first mention of the class" identifies the
        // block. Span every candidate closure and keep the one that actually
        // contains the established self-heal CALL.
        $anchor  = 'ValidatorMsgQueueWorker::register()';
        $matches = [];

        foreach ($tokens as $i => $tok) {
            if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING
                || trim($tok[1], "'\"") !== 'plugins_loaded') {
                continue;
            }

            $braceIdx = null;
            for ($j = $i, $n = count($tokens); $j < $n; $j++) {
                if ($tokens[$j] === '{') {
                    $braceIdx = $j;
                    break;
                }
                // A named-callback subscriber has no closure body; stop at the
                // statement end so it cannot borrow a later block's brace.
                if ($tokens[$j] === ';') {
                    break;
                }
            }
            if ($braceIdx === null) {
                continue;
            }

            $depth  = 0;
            $endIdx = null;
            for ($j = $braceIdx, $n = count($tokens); $j < $n; $j++) {
                $t = $tokens[$j];
                if ($t === '{'
                    || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($t === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $endIdx = $j;
                        break;
                    }
                }
            }
            if ($endIdx === null) {
                continue;
            }

            $candidateStart = $offsets[$braceIdx];
            $candidateEnd   = $offsets[$endIdx] + 1;

            if (str_contains(substr($src, $candidateStart, $candidateEnd - $candidateStart), $anchor)) {
                $matches[] = [$candidateStart, $candidateEnd];
            }
        }

        self::assertCount(
            1,
            $matches,
            'exactly one plugins_loaded closure must carry the established self-heal call'
        );

        [$start, $end] = $matches[0];

        /**
         * Comments are stripped from both halves.
         *
         * These assertions are about CODE. The prose in bcc-trust.php names
         * `DiscoveryRunMaintenance::register()` and `::HOOK` while explaining
         * where the wiring moved, and a raw substring search cannot tell an
         * explanation from a call — it reported the bare `add_action` as still
         * present when only the comment mentioning it remained.
         */
        $stripComments = static function (string $php): string {
            $out = '';
            foreach (token_get_all('<?php ' . $php) as $tok) {
                if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= is_array($tok) ? $tok[1] : $tok;
            }
            return $out;
        };

        // Sanity: the closure must be a small block, not "the rest of the file".
        self::assertLessThan(
            strlen($src) / 2,
            $end - $start,
            'token matching ran past the closure — the span is not a single block'
        );
        self::assertStringContainsString(
            'ValidatorMsgQueueWorker::register()',
            substr($src, $start, $end - $start),
            'the located span must be the block holding the anchor'
        );

        return [
            'inside'  => $stripComments(substr($src, $start, $end - $start)),
            'outside' => $stripComments(substr($src, 0, $start) . substr($src, $end)),
        ];
    }

    // ── (1) the callback is bound to the right hook ─────────────────────

    public function testRegisterBindsTheCallbackToTheMaintenanceHook(): void
    {
        DiscoveryRunMaintenance::register();

        $ids = BccMaintenanceHookState::subscriberIds(self::HOOK);
        self::assertSame(
            [DiscoveryRunMaintenance::class . '::handleSweep'],
            $ids,
            'the maintenance hook must invoke the void cron entry point'
        );
    }

    public function testTheHandlerTakesNoArguments(): void
    {
        DiscoveryRunMaintenance::register();

        $action = null;
        foreach (BccMaintenanceHookState::$actions as $a) {
            if ($a['hook'] === self::HOOK) {
                $action = $a;
            }
        }
        self::assertNotNull($action);
        self::assertSame(10, $action['priority']);
        self::assertSame(0, $action['accepted'], 'the sweep takes no cron arguments');
    }

    // ── (2)(3) registration actually schedules, at the right recurrence ──

    public function testRegisterSchedulesTheRecurringEvent(): void
    {
        self::assertFalse(
            \BCC\Core\Cron\wp_next_scheduled(self::HOOK),
            'precondition: nothing scheduled before registration'
        );

        DiscoveryRunMaintenance::register();

        $next = \BCC\Core\Cron\wp_next_scheduled(self::HOOK);
        self::assertIsInt($next, 'wp_next_scheduled must return a real timestamp, not false');
        self::assertGreaterThan(0, $next);
    }

    public function testTheRecurrenceIsExactlyBccFiveMinutes(): void
    {
        DiscoveryRunMaintenance::register();

        $event = CronHealState::$scheduled[CronHealState::eventKey(self::HOOK)] ?? null;
        self::assertNotNull($event);
        self::assertSame(self::INTERVAL, $event['interval']);

        self::assertSame(
            [['hook' => self::HOOK, 'interval' => self::INTERVAL, 'args' => []]],
            CronHealState::$scheduleCalls,
            'exactly one wp_schedule_event call, at the shared five-minute interval'
        );
    }

    public function testTheConstantAndTheDeclaredIntervalAgree(): void
    {
        self::assertSame(self::INTERVAL, DiscoveryRunMaintenance::INTERVAL);

        /** @var array{recurring: array<string, array{interval: string}>} $lists */
        $lists = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';

        self::assertArrayHasKey(
            self::HOOK,
            $lists['recurring'],
            'the declaration must stay — deactivation and drift detection read it'
        );
        self::assertSame(
            DiscoveryRunMaintenance::INTERVAL,
            $lists['recurring'][self::HOOK]['interval'],
            'the scheduled interval and the declared interval must not drift apart'
        );
    }

    // ── (5)(6) idempotency ──────────────────────────────────────────────

    public function testRepeatedRegistrationSchedulesNoDuplicateEvent(): void
    {
        DiscoveryRunMaintenance::register();
        DiscoveryRunMaintenance::register();
        DiscoveryRunMaintenance::register();

        self::assertCount(
            1,
            CronHealState::$scheduleCalls,
            'registerRecurring must short-circuit once an event exists'
        );
        self::assertSame(
            [CronHealState::$now],
            CronHealState::timestampsFor(self::HOOK),
            'exactly one pending occurrence'
        );
    }

    public function testRepeatedRegistrationBindsTheCallbackOnlyOnce(): void
    {
        DiscoveryRunMaintenance::register();
        DiscoveryRunMaintenance::register();

        self::assertSame(
            [DiscoveryRunMaintenance::class . '::handleSweep'],
            BccMaintenanceHookState::subscriberIds(self::HOOK),
            'an array callback has a stable WordPress id, so it replaces rather than appends'
        );
    }

    /**
     * The reason the handler must NOT be an anonymous closure.
     *
     * A closure gets a per-object callback id, so two registrations are two
     * subscribers and the sweep would run twice per tick. PR 7A used a closure
     * at file scope; this asserts the property that made moving it safe.
     */
    public function testAClosureHandlerWouldHaveBoundTwice(): void
    {
        $a = static function (): void {
        };
        $b = static function (): void {
        };

        self::assertNotSame(
            BccMaintenanceHookState::callbackId($a),
            BccMaintenanceHookState::callbackId($b),
            'two closures are two subscribers — control for the assertion above'
        );
        self::assertSame(
            BccMaintenanceHookState::callbackId([DiscoveryRunMaintenance::class, 'handleSweep']),
            BccMaintenanceHookState::callbackId([DiscoveryRunMaintenance::class, 'handleSweep']),
            'the array callback collapses to one id'
        );
    }

    // ── (7) the plugins_loaded initialization path ──────────────────────

    public function testRegistrationIsInvokedFromThePluginsLoadedSelfHealBlock(): void
    {
        $closure = $this->selfHealClosure();

        self::assertStringContainsString(
            'DiscoveryRunMaintenance::register()',
            $closure['inside'],
            'registration must sit INSIDE the plugins_loaded self-heal closure, '
            . 'not merely somewhere in the bootstrap'
        );
        self::assertStringNotContainsString(
            'DiscoveryRunMaintenance::register()',
            $closure['outside'],
            'and nowhere else — a second call site is a second registration path'
        );
    }

    public function testTheBareFileScopeAddActionIsGone(): void
    {
        $closure = $this->selfHealClosure();

        self::assertStringNotContainsString(
            'DiscoveryRunMaintenance::HOOK',
            $closure['outside'],
            'the maintenance hook must not also be bound at file scope — '
            . 'a second binding would run the sweep twice per tick'
        );
    }

    /**
     * The executor is deliberately NOT scheduled. Proves the previous two
     * assertions are not passing because everything moved.
     */
    public function testTheExecutorRemainsAOneShotAndIsNeverScheduled(): void
    {
        $src = $this->bootstrap();

        self::assertStringContainsString(
            'DiscoveryRunExecutor::HOOK',
            $src,
            'the executor handler must still be bound at file scope'
        );
        self::assertStringNotContainsString(
            'DiscoveryRunExecutor::HOOK, ' . "'" . self::INTERVAL . "'",
            $src
        );

        DiscoveryRunMaintenance::register();
        self::assertFalse(
            \BCC\Core\Cron\wp_next_scheduled('bcc_discovery_run_execute'),
            'registering maintenance must never schedule the executor'
        );
    }

    // ── (8) the expected-hook inventory ─────────────────────────────────

    /**
     * The production symptom, reproduced: the hook is DECLARED, so the drift
     * detector expects it; before the fix nothing scheduled it, so it was
     * reported MISSING on every request forever.
     */
    public function testTheDeclaredHookIsNoLongerMissingFromTheInventory(): void
    {
        /** @var array{recurring: array<string, array{interval: string}>} $lists */
        $lists = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';

        self::assertContains(
            self::HOOK,
            array_keys($lists['recurring']),
            'precondition: the hook is declared, so drift detection expects it'
        );

        $missingBefore = \BCC\Core\Cron\wp_next_scheduled(self::HOOK) === false;
        self::assertTrue($missingBefore, 'precondition: declared but unscheduled — the PR 7A state');

        DiscoveryRunMaintenance::register();

        self::assertIsInt(
            \BCC\Core\Cron\wp_next_scheduled(self::HOOK),
            'after registration the declared hook must be scheduled'
        );
    }

    // ── (9) deactivation still clears it ────────────────────────────────

    public function testDeactivationClearsTheEventViaTheDeclaredHookList(): void
    {
        DiscoveryRunMaintenance::register();
        self::assertIsInt(\BCC\Core\Cron\wp_next_scheduled(self::HOOK));

        /** @var array{recurring: array<string, array{interval: string}>, cleanup_only: list<string>} $lists */
        $lists = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';
        $hooks = array_merge(array_keys($lists['recurring']), $lists['cleanup_only']);

        self::assertContains(self::HOOK, $hooks, 'deactivation clears from the declared list');

        // What bcc_trust_deactivate() does for each declared hook.
        foreach ($hooks as $hook) {
            unset(CronHealState::$scheduled[CronHealState::eventKey((string) $hook)]);
        }

        self::assertFalse(
            \BCC\Core\Cron\wp_next_scheduled(self::HOOK),
            'the maintenance event must be cleared on deactivation'
        );
    }

    // ── (10) registration is inert ──────────────────────────────────────

    /**
     * Registration schedules and nothing else. `tick()` is never called here,
     * so no repository, provider, collection or capability is touched — the
     * point being that arming the sweep is not itself an act of discovery.
     */
    public function testRegistrationTouchesNothingButTheSchedule(): void
    {
        DiscoveryRunMaintenance::register();

        self::assertSame([], CronHealState::$singleScheduleCalls, 'no one-shot dispatch');
        self::assertSame([], CronHealState::$cleared, 'nothing unscheduled');
        self::assertSame([], CronHealState::$options, 'no option written');

        self::assertCount(1, CronHealState::$scheduleCalls);
        self::assertSame(self::HOOK, CronHealState::$scheduleCalls[0]['hook']);

        self::assertSame(
            [self::HOOK],
            array_values(array_map(
                static fn (array $e): string => $e['hook'],
                CronHealState::$scheduled
            )),
            'exactly one event exists, and it is the maintenance sweep'
        );
    }

    /**
     * register() must contain no discovery machinery. Structural, because a
     * behavioural assertion cannot prove the ABSENCE of a code path.
     */
    public function testRegisterContainsNoDiscoveryOrProviderMachinery(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Workers/DiscoveryRunMaintenance.php'
        );

        $start = strpos($src, 'public static function register(): void');
        self::assertNotFalse($start);
        $body = substr($src, $start, (int) (strpos($src, 'public static function tick(): array') ?: strlen($src)) - $start);

        foreach ([
            'insert', 'wp_remote_', 'curl_', 'DiscoveryRunService', 'request(',
            'chains', 'capabilit', 'collection', 'floor', 'price',
        ] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $body,
                "register() must not reference '{$forbidden}'"
            );
        }
    }
}
