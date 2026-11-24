<?php

declare(strict_types=1);

/**
 * Harness for BadgesDisputesOpenTest. Load only in a separate process.
 *
 *  - an in-memory object cache (get / set / add / incr);
 *  - a $wpdb double: get_var() answers the notification count; query()
 *    models wpdb faithfully for the fail-closed dispute read. A read that runs
 *    flushes, fills last_result and answers the row count; 'error' answers
 *    false with last_error set; 'silent' answers false with no error and a
 *    stale last_result (wpdb's pre-flush early return);
 *  - bcc-core doubles: DB::table, Logger, PeepSoBlockRepository (no blocks),
 *    PeepSoMessageRepository (a fixed unread count).
 *
 * Script it with $GLOBALS['__bcc_bd'] = ['open' => int, 'mode' => 'ok'|'error'|'silent'].
 */

namespace {
    if (!function_exists('wp_cache_get')) {
        function wp_cache_get(string $key, string $group = '', bool $force = false, mixed &$found = null): mixed
        {
            $k = $group . ':' . $key;
            $found = array_key_exists($k, $GLOBALS['__bcc_bd_cache'] ?? []);

            return $found ? $GLOBALS['__bcc_bd_cache'][$k] : false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        function wp_cache_set(string $key, mixed $data, string $group = '', int $expire = 0): bool
        {
            $GLOBALS['__bcc_bd_cache'][$group . ':' . $key] = $data;

            return true;
        }
    }

    if (!function_exists('wp_cache_add')) {
        function wp_cache_add(string $key, mixed $data, string $group = '', int $expire = 0): bool
        {
            $k = $group . ':' . $key;
            if (array_key_exists($k, $GLOBALS['__bcc_bd_cache'] ?? [])) {
                return false;
            }
            $GLOBALS['__bcc_bd_cache'][$k] = $data;

            return true;
        }
    }

    if (!function_exists('wp_cache_incr')) {
        function wp_cache_incr(string $key, int $offset = 1, string $group = ''): int|false
        {
            $k = $group . ':' . $key;
            if (!array_key_exists($k, $GLOBALS['__bcc_bd_cache'] ?? [])) {
                return false;
            }
            $GLOBALS['__bcc_bd_cache'][$k] = (int) $GLOBALS['__bcc_bd_cache'][$k] + $offset;

            return $GLOBALS['__bcc_bd_cache'][$k];
        }
    }

    final class BadgesDisputesWpdb
    {
        public string $prefix = 'wp_';

        public string $last_error = '';

        /** @var list<object> */
        public array $last_result = [];

        /** @var list<array{0: string, 1: list<mixed>}> */
        public array $prepared = [];

        public int $disputeReads = 0;

        public function prepare(string $sql, mixed ...$args): string
        {
            $this->prepared[] = [$sql, $args];

            return $sql;
        }

        public function get_var(?string $sql = null): ?string
        {
            return '4'; // unread notifications
        }

        public function query(string $sql): int|false
        {
            $this->disputeReads++;
            $mode = $GLOBALS['__bcc_bd']['mode'] ?? 'ok';
            if ($mode === 'silent') {
                return false; // early return: no flush, no error, stale last_result
            }
            $this->last_error  = '';
            $this->last_result = [];
            if ($mode === 'error') {
                $this->last_error = 'MySQL server has gone away';

                return false;
            }
            $this->last_result = [(object) ['n' => (string) ($GLOBALS['__bcc_bd']['open'] ?? 0)]];

            return 1;
        }
    }
}

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

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
            }

            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void
            {
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\PeepSoBlockRepository', false)) {
        final class PeepSoBlockRepository
        {
            /** @return list<int> */
            public static function getBlockedIdsOrUnknown(int $userId, int $limit = 0): ?array
            {
                return [];
            }

            /** @return list<int> */
            public static function getBlockerIdsOrUnknown(int $userId, int $limit = 0): ?array
            {
                return [];
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\PeepSoMessageRepository', false)) {
        final class PeepSoMessageRepository
        {
            public static function getUnreadConversationCountForUser(int $userId): int
            {
                return 2;
            }

            /**
             * @param list<int> $threadIds
             * @return array<int, array{latest_message_id: int, posted_at: string}>
             */
            public static function getLatestPerThreadForViewer(int $viewerId, array $threadIds): array
            {
                return [];
            }
        }
    }
}
