<?php
/**
 * Stubs for BlockAnyIdSameAnswerTest ONLY.
 *
 * ⚠ Required from the test's setUp(), inside its separate process, BEFORE
 * the classes below are autoloaded, so these declarations win. Never at file
 * level: a class declared there becomes THE class for the whole unit run.
 *
 * The REAL MyBlocksEndpoint and the REAL bcc-core PeepSoBlockWriter run over
 * an in-memory peepso_blocks table. Everything else is recorded or inert.
 * State lives in `$GLOBALS['__bcc_blk']`.
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_blk']['viewer'] ?? 0);
        }
    }

    /** Only REAL_USER exists. Recorded so a test can tell nobody asked. */
    if (!function_exists('get_userdata')) {
        function get_userdata(int $userId): object|false
        {
            $GLOBALS['__bcc_blk']['userdata_reads'][] = $userId;
            return in_array($userId, $GLOBALS['__bcc_blk']['users'] ?? [], true) ? (object) ['ID' => $userId] : false;
        }
    }

    if (!function_exists('do_action')) {
        function do_action(string $hook, mixed ...$args): void
        {
            $GLOBALS['__bcc_blk']['actions'][] = $hook;
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
        }
    }

    if (!class_exists('WP_REST_Response')) {
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
                $this->headers[$key] = $value;
            }

            /** @return array<string, string> */
            public function get_headers(): array
            {
                return $this->headers;
            }
        }
    }

    /** An in-memory wp_peepso_blocks for PeepSoBlockWriter's COUNT and INSERT. */
    final class BccBlockAnyIdFakeWpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        /** @var list<array{blk_user_id: int, blk_blocked_id: int}> */
        public array $blocks = [];

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            return vsprintf(str_replace('%s', "'%s'", $sql), $args);
        }

        public function get_var(string $sql): ?string
        {
            if (preg_match('/blk_user_id = (\d+)\s+AND blk_blocked_id = (\d+)/', $sql, $m) === 1) {
                $n = 0;
                foreach ($this->blocks as $row) {
                    $n += (int) ($row['blk_user_id'] === (int) $m[1] && $row['blk_blocked_id'] === (int) $m[2]);
                }
                return (string) $n;
            }
            return null;
        }

        /**
         * @param array<string, int> $data
         * @param list<string> $format
         */
        public function insert(string $table, array $data, array $format = []): int|false
        {
            if ($table !== 'wp_peepso_blocks') {
                return false;
            }
            $this->blocks[] = ['blk_user_id' => (int) $data['blk_user_id'], 'blk_blocked_id' => (int) $data['blk_blocked_id']];
            return 1;
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(__NAMESPACE__ . '\\Throttle', false)) {
        final class Throttle
        {
            public static function allow(string $key, int $limit, int $window, ?string $bucketKey = null, bool $countDenied = true): bool
            {
                $GLOBALS['__bcc_blk']['throttle'][] = [$key, $limit, $window];
                return (bool) ($GLOBALS['__bcc_blk']['throttle_allow'] ?? true);
            }
        }
    }
}

namespace BCC\Core\DB {
    if (!class_exists(__NAMESPACE__ . '\\AdvisoryLock', false)) {
        final class AdvisoryLock
        {
            public static function acquire(string $key, int $timeout = 0): bool
            {
                return true;
            }

            public static function release(string $key): bool
            {
                return true;
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
                $GLOBALS['__bcc_blk']['errors'][] = $m;
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

namespace BCC\Trust\Core\Security {
    if (!class_exists(__NAMESPACE__ . '\\AuditLogger', false)) {
        final class AuditLogger
        {
            /** @param array<string, mixed> $meta */
            public static function log(string $action, ?int $targetId = null, array $meta = [], ?string $targetType = null, ?int $userId = null): void
            {
                $GLOBALS['__bcc_blk']['audit'][] = [$action, $targetId];
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    // ReactionTypeRegistry (inside ApiResponse::ok) reads one option.
    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function get_option(string $key, $default = false)
        {
            return $default;
        }
    }
}

namespace BCC\Trust\Core {
    // The follow cleanup after a block: recorded, nothing else.
    if (!class_exists(__NAMESPACE__ . '\\Plugin', false)) {
        final class Plugin
        {
            private static ?self $instance = null;

            public static function instance(): self
            {
                return self::$instance ??= new self();
            }

            public function watchingService(): object
            {
                return new class {
                    public function severFollowsBetween(int $a, int $b): void
                    {
                        $GLOBALS['__bcc_blk']['severed'][] = [$a, $b];
                    }
                };
            }
        }
    }
}
