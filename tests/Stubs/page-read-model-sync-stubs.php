<?php
/**
 * WordPress shims for PageReadModelSyncIntegrationTest.
 *
 * `PageReadModelRepository::syncPage()` asks WordPress two things the shared
 * integration bootstrap does not answer:
 *
 *   - `get_post()`      — the ghost-row guard (is this still a published
 *                         `peepso-page`?) and the owner resolver's final
 *                         fallback (`post_author`).
 *   - `get_user_meta()` — the owner's PeepSo follower count.
 *
 * `get_post()` reads the REAL `wp_posts` row, so "published peepso-page" is a
 * fact about MySQL, not about a fixture array. The bootstrap's shared
 * `wp_posts` table carries no `post_author` column, so authorship — which only
 * the resolver's last fallback consults — comes from a test-controlled map.
 *
 * Guarded by `function_exists` and loaded only by the test that needs them, so
 * no other integration test inherits a shim it never asked for.
 */

declare(strict_types=1);

if (!function_exists('get_post')) {
    /**
     * @param int|object $post
     * @return object|null
     */
    function get_post($post)
    {
        $id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
        if ($id <= 0) {
            return null;
        }

        $wpdb = $GLOBALS['wpdb'];
        $row  = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_type, post_status FROM `{$wpdb->posts}` WHERE ID = %d",
            $id
        ));
        if ($row === null) {
            return null;
        }

        $row->post_author = (int) ($GLOBALS['__bcc_rm_test_post_authors'][$id] ?? 0);

        return $row;
    }
}

if (!function_exists('get_user_meta')) {
    /**
     * @return mixed
     */
    function get_user_meta(int $userId, string $key = '', bool $single = false)
    {
        $value = $GLOBALS['__bcc_rm_test_user_meta'][$userId][$key] ?? null;

        if ($single) {
            // WordPress returns '' (not null/false) for a missing key.
            return $value ?? '';
        }

        return $value === null ? [] : [$value];
    }
}
