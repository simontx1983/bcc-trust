<?php
/**
 * WordPress stubs for rendering ReportListTable rows in a unit test.
 *
 * ⚠ Require from setUp() inside a #[RunTestsInSeparateProcesses] test —
 * never at file level, where the code runs in the PARENT process during
 * discovery and these definitions (escaping functions, WP_List_Table,
 * admin_url…) become the ones every other unit test sees.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}
if (!class_exists('WP_List_Table')) {
    class WP_List_Table
    {
        /** @param array<string, mixed> $args */
        public function __construct(array $args = [])
        {
        }
    }
}
foreach (['esc_attr', 'esc_html', 'esc_url', 'esc_js', 'esc_html__', '__'] as $fn) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '(string $t, string $d = ""): string { return htmlspecialchars($t, ENT_QUOTES); }');
    }
}
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . $path;
    }
}
if (!function_exists('add_query_arg')) {
    /** @param array<string, int|string> $args */
    function add_query_arg(array $args, string $url): string
    {
        return $url . '?' . http_build_query($args);
    }
}
