<?php
/**
 * A page's post for tests that name a page (F-578: a review-reply bell
 * speaks as the page). Fixture `$GLOBALS['__bcc_page_titles'][<id>]` holds
 * a page's title; any other id has no post. Subprocess only.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_Post', false)) {
        final class WP_Post
        {
            public int $ID = 0;
            public string $post_title = '';
            public string $post_name = '';
            public string $post_type = 'peepso-page';
            public int $post_author = 0;
        }
    }

    if (!function_exists('get_post')) {
        function get_post($post = null)
        {
            $id    = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
            $title = $GLOBALS['__bcc_page_titles'][$id] ?? null;
            if (!is_string($title)) {
                return null;
            }
            $p             = new WP_Post();
            $p->ID         = $id;
            $p->post_title = $title;
            $p->post_name  = 'page-' . $id;

            return $p;
        }
    }

    if (!function_exists('get_post_meta')) {
        /** @return mixed */
        function get_post_meta(int $postId, string $key = '', bool $single = false)
        {
            return $single ? '' : [];
        }
    }
}
