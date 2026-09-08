<?php

declare(strict_types=1);

/**
 * Doubles for running the REAL {@see \BCC\Trust\Onchain\Support\OnchainCircuitBreaker}.
 *
 * ── WHY THE CLASS UNDER TEST IS REAL HERE ───────────────────────────────
 * Every other suite fakes the breaker at its production FQN, which is why a
 * fully green suite never noticed that `FAILURE_THRESHOLD` was asserted by
 * nothing at all. With the breaker replaced by a recorder, "five consecutive
 * failures open it" is not an observable claim.
 *
 * ⚠ THE TWO STORES ARE DELIBERATELY SEPARATE, exactly as production has
 * them: the failure COUNT lives in a `wp_options` row with no expiry, and
 * `opened_at` lives in a transient with a TTL. Production drifted precisely
 * because those lifetimes differ — chain 18 was found holding a counter of
 * 3022 beside a transient that had expired four days earlier. A double that
 * kept them in one array could not reproduce that, so this one does not.
 *
 * @package BCC\Trust\Onchain\Tests\Stubs
 */

namespace {

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

/** The two independent stores, plus a spy on the advisory lock. */
final class BccBreakerStore
{
    /** @var array<string, mixed> the wp_options table (no expiry) */
    public static array $options = [];

    /** @var array<string, mixed> the transient store (has a TTL) */
    public static array $transients = [];

    /** @var array<string, mixed> the object cache (request-scoped) */
    public static array $cache = [];

    /** @var array<string, bool> advisory locks currently held */
    public static array $locks = [];

    /** @var list<string> every lock acquire attempt, in order */
    public static array $lockAttempts = [];

    /** @var list<string> log lines */
    public static array $log = [];

    public static function reset(): void
    {
        self::$options      = [];
        self::$transients   = [];
        self::$cache        = [];
        self::$locks        = [];
        self::$lockAttempts = [];
        self::$log          = [];
    }

    /** The raw counter value, or null when the option row is absent. */
    public static function counter(int $chainId): ?int
    {
        $k = '_bcc_cb_counter_' . $chainId;

        return array_key_exists($k, self::$options) ? (int) self::$options[$k] : null;
    }

    /** Seed a counter WITHOUT any matching open-state. The chain-18 shape. */
    public static function seedOrphanCounter(int $chainId, int $value): void
    {
        self::$options['_bcc_cb_counter_' . $chainId] = $value;
        unset(self::$transients['bcc_cb_' . $chainId], self::$cache['bcc_circuit:cb_' . $chainId]);
    }

    /** Seed a coherent open breaker: counter AND state agree. */
    public static function seedOpen(int $chainId, int $failures, int $openedAt): void
    {
        self::$options['_bcc_cb_counter_' . $chainId] = $failures;
        $state = ['failures' => $failures, 'opened_at' => $openedAt];
        self::$transients['bcc_cb_' . $chainId]       = $state;
        self::$cache['bcc_circuit:cb_' . $chainId]    = $state;
    }

    /** @return array{failures: int, opened_at: int}|null */
    public static function state(int $chainId): ?array
    {
        $v = self::$transients['bcc_cb_' . $chainId] ?? null;

        return is_array($v) ? $v : null;
    }
}

// ── WordPress surface ───────────────────────────────────────────────────

if (!function_exists('wp_cache_get')) {
    function wp_cache_get(string $key, string $group = '', bool $force = false, &$found = null)
    {
        $k = $group . ':' . $key;
        if (!array_key_exists($k, BccBreakerStore::$cache)) {
            $found = false;

            return false;
        }
        $found = true;

        return BccBreakerStore::$cache[$k];
    }
}
if (!function_exists('wp_cache_set')) {
    function wp_cache_set(string $key, $data, string $group = '', int $expire = 0): bool
    {
        BccBreakerStore::$cache[$group . ':' . $key] = $data;

        return true;
    }
}
if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        unset(BccBreakerStore::$cache[$group . ':' . $key]);

        return true;
    }
}
if (!function_exists('get_transient')) {
    function get_transient(string $key)
    {
        return BccBreakerStore::$transients[$key] ?? false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient(string $key, $value, int $expiration = 0): bool
    {
        BccBreakerStore::$transients[$key] = $value;

        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        unset(BccBreakerStore::$transients[$key]);

        return true;
    }
}
if (!function_exists('get_option')) {
    function get_option(string $key, $default = false)
    {
        return array_key_exists($key, BccBreakerStore::$options)
            ? BccBreakerStore::$options[$key]
            : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $key, $value, $autoload = null): bool
    {
        BccBreakerStore::$options[$key] = $value;

        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option(string $key): bool
    {
        unset(BccBreakerStore::$options[$key]);

        return true;
    }
}
}

// ── Collaborators, faked at their production FQNs ───────────────────────

namespace BCC\Core\DB {
    final class AdvisoryLock
    {
        /** Non-blocking: succeeds only if nobody already holds the name. */
        public static function acquire(string $name, int $timeout = 0): bool
        {
            \BccBreakerStore::$lockAttempts[] = $name;
            if (!empty(\BccBreakerStore::$locks[$name])) {
                return false;
            }
            \BccBreakerStore::$locks[$name] = true;

            return true;
        }

        public static function release(string $name): void
        {
            unset(\BccBreakerStore::$locks[$name]);
        }
    }
}

namespace BCC\Core\Log {
    final class Logger
    {
        public static function warning(string $m, array $c = []): void
        {
            \BccBreakerStore::$log[] = 'warning: ' . $m;
        }

        public static function error(string $m, array $c = []): void
        {
            \BccBreakerStore::$log[] = 'error: ' . $m;
        }

        public static function info(string $m, array $c = []): void
        {
            \BccBreakerStore::$log[] = 'info: ' . $m;
        }

        public static function debug(string $m, array $c = []): void
        {
            \BccBreakerStore::$log[] = 'debug: ' . $m;
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    /**
     * The atomic counter, faked at its production FQN.
     *
     * `incrementFailureCounter()` mirrors INSERT … ON DUPLICATE KEY UPDATE:
     * 1 on a fresh row, the incremented value otherwise.
     */
    final class OnchainCircuitBreakerRepository
    {
        /** Set to true to simulate a DB error on increment. */
        public static bool $failIncrement = false;

        public static function incrementFailureCounter(string $optionName): ?int
        {
            if (self::$failIncrement) {
                return null;
            }
            $current = (int) (\BccBreakerStore::$options[$optionName] ?? 0);
            $next    = $current + 1;
            \BccBreakerStore::$options[$optionName] = $next;

            return $next;
        }

        public static function deleteCounter(string $optionName): void
        {
            unset(\BccBreakerStore::$options[$optionName]);
        }
    }
}
