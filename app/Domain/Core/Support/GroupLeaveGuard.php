<?php
/**
 * GroupLeaveGuard — the one place the three leave routes decide WHY a leave
 * cannot happen (MyGroupsEndpoint, HolderGroupsEndpoint,
 * ValidatorGroupsEndpoint).
 *
 * They used to map ANY PeepSoGroupWriter::leave() false to 403 "Owners cannot
 * leave their own community". Since bcc-core #112, leave() also returns false
 * when its per-(user, group) lock is busy, when the membership read failed, or
 * when PeepSo is absent, so an ordinary member was told they were the owner.
 *
 * Now the membership is read FIRST, fail-closed:
 *   - read failed     → 503 (never guessed as "not a member" or "owner")
 *   - member_owner    → the owner 403, and leave() is not called
 *   - anything else   → the caller calls leave(); a false from it is 503
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

use BCC\Core\Repositories\PeepSoGroupRepository;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupLeaveGuard
{
    public const OWNER_MESSAGE = 'Owners cannot leave their own community. Hand off ownership or delete the group first.';

    /**
     * Read the caller's membership before a leave.
     *
     * Returns [refusal, status]: a non-null refusal is the response to send
     * as is (nothing was written); otherwise status is the row's
     * gm_user_status, null when there is no row (callers use it to tell a
     * real leave from an idempotent one).
     *
     * @return array{0: WP_REST_Response|null, 1: string|null}
     */
    public static function precheck(int $userId, int $groupId): array
    {
        $status = PeepSoGroupRepository::getMembershipStatusOrFail($userId, $groupId);
        if ($status === false) {
            return [self::unavailable(), null];
        }
        if ($status === 'member_owner') {
            return [ApiResponse::error('bcc_permission_denied', self::OWNER_MESSAGE, 403), null];
        }
        return [null, $status];
    }

    /** leave() returned false for a non-owner: busy lock, failed write, PeepSo absent. */
    public static function unavailable(): WP_REST_Response
    {
        return ApiResponse::error('bcc_unavailable', 'Group membership service is unavailable.', 503);
    }
}
