<?php
/**
 * F-255 stubs for WatchingScaleIntegrationTest (subprocess-only).
 *
 * The harness has real tables but no WordPress post API and no PeepSo:
 *   - WP_Post / get_post() / _prime_post_caches(): read `wp_posts` through
 *     the real $wpdb, so page resolution runs on the seeded rows;
 *   - PeepSoUserFollower: the slice of PeepSo's class that bcc-core's
 *     PeepSoFollowWriter::unfollow() uses (load the pair, `is_follower`,
 *     `follow`, set('follow', 0)), writing the real `peepso_user_followers`
 *     row exactly as PeepSo does (uf_follow = 0, the row kept).
 */

declare(strict_types=1);

if (!class_exists('WP_Post', false)) {
    final class WP_Post
    {
        public int $ID = 0;
        public string $post_type = '';
        public string $post_status = '';
        public int $post_author = 0;
        public string $post_title = '';
        public string $post_name = '';
    }
}

if (!function_exists('get_post')) {
    function get_post($id = null)
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_type, post_status, post_author, post_title, post_name FROM {$wpdb->posts} WHERE ID = %d",
            (int) $id
        ));
        if (!is_object($row)) {
            return null;
        }
        $post              = new WP_Post();
        $post->ID          = (int) $row->ID;
        $post->post_type   = (string) $row->post_type;
        $post->post_status = (string) $row->post_status;
        $post->post_author = (int) $row->post_author;
        $post->post_title  = (string) $row->post_title;
        $post->post_name   = (string) $row->post_name;

        return $post;
    }
}

if (!function_exists('_prime_post_caches')) {
    function _prime_post_caches(array $ids, bool $updateTermCache = true, bool $updateMetaCache = true): void
    {
    }
}

if (!class_exists('PeepSoUserFollower', false)) {
    final class PeepSoUserFollower
    {
        public bool $is_follower = false;
        public int $follow = 0;

        public function __construct(private int $passive, private int $active, bool $create = false)
        {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT uf_follow FROM {$wpdb->prefix}peepso_user_followers
                  WHERE uf_active_user_id = %d AND uf_passive_user_id = %d LIMIT 1",
                $this->active,
                $this->passive
            ));
            if (is_object($row)) {
                $this->is_follower = true;
                $this->follow      = (int) $row->uf_follow;
            }
        }

        public function set(string $key, int $value): void
        {
            global $wpdb;
            if ($key === 'follow') {
                $wpdb->update(
                    $wpdb->prefix . 'peepso_user_followers',
                    ['uf_follow' => $value],
                    ['uf_active_user_id' => $this->active, 'uf_passive_user_id' => $this->passive]
                );
                $this->follow = $value;
            }
        }
    }
}
