<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Core\DB\AdvisoryLock;
use BCC\Trust\Onchain\Services\GatedGroupProvisioningService;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Two overlapping provisioning runs cannot both create a holder community.
 *
 * ── WHAT WAS UNGUARDED ──────────────────────────────────────────────────
 * `provisionOne()` read "is there a community for this collection?" and then
 * created one, with nothing in between. `findGroupForCollection()` is a
 * bounded SELECT and the gate config is five plain `wp_postmeta` rows with no
 * unique index, so the database could not reject a second community. Two runs
 * could both see nothing and both call `createPeepSoGroup()`. See #368.
 *
 * ── WHY THIS IS A LOCK AND NOT AN INCIDENT ──────────────────────────────
 * `CollectionRepository::setProvisioningState()` is a genuine compare-and-swap,
 * so the loser's finalize transaction threw and `compensate()` tore its group
 * down. The realistic outcome was a TRANSIENT duplicate. It is still worth
 * locking, for two reasons this suite does not test and one it does:
 * `compensate()` returns bool and a failed compensation leaves a stray group
 * with its gate meta already deleted; the teardown runs on the real PeepSo
 * write surface; and — the testable one — preventing the race is assertable,
 * whereas relying on the loser's cleanup routes the correctness argument
 * through the error handler.
 *
 * ── THE ENTRY POINTS THIS COVERS ────────────────────────────────────────
 * All three go through `provisionOne()`: `bcc_gated_group_provision` (a LIVE
 * daily cron, `bcc-trust.php:735`) and two admin surfaces
 * (`VerifyCollectionsPage` "Provision now" at :1440 and the sweep at :3785,
 * which calls `processRequested()` → `provisionOne()` per row). So the cron
 * vs admin race #123 describes abstractly is reachable here, and one lock
 * around `provisionOne()` covers every one of them.
 *
 * ⚠ The lock is PER COLLECTION. The sweep drains a queue one row at a time
 * through this method, so a global lock would serialize the whole sweep
 * behind a single slow create.
 */
#[CoversClass(GatedGroupProvisioningService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GatedGroupProvisioningLockTest extends TestCase
{
    private const CID      = 42;
    private const OTHER    = 99;
    private const OPERATOR = 7;
    private const ADDRESS  = '0x1234567890abcdef1234567890abcdef12345678';
    private const LOCK     = 'bcc_gated_group_provision:42';

    private GatedGroupProvisioningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/provisioning-intent-stubs.php';
        require_once __DIR__ . '/../Stubs/peepso-group-fakes.php';

        \BccAdminTestState::reset();
        \BccObjectCacheSpy::reset();
        \BccPeepSoSpy::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Core\Observability\DegradationMetrics::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Core\Security\TransactionManager::reset();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::reset();
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        AdvisoryLock::reset();

        $this->service = new GatedGroupProvisioningService();
    }

    private function seed(int $cid = self::CID, string $state = ProvisioningState::REQUESTED): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(4, 'ethereum', 'evm');
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[$cid] = (object) [
            'id'                   => $cid,
            'chain_id'             => 4,
            'contract_address'     => self::ADDRESS,
            'canonical_identifier' => self::ADDRESS,
            'collection_name'      => 'Seeded Collection',
            'is_verified'          => 1,
            'chain_type'           => 'evm',
            'chain_slug'           => 'ethereum',
        ];
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[$cid] = [
            'state' => $state,
            'at'    => '2026-09-01 00:00:00',
            'by'    => self::OPERATOR,
            'code'  => null,
        ];
    }

    // ── Contention ──────────────────────────────────────────────────────

    /**
     * The whole point: a peer holding the lock means nothing is created.
     */
    public function testAHeldLockCreatesNothing(): void
    {
        $this->seed();

        self::assertTrue(
            AdvisoryLock::acquire(self::LOCK, 0),
            'precondition: the peer takes the lock'
        );

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('busy', $result['status']);
        self::assertSame(0, \BccPeepSoSpy::$created, 'no PeepSo group may be created under contention');
    }

    /**
     * ⭐ THE CASE A LATE LOCK WOULD STILL PASS. A lock placed AFTER the
     * existence check leaves the TOCTOU window exactly as wide while every
     * outcome above still looks right. So the existence read must not be
     * reached at all.
     */
    public function testTheExistenceCheckIsNeverReachedUnderContention(): void
    {
        $this->seed();
        AdvisoryLock::acquire(self::LOCK, 0);

        $this->service->provisionOne(self::CID);

        self::assertSame(
            0,
            \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$lookups,
            'findGroupForCollection() must not run when the lock was refused: a lock '
                . 'taken after the existence read would leave the race untouched'
        );
    }

    /** Contention is not a failure, so nothing durable may record one. */
    public function testContentionRecordsNoFailureAndNoAudit(): void
    {
        $this->seed();
        AdvisoryLock::acquire(self::LOCK, 0);

        $result = $this->service->provisionOne(self::CID);

        self::assertFalse($result['failure_recorded'], 'a contended row must not be marked failed');
        self::assertSame(
            ProvisioningState::REQUESTED,
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID]['state'],
            'the row must stay `requested` so the next attempt picks it up — recording a '
                . 'failed state here would make the row ineligible on retry'
        );
    }

    /** A different collection must not be blocked by this one's lock. */
    public function testAnotherCollectionIsNotBlocked(): void
    {
        $this->seed(self::OTHER);
        AdvisoryLock::acquire(self::LOCK, 0);

        $result = $this->service->provisionOne(self::OTHER);

        self::assertSame('created', $result['status'], 'the lock is per collection, not global');
        self::assertSame(1, \BccPeepSoSpy::$created);
    }

    // ── Acquire / release discipline ─────────────────────────────────────

    public function testExactlyOneAcquireAndOneReleasePerRun(): void
    {
        $this->seed();

        $this->service->provisionOne(self::CID);

        self::assertSame([self::LOCK], AdvisoryLock::$acquired, 'one acquire, for this collection');
        self::assertSame([self::LOCK], AdvisoryLock::$released, 'one release, matching it');
    }

    /**
     * ⚠ MySQL's GET_LOCK is reentrant per session, so an unmatched pair leaks
     * the lock for the rest of the request. The release has to survive the
     * create failing, not just the create succeeding.
     *
     * ⚠⚠ AND A CORRECTION WORTH LEAVING HERE. This case was first written as
     * "released when the create THROWS", which it cannot be: `createPeepSoGroup()`
     * catches a throwing constructor and returns 0, so the exception is
     * converted into a clean `failed` result and NOTHING escapes
     * `provisionOne()`. Measured, not assumed — the throwing fixture produced
     * no exception at the call site.
     *
     * So the `finally` is not currently reachable by a throw from this path,
     * and this case does not pretend otherwise: it pins release on the FAILURE
     * path. The `finally` remains correct and worth keeping, because
     * `invalidateGroupCaches()`, `do_action('bcc_gated_group_provisioned')`
     * and a future listener on it are all inside the critical section and are
     * not wrapped by anything.
     */
    public function testTheLockIsReleasedWhenTheCreateFails(): void
    {
        $this->seed();
        \BccPeepSoSpy::$throwOnCreate = true;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame(
            'failed',
            $result['status'],
            'precondition: a throwing constructor is converted to a clean failure, '
                . 'not propagated'
        );
        self::assertSame([self::LOCK], AdvisoryLock::$released, 'released on the failure path too');
        self::assertSame([], AdvisoryLock::$held, 'and nothing stays held');
    }

    /**
     * And the lock is genuinely free afterwards — a leak would be invisible in
     * the case above, which only inspects the spy's own bookkeeping.
     *
     * ⚠ The row's state is `failed` after the first attempt, by PR 6's intent
     * gate: a failed provision needs a FRESH request, so a retry is `skipped`
     * rather than `created`. That is correct and is not what this case is
     * about, so the request is re-seeded.
     */
    public function testTheLockIsFreeForTheNextRunAfterAFailure(): void
    {
        $this->seed();
        \BccPeepSoSpy::$throwOnCreate = true;
        $this->service->provisionOne(self::CID);

        self::assertTrue(
            AdvisoryLock::acquire(self::LOCK, 0),
            'the name must be acquirable again; a leaked lock would refuse here'
        );
        AdvisoryLock::release(self::LOCK);

        \BccPeepSoSpy::$throwOnCreate = false;
        $this->seed();

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('created', $result['status'], 'a leaked lock would make this `busy`');
    }

    /**
     * ⚠ The wait is load-bearing, not a detail: a non-zero timeout is what
     * turns an ordinary double-submit into `exists` rather than `busy`. A
     * mutation to 0 passes every outcome assertion in this file.
     */
    public function testTheAcquireAsksForTheDeclaredWait(): void
    {
        $this->seed();

        $this->service->provisionOne(self::CID);

        self::assertCount(1, AdvisoryLock::$timeouts);
        self::assertSame(
            5,
            AdvisoryLock::$timeouts[0],
            'the acquire must ask for LOCK_WAIT_SECONDS, not a non-blocking 0'
        );
    }

    // ── Fail closed, and no lock for cheap refusals ─────────────────────

    /**
     * An unlockable create is the duplicate the lock exists to stop, so a
     * driver that cannot serialize must refuse rather than proceed.
     */
    public function testAnUnacquirableLockFailsClosed(): void
    {
        $this->seed();
        AdvisoryLock::$acquirable = false;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('busy', $result['status']);
        self::assertSame(0, \BccPeepSoSpy::$created);
    }

    /**
     * The cheap gates create nothing, so holding the lock across them would
     * let a stream of unverified rows contend with a real provision.
     */
    public function testACheapRefusalTakesNoLockAtAll(): void
    {
        $this->seed(self::CID, ProvisioningState::NONE);

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('skipped', $result['status'], 'precondition: this refusal is a cheap one');
        self::assertSame([], AdvisoryLock::$acquired, 'a refusal before Gate 4 must not take the lock');
    }

    // ── The sweep must not launder a contended row ──────────────────────

    /**
     * ⭐ `processRequested()`'s switch ended in `default: $skipped++`, so a new
     * `busy` status would have been counted as "nothing to do for this one" —
     * the same laundering its own `unavailable` arm exists to prevent, and it
     * would make a sweep that provisioned nothing read as a clean pass.
     */
    public function testTheSweepCountsAContendedRowAsContendedNotSkipped(): void
    {
        $this->seed();
        AdvisoryLock::acquire(self::LOCK, 0);

        $result = $this->service->processRequested();

        self::assertSame(1, $result['contended'], 'the contended row is counted as such');
        self::assertSame(0, $result['skipped'], 'and NOT as skipped — there is work, somebody else is doing it');
        self::assertSame(0, $result['created']);
        self::assertSame(0, $result['failed']);
        self::assertSame([], $result['errors'], 'contention is not an error for the operator to chase');
        self::assertTrue($result['available'], 'the queue was readable; only this row was busy');
    }

    /** Anti-vacuity for the case above: an uncontended sweep still creates. */
    public function testAnUncontendedSweepStillCreatesAndReportsZeroContended(): void
    {
        $this->seed();

        $result = $this->service->processRequested();

        self::assertSame(1, $result['created'], 'anti-vacuity: the sweep can still provision');
        self::assertSame(0, $result['contended']);
    }
}
