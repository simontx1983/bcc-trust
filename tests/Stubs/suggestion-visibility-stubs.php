<?php
/**
 * Doubles for SuggestionVisibilityTest (§F-02 on member suggestions), on
 * top of tests/Stubs/filter-visible-stubs.php (PeepSoUserSearch + PeepSo,
 * which the real ProfileAccess::filterVisible asks).
 *
 * ⚠⚠ SUBPROCESS-ONLY, required from the test's setUp(): it declares a fake
 * UserSyncRepository (user-sync-recent-stub.php, shared with
 * ColdStartVisibilityTest) and bcc-core's PeepSoMediaCache, which cannot be
 * undone in a process.
 *
 * Fixture ($GLOBALS['__bcc_sv']):
 *   users[<uid>] => true          ids get_userdata() finds
 *   avatar_calls => list<list<int>>  every avatarUrlBulk() id list
 */

declare(strict_types=1);

namespace {
    // The cold-start top-up's recency source.
    require_once __DIR__ . '/user-sync-recent-stub.php';
}

namespace BCC\Core\PeepSo {
    if (!class_exists(PeepSoMediaCache::class, false)) {
        final class PeepSoMediaCache
        {
            /**
             * @param list<int> $userIds
             * @return array<int, string>
             */
            public static function avatarUrlBulk(array $userIds): array
            {
                $GLOBALS['__bcc_sv']['avatar_calls'][] = $userIds;
                $out = [];
                foreach ($userIds as $id) {
                    $out[$id] = 'https://example.test/a/' . $id . '.png';
                }

                return $out;
            }
        }
    }
}

namespace {
    if (!function_exists('update_meta_cache')) {
        /** @param list<int> $ids */
        function update_meta_cache(string $type, array $ids): bool
        {
            return true;
        }
    }

    if (!function_exists('cache_users')) {
        /** @param list<int> $ids */
        function cache_users(array $ids): void
        {
        }
    }

    if (!function_exists('get_userdata')) {
        function get_userdata(int $userId): object|false
        {
            return isset($GLOBALS['__bcc_sv']['users'][$userId]) ? (object) ['ID' => $userId] : false;
        }
    }

    if (!function_exists('get_user_meta')) {
        /** Handles are `h<id>`; every privacy flag is off. */
        function get_user_meta(int $userId, string $key = '', bool $single = false): mixed
        {
            return $key === 'bcc_handle' ? 'h' . $userId : '';
        }
    }
}
