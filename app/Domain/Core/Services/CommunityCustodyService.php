<?php
/**
 * CommunityCustodyService — orchestrates the §21.2 ownership transfer
 * of a User-kind (member-created) community (Rank redesign Phase 7).
 *
 * The endpoint (MyGroupsEndpoint::postTransfer) stays thin: auth +
 * throttle + envelope mapping. This service owns the gate ORDER:
 *
 *   1. viewer holds member_owner on an existing group (nonexistent
 *      group and non-member both collapse to bcc_not_found — the same
 *      no-existence-leak posture as PostsService::gateGroupPost; a
 *      MEMBER who isn't the owner gets bcc_forbidden, membership
 *      already proves the group's existence to them)
 *   2. group is User-kind (Halls / holder / delegator / system
 *      communities are infra- or gate-provisioned and untransferable)
 *   3. receiver is a real, distinct user
 *   4. giver passes can('transfer_community') (reason passthrough)
 *   5. receiver ALREADY holds an active membership row (anti-grief:
 *      an unsolicited transfer to a stranger would burn THEIR cap and
 *      re-arm THEIR cooldown — membership is the consent proxy)
 *   6. receiver passes can('receive_community') — evaluated FOR the
 *      receiver; ANY denial collapses to the SINGLE generic wire reason
 *      `receiver_ineligible` (privacy: never leak the target's
 *      suspension / rank / tier / recovery / cap / cooldown status). The
 *      true sub-reason is logged server-side for admin debugging only.
 *   7. PeepSoGroupWriter::transferOwnership (the sanctioned write path
 *      — peepso-write-guard: every BCC gate above runs first)
 *   8. TWO custody-ledger rows (giver 'transfer_out', receiver
 *      'receive') — both (re)arm the 30-day global cooldown — then the
 *      audit trail
 *
 * Steps 6–8 run inside a per-RECEIVER advisory lock (§21.2 TOCTOU
 * close): the cap/cooldown check-then-act must be atomic so two
 * tightly-timed transfers can't each pass the receiver's cap check
 * before either records. Contention fails CLOSED with a retryable
 * bcc_conflict rather than proceeding unlocked.
 *
 * Collaborators are reached through protected hooks (same subclass
 * pattern as CapabilityResolver) so CommunityCustodyServiceTest can
 * exercise the real gate order without WordPress or a DB.
 *
 * @package BCC\Trust\Core\Services
 * @since Rank redesign Phase 7 (2026-08-04)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\ValueObjects\CapabilityDecision;
use BCC\Trust\Core\ValueObjects\GroupType;

if (!defined('ABSPATH')) {
    exit;
}

class CommunityCustodyService
{
    /**
     * Transfer ownership of $groupId from $viewerId to $toUserId.
     *
     * @return array{ok: true, group_id: int, new_owner_id: int}
     *         |array{error: string, message: string, data?: array<string, mixed>}
     */
    public function transfer(int $viewerId, int $groupId, int $toUserId): array
    {
        // ── 1. viewer must own an existing group ─────────────────────
        $viewerStatus = $this->membershipStatus($viewerId, $groupId);
        if ($viewerStatus === null || strpos($viewerStatus, 'member') !== 0) {
            // Nonexistent group, non-member, and pending/banned rows all
            // collapse to the same 404 — no existence leak on secret
            // groups (mirrors gateGroupPost's posture).
            return ['error' => 'bcc_not_found', 'message' => 'Group not found.'];
        }
        if ($viewerStatus !== 'member_owner') {
            // An active member already sees the group — a 403 leaks
            // nothing they don't know.
            return [
                'error'   => 'bcc_forbidden',
                'message' => 'Only the community owner can transfer ownership.',
            ];
        }

        // ── 2. only User-kind (member-created) groups transfer ───────
        $kind = $this->groupKind($groupId);
        if ($kind === null) {
            return ['error' => 'bcc_not_found', 'message' => 'Group not found.'];
        }
        if ($kind !== GroupType::User) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Only member-created communities can be transferred.',
            ];
        }

        // ── 3. receiver must be a real, distinct user ────────────────
        if ($toUserId <= 0 || $toUserId === $viewerId) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Pick another member to transfer ownership to.',
            ];
        }
        if (!$this->userExists($toUserId)) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'A valid member is required.',
            ];
        }

        // ── 4. giver capability (suspension / New Member / recovery) ─
        $giverDecision = $this->capability($viewerId, 'transfer_community');
        if (!$giverDecision->isAllowed()) {
            return [
                'error'   => 'bcc_forbidden',
                'message' => self::giverDenyMessage($giverDecision->reason),
                'data'    => ['reason' => $giverDecision->reason],
            ];
        }

        // ── 5. receiver must already be an active member ─────────────
        $receiverStatus = $this->membershipStatus($toUserId, $groupId);
        if ($receiverStatus === null
            || strpos($receiverStatus, 'member') !== 0
            || $receiverStatus === 'member_owner'
        ) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'The new owner must already be a member of this community.',
            ];
        }

        // ── 6–8 under a per-RECEIVER advisory lock (§21.2 TOCTOU) ────
        // The cap + cooldown gate below is the RECEIVER's (create and
        // receive share resolveCustodyAcquisition). Serialize the
        // receiver's check-then-act so two tightly-timed transfers can't
        // each pass the cap check before either records — mirrors
        // postCreate's per-creator lock. The giver has no cap/cooldown
        // gate, so the lock is keyed on the RECEIVER. Contention fails
        // CLOSED (retryable bcc_conflict) rather than proceeding unlocked.
        $lockKey = self::custodyLockKey($toUserId);
        if (!$this->acquireLock($lockKey)) {
            return [
                'error'   => 'bcc_conflict',
                'message' => 'Please retry.',
            ];
        }

        try {
            // ── 6. receiver capability, evaluated FOR the receiver ───
            // Re-read INSIDE the lock so the cap/cooldown facts are the
            // ones we act on. A denial NEVER leaks the receiver's
            // specific status (suspension / rank / tier / recovery / cap
            // / cooldown): the wire reason collapses to the single
            // generic `receiver_ineligible`; the true sub-reason is
            // logged server-side for admin debugging only.
            $receiverDecision = $this->capability($toUserId, 'receive_community');
            if (!$receiverDecision->isAllowed()) {
                $this->logReceiverDenied($groupId, $viewerId, $toUserId, $receiverDecision->reason);
                return [
                    'error'   => 'bcc_forbidden',
                    'message' => self::receiverDenyMessage(),
                    'data'    => ['reason' => 'receiver_ineligible'],
                ];
            }

            // ── 7. the sanctioned write path ─────────────────────────
            if (!$this->writerTransfer($groupId, $viewerId, $toUserId)) {
                return [
                    'error'   => 'bcc_internal_error',
                    'message' => 'Could not transfer the community. Try again in a moment.',
                ];
            }

            // ── 8. custody ledger (both cooldowns re-arm) + audit ────
            $this->recordCustody($viewerId, $groupId, 'transfer_out', $toUserId);
            $this->recordCustody($toUserId, $groupId, 'receive', $viewerId);
            $this->audit($groupId, $viewerId, $toUserId);

            return [
                'ok'           => true,
                'group_id'     => $groupId,
                'new_owner_id' => $toUserId,
            ];
        } finally {
            $this->releaseLock($lockKey);
        }
    }

    /**
     * Plain state descriptions per giver deny reason — no cadence
     * pressure, no nudges.
     */
    private static function giverDenyMessage(string $reason): string
    {
        return match ($reason) {
            'suspended'   => 'Your account is suspended.',
            'new_member'  => 'Reach Apprentice rank to transfer a community.',
            'in_recovery' => 'Community actions are paused while your rank is in recovery.',
            default       => 'You can\'t transfer this community right now.',
        };
    }

    /**
     * The SINGLE generic receiver-denial message. Privacy (wallet-privacy
     * P0 precedent): the initiating owner must NEVER learn the transfer
     * target's specific status — suspension, rank, tier, recovery,
     * ownership cap, or cooldown. All six receive_community sub-reasons
     * collapse to this one sentence beside the `receiver_ineligible` wire
     * reason; the true sub-reason is logged server-side only
     * (logReceiverDenied).
     */
    private static function receiverDenyMessage(): string
    {
        return 'This member can\'t receive a community right now.';
    }

    // ── collaborator hooks (overridden by CommunityCustodyServiceTest) ──

    protected function membershipStatus(int $userId, int $groupId): ?string
    {
        return \BCC\Core\Repositories\PeepSoGroupRepository::getMembershipStatus($userId, $groupId);
    }

    /** Null = group post missing / not a peepso-group. */
    protected function groupKind(int $groupId): ?GroupType
    {
        $context = \BCC\Trust\Core\Plugin::instance()->groupContextResolver()->forGroup($groupId);
        return $context?->type;
    }

    protected function userExists(int $userId): bool
    {
        return get_userdata($userId) !== false;
    }

    protected function capability(int $userId, string $key): CapabilityDecision
    {
        return \BCC\Trust\Core\Plugin::instance()->capabilityResolver()->can($userId, $key);
    }

    protected function writerTransfer(int $groupId, int $fromUserId, int $toUserId): bool
    {
        return \BCC\Core\PeepSo\PeepSoGroupWriter::transferOwnership($groupId, $fromUserId, $toUserId);
    }

    /**
     * Ledger write is non-fatal for the transfer itself (the PeepSo
     * write already landed and cannot be cleanly rolled back), but a
     * failure means a cooldown did NOT arm — error-log loudly.
     */
    protected function recordCustody(int $userId, int $groupId, string $event, ?int $counterpartyUserId): void
    {
        $ok = \BCC\Trust\Core\Plugin::instance()
            ->communityOwnershipLogRepository()
            ->record($userId, $groupId, $event, $counterpartyUserId);
        if (!$ok) {
            \BCC\Core\Log\Logger::error('[bcc-trust] custody ledger write failed — cooldown NOT armed', [
                'user_id'  => $userId,
                'group_id' => $groupId,
                'event'    => $event,
            ]);
        }
    }

    protected function audit(int $groupId, int $fromUserId, int $toUserId): void
    {
        \BCC\Trust\Core\Security\AuditLogger::log('group_ownership_transferred', $groupId, [
            'from' => $fromUserId,
            'to'   => $toUserId,
        ], 'group', $fromUserId);
    }

    /**
     * Server-side-only record of WHY a receiver was ineligible. The true
     * sub-reason (suspended / new_member / below_neutral / in_recovery /
     * cap_reached / cooldown_active) is kept for admin debugging but MUST
     * NEVER reach the wire — the response collapses to the generic
     * `receiver_ineligible` (privacy: the owner must not learn the
     * target's suspension / rank / tier / cooldown / cap status).
     */
    protected function logReceiverDenied(int $groupId, int $fromUserId, int $toUserId, string $trueReason): void
    {
        \BCC\Core\Log\Logger::info('[bcc-trust] community transfer denied — receiver ineligible', [
            'group_id'    => $groupId,
            'from'        => $fromUserId,
            'to'          => $toUserId,
            'true_reason' => $trueReason,
        ]);
    }

    // ── §21.2 TOCTOU advisory lock (mirrors DisputeRepository) ──────────

    /**
     * Per-user advisory-lock key namespacing the custody critical section.
     * Well under MySQL's 64-char GET_LOCK name limit.
     */
    private static function custodyLockKey(int $userId): string
    {
        return 'bcc_community_custody_' . $userId;
    }

    /**
     * Acquire the per-user custody advisory lock (non-blocking, timeout 0).
     * Routed through a protected hook so CommunityCustodyServiceTest can
     * simulate contention without a live MySQL session. Fails CLOSED when
     * the bcc-core primitive is unavailable — the cap/cooldown critical
     * section never runs unlocked. NULL-vs-0 handling lives inside
     * AdvisoryLock::acquire (driver error and held-elsewhere both → false).
     */
    protected function acquireLock(string $key): bool
    {
        if (!class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            return false;
        }
        return \BCC\Core\DB\AdvisoryLock::acquire($key, 0);
    }

    /** Release the custody advisory lock (safe no-op if not held). */
    protected function releaseLock(string $key): void
    {
        if (class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            \BCC\Core\DB\AdvisoryLock::release($key);
        }
    }
}
