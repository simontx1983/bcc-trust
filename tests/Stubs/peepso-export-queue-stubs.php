<?php

declare(strict_types=1);

/**
 * Doubles for PeepSoDataExportQueueTest (F-581): hook registration is
 * recorded, wp_die() records its arguments and halts, and a nonce check
 * records that it was asked (so a test can prove the guard never needs it).
 *
 * Loaded only by that test, which runs each case in a separate process.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

final class ExportQueueHalt extends \RuntimeException
{
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): bool
    {
        $GLOBALS['__bcc_xq']['hooks'][] = [$hook, $callback, $priority, $args];
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, $callback, int $priority = 10, int $args = 1): bool
    {
        return add_filter($hook, $callback, $priority, $args);
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

if (!function_exists('wp_die')) {
    /**
     * @param mixed $message
     * @param mixed $title
     * @param mixed $args
     * @return never
     */
    function wp_die($message = '', $title = '', $args = [])
    {
        $GLOBALS['__bcc_xq']['die'] = [$message, $title, $args];
        throw new \ExportQueueHalt('wp_die');
    }
}

if (!function_exists('current_user_can')) {
    /** Every caller here is an admin: the queue stays closed to admins too. */
    function current_user_can(string $capability, ...$args): bool
    {
        return true;
    }
}

if (!function_exists('check_admin_referer')) {
    /** @return int|false */
    function check_admin_referer($action = -1, string $queryArg = '_wpnonce')
    {
        $GLOBALS['__bcc_xq']['nonce_checked'] = true;
        return 1;
    }
}
