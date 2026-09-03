<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\DiscoveryRunService;
use BCC\Trust\Onchain\Support\CosmwasmScanEligibility;
use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A run is a durable statement that a NAMED administrator asked for a scan.
 * Every test here defends that sentence.
 */
#[CoversClass(DiscoveryRunService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DiscoveryRunServiceTest extends TestCase
{
    private const CHAIN    = 17;
    private const OPERATOR = 7;

    private DiscoveryRunService $service;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/discovery-run-stubs.php';

        // ── PR 7.1: the environment master switches ─────────────────────
        // These tests are about the SERVICE — authorization, the ledger,
        // the audit, the race — not about the environment gate. Defined ON
        // here so each one keeps exercising what it was written for.
        //
        // ⚠ The OFF cases are deliberately NOT expressed by trying to
        // redefine these (a constant cannot be undefined). They live in
        // DiscoveryRunReadinessGateTest, which runs in its own process and
        // defines them per test method — the pattern
        // CosmwasmChainEligibilityTest already uses.
        if (!defined('BCC_COSMWASM_DISCOVERY_ENABLED')) {
            define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        }
        if (!defined('BCC_COSMWASM_BACKFILL_ENABLED')) {
            define('BCC_COSMWASM_BACKFILL_ENABLED', true);
        }

        \BccDiscoveryTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Core\Security\TransactionManager::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::reset();
        \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::reset();

        \BccDiscoveryTestState::seedAdmin(self::OPERATOR);
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN, 'dungeon');

        $this->service = new DiscoveryRunService();
    }

    // ── Authorization ───────────────────────────────────────────────────

    public function testASuccessfulRequestCreatesAQueuedRunAndDispatchesIt(): void
    {
        $result = $this->service->request(self::CHAIN, self::OPERATOR);

        self::assertTrue($result['ok']);
        self::assertSame('queued', $result['status']);
        self::assertGreaterThan(0, $result['run_id']);
        self::assertNotSame('', $result['run_uuid']);
        self::assertCount(1, \BccDiscoveryTestState::$dispatched);
    }

    /**
     * ⚠ User id 0 is not an administrator identity. It is the absence of
     * one, and WordPress hands it back for every unauthenticated context.
     */
    public function testUserIdZeroIsRefused(): void
    {
        $result = $this->service->request(self::CHAIN, 0);

        self::assertFalse($result['ok']);
        self::assertSame(DiscoveryRunError::OPERATOR_UNRESOLVED, $result['reason']);
        self::assertSame([], \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::$rows);
    }

    public function testANonExistentUserIsRefused(): void
    {
        $result = $this->service->request(self::CHAIN, 999);

        self::assertFalse($result['ok']);
        self::assertSame(DiscoveryRunError::OPERATOR_UNRESOLVED, $result['reason']);
    }

    /** The capability is checked on the NAMED user, never implicitly. */
    public function testAUserWithoutManageOptionsIsRefused(): void
    {
        \BccDiscoveryTestState::seedSubscriber(42);

        $result = $this->service->request(self::CHAIN, 42);

        self::assertFalse($result['ok']);
        self::assertSame(DiscoveryRunError::OPERATOR_UNRESOLVED, $result['reason']);
        self::assertSame([], \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::$rows);
    }

    // ── Chain / driver / capability gate ────────────────────────────────

    /**
     * A refusal creates NO ROW. Queueing a run the executor is guaranteed
     * to refuse would manufacture a failed run on a schedule and tell the
     * operator their request was accepted when it never could be.
     */
    public function testDiscoveryDisabledRefusesWithoutCreatingARow(): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN, 'dungeon', 'cosmos', 1, 0);

        $result = $this->service->request(self::CHAIN, self::OPERATOR);

        self::assertFalse($result['ok']);
        // PR 7.1: the refusal NAMES the blocker. This used to be the
        // catch-all `discovery_disabled`, which a paused chain, an
        // allowlist exclusion and a missing opt-in all produced — leaving
        // the operator no way to tell which one to change.
        self::assertSame(CosmwasmScanEligibility::NOT_OPTED_IN, $result['reason']);
        self::assertSame([], \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::$rows);
        self::assertSame([], \BccDiscoveryTestState::$dispatched);
    }

    public function testAnUnsupportedChainFamilyIsRefused(): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN, 'ethereum', 'evm');

        $result = $this->service->request(self::CHAIN, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(DiscoveryRunError::CHAIN_UNSUPPORTED, $result['reason']);
        self::assertSame([], \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::$rows);
    }

    public function testAnInactiveChainIsRefused(): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN, 'dungeon', 'cosmos', 0, 1);

        $result = $this->service->request(self::CHAIN, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(DiscoveryRunError::CHAIN_UNKNOWN, $result['reason']);
    }

    public function testAnUnknownChainIsRefused(): void
    {
        $result = $this->service->request(4242, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(DiscoveryRunError::CHAIN_UNKNOWN, $result['reason']);
    }

    // ── Scan mode is chosen by the server ───────────────────────────────

    public function testAnUnfinishedBackfillYieldsHistorical(): void
    {
        \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::seed(self::CHAIN, null);

        $result = $this->service->request(self::CHAIN, self::OPERATOR);

        self::assertSame(DiscoveryScanMode::HISTORICAL, $result['scan_mode']);
    }

    public function testACompletedBackfillYieldsIncremental(): void
    {
        \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::seed(self::CHAIN, '2026-08-19 17:29:32');

        $result = $this->service->request(self::CHAIN, self::OPERATOR);

        self::assertSame(DiscoveryScanMode::INCREMENTAL, $result['scan_mode']);
    }

    /** A chain never walked is historical, not "already done". */
    public function testAMissingCheckpointYieldsHistorical(): void
    {
        $result = $this->service->request(self::CHAIN, self::OPERATOR);

        self::assertSame(DiscoveryScanMode::HISTORICAL, $result['scan_mode']);
    }

    // ── Audit integrity ─────────────────────────────────────────────────

    public function testTheRequestWritesACheckedAuthorizationAudit(): void
    {
        $this->service->request(self::CHAIN, self::OPERATOR);

        $rows = \BCC\Trust\Core\Security\AuditLogger::$rows;
        self::assertCount(1, $rows);
        self::assertSame(DiscoveryRunService::AUDIT_REQUESTED, $rows[0]['action']);
        self::assertSame(self::OPERATOR, $rows[0]['meta']['operator_user_id']);
        self::assertSame(self::OPERATOR, $rows[0]['userId']);
    }

    /**
     * A queued run with no proof of who authorized it is exactly what this
     * design exists to prevent, so the audit failure must undo the request.
     */
    public function testAFailedCheckedAuditRollsBackTheRequest(): void
    {
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;

        $result = $this->service->request(self::CHAIN, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(DiscoveryRunError::AUDIT_UNCOMMITTED, $result['reason']);

        // No run may remain claimable.
        $active = \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::findActive(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            self::CHAIN
        );
        self::assertNull($active, 'an unattributed run must not stay active');
        self::assertSame([], \BccDiscoveryTestState::$dispatched, 'and nothing may be dispatched');
    }

    // ── Dispatch failure ────────────────────────────────────────────────

    /**
     * A soft dispatch failure is NOT a user-facing error and NOT an attempt.
     * The request is durable; the maintenance sweep re-dispatches it.
     */
    public function testASoftDispatchFailureStillLeavesARecoverableQueuedRun(): void
    {
        \BccDiscoveryTestState::$dispatchAccepts = false;

        $result = $this->service->request(self::CHAIN, self::OPERATOR);

        self::assertTrue($result['ok'], 'the request itself succeeded');
        self::assertSame('queued', $result['status']);

        $active = \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::findActive(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            self::CHAIN
        );
        self::assertNotNull($active, 'the run must survive for the sweep to find');
        self::assertSame('queued', $active->status);
        self::assertSame(0, (int) $active->attempt_count, 'no claim occurred, so no attempt was consumed');
        self::assertNotSame([], \BCC\Core\Log\Logger::ofLevel('error'));
    }

    // ── The duplicate active-run race ───────────────────────────────────

    public function testASecondRequestWhileOneIsActiveIsRefusedWithTheActiveRunId(): void
    {
        $first = $this->service->request(self::CHAIN, self::OPERATOR);

        $second = $this->service->request(self::CHAIN, self::OPERATOR);

        self::assertFalse($second['ok']);
        self::assertSame(DiscoveryRunError::ALREADY_ACTIVE, $second['reason']);
        self::assertSame($first['run_id'], $second['active_run_id']);
    }

    /**
     * The subtle half: the winner terminalizes between our INSERT and our
     * SELECT, so there is no active run to report. Returning "already
     * active" with no id — or a stale row — would be a lie. The loop retries
     * and succeeds.
     */
    public function testARaceLostToARunThatImmediatelyFinishedRetriesAndSucceeds(): void
    {
        $this->service->request(self::CHAIN, self::OPERATOR);

        \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::$terminalizeOnConflict = true;
        \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::$insertAttempts = 0;

        $second = $this->service->request(self::CHAIN, self::OPERATOR);

        self::assertTrue($second['ok'], 'the slot was free again; the request must succeed');
        self::assertGreaterThan(
            1,
            \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::$insertAttempts,
            'the bounded retry loop must actually have retried'
        );
    }

    /** A repeatedly failing insert is bounded and never fabricates an id. */
    public function testAPersistentInsertFailureIsBoundedAndReturnsNoRunId(): void
    {
        \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::$insertFails = true;

        $result = $this->service->request(self::CHAIN, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(DiscoveryRunError::CONTENTION, $result['reason']);
        self::assertArrayNotHasKey('run_id', $result);
        self::assertSame(
            3,
            \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::$insertAttempts,
            'bounded at three attempts, not a spin loop'
        );
    }

    // ── Cancellation ────────────────────────────────────────────────────

    public function testCancellingAQueuedRunAuditsAndFreesTheSlot(): void
    {
        $run = $this->service->request(self::CHAIN, self::OPERATOR);

        $result = $this->service->cancel((int) $run['run_id'], self::OPERATOR);

        self::assertTrue($result['ok']);
        self::assertContains(DiscoveryRunService::AUDIT_CANCELLED, \BCC\Trust\Core\Security\AuditLogger::actions());
        self::assertNull(\BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::findActive(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            self::CHAIN
        ));
    }

    public function testAFailedCancellationAuditRollsBackTheCancellation(): void
    {
        $run = $this->service->request(self::CHAIN, self::OPERATOR);
        \BCC\Trust\Core\Security\AuditLogger::$failCheckedActions = [DiscoveryRunService::AUDIT_CANCELLED];

        $result = $this->service->cancel((int) $run['run_id'], self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(DiscoveryRunError::AUDIT_UNCOMMITTED, $result['reason']);

        $active = \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::findActive(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            self::CHAIN
        );
        self::assertNotNull($active, 'the run must still be active — the cancellation was undone');
        self::assertSame('queued', $active->status);
    }

    public function testCancellingSomethingThatIsNotQueuedIsRefused(): void
    {
        $run = $this->service->request(self::CHAIN, self::OPERATOR);
        $this->service->cancel((int) $run['run_id'], self::OPERATOR);

        $again = $this->service->cancel((int) $run['run_id'], self::OPERATOR);

        self::assertFalse($again['ok']);
    }

    // ── Retry ───────────────────────────────────────────────────────────

    public function testRetryingAnActiveRunIsRefused(): void
    {
        $run = $this->service->request(self::CHAIN, self::OPERATOR);

        $retry = $this->service->retry((int) $run['run_id'], self::OPERATOR);

        self::assertFalse($retry['ok']);
        self::assertSame(DiscoveryRunError::ALREADY_ACTIVE, $retry['reason']);
    }

    /** A retry is a NEW row that points at the original — history is kept. */
    public function testRetryingATerminalRunCreatesANewLinkedRun(): void
    {
        $run = $this->service->request(self::CHAIN, self::OPERATOR);
        $this->service->cancel((int) $run['run_id'], self::OPERATOR);

        $retry = $this->service->retry((int) $run['run_id'], self::OPERATOR);

        self::assertTrue($retry['ok']);
        self::assertNotSame($run['run_id'], $retry['run_id']);

        $new = \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::findById((int) $retry['run_id']);
        self::assertNotNull($new);
        self::assertSame((int) $run['run_id'], (int) $new->retry_of_run_id);
        self::assertContains(DiscoveryRunService::AUDIT_RETRIED, \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    /** A retry is a fresh authorization: the capability is re-checked. */
    public function testRetryReGatesTheChainCapability(): void
    {
        $run = $this->service->request(self::CHAIN, self::OPERATOR);
        $this->service->cancel((int) $run['run_id'], self::OPERATOR);

        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN, 'dungeon', 'cosmos', 1, 0);

        $retry = $this->service->retry((int) $run['run_id'], self::OPERATOR);

        self::assertFalse($retry['ok']);
        self::assertSame(CosmwasmScanEligibility::NOT_OPTED_IN, $retry['reason']);
    }

    // ── No market data ──────────────────────────────────────────────────

    /**
     * BCC is never a price platform. The ledger records counts of WORK, and
     * no audit meta this service writes may carry a monetary field.
     */
    public function testNoMarketFieldEverReachesTheLedgerOrItsAudit(): void
    {
        $this->service->request(self::CHAIN, self::OPERATOR);

        $forbidden = ['floor_price', 'floor_currency', 'total_volume', 'listed_percentage',
                      'royalty_percentage', 'last_sale', 'price', 'volume'];

        foreach (\BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::$rows as $row) {
            foreach ($forbidden as $key) {
                self::assertArrayNotHasKey($key, $row, 'the ledger must hold no market data');
            }
        }

        foreach (\BCC\Trust\Core\Security\AuditLogger::$rows as $audit) {
            foreach ($forbidden as $key) {
                self::assertArrayNotHasKey($key, $audit['meta'], 'no audit meta may carry a monetary field');
            }
        }
    }
}
