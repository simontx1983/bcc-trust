<?php

declare(strict_types=1);

/**
 * F-469 batch 3: fakes for the reputation WRITERS' unknown cases
 * (ReputationWriterUnknownTest). Load only in a separate process.
 *
 * The final repositories (AttestationRepository, PeepSoReactionRepository)
 * run for real against a $wpdb double that answers get_results() by SQL
 * fragment; the non-final ReputationRepository is subclassed by the test.
 */

namespace {
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    final class ReputationWriterWorld
    {
        /** @var list<string> "level: message context-json" */
        public static array $logs = [];

        /** @var list<string> "subsystem.event" */
        public static array $metrics = [];

        /** @var array<int, array<string, mixed>> */
        public static array $userMeta = [];

        /** @var list<string> */
        public static array $actions = [];

        /** @var array<string, list<mixed>> SQL fragment => rows a read of it answers */
        public static array $results = [];

        /**
         * @var array<string, string> SQL fragment => how a read of it fails:
         *   'error'  — query() returns false and sets last_error (wpdb::flush ran);
         *   'silent' — query() returns false with NO error and last_result
         *              untouched (wpdb's pre-flush early return).
         */
        public static array $failures = [];

        public static function reset(): void
        {
            self::$logs     = [];
            self::$metrics  = [];
            self::$userMeta = [];
            self::$actions  = [];
            self::$results  = [];
            self::$failures = [];
        }

        public static function logged(string $needle): bool
        {
            foreach (self::$logs as $line) {
                if (str_contains($line, $needle)) {
                    return true;
                }
            }

            return false;
        }
    }

    if (!function_exists('get_user_meta')) {
        function get_user_meta(int $userId, string $key = '', bool $single = false): mixed
        {
            return ReputationWriterWorld::$userMeta[$userId][$key] ?? '';
        }
    }

    if (!function_exists('update_user_meta')) {
        function update_user_meta(int $userId, string $key, mixed $value): bool
        {
            ReputationWriterWorld::$userMeta[$userId][$key] = $value;

            return true;
        }
    }

    if (!function_exists('do_action')) {
        function do_action(string $hook, mixed ...$args): void
        {
            ReputationWriterWorld::$actions[] = $hook;
        }
    }

    if (!function_exists('apply_filters')) {
        function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
        {
            return $value;
        }
    }

    /*
     * A $wpdb that models wpdb::query() faithfully, because repositories read
     * two ways: get_results(), and runRead()/trustedRows(), which call query()
     * and then read last_result / last_error themselves.
     */
    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';

        public string $last_error = '';

        /** @var list<mixed> */
        public array $last_result = [];

        public function prepare(string $query, mixed ...$args): string
        {
            return $query;
        }

        /**
         * wpdb::query(): a read that runs flushes, fills last_result and
         * answers the row count; a failing one answers false (see
         * ReputationWriterWorld::$failures for the two ways).
         */
        public function query(string $query): int|false
        {
            $failure = self::lookup(ReputationWriterWorld::$failures, $query);
            if ($failure === 'silent') {
                return false; // early return: no flush, no error, stale last_result
            }

            $this->last_error  = '';
            $this->last_result = [];
            if ($failure === 'error') {
                $this->last_error = 'MySQL server has gone away';

                return false;
            }

            /** @var list<mixed> $rows */
            $rows = self::lookup(ReputationWriterWorld::$results, $query) ?? [];
            $this->last_result = $rows;

            return count($rows);
        }

        /**
         * wpdb::get_results(): runs query() and answers last_result, so a
         * failed read answers [] after a flush (and stale rows after the
         * early return), as real wpdb does.
         *
         * @return list<mixed>
         */
        public function get_results(?string $query = null, string $output = 'OBJECT'): array
        {
            if ($query !== null) {
                $this->query($query);
            }

            return $this->last_result;
        }

        /** @param array<string, mixed> $map */
        private static function lookup(array $map, string $query): mixed
        {
            foreach ($map as $fragment => $value) {
                if (str_contains($query, $fragment)) {
                    return $value;
                }
            }

            return null;
        }
    };
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void
            {
                \ReputationWriterWorld::$logs[] = 'info: ' . $m . ' ' . json_encode($c);
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
                \ReputationWriterWorld::$logs[] = 'warning: ' . $m . ' ' . json_encode($c);
            }

            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                \ReputationWriterWorld::$logs[] = 'error: ' . $m . ' ' . json_encode($c);
            }
        }
    }
}

namespace BCC\Core\Observability {
    if (!class_exists(__NAMESPACE__ . '\\DegradationMetrics', false)) {
        final class DegradationMetrics
        {
            public static function record(string $subsystem, string $event = 'activation'): void
            {
                \ReputationWriterWorld::$metrics[] = $subsystem . '.' . $event;
            }
        }
    }
}
