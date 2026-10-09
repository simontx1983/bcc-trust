<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * `bcc_discovery_run_maintenance` no longer schedules itself, and cannot.
 *
 * ── WHAT WAS RETIRED ────────────────────────────────────────────────────
 * A five-minute recurring hook over the discovery-run ledger: re-dispatch
 * runs an administrator had already requested, requeue expired leases,
 * terminalize exhausted ones, prune terminal history. MAINTENANCE, never
 * discovery — it had no chain-selection logic and could not create a run.
 *
 * It is retired because the surface that created runs is gone, and because it
 * had already been a complete no-op since the freeze: `tick()` returns its
 * zero counts before triaging anything.
 *
 * ── THIS FILE REPLACES DiscoveryMaintenanceScheduleTest ─────────────────
 * That file existed to prove the opposite — that the sweep was SCHEDULED and
 * not merely wired — because PR 7A shipped a handler bound to an event
 * nothing created, and staging measured
 * `expected=37, scheduled=36, MISSING=bcc_discovery_run_maintenance`.
 *
 * Its premise is now inverted, so the coverage is carried over inverted
 * rather than dropped, under the house `*CronRetiredTest` name. Three of its
 * cases survive UNCHANGED in intent and are reproduced below, because they
 * assert things that must still hold:
 *
 *   • the bare file-scope `add_action` is gone  (it was the PR 7A bug)
 *   • the EXECUTOR remains a one-shot and is never scheduled
 *   • deactivation clears the event through the declared hook list
 *
 * ⚠ COMMENTS ARE STRIPPED BEFORE ASSERTING ABSENCE. The retirement notes left
 * in `bcc-trust.php`, `cron-hooks.php` and the worker deliberately NAME the
 * hook and quote the deleted `register()` body so a future reader understands
 * why it is gone — a raw string search would match that prose and make the
 * explanation itself the failure.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DiscoveryMaintenanceCronRetiredTest extends TestCase
{
    private const HOOK          = 'bcc_discovery_run_maintenance';
    private const EXECUTOR_HOOK = 'bcc_discovery_run_execute';

    /** Source with comments and docblocks removed. */
    private static function codeOf(string $relativePath): string
    {
        $src  = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        $code = '';

        foreach (token_get_all($src) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    // ── The whole worker is gone, registration sites included ───────────

    /**
     * Why S6 asserted the two registration halves separately, recorded
     * because it is the reasoning a future reader needs before re-adding
     * anything here: `register()` did two things, and removing either one
     * alone would have been worse than removing neither. Without the
     * schedule a bound handler waits on an event nothing creates (the PR 7A
     * bug, reported MISSING forever); without the handler a five-minute
     * event fires into nothing, which is drift a health check then has to
     * explain. S8 removed both by removing the file.
     */
    /**
     * ⚠ S8 REPLACED TWO CASES HERE WITH ONE STRONGER ONE.
     *
     * S6 asserted that `DiscoveryRunMaintenance.php` still existed but no
     * longer carried `register()`, `registerRecurring()`, `wp_schedule_event`
     * or `add_action` — with an explicit anti-vacuity guard that the file was
     * "still the worker, not an empty shell".
     *
     * S8 deleted the file. That guard then did exactly its job and failed,
     * which is how this was caught: without it, `assertStringNotContainsString`
     * over an empty string would have passed for the wrong reason and both
     * cases would have gone quietly vacuous.
     *
     * "The file does not exist" implies everything the four string checks
     * asserted and cannot be satisfied by an empty shell, so it replaces them.
     * The hook-side cases below are untouched: they are about `wp_options.cron`
     * and the migration, which still matter.
     */
    public function testTheMaintenanceWorkerFileIsGoneEntirely(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertFileDoesNotExist(
            $root . '/app/Domain/Onchain/Workers/DiscoveryRunMaintenance.php',
            'the maintenance sweep was deleted in S8 and must not come back'
        );
        self::assertFalse(
            class_exists(\BCC\Trust\Onchain\Workers\DiscoveryRunMaintenance::class),
            'and nothing may reintroduce the class under that name'
        );

        // Anti-vacuity: the sibling it was deleted alongside IS still here, so
        // this is not an assertion over a tree that failed to load.
        self::assertFileExists(
            $root . '/app/Domain/Onchain/Workers/DiscoveryRunExecutor.php',
            'denominator: the retained executor must still be on disk'
        );
    }

    /**
     * The `plugins_loaded` self-heal block must not call it.
     *
     * This is the dangerous one: self-healing a retired hook reschedules it on
     * the very next request, which is precisely the failure shape the
     * NftEnrichmentService note in that block warns about.
     */
    public function testTheSelfHealBlockDoesNotRegisterTheSweep(): void
    {
        $code = self::codeOf('bcc-trust.php');

        self::assertStringNotContainsString('DiscoveryRunMaintenance::register', $code);

        // Anti-vacuity: the self-heal block still exists and still registers
        // the sibling worker, so the absence above is about this hook and not
        // about the block having been removed.
        self::assertStringContainsString('ValidatorMsgQueueWorker::register', $code);
    }

    /**
     * Carried over unchanged from DiscoveryMaintenanceScheduleTest. The bare
     * file-scope `add_action` WAS the PR 7A defect, and it must not return by
     * either route.
     */
    public function testTheBareFileScopeAddActionIsStillGone(): void
    {
        $code = self::codeOf('bcc-trust.php');

        self::assertStringNotContainsString(
            "add_action(\n    \\BCC\\Trust\\Onchain\\Workers\\DiscoveryRunMaintenance::HOOK",
            $code
        );
        self::assertStringNotContainsString('DiscoveryRunMaintenance::HOOK', $code);
    }

    // ── The retirement lists ────────────────────────────────────────────

    public function testTheHookIsInTheCleanupOnlyList(): void
    {
        $map = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';

        self::assertContains(self::HOOK, $map['cleanup_only'], 'must be cleared on deactivate/uninstall');
        self::assertArrayNotHasKey(self::HOOK, $map['recurring'], 'must not be an expected recurring hook');
    }

    /**
     * ⚠ EXACTLY ONE LIST.
     *
     * `recurring` is what populates the `bcc_expected_cron_hooks` drift
     * detector. Leaving the hook there after deleting its scheduler would
     * report it permanently MISSING — the PR 7A condition again, arrived at
     * from the opposite direction.
     */
    public function testTheMoveWasAMoveAndNotACopy(): void
    {
        $map = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';

        $both = array_intersect(array_keys($map['recurring']), $map['cleanup_only']);
        self::assertSame([], $both, 'the two lists must stay disjoint');
    }

    /**
     * Carried over from DiscoveryMaintenanceScheduleTest. Deactivation clears
     * from the declared hook list, so the hook being in that file at all is
     * what makes deactivation clear it — hence `cleanup_only` rather than
     * deletion.
     */
    public function testDeactivationStillClearsTheEventViaTheDeclaredHookList(): void
    {
        $map = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';

        $cleared = array_merge(array_keys($map['recurring']), $map['cleanup_only']);
        self::assertContains(self::HOOK, $cleared);
    }

    public function testAMigrationEntryExistsWithItsOwnDoneOption(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/includes/database/migration-runner.php'
        );

        self::assertStringContainsString("'unschedule_discovery_maintenance_v1'", $src);
        self::assertStringContainsString("'bcc_trust_discovery_maintenance_unscheduled'", $src);
        self::assertStringContainsString("'bcc_trust_unschedule_discovery_maintenance'", $src);

        // ⚠ A DISTINCT done_option is not a style preference. Both existing
        // unschedule migrations have already completed on every install, so
        // the runner short-circuits on their option — folding this hook into
        // one of them would never run on exactly the installs carrying the
        // live event.
        foreach ([
            'bcc_trust_automatic_nft_discovery_unscheduled',
            'bcc_trust_nft_enrichment_tick_unscheduled',
            'bcc_trust_hall_provision_unscheduled',
        ] as $borrowed) {
            self::assertStringNotContainsString(
                "'done_option' => '" . $borrowed . "',\n                'callback'    => 'bcc_trust_unschedule_discovery_maintenance'",
                $src
            );
        }
    }

    public function testTheMigrationFileIsLoadedByTheBootstrap(): void
    {
        $code = self::codeOf('bcc-trust.php');

        self::assertStringContainsString(
            'includes/database/unschedule-discovery-maintenance.php',
            $code,
            'an unregistered migration file never runs'
        );
    }

    public function testTheHookIsInTheSharedRetiredList(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/database/unschedule-discovery-maintenance.php';

        self::assertContains(self::HOOK, bcc_trust_retired_discovery_maintenance_hooks());
    }

    /**
     * ⚠⚠⚠ THE EXECUTOR IS NOT IN THE RETIRED LIST, AND MUST NOT BE.
     *
     * Freezing what creates work does not stop work already queued. Clearing
     * the executor hook would also take more than this migration does: its
     * wp-cron fallback events carry `[$runId]`, and a no-argument clear cannot
     * see an argument-bearing event, so a list entry here would look like it
     * had drained the queue while leaving every real event in place.
     */
    public function testTheExecutorHookIsNotRetiredByThisMigration(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/database/unschedule-discovery-maintenance.php';

        self::assertNotContains(self::EXECUTOR_HOOK, bcc_trust_retired_discovery_maintenance_hooks());
    }

    /**
     * Carried over unchanged from DiscoveryMaintenanceScheduleTest: the
     * executor stays a one-shot and is never given a recurrence.
     */
    public function testTheExecutorRemainsAOneShotAndIsNeverScheduled(): void
    {
        $code = self::codeOf('bcc-trust.php');
        $map  = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';

        // Still bound — a registered no-op that refuses before claiming is
        // not drift; an unregistered callback on a scheduled event is.
        self::assertStringContainsString('DiscoveryRunExecutor::HOOK', $code);

        // But never recurring, in either list.
        self::assertArrayNotHasKey(self::EXECUTOR_HOOK, $map['recurring']);
        self::assertNotContains(self::EXECUTOR_HOOK, $map['cleanup_only']);
    }

    // ── The migration itself ────────────────────────────────────────────

    public function testTheMigrationClearsAnExistingEventAndProvesIt(): void
    {
        require_once __DIR__ . '/../Stubs/discovery-retirement-stubs.php';
        require_once dirname(__DIR__, 2) . '/includes/database/unschedule-discovery-maintenance.php';

        \BccRetirementState::reset();
        \BccRetirementState::schedule(self::HOOK, [], 'bcc_five_minutes');

        self::assertNotFalse(\BccRetirementState::next(self::HOOK), 'precondition: the event exists');

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_unschedule_discovery_maintenance());
        self::assertFalse(\BccRetirementState::next(self::HOOK), 'the event must be gone');
    }

    public function testTheMigrationIsIdempotentOnAFreshInstall(): void
    {
        require_once __DIR__ . '/../Stubs/discovery-retirement-stubs.php';
        require_once dirname(__DIR__, 2) . '/includes/database/unschedule-discovery-maintenance.php';

        \BccRetirementState::reset();

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_unschedule_discovery_maintenance());
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_unschedule_discovery_maintenance());
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_unschedule_discovery_maintenance());
    }

    /**
     * ⚠ THE FAIL-CLOSED PATH.
     *
     * A migration that marked itself done on the strength of having CALLED
     * the clear would leave the five-minute event running with no remaining
     * attempt to remove it, and nothing would ever say so.
     */
    public function testAClearThatDidNotTakeReportsIncomplete(): void
    {
        require_once __DIR__ . '/../Stubs/discovery-retirement-stubs.php';
        require_once dirname(__DIR__, 2) . '/includes/database/unschedule-discovery-maintenance.php';

        \BccRetirementState::reset();
        \BccRetirementState::schedule(self::HOOK, [], 'bcc_five_minutes');
        \BccRetirementState::$refuseToClear = [self::HOOK];

        self::assertSame(
            BCC_TRUST_MIGRATION_INCOMPLETE,
            bcc_trust_unschedule_discovery_maintenance(),
            'an unproven postcondition must not report COMPLETE'
        );
        self::assertNotFalse(\BccRetirementState::next(self::HOOK), 'and the event is still there');
    }

    /**
     * An argument-bearing event is a DIFFERENT identity and legitimately
     * survives `wp_clear_scheduled_hook($hook)`.
     *
     * Nothing ever scheduled one for THIS hook —
     * `AsyncDispatcher::registerRecurring()` calls `wp_schedule_event()` with
     * no fourth argument — but pinning the behaviour keeps that correctness
     * argument honest rather than assumed, and it is the same property that
     * makes clearing the EXECUTOR hook a larger job than this migration.
     */
    public function testAnArgumentBearingEventIsNotSilentlyClaimed(): void
    {
        require_once __DIR__ . '/../Stubs/discovery-retirement-stubs.php';
        require_once dirname(__DIR__, 2) . '/includes/database/unschedule-discovery-maintenance.php';

        \BccRetirementState::reset();
        \BccRetirementState::schedule(self::HOOK, [], 'bcc_five_minutes');
        \BccRetirementState::schedule(self::HOOK, [123], 'bcc_five_minutes');

        bcc_trust_unschedule_discovery_maintenance();

        self::assertFalse(\BccRetirementState::next(self::HOOK), 'the no-arg identity is cleared');
        self::assertNotFalse(
            \BccRetirementState::next(self::HOOK, [123]),
            'the argument-bearing identity is a different event'
        );
    }

    /**
     * The migration touches the cron option and nothing else — in particular
     * it must not prune, delete or otherwise mutate the run ledger.
     *
     * Retention stopping is a CONSEQUENCE of retiring the sweep (the sweep was
     * `pruneTerminal()`'s only production caller), not something this
     * migration does on its way past.
     */
    public function testTheMigrationTouchesNoLedgerRow(): void
    {
        $code = self::codeOf('includes/database/unschedule-discovery-maintenance.php');

        foreach ([
            'pruneTerminal',
            'DiscoveryRunRepository',
            'DELETE',
            'TRUNCATE',
            '$wpdb',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $code, $forbidden . ' must not appear');
        }

        // Anti-vacuity: it really is the migration, and it really does clear.
        self::assertStringContainsString('wp_clear_scheduled_hook', $code);
        self::assertStringContainsString('wp_next_scheduled', $code);
    }

    /**
     * A fresh install must stay QUIET.
     *
     * `drop-onchain-role-boost-columns.php` logs its "dropped" line outside
     * the branch that does the dropping, so it announces a retirement that
     * did not happen on every install, hourly, forever. This migration logs
     * only when something was actually cleared.
     */
    public function testNothingIsLoggedWhenThereWasNothingToClear(): void
    {
        $code = self::codeOf('includes/database/unschedule-discovery-maintenance.php');

        // The success log is guarded by a non-empty $cleared.
        self::assertStringContainsString('if ($cleared !== [] && class_exists', $code);
    }
}
