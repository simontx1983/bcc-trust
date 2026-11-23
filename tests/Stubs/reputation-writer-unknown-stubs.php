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

        /** @var array<string, list<mixed>> SQL fragment => rows get_results() answers */
        public static array $results = [];

        public static function reset(): void
        {
            self::$logs     = [];
            self::$metrics  = [];
            self::$userMeta = [];
            self::$actions  = [];
            self::$results  = [];
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

    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';

        public string $last_error = '';

        public function prepare(string $query, mixed ...$args): string
        {
            return $query;
        }

        /** @return list<mixed> */
        public function get_results(?string $query = null, string $output = 'OBJECT'): array
        {
            foreach (ReputationWriterWorld::$results as $fragment => $rows) {
                if ($query !== null && str_contains($query, $fragment)) {
                    return $rows;
                }
            }

            return [];
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
