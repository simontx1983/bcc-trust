<?php
/**
 * Doubles for BlogOwnershipOracleTest (F-47): just enough WordPress for the
 * blog edit-load (BlogService::getBlogForEdit) and edit-write
 * (PostsService::updateBlog) to reach their ownership gate.
 *
 * Posts live in $GLOBALS['__bcc_blog_posts'] (id => WP_Post) and their meta in
 * $GLOBALS['__bcc_blog_meta'] (id => key => value).
 *
 * Required from setUp() of a #[RunTestsInSeparateProcesses] class, never at
 * file scope: PHPUnit loads test files in the parent process too.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_Post', false)) {
        class WP_Post
        {
            public int $ID = 0;
            public string $post_type = '';
            public string $post_status = '';
            public int|string $post_author = 0;
        }
    }
}

namespace BCC\Trust\Core\Services {
    if (!function_exists(__NAMESPACE__ . '\\get_post')) {
        /** @return \WP_Post|null */
        function get_post(int $postId)
        {
            return $GLOBALS['__bcc_blog_posts'][$postId] ?? null;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\get_post_meta')) {
        /** @return mixed */
        function get_post_meta(int $postId, string $key = '', bool $single = false)
        {
            return $GLOBALS['__bcc_blog_meta'][$postId][$key] ?? '';
        }
    }
}
