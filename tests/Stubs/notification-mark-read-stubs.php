<?php
/**
 * Stubs for NotificationMarkReadManyTest (F-516).
 *
 * Loaded ONLY inside a PHPUnit subprocess (RunTestsInSeparateProcesses), from
 * setUp(), before any of the classes below is autoloaded, so these
 * declarations win there and nowhere else.
 *
 * Fixture ($GLOBALS['__bcc_mark_read']):
 *   user_id    int                 get_current_user_id()
 *   throttled  bool                true: Throttle::allow() refuses (429)
 *   affected   int|false           what $wpdb->query() answers
 *   queries    list<array{sql: string, args: list<mixed>}>  every statement prepared and run
 *   bumped     list<int>           BadgesService::bumpForUser() calls
 */

declare(strict_types=1);

namespace {
    if (!defined('BCC_NOTIFICATION_MODULE_ID')) {
        define('BCC_NOTIFICATION_MODULE_ID', 9000);
    }

    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_mark_read']['user_id'] ?? 0);
        }
    }

    if (!class_exists('WP_REST_Request')) {
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

            /** As WordPress answers it: the key was sent, whatever its value (null included). */
            public function has_param(string $key): bool
            {
                return array_key_exists($key, $this->params);
            }
        }
    }

    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
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
            }
        }
    }

    final class BccMarkReadFakeWpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';

        /** @var list<mixed> */
        private array $pendingArgs = [];

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            $this->pendingArgs = $args;
            return $sql;
        }

        /** @return int|false */
        public function query(string $sql)
        {
            $GLOBALS['__bcc_mark_read']['queries'][] = ['sql' => $sql, 'args' => $this->pendingArgs];
            return $GLOBALS['__bcc_mark_read']['affected'] ?? 0;
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(Throttle::class, false)) {
        final class Throttle
        {
            public static function allow(string $key, int $limit, int $window, ?string $bucketKey = null, bool $countDenied = true): bool
            {
                return empty($GLOBALS['__bcc_mark_read']['throttled']);
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    // ApiResponse::ok() reaches ReactionTypeRegistry::all(), which reads one option.
    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        /** @return mixed */
        function get_option(string $key, $default = false)
        {
            return '';
        }
    }
}

namespace BCC\Trust\Core\Services {
    if (!class_exists(BadgesService::class, false)) {
        final class BadgesService
        {
            public static function bumpForUser(int $userId): void
            {
                $GLOBALS['__bcc_mark_read']['bumped'][] = $userId;
            }
        }
    }
}

namespace BCC\Trust\Core {
    if (!class_exists(Plugin::class, false)) {
        final class Plugin
        {
            public static function instance(): self
            {
                return new self();
            }

            public function notificationRepository(): Repositories\NotificationRepository
            {
                return new Repositories\NotificationRepository();
            }
        }
    }
}
