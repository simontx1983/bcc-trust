<?php

declare(strict_types=1);

/**
 * WP + PeepSo shims for PageDiscoveryService's page avatar/cover resolution.
 *
 * Complements `page-discovery-chain-applied-stubs.php` (sanitizers, cache,
 * DB, DegradationMetrics) rather than restating it — both are guarded, so a
 * test may require either or both.
 *
 * ⭐ `PeepSoPage` here COUNTS ITS CONSTRUCTIONS. That is not decoration:
 * the real `PeepSoPage::get_avatar_url()` does a `file_exists()` per call
 * and calls `set_avatar_custom_flag()`, whose short-circuit compares
 * `0 == $current_val` against an absent meta (`''`) — and under PHP 8
 * `0 == ''` is FALSE, so it does not fire for exactly the pages that have
 * no avatar. A test can therefore assert that avatar-less pages never
 * reach it, which is the whole reason the accessor is gated on the upload
 * hash instead of being called unconditionally.
 */

namespace {
    if (!isset($GLOBALS['__bcc_pm'])) {
        /** @var array<int, array<string, mixed>> post_id => meta_key => value */
        $GLOBALS['__bcc_pm'] = [];
    }
    if (!isset($GLOBALS['__bcc_thumb'])) {
        /** @var array<int, string> post_id => featured-image URL */
        $GLOBALS['__bcc_thumb'] = [];
    }

    if (!class_exists('PeepSoPage', false)) {
        class PeepSoPage
        {
            /** How many times a page object was built — see the file docblock. */
            public static int $constructed = 0;

            /** @var array<int, string> post_id => avatar URL the real file would yield */
            public static array $avatarUrls = [];

            /** @var array<int, string> post_id => default, used when no file exists */
            public static string $defaultAvatarUrl = 'https://peepso.example/default-avatar.jpg';

            private int $id;

            /**
             * ⚠ The REAL signature, `__construct($id = NULL, $data = NULL)`
             * (peepso-pages/classes/models/page.php:92) — not the `int $id`
             * this double first had. PHPStan caught the difference against
             * the phpstan-bootstrap stub, which was itself wrong; a double
             * shaped to my assumption rather than to the real class would
             * have passed this suite and failed in production.
             *
             * @param int|string|null                  $id
             * @param array<string, mixed>|object|null $data
             */
            public function __construct($id = null, $data = null)
            {
                $this->id = (int) $id;
                self::$constructed++;
            }

            /**
             * Faithful in the one respect that matters: it NEVER returns
             * empty. With no avatar file the real one answers
             * `get_default_avatar_url()`, which is why calling it
             * unconditionally would make the caller's featured-image
             * fallback unreachable.
             */
            /**
             * ⚠ UNTYPED, like the real `get_avatar_url($full = TRUE)`
             * (page.php:502). The first version of this double wrote
             * `(bool $full = true): string`, which is tighter than the
             * plugin — the same mistake as the constructor above, and
             * the matching phpstan stub's `: string` made a caller's
             * `is_string()` guard look redundant.
             *
             * @param mixed $full
             * @return mixed
             */
            public function get_avatar_url($full = true)
            {
                return self::$avatarUrls[$this->id] ?? self::$defaultAvatarUrl;
            }

            public static function reset(): void
            {
                self::$constructed = 0;
                self::$avatarUrls  = [];
            }
        }
    }

    if (!function_exists('get_post_meta')) {
        /** @return mixed */
        function get_post_meta(int $postId, string $key = '', bool $single = false)
        {
            $value = $GLOBALS['__bcc_pm'][$postId][$key] ?? null;
            if ($value === null) {
                return $single ? '' : [];
            }

            return $single ? $value : [$value];
        }
    }

    if (!function_exists('get_the_post_thumbnail_url')) {
        /** @return string|false */
        function get_the_post_thumbnail_url(int $postId = 0, string $size = 'post-thumbnail')
        {
            return $GLOBALS['__bcc_thumb'][$postId] ?? false;
        }
    }

    if (!function_exists('get_permalink')) {
        /** @return string|false */
        function get_permalink(int $postId = 0)
        {
            return 'https://example.test/?p=' . $postId;
        }
    }

    if (!function_exists('esc_url_raw')) {
        function esc_url_raw(string $url): string
        {
            return $url;
        }
    }

    if (!function_exists('get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            return $single ? '' : [];
        }
    }
}
