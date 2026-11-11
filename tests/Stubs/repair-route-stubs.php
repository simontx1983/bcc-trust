<?php
/**
 * In-namespace WP stubs for RepairRoutesTest. RepairService resolves
 * add_action / current_user_can / wp_verify_nonce / wp_die in its own
 * namespace first, so these intercept without WordPress loaded.
 *
 * @package BCC\Trust\Tests
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services\Admin;

final class RepairRouteStubState
{
    /** @var array<string, callable> */
    public static array $actions = [];
    public static bool $canManage = true;
    public static bool $nonceOk = true;
}

final class RepairRouteDied extends \RuntimeException
{
}

if (!function_exists(__NAMESPACE__ . '\\add_action')) {
    function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
    {
        RepairRouteStubState::$actions[$hook] = $cb;
        return true;
    }
}

if (!function_exists(__NAMESPACE__ . '\\current_user_can')) {
    function current_user_can(string $cap): bool
    {
        return RepairRouteStubState::$canManage;
    }
}

if (!function_exists(__NAMESPACE__ . '\\wp_verify_nonce')) {
    /** @param mixed $nonce */
    function wp_verify_nonce($nonce, string $action): bool
    {
        return RepairRouteStubState::$nonceOk;
    }
}

if (!function_exists(__NAMESPACE__ . '\\wp_die')) {
    /** @return never */
    function wp_die(string $message): void
    {
        throw new RepairRouteDied($message);
    }
}
