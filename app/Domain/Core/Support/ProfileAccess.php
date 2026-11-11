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
