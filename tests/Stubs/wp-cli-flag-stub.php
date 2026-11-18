<?php
/**
 * WP-CLI's flag reader, verbatim semantics (wp-cli/php/utils.php):
 * `isset($assoc_args[$flag]) ? $assoc_args[$flag] : $default`.
 *
 * WP-CLI itself turns `--apply` into `apply => true`, `--no-apply` into
 * `apply => false` and `--apply=0` into `apply => '0'` before a command sees
 * them; the test feeds those shapes. Required from setUp() of a
 * #[RunTestsInSeparateProcesses] class, never at file scope.
 */

declare(strict_types=1);

namespace WP_CLI\Utils;

if (!function_exists('WP_CLI\Utils\get_flag_value')) {
    /**
     * @param array<string, mixed> $assoc_args
     * @param mixed                $default
     * @return mixed
     */
    function get_flag_value($assoc_args, $flag, $default = null)
    {
        return isset($assoc_args[$flag]) ? $assoc_args[$flag] : $default;
    }
}
