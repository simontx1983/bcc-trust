<?php
/**
 * Stubs for CardCoverPhotoTest (§3.2 `cover_photo_url`, Structure A row 17).
 *
 * ⚠⚠ SUBPROCESS-ONLY. Declares bcc-core's `PeepSoMediaCache`, and a class
 * declaration cannot be undone — the first one wins for the rest of the
 * process, so a later test in a shared process could not load the real one.
 * The consuming test is `#[RunTestsInSeparateProcesses]` +
 * `#[PreserveGlobalState(false)]`.
 *
 * ⭐ The double COUNTS ITS CALLS. That is not decoration: resolving a
 * member's cover uncached constructs a `PeepSoUser`, which is a per-user
 * `peepso_users` SELECT — once per card in a list. A test can therefore
 * assert that the card goes through the cached seam rather than reaching
 * PeepSo directly, which is what stops the call being "simplified" away.
 *
 * Fixture ($GLOBALS['__bcc_ccp']):
 *   meta[<post_id>][<meta_key>] => mixed   stored post meta (absent = unset)
 *   covers[<user_id>]           => string  what the media cache answers
 */

declare(strict_types=1);

namespace {
    if (!isset($GLOBALS['__bcc_ccp'])) {
        /** @var array{meta: array<int, array<string, mixed>>, covers: array<int, string>} */
        $GLOBALS['__bcc_ccp'] = ['meta' => [], 'covers' => []];
    }

    /** @return array{meta: array<int, array<string, mixed>>, covers: array<int, string>} */
    function bcc_ccp_fixture(): array
    {
        $f = $GLOBALS['__bcc_ccp'] ?? [];

        return [
            'meta'   => is_array($f['meta'] ?? null) ? $f['meta'] : [],
            'covers' => is_array($f['covers'] ?? null) ? $f['covers'] : [],
        ];
    }

    if (!class_exists('WP_Post', false)) {
        class WP_Post
        {
            public int $ID = 0;
        }
    }

    if (!function_exists('get_post_meta')) {
        /** @return mixed */
        function get_post_meta(int $postId, string $key = '', bool $single = false)
        {
            $value = \bcc_ccp_fixture()['meta'][$postId][$key] ?? null;
            if ($value === null) {
                return $single ? '' : [];
            }

            return $single ? $value : [$value];
        }
    }

    // PageCoverUrl rebases the stored value onto this origin.
    if (!function_exists('content_url')) {
        function content_url(string $path = ''): string
        {
            return 'https://cms.example.test/wp-content' . $path;
        }
    }

    if (!function_exists('esc_url_raw')) {
        function esc_url_raw(string $url): string
        {
            // Faithful in the one respect the caller depends on: a value
            // that is not a usable URL comes back empty, and the caller
            // turns that into "no cover".
            return filter_var($url, FILTER_VALIDATE_URL) === false ? '' : $url;
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(PeepSoMediaCache::class, false)) {
        final class PeepSoMediaCache
        {
            /** @var list<int> every user id a cover was asked for, in order */
            public static array $coverCalls = [];

            public static function coverPhotoUrl(int $userId): ?string
            {
                self::$coverCalls[] = $userId;

                $url = \bcc_ccp_fixture()['covers'][$userId] ?? null;

                return is_string($url) && $url !== '' ? $url : null;
            }
        }
    }
}
