<?php

declare(strict_types=1);

/**
 * Harness for EdgeRepository::backfillFromSources() (F-472).
 *
 * The point of this file is a FAITHFUL `$wpdb`, because the defect it
 * exists to pin is invisible to a kind one.
 *
 * `wpdb::query()` returns false ABOVE its own `flush()` on two paths — a
 * failed reconnect (`! $this->ready`) and an emptied `query` filter. On
 * either, `last_error` is EMPTY and `last_result` still holds the
 * PREVIOUS statement's rows. `get_results()` discards query()'s return
 * value and reads `last_result`, so it hands those rows back as if they
 * were this query's own, and no `last_error` check can see it.
 *
 * So the double here:
 *   - records every statement in order (`$sent`), reads and writes alike,
 *     which is how the test asserts that NOTHING was upserted;
 *   - leaves `last_result` populated from the last successful READ, so a
 *     bail genuinely has another query's rows sitting there;
 *   - on a bail returns false and touches NEITHER `last_error` NOR
 *     `last_result`, exactly as wpdb does above flush();
 *   - keeps `get_results()` faithful: it calls `query()`, DISCARDS the
 *     return value and reads `last_result`. That is what makes the
 *     mutation control meaningful — restoring the old
 *     `get_results(...) ?: []` must fail the test, and it can only fail
 *     if the double is as careless as wpdb is.
 *
 * The bail is scoped to the backfill's OWN aggregate SELECT (the one
 * reading the votes table with a GROUP BY). The table-existence probes
 * that `hiddenReviewExclusion()` runs first still answer, so they are
 * what leaves the stale rows behind — a real "previous query", not a
 * contrived one.
 */

namespace {
    // WordPress time constants. TableRegistry's existence-cache TTL is one of
    // them, and a class constant built from an undefined constant is an Error
    // the moment it is read — not at class load, so it surfaces as a failure
    // inside the method under test.
    foreach (['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }

    if (!class_exists('BccEdgeBackfillFakeWpdb', false)) {
        class BccEdgeBackfillFakeWpdb
        {
            public string $prefix     = 'wp_';
            public string $last_error = '';

            /** @var list<object> */
            public array $last_result = [];

            public int $insert_id     = 0;
            public int $rows_affected = 0;

            /** @var list<string> Every statement sent, in order. */
            public array $sent = [];

            /** @var list<array{table: string, where: array<string, mixed>}> */
            public array $deletes = [];

            /** @var list<object> What a successful READ leaves in last_result. */
            public array $resultRows = [];

            /** Make the backfill's own aggregate SELECT bail pre-flush. */
            public bool $bailOnBackfillRead = false;

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

                        return "'" . (string) $v . "'";
                    },
                    $query
                );

                return (string) $out;
            }

            /**
             * Returns the row count for a read and the affected-row count for
             * a write, as wpdb does — never a result set.
             */
            public function query(string $sql): int|false
            {
                $this->sent[] = $sql;

                if ($this->bailOnBackfillRead && self::isBackfillRead($sql)) {
                    // THE BAIL. Above flush(): no error is recorded and
                    // last_result is left exactly as the previous statement
                    // left it. The absence of an assignment here is the point.
                    return false;
                }

                if (self::isRead($sql)) {
                    $this->last_result = $this->resultRows;

                    return count($this->resultRows);
                }

                $this->last_result   = [];
                $this->rows_affected = 1;

                return 1;
            }

            /**
             * wpdb's own: calls query(), THROWS THE RETURN VALUE AWAY and
             * reads last_result. Kind doubles that return [] on failure are
             * why a defect of this shape survives review.
             *
             * @return list<object>
             */
            public function get_results(string $sql, string $output = 'OBJECT'): array
            {
                $this->query($sql); // return deliberately ignored, as wpdb does

                return array_values($this->last_result);
            }

            /** @param array<string, mixed> $where */
            public function delete(string $table, array $where, mixed $formats = null): int
            {
                $this->deletes[] = ['table' => $table, 'where' => $where];

                return 1;
            }

            /**
             * Every statement that WROTE an edge row.
             *
             * @return list<string>
             */
            public function edgeUpserts(): array
            {
                return array_values(array_filter(
                    $this->sent,
                    static fn (string $sql): bool => str_contains($sql, 'INSERT INTO')
                        && str_contains($sql, 'bcc_trust_edges')
                ));
            }

            private static function isRead(string $sql): bool
            {
                $head = strtoupper(ltrim($sql));

                return str_starts_with($head, 'SELECT') || str_starts_with($head, 'SHOW');
            }

            /**
             * The backfill's own aggregate read — not the existence probes
             * that run before it, and not the upsert.
             */
            private static function isBackfillRead(string $sql): bool
            {
                return str_contains($sql, 'bcc_trust_votes')
                    && str_contains($sql, 'GROUP BY');
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

    if (!function_exists('current_time')) {
        function current_time(string $type = 'mysql', int $gmt = 0): string
        {
            return '2026-01-01 00:00:00';
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
                $GLOBALS['__bcc_edge_log'][] = [$message, $context];
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
