<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Core\DB\AdvisoryLock;
use BCC\Trust\Onchain\Services\GatedGroupProvisioningService;
use BCC\Trust\Onchain\ValueObjects\ProvisioningFailureCode;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-160 — the holder community's PeepSo owner is the administrator who
 * REQUESTED it, whoever (or whatever) runs the provisioning.
 *
 * ── THE BUG ─────────────────────────────────────────────────────────────
 * `createPeepSoGroup()` passed `owner_id`, which PeepSo maps to post_author
 * only. PeepSo's create() makes the CURRENT user `member_owner`. So:
 *   - a different admin pressing "Provision now" became the owner, and the
 *     requester had no membership at all;
 *   - the daily `bcc_gated_group_provision` cron has current user 0, and
 *     PeepSo wrote a `gm_user_id = 0` owner row nobody can act as.
 * bcc-core's PeepSoGroupWriter refuses user 0, so the Hall-style
 * join → transfer → leave repair cannot run on the cron path; the fix makes
 * the requester the current user for the constructor alone.
 *
 * The PeepSo fake models the real create (current user → member_owner), so
 * reverting the fix turns these red.
 */
#[CoversClass(GatedGroupProvisioningService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GatedGroupProvisioningOwnerTest extends TestCase
{
    private const CID       = 42;
    private const REQUESTER = 7;
    private const OTHER     = 3;
    private const ADDRESS   = '0x1234567890abcdef1234567890abcdef12345678';

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

    private function seed(): void
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
            'by'    => self::REQUESTER,
            'code'  => null,
        ];
    }

    /** @return array<string, string> */
    private function ledgerFor(int $groupId): array
    {
        return array_filter(
            \BccPeepSoSpy::$memberships,
            static fn (string $key): bool => str_ends_with($key, ':' . $groupId),
            ARRAY_FILTER_USE_KEY
        );
    }

    /** The daily cron has no current user. The requester must still own it. */
    public function testUnderTheCronTheRequesterOwnsTheCommunityNotUserZero(): void
    {
        $this->seed();
        \BccAdminTestState::$userId = 0;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('created', $result['status']);
        $groupId = \BccPeepSoSpy::$lastGroupId;
        self::assertSame(
            [self::REQUESTER . ':' . $groupId => 'member_owner'],
            $this->ledgerFor($groupId),
            'exactly one membership: the requester, as owner — no gm_user_id = 0 row'
        );
        self::assertSame([self::REQUESTER], \BccPeepSoSpy::$creatorIds);
        self::assertSame(0, get_current_user_id(), 'the cron identity is restored');
        $meta = \BCC\Trust\Core\Security\AuditLogger::$rows[0]['meta'];
        self::assertSame(self::REQUESTER, $meta['operator_user_id']);
        self::assertSame(0, $meta['acting_user_id'], 'the audit says the cron ran it');
    }

    /** Another admin pressing "Provision now" must not take the community. */
    public function testADifferentAdminRunningItDoesNotBecomeTheOwner(): void
    {
        $this->seed();
        \BccAdminTestState::$userId = self::OTHER;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('created', $result['status']);
        $groupId = \BccPeepSoSpy::$lastGroupId;
        self::assertSame('member_owner', \BccPeepSoSpy::$memberships[self::REQUESTER . ':' . $groupId] ?? null);
        self::assertArrayNotHasKey(self::OTHER . ':' . $groupId, \BccPeepSoSpy::$memberships);
        self::assertSame(self::OTHER, get_current_user_id(), 'the acting admin identity is restored');
        self::assertSame(self::OTHER, \BCC\Trust\Core\Security\AuditLogger::$rows[0]['meta']['acting_user_id'],
            'the audit names the admin who ran it, not the requester');
    }

    /**
     * Ownership is READ BACK. A create that returns an id but leaves no owner
     * row is compensated and reported as a create failure, never `created`.
     */
    public function testACreateThatLeavesNoOwnerIsCompensatedAndFails(): void
    {
        $this->seed();
        \BccPeepSoSpy::$createSkipsOwnerRow = true;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('failed', $result['status']);
        self::assertSame(ProvisioningFailureCode::GROUP_CREATE_FAILED, $result['failure_code']);
        self::assertSame(ProvisioningState::FAILED,
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID]['state']);
        self::assertSame(1, \BccPeepSoSpy::$deleted, 'the ownerless group was removed');
        self::assertNull(
            \BCC\Trust\Onchain\Repositories\GatedGroupRepository::getGateConfig(\BccPeepSoSpy::$lastGroupId),
            'no gate was configured on it'
        );
    }

    /** A throwing constructor must not leave the request running as the requester. */
    public function testTheIdentityIsRestoredWhenTheCreateThrows(): void
    {
        $this->seed();
        \BccAdminTestState::$userId = self::OTHER;
        \BccPeepSoSpy::$throwOnCreate = true;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('failed', $result['status']);
        self::assertSame(1, \BccPeepSoSpy::$created, 'the constructor was reached and threw');
        self::assertSame(self::OTHER, get_current_user_id(), 'the identity swap did not outlive the throw');
    }
}
