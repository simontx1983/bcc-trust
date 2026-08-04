<?php
/**
 * Shared unit-test stubs for the Rank domain (Phase 5.1 R1 suite).
 *
 * Namespaced WP-function fakes intercept the Rank services' calls
 * (PHP resolves unqualified functions namespace-first), and guarded
 * FQN class stubs stand in for bcc-core statics that the unit
 * bootstrap never loads. All guarded — first definition wins across
 * the suite (house convention: "fake-at-FQN, guarded").
 *
 * @package BCC\Trust\Tests
 */

declare(strict_types=1);

// ── Namespaced WP-function fakes for BCC\Trust\Rank\Services ─────────

namespace BCC\Trust\Rank\Services {
    if (!function_exists(__NAMESPACE__ . '\\get_post')) {
        /**
         * Registry-backed get_post fake. Tests seed
         * RankUnitWpFakes::$posts[id] with an object{post_status, post_author}.
         *
         * @return object|null
         */
        function get_post(int $id): ?object
        {
            return \BCC\Trust\Tests\Stubs\RankUnitWpFakes::$posts[$id] ?? null;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\do_action')) {
        /** Capture-only action fake. */
        function do_action(string $hook, mixed ...$args): void
        {
            \BCC\Trust\Tests\Stubs\RankUnitWpFakes::$actions[] = [$hook, $args];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\get_user_meta')) {
        /**
         * Registry-backed user-meta fake (Phase 8 decay-warning gate).
         * Mirrors WP's single=true contract: '' when absent.
         */
        function get_user_meta(int $userId, string $key, bool $single = false): mixed
        {
            unset($single);
            return \BCC\Trust\Tests\Stubs\RankUnitWpFakes::$userMeta[$userId][$key] ?? '';
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\update_user_meta')) {
        function update_user_meta(int $userId, string $key, mixed $value): bool
        {
            \BCC\Trust\Tests\Stubs\RankUnitWpFakes::$userMeta[$userId][$key] = $value;
            return true;
        }
    }
}

// ── Namespaced WP-function fakes for BCC\Trust\Rank\Repositories ─────
// (generation-counter cache bumps in the real repositories)

namespace BCC\Trust\Rank\Repositories {
    if (!function_exists(__NAMESPACE__ . '\\wp_cache_incr')) {
        function wp_cache_incr(string $key, int $offset = 1, string $group = ''): int|false
        {
            unset($key, $offset, $group);
            return false; // route to the wp_cache_set fallback branch
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_cache_set')) {
        function wp_cache_set(string $key, mixed $value, string $group = '', int $expire = 0): bool
        {
            unset($key, $value, $group, $expire);
            return true;
        }
    }
}

// ── Registries the fakes read/write ──────────────────────────────────

namespace BCC\Trust\Tests\Stubs {
    /**
     * Query-recording $wpdb fake for the real (final)
     * ContentReportRepository: `get_var` returns the configured
     * upheld-report count and records every SQL string.
     */
    final class FakeReportWpdb
    {
        public string $prefix = 'wp_';

        /** @var list<string> */
        public array $queries = [];

        public int $upheldCount = 0;

        public function prepare(string $sql, mixed ...$args): string
        {
            unset($args);
            return $sql;
        }

        public function get_var(string $sql): string
        {
            $this->queries[] = $sql;
            return (string) $this->upheldCount;
        }
    }

    final class RankUnitWpFakes
    {
        /** @var array<int, object> */
        public static array $posts = [];

        /** @var list<array{0: string, 1: array<int, mixed>}> */
        public static array $actions = [];

        /** @var array<int, array<string, mixed>> user_id => key => value */
        public static array $userMeta = [];

        public static function reset(): void
        {
            self::$posts    = [];
            self::$actions  = [];
            self::$userMeta = [];
        }

        /** @return list<string> Hook names fired, in order. */
        public static function firedHooks(): array
        {
            return array_map(static fn (array $a): string => $a[0], self::$actions);
        }
    }
}

// ── Guarded FQN stubs for bcc-core statics ───────────────────────────

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function audit(string $m, array $c = []): void {}
        }
    }
}

namespace BCC\Core\Permissions {
    if (!class_exists(Permissions::class, false)) {
        // Shape-compatible with UserViewFlagsTest's guarded stub (the
        // suite's first-defined stub wins — all definitions must agree).
        final class Permissions
        {
            /** @var array<int, int> userIds currently suspended */
            public static array $suspended = [];

            public static function is_not_suspended(?int $userId = null, bool $allowAdminBypass = true): bool
            {
                unset($allowAdminBypass); // matches the WP-side signature; not consulted here
                if ($userId === null) {
                    return true;
                }
                return !in_array($userId, self::$suspended, true);
            }

            public static function owns_page(int $pageId, int $userId): bool
            {
                unset($pageId, $userId);
                return false;
            }
        }
    }
}
