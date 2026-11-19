<?php
/**
 * Doubles for NotificationJoinRequestLinkTest (F-389): bcc-core's
 * PeepSoGroupRepository, answering findManyByIds() from
 * $GLOBALS['__njr_groups'] (group id => object with post_name).
 *
 * ⚠ Required from setUp() of a #[RunTestsInSeparateProcesses] class, never at
 * file scope, and guarded with class_exists(..., false): a plain
 * class_exists() would autoload the real class and skip the double.
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoGroupRepository::class, false)) {
        final class PeepSoGroupRepository
        {
            /** @var list<list<int>> every findManyByIds() argument, in order */
            public static array $lookups = [];

            /**
             * @param list<int> $ids
             * @return array<int, object>
             */
            public static function findManyByIds(array $ids): array
            {
                self::$lookups[] = $ids;
                $out = [];
                foreach ($ids as $id) {
                    if (isset($GLOBALS['__njr_groups'][$id])) {
                        $out[$id] = $GLOBALS['__njr_groups'][$id];
                    }
                }

                return $out;
            }
        }
    }
}
