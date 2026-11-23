<?php
/**
 * H7: stand-ins for the opt-out map's read and write, for test worlds that
 * keep user meta in their own get_user_meta() / update_user_meta() /
 * delete_user_meta() fixture (revocation-fail-safe, suspension-gate,
 * delegator-group). They use THAT fixture, so a world's existing opt-out
 * rows are what the map's read sees and its writes land there.
 *
 * The real pair is tested elsewhere:
 *   - bcc-core UserMetaRepository: core's UserMetaRepositoryReadTest;
 *   - UserMetaSwapRepository and the map over both:
 *     UserMetaSwapRepositoryTest, OptOutMapReadFailClosedTest and
 *     OptOutMapCoherenceTest, over the $wpdb double.
 * These only keep the worlds that test OTHER things working.
 *
 * '' (or null/false) from the fixture reads as "no row", as get_user_meta()
 * gives it. $GLOBALS['__bcc_trusted_meta_fails'] = true makes every read
 * fail (null). The swap compares against the fixture's current value.
 *
 * Require BEFORE anything autoloads the real classes, from inside a separate
 * process only.
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\UserMetaRepository', false)) {
        final class UserMetaRepository
        {
            /**
             * @param list<int>    $userIds
             * @param list<string> $keys
             * @return array<int, array<string, string>>|null
             */
            public static function firstValuesOrUnknown(array $userIds, array $keys, bool $fresh = false): ?array
            {
                if (!empty($GLOBALS['__bcc_trusted_meta_fails'])) {
                    return null;
                }
                $out = [];
                foreach ($userIds as $id) {
                    foreach ($keys as $key) {
                        $value = \get_user_meta((int) $id, $key, true);
                        if ($value === '' || $value === null || $value === false) {
                            continue;
                        }
                        $out[(int) $id][$key] = is_scalar($value) ? (string) $value : (string) json_encode($value);
                    }
                }
                return $out;
            }

            public static function forget(int $userId): void
            {
            }
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\UserMetaSwapRepository', false)) {
        final class UserMetaSwapRepository
        {
            public static function swap(int $userId, string $key, ?string $expected, ?string $next): bool
            {
                $current = \get_user_meta($userId, $key, true);
                $current = ($current === '' || $current === null || $current === false)
                    ? null
                    : (is_scalar($current) ? (string) $current : (string) json_encode($current));
                if ($current !== $expected) {
                    return false;
                }
                if ($next === null) {
                    \delete_user_meta($userId, $key);
                } else {
                    \update_user_meta($userId, $key, $next);
                }
                return true;
            }
        }
    }
}
