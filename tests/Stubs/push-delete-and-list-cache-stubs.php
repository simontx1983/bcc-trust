<?php
/**
 * Stubs for PushSubscriptionDeleteNoOracleTest and
 * ViewerScopedListCacheVaryTest.
 *
 * ⚠⚠ SUBPROCESS-ONLY. This declares `\WP_REST_Request`,
 * `\WP_REST_Response`, `\BCC\Core\Security\Throttle` and
 * `\BCC\Core\Log\Logger`. A class declaration cannot be undone and the
 * first one wins for the rest of the process, so both consuming tests are
 * `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]`.
 *
 * The REAL MyPushSubscriptionEndpoint::revoke() and the REAL
 * PushSubscriptionRepository run on top of a fake `$wpdb` that keeps rows
 * in memory and applies `delete()`'s WHERE the way wpdb does (every key
 * must match). The SQL itself is proven against a real database by
 * PushEndpointHandoverIntegrationTest.
 *
 * Fixture ($GLOBALS['__bcc_pdl']):
 *   user_id    int                       what get_current_user_id() returns
 *   rows       array<int, int>           subscription id => owner user id
 *   fail       bool                      delete() returns false (DB error)
 *   deletes    list<array<string,int>>   WHERE arrays delete() was given
 *   reads      list<string>              read methods that were called
 *   errors     list<string>              Logger::error() messages
 */

declare(strict_types=1);

namespace BCC\Core\Security {
    if (!class_exists(Throttle::class, false)) {
        class Throttle
        {
            public static function allow(string $key, int $limit, int $window, ?string $bucketKey = null): bool
            {
                unset($key, $limit, $window, $bucketKey);
                return true;
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        class Logger
        {
            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                unset($context);
                $GLOBALS['__bcc_pdl']['errors'][] = $message;
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    // ApiResponse's envelope reaches ReactionTypeRegistry::all(), which
    // reads an option. The default ("nothing configured") is the
    // registry's own documented path.
    if (!\function_exists('BCC\Trust\Core\Support\get_option')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function get_option(string $key, $default = false)
        {
            unset($key);
            return $default;
        }
    }
}

namespace {
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_pdl']['user_id'] ?? 0);
        }
    }

    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            /** @param array<string, mixed> $params */
            public function __construct(private array $params = [])
            {
            }

            /** @return mixed */
            public function get_param(string $key)
            {
                return $this->params[$key] ?? null;
            }
        }
    }

    if (!class_exists('WP_REST_Response', false)) {
        class WP_REST_Response
        {
            /** @var array<string, string> */
            private array $headers = [];

            /** @param mixed $data */
            public function __construct(private $data = null, private int $status = 200)
            {
            }

            /** @return mixed */
            public function get_data()
            {
                return $this->data;
            }

            public function get_status(): int
            {
                return $this->status;
            }

            public function header(string $key, string $value, bool $replace = true): void
            {
                unset($replace);
                $this->headers[$key] = $value;
            }

            /** @return array<string, string> */
            public function get_headers(): array
            {
                return $this->headers;
            }
        }
    }

    final class BccPushDeleteFakeWpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';

        /**
         * @param array<string, int> $where
         * @param list<string>       $formats
         * @return int|false
         */
        public function delete(string $table, array $where, array $formats = [])
        {
            unset($table, $formats);
            $GLOBALS['__bcc_pdl']['deletes'][] = $where;
            if (!empty($GLOBALS['__bcc_pdl']['fail'])) {
                $this->last_error = 'Deadlock found when trying to get lock';
                return false;
            }

            $removed = 0;
            foreach ($GLOBALS['__bcc_pdl']['rows'] as $id => $owner) {
                $row = ['id' => $id, 'user_id' => $owner];
                $matches = true;
                foreach ($where as $col => $val) {
                    if (!array_key_exists($col, $row) || $row[$col] !== (int) $val) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    unset($GLOBALS['__bcc_pdl']['rows'][$id]);
                    $removed++;
                }
            }
            return $removed;
        }

        /** @return null */
        public function get_row(string $sql)
        {
            unset($sql);
            $GLOBALS['__bcc_pdl']['reads'][] = 'get_row';
            return null;
        }

        /** @return array<int, mixed> */
        public function get_results(string $sql): array
        {
            unset($sql);
            $GLOBALS['__bcc_pdl']['reads'][] = 'get_results';
            return [];
        }

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            unset($args);
            return $sql;
        }
    }
}
