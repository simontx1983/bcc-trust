<?php
/**
 * MyProfilePrefsEndpoint — handles /bcc/v1/me/profile-prefs.
 *
 * Two routes for the Preferences sub-tab on /settings/profile (V2 Phase 2.5):
 *
 *   GET   /me/profile-prefs   — read current values (with defaults applied)
 *   PATCH /me/profile-prefs   — partial update; missing keys untouched
 *
 * Body shape (PATCH) — every field optional:
 *   {
 *     "profile_visibility":     "public" | "members" | "private",
 *     "post_visibility":        "members" | "private",
 *     "hide_birthday_year":     bool,
 *     "hide_online":            bool,
 *     "hide_from_search":       bool,
 *     "default_post_audience":  "public" | "members" | "private"
 *   }
 *
 * Storage:
 *   - profile_visibility     → `peepso_users.usr_profile_acc` column (custom
 *                               PeepSo table). Read via
 *                               `PeepSoUser::get_profile_accessibility()`,
 *                               write via `update_peepso_user(['usr_profile_acc'])`.
 *                               This is the column PeepSo's own user-search
 *                               joins against, so writing through here keeps
 *                               search and feed gating coherent.
 *   - post_visibility        → `peepso_profile_post_acc` user_meta. Read via
 *                               `PeepSoUser::get_profile_post_accessibility()`,
 *                               write directly with `update_user_meta`. PeepSo
 *                               strips PUBLIC from this option's UI (you can't
 *                               let the world post on your wall), so we
 *                               restrict the wire vocabulary to MEMBERS /
 *                               PRIVATE — same as PeepSo's `access-profile-post`
 *                               select.
 *   - hide_birthday_year     → `peepso_hide_birthday_year` user_meta, bool
 *                               stored as "1"/"0".
 *   - hide_online            → `peepso_hide_online_status` user_meta, "1"/"0".
 *                               PeepSo's online-presence indicators (profile
 *                               page, member widgets) treat "1" as "hide the
 *                               green dot for this user".
 *   - hide_from_search       → `peepso_is_hide_profile_from_user_listing`
 *                               user_meta, "1"/"0". PeepSo's user-search
 *                               joins on this key — "1" excludes the user
 *                               from the directory and member search results
 *                               (direct profile links still work).
 *   - default_post_audience  → `peepso_last_used_post_privacy` user_meta, int
 *                               10/20/40 matching PeepSo::ACCESS_*. Read by
 *                               PeepSo's posting UI to default the audience
 *                               picker on new wall posts. Note that PeepSo
 *                               itself overwrites this on every post — this
 *                               setting lets the user manually nudge the
 *                               default forward.
 *
 * Auth: required. Self-only.
 *
 * Cache: `no-store`.
 *
 * @package BCC\Trust\Core\REST
 * @since V2 Phase 2.5 (Preferences sub-tab)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MyProfilePrefsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** PeepSo access constants — duplicated as ints to keep the file phpstan-clean
     *  even when PeepSo isn't loaded. Verified against peepso.php:70-72.
     *
     * @var array<string, int>
     */
    private const PROFILE_VISIBILITY_TOKENS = [
        'public'  => 10,
        'members' => 20,
        'private' => 40,
    ];

    /**
     * Subset for `peepso_profile_post_acc` — PUBLIC is intentionally
     * absent (matches PeepSo's `access-profile-post` UI which strips it).
     *
     * @var array<string, int>
     */
    private const POST_VISIBILITY_TOKENS = [
        'members' => 20,
        'private' => 40,
    ];

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/profile-prefs',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'get'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/profile-prefs',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$instance, 'patch'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function get(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $resp = ApiResponse::ok(self::readAll($userId));
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function patch(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $touched = false;

        // profile_visibility → peepso_users.usr_profile_acc
        $profileVis = $request->get_param('profile_visibility');
        if ($profileVis !== null) {
            if (!is_string($profileVis) || !isset(self::PROFILE_VISIBILITY_TOKENS[$profileVis])) {
                return ApiResponse::error(
                    'bcc_invalid_request',
                    '`profile_visibility` must be one of: public, members, private.',
                    422
                );
            }
            $err = self::writeProfileAccessibility($userId, self::PROFILE_VISIBILITY_TOKENS[$profileVis]);
            if ($err instanceof WP_REST_Response) {
                return $err;
            }
            $touched = true;
        }

        // post_visibility → peepso_profile_post_acc user_meta
        $postVis = $request->get_param('post_visibility');
        if ($postVis !== null) {
            if (!is_string($postVis) || !isset(self::POST_VISIBILITY_TOKENS[$postVis])) {
                return ApiResponse::error(
                    'bcc_invalid_request',
                    '`post_visibility` must be one of: members, private.',
                    422
                );
            }
            update_user_meta(
                $userId,
                'peepso_profile_post_acc',
                (string) self::POST_VISIBILITY_TOKENS[$postVis]
            );
            $touched = true;
        }

        // hide_birthday_year → peepso_hide_birthday_year user_meta
        $hideBday = $request->get_param('hide_birthday_year');
        if ($hideBday !== null) {
            $value = filter_var($hideBday, FILTER_VALIDATE_BOOLEAN);
            update_user_meta($userId, 'peepso_hide_birthday_year', $value ? '1' : '0');
            $touched = true;
        }

        // hide_online → peepso_hide_online_status user_meta
        $hideOnline = $request->get_param('hide_online');
        if ($hideOnline !== null) {
            $value = filter_var($hideOnline, FILTER_VALIDATE_BOOLEAN);
            update_user_meta($userId, 'peepso_hide_online_status', $value ? '1' : '0');
            $touched = true;
        }

        // hide_from_search → peepso_is_hide_profile_from_user_listing user_meta
        $hideFromSearch = $request->get_param('hide_from_search');
        if ($hideFromSearch !== null) {
            $value = filter_var($hideFromSearch, FILTER_VALIDATE_BOOLEAN);
            update_user_meta(
                $userId,
                'peepso_is_hide_profile_from_user_listing',
                $value ? '1' : '0'
            );
            $touched = true;
        }

        // default_post_audience → peepso_last_used_post_privacy user_meta
        $defaultAudience = $request->get_param('default_post_audience');
        if ($defaultAudience !== null) {
            if (!is_string($defaultAudience) || !isset(self::PROFILE_VISIBILITY_TOKENS[$defaultAudience])) {
                return ApiResponse::error(
                    'bcc_invalid_request',
                    '`default_post_audience` must be one of: public, members, private.',
                    422
                );
            }
            update_user_meta(
                $userId,
                'peepso_last_used_post_privacy',
                (string) self::PROFILE_VISIBILITY_TOKENS[$defaultAudience]
            );
            $touched = true;
        }

        if (!$touched) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'No profile preferences provided.',
                422
            );
        }

        $resp = ApiResponse::ok(self::readAll($userId));
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return array{
     *   profile_visibility: string,
     *   post_visibility: string,
     *   hide_birthday_year: bool,
     *   hide_online: bool,
     *   hide_from_search: bool,
     *   default_post_audience: string
     * }
     */
    private static function readAll(int $userId): array
    {
        return [
            'profile_visibility'    => self::tokenForAcc(
                self::readProfileAccessibility($userId),
                self::PROFILE_VISIBILITY_TOKENS,
                'public' // matches PeepSo schema default
            ),
            'post_visibility'       => self::tokenForAcc(
                self::readPostAccessibility($userId),
                self::POST_VISIBILITY_TOKENS,
                'members' // safest default — anyone logged in can post
            ),
            'hide_birthday_year'    => self::flag($userId, 'peepso_hide_birthday_year', false),
            'hide_online'           => self::flag($userId, 'peepso_hide_online_status', false),
            'hide_from_search'      => self::flag($userId, 'peepso_is_hide_profile_from_user_listing', false),
            'default_post_audience' => self::tokenForAcc(
                self::readLastUsedPostPrivacy($userId),
                self::PROFILE_VISIBILITY_TOKENS,
                'members' // PeepSo's safest default for posts
            ),
        ];
    }

    /**
     * Read peepso_last_used_post_privacy as int. Returns 0 when unset
     * (no prior post) — readAll() then falls through to the "members"
     * fallback in tokenForAcc().
     */
    private static function readLastUsedPostPrivacy(int $userId): int
    {
        $raw = get_user_meta($userId, 'peepso_last_used_post_privacy', true);
        if (!is_numeric($raw)) {
            return 0;
        }
        return (int) $raw;
    }

    private static function readProfileAccessibility(int $userId): int
    {
        if (!class_exists('\\PeepSoUser')) {
            return 10; // PeepSo schema default = ACCESS_PUBLIC
        }
        $value = \PeepSoUser::get_instance($userId)->get_profile_accessibility();
        return is_numeric($value) ? (int) $value : 10;
    }

    private static function readPostAccessibility(int $userId): int
    {
        if (!class_exists('\\PeepSoUser')) {
            return 20;
        }
        $value = \PeepSoUser::get_instance($userId)->get_profile_post_accessibility();
        return is_numeric($value) ? (int) $value : 20;
    }

    private static function writeProfileAccessibility(int $userId, int $accInt): ?WP_REST_Response
    {
        if (!class_exists('\\PeepSoUser')) {
            return ApiResponse::error(
                'bcc_peepso_unavailable',
                'Profile preferences require PeepSo. The administrator should activate the PeepSo plugin.',
                503
            );
        }
        $user = \PeepSoUser::get_instance($userId);
        $row = $user->get_peepso_user();
        $row['usr_profile_acc'] = $accInt;
        $user->update_peepso_user($row);

        return null;
    }

    /**
     * @param array<string, int> $tokenMap
     */
    private static function tokenForAcc(int $acc, array $tokenMap, string $fallback): string
    {
        foreach ($tokenMap as $token => $intValue) {
            if ($intValue === $acc) {
                return $token;
            }
        }
        return $fallback;
    }

    private static function flag(int $userId, string $key, bool $default): bool
    {
        $raw = get_user_meta($userId, $key, true);
        if (!is_string($raw) || $raw === '') {
            return $default;
        }
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}
