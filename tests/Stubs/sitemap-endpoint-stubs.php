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
    // F-341's accessor resolves the admin exemption for the VIEWER being
    // served, not for the request's user, so it reaches two more
    // functions from this namespace. Shadowed here so nothing in it
    // touches WordPress.
    //
    // Minimal on purpose: no request user, and user_can() DEFERS to a
    // global one when a suite defined it. No world gains an admin viewer
    // it did not already have.
    if (!\function_exists('BCC\Trust\Core\Support\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return 0;
        }
    }

    if (!\function_exists('BCC\Trust\Core\Support\user_can')) {
        function user_can($user, string $capability, ...$args): bool
        {
            return \function_exists('user_can')
                && \user_can($user, $capability, ...$args);
        }
    }
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
