<?php
/**
 * Fixture-backed stubs for the public_all authoring-gate tests
 * (GroupPostPolicyRepositoryTest + GroupsPublicAllGateTest).
 *
 * Subprocess-only (RunTestsInSeparateProcesses). Defines, at their FQNs
 * and BEFORE the autoloader can resolve the real ones, the WordPress
 * functions + collaborators the gate path touches:
 *   - get_post / get_post_meta        — REAL GroupContextResolver + PeepSoPrivacy
 *   - get_post_meta / update_post_meta / delete_post_meta — GroupPostPolicyRepository
 *   - user_can                        — GroupsService::setPublicAllMembersPolicy admin check
 *   - BCC\Core\Repositories\PeepSoGroupRepository::getMembershipStatus (bcc-core, static)
 *   - BCC\Trust\Core\Repositories\ReputationRepository — no-op ctor (the real one
 *     hits TableRegistry/$wpdb on construct; the gate methods never call it)
 *
 * PublicAllPolicy + CommentService constants are NOT stubbed — pure, no DB
 * on load. Fixture ($GLOBALS['__bcc_puball_fixture']):
 *   groups[<id>] = [
 *     'post_type'  => 'peepso-group',
 *     'meta'       => [<key> => <string value>],   // peepso_group_privacy, _bcc_group_public_all_members, …
 *     'membership' => [<uid> => <status|null>],    // getMembershipStatus source
 *   ]
 *   admins = [<uid>, …]                             // user_can(manage_options) = true
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_Post', false)) {
        final class WP_Post
        {
            public int $ID = 0;
            public string $post_type = 'peepso-group';
        }
    }

    /**
     * Shared fixture reader — one map, every stubbed namespace reads it.
     *
     * @return mixed
     */
    function bcc_puball_meta(int $id, string $key)
    {
        return $GLOBALS['__bcc_puball_fixture']['groups'][$id]['meta'][$key] ?? '';
    }
}

namespace BCC\Trust\Core\Services {
    if (!function_exists('BCC\\Trust\\Core\\Services\\get_post')) {
        function get_post($id)
        {
            $group = $GLOBALS['__bcc_puball_fixture']['groups'][(int) $id] ?? null;
            if ($group === null) {
                return null;
            }
            $post = new \WP_Post();
            $post->ID = (int) $id;
            $post->post_type = (string) ($group['post_type'] ?? 'peepso-group');
            return $post;
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Services\\get_post_meta')) {
        function get_post_meta($id, $key, $single = false)
        {
            return \bcc_puball_meta((int) $id, (string) $key);
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Services\\user_can')) {
        function user_can($user, $capability, ...$args): bool
        {
            $uid    = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;
            $admins = $GLOBALS['__bcc_puball_fixture']['admins'] ?? [];
            return in_array($uid, $admins, true);
        }
    }
}

namespace BCC\Trust\Core\ValueObjects {
    if (!function_exists('BCC\\Trust\\Core\\ValueObjects\\get_post_meta')) {
        function get_post_meta($id, $key, $single = false)
        {
            return \bcc_puball_meta((int) $id, (string) $key);
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    if (!function_exists('BCC\\Trust\\Core\\Repositories\\get_post_meta')) {
        function get_post_meta($id, $key, $single = false)
        {
            return \bcc_puball_meta((int) $id, (string) $key);
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Repositories\\update_post_meta')) {
        function update_post_meta($id, $key, $value): bool
        {
            $GLOBALS['__bcc_puball_fixture']['groups'][(int) $id]['meta'][(string) $key] = (string) $value;
            return true;
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Repositories\\delete_post_meta')) {
        function delete_post_meta($id, $key): bool
        {
            unset($GLOBALS['__bcc_puball_fixture']['groups'][(int) $id]['meta'][(string) $key]);
            return true;
        }
    }

    // Real ctor hits TableRegistry::scores() ($wpdb) — the gate methods
    // never call reputationRepo, so a no-op double is enough to satisfy
    // the GroupsService constructor type.
    if (!class_exists(ReputationRepository::class, false)) {
        class ReputationRepository
        {
            public function __construct()
            {
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoGroupRepository::class, false)) {
        class PeepSoGroupRepository
        {
            public static function getMembershipStatus(int $userId, int $groupId): ?string
            {
                return $GLOBALS['__bcc_puball_fixture']['groups'][$groupId]['membership'][$userId] ?? null;
            }
        }
    }
}
