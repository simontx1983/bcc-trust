<?php
/**
 * Stubs for GroupDetailJoinVerdictTest (F-359): the two repositories the
 * group page's NFT enrichment reads, driven by a fixture, so the test can
 * run the real GroupsService::resolveNftEnrichment() without a database.
 *
 * Fixture shape ($GLOBALS['__bcc_gdh']):
 *   configs      array<int, GatedGroupConfig>  gate config per group id
 *   collections  array<int, object>            collection row per collection id
 *
 * Safe only inside #[RunTestsInSeparateProcesses]; require it from setUp().
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(GatedGroupRepository::class, false)) {
        final class GatedGroupRepository
        {
            /**
             * @param int[] $groupIds
             * @return array<int, \BCC\Trust\Onchain\ValueObjects\GatedGroupConfig>
             */
            public static function findManyByGroupIds(array $groupIds): array
            {
                $configs = $GLOBALS['__bcc_gdh']['configs'] ?? [];
                return array_intersect_key($configs, array_flip($groupIds));
            }
        }
    }

    if (!class_exists(CollectionRepository::class, false)) {
        final class CollectionRepository
        {
            /**
             * @param int[] $ids
             * @return array<int, object>
             */
            public static function findManyByIds(array $ids): array
            {
                $rows = $GLOBALS['__bcc_gdh']['collections'] ?? [];
                return array_intersect_key($rows, array_flip($ids));
            }
        }
    }
}

namespace {
    if (!function_exists('apply_filters')) {
        /**
         * @param mixed $value
         * @return mixed
         */
        function apply_filters(string $hook, $value)
        {
            return $value;
        }
    }
}
