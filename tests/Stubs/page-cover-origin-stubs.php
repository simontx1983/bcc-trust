<?php
/**
 * Stubs for PageCoverUrlOriginTest — the WordPress functions the shared
 * page-cover reader uses, and nothing else.
 *
 * `content_url` is a FIXTURE rather than a constant, because one test moves
 * the content directory to the domain root to prove the reader fails closed
 * there.
 *
 * Fixture ($GLOBALS['__bcc_pco']):
 *   content_url                  => string   what content_url() answers
 *   meta[<post_id>][<meta_key>]  => mixed    stored post meta (absent = unset)
 */

declare(strict_types=1);

namespace {
    if (!isset($GLOBALS['__bcc_pco'])) {
        /** @var array{content_url: string, meta: array<int, array<string, mixed>>} */
        $GLOBALS['__bcc_pco'] = [
            'content_url' => 'https://cms.example.test/wp-content',
            'meta'        => [],
        ];
    }

    /** @return array{content_url: string, meta: array<int, array<string, mixed>>} */
    function bcc_pco_fixture(): array
    {
        $f = $GLOBALS['__bcc_pco'] ?? [];

        return [
            'content_url' => is_string($f['content_url'] ?? null)
                ? $f['content_url']
                : 'https://cms.example.test/wp-content',
            'meta'        => is_array($f['meta'] ?? null) ? $f['meta'] : [],
        ];
    }

    if (!function_exists('content_url')) {
        function content_url(string $path = ''): string
        {
            return \bcc_pco_fixture()['content_url'] . $path;
        }
    }

    if (!function_exists('get_post_meta')) {
        /** @return mixed */
        function get_post_meta(int $postId, string $key = '', bool $single = false)
        {
            $value = \bcc_pco_fixture()['meta'][$postId][$key] ?? null;
            if ($value === null) {
                return $single ? '' : [];
            }

            return $single ? $value : [$value];
        }
    }

    if (!function_exists('esc_url_raw')) {
        /**
         * Faithful in the one respect the reader depends on: a value that is
         * not a usable URL comes back EMPTY, and the reader turns that into
         * "no cover".
         *
         * ⚠ It deliberately lets a relative path through unchanged — the
         * real `esc_url_raw()` does too — so the reader's own
         * absolute-only check is what refuses those, and this double cannot
         * mask it.
         */
        function esc_url_raw(string $url): string
        {
            if ($url === '') {
                return '';
            }
            if (str_starts_with($url, '/') || !str_contains($url, '://')) {
                return $url;
            }

            return filter_var($url, FILTER_VALIDATE_URL) === false ? '' : $url;
        }
    }
}
