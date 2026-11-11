<?php

/**
 * The only doubles BlogTabGroupVisibilityIntegrationTest needs.
 *
 * Deliberately TINY. Everything that decides visibility — the group rows,
 * the membership rows, the per-post markers, the deny list, the SQL — is
 * real and in MySQL. What is doubled here is only the session identity and
 * the author display block, neither of which the gate consults.
 *
 * `get_current_user_id()` follows the house convention
 * (`$GLOBALS['__bcc_current_user']`) used by the other integration suites.
 */

declare(strict_types=1);

namespace {
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_current_user'] ?? 0);
        }
    }

    // hydrateAuthor() takes its early exit on false, so the author block is
    // a constant and no user plumbing is pulled into a visibility test.
    if (!function_exists('get_userdata')) {
        function get_userdata(int $userId)
        {
            return false;
        }
    }

    if (!function_exists('get_avatar_url')) {
        function get_avatar_url($idOrEmail, array $args = []): string
        {
            return '';
        }
    }

    if (!function_exists('get_user_meta')) {
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            return $single ? '' : [];
        }
    }

    // ── Two functions this read path needs that the shared harness does
    //    not provide, because no integration suite had exercised
    //    BlogService::getUserBlog or GroupContextResolver before.
    //
    // Found by RUNNING the suite in CI, not by reading it: three of the
    // nine failures were these, not the MysqliWpdb TypeError. Worth the
    // note — "the harness is broken" was the convenient explanation and
    // it was only two thirds true.

    /**
     * Minimal WP_Post. `BlogService::hydrateForPostId` guards on
     * `instanceof \WP_Post`, so a plain object is silently treated as "no
     * post" and the body comes back empty — a test would then pass for
     * the wrong reason.
     */
    if (!class_exists('WP_Post')) {
        final class WP_Post
        {
            public int $ID = 0;
            public int $post_author = 0;
            public string $post_type = '';
            public string $post_status = '';
            public string $post_title = '';
            public string $post_excerpt = '';
            public string $post_content = '';
            public string $post_name = '';
            public string $post_date_gmt = '';
        }
    }

    /**
     * Reads the REAL row, so the body under test is the row the fixtures
     * inserted rather than a hand-made object.
     */
    if (!function_exists('get_post')) {
        function get_post($post = null)
        {
            $postId = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
            if ($postId <= 0) {
                return null;
            }

            $wpdb = $GLOBALS['wpdb'];
            $row  = $wpdb->get_row($wpdb->prepare(
                "SELECT ID, post_author, post_type, post_status, post_title,
                        post_excerpt, post_content, post_name, post_date_gmt
                   FROM `{$wpdb->posts}` WHERE ID = %d LIMIT 1",
                $postId
            ));
            if ($row === null) {
                return null;
            }

            $out = new WP_Post();
            foreach (get_object_vars($row) as $k => $v) {
                if (property_exists($out, $k)) {
                    $out->$k = in_array($k, ['ID', 'post_author'], true) ? (int) $v : (string) $v;
                }
            }

            return $out;
        }
    }

    /**
     * Cache priming only — `GroupContextResolver::forManyGroups()` calls
     * it before reading post-meta. A no-op is faithful here precisely
     * because this harness reads meta from real SQL rather than a cache,
     * so there is nothing to prime and nothing to go stale.
     */
    if (!function_exists('_prime_post_caches')) {
        function _prime_post_caches(array $ids, bool $updateTermCache = true, bool $updateMetaCache = true): void
        {
        }
    }
}
