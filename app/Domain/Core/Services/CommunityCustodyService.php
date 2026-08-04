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
 *      receiver; deny reasons surface prefixed `receiver_*`
 *   7. PeepSoGroupWriter::transferOwnership (the sanctioned write path
 *      — peepso-write-guard: every BCC gate above runs first)
 *   8. TWO custody-ledger rows (giver 'transfer_out', receiver
 *      'receive') — both (re)arm the 30-day global cooldown — then the
 *      audit trail
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

        // ── 6. receiver capability, evaluated FOR the receiver ───────
        $receiverDecision = $this->capability($toUserId, 'receive_community');
        if (!$receiverDecision->isAllowed()) {
            $reason = 'receiver_' . $receiverDecision->reason;
            return [
                'error'   => 'bcc_forbidden',
                'message' => self::receiverDenyMessage($receiverDecision->reason),
                'data'    => ['reason' => $reason],
            ];
        }

        // ── 7. the sanctioned write path ─────────────────────────────
        if (!$this->writerTransfer($groupId, $viewerId, $toUserId)) {
            return [
                'error'   => 'bcc_internal_error',
                'message' => 'Could not transfer the community. Try again in a moment.',
            ];
        }

        // ── 8. custody ledger (both cooldowns re-arm) + audit ────────
        $this->recordCustody($viewerId, $groupId, 'transfer_out', $toUserId);
        $this->recordCustody($toUserId, $groupId, 'receive', $viewerId);
        $this->audit($groupId, $viewerId, $toUserId);

        return [
            'ok'           => true,
            'group_id'     => $groupId,
            'new_owner_id' => $toUserId,
        ];
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
     * Plain state descriptions per receiver deny reason. The wire
     * reason carries the `receiver_` prefix; the human copy names the
     * blocked party so the owner understands the transfer target — not
     * their own account — is the blocker.
     */
    private static function receiverDenyMessage(string $reason): string
    {
        return match ($reason) {
            'suspended'       => 'The new owner\'s account is suspended.',
            'new_member'      => 'The new owner must be at least an Apprentice.',
            'below_neutral'   => 'The new owner\'s standing must be Neutral or better.',
            'in_recovery'     => 'The new owner\'s community actions are paused during rank recovery.',
            'cap_reached'     => 'The new owner already owns the maximum number of communities for their rank.',
            'cooldown_active' => 'The new owner is inside the 30-day community cooldown.',
            default           => 'The new owner can\'t receive this community right now.',
        };
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
}
