<?php
/**
 * The few WordPress functions SecretPagesOnMemberListsIntegrationTest needs
 * on top of profile-groups-integration-stubs.php (subprocess-only).
 *
 * The vouches tab titles a page row with get_the_title() and links it with
 * get_permalink(), and the tier goes through sanitize_key(). The title reads
 * the REAL wp_posts row, so a row the suite asserts on carries the fixture's
 * own title.
 */

declare(strict_types=1);

namespace {
    if (!function_exists('get_the_title')) {
        function get_the_title($post = 0): string
        {
            $page = get_post($post);

            return $page instanceof \WP_Post ? $page->post_title : '';
        }
    }

    if (!function_exists('get_permalink')) {
        function get_permalink($post = 0)
        {
            $page = get_post($post);

            return $page instanceof \WP_Post ? 'https://example.test/p/' . $page->post_name : false;
        }
    }

    if (!function_exists('sanitize_key')) {
        function sanitize_key($key): string
        {
            return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
        }
    }
}
