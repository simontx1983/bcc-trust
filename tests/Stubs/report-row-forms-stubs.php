<?php
/**
 * WordPress stubs for ReportRowFormsOutsideOuterFormTest ONLY.
 *
 * ⚠ Required from the test's setUp(), inside its separate process. They
 * used to sit at FILE level, which runs in the PARENT process during
 * discovery and leaks these escaping/nonce/URL stubs into every other
 * unit test (the class of leak that broke #652's CI).
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
foreach ([
    'esc_attr', 'esc_html', 'esc_url', 'esc_js', 'esc_html__', '__',
] as $fn) {
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
    /** @param array<string, string> $args */
    function add_query_arg(array $args, string $url): string
    {
        return $url . '?' . http_build_query($args);
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }
}
if (!function_exists('absint')) {
    function absint($n): int
    {
        return abs((int) $n);
    }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = '-1'): string
    {
        return 'nonce-' . md5($action);
    }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
    {
        return '<input type="hidden" name="' . $name . '" value="nonce-' . md5($action) . '">';
    }
}
