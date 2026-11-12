<?php
/**
 * GroupJoinRequestService — F-272: getting into closed and secret groups.
 *
 *   closed → a member asks to join (`pending_admin`); an owner or manager
 *            approves (→ member) or denies (silent, D2; 7-day re-request
 *            cooldown, D3).
 *   closed + secret → an owner or manager invites (`pending_user`, D1:
 *            owners and managers only); the invitee accepts (→ member) or
 *            declines.
 *
 * The writes go through bcc-core PeepSoGroupWriter, which acts only from the
 * row state each transition expects: a banned row is never touched (refused),
 * a failed read refuses, and a raced transition is a conflict. This class adds
 * authorization, the account gates, notifications and the audit trail, and
 * serializes each (user, group) pair under an advisory lock.
 *
 * Plain user/system groups only. Holder groups, Halls and delegator
 * communities have their own on-chain gates and never come through here.
 *
 * Every method returns either ['ok' => true, 'data' => [...]] or
 * ['error' => code, 'message' => copy, 'status' => http status]; the endpoint
 * maps it mechanically. A secret group is 404 to anyone who is neither an
 * active member nor its invitee, exactly like a missing group (F-47).
 *
 * @package BCC\Trust\Core\Services
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\PeepSo\PeepSoGroupWriter;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Support\MemberSummaryPrefetcher;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type Result array{ok: true, data: array<string, mixed>}|array{error: string, message: string, status: int}
 */
final class GroupJoinRequestService
{
    /** D3: after a deny, the same member may ask again after this long. */
    public const DENY_COOLDOWN_SECONDS = 7 * DAY_IN_SECONDS;

    /** Outstanding invitations one group may hold at once (spam bound). */
    public const MAX_PENDING_INVITES_PER_GROUP = 200;

    public const MAX_LIMIT     = 100;
    public const DEFAULT_LIMIT = 24;

    /** usermeta: group id → unix time of the last denied request. */
    private const DENIED_META = 'bcc_group_join_denied';

    private const ADMIN_ROLES = ['member_owner', 'member_manager'];

    // ── requester ───────────────────────────────────────────────────────

    /** @return Result */
    public function requestJoin(int $userId, int $groupId): array
    {
        $gate = $this->accountGate($userId);
        if ($gate !== null) {
            return $gate;
        }
        $ctx = $this->plainGroup($userId, $groupId);
        if (!$ctx instanceof GroupContext) {
            return $ctx;
        }
        if ($ctx->privacy !== PeepSoPrivacy::Closed) {
            return self::error('bcc_invalid_request', 'This community does not take join requests.', 400);
        }
        if ($this->inDenyCooldown($userId, $groupId)) {
            return self::error('bcc_permission_denied', "You can't ask to join this community again yet.", 403);
        }

        $outcome = $this->locked($userId, $groupId, static fn (): string => PeepSoGroupWriter::requestJoin($userId, $groupId));
        if ($outcome !== PeepSoGroupWriter::OUTCOME_DONE) {
            return self::fromOutcome($outcome, 'You are already a member, invited, or waiting on a request here.');
        }

        Plugin::instance()->notificationDispatcher()->onGroupJoinRequested($userId, $groupId, self::adminIds($groupId));
        AuditLogger::log('group_join_requested', $groupId, [], 'group', $userId);

        return self::ok(['status' => 'requested', 'group_id' => $groupId]);
    }

    /** @return Result */
    public function cancelRequest(int $userId, int $groupId): array
    {
        if ($userId <= 0) {
            return self::error('bcc_unauthorized', 'Sign in required.', 401);
        }
        $ctx = $this->plainGroup($userId, $groupId);
        if (!$ctx instanceof GroupContext) {
            return $ctx;
        }

        $outcome = $this->locked($userId, $groupId, static fn (): string => PeepSoGroupWriter::cancelJoinRequest($userId, $groupId));
        if ($outcome !== PeepSoGroupWriter::OUTCOME_DONE) {
            return self::fromOutcome($outcome, 'There is no pending request to cancel.');
        }
        return self::ok(['status' => 'none', 'group_id' => $groupId]);
    }

    // ── owner / manager ─────────────────────────────────────────────────

    /** @return Result */
    public function approve(int $actorId, int $userId, int $groupId): array
    {
        $ctx = $this->adminGroup($actorId, $groupId);
        if (!$ctx instanceof GroupContext) {
            return $ctx;
        }
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($userId, false)) {
            return self::error('bcc_permission_denied', 'This account is suspended and cannot join.', 403);
        }

        $outcome = $this->locked($userId, $groupId, static fn (): string => PeepSoGroupWriter::approveJoinRequest($userId, $groupId, $actorId));
        if ($outcome !== PeepSoGroupWriter::OUTCOME_DONE) {
            return self::fromOutcome($outcome, 'That request is no longer pending.');
        }

        Plugin::instance()->notificationDispatcher()->onGroupJoinApproved($actorId, $userId, $groupId);
        AuditLogger::log('group_join_approved', $groupId, ['user_id' => $userId], 'group', $actorId);

        return self::ok(['status' => 'member', 'group_id' => $groupId, 'user_id' => $userId]);
    }

    /**
     * D2: silent. Arms the D3 re-request cooldown.
     *
     * @return Result
     */
    public function deny(int $actorId, int $userId, int $groupId): array
    {
        $ctx = $this->adminGroup($actorId, $groupId);
        if (!$ctx instanceof GroupContext) {
            return $ctx;
        }

        $outcome = $this->locked($userId, $groupId, static fn (): string => PeepSoGroupWriter::denyJoinRequest($userId, $groupId));
        if ($outcome !== PeepSoGroupWriter::OUTCOME_DONE) {
            return self::fromOutcome($outcome, 'That request is no longer pending.');
        }

        $this->recordDenial($userId, $groupId);
        AuditLogger::log('group_join_denied', $groupId, ['user_id' => $userId], 'group', $actorId);

        return self::ok(['status' => 'none', 'group_id' => $groupId, 'user_id' => $userId]);
    }

    /** @return Result */
    public function invite(int $actorId, int $inviteeId, int $groupId): array
    {
        $ctx = $this->adminGroup($actorId, $groupId);
        if (!$ctx instanceof GroupContext) {
            return $ctx;
        }
        if ($ctx->privacy === PeepSoPrivacy::Open) {
            return self::error('bcc_invalid_request', 'Anyone can join an open community; no invitation needed.', 400);
        }
        if ($inviteeId === $actorId) {
            return self::error('bcc_invalid_request', 'You are already in this community.', 400);
        }
        // Unknown and suspended accounts answer alike: an invite form must
        // not become a probe for account state.
        if ($inviteeId <= 0 || get_userdata($inviteeId) === false
            || !\BCC\Core\Permissions\Permissions::is_not_suspended($inviteeId, false)) {
            return self::error('bcc_invalid_request', 'Member not found.', 404);
        }
        $openInvites = PeepSoGroupRepository::countPendingMembers($groupId, PeepSoGroupRepository::PENDING_INVITE);
        if ($openInvites === null) {
            return self::unavailable();
        }
        if ($openInvites >= self::MAX_PENDING_INVITES_PER_GROUP) {
            return self::error('bcc_rate_limited', 'This community has too many open invitations. Revoke some first.', 429);
        }

        $outcome = $this->locked($inviteeId, $groupId, static fn (): string => PeepSoGroupWriter::invite($inviteeId, $groupId, $actorId));
        if ($outcome === PeepSoGroupWriter::OUTCOME_REFUSED) {
            return self::error('bcc_permission_denied', "This member can't be invited.", 403);
        }
        if ($outcome !== PeepSoGroupWriter::OUTCOME_DONE) {
            return self::fromOutcome($outcome, 'This member is already in, already invited, or has asked to join (approve their request instead).');
        }

        Plugin::instance()->notificationDispatcher()->onGroupInvited($actorId, $inviteeId, $groupId);
        AuditLogger::log('group_invite_sent', $groupId, ['user_id' => $inviteeId], 'group', $actorId);

        return self::ok(['status' => 'invited', 'group_id' => $groupId, 'user_id' => $inviteeId]);
    }

    /** @return Result */
    public function revokeInvite(int $actorId, int $inviteeId, int $groupId): array
    {
        $ctx = $this->adminGroup($actorId, $groupId);
        if (!$ctx instanceof GroupContext) {
            return $ctx;
        }

        $outcome = $this->locked($inviteeId, $groupId, static fn (): string => PeepSoGroupWriter::revokeInvite($inviteeId, $groupId));
        if ($outcome !== PeepSoGroupWriter::OUTCOME_DONE) {
            return self::fromOutcome($outcome, 'That invitation is no longer pending.');
        }
        AuditLogger::log('group_invite_revoked', $groupId, ['user_id' => $inviteeId], 'group', $actorId);

        return self::ok(['status' => 'none', 'group_id' => $groupId, 'user_id' => $inviteeId]);
    }

    // ── invitee ─────────────────────────────────────────────────────────

    /** @return Result */
    public function acceptInvite(int $userId, int $groupId): array
    {
        $gate = $this->accountGate($userId);
        if ($gate !== null) {
            return $gate;
        }
        $ctx = $this->invitedGroup($userId, $groupId);
        if (!$ctx instanceof GroupContext) {
            return $ctx;
        }

        $outcome = $this->locked($userId, $groupId, static fn (): string => PeepSoGroupWriter::acceptInvite($userId, $groupId));
        if ($outcome !== PeepSoGroupWriter::OUTCOME_DONE) {
            return self::fromOutcome($outcome, 'That invitation is no longer pending.');
        }
        AuditLogger::log('group_invite_accepted', $groupId, [], 'group', $userId);

        return self::ok(['joined' => true, 'status' => 'member', 'group_id' => $groupId]);
    }

    /** @return Result */
    public function declineInvite(int $userId, int $groupId): array
    {
        if ($userId <= 0) {
            return self::error('bcc_unauthorized', 'Sign in required.', 401);
        }
        $ctx = $this->invitedGroup($userId, $groupId);
        if (!$ctx instanceof GroupContext) {
            return $ctx;
        }

        $outcome = $this->locked($userId, $groupId, static fn (): string => PeepSoGroupWriter::declineInvite($userId, $groupId));
        if ($outcome !== PeepSoGroupWriter::OUTCOME_DONE) {
            return self::fromOutcome($outcome, 'That invitation is no longer pending.');
        }
        return self::ok(['status' => 'none', 'group_id' => $groupId]);
    }

    // ── lists ───────────────────────────────────────────────────────────

    /**
     * An owner's/manager's view of a group's open requests or invitations.
     *
     * @param PeepSoGroupRepository::PENDING_* $status
     * @return Result
     */
    public function listPending(int $actorId, int $groupId, string $status, int $offset, int $limit): array
    {
        $ctx = $this->adminGroup($actorId, $groupId);
        if (!$ctx instanceof GroupContext) {
            return $ctx;
        }
        $offset = max(0, $offset);
        $limit  = max(1, min(self::MAX_LIMIT, $limit));

        $rows  = PeepSoGroupRepository::listPendingMembers($groupId, $status, $offset, $limit);
        $total = PeepSoGroupRepository::countPendingMembers($groupId, $status);
        // Fail-closed: a failed read must not show "nobody is waiting".
        if ($rows === null || $total === null) {
            return self::unavailable();
        }

        $userIds = [];
        foreach ($rows as $row) {
            if ((int) $row->user_id > 0) {
                $userIds[] = (int) $row->user_id;
            }
        }
        $items = [];
        if ($userIds !== []) {
            $prefetched = MemberSummaryPrefetcher::primeFor($userIds);
            $userView   = Plugin::instance()->userViewService();
            foreach ($rows as $row) {
                $summary = $userView->getSummary((int) $row->user_id, $actorId, $prefetched);
                if ($summary === null) {
                    continue; // deleted account; the count self-heals
                }
                $items[] = array_merge($summary, [
                    ($status === PeepSoGroupRepository::PENDING_REQUEST ? 'requested_at' : 'invited_at')
                        => self::isoFromEpoch((int) $row->requested_utc),
                ]);
            }
        }

        return self::ok([
            'items'      => $items,
            'pagination' => [
                'offset'   => $offset,
                'limit'    => $limit,
                'total'    => $total,
                'has_more' => ($offset + count($rows)) < $total,
            ],
        ]);
    }

    /**
     * The viewer's open invitations — the only way an invitee finds a secret
     * group. Groups that no longer resolve, or are not plain groups, drop out.
     *
     * @return Result
     */
    public function listMyInvites(int $userId, int $offset, int $limit): array
    {
        if ($userId <= 0) {
            return self::error('bcc_unauthorized', 'Sign in required.', 401);
        }
        $offset = max(0, $offset);
        $limit  = max(1, min(self::MAX_LIMIT, $limit));

        $rows     = PeepSoGroupRepository::listUserPendingInvites($userId, $offset, $limit + 1);
        if ($rows === null) {
            return self::unavailable();
        }
        $hasMore  = count($rows) > $limit;
        $rows     = array_slice($rows, 0, $limit);
        $groupIds = array_map(static fn (object $r): int => (int) $r->group_id, $rows);
        $groups   = $groupIds === [] ? [] : PeepSoGroupRepository::findManyByIds($groupIds);
        $resolver = Plugin::instance()->groupContextResolver();

        $items = [];
        foreach ($rows as $row) {
            $groupId = (int) $row->group_id;
            $group   = $groups[$groupId] ?? null;
            $ctx     = $group !== null ? $resolver->forGroup($groupId) : null;
            if ($group === null || $ctx === null || !self::isPlainType($ctx)) {
                continue;
            }
            $inviterId = (int) ($row->invited_by_id ?? 0);
            $inviter   = $inviterId > 0 ? get_userdata($inviterId) : false;
            $items[] = [
                'group' => [
                    'id'      => $groupId,
                    'slug'    => (string) $group->post_name,
                    'name'    => (string) $group->post_title,
                    'privacy' => $ctx->privacy->value,
                    'links'   => ['self' => \BCC\Trust\Core\Support\CardUrlMap::groupUrl($ctx->type->value, (string) $group->post_name)],
                ],
                'invited_by' => $inviter !== false ? \BCC\Trust\Core\Support\PublicIdentity::forUser($inviter) : null,
                'invited_at' => self::isoFromEpoch((int) $row->invited_utc),
            ];
        }

        return self::ok([
            'items'      => $items,
            'pagination' => ['offset' => $offset, 'limit' => $limit, 'has_more' => $hasMore],
        ]);
    }

    // ── view-model support (GroupsService) ──────────────────────────────

    /** Whether a denied request still blocks a new one (D3). */
    public function inDenyCooldown(int $userId, int $groupId): bool
    {
        $deniedAt = $this->denials($userId)[$groupId] ?? 0;
        return $deniedAt > 0 && (time() - $deniedAt) < self::DENY_COOLDOWN_SECONDS;
    }

    /** Owner, manager, or site admin (moderators may not decide membership, D1). */
    public static function isGroupAdmin(int $actorId, int $groupId): bool
    {
        if ($actorId <= 0 || $groupId <= 0) {
            return false;
        }
        if (user_can($actorId, 'manage_options')) {
            return true;
        }
        $status = PeepSoGroupRepository::getMembershipStatus($actorId, $groupId);
        return in_array($status, self::ADMIN_ROLES, true);
    }

    // ── internals ───────────────────────────────────────────────────────

    /** @return array{error: string, message: string, status: int}|null */
    private function accountGate(int $userId): ?array
    {
        if ($userId <= 0) {
            return self::error('bcc_unauthorized', 'Sign in required.', 401);
        }
        // A suspended account must not walk into a community through any
        // door (same rule and admin-bypass-off as MyGroupsEndpoint::postJoin).
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($userId, false)) {
            return self::error('bcc_forbidden', 'Your account is suspended.', 403);
        }
        return null;
    }

    /**
     * A plain group the caller may see (member or not): the same 404 rule as
     * the rest of the group surface (secret + non-member → 404).
     *
     * @return GroupContext|array{error: string, message: string, status: int}
     */
    private function plainGroup(int $userId, int $groupId): GroupContext|array
    {
        $access = Plugin::instance()->groupsService()->resolveGroupAccess($userId, $groupId);
        if ($access === null) {
            return self::error('bcc_invalid_request', 'Group not found.', 404);
        }
        if (!self::isPlainType($access['ctx'])) {
            return self::error('bcc_invalid_request', 'This community has its own join flow.', 400);
        }
        return $access['ctx'];
    }

    /**
     * A plain group whose admins include the actor. A non-admin gets 403 on a
     * group they can see and 404 on one they can't (secret).
     *
     * @return GroupContext|array{error: string, message: string, status: int}
     */
    private function adminGroup(int $actorId, int $groupId): GroupContext|array
    {
        // A suspended owner/manager decides nothing either.
        $gate = $this->accountGate($actorId);
        if ($gate !== null) {
            return $gate;
        }
        $ctx = $this->plainGroup($actorId, $groupId);
        if (!$ctx instanceof GroupContext) {
            return $ctx;
        }
        if (!self::isGroupAdmin($actorId, $groupId)) {
            return self::error('bcc_permission_denied', 'Only the owner or a manager can do that.', 403);
        }
        return $ctx;
    }

    /**
     * A plain group the caller is invited to. resolveGroupAccess() hides a
     * secret group from non-members, so the invitation itself is what reveals
     * it: no invitation → 404, whatever the privacy.
     *
     * @return GroupContext|array{error: string, message: string, status: int}
     */
    private function invitedGroup(int $userId, int $groupId): GroupContext|array
    {
        $ctx = $groupId > 0 ? Plugin::instance()->groupContextResolver()->forGroup($groupId) : null;
        $status = $ctx !== null ? PeepSoGroupRepository::getMembershipStatusOrFail($userId, $groupId) : null;
        if ($status === false) {
            return self::unavailable();
        }
        if ($ctx === null || $status !== PeepSoGroupRepository::PENDING_INVITE || !self::isPlainType($ctx)) {
            return self::error('bcc_invalid_request', 'Invitation not found.', 404);
        }
        return $ctx;
    }

    private static function isPlainType(GroupContext $ctx): bool
    {
        return $ctx->type !== GroupType::Nft
            && $ctx->type !== GroupType::Hall
            && $ctx->type !== GroupType::Validator;
    }

    /**
     * Serialize transitions on one (user, group) pair. Contention fails
     * closed as a retryable 409 rather than racing (same posture as the
     * custody lock).
     *
     * @param callable(): string $write
     * @return string a PeepSoGroupWriter::OUTCOME_* or 'busy'
     */
    private function locked(int $userId, int $groupId, callable $write): string
    {
        $key = 'bcc_gjr_' . $groupId . '_' . $userId;
        if (!\BCC\Core\DB\AdvisoryLock::acquire($key, 0)) {
            return 'busy';
        }
        try {
            return $write();
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($key);
        }
    }

    /** @return array{error: string, message: string, status: int} */
    private static function fromOutcome(string $outcome, string $conflictMessage): array
    {
        return match ($outcome) {
            PeepSoGroupWriter::OUTCOME_CONFLICT => self::error('bcc_conflict', $conflictMessage, 409),
            // A ban (or a row that blocks this) — no detail beyond "can't".
            PeepSoGroupWriter::OUTCOME_REFUSED  => self::error('bcc_permission_denied', "You can't join this community.", 403),
            'busy'                              => self::error('bcc_conflict', 'Please retry.', 409),
            default                             => self::unavailable(),
        };
    }

    /** @return array{error: string, message: string, status: int} */
    private static function unavailable(): array
    {
        return self::error('bcc_unavailable', 'Group membership service is unavailable.', 503);
    }

    /** @return list<int> owners and managers, for the request bell */
    private static function adminIds(int $groupId): array
    {
        // listGroupMembers sorts owner → manager/moderator → member, so the
        // first page holds every admin of any realistic group.
        $ids = [];
        foreach (PeepSoGroupRepository::listGroupMembers($groupId, 0, 100) as $row) {
            if (in_array((string) $row->role, self::ADMIN_ROLES, true)) {
                $ids[] = (int) $row->user_id;
            }
        }
        return $ids;
    }

    /** @return array<int, int> group id → denied-at */
    private function denials(int $userId): array
    {
        $raw = $userId > 0 ? get_user_meta($userId, self::DENIED_META, true) : [];
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $groupId => $at) {
            if ((int) $groupId > 0 && (int) $at > 0) {
                $out[(int) $groupId] = (int) $at;
            }
        }
        return $out;
    }

    private function recordDenial(int $userId, int $groupId): void
    {
        $now     = time();
        $denials = array_filter(
            $this->denials($userId),
            static fn (int $at): bool => ($now - $at) < self::DENY_COOLDOWN_SECONDS
        );
        $denials[$groupId] = $now;
        update_user_meta($userId, self::DENIED_META, $denials);
    }

    private static function isoFromEpoch(int $epoch): ?string
    {
        return $epoch > 0 ? gmdate('Y-m-d\TH:i:s\Z', $epoch) : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok: true, data: array<string, mixed>}
     */
    private static function ok(array $data): array
    {
        return ['ok' => true, 'data' => $data];
    }

    /** @return array{error: string, message: string, status: int} */
    private static function error(string $code, string $message, int $status): array
    {
        return ['error' => $code, 'message' => $message, 'status' => $status];
    }
}
