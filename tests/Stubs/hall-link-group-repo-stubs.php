<?php
/**
 * Stubs for HallLinksToCommunitiesTest ONLY: a bcc-core PeepSoGroupRepository
 * double answering findHallById() from $GLOBALS['__bcc_hall_rows']
 * (group id => post_name; an absent id is "not a Hall").
 *
 * ⚠⚠ SUBPROCESS-ONLY, declared before anything autoloads the real class.
 * `class_exists($x, false)`: a bare class_exists() would autoload the real
 * class, the guard would always be true, and this double would never be
 * declared.
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!\class_exists('BCC\Core\Repositories\PeepSoGroupRepository', false)) {
        final class PeepSoGroupRepository
        {
            public static function findHallById(int $groupId): ?object
            {
                $slug = $GLOBALS['__bcc_hall_rows'][$groupId] ?? null;
                return $slug === null ? null : (object) ['ID' => $groupId, 'post_name' => $slug];
            }
        }
    }
}
