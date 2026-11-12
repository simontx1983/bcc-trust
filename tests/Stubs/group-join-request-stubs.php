<?php
/**
 * Doubles for GroupJoinRequestServiceTest (F-272): the REAL
 * GroupJoinRequestsEndpoint, GroupJoinRequestService and
 * GroupsService::resolveGroupAccess(), with bcc-core (the writer, the
 * repository, the pair lock, Permissions) and the notification dispatcher
 * faked at their FQNs.
 *
 * Fixture ($GLOBALS['__bcc_gjr']):
 *   contexts     group_id => GroupContext (absent → no such group)
 *   memberships  user_id => group_id => gm_user_status
 *   suspended    list<int> suspended user ids
 *   site_admins  list<int> ids with manage_options
 *   users        list<int> ids that exist (get_userdata)
 *   outcome      what every writer door returns (default 'done')
 *   read_failed  getMembershipStatusOrFail returns false
 *   lists_fail   the pending list/count reads return null
 *   pending_invites  countPendingMembers(invite) value (default 0)
 *   lock_busy    the service's outer lock is busy
 *   writes       list<[door, user, group, extra, lockHeld]>
 *   notes        list<[event, ...args]>   audits list<[action, target]>
 *
 * The signed-in user is $GLOBALS['__bcc_susp_fixture']['user_id'].
 * Safe only inside #[RunTestsInSeparateProcesses] (fake FQNs). Required from
 * setUp(), never at file scope.
 */

declare(strict_types=1);

namespace {
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }

    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            /** @param array<string, mixed> $params */
            public function __construct(private array $params = [])
            {
            }

            /** @return mixed */
            public function get_param(string $key)
            {
                return $this->params[$key] ?? null;
            }
        }
    }

    if (!function_exists('get_userdata')) {
        /** @return object|false */
        function get_userdata(int $userId)
        {
            return in_array($userId, $GLOBALS['__bcc_gjr']['users'] ?? [], true)
                ? (object) ['ID' => $userId, 'user_login' => 'u' . $userId, 'display_name' => 'User ' . $userId]
                : false;
        }
    }

    if (!function_exists('user_can')) {
        function user_can(int $userId, string $cap): bool
        {
            return $cap === 'manage_options' && in_array($userId, $GLOBALS['__bcc_gjr']['site_admins'] ?? [], true);
        }
    }

    if (!function_exists('update_user_meta')) {
        /** @param mixed $value */
        function update_user_meta(int $userId, string $key, $value): bool
        {
            $GLOBALS['__bcc_susp_fixture']['user_meta'][$userId][$key] = $value;
            return true;
        }
    }
}

namespace BCC\Core\Permissions {
    // Declared before suspension-gate-stubs.php so this per-user one wins.
    if (!class_exists(Permissions::class, false)) {
        class Permissions
        {
            public static function is_not_suspended(?int $user_id = null, bool $allowAdminBypass = true): bool
            {
                $GLOBALS['__bcc_gjr']['bypass_args'][] = $allowAdminBypass;
                return !in_array((int) $user_id, $GLOBALS['__bcc_gjr']['suspended'] ?? [], true);
            }
        }
    }
}

namespace {
    require_once __DIR__ . '/suspension-gate-stubs.php';
}

namespace BCC\Trust\Core\Services {
    if (!class_exists(GroupContextResolver::class, false)) {
        final class GroupContextResolver
        {
            public function forGroup(int $groupId): ?\BCC\Trust\Core\ValueObjects\GroupContext
            {
                return $GLOBALS['__bcc_gjr']['contexts'][$groupId] ?? null;
            }
        }
    }

    if (!class_exists(NotificationDispatcher::class, false)) {
        final class NotificationDispatcher
        {
            /** @param list<int> $recipientIds */
            public function onGroupJoinRequested(int $requesterId, int $groupId, array $recipientIds): void
            {
                $GLOBALS['__bcc_gjr']['notes'][] = ['requested', $requesterId, $groupId, $recipientIds];
            }

            public function onGroupJoinApproved(int $approverId, int $requesterId, int $groupId): void
            {
                $GLOBALS['__bcc_gjr']['notes'][] = ['approved', $approverId, $requesterId, $groupId];
            }

            public function onGroupInvited(int $inviterId, int $inviteeId, int $groupId): void
            {
                $GLOBALS['__bcc_gjr']['notes'][] = ['invited', $inviterId, $inviteeId, $groupId];
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    // ApiResponse::ok() → ReactionTypeRegistry::all() reads one option.
    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        /**
         * @param  mixed $default
         * @return mixed
         */
        function get_option(string $key, $default = false)
        {
            return $default;
        }
    }
}

namespace BCC\Trust\Core\Security {
    if (!class_exists(AuditLogger::class, false)) {
        class AuditLogger
        {
            /** @param array<string, mixed> $meta */
            public static function log(string $action, ?int $targetId = null, array $meta = [], ?string $targetType = null, ?int $userId = null): void
            {
                $GLOBALS['__bcc_gjr']['audits'][] = [$action, $targetId];
            }
        }
    }
}

namespace BCC\Core\DB {
    if (!class_exists(AdvisoryLock::class, false)) {
        class AdvisoryLock
        {
            public static function acquire(string $key, int $timeout = 0): bool
            {
                if (!empty($GLOBALS['__bcc_gjr']['lock_busy'])) {
                    return false;
                }
                $GLOBALS['__bcc_gjr']['held'][$key] = true;
                return true;
            }

            public static function release(string $key): bool
            {
                unset($GLOBALS['__bcc_gjr']['held'][$key]);
                return true;
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoGroupRepository::class, false)) {
        final class PeepSoGroupRepository
        {
            public const PENDING_REQUEST = 'pending_admin';
            public const PENDING_INVITE  = 'pending_user';

            public static function getMembershipStatus(int $userId, int $groupId): ?string
            {
                return $GLOBALS['__bcc_gjr']['memberships'][$userId][$groupId] ?? null;
            }

            public static function getMembershipStatusOrFail(int $userId, int $groupId): string|null|false
            {
                if (!empty($GLOBALS['__bcc_gjr']['read_failed'])) {
                    return false;
                }
                return self::getMembershipStatus($userId, $groupId);
            }

            public static function countPendingMembers(int $groupId, string $status): ?int
            {
                if (!empty($GLOBALS['__bcc_gjr']['lists_fail'])) {
                    return null;
                }
                return $status === self::PENDING_INVITE ? (int) ($GLOBALS['__bcc_gjr']['pending_invites'] ?? 0) : 0;
            }

            /** @return list<object>|null */
            public static function listPendingMembers(int $groupId, string $status, int $offset, int $limit): ?array
            {
                return !empty($GLOBALS['__bcc_gjr']['lists_fail']) ? null : [];
            }

            /** @return list<object>|null */
            public static function listUserPendingInvites(int $userId, int $offset, int $limit): ?array
            {
                return !empty($GLOBALS['__bcc_gjr']['lists_fail']) ? null : [];
            }

            /** @return list<object> owners/managers etc. of the group, from memberships */
            public static function listGroupMembers(int $groupId, int $offset, int $limit): array
            {
                if (!empty($GLOBALS['__bcc_gjr']['roster_fails'])) {
                    return []; // the real read answers [] when it fails
                }
                $out = [];
                foreach ($GLOBALS['__bcc_gjr']['memberships'] ?? [] as $userId => $groups) {
                    $status = $groups[$groupId] ?? null;
                    if (is_string($status) && str_starts_with($status, 'member')) {
                        $out[] = (object) ['user_id' => (string) $userId, 'role' => $status];
                    }
                }
                return $out;
            }

            /** @return array<int, object> */
            public static function findManyByIds(array $groupIds): array
            {
                return [];
            }

            /** @return array<int, object> */
            public static function findUserMemberships(int $userId, array $groupIds): array
            {
                $out = [];
                foreach ($groupIds as $groupId) {
                    $status = $GLOBALS['__bcc_gjr']['memberships'][$userId][$groupId] ?? null;
                    if (is_string($status) && str_starts_with($status, 'member')) {
                        $out[$groupId] = (object) ['group_id' => (string) $groupId, 'user_status' => $status, 'gm_joined_utc' => '1700000000'];
                    }
                }
                return $out;
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoBlockRepository::class, false)) {
        /**
         * Fixture: blocks = list<[blocker, blocked]> (either direction counts),
         * block_unknown = list<int> users whose block read cannot run.
         */
        final class PeepSoBlockRepository
        {
            public static function isMutuallyBlockedOrUnknown(int $userA, int $userB): ?bool
            {
                $unknown = $GLOBALS['__bcc_gjr']['block_unknown'] ?? [];
                if (in_array($userA, $unknown, true) || in_array($userB, $unknown, true)) {
                    return null;
                }
                foreach ($GLOBALS['__bcc_gjr']['blocks'] ?? [] as [$a, $b]) {
                    if (($a === $userA && $b === $userB) || ($a === $userB && $b === $userA)) {
                        return true;
                    }
                }
                return false;
            }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(PeepSoGroupWriter::class, false)) {
        final class PeepSoGroupWriter
        {
            public const OUTCOME_DONE        = 'done';
            public const OUTCOME_CONFLICT    = 'conflict';
            public const OUTCOME_REFUSED     = 'refused';
            public const OUTCOME_UNAVAILABLE = 'unavailable';

            private static function door(string $door, int $userId, int $groupId, ?int $extra = null): string
            {
                $GLOBALS['__bcc_gjr']['writes'][] = [$door, $userId, $groupId, $extra, !empty($GLOBALS['__bcc_gjr']['held'])];
                return $GLOBALS['__bcc_gjr']['outcome'] ?? self::OUTCOME_DONE;
            }

            public static function requestJoin(int $userId, int $groupId): string { return self::door('requestJoin', $userId, $groupId); }
            public static function cancelJoinRequest(int $userId, int $groupId): string { return self::door('cancelJoinRequest', $userId, $groupId); }
            public static function approveJoinRequest(int $userId, int $groupId, int $actorId): string { return self::door('approveJoinRequest', $userId, $groupId, $actorId); }
            public static function denyJoinRequest(int $userId, int $groupId): string { return self::door('denyJoinRequest', $userId, $groupId); }
            public static function invite(int $userId, int $groupId, int $inviterId): string { return self::door('invite', $userId, $groupId, $inviterId); }
            public static function revokeInvite(int $userId, int $groupId): string { return self::door('revokeInvite', $userId, $groupId); }
            public static function acceptInvite(int $userId, int $groupId): string { return self::door('acceptInvite', $userId, $groupId); }
            public static function declineInvite(int $userId, int $groupId): string { return self::door('declineInvite', $userId, $groupId); }
        }
    }
}
