<?php
/**
 * H7: a stand-in for the opt-out map's WRITE, for test worlds that keep user
 * meta in their own get_user_meta() / update_user_meta() /
 * delete_user_meta() fixture (revocation-fail-safe, suspension-gate,
 * delegator-group). The swap compares against the fixture's current value
 * and writes there, so a world's opt-out rows stay in one place.
 *
 * The map's READ in those worlds is bcc-core's UserMetaRepository as the
 * unit double tests/Stubs/user-meta-repository-double.php gives it (it reads
 * the same fixture; `UserMetaRepository::$unreadable[$id] = true` makes a
 * member's read fail). Unit processes autoload that double from
 * tests/bootstrap.php; the integration world requires it explicitly.
 *
 * The real pair is tested elsewhere:
 *   - bcc-core UserMetaRepository: core's UserMetaRepositoryReadTest;
 *   - UserMetaSwapRepository and the map over both:
 *     UserMetaSwapRepositoryTest, OptOutMapReadFailClosedTest and
 *     OptOutMapCoherenceTest, over the $wpdb double.
 *
 * Require BEFORE anything autoloads the real class, from inside a separate
 * process only.
 */

declare(strict_types=1);

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
