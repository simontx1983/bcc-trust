<?php

declare(strict_types=1);

/**
 * Harness for CollectionRepository's four verified reads (F-472).
 *
 * ⭐ The `$wpdb` double is FAITHFUL on purpose, because the failure these
 * tests exist to pin is invisible to a kind one.
 *
 * `wpdb::query()` returns false ABOVE its own `flush()` on two paths — a
 * failed reconnect (`! $this->ready`) and an emptied `query` filter. On
 * either, `last_error` is EMPTY and `last_result` still holds the
 * PREVIOUS statement's rows. So:
 *
 *   - `query()` on a bailing statement returns false and touches NEITHER
 *     `last_error` NOR `last_result`. The absence of those assignments is
 *     the whole model;
 *   - `get_results()`, `get_row()` and `get_var()` are as careless as
 *     wpdb's: each calls `query()`, DISCARDS the return value and reads
 *     `last_result`. A double that returned `[]` on failure would let the
 *     pre-fix code pass, and the mutation controls would prove nothing.
 *
 * Statements are routed by SQL fragment rather than by call order, so a
 * test can bail ONE read while every other read in the same code path
 * still answers. That matters here: `readTokenStandard()` resolves a chain
 * row first, and `listVerifiedByChain()` is reached through a cached chain
 * set — a blanket failure would stop the method before it ever made the
 * read under test, and the assertion would pass for the wrong reason.
 */

namespace {
    foreach (['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }

    if (!class_exists('BccCollectionReadFakeWpdb', false)) {
        class BccCollectionReadFakeWpdb
        {
            public string $prefix     = 'wp_';
            public string $last_error = '';

            /** @var list<object> */
            public array $last_result = [];

            public int $insert_id     = 0;
            public int $rows_affected = 0;

            /** @var list<string> Every statement sent, in order. */
            public array $sent = [];

            /**
             * Rows a read answers with, keyed by a fragment of its SQL.
             * First match wins, so order the more specific fragment first.
             *
             * @var array<string, list<object>>
             */
            public array $answers = [];

            /** Fragment of the ONE statement that bails pre-flush. */
            public ?string $bailOn = null;

            public function prepare(string $query, mixed ...$args): string
            {
                $i   = 0;
                $out = preg_replace_callback(
                    '/%[sdf]/',
                    static function (array $m) use (&$i, $args): string {
                        $v = $args[$i] ?? null;
                        $i++;
                        if ($m[0] === '%d') {
                            return (string) (int) $v;
                        }
                        if ($m[0] === '%f') {
                            return (string) (float) $v;
                        }

                        return "'" . (is_scalar($v) ? (string) $v : '') . "'";
                    },
                    $query
                );

                return (string) $out;
            }

            /** The row count for a read, the affected count for a write. */
            public function query(string $sql): int|false
            {
                $this->sent[] = $sql;

                if ($this->bailOn !== null && str_contains($sql, $this->bailOn)) {
                    // THE BAIL. Above flush(): no error recorded, and
                    // last_result left exactly as the previous statement
                    // left it. The missing assignments ARE the model.
                    return false;
                }

                if (self::isRead($sql)) {
                    $this->last_result = $this->rowsFor($sql);

                    return count($this->last_result);
                }

                $this->last_result   = [];
                $this->rows_affected = 1;

                return 1;
            }

            /** @return list<object> */
            public function get_results(string $sql, string $output = 'OBJECT'): array
            {
                $this->query($sql); // return deliberately ignored, as wpdb does

                return array_values($this->last_result);
            }

            public function get_row(string $sql, string $output = 'OBJECT', int $y = 0): ?object
            {
                $this->query($sql); // return deliberately ignored, as wpdb does
                $row = $this->last_result[0] ?? null;

                return is_object($row) ? $row : null;
            }

            public function get_var(string $sql, int $x = 0, int $y = 0): ?string
            {
                $this->query($sql); // return deliberately ignored, as wpdb does
                $row = $this->last_result[0] ?? null;
                if (!is_object($row)) {
                    return null;
                }
                $values = array_values(get_object_vars($row));
                $first  = $values[0] ?? null;

                return is_scalar($first) ? (string) $first : null;
            }

            /** @param array<string, mixed> $where */
            public function delete(string $table, array $where, mixed $formats = null): int
            {
                return 1;
            }

            /** Statements matching a fragment, for asserting what ran. */
            public function sentMatching(string $fragment): array
            {
                return array_values(array_filter(
                    $this->sent,
                    static fn (string $sql): bool => str_contains($sql, $fragment)
                ));
            }

            /** @return list<object> */
            private function rowsFor(string $sql): array
            {
                foreach ($this->answers as $fragment => $rows) {
                    if (str_contains($sql, (string) $fragment)) {
                        return array_values($rows);
                    }
                }

                return [];
            }

            private static function isRead(string $sql): bool
            {
                $head = strtoupper(ltrim($sql));

                return str_starts_with($head, 'SELECT') || str_starts_with($head, 'SHOW');
            }
        }
    }

    if (!function_exists('wp_cache_get')) {
        function wp_cache_get(string $key, string $group = '', bool $force = false, mixed &$found = null): mixed
        {
            $found = false;
            return false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        function wp_cache_set(string $key, mixed $data, string $group = '', int $expire = 0): bool
        {
            return true;
        }
    }

    if (!function_exists('wp_cache_delete')) {
        function wp_cache_delete(string $key, string $group = ''): bool
        {
            return true;
        }
    }

    // Hook shims. Neutral on purpose: apply_filters hands the value
    // straight back, so nothing in these tests depends on a filter nobody
    // registered. ChainRepository runs its active set through one.
    if (!function_exists('apply_filters')) {
        function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
        {
            return $value;
        }
    }

    if (!function_exists('do_action')) {
        function do_action(string $hook, mixed ...$args): void
        {
        }
    }

    if (!function_exists('has_filter')) {
        function has_filter(string $hook, mixed $callback = false): bool
        {
            return false;
        }
    }

    if (!function_exists('add_filter')) {
        function add_filter(string $hook, mixed $callback, int $priority = 10, int $accepted = 1): bool
        {
            return true;
        }
    }

    if (!function_exists('absint')) {
        function absint(mixed $value): int
        {
            return abs((int) $value);
        }
    }

    // ChainRepository's cached active set reads a transient before it
    // queries. Always a MISS here, so every test drives the real read
    // rather than a warm cache — a cache hit would skip the statement
    // under test and the assertion would pass for the wrong reason.
    if (!function_exists('get_transient')) {
        function get_transient(string $key): mixed
        {
            return false;
        }
    }

    if (!function_exists('set_transient')) {
        function set_transient(string $key, mixed $value, int $expiration = 0): bool
        {
            return true;
        }
    }

    if (!function_exists('delete_transient')) {
        function delete_transient(string $key): bool
        {
            return true;
        }
    }

    if (!function_exists('current_time')) {
        function current_time(string $type = 'mysql', int $gmt = 0): string
        {
            return '2026-01-01 00:00:00';
        }
    }
}

namespace BCC\Core\DB {
    // `CollectionRepository::table()` and `ChainRepository::table()` both
    // resolve through bcc-core's DB. The `false` second argument matters: a
    // plain class_exists() would AUTOLOAD the real class and this guard
    // would never fire.
    if (!class_exists(DB::class, false)) {
        final class DB
        {
            /** The shape the real one yields, e.g. `wp_bcc_onchain_collections`. */
            public static function table(string $name): string
            {
                global $wpdb;

                return $wpdb->prefix . 'bcc_' . $name;
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_coll_log']['error'][] = [$message, $context];
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_coll_log']['warning'][] = [$message, $context];
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
            }

            /** @param array<string, mixed> $context */
            public static function debug(string $message, array $context = []): void
            {
            }

            /** @param array<string, mixed> $context */
            public static function audit(string $message, array $context = []): void
            {
            }
        }
    }
}
