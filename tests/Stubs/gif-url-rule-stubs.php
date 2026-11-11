<?php
/**
 * Stubs for GifUrlSharedRuleTest (subprocess-only): the two WP functions
 * GifRepository::findByPostIds touches. Post meta comes from
 * $GLOBALS['__bcc_gif_meta'] (post_id => peepso_giphy value).
 */

declare(strict_types=1);

if (!function_exists('update_meta_cache')) {
    function update_meta_cache($type, $ids)
    {
        return true;
    }
}

if (!function_exists('get_post_meta')) {
    /** @return mixed */
    function get_post_meta($postId, $key = '', $single = false)
    {
        if ($key !== 'peepso_giphy') {
            return '';
        }
        return $GLOBALS['__bcc_gif_meta'][(int) $postId] ?? '';
    }
}
