<?php
/**
 * Blog-create test stubs (audit HIGH #1 — draft lifecycle).
 *
 * Loaded ONLY from inside a #[RunTestsInSeparateProcesses] subprocess
 * (BlogDraftMarkerTest) so the main PHPUnit process never sees these
 * definitions. Lets PostsService::createBlog run to completion for a
 * draft/publish without WordPress, PeepSo, bcc-core, or a real DB:
 *
 *   - WP functions are defined IN the production namespaces where the
 *     code calls them (unqualified calls resolve to the current namespace
 *     before global), backed by recorder arrays in $GLOBALS:
 *       $GLOBALS['__bcc_blog_meta_writes'] = [[postId, key, value], ...]
 *       $GLOBALS['__bcc_blog_actions']     = [[hook, args], ...]
 *   - The three bcc-core statics createBlog reaches (Throttle, the blog
 *     writer, DB) are faked at their FQN — bcc-core is not autoloaded in
 *     this process, so these ARE the classes createBlog links against.
 *   - $GLOBALS['wpdb'] is a no-op double so the real (final)
 *     BlogChainTagRepository::replace($id, []) — a bounded delete with an
 *     empty insert set — runs harmlessly.
 *
 * All definitions are guarded so a second require / the autoloader leaves
 * them alone.
 */

declare(strict_types=1);

namespace {
    if (!defined('BCC_TRUST_RATE_LIMIT_STATUS_POST')) {
        define('BCC_TRUST_RATE_LIMIT_STATUS_POST', 5);
    }
    if (!defined('BCC_TRUST_RATE_WINDOW_STATUS_POST')) {
        define('BCC_TRUST_RATE_WINDOW_STATUS_POST', 120);
    }

    if (!class_exists('BccBlogFakeWpdb', false)) {
        final class BccBlogFakeWpdb
        {
            public string $prefix = 'wp_';
            /** @param array<string, mixed> $where @param list<string>|null $fmt */
            public function delete(string $table, array $where, ?array $fmt = null): int
            {
                return 1;
            }
            /** @param array<string, mixed> $data @param list<string>|null $fmt */
            public function insert(string $table, array $data, ?array $fmt = null): int
            {
                return 1;
            }
        }
    }
    $GLOBALS['wpdb'] = new \BccBlogFakeWpdb();
}

namespace BCC\Trust\Core\Services {
    if (!function_exists('BCC\\Trust\\Core\\Services\\update_post_meta')) {
        function update_post_meta($postId, $key, $value)
        {
            $GLOBALS['__bcc_blog_meta_writes'][] = [(int) $postId, (string) $key, $value];
            return true;
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Services\\do_action')) {
        function do_action($hook, ...$args)
        {
            $GLOBALS['__bcc_blog_actions'][] = [(string) $hook, $args];
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Services\\sanitize_text_field')) {
        function sanitize_text_field($value)
        {
            return is_string($value) ? trim(strip_tags($value)) : '';
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Services\\wp_json_encode')) {
        function wp_json_encode($data, $flags = 0)
        {
            return json_encode($data, (int) $flags);
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Services\\set_post_thumbnail')) {
        function set_post_thumbnail($postId, $thumbnailId)
        {
            return true;
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    // BlogChainTagRepository::replace() bumps a cache generation counter
    // after the (faked) delete. Object-cache no-ops are fine for the test.
    if (!function_exists('BCC\\Trust\\Core\\Repositories\\wp_cache_incr')) {
        function wp_cache_incr($key, $offset = 1, $group = '')
        {
            return 1;
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Repositories\\wp_cache_get')) {
        function wp_cache_get($key, $group = '', $force = false, &$found = null)
        {
            $found = false;
            return false;
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Repositories\\wp_cache_set')) {
        function wp_cache_set($key, $data, $group = '', $expire = 0)
        {
            return true;
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists('BCC\\Core\\Security\\Throttle', false)) {
        final class Throttle
        {
            public static function allow(string $key, int $limit, int $window): bool
            {
                return true;
            }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists('BCC\\Core\\PeepSo\\PeepSoStatusWriter', false)) {
        final class PeepSoStatusWriter
        {
            public static function createSelfBlogPost(
                int $authorId,
                string $excerpt,
                string $fullText,
                ?string $title,
                string $status
            ): int {
                $GLOBALS['__bcc_blog_last_create'] = ['author' => $authorId, 'status' => $status];
                return 123;
            }
        }
    }
}

namespace BCC\Core\DB {
    if (!class_exists('BCC\\Core\\DB\\DB', false)) {
        final class DB
        {
            public static function table(string $name): string
            {
                return 'wp_bcc_' . $name;
            }
        }
    }
}
