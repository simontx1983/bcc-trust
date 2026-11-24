<?php

declare(strict_types=1);

/**
 * Harness for PostShortcodeRepository's table check (F-472 / F-438).
 *
 * The `$wpdb` double is faithful to the one wpdb behaviour that matters
 * here: `query()` returns false ABOVE `flush()` on a failed reconnect and
 * on an emptied `query` filter, so `last_error` is EMPTY and `last_result`
 * still holds the PREVIOUS statement's rows.
 *
 * ⭐ That is why no `last_error` check can police this path, and why the
 * table check had to ask `existsOrUnknown()` for the three-state answer
 * instead. A double that set `last_error` on failure would let the old
 * code pass and the mutation control would prove nothing.
 *
 * Statements are routed by SQL fragment, so a test can bail the SHOW
 * TABLES probe while the act-id lookup after it still answers — and the
 * other way round. A blanket failure could not tell the two apart, which
 * is the whole distinction under test.
 */

namespace {
    foreach (['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }

    if (!class_exists('BccShortcodeTableCheckFakeWpdb', false)) {
        class BccShortcodeTableCheckFakeWpdb
        {
            public string $prefix     = 'wp_';
            public string $last_error = '';

            /** @var list<object> */
            public array $last_result = [];

            /** @var list<string> Every statement sent, in order. */
            public array $sent = [];

            /** @var array<string, list<object>> Rows per SQL fragment; first match wins. */
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

            public function query(string $sql): int|false
            {
                $this->sent[] = $sql;

                if ($this->bailOn !== null && str_contains($sql, $this->bailOn)) {
                    // THE BAIL. No error recorded, last_result untouched.
                    // The absence of both assignments IS the model.
                    return false;
                }

                $this->last_result = $this->rowsFor($sql);

                return count($this->last_result);
            }

            /** @return list<object> */
            public function get_results(string $sql, string $output = 'OBJECT'): array
            {
                $this->query($sql); // return deliberately ignored, as wpdb does

                return array_values($this->last_result);
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
        }
    }

    // Always a cache MISS, so every test drives the real SHOW TABLES probe.
    // A warm cache would skip the statement under test and the assertion
    // would pass for the wrong reason.
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
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_psc_log'][] = [$message, $context];
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
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
