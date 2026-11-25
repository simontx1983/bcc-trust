<?php
/**
 * Stubs for PrivacyChangeFiresOnlyOnChangeTest.
 *
 * ⚠⚠ SUBPROCESS-ONLY. The consuming test is
 * `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]`.
 *
 * ⭐ `PrivacySettings` and `EdgeCache` are the REAL classes — the point is
 * whether THEY decide correctly. What is doubled is only the world around
 * them: the meta store, WordPress's action API, and LiteSpeed's purge hook.
 *
 * The `UserMetaRepository` double is the shared one `tests/bootstrap.php`
 * autoloads; it reads through the `BCC\Trust\Core\Support\get_user_meta()`
 * shim below, and `$unreadable[$id]` makes a member's read fail.
 *
 * Fixture ($GLOBALS['__bcc_pcs']):
 *   meta[<uid>][<meta_key>] => string   stored value (absent = unset)
 *   fired                   => list<list<string>>  each action fire's keys
 *   purged                  => list<string>        each purged tag
 */

declare(strict_types=1);

namespace {
    if (!isset($GLOBALS['__bcc_pcs'])) {
        /** @var array{meta: array<int, array<string, string>>, fired: list<list<string>>, purged: list<string>} */
        $GLOBALS['__bcc_pcs'] = ['meta' => [], 'fired' => [], 'purged' => []];
    }

    /** Registered listeners, by hook. @var array<string, list<callable>> */
    $GLOBALS['__bcc_pcs_hooks'] = [];

    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $fn, int $priority = 10, int $args = 1): bool
        {
            unset($priority, $args);
            $GLOBALS['__bcc_pcs_hooks'][$hook][] = $fn;

            return true;
        }
    }

    if (!function_exists('do_action')) {
        /**
         * Records the two actions under test, then dispatches to whatever
         * registered — so a test can assert on the FIRE and on what a
         * listener did with it, separately.
         */
        function do_action(string $hook, ...$args): void
        {
            if ($hook === 'bcc_privacy_settings_changed' || $hook === 'bcc_member_listing_fields_changed') {
                $keys = $args[1] ?? [];
                $GLOBALS['__bcc_pcs']['fired'][] = is_array($keys) ? array_values($keys) : [];
            }

            // LiteSpeed's purge hook, which EdgeCache::purge() fires.
            if ($hook === 'litespeed_purge') {
                $GLOBALS['__bcc_pcs']['purged'][] = (string) ($args[0] ?? '');
            }

            foreach ($GLOBALS['__bcc_pcs_hooks'][$hook] ?? [] as $fn) {
                $fn(...$args);
            }
        }
    }

    if (!function_exists('add_filter')) {
        function add_filter(string $hook, callable $fn, int $priority = 10, int $args = 1): bool
        {
            unset($hook, $fn, $priority, $args);

            return true;
        }
    }

    if (!function_exists('apply_filters')) {
        /** @param mixed $value @return mixed */
        function apply_filters(string $hook, $value, ...$args)
        {
            unset($hook, $args);

            return $value;
        }
    }
}

namespace BCC\Trust\Core\Support {
    /**
     * ⭐ ONE shim serves PrivacySettings, the UserMetaRepository double and
     * everything else in this namespace: PHP resolves an unqualified call to
     * the current namespace first.
     */
    if (!\function_exists('BCC\Trust\Core\Support\get_user_meta')) {
        /** @return mixed */
        function get_user_meta($userId, $key = '', $single = false)
        {
            unset($single);

            return $GLOBALS['__bcc_pcs']['meta'][(int) $userId][$key] ?? '';
        }
    }

    if (!\function_exists('BCC\Trust\Core\Support\update_user_meta')) {
        function update_user_meta($userId, $key, $value): bool
        {
            $before = $GLOBALS['__bcc_pcs']['meta'][(int) $userId][$key] ?? null;
            $GLOBALS['__bcc_pcs']['meta'][(int) $userId][$key] = (string) $value;

            // Like WordPress: false for an unchanged value, which is exactly
            // why the production code cannot use this as a change signal.
            return $before !== (string) $value;
        }
    }

    if (!\function_exists('BCC\Trust\Core\Support\delete_user_meta')) {
        function delete_user_meta($userId, $key): bool
        {
            unset($GLOBALS['__bcc_pcs']['meta'][(int) $userId][$key]);

            return true;
        }
    }
}
