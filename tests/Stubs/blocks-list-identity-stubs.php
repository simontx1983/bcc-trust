<?php
/**
 * MyBlocksListIdentityTest: the world around MyBlocksService::getMyBlocks().
 *
 * ⚠ Required from a test's setUp(), i.e. inside its separate process —
 * NEVER at file level, or these doubles become THE classes for the whole
 * unit run. Load tests/Stubs/profile-access-stubs.php alongside it: the REAL
 * ProfileAccess::canViewProfile() runs over its PeepSo and suspension doubles.
 *
 * Fixture ($GLOBALS['__bcc_blk']):
 *   rows         => list<object>  what PeepSoBlockRepository::listForBlocker()
 *                                 returns (blk_id, blk_blocked_id, user_login,
 *                                 display_name; user_login NULL = no WP user)
 *   meta         => array<int, array<string, string>>  get_user_meta()
 *   avatar_calls => list<list<int>>  every avatarUrlBulk() argument
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\PeepSoBlockRepository', false)) {
        final class PeepSoBlockRepository
        {
            public static function countByBlocker(int $blockerId): int
            {
                return count($GLOBALS['__bcc_blk']['rows'] ?? []);
            }

            /** @return list<object> */
            public static function listForBlocker(int $blockerId, int $limit, int $offset): array
            {
                return array_slice($GLOBALS['__bcc_blk']['rows'] ?? [], $offset, $limit);
            }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(__NAMESPACE__ . '\\PeepSoMediaCache', false)) {
        final class PeepSoMediaCache
        {
            /**
             * @param list<int> $userIds
             * @return array<int, string>
             */
            public static function avatarUrlBulk(array $userIds): array
            {
                $GLOBALS['__bcc_blk']['avatar_calls'][] = $userIds;
                $out = [];
                foreach ($userIds as $id) {
                    $out[$id] = 'https://avatars.test/' . $id . '.png';
                }

                return $out;
            }
        }
    }
}

namespace BCC\Trust\Core\Services {
    // MyBlocksService checks it is answering for the current viewer; the
    // same viewer ProfileAccess's own shadow (profile-access-stubs) reads.
    if (!\function_exists('BCC\Trust\Core\Services\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return \bcc_profile_access_fixture()['viewer_id'];
        }
    }
}

namespace {
    if (!function_exists('get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            return $GLOBALS['__bcc_blk']['meta'][$userId][$key] ?? '';
        }
    }
}
