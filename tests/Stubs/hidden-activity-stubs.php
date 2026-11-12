<?php
/**
 * HiddenActivityRepository unit stubs (F-98 / F-77). Loaded ONLY from a
 * #[RunTestsInSeparateProcesses] suite, so nothing here leaks.
 *
 * The $wpdb double models REAL WPDB failure semantics, the whole point of
 * F-98:
 *   - query() returns the row count, or false; get_col()/get_var() call it
 *     and DISCARD that return, then read last_result;
 *   - a normal failure resets then sets `last_error` (wpdb::flush());
 *   - the SILENT failure (`! $this->ready`) returns false with last_error
 *     '' and last_result still holding the PREVIOUS query's rows.
 * A double that returned false (or threw) from get_col() would let a fix
 * that only checked `=== false` pass while production still cached [].
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Stubs {
    final class HiddenIdsWpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        /** @var list<int> */
        public array $hidden = [];
        /** Number of upcoming queries that fail (0 = none). */
        public int $failNext = 0;
        /** Fail only the Nth query from now (1-based), 0 = off. */
        public int $failOnQuery = 0;
        public int $queries = 0;
        /**
         * Number of upcoming queries that hit wpdb::query()'s SILENT failure
         * path: `! $this->ready` after a failed reconnect. It returns false
         * BEFORE flush(), so last_error stays '' and last_result keeps the
         * PREVIOUS query's rows. A last_error-only check misses it.
         */
        public int $failSilentNext = 0;
        /** @var list<array<int, string>> rows of the last query that ran (wpdb::$last_result) */
        public array $lastResult = [];
        /** @var array<int, mixed> */
        private array $args = [];

        public function prepare(string $sql, mixed ...$args): string
        {
            $this->args = $args;
            return $sql . '|' . json_encode($args);
        }

        /**
         * wpdb::query(): returns the row count of a SELECT, or false.
         * Populates lastResult only when it actually ran.
         */
        public function query(string $sql): int|false
        {
            $this->queries++;
            if ($this->failSilentNext > 0) {
                $this->failSilentNext--;
                return false; // `! $this->ready`: no flush(), no last_error, stale lastResult
            }
            $this->last_error = ''; // wpdb::flush()
            $fail = $this->failNext > 0 || ($this->failOnQuery > 0 && $this->queries === $this->failOnQuery);
            if ($this->failNext > 0) {
                $this->failNext--;
            }
            if ($fail) {
                $this->last_error = 'MySQL server has gone away';
                $this->lastResult = [];
                return false;
            }
            $this->lastResult = $this->execute($sql);
            return count($this->lastResult);
        }

        /**
         * get_col(): with a query it runs it (and DISCARDS query()'s return, as
         * WPDB does); with null it reads the last result.
         *
         * @return list<string>
         */
        public function get_col(?string $sql = null): array
        {
            if ($sql !== null) {
                $this->query($sql);
            }
            return array_values(array_map(static fn (array $r): string => (string) $r[0], $this->lastResult));
        }

        public function get_var(?string $sql = null): ?string
        {
            if ($sql !== null) {
                $this->query($sql);
            }
            return isset($this->lastResult[0][0]) ? (string) $this->lastResult[0][0] : null;
        }

        /** @return list<array<int, string>> */
        private function execute(string $sql): array
        {
            if (str_contains($sql, 'COUNT(*)')) {
                return [[in_array((int) $this->args[0], $this->hidden, true) ? '1' : '0']];
            }
            [$cursor, $limit] = [(int) $this->args[0], (int) $this->args[1]];
            $ids = array_values(array_filter($this->hidden, static fn (int $i): bool => $i < $cursor));
            rsort($ids);
            return array_map(static fn (int $i): array => [(string) $i], array_slice($ids, 0, $limit));
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    function wp_cache_get($key, string $group = '')
    {
        return $GLOBALS['__hidden_cache'][$group . ':' . $key] ?? false;
    }
    function wp_cache_set($key, $value, string $group = '', int $expire = 0): bool
    {
        $GLOBALS['__hidden_cache'][$group . ':' . $key] = $value;
        return true;
    }
    function wp_cache_incr($key, int $offset = 1, string $group = '')
    {
        $k = $group . ':' . $key;
        $GLOBALS['__hidden_cache'][$k] = (int) ($GLOBALS['__hidden_cache'][$k] ?? 0) + $offset;
        return $GLOBALS['__hidden_cache'][$k];
    }
    function get_option(string $name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['__hidden_options'] ?? []) ? $GLOBALS['__hidden_options'][$name] : $default;
    }
    function update_option(string $name, $value, $autoload = null): bool
    {
        $GLOBALS['__hidden_options'][$name] = $value;
        $GLOBALS['__hidden_option_writes'] = ($GLOBALS['__hidden_option_writes'] ?? 0) + 1;
        return true;
    }
}

// The generation counter is read and bumped through CacheGeneration, whose
// unqualified wp_cache_* calls resolve in its own namespace first: same store.
namespace BCC\Trust\Core\Support {
    function wp_cache_get($key, string $group = '')
    {
        return \BCC\Trust\Core\Repositories\wp_cache_get($key, $group);
    }
    function wp_cache_set($key, $value, string $group = '', int $expire = 0): bool
    {
        return \BCC\Trust\Core\Repositories\wp_cache_set($key, $value, $group, $expire);
    }
    function wp_cache_add($key, $value, string $group = '', int $expire = 0): bool
    {
        if (array_key_exists($group . ':' . $key, $GLOBALS['__hidden_cache'] ?? [])) {
            return false;
        }
        return \BCC\Trust\Core\Repositories\wp_cache_set($key, $value, $group, $expire);
    }
    function wp_cache_incr($key, int $offset = 1, string $group = '')
    {
        return \BCC\Trust\Core\Repositories\wp_cache_incr($key, $offset, $group);
    }
}

namespace BCC\Core\Observability {
    if (!class_exists(__NAMESPACE__ . '\\DegradationMetrics', false)) {
        final class DegradationMetrics
        {
            /** @var list<string> "subsystem:event" */
            public static array $recorded = [];

            public static function record(string $subsystem, string $event): void
            {
                self::$recorded[] = $subsystem . ':' . $event;
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public static array $errors = [];

            public static function error(string $m, array $c = []): void
            {
                self::$errors[] = [$m, $c];
            }
            public static function warning(string $m, array $c = []): void {}
            public static function info(string $m, array $c = []): void {}
        }
    }
}
