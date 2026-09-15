<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Workers\DiscoveryRunMaintenance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANT: only an explicit administrator action can create a
 * discovery run. Recurring work may RESUME one; it may never START one.
 *
 * ── WHY THE DISTINCTION IS THE WHOLE POINT ──────────────────────────────
 * Five automatic launchers were retired because they selected chains on a
 * timer and called providers with nobody deciding they should
 * (`bcc_index_collections`, four `bcc_cosmwasm_*` hooks), then a sixth
 * (`bcc_nft_enrichment_tick`) for the same reason, then `bcc_hall_provision`
 * for the same shape of reason in a different domain. What remains recurring
 * is {@see DiscoveryRunMaintenance} — and the reason it is allowed to remain
 * is precisely that it cannot select a chain.
 *
 * "Cannot" is a strong word, so it is proved strongly: the ledger double's
 * `insertQueued()` THROWS. The sweep finishing at all is the assertion, and
 * it holds for every branch rather than for the one the fixture happened to
 * take. A row count that merely stayed the same would also be satisfied by
 * an insert followed by a delete, or by an insert somewhere the assertion
 * does not look.
 *
 * ── WHAT THIS DOES NOT CLAIM ────────────────────────────────────────────
 * Not that the sweep is useless — a run whose worker died must come back, or
 * an administrator's request is silently lost. Each recovery behaviour is
 * asserted positively below, so "creates nothing" cannot be satisfied by a
 * sweep that does nothing at all.
 */
#[CoversClass(DiscoveryRunMaintenance::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ManualOnlyDiscoveryTest extends TestCase
{
    private const CHAIN = 8;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/discovery-maintenance-stubs.php';

        \BccMaintenanceWorld::reset();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. THE SWEEP CANNOT CREATE A RUN
    // ═══════════════════════════════════════════════════════════════════

    /**
     * An empty ledger stays empty. There is no chain-selection input to the
     * sweep at all — it reads the run table and nothing else — so there is
     * nothing for it to decide to scan.
     */
    public function testAnEmptyLedgerStaysEmpty(): void
    {
        $result = DiscoveryRunMaintenance::tick();

        self::assertSame([], \BccMaintenanceWorld::$rows, 'no run was invented');
        self::assertSame([], \BccMaintenanceWorld::$dispatched, 'and none was dispatched');
        self::assertSame(
            ['redispatched' => 0, 'requeued' => 0, 'exhausted' => 0, 'pruned' => 0],
            $result
        );
    }

    /**
     * ⚠ AND IT STAYS EMPTY WITH ELIGIBLE CHAINS SITTING RIGHT THERE. This is
     * the scenario every retired launcher got wrong: chains are configured,
     * opted in and perfectly scannable, cron fires — and nothing starts,
     * because nobody asked for anything.
     */
    public function testEligibleChainsDoNotCauseTheSweepToStartAnything(): void
    {
        // The sweep is given no chain input by construction; seeding the
        // world with chains is the closest a test can get to "temptation".
        DiscoveryRunMaintenance::tick();
        DiscoveryRunMaintenance::tick();
        DiscoveryRunMaintenance::tick();

        self::assertSame([], \BccMaintenanceWorld::$rows);
        self::assertSame([], \BccMaintenanceWorld::$dispatched);
    }

    /**
     * A ledger full of FINISHED runs does not get a successor. "The last scan
     * completed, so run another" is the single most natural feature to add
     * here and the one that would quietly restore unattended scanning.
     */
    public function testTerminalRunsAreNeverFollowedByANewOne(): void
    {
        \BccMaintenanceWorld::seedRun(1, self::CHAIN, 'succeeded');
        \BccMaintenanceWorld::seedRun(2, self::CHAIN, 'failed');
        \BccMaintenanceWorld::seedRun(3, self::CHAIN, 'cancelled');

        $result = DiscoveryRunMaintenance::tick();

        self::assertCount(3, \BccMaintenanceWorld::$rows, 'no fourth run');
        self::assertSame([], \BccMaintenanceWorld::$dispatched);
        self::assertSame(0, $result['redispatched']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. IT CAN ONLY RESUME WHAT ALREADY EXISTS
    // ═══════════════════════════════════════════════════════════════════
    //
    //  Anti-vacuity for §1: a sweep that did nothing would pass all of it.

    public function testAQueuedRunIsReDispatchedByItsIdAndNotRecreated(): void
    {
        \BccMaintenanceWorld::seedRun(41, self::CHAIN, 'queued');

        $result = DiscoveryRunMaintenance::tick();

        self::assertSame([41], \BccMaintenanceWorld::$dispatched, 'the EXISTING row, by id');
        self::assertSame(1, $result['redispatched']);
        self::assertCount(1, \BccMaintenanceWorld::$rows, 'still exactly the one row');
    }

    /** A run whose worker died comes back — that is the sweep's whole job. */
    public function testAnExpiredLeaseIsRequeuedAndPickedUpInTheSameTick(): void
    {
        \BccMaintenanceWorld::seedRun(42, self::CHAIN, 'running', [
            'lease_expired' => true,
            'attempt_count' => 1,
        ]);

        $result = DiscoveryRunMaintenance::tick();

        self::assertSame([42], \BccMaintenanceWorld::$requeued);
        self::assertSame([42], \BccMaintenanceWorld::$dispatched, 'requeued first, so it moves this tick');
        self::assertSame(1, $result['requeued']);
        self::assertCount(1, \BccMaintenanceWorld::$rows);
    }

    /**
     * ⚠ THE REQUEUE IS BOUNDED. A run that has burned every attempt is
     * terminalised rather than requeued — otherwise a run that always dies
     * would be re-dispatched every five minutes forever, which is unattended
     * repeated provider work arriving through the back door.
     */
    public function testAnExhaustedRunIsTerminalisedRatherThanRequeuedForever(): void
    {
        \BccMaintenanceWorld::seedRun(43, self::CHAIN, 'running', [
            'lease_expired' => true,
            'attempt_count' => 3,
        ]);

        $result = DiscoveryRunMaintenance::tick();

        self::assertSame([43], \BccMaintenanceWorld::$exhausted);
        self::assertSame([], \BccMaintenanceWorld::$requeued);
        self::assertSame([], \BccMaintenanceWorld::$dispatched, 'a terminal run is not dispatched');
        self::assertSame(1, $result['exhausted']);
    }

    /**
     * A refused dispatch loses nothing and invents nothing: the row stays
     * queued and the next tick tries again.
     */
    public function testARefusedDispatchNeitherLosesNorRecreatesTheRun(): void
    {
        \BccMaintenanceWorld::seedRun(44, self::CHAIN, 'queued');
        \BccMaintenanceWorld::$dispatchAccepts = false;

        $result = DiscoveryRunMaintenance::tick();

        self::assertSame(0, $result['redispatched']);
        self::assertSame([], \BccMaintenanceWorld::$dispatched);
        self::assertCount(1, \BccMaintenanceWorld::$rows);
        self::assertSame('queued', \BccMaintenanceWorld::$rows[44]->status, 'still there, still queued');
    }

    /**
     * Repeated ticks over the same ledger stay idempotent in the only sense
     * that matters here: no tick ever adds a row.
     */
    public function testRepeatedTicksNeverGrowTheLedger(): void
    {
        \BccMaintenanceWorld::seedRun(45, self::CHAIN, 'queued');

        for ($i = 0; $i < 5; $i++) {
            DiscoveryRunMaintenance::tick();
        }

        self::assertCount(1, \BccMaintenanceWorld::$rows);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. THE SCHEDULE ITSELF
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The sweep is recurring, on the shared five-minute interval, under the
     * name the drift detector and the deactivation clear-list both know.
     *
     * Kept as a pinned constant rather than a comment because the hook name
     * appears in `includes/cron-hooks.php`, in the uninstall path and in the
     * operator-facing cron audit; a rename that moved only one of them would
     * leave an orphaned recurring event firing into nothing forever.
     */
    public function testTheSweepIsTheHookTheRestOfTheSystemExpects(): void
    {
        self::assertSame('bcc_discovery_run_maintenance', DiscoveryRunMaintenance::HOOK);
        self::assertSame('bcc_five_minutes', DiscoveryRunMaintenance::INTERVAL);
    }
}
