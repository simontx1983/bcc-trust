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
     * @param list<string> $deniedLockKeys advisory-lock keys that fail to acquire
     */
    private function service(
        array $statuses = [self::OWNER => 'member_owner', self::RECEIVER => 'member'],
        ?GroupType $kind = GroupType::User,
        bool $userExists = true,
        array $decisions = [],
        bool $writerOk = true,
        bool $lockAcquirable = true,
        array $deniedLockKeys = []
    ): RecordingCustodyService {
        return new RecordingCustodyService($statuses, $kind, $userExists, $decisions, $writerOk, $lockAcquirable, $deniedLockKeys);
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

        // §21.2 TOCTOU: the per-GROUP lock (same-group serialization)
        // wraps the whole gate chain; the RECEIVER's per-user lock wraps
        // the cap/cooldown critical section. Fixed order group → receiver,
        // released in reverse (receiver first — inner finally unwinds first).
        self::assertSame(
            ['bcc_community_custody_group_' . self::GROUP, 'bcc_community_custody_' . self::RECEIVER],
            $svc->lockAcquireCalls
        );
        self::assertSame(
            ['bcc_community_custody_' . self::RECEIVER, 'bcc_community_custody_group_' . self::GROUP],
            $svc->lockReleaseCalls
        );
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

    /**
     * Privacy (wallet-privacy P0 precedent): a receiver capability failure
     * NEVER leaks the target's specific status. All six former
     * `receiver_*` sub-reasons collapse to the single generic wire reason
     * `receiver_ineligible` + one generic message — while the TRUE
     * sub-reason is still captured server-side (logReceiverDenied) for
     * admin debugging.
     */
    public function testReceiverDenialCollapsesToGenericIneligible(): void
    {
        $subReasons = [
            'suspended',
            'new_member',
            'below_neutral',
            'in_recovery',
            'cap_reached',
            'cooldown_active',
        ];

        foreach ($subReasons as $reason) {
            $svc = $this->service(decisions: [
                self::RECEIVER . ':receive_community' => CapabilityDecision::denied($reason, 'community_custody'),
            ]);
            $result = $svc->transfer(self::OWNER, self::GROUP, self::RECEIVER);

            self::assertSame('bcc_forbidden', $result['error'] ?? null, "reason {$reason}");
            // Wire: single generic reason — NEVER the specific sub-reason.
            self::assertSame(['reason' => 'receiver_ineligible'], $result['data'] ?? null, "reason {$reason}");
            self::assertSame(
                'This member can\'t receive a community right now.',
                $result['message'] ?? null,
                "reason {$reason}"
            );
            self::assertSame([], $svc->writerCalls, "reason {$reason}");
            self::assertSame([], $svc->custodyCalls, "reason {$reason}");

            // Server-side ONLY: the true sub-reason is logged for admins,
            // keyed to the parties — but it never rode the wire above.
            self::assertSame(
                [[self::GROUP, self::OWNER, self::RECEIVER, $reason]],
                $svc->receiverDeniedLog,
                "reason {$reason}"
            );

            // Locks acquired (re-read is inside them) then released even on
            // the denial return — receiver (inner) unwinds before group.
            self::assertSame(
                ['bcc_community_custody_' . self::RECEIVER, 'bcc_community_custody_group_' . self::GROUP],
                $svc->lockReleaseCalls,
                "reason {$reason}"
            );
        }
    }

    /**
     * A `member_readonly` receiver holds a membership row (step 5's
     * consent proxy passes) but is moderation-muted — custody must not
     * land on them. The denial collapses to the SAME generic
     * receiver_ineligible wire shape as a step-6 capability denial (the
     * giver never learns the member is read-only) while the true reason
     * is logged server-side.
     */
    public function testReadonlyReceiverCollapsesToGenericIneligible(): void
    {
        $svc = $this->service(
            statuses: [self::OWNER => 'member_owner', self::RECEIVER => 'member_readonly']
        );
        $result = $svc->transfer(self::OWNER, self::GROUP, self::RECEIVER);

        self::assertSame('bcc_forbidden', $result['error'] ?? null);
        self::assertSame(['reason' => 'receiver_ineligible'], $result['data'] ?? null);
        self::assertSame('This member can\'t receive a community right now.', $result['message'] ?? null);
        self::assertSame([], $svc->writerCalls);
        self::assertSame([], $svc->custodyCalls);
        self::assertSame(
            [[self::GROUP, self::OWNER, self::RECEIVER, 'member_readonly']],
            $svc->receiverDeniedLog
        );
        // Step-5 denial fires inside the group lock, before the receiver
        // lock is ever taken.
        self::assertSame(['bcc_community_custody_group_' . self::GROUP], $svc->lockAcquireCalls);
        self::assertSame(['bcc_community_custody_group_' . self::GROUP], $svc->lockReleaseCalls);
    }

    /**
     * §21.2 TOCTOU fail-closed: if the per-GROUP custody lock can't be
     * acquired (a concurrent same-group transfer holds it), the transfer
     * fails CLOSED with a retryable bcc_conflict (→ 409) rather than
     * proceeding unlocked. No write, no ledger, and — since acquire
     * failed before the try — no release, and the receiver lock is
     * never even attempted.
     */
    public function testGroupLockContentionFailsClosedWithConflict(): void
    {
        $svc    = $this->service(lockAcquirable: false);
        $result = $svc->transfer(self::OWNER, self::GROUP, self::RECEIVER);

        self::assertSame('bcc_conflict', $result['error'] ?? null);
        self::assertSame('Please retry.', $result['message'] ?? null);
        self::assertSame([], $svc->writerCalls);
        self::assertSame([], $svc->custodyCalls);
        self::assertSame([], $svc->auditCalls);
        self::assertSame(['bcc_community_custody_group_' . self::GROUP], $svc->lockAcquireCalls);
        self::assertSame([], $svc->lockReleaseCalls);
    }

    /**
     * Same fail-closed posture one layer in: the group lock acquires but
     * the per-RECEIVER lock is contended (a concurrent create/receive
     * holds it). Retryable bcc_conflict, no write — and the group lock
     * (already held) is still released on the way out.
     */
    public function testReceiverLockContentionFailsClosedWithConflict(): void
    {
        $svc    = $this->service(deniedLockKeys: ['bcc_community_custody_' . self::RECEIVER]);
        $result = $svc->transfer(self::OWNER, self::GROUP, self::RECEIVER);

        self::assertSame('bcc_conflict', $result['error'] ?? null);
        self::assertSame('Please retry.', $result['message'] ?? null);
        self::assertSame([], $svc->writerCalls);
        self::assertSame([], $svc->custodyCalls);
        self::assertSame(
            ['bcc_community_custody_group_' . self::GROUP, 'bcc_community_custody_' . self::RECEIVER],
            $svc->lockAcquireCalls
        );
        self::assertSame(['bcc_community_custody_group_' . self::GROUP], $svc->lockReleaseCalls);
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
    /** @var list<string> Advisory-lock keys acquire was CALLED with. */
    public array $lockAcquireCalls = [];
    /** @var list<string> Advisory-lock keys release was CALLED with. */
    public array $lockReleaseCalls = [];
    /** @var list<array{int, int, int, string}> Server-side receiver-denial log. */
    public array $receiverDeniedLog = [];

    /**
     * @param array<int, ?string> $statuses
     * @param array<string, CapabilityDecision> $decisions
     * @param list<string> $deniedLockKeys per-key contention script
     */
    public function __construct(
        private readonly array $statuses,
        private readonly ?GroupType $kind,
        private readonly bool $fakeUserExists,
        private readonly array $decisions,
        private readonly bool $writerOk,
        private readonly bool $lockAcquirable = true,
        private readonly array $deniedLockKeys = []
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

    protected function logReceiverDenied(int $groupId, int $fromUserId, int $toUserId, string $trueReason): void
    {
        $this->receiverDeniedLog[] = [$groupId, $fromUserId, $toUserId, $trueReason];
    }

    protected function acquireLock(string $key): bool
    {
        $this->lockAcquireCalls[] = $key;
        if (in_array($key, $this->deniedLockKeys, true)) {
            return false;
        }
        return $this->lockAcquirable;
    }

    protected function releaseLock(string $key): void
    {
        $this->lockReleaseCalls[] = $key;
    }
}
