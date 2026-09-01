<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\CommunityRequestService;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Community-request INTENT: the thing that replaces "verification creates a
 * community".
 *
 * ── THE PROPERTY EVERY TEST HERE ORBITS ─────────────────────────────────
 * `is_verified = 1` is necessary and never sufficient. Nothing in this file
 * may pass while a code path exists that turns verification alone into a
 * community, and the closest such path — `applyVerification()` — is tested
 * for the ABSENCE of a provisioning write rather than the presence of one.
 */
#[CoversClass(CommunityRequestService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CommunityRequestServiceTest extends TestCase
{
    private const CID      = 42;
    /** Canonical EVM: lowercase 0x + 20 bytes. No checksum to satisfy. */
    private const ADDRESS  = '0x1234567890abcdef1234567890abcdef12345678';
    private const OPERATOR = 7;

    private CommunityRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/verify-collections-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Core\Security\TransactionManager::reset();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::reset();
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();

        $this->service = new CommunityRequestService();
    }

    /**
     * A verified, canonically-identified, community-less EVM collection.
     *
     * EVM rather than Cosmos deliberately: a canonical EVM address is
     * lowercase hex with no checksum, so these tests exercise the REQUEST
     * rules without every fixture also having to be a valid bech32. Cosmos
     * checksum behaviour is covered where it belongs, in the intake tests.
     */
    private function seedEligible(int $id = self::CID): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(4, 'ethereum', 'evm');
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[$id] = (object) [
            'id'                   => $id,
            'chain_id'             => 4,
            'contract_address'     => self::ADDRESS,
            'canonical_identifier' => self::ADDRESS,
            'collection_name'      => 'Seeded Collection',
            'is_verified'          => 1,
            'chain_type'           => 'evm',
            'chain_slug'           => 'ethereum',
        ];
    }

    private function state(int $id = self::CID): string
    {
        return \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[$id]['state'] ?? 'none';
    }

    // ── Verification is never authorization ─────────────────────────────

    public function testAnUnverifiedCollectionCannotBeRequested(): void
    {
        $this->seedEligible();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID]->is_verified = 0;

        $result = $this->service->request(self::CID, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(CommunityRequestService::REFUSED_NOT_VERIFIED, $result['reason']);
        self::assertSame('none', $this->state(), 'no intent may be recorded');
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
    }

    /**
     * The whole point of PR 6, asserted as an ABSENCE.
     *
     * Verifying a collection must leave provisioning state untouched. A test
     * that only checked "requesting works" would pass just as happily against
     * a build where verification also provisioned.
     */
    public function testVerifyingWritesNoProvisioningStateAtAll(): void
    {
        $this->seedEligible();

        $result = $this->service->applyVerification([self::CID], [], self::OPERATOR);

        self::assertTrue($result['ok']);
        self::assertSame(
            [],
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$stateWrites,
            'verification must not touch provisioning state'
        );
        self::assertSame('none', $this->state());
    }

    // ── Request ─────────────────────────────────────────────────────────

    public function testARequestRecordsTheExactAdministratorAndAWhen(): void
    {
        $this->seedEligible();

        $result = $this->service->request(self::CID, self::OPERATOR);

        self::assertTrue($result['ok']);
        self::assertSame('requested', $result['status']);
        self::assertSame(ProvisioningState::REQUESTED, $this->state());

        $write = \BCC\Trust\Onchain\Repositories\CollectionRepository::$stateWrites[0];
        self::assertSame(self::OPERATOR, $write['by'], 'the requester is recorded, not inferred');
        self::assertNotNull($write['at']);
        self::assertNull($write['code']);

        $audit = \BCC\Trust\Core\Security\AuditLogger::$rows[0];
        self::assertSame(CommunityRequestService::AUDIT_REQUESTED, $audit['action']);
        self::assertSame(self::OPERATOR, $audit['meta']['operator_user_id']);
        self::assertSame('none', $audit['meta']['previous_state']);
        self::assertSame('requested', $audit['meta']['new_state']);
    }

    public function testAskingTwiceIsIdempotentAndDoesNotReattributeTheRequest(): void
    {
        $this->seedEligible();
        $this->service->request(self::CID, self::OPERATOR);

        $second = $this->service->request(self::CID, 99);

        self::assertTrue($second['ok']);
        self::assertSame('already_requested', $second['status']);
        self::assertCount(
            1,
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$stateWrites,
            'a repeat request must not re-stamp the row'
        );
        self::assertSame(
            self::OPERATOR,
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID]['by'],
            'the original requester survives a second click by someone else'
        );
        self::assertCount(1, \BCC\Trust\Core\Security\AuditLogger::$rows);
    }

    public function testARetryOfAFailedProvisioningGoesThroughTheSameRoute(): void
    {
        $this->seedEligible();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID] = [
            'state' => ProvisioningState::FAILED,
            'at'    => '2026-09-01 00:00:00',
            'by'    => self::OPERATOR,
            'code'  => 'group_create_failed',
        ];

        $result = $this->service->request(self::CID, self::OPERATOR);

        self::assertTrue($result['ok']);
        self::assertSame('requested', $result['status']);
        self::assertSame(ProvisioningState::REQUESTED, $this->state());
        self::assertNull(
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID]['code'],
            'a retry clears the previous failure code'
        );
    }

    public function testACollectionWithNoResolvedIdentityCannotBeRequested(): void
    {
        $this->seedEligible();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID]->canonical_identifier = null;

        $result = $this->service->request(self::CID, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(CommunityRequestService::REFUSED_IDENTITY, $result['reason']);
        self::assertSame('none', $this->state());
    }

    public function testARequestForACollectionThatAlreadyHasACommunityCreatesNothing(): void
    {
        $this->seedEligible();
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$groups['4|' . self::ADDRESS] = 555;

        $result = $this->service->request(self::CID, self::OPERATOR);

        self::assertTrue($result['ok']);
        self::assertSame('exists', $result['status']);
        self::assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$stateWrites);
    }

    // ── Operator identity ───────────────────────────────────────────────

    public function testAnOperatorWhoNoLongerExistsIsRefused(): void
    {
        $this->seedEligible();
        \BccAdminTestState::$knownUserIds = [1, 2];

        $result = $this->service->request(self::CID, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(CommunityRequestService::REFUSED_BAD_OPERATOR, $result['reason']);
        self::assertSame('none', $this->state());
    }

    public function testAnOperatorWithoutTheCapabilityIsRefused(): void
    {
        $this->seedEligible();
        \BccAdminTestState::$capableUserIds = [1];

        $result = $this->service->request(self::CID, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(CommunityRequestService::REFUSED_BAD_OPERATOR, $result['reason']);
    }

    public function testOperatorZeroIsRefused(): void
    {
        $this->seedEligible();

        $result = $this->service->request(self::CID, 0);

        self::assertFalse($result['ok']);
        self::assertSame(CommunityRequestService::REFUSED_BAD_OPERATOR, $result['reason']);
    }

    // ── Checked audit ───────────────────────────────────────────────────

    /**
     * An intent nobody can prove was recorded is not an intent. If the
     * checked audit cannot be written, the state change must not survive.
     */
    public function testAFailedCheckedAuditRollsTheRequestBack(): void
    {
        $this->seedEligible();
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;

        $result = $this->service->request(self::CID, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(CommunityRequestService::REFUSED_WRITE_FAILED, $result['reason']);
        self::assertSame('none', $this->state(), 'the state write must be rolled back');
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
        self::assertSame(1, \BCC\Trust\Core\Security\TransactionManager::$rollbacks);
    }

    public function testAFailedCheckedAuditRollsAWithdrawalBackToo(): void
    {
        $this->seedEligible();
        $this->service->request(self::CID, self::OPERATOR);

        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;
        $result = $this->service->withdraw(self::CID, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(
            ProvisioningState::REQUESTED,
            $this->state(),
            'a withdrawal nobody can prove must not stand'
        );
    }

    // ── Withdraw ────────────────────────────────────────────────────────

    public function testWithdrawingAPendingRequestReturnsItToNone(): void
    {
        $this->seedEligible();
        $this->service->request(self::CID, self::OPERATOR);

        $result = $this->service->withdraw(self::CID, self::OPERATOR);

        self::assertTrue($result['ok']);
        self::assertSame('withdrawn', $result['status']);
        self::assertSame('none', $this->state());
        self::assertSame(
            CommunityRequestService::AUDIT_WITHDRAWN,
            \BCC\Trust\Core\Security\AuditLogger::$rows[1]['action']
        );
    }

    /**
     * The asymmetry issue #215 settles: withdrawing a request is never a way
     * to remove a community.
     */
    public function testWithdrawingNeverTouchesAProvisionedCollection(): void
    {
        $this->seedEligible();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID] = [
            'state' => ProvisioningState::PROVISIONED,
            'at'    => null,
            'by'    => null,
            'code'  => null,
        ];

        $result = $this->service->withdraw(self::CID, self::OPERATOR);

        self::assertTrue($result['ok']);
        self::assertSame('provisioned', $result['status']);
        self::assertSame(ProvisioningState::PROVISIONED, $this->state());
        self::assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$withdrawals);
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows, 'nothing happened, so nothing is audited');
    }

    public function testWithdrawingWhenNothingIsPendingIsANoOp(): void
    {
        $this->seedEligible();

        $result = $this->service->withdraw(self::CID, self::OPERATOR);

        self::assertTrue($result['ok']);
        self::assertSame('nothing_pending', $result['status']);
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
    }

    // ── Unverify withdraws pending intent ───────────────────────────────

    public function testRemovingVerificationWithdrawsAPendingRequest(): void
    {
        $this->seedEligible();
        $this->service->request(self::CID, self::OPERATOR);

        $result = $this->service->applyVerification([], [self::CID], self::OPERATOR);

        self::assertTrue($result['ok']);
        self::assertSame(1, $result['withdrawn']);
        self::assertSame('none', $this->state());
    }

    public function testRemovingVerificationWithdrawsAFailedRequestToo(): void
    {
        $this->seedEligible();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID] = [
            'state' => ProvisioningState::FAILED,
            'at'    => '2026-09-01 00:00:00',
            'by'    => self::OPERATOR,
            'code'  => 'group_create_failed',
        ];

        $result = $this->service->applyVerification([], [self::CID], self::OPERATOR);

        self::assertSame(1, $result['withdrawn']);
        self::assertSame('none', $this->state());
    }

    /**
     * And an existing community survives. This is the case an operator can
     * cause with one click, so it is asserted on its own.
     */
    public function testRemovingVerificationLeavesAProvisionedCommunityAlone(): void
    {
        $this->seedEligible();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID] = [
            'state' => ProvisioningState::PROVISIONED,
            'at'    => null,
            'by'    => null,
            'code'  => null,
        ];
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$groups['4|' . self::ADDRESS] = 555;

        $result = $this->service->applyVerification([], [self::CID], self::OPERATOR);

        self::assertTrue($result['ok']);
        self::assertSame(0, $result['withdrawn']);
        self::assertSame(ProvisioningState::PROVISIONED, $this->state());
        self::assertSame(
            555,
            \BCC\Trust\Onchain\Repositories\GatedGroupRepository::findGroupForCollection(4, self::ADDRESS),
            'the community itself is untouched'
        );
    }

    /**
     * The verification write and the withdrawal are ONE transaction: a
     * failure in either must leave neither.
     */
    public function testAFailedAuditRollsBackBothTheVerificationAndTheWithdrawal(): void
    {
        $this->seedEligible();
        $this->service->request(self::CID, self::OPERATOR);

        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;
        $result = $this->service->applyVerification([], [self::CID], self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame(
            ProvisioningState::REQUESTED,
            $this->state(),
            'the withdrawal must not survive a rolled-back verification change'
        );
        self::assertSame(
            [],
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$bulkCalls,
            'the verification write must be rolled back with it'
        );
    }

    /**
     * Neither a verification change nor a provisioning change may drop
     * `collection_counts_by_chain`.
     *
     * That cache holds a count of collection ROWS per chain and reads
     * neither `is_verified` nor `provisioning_state`. Busting it here would
     * be pure churn — and, worse, it is the kind of "invalidate everything
     * nearby" habit that makes a real invalidation bug invisible.
     */
    public function testNeitherVerifyingNorRequestingDropsThePerChainCountsCache(): void
    {
        $this->seedEligible();
        \BccObjectCacheSpy::reset();

        $this->service->applyVerification([self::CID], [], self::OPERATOR);
        $this->service->request(self::CID, self::OPERATOR);
        $this->service->withdraw(self::CID, self::OPERATOR);

        $keys = array_map(
            static fn (array $d): string => (string) $d['key'],
            \BccObjectCacheSpy::$deleted
        );
        self::assertNotContains('collection_counts_by_chain', $keys);
    }

    public function testAnEmptyVerificationChangeDoesNothingAtAll(): void
    {
        $result = $this->service->applyVerification([], [], self::OPERATOR);

        self::assertTrue($result['ok']);
        self::assertSame(0, $result['changed']);
        self::assertSame(0, \BCC\Trust\Core\Security\TransactionManager::$runs);
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
    }
}
