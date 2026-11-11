<?php
/**
 * HiddenActivityRepository unit stubs (F-98 / F-77). Loaded ONLY from a
 * #[RunTestsInSeparateProcesses] suite, so nothing here leaks.
 *
 * The $wpdb double models REAL WPDB failure semantics, the whole point of
 * F-98:
 *   - every query first resets `last_error` (wpdb::flush());
 *   - a failed get_col() returns [] AND sets last_error;
 *   - a failed get_var() returns NULL AND sets last_error.
 * A double that returned false (or threw) would let a fix that only checked
 * `=== false` pass while production still cached [].
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
        /** @var array<int, mixed> */
        private array $args = [];

        public function prepare(string $sql, mixed ...$args): string
        {
            $this->args = $args;
            return $sql;
        }

        /** @return list<string> */
        public function get_col(string $sql): array
        {
            if ($this->fails()) {
                return [];
            }
            [$cursor, $limit] = [(int) $this->args[0], (int) $this->args[1]];
            $ids = array_values(array_filter($this->hidden, static fn (int $i): bool => $i < $cursor));
            rsort($ids);
            return array_map('strval', array_slice($ids, 0, $limit));
        }

        public function get_var(string $sql): ?string
        {
            if ($this->fails()) {
                return null;
            }
            return in_array((int) $this->args[0], $this->hidden, true) ? '1' : '0';
        }

        private function fails(): bool
        {
            $this->queries++;
            $this->last_error = ''; // wpdb::flush() at the start of every query
            $fail = $this->failNext > 0 || ($this->failOnQuery > 0 && $this->queries === $this->failOnQuery);
            if ($this->failNext > 0) {
                $this->failNext--;
            }
            if ($fail) {
                $this->last_error = 'MySQL server has gone away';
            }
            return $fail;
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
