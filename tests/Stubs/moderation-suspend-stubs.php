<?php
/**
 * Stubs for ModerationSuspendRevokesTokensTest (subprocess-only).
 *
 * The REAL ModerationService and the REAL JwtToken run. Doubled here are only
 * the infrastructure they touch, at their real FQCNs:
 *   - JwtToken's WP calls (namespace-scoped, so they bind for JwtToken only):
 *     wp_salt / home_url / wp_json_encode / wp_generate_uuid4 and the
 *     user-meta store holding the revocation counter;
 *   - TransactionManager::run (runs the callback; records the call),
 *     AuditLogger::log, EdgeCache::purge, bcc-core Logger;
 *   - Plugin::instance()->userSyncService(), whose sync() creates the row
 *     in the fake repository the test injects.
 *
 * Fixture ($GLOBALS['__bcc_ms']):
 *   tv        array<int,int>  user id → bcc_token_version
 *   rows      array<int,bool> user id → user_info row exists
 *   synced    list<int>       user ids passed to sync()
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support {
    if (!function_exists(__NAMESPACE__ . '\\wp_salt')) {
        function wp_salt(string $scheme = 'auth'): string
        {
            return 'unit-test-fixed-secret-key-0123456789abcdef';
        }
        function home_url(string $path = '/'): string
        {
            return 'https://bcc.test/' . ltrim($path, '/');
        }
        /** @param mixed $data */
        function wp_json_encode($data, int $options = 0, int $depth = 512): string
        {
            return (string) json_encode($data, $options, $depth);
        }
        function wp_generate_uuid4(): string
        {
            return '00000000-0000-4000-8000-000000000000';
        }
        /** @return int */
        function get_user_meta(int $userId, string $key, bool $single = false)
        {
            return $GLOBALS['__bcc_ms']['tv'][$userId] ?? 0;
        }
        /** @param mixed $value */
        function update_user_meta(int $userId, string $key, $value): bool
        {
            $GLOBALS['__bcc_ms']['tv'][$userId] = (int) $value;
            return true;
        }
    }
}

namespace BCC\Trust\Core\Security {
    final class TransactionManager
    {
        /** @return mixed */
        public static function run(callable $callback, ?int $retryAttempts = null)
        {
            return $callback();
        }
    }

    final class AuditLogger
    {
        /** @param array<string, mixed> $data */
        public static function log(string $action, int $targetId, array $data = [], string $targetType = ''): void
        {
            $GLOBALS['__bcc_ms']['audit'][] = $action;
        }
    }
}

namespace BCC\Trust\Infrastructure {
    final class EdgeCache
    {
        public const TAG_MEMBERS = 'members';

        public static function purge(string $tag): void
        {
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $ctx */
            public static function error(string $msg, array $ctx = []): void
            {
                $GLOBALS['__bcc_ms']['errors'][] = $msg;
            }
            /** @param array<string, mixed> $ctx */
            public static function warning(string $msg, array $ctx = []): void
            {
            }
            /** @param array<string, mixed> $ctx */
            public static function info(string $msg, array $ctx = []): void
            {
            }
        }
    }
}

namespace BCC\Trust\Core {
    final class Plugin
    {
        private static ?self $instance = null;

        public static function instance(): self
        {
            return self::$instance ??= new self();
        }

        public function userSyncService(): Services\UserSyncService
        {
            return new class extends Services\UserSyncService {
                public function __construct()
                {
                }

                public function sync(?int $userId = null): int
                {
                    $GLOBALS['__bcc_ms']['synced'][] = (int) $userId;
                    $GLOBALS['__bcc_ms']['rows'][(int) $userId] = true;
                    return 1;
                }
            };
        }
    }
}

namespace BCC\Trust\Core\Services\Admin {
    // The ring/bulk admin handlers are admin-post endpoints: capability +
    // nonce checks up front, then `wp_safe_redirect(); exit;` at the end.
    // Namespace-scoped so they bind for ModerationService only. The redirect
    // THROWS a sentinel so the test process is not killed by `exit`.
    final class RedirectSignal extends \RuntimeException
    {
    }
    function current_user_can(string $cap): bool
    {
        return true;
    }
    function check_admin_referer(string $action): bool
    {
        return true;
    }
    function wp_get_referer(): string
    {
        return '';
    }
    function admin_url(string $path = ''): string
    {
        return 'https://bcc.test/wp-admin/' . ltrim($path, '/');
    }
    /** @param mixed ...$args */
    function add_query_arg(...$args): string
    {
        return '';
    }
    function wp_safe_redirect(string $url): bool
    {
        throw new RedirectSignal('redirect');
    }
}

namespace {
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return 1;
        }
    }
    if (!function_exists('wp_get_current_user')) {
        function wp_get_current_user(): object
        {
            return (object) ['display_name' => 'admin'];
        }
    }
    if (!function_exists('absint')) {
        /** @param mixed $v */
        function absint($v): int
        {
            return abs((int) $v);
        }
    }
    if (!function_exists('esc_html')) {
        function esc_html(string $s): string
        {
            return htmlspecialchars($s, ENT_QUOTES);
        }
    }
}
