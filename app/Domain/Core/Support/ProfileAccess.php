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
        // ⚠ Must stay FIRST. hiddenBySuspension() passes $ownerId to
        // bcc-core, whose `$user_id = $user_id ?: get_current_user_id()`
        // would silently turn a 0 target into a check of the VIEWER — a
        // target read answering a question about the wrong person.
        if ($ownerId <= 0) {
            return false;
        }

        if (!class_exists('\\PeepSoUser')) {
            return false;
        }

        // §F-182 — suspension hiding, STACKED WITH the privacy ladder
        // below rather than folded into it. The two are different
        // questions ("did this member choose to be private" vs "has this
        // member been sanctioned"), and a member can be neither, either
        // or both; a public profile belonging to a suspended account was
        // readable before because only the first question was asked.
        //
        // ⚠ Deliberately AFTER the PeepSo guard, not before it. "PeepSo
        // is not loaded" already denies every profile unconditionally, so
        // asking about suspension first would only add work to a decision
        // that is already made — and would make this gate consult bcc-core
        // on a site that has no profiles to show.
        if (self::hiddenBySuspension($ownerId)) {
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

    /**
     * §F-182 — is $ownerId's profile withheld because that account is
     * suspended?
     *
     * The decision: a suspended member's public profile is hidden from
     * others; admins excepted. Two exemptions are therefore explicit
     * here, because the suspension branch does NOT go through PeepSo and
     * so inherits none of the ladder's own exemptions:
     *
     *   1. THE MEMBER THEMSELVES. A suspension restricts what someone may
     *      DO; locking them out of reading their own profile would also
     *      hide the standing notice that tells them they are suspended.
     *   2. SITE ADMINS, who are the only people for whom a moderated
     *      account must stay reviewable.
     *
     * ── Why `is_not_suspended()` and not a direct user_info read ───────
     *
     * It is bcc-core's canonical suspension answer (the same one
     * OperatorProfileEndpoint, PostsService, CredibilityAtoms and the
     * dispute paths ask), it caches per user for 60s — which a read path
     * wants — and it is flushed on `bcc_user_suspension_changed`, which
     * {@see \BCC\Trust\Core\Repositories\UserInfoRepository::suspendUser}
     * fires. Reading `user_info.is_suspended` here would be a second
     * opinion that could disagree with every gate above.
     *
     * ⚠ ADMIN BYPASS IS LEFT ON (the default), and that is about the
     * SUBJECT, not the viewer. `is_not_suspended()`'s bypass tests
     * whether the user being CHECKED is an admin, so with it on, a
     * suspended admin's own profile stays visible. That matches "admins
     * excepted" read in both directions, and it avoids the alternative's
     * side effect: with `$allowAdminBypass = false`, bcc-core logs
     * "Suspended admin blocked from dispute participation" every time
     * anyone opens such a profile — the wrong message, on a read path,
     * at read-path volume.
     *
     * ⚠ "Admin" here is `Permissions::is_admin()` = `manage_options`,
     * which is NARROWER than the `PeepSo::is_admin()` used by the
     * privacy ladder (manage_options OR the PeepSo role `admin`). A
     * PeepSo-role admin without `manage_options` can therefore see a
     * PRIVATE profile but not a SUSPENDED one. Deliberately not widened:
     * it errs toward hiding, and `Permissions` is how the rest of this
     * codebase decides who is exempt from a suspension gate.
     *
     * ⚠⚠ THIS GATE FAILS OPEN, BY DECISION. An unreadable suspension
     * state counts as "not suspended", so the profile stays visible: it
     * is a read path, and the alternative — treating unknown as
     * suspended — blacks out every profile on the site the moment the
     * state cannot be read. Two consequences worth knowing:
     *   - `UserInfoRepository::getByUserId()` CACHES a null row as "no
     *     row" for 5 minutes, so a transient read fault becomes a
     *     5-minute "not suspended" (see bcc-core #84, which exists
     *     because an unknown state must never be cached).
     *   - when bcc-core #84 lands, this should become
     *     `Permissions::suspensionState($ownerId)` with `null` mapped to
     *     VISIBLE — i.e. `=== true` for hidden, never `!== false`.
     */
    private static function hiddenBySuspension(int $ownerId): bool
    {
        if (get_current_user_id() === $ownerId) {
            return false;
        }

        if (!class_exists('\\BCC\\Core\\Permissions\\Permissions')) {
            // bcc-core absent: no canonical answer, and per the fail-open
            // decision an unknown state does not hide the profile.
            return false;
        }

        if (\BCC\Core\Permissions\Permissions::is_admin()) {
            return false;
        }

        return !\BCC\Core\Permissions\Permissions::is_not_suspended($ownerId);
    }

    /**
     * §F-182 — hook the suspension exclusion into PeepSo-driven user
     * listings. Called once at plugin boot.
     *
     * Without this, {@see canViewProfile} hides a suspended member's
     * profile while the member DIRECTORY still lists them: a row the
     * viewer can see but cannot open, which is the inconsistency F-02
     * set out to remove rather than a smaller version of the fix.
     *
     * ⚠ Suspension does NOT reach PeepSo's own exclusions. PeepSo's
     * listing SQL drops banned and unvalidated accounts by `usr_role`,
     * and {@see \BCC\Trust\Core\Repositories\UserInfoRepository::suspendUser}
     * writes only `user_info.is_suspended` — it never touches
     * `usr_role`. So a suspended member is, to PeepSo, an ordinary one.
     */
    public static function registerListingFilter(): void
    {
        add_action('pre_user_query', [self::class, 'filterSuspendedFromListing'], 20, 1);
    }

    /**
     * Add the suspension exclusion to a PeepSo user query.
     *
     * ── Why SQL and not an `exclude` id list ─────────────────────────
     *
     * An id list is the obvious alternative and it is the wrong tool
     * twice over. `SuspensionRepository::getActiveSuspendedUserIds()` is
     * BOUNDED (1000, hard-capped at 5000), so past that many suspensions
     * the exclusion would silently become partial — a privacy filter that
     * stops working as the site grows, with nothing failing to say so.
     * And it reads a DIFFERENT SOURCE: the `suspensions` table's
     * `unsuspended_at IS NULL`, where this gate and every other
     * suspension gate read `user_info.is_suspended`. The two can
     * disagree, and a listing that disagreed with the profile gate would
     * reintroduce exactly the mismatch this change removes. Joining the
     * same column the gate reads keeps one source of truth and has no
     * cap.
     *
     * ── Scope ───────────────────────────────────────────────────────
     *
     * `pre_user_query` fires for EVERY `WP_User_Query`, so this narrows
     * to queries PeepSo built: `PeepSoUserSearch` always injects
     * `_peepso_user_id` into its args. wp-admin's Users screen and any
     * other plain `WP_User_Query` are untouched, which is deliberate —
     * an admin administering a suspended account must still find them.
     *
     * Exemptions mirror {@see hiddenBySuspension} so a row and its
     * profile always agree: admins see everyone, and the viewer always
     * sees themselves.
     *
     * ⚠ "Admin" is `Permissions::is_admin()` (`manage_options`), NOT
     * `PeepSo::is_admin()` (which also admits the PeepSo role `admin`).
     * That is the same narrower notion the profile gate uses, and the
     * agreement is the point: using the broader one here would list rows
     * whose profiles then answered 403.
     *
     * ⚠ The parameter is TYPED rather than guarded at runtime. An
     * earlier version took `$query` untyped and defended with
     * `is_object()` / `isset()`, which cost more than it bought:
     * `WP_User_Query::$query_vars` is a non-nullable array, so the
     * `isset()` arm was dead code, and narrowing the global `$wpdb` with
     * `is_object()` discarded its `wpdb` type, leaving `prepare()` an
     * undefined method on a bare `object`. WordPress core always passes
     * a `WP_User_Query` here.
     */
    public static function filterSuspendedFromListing(\WP_User_Query $query): void
    {
        if (!array_key_exists('_peepso_user_id', $query->query_vars)) {
            return;
        }
        if (class_exists('\\BCC\\Core\\Permissions\\Permissions')
            && \BCC\Core\Permissions\Permissions::is_admin()) {
            return;
        }

        global $wpdb;

        $table  = \BCC\Trust\Core\Database\TableRegistry::userInfo();
        $viewer = (int) get_current_user_id();

        // A LEFT JOIN, so a member with no user_info row stays listed:
        // "no row" is NOT suspended, the same reading
        // TrustReadService::isSuspended() gives it, and the same
        // fail-open direction the decision calls for.
        $query->query_from .= " LEFT JOIN {$table} bcc_f182 ON bcc_f182.user_id = {$wpdb->users}.ID";

        $query->query_where .= $wpdb->prepare(
            " AND (bcc_f182.is_suspended IS NULL"
            . " OR bcc_f182.is_suspended = 0"
            . " OR {$wpdb->users}.ID = %d)",
            $viewer
        );
    }
}
