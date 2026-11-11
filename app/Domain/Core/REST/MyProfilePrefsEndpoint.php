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
 *     "profile_visibility":     "public" | "members" | "friends" | "private",
 *     "post_visibility":        "members" | "private",
 *     "hide_birthday_year":     bool,
 *     "hide_online":            bool,
 *     "hide_from_search":       bool,
 *     "default_post_audience":  "public" | "members" | "private",
 *     "mentor_opt_in":          bool
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
 *                               ⚠ "friends" is 30, which is
 *                               `PeepSoFriendsPlugin::ACCESS_FRIENDS` —
 *                               defined and enforced by the peepso-friends
 *                               plugin, not by PeepSo core. The value is
 *                               ENFORCED on BCC's own read surfaces by
 *                               {@see \BCC\Trust\Core\Support\ProfileAccess},
 *                               which delegates to
 *                               `PeepSoUser::is_accessible_static()`.
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
 *   - mentor_opt_in          → `bcc_mentor_opt_in` user_meta, "1"/"0"
 *                               (Rank Phase 7 §21.4, via
 *                               MentorListingService). Opting in needs NO
 *                               eligibility — a non-Veteran may pre-opt-in;
 *                               the LISTING composes opt-in AND the live
 *                               list_as_mentor capability. The GET response
 *                               therefore also carries the READ-ONLY pair
 *                               `mentor_listed` (actively listed right now)
 *                               and `mentor_eligibility_reason` (stable
 *                               resolver deny slug, null while eligible) so
 *                               the frontend can explain a paused listing.
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
     * ⚠ `friends` (30) does NOT come from peepso.php. It is
     * `PeepSoFriendsPlugin::ACCESS_FRIENDS`, defined in the peepso-friends
     * plugin, which is also what ENFORCES it — both through PeepSo's
     * `peepso_user_is_accessible` filter and through a raw clause its
     * `pre_user_query` appends to user search. Writing 30 here is
     * therefore only meaningful while that plugin is active; when it is
     * absent, `is_accessible_static` leaves 30 at deny, which is the
     * fail-closed direction. See {@see \BCC\Trust\Core\Support\ProfileAccess}.
     *
     * The level was previously unreachable through this API: a member
     * could not set "friends" at all, only through PeepSo's own profile
     * UI, even though every other surface already understood it.
     *
     * @var array<string, int>
     */
    private const PROFILE_VISIBILITY_TOKENS = [
        'public'  => 10,
        'members' => 20,
        'friends' => 30,
        'private' => 40,
    ];

    /**
     * `default_post_audience` (`peepso_last_used_post_privacy`) keeps the
     * THREE-token set it has always accepted.
     *
     * ⚠ Deliberately a separate map, not a reuse of the one above. The
     * two shared a constant until `friends` was added for the profile
     * field; continuing to share it would have silently widened a SECOND
     * public enum in the same change, without that being asked for or
     * reviewed.
     *
     * Extending this one to `friends` is defensible on its own merits —
     * `act_access = 30` is a real PeepSo post level that peepso-friends
     * gates — but it is a separate contract change and belongs in its own
     * PR.
     *
     * @var array<string, int>
     */
    private const POST_AUDIENCE_TOKENS = [
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

        // ⚠ ALL VALIDATION BEFORE ANY WRITE (F-97). Phase 1 validates EVERY
        // present field and the one precondition a write can fail on
        // (PeepSo, for profile_visibility) before anything is written, so a
        // 422 or 503 writes nothing. This used to validate-and-write field by
        // field, so a 422 on a later field (e.g. a bad default_post_audience)
        // returned an error while earlier fields, including
        // profile_visibility, which feeds search and feed gating, were
        // already saved: a "failed" request that had partly succeeded.
        //
        // 📌 NOT a transaction: Phase 2 does not detect a WRITE that fails
        // after validation passed (a DB error during update_user_meta or the
        // PeepSo row update). That is a separate follow-up.

        // ── Phase 1: validate, collecting the planned writes ─────────────
        $profileAcc = null; // ?int
        $profileVis = $request->get_param('profile_visibility');
        if ($profileVis !== null) {
            if (!is_string($profileVis) || !isset(self::PROFILE_VISIBILITY_TOKENS[$profileVis])) {
                return ApiResponse::error(
                    'bcc_invalid_request',
                    '`profile_visibility` must be one of: public, members, friends, private.',
                    422
                );
            }
            if (!class_exists('\\PeepSoUser')) {
                return self::peepsoUnavailable();
            }
            $profileAcc = self::PROFILE_VISIBILITY_TOKENS[$profileVis];
        }

        /** @var array<string, string> $meta user_meta key => value to write */
        $meta = [];

        $postVis = $request->get_param('post_visibility');
        if ($postVis !== null) {
            if (!is_string($postVis) || !isset(self::POST_VISIBILITY_TOKENS[$postVis])) {
                return ApiResponse::error(
                    'bcc_invalid_request',
                    '`post_visibility` must be one of: members, private.',
                    422
                );
            }
            $meta['peepso_profile_post_acc'] = (string) self::POST_VISIBILITY_TOKENS[$postVis];
        }

        $defaultAudience = $request->get_param('default_post_audience');
        if ($defaultAudience !== null) {
            if (!is_string($defaultAudience) || !isset(self::POST_AUDIENCE_TOKENS[$defaultAudience])) {
                return ApiResponse::error(
                    'bcc_invalid_request',
                    '`default_post_audience` must be one of: public, members, private.',
                    422
                );
            }
            $meta['peepso_last_used_post_privacy'] = (string) self::POST_AUDIENCE_TOKENS[$defaultAudience];
        }

        // Boolean flags: any value is accepted (FILTER_VALIDATE_BOOLEAN), so
        // they cannot fail validation.
        foreach ([
            'hide_birthday_year' => 'peepso_hide_birthday_year',
            'hide_online'        => 'peepso_hide_online_status',
            'hide_from_search'   => 'peepso_is_hide_profile_from_user_listing',
        ] as $param => $metaKey) {
            $raw = $request->get_param($param);
            if ($raw !== null) {
                $meta[$metaKey] = filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }
        }

        // mentor_opt_in → bcc_mentor_opt_in (Rank Phase 7 §21.4). No
        // eligibility check on the WRITE: listing composes opt-in AND the
        // live capability at read time.
        $mentorOptIn = $request->get_param('mentor_opt_in');
        $mentorValue = $mentorOptIn !== null ? filter_var($mentorOptIn, FILTER_VALIDATE_BOOLEAN) : null;

        if ($profileAcc === null && $meta === [] && $mentorValue === null) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'No profile preferences provided.',
                422
            );
        }

        // ── Phase 2: every field validated; write them ───────────────────
        if ($profileAcc !== null) {
            $err = self::writeProfileAccessibility($userId, $profileAcc);
            if ($err instanceof WP_REST_Response) {
                return $err;
            }
        }
        foreach ($meta as $metaKey => $value) {
            update_user_meta($userId, $metaKey, $value);
        }
        if ($mentorValue !== null) {
            \BCC\Trust\Core\Plugin::instance()->mentorListingService()->setOptIn($userId, $mentorValue);
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
     *   default_post_audience: string,
     *   mentor_opt_in: bool,
     *   mentor_listed: bool,
     *   mentor_eligibility_reason: string|null
     * }
     */
    private static function readAll(int $userId): array
    {
        // §21.4 mentor listing state — opt-in flag + live-composed
        // listing verdict (never materialized; a tier/rank/recovery
        // change pauses the listing on the next read with no sweep).
        $mentor = \BCC\Trust\Core\Plugin::instance()->mentorListingService()->listingFor($userId);

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
            // ⚠ POST_AUDIENCE_TOKENS, matching this field's WRITE path.
            // Both maps hold the same three entries, so this read is
            // byte-identical to before; the point is that read and write
            // stay pinned to the SAME map now that the profile field's map
            // has a fourth token. Had this kept reading the profile map, a
            // client could GET 'friends' here and then be refused on PUT.
            //
            // 📌 PRE-EXISTING, not introduced here: a user whose stored
            // `peepso_last_used_post_privacy` is 30 (settable in PeepSo's
            // own UI) is reported as 'members', and a client round-trip
            // would then write 20 back — LOOSENING friends to members.
            // That is true on main today and is logged as a follow-up
            // rather than silently changed under cover of this PR.
            'default_post_audience' => self::tokenForAcc(
                self::readLastUsedPostPrivacy($userId),
                self::POST_AUDIENCE_TOKENS,
                'members' // PeepSo's safest default for posts
            ),
            'mentor_opt_in'             => $mentor['opted_in'],
            'mentor_listed'             => $mentor['listed'],
            'mentor_eligibility_reason' => $mentor['reason'],
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

    private static function peepsoUnavailable(): WP_REST_Response
    {
        return ApiResponse::error(
            'bcc_peepso_unavailable',
            'Profile preferences require PeepSo. The administrator should activate the PeepSo plugin.',
            503
        );
    }

    private static function writeProfileAccessibility(int $userId, int $accInt): ?WP_REST_Response
    {
        if (!class_exists('\\PeepSoUser')) {
            return self::peepsoUnavailable();
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
