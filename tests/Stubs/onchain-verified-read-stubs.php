<?php

declare(strict_types=1);

/**
 * Harness for the remaining five onchain repositories' verified reads
 * (F-472).
 *
 * Same contract as the CollectionRepository harness beside it, and the
 * same reason for existing: the `$wpdb` double is FAITHFUL, because the
 * failure under test is invisible to a kind one.
 *
 * `wpdb::query()` returns false ABOVE its own `flush()` on a failed
 * reconnect and on an emptied `query` filter. On either, `last_error` is
 * EMPTY and `last_result` still holds the PREVIOUS statement's rows. So
 * on a bail this double returns false and touches NEITHER — the absence
 * of those two assignments is the whole model — while `get_results()`,
 * `get_row()` and `get_var()` are as careless as wpdb's: each calls
 * `query()`, DISCARDS the return value and reads `last_result`.
 *
 * Statements are routed by SQL fragment, so a test can bail ONE read
 * while every other statement in the same code path still answers. That
 * matters for `addCuUsage()`, which wraps its read in an explicit
 * transaction: a blanket failure would break `START TRANSACTION` and the
 * method would never reach the read under test.
 *
 * `update()` and the transaction verbs are recorded too, because one test
 * asserts a ROLLBACK happened.
 */

namespace {
    foreach (['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }

    if (!class_exists('BccOnchainReadFakeWpdb', false)) {
        class BccOnchainReadFakeWpdb
        {
            public string $prefix     = 'wp_';
            public string $last_error = '';

            /** @var list<object> */
            public array $last_result = [];

            public int $insert_id     = 0;
            public int $rows_affected = 0;

            public string $postmeta = 'wp_postmeta';
            public string $posts    = 'wp_posts';
            public string $users    = 'wp_users';
            public string $usermeta = 'wp_usermeta';

            /** @var list<string> Every statement sent, in order. */
            public array $sent = [];

            /** @var list<array{table: string, data: array<string, mixed>}> */
            public array $updates = [];

            /** @var array<string, list<object>> Rows per SQL fragment; first match wins. */
            public array $answers = [];

            /** Fragment of the ONE statement that bails pre-flush. */
            public ?string $bailOn = null;

            public function prepare(string $query, mixed ...$args): string
            {
                // Flatten the array form, `prepare($sql, [$a, $b])`, which
                // NftHoldingsRepository uses for its IN() list.
                if (count($args) === 1 && is_array($args[0])) {
                    $args = array_values($args[0]);
                }

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

            public function query(string $sql): int|false
            {
                $this->sent[] = $sql;

                if ($this->bailOn !== null && str_contains($sql, $this->bailOn)) {
                    // THE BAIL. No error recorded, last_result untouched.
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

            /** @param array<string, mixed> $data */
            public function update(string $table, array $data, mixed $where = null, mixed $f = null, mixed $wf = null): int
            {
                $this->updates[] = ['table' => $table, 'data' => $data];

                return 1;
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

    // ⚠ FORMAT-AWARE ON PURPOSE. A stub that ignores its format argument is
    // the same class of mistake as a $wpdb double kinder than wpdb:
    // `addCuUsage()` compares `current_time('Y-m-d', true)` against a stored
    // reset date, so a stub answering a datetime for every format sends it
    // down the "new day, reset the counter" branch in every test and the
    // increment path is never exercised.
    //
    // The instant is fixed so the tests are deterministic.
    if (!function_exists('current_time')) {
        function current_time(string $type = 'mysql', int $gmt = 0): string|int
        {
            $ts = 1767225600; // 2026-01-01 00:00:00 UTC

            if ($type === 'timestamp') {
                return $ts;
            }

            return gmdate($type === 'mysql' ? 'Y-m-d H:i:s' : $type, $ts);
        }
    }
}

namespace BCC\Core\DB {
    // The `false` matters: a plain class_exists() would AUTOLOAD the real
    // class and this guard would never fire.
    if (!class_exists(DB::class, false)) {
        final class DB
        {
            /** The shape the real one yields, e.g. `wp_bcc_onchain_wallets`. */
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
                $GLOBALS['__bcc_onchain_log']['error'][] = [$message, $context];
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_onchain_log']['warning'][] = [$message, $context];
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
