<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\ScannerFreeze;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The scanner's BACKGROUND entry points are frozen too.
 *
 * ── WHY THIS CLASS EXISTS ───────────────────────────────────────────────
 * Freezing the operator-facing routes left the scanner reachable, and review
 * caught it. Two paths need nobody to click anything:
 *
 *   1. `DiscoveryRunMaintenance` fires every five minutes. Its tick requeues
 *      expired leases, terminalizes exhausted runs, finds dispatchable runs
 *      and enqueues `DiscoveryRunExecutor`. A frozen UI plus a live sweep
 *      still starts, continues and retries scans.
 *   2. An executor action ALREADY QUEUED in Action Scheduler fires after the
 *      deployment that froze everything else. Freezing what creates work does
 *      not stop work that already exists.
 *
 * ── AND WHY THE FIXTURES MATTER MORE THAN THE ASSERTIONS ────────────────
 * "Nothing happened" is trivially true when there was nothing to do. Every
 * test here first proves the tick had REAL WORK WAITING — the selectors the
 * production code calls return the seeded rows — and only then asserts that
 * the frozen path left all of it untouched.
 */
#[CoversNothing]
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ScannerBackgroundEntryPointsAreFrozenTest extends TestCase
{
    // ── 1. The five-minute maintenance sweep ────────────────────────────

    /** A run sitting in `queued`: the sweep's own selector calls it dispatchable. */
    public function testADispatchableRunIsNeitherDispatchedNorMutated(): void
    {
        require_once __DIR__ . '/../Stubs/discovery-maintenance-stubs.php';
        \BccMaintenanceWorld::reset();
        \BccMaintenanceWorld::seedRun(41, 8, 'queued');

        // Non-vacuity: production's own selector says there IS work to dispatch.
        self::assertCount(
            1,
            \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::findDispatchable(20),
            'the fixture must be dispatchable, or the freeze proves nothing'
        );

        $result = \BCC\Trust\Onchain\Workers\DiscoveryRunMaintenance::tick();

        self::assertSame(['redispatched' => 0, 'requeued' => 0, 'exhausted' => 0, 'pruned' => 0], $result);
        self::assertSame([], \BccMaintenanceWorld::$dispatched, 'zero executor enqueues');
        self::assertSame('queued', \BccMaintenanceWorld::$rows[41]->status, 'the run is untouched');
        self::assertSame(0, \BccMaintenanceWorld::$rows[41]->attempt_count, 'no attempt was spent');
    }

    /** A run whose worker died: `running` with an expired lease. */
    public function testAnExpiredLeaseIsNeitherRequeuedNorTerminalized(): void
    {
        require_once __DIR__ . '/../Stubs/discovery-maintenance-stubs.php';
        \BccMaintenanceWorld::reset();
        \BccMaintenanceWorld::seedRun(42, 8, 'running', [
            'lease_expired' => true,
            'lease_token'   => 'lease-42',
            'attempt_count' => 1,
        ]);

        self::assertCount(
            1,
            \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::findExpiredLeases(20),
            'the fixture must have an expired lease, or the freeze proves nothing'
        );

        $result = \BCC\Trust\Onchain\Workers\DiscoveryRunMaintenance::tick();

        self::assertSame(0, $result['requeued']);
        self::assertSame(0, $result['exhausted']);
        self::assertSame([], \BccMaintenanceWorld::$requeued, 'zero requeues');
        self::assertSame([], \BccMaintenanceWorld::$exhausted);
        self::assertSame([], \BccMaintenanceWorld::$dispatched, 'zero executor enqueues');

        $row = \BccMaintenanceWorld::$rows[42];
        self::assertSame('running', $row->status, 'the run keeps the state its operator left it in');
        self::assertTrue($row->lease_expired, 'the lease state is not changed either');
        self::assertSame('lease-42', $row->lease_token);
        self::assertSame(1, $row->attempt_count, 'no attempt was spent');
    }

    /** An attempt-exhausted run is the other branch of the lease triage. */
    public function testAnExhaustedRunIsNotTerminalizedWhileFrozen(): void
    {
        require_once __DIR__ . '/../Stubs/discovery-maintenance-stubs.php';
        \BccMaintenanceWorld::reset();
        \BccMaintenanceWorld::seedRun(43, 8, 'running', [
            'lease_expired' => true,
            'attempt_count' => \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::MAX_ATTEMPTS,
        ]);

        self::assertCount(1, \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::findExpiredLeases(20));

        \BCC\Trust\Onchain\Workers\DiscoveryRunMaintenance::tick();

        self::assertSame([], \BccMaintenanceWorld::$exhausted);
        self::assertSame('running', \BccMaintenanceWorld::$rows[43]->status);
    }

    /** Retention is skipped too, which is what preserves the retained history. */
    public function testTheSweepPrunesNothingSoHistoryIsRetained(): void
    {
        require_once __DIR__ . '/../Stubs/discovery-maintenance-stubs.php';
        \BccMaintenanceWorld::reset();
        \BccMaintenanceWorld::$pruned = 7; // the pruner WOULD report removing seven rows

        $result = \BCC\Trust\Onchain\Workers\DiscoveryRunMaintenance::tick();

        self::assertSame(0, $result['pruned'], 'a frozen sweep deletes no history');
    }

    /** The cron callback is the thing WordPress actually fires. */
    public function testTheRegisteredCronCallbackIsAlsoANoOp(): void
    {
        require_once __DIR__ . '/../Stubs/discovery-maintenance-stubs.php';
        \BccMaintenanceWorld::reset();
        \BccMaintenanceWorld::seedRun(44, 8, 'queued');
        \BccMaintenanceWorld::seedRun(45, 8, 'running', ['lease_expired' => true]);

        \BCC\Trust\Onchain\Workers\DiscoveryRunMaintenance::handleSweep();

        self::assertSame([], \BccMaintenanceWorld::$dispatched);
        self::assertSame([], \BccMaintenanceWorld::$requeued);
        self::assertSame('queued', \BccMaintenanceWorld::$rows[44]->status);
        self::assertSame('running', \BccMaintenanceWorld::$rows[45]->status);
    }

    // ── 2. An executor action queued BEFORE the deployment ──────────────

    /**
     * Exactly what Action Scheduler does when a pending action fires: it calls
     * the hook, which calls `execute()`. The run must come out untouched.
     */
    public function testAnAlreadyPendingExecutorActionDoesNothing(): void
    {
        require_once __DIR__ . '/../Stubs/cosmwasm-cli-stubs.php';
        \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::reset();

        $inserted = \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::insertQueued(
            'cosmwasm_discovery',
            'incremental',
            8,
            1,
            null
        );
        self::assertIsArray($inserted);
        $runId = (int) $inserted['id'];

        $before = \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::$rows[$runId];
        self::assertSame('queued', $before['status'], 'the fixture must be claimable, or the freeze proves nothing');

        $result = \BCC\Trust\Onchain\Workers\DiscoveryRunExecutor::handleQueuedAction($runId);

        self::assertSame('frozen', $result['status'], 'the executor refuses before the claim');
        self::assertSame($runId, $result['run_id']);

        $after = \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::$rows[$runId];
        self::assertSame('queued', $after['status'], 'no claim: the run is still queued');
        self::assertSame(
            $before['attempt_count'],
            $after['attempt_count'],
            'a refused action must not spend an attempt'
        );
        self::assertSame($before, $after, 'zero run, lease and progress changes');
    }

    /** Zero provider requests: the scanner worker is never entered. */
    public function testAPendingExecutorActionMakesNoProviderRequest(): void
    {
        require_once __DIR__ . '/../Stubs/cosmwasm-cli-stubs.php';
        \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::reset();

        $inserted = \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::insertQueued(
            'cosmwasm_discovery',
            'incremental',
            8,
            1,
            null
        );
        $runId = (int) $inserted['id'];

        \BCC\Trust\Onchain\Workers\DiscoveryRunExecutor::handleQueuedAction($runId);

        // Nothing was scheduled to continue the work either.
        self::assertSame([], \BCC\Core\Cron\AsyncDispatcher::$scheduled, 'no continuation chunk is scheduled');

        // And "no provider request" is a consequence of ORDER, not of an absence nobody
        // observed: in execute(), the freeze returns before the claim, and every call that
        // can reach a provider sits after it.
        $source = (string) file_get_contents(__DIR__ . '/../../app/Domain/Onchain/Workers/DiscoveryRunExecutor.php');
        $freeze = strpos($source, 'ScannerFreeze::frozen()');
        $claim  = strpos($source, 'DiscoveryRunRepository::claim(');
        $worker = strpos($source, 'CosmwasmDiscoveryWorker::run');
        self::assertIsInt($freeze, 'the executor must carry the freeze guard');
        self::assertIsInt($claim);
        self::assertIsInt($worker);
        self::assertLessThan($claim, $freeze, 'the guard must precede the claim');
        self::assertLessThan($worker, $freeze, 'the guard must precede every provider-reaching call');
    }

    /**
     * The hook stays REGISTERED. An unhandled pending action would be logged as
     * a failure by Action Scheduler and would look like a broken deployment;
     * a registered handler that refuses is the honest shape.
     */
    public function testTheExecutorHookIsStillRegisteredSoAPendingActionIsRefusedNotUnhandled(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../bcc-trust.php');

        self::assertStringContainsString(
            'DiscoveryRunExecutor::HOOK',
            $source,
            'the async hook keeps a handler so a queued action is refused rather than erroring'
        );
        self::assertStringContainsString(
            'DiscoveryRunExecutor::handleQueuedAction((int) $runId)',
            $source,
            'the hook routes to the FROZEN handler, not straight into execute()'
        );
    }


    /**
     * The freeze guards the ENTRY POINT, so the set of callers is part of the contract.
     *
     * `execute()` itself stays unfrozen on purpose: it is the retained implementation and
     * the executor, session and CLI suites drive it end to end. That is only safe while the
     * sole production callers are this frozen handler and the CLI command whose registration
     * is frozen — so a new caller must fail this test rather than quietly bypass the freeze.
     */
    public function testTheOnlyProductionCallersOfExecuteAreThemselvesFrozen(): void
    {
        $root = __DIR__ . '/../../';
        $callers = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . 'app', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') { continue; }
            $src = (string) file_get_contents($file->getPathname());

            // Executable tokens only: a docblock that MENTIONS execute() is not a caller.
            $code = '';
            foreach (token_get_all($src) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
                    $code .= $token[1];
                    continue;
                }
                $code .= $token;
            }
            if (str_contains($code, 'DiscoveryRunExecutor::execute(')) {
                $callers[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
            }
        }
        sort($callers);

        self::assertSame(
            ['app/Domain/Onchain/CLI/CosmwasmOneShotDiscoveryCommand.php'],
            $callers,
            'a new caller of execute() would bypass the freeze on the registered handler'
        );

        // …and inside the executor itself the only route into execute() is the frozen handler.
        $executor = (string) file_get_contents($root . 'app/Domain/Onchain/Workers/DiscoveryRunExecutor.php');
        self::assertSame(
            1,
            substr_count($executor, 'self::execute('),
            'exactly one internal call into execute(), from handleQueuedAction'
        );
        $handler = strpos($executor, 'public static function handleQueuedAction');
        $guard   = strpos($executor, 'ScannerFreeze::frozen()');
        $call    = strpos($executor, 'self::execute(');
        self::assertIsInt($handler);
        self::assertIsInt($guard);
        self::assertIsInt($call);
        self::assertLessThan($guard, $handler, 'the guard lives inside the handler');
        self::assertLessThan($call, $guard, 'and it returns before the delegation');
    }

    // ── 3. The inventory must say all of this out loud ──────────────────

    public function testTheInventoryListsTheBackgroundEntryPoints(): void
    {
        self::assertContains('cron:bcc_discovery_run_maintenance', ScannerFreeze::FROZEN_ENTRY_POINTS);
        self::assertContains('async:bcc_discovery_run_execute', ScannerFreeze::FROZEN_ENTRY_POINTS);
        self::assertContains('admin_post_bcc_chain_cw_discovery_enable', ScannerFreeze::FROZEN_ENTRY_POINTS);
        self::assertContains('admin_post_bcc_chain_cw_discovery_disable', ScannerFreeze::FROZEN_ENTRY_POINTS);
    }
}
