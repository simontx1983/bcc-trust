<?php
/**
 * Doubles for MyGroupsOracleTest (F-47): the real MyGroupsEndpoint join/leave
 * and the real GroupsService::resolveGroupAccess(), with the group context,
 * membership and PeepSo writer faked.
 *
 * Fixture ($GLOBALS['__bcc_mg']):
 *   contexts     group_id => GroupContext (absent → no such group)
 *   memberships  user_id => group_id => gm_user_status
 *   writes       list of [op, user_id, group_id] the PeepSo writer received
 *
 * Reuses suspension-gate-stubs.php for get_current_user_id, Throttle,
 * Permissions and WP_REST_Response; declares a params-aware WP_REST_Request
 * FIRST, so the guarded one there is skipped.
 *
 * The final GroupContextResolver and the bcc-core statics are declared at
 * their FQNs, which is safe only because the consuming test is
 * #[RunTestsInSeparateProcesses] (the real classes never load in that child).
 * Required from setUp(), never at file scope.
 */

declare(strict_types=1);

namespace {
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

    require_once __DIR__ . '/suspension-gate-stubs.php';
}

namespace BCC\Trust\Core\Services {
    if (!class_exists(GroupContextResolver::class, false)) {
        final class GroupContextResolver
        {
            public function forGroup(int $groupId): ?\BCC\Trust\Core\ValueObjects\GroupContext
            {
                return $GLOBALS['__bcc_mg']['contexts'][$groupId] ?? null;
            }
        }
    }
}

namespace BCC\Trust\Core\REST {
    // The trust-gate read in postJoin. No gate configured.
    if (!function_exists(__NAMESPACE__ . '\\get_post_meta')) {
        /** @return mixed */
        function get_post_meta(int $postId, string $key = '', bool $single = false)
        {
            return '';
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
    // A real join/leave audits the transition; record it instead of writing.
    if (!class_exists(AuditLogger::class, false)) {
        class AuditLogger
        {
            /** @param array<string, mixed> $meta */
            public static function log(string $action, ?int $targetId = null, array $meta = [], ?string $targetType = null, ?int $userId = null): void
            {
                $GLOBALS['__bcc_mg']['audits'][] = [$action, $targetId];
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoGroupRepository::class, false)) {
        final class PeepSoGroupRepository
        {
            public static function getMembershipStatus(int $userId, int $groupId): ?string
            {
                return $GLOBALS['__bcc_mg']['memberships'][$userId][$groupId] ?? null;
            }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(PeepSoGroupWriter::class, false)) {
        final class PeepSoGroupWriter
        {
            public static function join(int $userId, int $groupId): bool
            {
                $GLOBALS['__bcc_mg']['writes'][] = ['join', $userId, $groupId];
                return true;
            }

            public static function leave(int $userId, int $groupId): bool
            {
                $GLOBALS['__bcc_mg']['writes'][] = ['leave', $userId, $groupId];
                return true;
            }
        }
    }
}
