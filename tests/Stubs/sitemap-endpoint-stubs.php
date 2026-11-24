<?php
/**
 * Stubs for SitemapEndpointTest (F-518). Load ONLY from a
 * #[RunTestsInSeparateProcesses] class: a function declaration cannot be
 * undone.
 *
 * The unit suite's UserMetaRepository double reads through
 * `BCC\Trust\Core\Support\get_user_meta()` when it exists. This one answers
 * from `$GLOBALS['__bcc_sitemap_meta'][$userId][$key]`.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support {
    if (!\function_exists('BCC\Trust\Core\Support\get_user_meta')) {
        /**
         * @return mixed
         */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            $value = $GLOBALS['__bcc_sitemap_meta'][$userId][$key] ?? null;
            if ($single) {
                return $value ?? '';
            }

            return $value === null ? [] : [$value];
        }
    }
}
