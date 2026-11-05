<?php
/**
 * Doubles for FeedPageContextHydrationTest.
 *
 * The unit suite does not autoload bcc-core, and it shares one process, so
 * these must never reach the other suites: required from setUp() of a
 * #[RunTestsInSeparateProcesses] class only.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services\Feed {
    // The stage calls these unqualified, so PHP resolves them here first.
    if (!function_exists(__NAMESPACE__ . '\\update_meta_cache')) {
        /** @param list<int> $ids */
        function update_meta_cache(string $type, array $ids): void
        {
            $GLOBALS['__bcc_page_ctx']['meta_primes'][] = $ids;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\get_post_meta')) {
        function get_post_meta(int $postId, string $key, bool $single = false): string
        {
            $GLOBALS['__bcc_page_ctx']['meta_keys'][$key] = true;

            return $GLOBALS['__bcc_page_ctx']['markers'][$postId] ?? '';
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoActivityRepository::class, false)) {
        final class PeepSoActivityRepository
        {
            public const PAGE_TARGET_META = '_bcc_page_id';
        }
    }
}

namespace BCC\Core\DB {
    if (!class_exists(DB::class, false)) {
        final class DB
        {
            public static function table(string $name): string
            {
                return 'wp_bcc_' . $name;
            }
        }
    }
}
