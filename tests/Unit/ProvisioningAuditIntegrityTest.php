<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\GatedGroupProvisioningService;
use BCC\Trust\Onchain\ValueObjects\ProvisioningFailureCode;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Every durable provisioning state change must be provable.
 *
 * ── THE RULE ────────────────────────────────────────────────────────────
 * A state a person can see, act on, and be held to must have a confirmed
 * audit row. The first cut of PR 6 got this right for `requested`,
 * `withdrawn` and `provisioned` — and wrong for the two paths that only
 * run when something has already gone sideways:
 *
 *   `fail()`     moved requested -> failed and THEN called the unchecked
 *                `AuditLogger::log()`. A failed encode or a failed insert
 *                left a durable `failed` row with no record of who caused
 *                it or why — precisely when the trail matters most.
 *   `exists`     called `markProvisioned()`, ignored its return value, and
 *                reported `exists` regardless. A lost race or a refused
 *                write reported success.
 *
 * Both are now one transaction with `logChecked()`, and both report whether
 * the record actually committed rather than assuming it did.
 */
#[CoversClass(GatedGroupProvisioningService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProvisioningAuditIntegrityTest extends TestCase
{
    private const CID      = 42;
    private const OPERATOR = 7;
    private const ADDRESS  = '0x1234567890abcdef1234567890abcdef12345678';

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

        $this->service = new GatedGroupProvisioningService();
    }

    private function seedRequested(): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(4, 'ethereum', 'evm');
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID] = (object) [
            'id'                   => self::CID,
            'chain_id'             => 4,
            'contract_address'     => self::ADDRESS,
            'canonical_identifier' => self::ADDRESS,
            'collection_name'      => 'Seeded Collection',
            'is_verified'          => 1,
            'chain_type'           => 'evm',
            'chain_slug'           => 'ethereum',
        ];
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID] = [
            'state' => ProvisioningState::REQUESTED,
            'at'    => '2026-09-01 00:00:00',
            'by'    => self::OPERATOR,
            'code'  => null,
        ];
    }

    private function state(): string
    {
        return \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID]['state'] ?? 'none';
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(string $action): array
    {
        return array_values(array_filter(
            \BCC\Trust\Core\Security\AuditLogger::$rows,
            static fn (array $r): bool => $r['action'] === $action
        ));
    }

    // ── BLOCKER 2: the failure transition ───────────────────────────────

    /**
     * A failure that cannot be recorded must not be durable.
     *
     * The collection stays `requested`, so the queue will retry it — which is
     * the honest outcome, because as far as any durable record is concerned
     * the attempt never happened.
     */
    public function testAFailedCheckedAuditLeavesTheCollectionRequested(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID]->collection_name = '';
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame(
            ProvisioningState::REQUESTED,
            $this->state(),
            'a failure nobody can prove must not become durable'
        );
        self::assertSame(ProvisioningFailureCode::AWAITING_METADATA, $result['failure_code']);
    }

    public function testNoPartialFailureAuditRowSurvivesARollback(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID]->collection_name = '';
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;

        $this->service->provisionOne(self::CID);

        self::assertSame(
            [],
            $this->auditRows(GatedGroupProvisioningService::AUDIT_FAILED),
            'no half-written failure record may remain'
        );
        self::assertGreaterThanOrEqual(1, \BCC\Trust\Core\Security\TransactionManager::$rollbacks);
    }

    /**
     * The caller has to be able to tell "this failure is on the record" from
     * "the record itself could not be committed" — they call for different
     * operator responses, and collapsing them is how a silent data-loss
     * window gets reported as an ordinary failure.
     */
    public function testTheResultDistinguishesRecordedFromUnrecordedFailure(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID]->collection_name = '';

        $recorded = $this->service->provisionOne(self::CID);
        self::assertTrue($recorded['failure_recorded'], 'a normal failure is on the record');

        // Reset to requested and repeat with the audit refusing.
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID] = [
            'state' => ProvisioningState::REQUESTED,
            'at'    => '2026-09-01 00:00:00',
            'by'    => self::OPERATOR,
            'code'  => null,
        ];
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;

        $unrecorded = $this->service->provisionOne(self::CID);
        self::assertFalse(
            $unrecorded['failure_recorded'],
            'an uncommittable failure record must not be reported as recorded'
        );
    }

    public function testASuccessfulFailureTransitionStoresOneAuditRowAndOneBoundedCode(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID]->collection_name = '';

        $result = $this->service->provisionOne(self::CID);

        self::assertSame(ProvisioningState::FAILED, $this->state());
        self::assertTrue($result['failure_recorded']);

        $rows = $this->auditRows(GatedGroupProvisioningService::AUDIT_FAILED);
        self::assertCount(1, $rows);

        $meta = $rows[0]['meta'];
        self::assertSame(ProvisioningFailureCode::AWAITING_METADATA, $meta['failure_code']);
        self::assertTrue(ProvisioningFailureCode::isValid((string) $meta['failure_code']));
        self::assertSame(ProvisioningState::REQUESTED, $meta['previous_state']);
        self::assertSame(ProvisioningState::FAILED, $meta['new_state']);

        // The durable column carries the same bounded code, nothing else.
        $stored = \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID]['code'];
        self::assertTrue(ProvisioningFailureCode::isValid((string) $stored));
    }

    /**
     * The operator-facing MESSAGE is returned and logged, never persisted.
     * A durable free-text channel is what the PR 5b audit work removed.
     */
    public function testTheOperatorMessageNeverReachesTheAuditOrTheColumn(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID]->collection_name = '';

        $result = $this->service->provisionOne(self::CID);

        self::assertNotSame('', $result['message'], 'the operator still gets a sentence');

        $rows = $this->auditRows(GatedGroupProvisioningService::AUDIT_FAILED);
        $encoded = (string) json_encode($rows[0]['meta']);

        self::assertStringNotContainsString('awaiting a name', $encoded);
        self::assertStringNotContainsString($result['message'], $encoded);

        foreach (['message', 'detail', 'error', 'exception', 'note', 'reason_text'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $rows[0]['meta']);
        }
    }

    /**
     * A rerun after a recorded failure does not write a second failure row:
     * the collection is no longer `requested`, so the intent gate refuses it
     * before any state or audit work happens.
     */
    public function testRerunningAfterARecordedFailureDoesNotDuplicateTheAudit(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID]->collection_name = '';

        $this->service->provisionOne(self::CID);
        $this->service->provisionOne(self::CID);
        $this->service->provisionOne(self::CID);

        self::assertCount(
            1,
            $this->auditRows(GatedGroupProvisioningService::AUDIT_FAILED),
            'the failure is recorded once, not once per sweep'
        );
    }

    // ── BLOCKER 3: existing-community reconciliation ────────────────────

    private function seedRequestedWithExistingGroup(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$groups['4|' . self::ADDRESS] = 555;
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$configs[555] = [
            'groupId'         => 555,
            'chainId'         => 4,
            'contractAddress' => self::ADDRESS,
            'minBalance'      => 1,
            'collectionId'    => self::CID,
        ];
    }

    public function testAnExistingCommunityReconcilesExactlyOnceAndIsAudited(): void
    {
        $this->seedRequestedWithExistingGroup();

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('exists', $result['status']);
        self::assertSame(555, $result['group_id']);
        self::assertSame(ProvisioningState::PROVISIONED, $this->state());
        self::assertSame(0, \BccPeepSoSpy::$created, 'nothing new is created');
        self::assertSame(0, \BccPeepSoSpy::$deleted, 'the pre-existing community is NOT deleted');

        $rows = $this->auditRows(GatedGroupProvisioningService::AUDIT_PROVISIONED);
        self::assertCount(1, $rows, 'reconciliation is a state change, so it is audited');
        self::assertSame(555, $rows[0]['meta']['group_id'], 'the audit names the EXISTING group');
        self::assertSame(ProvisioningState::REQUESTED, $rows[0]['meta']['previous_state']);
        self::assertSame(ProvisioningState::PROVISIONED, $rows[0]['meta']['new_state']);
    }

    /**
     * If the reconciliation audit cannot be written, the row stays
     * `requested` and the caller is NOT told the community is accounted for.
     */
    public function testAFailedReconciliationAuditDoesNotClaimSuccess(): void
    {
        $this->seedRequestedWithExistingGroup();
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;

        $result = $this->service->provisionOne(self::CID);

        self::assertNotSame(
            'exists',
            $result['status'],
            'an unrecorded reconciliation must not be reported as done'
        );
        self::assertSame(
            ProvisioningState::REQUESTED,
            $this->state(),
            'the state transition rolls back with its audit'
        );
        self::assertSame(0, \BccPeepSoSpy::$deleted, 'the community survives the rollback');
    }

    /**
     * A lost race — someone else moved the row between the read and the
     * write — must not be reported as a successful reconciliation.
     */
    public function testALostStateRaceDoesNotClaimSuccess(): void
    {
        $this->seedRequestedWithExistingGroup();

        // The row has already moved on by the time the write is attempted.
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$stateWriteRefuses = true;

        $result = $this->service->provisionOne(self::CID);

        self::assertNotSame('exists', $result['status']);
        self::assertSame(0, \BccPeepSoSpy::$deleted);
        self::assertSame(
            [],
            $this->auditRows(GatedGroupProvisioningService::AUDIT_PROVISIONED),
            'no audit may claim a transition that did not happen'
        );
    }

    public function testReconciliationIsIdempotentAcrossReruns(): void
    {
        $this->seedRequestedWithExistingGroup();

        $this->service->provisionOne(self::CID);
        $this->service->provisionOne(self::CID);
        $this->service->provisionOne(self::CID);

        self::assertSame(ProvisioningState::PROVISIONED, $this->state());
        self::assertSame(0, \BccPeepSoSpy::$created, 'no duplicate community');
        self::assertCount(
            1,
            $this->auditRows(GatedGroupProvisioningService::AUDIT_PROVISIONED),
            'no duplicate audit'
        );
    }

    // ── Compensation audit ──────────────────────────────────────────────

    /**
     * Compensation cannot be rolled back — the PeepSo group is already gone
     * — so a failed compensation audit is surfaced as a DEGRADATION on the
     * result rather than pretended away.
     */
    public function testAFailedCompensationAuditIsSurfacedRatherThanSwallowed(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$postconditionFails = true;
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('failed', $result['status']);
        self::assertTrue(
            $result['audit_degraded'],
            'an uncommittable compensation record must be reported, not hidden'
        );
        self::assertSame(1, \BccPeepSoSpy::$deleted, 'the group is still removed');
    }

    public function testASuccessfulCompensationIsNotReportedAsDegraded(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$postconditionFails = true;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('failed', $result['status']);
        self::assertFalse($result['audit_degraded']);
        self::assertCount(1, $this->auditRows(GatedGroupProvisioningService::AUDIT_COMPENSATED));
    }
}
