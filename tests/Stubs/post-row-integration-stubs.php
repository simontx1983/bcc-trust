<?php
/**
 * get_post() for integration suites whose code path reaches
 * PageAccess::viewerMaySeeId() (it reads the page's author). Reads the REAL
 * wp_posts row, so a page fixture is decided exactly as stored. Subprocess
 * only: a class and a function declaration cannot be undone.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_Post', false)) {
        final class WP_Post
        {
            public int $ID = 0;
            public int $post_author = 0;
            public string $post_type = '';
            public string $post_status = '';
            public string $post_title = '';
            public string $post_name = '';
        }
    }

    if (!function_exists('get_post')) {
        function get_post($post = null)
        {
            global $wpdb;
            $id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
            if ($id <= 0) {
                return null;
            }
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT ID, post_author, post_type, post_status, post_title, post_name FROM {$wpdb->posts} WHERE ID = %d",
                $id
            ));
            if (!is_object($row)) {
                return null;
            }
            $p              = new WP_Post();
            $p->ID          = (int) $row->ID;
            $p->post_author = (int) $row->post_author;
            $p->post_type   = (string) $row->post_type;
            $p->post_status = (string) $row->post_status;
            $p->post_title  = (string) $row->post_title;
            $p->post_name   = (string) $row->post_name;

            return $p;
        }
    }
}
