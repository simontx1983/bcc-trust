<?php
/**
 * Doubles for ProfileHallRoleTest: the REAL UserViewService::resolveHalls()
 * and GroupRole, with bcc-core's group repository, the context resolver and
 * the profile-visibility filter faked at their FQNs.
 *
 * Fixture ($GLOBALS['__bcc_phr']):
 *   groups       list<int> the member's group ids
 *   contexts     group_id => GroupContext
 *   statuses     user_id => group_id => gm_user_status
 *   read_fails   list<int> users whose batched status read fails (null)
 *   status_reads list<int> users whose statuses were read
 *
 * The visibility filter passes everything through (it has its own tests);
 * this file tests only the role disclosure. Safe only inside
 * #[RunTestsInSeparateProcesses].
 */

declare(strict_types=1);

namespace {
    if (!function_exists('get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            return '';
        }
    }
    if (!function_exists('apply_filters')) {
        /**
         * @param mixed $value
         * @return mixed
         */
        function apply_filters(string $hook, $value, ...$args)
        {
            return $value;
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoGroupRepository::class, false)) {
        final class PeepSoGroupRepository
        {
            /** @return list<int> */
            public static function getUserMemberGroupIds(int $userId, int $limit = 200): array
            {
                return $GLOBALS['__bcc_phr']['groups'] ?? [];
            }

            /** @return array<int, object> */
            public static function findManyByIds(array $groupIds): array
            {
                $out = [];
                foreach ($groupIds as $id) {
                    $out[$id] = (object) ['post_name' => 'g' . $id, 'post_title' => 'Group ' . $id];
                }
                return $out;
            }

            /** @return array<int, string>|null */
            public static function findUserMembershipStatuses(int $userId, array $groupIds): ?array
            {
                $GLOBALS['__bcc_phr']['status_reads'][] = $userId;
                if (in_array($userId, $GLOBALS['__bcc_phr']['read_fails'] ?? [], true)) {
                    return null;
                }
                return array_intersect_key($GLOBALS['__bcc_phr']['statuses'][$userId] ?? [], array_flip($groupIds));
            }
        }
    }
}

namespace BCC\Trust\Core\Services {
    if (!class_exists(GroupContextResolver::class, false)) {
        final class GroupContextResolver
        {
            /** @return array<int, \BCC\Trust\Core\ValueObjects\GroupContext> */
            public function forManyGroups(array $groupIds): array
            {
                return array_intersect_key($GLOBALS['__bcc_phr']['contexts'] ?? [], array_flip($groupIds));
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    if (!class_exists(ProfileGroupVisibility::class, false)) {
        final class ProfileGroupVisibility
        {
            /** @return array<int, mixed> everything visible — not under test here */
            public static function filter(array $contexts, int $ownerId, int $viewerId): array
            {
                return $contexts;
            }
        }
    }
}
