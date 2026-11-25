<?php
/**
 * Stubs for CardCoverWiringTest — enough WordPress and bcc-core for the
 * MEMBER list-card builder to run end to end for an ANONYMOUS viewer.
 *
 * ⚠⚠ SUBPROCESS-ONLY. Declares bcc-core's `PeepSoMediaCache`, and a class
 * declaration cannot be undone.
 *
 * ⭐ Deliberately thin, and the thinness is the design: with `$viewerId = 0`
 * every viewer-specific branch of the builder short-circuits (attestations,
 * votes, watches), so this needs no doubles for them. What is left is the
 * member's own data.
 *
 * Fixture ($GLOBALS['__bcc_ccw']):
 *   covers[<user_id>] => string   what the media cache answers for a cover
 */

declare(strict_types=1);

namespace {
    if (!isset($GLOBALS['__bcc_ccw'])) {
        /** @var array{covers: array<int, string>} */
        $GLOBALS['__bcc_ccw'] = ['covers' => []];
    }

    /** @return array{covers: array<int, string>, meta: array<int, array<string, mixed>>} */
    function bcc_ccw_fixture(): array
    {
        $f = $GLOBALS['__bcc_ccw'] ?? [];

        return [
            'covers' => is_array($f['covers'] ?? null) ? $f['covers'] : [],
            'meta'   => is_array($f['meta'] ?? null) ? $f['meta'] : [],
        ];
    }

    if (!class_exists('WP_User', false)) {
        class WP_User
        {
            public int $ID = 0;
            public string $user_login = 'stub-member';
            public string $display_name = 'Stub Member';
            public string $description = 'A short bio.';
            public string $user_registered = '2024-01-01 00:00:00';
        }
    }

    if (!class_exists('WP_Post', false)) {
        class WP_Post
        {
            public int $ID = 0;
            public int $post_author = 77;
            public string $post_title = 'Acme Validator';
            public string $post_name = 'acme-validator';
            public string $post_type = 'peepso-page';
            public string $post_status = 'publish';
            public string $post_excerpt = 'A validator page.';
            public string $post_content = '';
            /** The velocity cap reads the entity's age from this. */
            public string $post_date_gmt = '2024-01-01 00:00:00';
            public string $post_date = '2024-01-01 00:00:00';
        }
    }

    if (!function_exists('get_post')) {
        /** @return WP_Post|null */
        function get_post(int $postId = 0)
        {
            if ($postId <= 0) {
                return null;
            }
            $p = new WP_Post();
            $p->ID = $postId;

            return $p;
        }
    }

    if (!function_exists('get_userdata')) {
        /** @return WP_User|false */
        function get_userdata(int $userId)
        {
            if ($userId <= 0) {
                return false;
            }
            $u = new WP_User();
            $u->ID = $userId;

            return $u;
        }
    }

    if (!function_exists('get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            return $single ? '' : [];
        }
    }

    if (!function_exists('get_post_meta')) {
        /** @return mixed */
        function get_post_meta(int $postId, string $key = '', bool $single = false)
        {
            // `_bcc_page_type` must match the kind the test asks for, or
            // the builder answers null before reaching any field.
            if ($key === '_bcc_page_type') {
                return $single ? 'validator' : ['validator'];
            }

            $value = \bcc_ccw_fixture()['meta'][$postId][$key] ?? null;
            if ($value === null) {
                return $single ? '' : [];
            }

            return $single ? $value : [$value];
        }
    }

    if (!function_exists('esc_url_raw')) {
        function esc_url_raw(string $url): string
        {
            return filter_var($url, FILTER_VALIDATE_URL) === false ? '' : $url;
        }
    }

    if (!function_exists('content_url')) {
        function content_url(string $path = ''): string
        {
            return 'https://cms.example.test/wp-content' . $path;
        }
    }

    // The crest's image fallback for a page.
    if (!function_exists('get_the_post_thumbnail_url')) {
        /** @return string|false */
        function get_the_post_thumbnail_url(int $postId = 0, string $size = 'post-thumbnail')
        {
            unset($postId, $size);

            return false;
        }
    }

    if (!function_exists('get_permalink')) {
        /** @return string|false */
        function get_permalink(int $postId = 0)
        {
            return 'https://example.test/?p=' . $postId;
        }
    }

    if (!function_exists('get_option')) {
        /** @param mixed $default @return mixed */
        function get_option(string $name, $default = false)
        {
            unset($name);

            return $default;
        }
    }

    if (!function_exists('apply_filters')) {
        /** @param mixed $value @return mixed */
        function apply_filters(string $hook, $value, ...$args)
        {
            unset($hook, $args);

            return $value;
        }
    }

    if (!function_exists('esc_html')) {
        function esc_html(string $text): string
        {
            return $text;
        }
    }

    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field(string $text): string
        {
            return trim(strip_tags($text));
        }
    }

    if (!function_exists('wp_strip_all_tags')) {
        function wp_strip_all_tags(string $text, bool $removeBreaks = false): string
        {
            unset($removeBreaks);

            return strip_tags($text);
        }
    }

    if (!function_exists('home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://example.test' . $path;
        }
    }

    if (!function_exists('site_url')) {
        function site_url(string $path = ''): string
        {
            return 'https://cms.example.test' . $path;
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(PeepSoMediaCache::class, false)) {
        final class PeepSoMediaCache
        {
            public static function coverPhotoUrl(int $userId): ?string
            {
                $url = \bcc_ccw_fixture()['covers'][$userId] ?? null;

                return is_string($url) && $url !== '' ? $url : null;
            }

            public static function avatarUrl(int $userId): string
            {
                unset($userId);

                return 'https://cms.example.test/wp-content/peepso/avatar.png';
            }

            /**
             * @param  list<int> $userIds
             * @return array<int, string>
             */
            public static function avatarUrlBulk(array $userIds): array
            {
                $out = [];
                foreach ($userIds as $id) {
                    $out[(int) $id] = 'https://cms.example.test/wp-content/peepso/avatar.png';
                }

                return $out;
            }
        }
    }
}

namespace BCC\Trust\Core\Services {
    /**
     * ⚠⚠ DECLARED, NOT EXTENDED. The real `UserViewService` is FINAL and
     * `CardViewService` holds it in a readonly TYPED property, so it can
     * neither be subclassed nor swapped for a different class. Declaring
     * the FQN here first wins for the process — the same mechanism
     * `user-meta-repository-double.php` uses, and the reason this file is
     * subprocess-only.
     *
     * Only what the member card builder actually reads: `getSummary()`'s
     * six keys, and the static `resolveFlags()` that `buildFlags()`
     * delegates to. Shaped from the builder's own reads rather than from an
     * assumption about the class.
     */
    if (!class_exists(UserViewService::class, false)) {
        class UserViewService
        {
            /**
             * @param  array<string, mixed>|null $prefetched
             * @return array<string, mixed>|null
             */
            public function getSummary(int $userId, int $viewerId, ?array $prefetched = null): ?array
            {
                unset($viewerId, $prefetched);

                if ($userId <= 0) {
                    return null;
                }

                // ⚠ Every key is here because the BUILDER reads it, found by
                // running it rather than by reading the class — the summary's
                // own shape is much wider, and copying all of it would make
                // this double a second definition of that shape.
                return [
                    'is_self'               => false,
                    'privacy'               => [],
                    'member_state'          => 'established',
                    'rank_label'            => 'Journeyman',
                    'current_rank_label'    => 'Journeyman',
                    'reputation_tier_label' => 'Trusted',
                    // Already privacy-narrowed by getSummary; the card reads
                    // the narrowed values so it holds no privacy logic.
                    'followers_count'       => 31,
                    'following_count'       => 9,
                    'engagement'            => ['reviews_written' => 4],
                    // The member_dossier block, copied straight from
                    // getSummary's already-resolved sub-arrays by the builder.
                    'primary_hall'          => null,
                    'halls'                 => [],
                    'verifications'         => [],
                    'owned_pages_by_type'   => [],
                ];
            }

            /** @return list<string> */
            public static function resolveFlags(int $userId): array
            {
                unset($userId);

                return [];
            }
        }
    }

    /**
     * ⚠⚠ DECLARED for the same reason: `AttestationService` is FINAL too.
     *
     * Only `getViewerActionPermissions()`, and only the three entries
     * `attestationPermToCardPerm()` reads from each — `allowed` and
     * `unlock_hint`. The real service decides those from the viewer's tier;
     * an anonymous viewer is refused, which is what this returns.
     */
    if (!class_exists(AttestationService::class, false)) {
        class AttestationService
        {
            /** @return array<string, array{allowed: bool, unlock_hint: string|null}> */
            public function getViewerActionPermissions(int $viewerId, int $targetUserId): array
            {
                unset($viewerId, $targetUserId);

                $refused = ['allowed' => false, 'unlock_hint' => 'Sign in to do this.'];

                return [
                    'can_vouch'        => $refused,
                    'can_stand_behind' => $refused,
                    'can_report'       => $refused,
                ];
            }
        }
    }
}
