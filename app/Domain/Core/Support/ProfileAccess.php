<?php
/**
 * ProfileAccess — the profile-wide visibility gate for per-user reads.
 *
 * Answers one question: may the CURRENT viewer read anything about this
 * user's profile? Every per-user public REST surface asks it before
 * composing a payload, so a member who sets their profile to
 * "only me" or "friends" is actually private instead of merely
 * appearing so in a settings screen.
 *
 * Before this existed, `usr_profile_acc` was WRITTEN by
 * {@see \BCC\Trust\Core\REST\MyProfilePrefsEndpoint} and READ by nothing
 * that made an access decision — `PeepSoUser::is_accessible*` was called
 * zero times anywhere in this plugin. The setting was inert.
 *
 * ── Why this delegates instead of implementing the ladder ────────────
 *
 * PeepSo already owns the ladder, and it already encodes two rules this
 * codebase would otherwise have to restate. `PeepSoUser::is_accessible_static`
 * (peepso/classes/user.php) does, in order:
 *
 *   1. TRUE when `get_current_user_id() === $userId`  — OWNER EXEMPT
 *   2. TRUE when `PeepSo::is_admin()`                 — ADMIN EXEMPT
 *   3. switch: 0 and ACCESS_PUBLIC (10) → TRUE;
 *      ACCESS_MEMBERS (20) → `is_user_logged_in()`;
 *      ACCESS_PRIVATE (40) → FALSE
 *   4. `apply_filters('peepso_user_is_accessible', $ret, $acc, $userId)`
 *
 * Level 30 is deliberately absent from that switch, so `$ret` is FALSE
 * when it reaches the filter — i.e. 30 FAILS CLOSED unless something
 * grants it. What grants it is peepso-friends, which registers that
 * filter at priority 10 and returns
 * `PeepSoFriendsModel::are_friends($userId, get_current_user_id())`.
 * ⚠ 30 is `PeepSoFriendsPlugin::ACCESS_FRIENDS` — defined in
 * peepso-friends, NOT in PeepSo core, which declares only 10, 20 and 40.
 *
 * So delegating buys owner-exempt, admin-exempt and friends-aware 30 for
 * free, and — more importantly — guarantees this gate cannot DISAGREE
 * with the gates PeepSo applies on its own surfaces (messages, user
 * search, the friends list). A reimplementation would be one refactor
 * away from drifting.
 *
 * ── Relationship to PeepSoAlbumAccess ───────────────────────────────
 *
 * {@see PeepSoAlbumAccess} is the sibling gate for ALBUMS and it DOES
 * implement the ladder inline, over `pho_album_acc`. The two are
 * deliberately different, and both are correct for their job:
 *
 *   - Albums gate a per-ALBUM column, and a page of albums is filtered in
 *     a loop, so `canView()` takes `$isFriend` already resolved (once per
 *     request, via {@see PeepSoFriendGate}) rather than re-querying the
 *     friend graph per row.
 *   - Albums have NO admin exemption. Profiles need one.
 *   - Albums treat an unknown enum (including 0) as owner-only; PeepSo's
 *     own ladder treats 0 as public. For `usr_profile_acc` the schema
 *     default is 10, so 0 should not occur — but where it does, PeepSo's
 *     reading is the one that governs the user's other surfaces, which is
 *     the behaviour a profile gate must match.
 *
 * ⚠⚠ THE TWO GATES STACK, THEY DO NOT REPLACE EACH OTHER. An album read
 * must pass BOTH: this profile-level gate (may the viewer see this person
 * at all?) and `PeepSoAlbumAccess::canView` (may they see this particular
 * album?). A public album belonging to an "only me" profile was reachable
 * before precisely because only the second question was being asked.
 *
 * ── Fail-closed posture ─────────────────────────────────────────────
 *
 * Every uncertain path DENIES:
 *   - no PeepSo → deny. Same choice as
 *     {@see \BCC\Trust\Core\Services\Mentions\MentionPolicy}: no PeepSo
 *     means no privacy filter, so rejecting beats risking a leak.
 *   - a non-numeric stored level → deny.
 *   - a failed friend lookup → deny, and this one is PeepSo's doing
 *     rather than ours: `are_friends()` runs `$wpdb->get_var()` and
 *     returns FALSE when the result `is_null`. `get_var` yields null both
 *     for "no friendship row" and for a DB ERROR, so the two are
 *     indistinguishable — and both land on deny, which is the safe
 *     direction for a gate.
 *
 * ⚠ Note the deliberate asymmetry with
 * `MyProfilePrefsEndpoint::readProfileAccessibility()`, which returns 10
 * (public) when PeepSo is missing. That one REPORTS the owner their own
 * setting, where defaulting to the schema default is right. This one
 * DECIDES access, where the same default would be a leak.
 *
 * @package BCC\Trust\Core\Support
 * @since F-02 (profile-visibility enforcement)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class ProfileAccess
{
    /**
     * Machine-readable refusal reason, carried in `error.data.reason` so
     * the frontend can render "This profile is private" instead of a
     * generic permission error. Part of the §9 contract.
     */
    public const REASON_PRIVATE = 'profile_private';

    /**
     * Largest id set {@see filterVisible} will judge in one call.
     *
     * A list page is a few dozen rows, so this is a defensive bound on
     * the `IN ()` rather than a real limit. Overflow is REFUSED, not
     * truncated — see the method.
     */
    public const FILTER_VISIBLE_MAX = 500;

    /**
     * Of $userIds, the subset the CURRENT viewer may see — one query.
     *
     * For list surfaces (the member directory's rows, follower and
     * watcher lists, watching lists). Per-row `canViewProfile()` would
     * be N+1: each call reads the level AND, at level 30, asks
     * peepso-friends for a friendship, so a page of 20 could cost 40
     * queries.
     *
     * ── Why PeepSoUserSearch rather than a reimplemented ladder ──────
     *
     * Constraining `PeepSoUserSearch` with `include` makes PeepSo's own
     * privacy SQL do the work for the whole set at once — the same
     * pattern {@see \BCC\Trust\Core\Services\Mentions\MentionPolicy}
     * already uses. That keeps ONE definition of "visible", so a list
     * cannot drift from the single-profile gate or from PeepSo's own
     * surfaces.
     *
     * It filters more than the profile level, all of it privacy-positive
     * and all of it matching what PeepSo does elsewhere: banned and
     * unvalidated accounts (`usr_role`), blocks in either direction when
     * `user_blocking_enable` is on, and `hide_from_search`.
     *
     * ── The two exemptions PeepSo's SQL does NOT make ───────────────
     *
     * ⚠ `is_accessible_static()` exempts the OWNER and ADMINS before it
     * consults the ladder. PeepSo's `pre_user_query` does NOT: its
     * `usr_profile_acc <> ACCESS_PRIVATE` clause is unconditional, so a
     * private user is absent from their own result set and admins do not
     * see them either. Left to itself that would make this helper
     * disagree with the gate on exactly those two cases, so both are
     * re-applied here:
     *   - the viewer always sees THEMSELVES;
     *   - an admin sees everyone.
     * Without that, a private member would vanish from their own
     * followers list, which reads as data loss rather than privacy.
     *
     * Order is preserved and duplicates collapse, so a caller can use
     * the result to filter its own page while keeping its sort.
     *
     * @param list<int> $userIds
     * @return list<int>
     */
    public static function filterVisible(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $unique = [];
        foreach ($userIds as $id) {
            $i = (int) $id;
            if ($i > 0) {
                $unique[$i] = true;
            }
        }
        if ($unique === []) {
            return [];
        }

        // ⚠ REFUSE an oversized set rather than truncate it. Truncating
        // would silently hide the tail of a list — a caller would get a
        // short page and no indication why, which looks like missing
        // data and is impossible to debug from the response.
        if (count($unique) > self::FILTER_VISIBLE_MAX) {
            return [];
        }

        $viewerId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;

        // Admin exemption, mirroring is_accessible_static().
        if (class_exists('\\PeepSo') && \PeepSo::is_admin($viewerId > 0 ? $viewerId : null)) {
            return self::inOriginalOrder($userIds, $unique);
        }

        // ⚠ Fail CLOSED when PeepSo is absent: no privacy filter means
        // no way to judge, so nothing is visible except the viewer. Same
        // posture as canViewProfile() and MentionPolicy.
        if (!class_exists('\\PeepSoUserSearch')) {
            return isset($unique[$viewerId]) ? [$viewerId] : [];
        }

        $ids    = array_keys($unique);
        $search = new \PeepSoUserSearch(
            [
                'include' => $ids,
                'number'  => count($ids),
            ],
            $viewerId > 0 ? $viewerId : null,
            ''
        );

        /** @var list<int|numeric-string> $raw */
        $raw     = $search->results;
        $allowed = [];
        foreach ($raw as $row) {
            $i = (int) $row;
            if ($i > 0) {
                $allowed[$i] = true;
            }
        }

        // Owner exemption, mirroring is_accessible_static(). PeepSo's
        // unconditional privacy clause drops a private viewer from their
        // own results, which would remove them from their own lists.
        if ($viewerId > 0 && isset($unique[$viewerId])) {
            $allowed[$viewerId] = true;
        }

        return self::inOriginalOrder($userIds, $allowed);
    }

    /**
     * @param list<int>          $original
     * @param array<int, true>   $keep
     * @return list<int>
     */
    private static function inOriginalOrder(array $original, array $keep): array
    {
        $out     = [];
        $emitted = [];
        foreach ($original as $id) {
            $i = (int) $id;
            if ($i > 0 && isset($keep[$i]) && !isset($emitted[$i])) {
                $out[]        = $i;
                $emitted[$i]  = true;
            }
        }
        return $out;
    }

    /**
     * May the current viewer read $ownerId's profile surfaces?
     *
     * ⚠ CURRENT VIEWER ONLY. PeepSo resolves the viewer internally via
     * `get_current_user_id()` (both for the owner short-circuit and
     * inside the friends filter), so this cannot answer the question on
     * behalf of some OTHER viewer. Do not add a `$viewerId` parameter
     * and pass it through — it would be silently ignored, which is the
     * failure mode that made `getUserBlog`'s viewer argument misleading.
     * A caller needing a third-party verdict must take a different route.
     */
    public static function canViewProfile(int $ownerId): bool
    {
        if ($ownerId <= 0) {
            return false;
        }
        if (!class_exists('\\PeepSoUser')) {
            return false;
        }

        $acc = \PeepSoUser::get_instance($ownerId)->get_profile_accessibility();
        if (!is_numeric($acc)) {
            return false;
        }

        return (bool) \PeepSoUser::is_accessible_static((int) $acc, $ownerId);
    }

    /**
     * The single refusal shape, so all gated routes answer identically.
     *
     * 403 rather than 404 deliberately: the handle is already a public
     * lookup (the member directory and the slug resolver both expose
     * existence), so 403 discloses nothing new, and it lets the profile
     * page say "this profile is private" instead of pretending the
     * person does not exist.
     */
    public static function forbidden(): WP_REST_Response
    {
        return ApiResponse::error(
            'bcc_forbidden',
            'This profile is private.',
            403,
            ['reason' => self::REASON_PRIVATE]
        );
    }
}
