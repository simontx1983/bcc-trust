<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\CommunityCustodyService;
use BCC\Trust\Core\ValueObjects\CapabilityDecision;
use BCC\Trust\Core\ValueObjects\GroupType;
use PHPUnit\Framework\TestCase;

/**
 * Rank Phase 7 (§21.2) — ownership-transfer orchestration.
 *
 * Pins the gate ORDER of CommunityCustodyService::transfer (owner-only
 * with the 404 no-existence-leak posture → User-kind only → receiver
 * identity → giver capability → receiver active membership (anti-grief)
 * → receiver capability with the receiver_* reason prefix → sanctioned
 * writer → TWO custody rows + audit) against scripted collaborators.
 * The capability verdicts themselves are pinned in CapabilityMatrixTest;
 * the PeepSo write sequence in bcc-core's PeepSoGroupWriterTransferTest.
 */
final class CommunityCustodyServiceTest extends TestCase
{
    private const GROUP = 42;
    private const OWNER = 7;
    private const RECEIVER = 9;

    /**
     * @param array<int, ?string> $statuses userId => membership status
     * @param array<string, CapabilityDecision> $decisions "userId:key" => verdict
     */
    private function service(
        array $statuses = [self::OWNER => 'member_owner', self::RECEIVER => 'member'],
        ?GroupType $kind = GroupType::User,
        bool $userExists = true,
        array $decisions = [],
        bool $writerOk = true
    ): RecordingCustodyService {
        return new RecordingCustodyService($statuses, $kind, $userExists, $decisions, $writerOk);
    }

    public function testHappyPathTransfersLogsBothCustodyRowsAndAudits(): void
    {
        $svc    = $this->service();
        $result = $svc->transfer(self::OWNER, self::GROUP, self::RECEIVER);

        self::assertSame(
            ['ok' => true, 'group_id' => self::GROUP, 'new_owner_id' => self::RECEIVER],
            $result
        );

        self::assertSame([[self::GROUP, self::OWNER, self::RECEIVER]], $svc->writerCalls);
        // Giver 'transfer_out' + receiver 'receive' — BOTH cooldowns re-arm.
        self::assertSame(
            [
                [self::OWNER, self::GROUP, 'transfer_out', self::RECEIVER],
                [self::RECEIVER, self::GROUP, 'receive', self::OWNER],
            ],
            $svc->custodyCalls
        );
        self::assertSame([[self::GROUP, self::OWNER, self::RECEIVER]], $svc->auditCalls);
    }

    public function testNonexistentGroupAndNonMemberBothCollapseTo404(): void
    {
        // Viewer has NO row at all (group may not even exist).
        $none = $this->service(statuses: [])->transfer(self::OWNER, self::GROUP, self::RECEIVER);
        self::assertSame('bcc_not_found', $none['error'] ?? null);

        // Pending / banned rows are NOT active membership — same 404, no leak.
        foreach (['pending_admin', 'banned', 'block_invites'] as $status) {
            $result = $this->service(statuses: [self::OWNER => $status])
                ->transfer(self::OWNER, self::GROUP, self::RECEIVER);
            self::assertSame('bcc_not_found', $result['error'] ?? null, "status {$status}");
        }
    }

    public function testActiveMemberWhoIsNotOwnerGets403(): void
    {
        // Membership already proves existence to them — 403 leaks nothing.
        $result = $this->service(statuses: [self::OWNER => 'member_manager', self::RECEIVER => 'member'])
            ->transfer(self::OWNER, self::GROUP, self::RECEIVER);

        self::assertSame('bcc_forbidden', $result['error'] ?? null);
    }

    public function testOnlyUserKindGroupsTransfer(): void
    {
        foreach ([GroupType::Hall, GroupType::Nft, GroupType::Validator, GroupType::System] as $kind) {
            $result = $this->service(kind: $kind)->transfer(self::OWNER, self::GROUP, self::RECEIVER);
            self::assertSame('bcc_invalid_request', $result['error'] ?? null, "kind {$kind->value}");
            self::assertSame(
                'Only member-created communities can be transferred.',
                $result['message'] ?? null,
                "kind {$kind->value}"
            );
        }

        // Unresolvable kind (post vanished) fails closed as not-found.
        $gone = $this->service(kind: null)->transfer(self::OWNER, self::GROUP, self::RECEIVER);
        self::assertSame('bcc_not_found', $gone['error'] ?? null);
    }

    public function testReceiverIdentityChecks(): void
    {
        $self = $this->service()->transfer(self::OWNER, self::GROUP, self::OWNER);
        self::assertSame('bcc_invalid_request', $self['error'] ?? null);

        $zero = $this->service()->transfer(self::OWNER, self::GROUP, 0);
        self::assertSame('bcc_invalid_request', $zero['error'] ?? null);

        $missing = $this->service(userExists: false)->transfer(self::OWNER, self::GROUP, self::RECEIVER);
        self::assertSame('bcc_invalid_request', $missing['error'] ?? null);
    }

    public function testGiverDenialSurfacesReasonAndBlocksBeforeReceiverChecks(): void
    {
        // Giver in recovery AND receiver not a member — the GIVER's
        // capability gate runs first, so its reason wins (order pin).
        $svc = $this->service(
            statuses: [self::OWNER => 'member_owner', self::RECEIVER => null],
            decisions: [
                self::OWNER . ':transfer_community' => CapabilityDecision::denied('in_recovery', 'community_custody'),
            ]
        );
        $result = $svc->transfer(self::OWNER, self::GROUP, self::RECEIVER);

        self::assertSame('bcc_forbidden', $result['error'] ?? null);
        self::assertSame(['reason' => 'in_recovery'], $result['data'] ?? null);
        self::assertSame([], $svc->writerCalls);
    }

    public function testReceiverMustAlreadyBeAnActiveMember(): void
    {
        // Anti-grief: an unsolicited transfer to a stranger would burn
        // THEIR cap and re-arm THEIR cooldown — membership is consent.
        foreach ([null, 'pending_admin', 'banned'] as $status) {
            $result = $this->service(statuses: [self::OWNER => 'member_owner', self::RECEIVER => $status])
                ->transfer(self::OWNER, self::GROUP, self::RECEIVER);
            self::assertSame('bcc_invalid_request', $result['error'] ?? null, 'status ' . var_export($status, true));
            self::assertSame(
                'The new owner must already be a member of this community.',
                $result['message'] ?? null
            );
        }
    }

    public function testReceiverDenialCarriesReceiverPrefixedReason(): void
    {
        $svc = $this->service(decisions: [
            self::RECEIVER . ':receive_community' => CapabilityDecision::denied('cooldown_active', 'community_custody'),
        ]);
        $result = $svc->transfer(self::OWNER, self::GROUP, self::RECEIVER);

        self::assertSame('bcc_forbidden', $result['error'] ?? null);
        self::assertSame(['reason' => 'receiver_cooldown_active'], $result['data'] ?? null);
        self::assertSame([], $svc->writerCalls);
        self::assertSame([], $svc->custodyCalls);
    }

    public function testWriterFailureIsInternalErrorWithNoLedgerOrAudit(): void
    {
        $svc    = $this->service(writerOk: false);
        $result = $svc->transfer(self::OWNER, self::GROUP, self::RECEIVER);

        self::assertSame('bcc_internal_error', $result['error'] ?? null);
        self::assertSame([], $svc->custodyCalls);
        self::assertSame([], $svc->auditCalls);
    }
}

/**
 * Recording test double — scripts every collaborator hook and records
 * the write-side calls so the suite can pin ordering + payloads.
 */
final class RecordingCustodyService extends CommunityCustodyService
{
    /** @var list<array{int, int, int}> */
    public array $writerCalls = [];
    /** @var list<array{int, int, string, ?int}> */
    public array $custodyCalls = [];
    /** @var list<array{int, int, int}> */
    public array $auditCalls = [];

    /**
     * @param array<int, ?string> $statuses
     * @param array<string, CapabilityDecision> $decisions
     */
    public function __construct(
        private readonly array $statuses,
        private readonly ?GroupType $kind,
        private readonly bool $fakeUserExists,
        private readonly array $decisions,
        private readonly bool $writerOk
    ) {
    }

    protected function membershipStatus(int $userId, int $groupId): ?string
    {
        return $this->statuses[$userId] ?? null;
    }

    protected function groupKind(int $groupId): ?GroupType
    {
        return $this->kind;
    }

    protected function userExists(int $userId): bool
    {
        return $this->fakeUserExists;
    }

    protected function capability(int $userId, string $key): CapabilityDecision
    {
        return $this->decisions["{$userId}:{$key}"]
            ?? CapabilityDecision::allowed('community_custody');
    }

    protected function writerTransfer(int $groupId, int $fromUserId, int $toUserId): bool
    {
        $this->writerCalls[] = [$groupId, $fromUserId, $toUserId];
        return $this->writerOk;
    }

    protected function recordCustody(int $userId, int $groupId, string $event, ?int $counterpartyUserId): void
    {
        $this->custodyCalls[] = [$userId, $groupId, $event, $counterpartyUserId];
    }

    protected function audit(int $groupId, int $fromUserId, int $toUserId): void
    {
        $this->auditCalls[] = [$groupId, $fromUserId, $toUserId];
    }
}
