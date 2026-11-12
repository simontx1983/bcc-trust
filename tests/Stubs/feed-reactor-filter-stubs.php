<?php
/**
 * Doubles for FeedReactorFacesFilterTest (F-90): the REAL
 * FeedHydrationPipeline::hydrateSocialProofReactors() with its reads faked.
 *
 * Fixture ($GLOBALS['__bcc_rf']):
 *   reactors    act_id => list<user_id>   PeepSoReactionRepository::recentReactorIdsByActIds()
 *   blocked     list<user_id> | null      PeepSoBlockRepository::getMutuallyBlockedSetOrUnknown() (null = read failed)
 *   block_calls list of [viewer, ids]
 *   users       user_id => display name   get_users()
 *
 * The final PeepSoReactionRepository and the bcc-core statics are declared at
 * their FQNs, which is safe only because the consuming test is
 * #[RunTestsInSeparateProcesses]. Required from setUp(), never at file scope.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories {
    if (!class_exists(PeepSoReactionRepository::class, false)) {
        final class PeepSoReactionRepository
        {
            /**
             * @param list<int> $actIds
             * @return array<int, list<int>>
             */
            public function recentReactorIdsByActIds(array $actIds, int $perActLimit = 3): array
            {
                return array_intersect_key($GLOBALS['__bcc_rf']['reactors'], array_flip($actIds));
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoBlockRepository::class, false)) {
        final class PeepSoBlockRepository
        {
            /**
             * @param list<int> $otherIds
             * @return array<int, true>|null
             */
            public static function getMutuallyBlockedSetOrUnknown(int $userId, array $otherIds): ?array
            {
                $GLOBALS['__bcc_rf']['block_calls'][] = [$userId, $otherIds];
                $blocked = $GLOBALS['__bcc_rf']['blocked'];
                if ($blocked === null) {
                    return null;
                }
                return array_fill_keys(array_values(array_intersect($otherIds, $blocked)), true);
            }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(PeepSoMediaCache::class, false)) {
        final class PeepSoMediaCache
        {
            public static function avatarUrl(int $userId): string
            {
                return 'https://example.test/avatar/' . $userId;
            }
        }
    }
}

namespace BCC\Trust\Core\Services\Feed {
    if (!function_exists(__NAMESPACE__ . '\\get_users')) {
        /**
         * @param array<string, mixed> $args
         * @return list<object>
         */
        function get_users(array $args): array
        {
            $out = [];
            foreach ((array) $args['include'] as $id) {
                if (isset($GLOBALS['__bcc_rf']['users'][$id])) {
                    $out[] = (object) ['ID' => $id, 'display_name' => $GLOBALS['__bcc_rf']['users'][$id], 'user_login' => 'login' . $id];
                }
            }
            return $out;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\update_meta_cache')) {
        /** @param list<int> $ids */
        function update_meta_cache(string $type, array $ids): void
        {
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            return 'handle' . $userId;
        }
    }
}
