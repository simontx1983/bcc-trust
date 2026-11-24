<?php
/**
 * Stubs for ValidatorVoteAccountWriteTest (F-550): just enough of WordPress
 * and bcc-core for ValidatorRepository::bulkUpsert() to run against a
 * recording $wpdb double. Loaded from setUp() in a separate process, so
 * nothing here leaks into other suites.
 */

declare(strict_types=1);

namespace BCC\Core\DB {

    if (!class_exists(__NAMESPACE__ . '\\DB', false)) {
        final class DB
        {
            public static function table(string $name): string
            {
                return 'wp_bcc_' . $name;
            }
        }
    }
}

namespace {

    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }

    if (!function_exists('current_time')) {
        function current_time(string $type, bool $gmt = false): string
        {
            return '2026-11-23 12:00:00';
        }
    }

    if (!function_exists('wp_cache_delete')) {
        function wp_cache_delete(string $key, string $group = ''): bool
        {
            return true;
        }
    }

    /**
     * A $wpdb double: prepare() substitutes its arguments the way wpdb does
     * for these placeholders (%s quoted, %d int, %f float), query() records
     * every statement, and get_results() returns the rows a test queued.
     */
    final class BccVoteAccountFakeWpdb
    {
        public string $prefix = 'wp_';

        /** @var list<string> */
        public array $queries = [];

        /** @var list<object> */
        public array $existing = [];

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            $i = 0;
            return (string) preg_replace_callback('/%[sdf]/', static function (array $m) use (&$i, $args): string {
                $arg = $args[$i++] ?? null;
                return match ($m[0]) {
                    '%d'    => (string) (int) $arg,
                    '%f'    => (string) (float) $arg,
                    default => "'" . addslashes((string) $arg) . "'",
                };
            }, $sql);
        }

        /** @return list<object> */
        public function get_results(string $sql): array
        {
            $this->queries[] = $sql;
            return $this->existing;
        }

        public function query(string $sql): int
        {
            $this->queries[] = $sql;
            return 1;
        }

        /** The INSERT and UPDATE statements, whitespace collapsed. @return list<string> */
        public function writes(): array
        {
            $out = [];
            foreach ($this->queries as $q) {
                $q = (string) preg_replace('/\s+/', ' ', trim($q));
                if (str_starts_with($q, 'INSERT') || str_starts_with($q, 'UPDATE')) {
                    $out[] = $q;
                }
            }
            return $out;
        }
    }
}
