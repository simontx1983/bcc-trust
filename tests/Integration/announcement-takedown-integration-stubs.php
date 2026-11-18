<?php
/**
 * WordPress functions AnnouncementModerationService needs that the
 * integration harness does not define. Each reads or writes the REAL
 * wp_posts / wp_postmeta rows, so a takedown is a claim about MySQL.
 * Loaded only by AnnouncementTakedownIntegrationTest, in its own process.
 */

declare(strict_types=1);

if (!class_exists('WP_Post')) {
    final class WP_Post
    {
        public int $ID = 0;
        public int $post_author = 0;
        public int $post_parent = 0;
        public string $post_type = '';
        public string $post_status = '';
        public string $post_title = '';
        public string $post_content = '';
        public string $post_name = '';
        public string $post_date_gmt = '';
    }
}

if (!function_exists('get_post')) {
    function get_post($post = null)
    {
        $postId = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
        if ($postId <= 0) {
            return null;
        }
        $wpdb = $GLOBALS['wpdb'];
        $row  = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_author, post_parent, post_type, post_status, post_title, post_content, post_name, post_date_gmt
               FROM `{$wpdb->posts}` WHERE ID = %d LIMIT 1",
            $postId
        ));
        if ($row === null) {
            return null;
        }
        $out = new WP_Post();
        foreach (get_object_vars($row) as $k => $v) {
            if (property_exists($out, $k)) {
                $out->$k = in_array($k, ['ID', 'post_author', 'post_parent'], true) ? (int) $v : (string) $v;
            }
        }
        return $out;
    }
}

if (!function_exists('get_post_field')) {
    function get_post_field(string $field, $post = null)
    {
        $p = get_post($post);
        return $p !== null && property_exists($p, $field) ? $p->$field : '';
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $postId, string $key, $value = ''): bool
    {
        $wpdb = $GLOBALS['wpdb'];
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->postmeta}` WHERE post_id = %d AND meta_key = %s",
            $postId,
            $key
        )) !== false;
    }
}

if (!function_exists('clean_post_cache')) {
    /** The harness reads meta from SQL, so there is no cache to clean. */
    function clean_post_cache($post): void
    {
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string
    {
        return trim(strip_tags($text));
    }
}
