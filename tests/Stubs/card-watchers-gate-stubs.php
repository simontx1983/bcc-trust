<?php
/**
 * Doubles for CardWatchersProfileGateTest (F-26): the REAL
 * CardWatchersService::listWatchers() and the REAL ProfileAccess gate.
 *
 * Builds on profile-access-stubs.php (PeepSoUser + Permissions doubles and
 * the __bcc_profile_access fixture). Adds:
 *   $GLOBALS['__bcc_cw']['posts'][id] => object{post_type, post_status, post_author}
 *   $GLOBALS['__bcc_cw']['meta'][id]  => _bcc_page_type
 *   $GLOBALS['__bcc_cw']['follower_reads'] => list<int> owner ids whose followers were read
 *
 * The follower repository answers "0 followers", so an ALLOWED call returns
 * before member-card hydration: the test is about the gate, not the cards.
 *
 * Required from setUp() of a #[RunTestsInSeparateProcesses] class, never at
 * file scope (profile-access-stubs.php is subprocess-only).
 */

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/profile-access-stubs.php';
}

namespace BCC\Trust\Core\Services {
    if (!function_exists(__NAMESPACE__ . '\\get_post')) {
        function get_post(int $postId): ?object
        {
            return $GLOBALS['__bcc_cw']['posts'][$postId] ?? null;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\get_post_meta')) {
        /** @return mixed */
        function get_post_meta(int $postId, string $key = '', bool $single = false)
        {
            return $GLOBALS['__bcc_cw']['meta'][$postId] ?? '';
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoFollowerRepository::class, false)) {
        final class PeepSoFollowerRepository
        {
            /** @return array{followers: int, following: int} */
            public static function getCounts(int $userId): array
            {
                $GLOBALS['__bcc_cw']['follower_reads'][] = $userId;
                return ['followers' => 0, 'following' => 0];
            }

            /** @return list<int> */
            public static function getFollowers(int $userId, int $limit, int $offset): array
            {
                return [];
            }
        }
    }
}
