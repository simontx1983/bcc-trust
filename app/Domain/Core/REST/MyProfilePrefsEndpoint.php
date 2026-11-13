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
 *     "default_post_audience":  "public" | "members" | "friends" | "private",
 *     "show_groups_on_profile": bool,
 *     "show_about_on_profile":         bool,
 *     "show_verifications_on_profile": bool,
 *     "show_operator_on_profile":      bool,
 *     "show_blogs_on_profile":         bool,
 *     "show_reviews_on_profile":       bool,
 *     "show_disputes_on_profile":      bool,
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
 *   - show_groups_on_profile → `bcc_show_groups_on_profile` user_meta,
 *                             "1"/"0". ⚠ ABSENT MEANS ON (F-270): the
 *                             default preserves today's behaviour, so an
 *                             untouched account keeps listing its groups.
 *                             ⚠ It governs only the TOGGLEABLE part of the
 *                             policy. Secret groups are never shown to
 *                             non-members, and holder/delegator groups are
 *                             shown to signed-in viewers only, whatever
 *                             this says — see ProfileGroupVisibility.
 *   - show_<section>_on_profile *(F-341)* → the profile SECTION switches.
 *                             All default ON (absent user_meta = shown), so
 *                             nothing changes until a member opts out.
 *                             THREE storage conventions, deliberately:
 *                               · about / verifications / operator / blogs →
 *                                 new `bcc_show_*_on_profile` user_meta;
 *                               · HALLS → `show_groups_on_profile`, F-270's
 *                                 field. ⚠ It keeps that name rather than
 *                                 becoming `show_halls_on_profile`: renaming
 *                                 a live field breaks clients for no gain.
 *                                 A deliberate exception to the pattern.
 *                               · reviews / disputes → INVERTED views of the
 *                                 §K2 `reviews_hidden` / `disputes_hidden`
 *                                 flags, which remain the ONLY storage.
 *                                 `show_* = true` means `*_hidden = false`.
 *                                 One source of truth, no migration, and
 *                                 /me/privacy keeps writing the same rows.
 *                             ⚠ §K2's own ROUTE behaviour is UNCHANGED by
 *                             F-341: /reviews and /disputes answer exactly
 *                             as they did. Only the new sections follow the
 *                             documented-empty rule.
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

use BCC\Trust\Core\Repositories\DbWriteCheck;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\PrivacySettings;
use BCC\Trust\Core\Support\ProfileAccess;
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
     * `default_post_audience` (`peepso_last_used_post_privacy`).
     *
     * §F-169 added `friends` (30). It is still a SEPARATE map from the
     * profile-visibility one above, deliberately: they happen to hold the
     * same four entries now, but they are two public enums on two fields
     * and sharing a constant is how a future change to one silently
     * widens the other. That is exactly what the previous note warned
     * about, and the warning holds in the other direction too.
     *
     * ⚠⚠ WHY 30 HAD TO BE ADDED, rather than left out as "unused": PeepSo's
     * own posting UI can store 30, and this map is the read path as well as
     * the write path. With no entry for it, a stored 30 was reported as
     * `members`, so a settings form that GETs and then PATCHes — which is
     * what a settings form does — wrote 20 back and SILENTLY LOOSENED the
     * user's default from friends to members. Omitting a token from a
     * round-trip map is not neutral.
     *
     * ⚠ What `friends` means for a post TODAY: with peepso-friends removed
     * from production, `act_access = 30` resolves to the author only (the
     * §F-02 ladder's friends rung against an empty friend list). So this
     * now round-trips the user's choice faithfully, and that choice
     * currently means "only me" in practice. Reporting it honestly is
     * still right — the alternative was changing it without being asked —
     * but the copy shown beside this control is a product question, not
     * something this map can answer.
     *
     * @var array<string, int>
     */
    private const POST_AUDIENCE_TOKENS = [
        'public'  => 10,
        'members' => 20,
        'friends' => 30,
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

        // Per-ACCOUNT limit on cheap self-scoped writes (F-134).
        if (!\BCC\Core\Security\Throttle::allow('me_write', \BCC\Trust\Core\REST\Auth\AuthSupport::ME_WRITE_RATE_LIMIT, 60, 'me_write:' . $userId)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Please slow down.', 429);
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
        // 📌 NOT a transaction: a WRITE that fails after validation passed
        // (a DB error in update_user_meta or the PeepSo row update) stops
        // Phase 2 and answers 500 with `data.saved` listing what already
        // persisted. Earlier writes are not rolled back.

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
                    '`default_post_audience` must be one of: public, members, friends, private.',
                    422
                );
            }
            $meta['peepso_last_used_post_privacy'] = (string) self::POST_AUDIENCE_TOKENS[$defaultAudience];
        }

        // Boolean flags: any value is accepted (FILTER_VALIDATE_BOOLEAN), so
        // they cannot fail validation.
        foreach ([
            'hide_birthday_year'     => 'peepso_hide_birthday_year',
            'hide_online'            => 'peepso_hide_online_status',
            'hide_from_search'       => 'peepso_is_hide_profile_from_user_listing',
            'show_groups_on_profile' => ProfileAccess::META_SHOW_GROUPS,
            // F-341 section switches with their own storage. Halls is the
            // line above (F-270's key); reviews/disputes are handled after
            // this loop, because their storage is a §K2 HIDE flag.
            'show_about_on_profile'         => 'bcc_show_about_on_profile',
            'show_verifications_on_profile' => 'bcc_show_verifications_on_profile',
            'show_operator_on_profile'      => 'bcc_show_operator_on_profile',
            'show_blogs_on_profile'         => 'bcc_show_blogs_on_profile',
        ] as $param => $metaKey) {
            $raw = $request->get_param($param);
            if ($raw !== null) {
                $meta[$metaKey] = filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }
        }

        // F-341 — the two section switches whose storage is a §K2 HIDE
        // flag. Collected here and written below through
        // PrivacySettings::writePartial, the SAME writer /me/privacy uses,
        // so these rows have one storage and one write path.
        //
        // ⚠ INVERTED: `show_reviews_on_profile = false` means
        // `reviews_hidden = true`. The inversion lives here and in the
        // contract and nowhere else — a second place that flipped it would
        // silently disagree with /me/privacy.
        $privacyFlags = [];
        foreach ([
            'show_reviews_on_profile'  => 'reviews_hidden',
            'show_disputes_on_profile' => 'disputes_hidden',
        ] as $param => $flag) {
            $rawFlag = $request->get_param($param);
            if ($rawFlag !== null) {
                $privacyFlags[$flag] = !filter_var($rawFlag, FILTER_VALIDATE_BOOLEAN);
            }
        }

        // mentor_opt_in → bcc_mentor_opt_in (Rank Phase 7 §21.4). No
        // eligibility check on the WRITE: listing composes opt-in AND the
        // live capability at read time.
        $mentorOptIn = $request->get_param('mentor_opt_in');
        $mentorValue = $mentorOptIn !== null ? filter_var($mentorOptIn, FILTER_VALIDATE_BOOLEAN) : null;

        if ($profileAcc === null && $meta === [] && $privacyFlags === [] && $mentorValue === null) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'No profile preferences provided.',
                422
            );
        }

        // ── Phase 2: every field validated; write them ───────────────────
        // Each write is CHECKED (#449 review, M1) through DbWriteCheck: the
        // database's last error is cleared before it and read after it. The return value cannot be
        // used, because update_user_meta() also returns false when the value
        // is unchanged. The first failed write stops the sequence and the
        // request answers 500 with `data.saved`, the fields that DID persist,
        // so the client never reports "saved" for a partial write.
        /** @var list<string> $saved request field names already persisted */
        $saved = [];

        if ($profileAcc !== null) {
            $err = null;
            $ok  = self::checkedWrite(static function () use ($userId, $profileAcc, &$err): void {
                $err = self::writeProfileAccessibility($userId, $profileAcc);
            });
            if ($err instanceof WP_REST_Response) {
                return $err;
            }
            if (!$ok) {
                return self::writeFailed($userId, 'profile_visibility', $saved);
            }
            $saved[] = 'profile_visibility';
        }
        foreach ($meta as $metaKey => $value) {
            $field = self::META_FIELDS[$metaKey];
            if (!self::checkedWrite(static fn () => update_user_meta($userId, $metaKey, $value))) {
                return self::writeFailed($userId, $field, $saved);
            }
            $saved[] = $field;
        }
        foreach ($privacyFlags as $flag => $hidden) {
            // One flag per call, so `saved`/`failed` can name the REQUEST
            // field that failed — the v1.108 partial-failure contract.
            $ok = self::checkedWrite(static function () use ($userId, $flag, $hidden): void {
                PrivacySettings::writePartial($userId, [$flag => $hidden]);
            });
            $field = $flag === 'reviews_hidden'
                ? 'show_reviews_on_profile'
                : 'show_disputes_on_profile';
            if (!$ok) {
                return self::writeFailed($userId, $field, $saved);
            }
            $saved[] = $field;
        }
        if ($mentorValue !== null) {
            $ok = self::checkedWrite(static function () use ($userId, $mentorValue): void {
                \BCC\Trust\Core\Plugin::instance()->mentorListingService()->setOptIn($userId, $mentorValue);
            });
            if (!$ok) {
                return self::writeFailed($userId, 'mentor_opt_in', $saved);
            }
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
     *   show_groups_on_profile: bool,
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
            // Read and write stay pinned to the SAME map, so a client can
            // never GET a token here that the write path would refuse.
            //
            // §F-169 fixed what the previous note recorded: a stored 30
            // was reported as 'members', so a GET-then-PATCH round trip
            // wrote 20 back and loosened friends to members. 30 now has a
            // token, so the round trip preserves it.
            //
            // ⚠ The 'members' fallback below is for a value with NO token
            // at all — an unparseable or out-of-range meta value, which
            // this column can hold since PeepSo does not validate it. It
            // is no longer reachable for 30.
            'default_post_audience' => self::tokenForAcc(
                self::readLastUsedPostPrivacy($userId),
                self::POST_AUDIENCE_TOKENS,
                'members' // PeepSo's safest default for posts
            ),
            // ⚠ default TRUE, unlike every other flag here — see the
            // header note and ProfileAccess::showsGroupsOnProfile().
            'show_groups_on_profile'    => self::flag($userId, ProfileAccess::META_SHOW_GROUPS, true),
            // F-341 — all default ON. ⚠ The last two are INVERTED reads of
            // §K2 flags, not their own storage; see the header.
            'show_about_on_profile'         => self::flag($userId, 'bcc_show_about_on_profile', true),
            'show_verifications_on_profile' => self::flag($userId, 'bcc_show_verifications_on_profile', true),
            'show_operator_on_profile'      => self::flag($userId, 'bcc_show_operator_on_profile', true),
            'show_blogs_on_profile'         => self::flag($userId, 'bcc_show_blogs_on_profile', true),
            'show_reviews_on_profile'       => !PrivacySettings::flag($userId, 'reviews_hidden'),
            'show_disputes_on_profile'      => !PrivacySettings::flag($userId, 'disputes_hidden'),
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

    /** user_meta key → the PATCH field that writes it (for `data.saved`). */
    private const META_FIELDS = [
        'peepso_profile_post_acc'                  => 'post_visibility',
        'peepso_last_used_post_privacy'            => 'default_post_audience',
        'peepso_hide_birthday_year'                => 'hide_birthday_year',
        'peepso_hide_online_status'                => 'hide_online',
        'peepso_is_hide_profile_from_user_listing' => 'hide_from_search',
        ProfileAccess::META_SHOW_GROUPS            => 'show_groups_on_profile',
        'bcc_show_about_on_profile'                => 'show_about_on_profile',
        'bcc_show_verifications_on_profile'        => 'show_verifications_on_profile',
        'bcc_show_operator_on_profile'             => 'show_operator_on_profile',
        'bcc_show_blogs_on_profile'                => 'show_blogs_on_profile',
    ];

    /**
     * Run one write and report whether the database accepted it, via
     * {@see DbWriteCheck} ($wpdb stays in the Repositories layer, §1).
     *
     * The write's return value is ignored: update_user_meta() answers false
     * for an UNCHANGED value as well as for a failure, so "false" is not an
     * error signal. ⚠ Last-resort check: a write lost on wpdb's `! ready`
     * early return leaves last_error empty and counts as saved. See
     * DbWriteCheck for why a write through a WordPress API cannot do better.
     */
    private static function checkedWrite(callable $write): bool
    {
        return DbWriteCheck::run($write);
    }

    /** @param list<string> $saved */
    private static function writeFailed(int $userId, string $field, array $saved): WP_REST_Response
    {
        \BCC\Core\Log\Logger::error('[MyProfilePrefs] preference write failed; stopped', [
            'user_id' => $userId,
            'field'   => $field,
            'saved'   => $saved,
            'error'   => DbWriteCheck::lastError(),
        ]);

        return ApiResponse::error(
            'bcc_internal_error',
            'Some of your preferences could not be saved. Please try again.',
            500,
            ['saved' => $saved, 'failed' => $field]
        );
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
