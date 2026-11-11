<?php
/**
 * PrivacySettings — central reader/writer for §K2 privacy toggles.
 *
 * Single source of truth for the seven §K2 toggles + the §G1 PeepSo
 * search opt-out. All eight live in `wp_usermeta` under the
 * `bcc_privacy_*` namespace; this class hides the meta-key strings from
 * callers so we can rename without grepping.
 *
 * Two consumers:
 *   - UserViewService::resolvePrivacy — embeds the seven §K2 flags in
 *     the user view-model so the frontend renders "private" placeholders
 *     for hidden surfaces (per §A2: server decides, frontend renders).
 *   - MyPrivacyEndpoint — GET / PATCH /me/privacy, the settings UI.
 *
 * The discovery_optout flag is intentionally NOT in the user view-model
 * — it's a self-only setting that affects search results, not the
 * profile page render. It only ships through /me/privacy.
 *
 * Boolean-truthy parsing (FILTER_VALIDATE_BOOLEAN) follows the same
 * convention as UserViewService::metaFlag — wp_usermeta stores strings,
 * so a `(bool)` cast on "false" or "0" surfaces phantom truthy values.
 *
 * @package BCC\Trust\Core\Support
 * @since V1 (2026-04, §K2 privacy UI)
 */

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class PrivacySettings
{
    /**
     * §K2 toggles — embedded in the user view-model. Order is canonical;
     * the settings UI consumes this list verbatim.
     */
    public const PROFILE_KEYS = [
        'watching_hidden',
        'reviews_hidden',
        'disputes_hidden',
        'delegations_hidden',
        'follower_count_hidden',
        'real_name_hidden',
        'email_hidden',
    ];

    /**
     * §G1 PeepSo overlap — opt out of being listed in user search.
     * Self-only; never embedded in the user view-model.
     */
    public const DISCOVERY_KEYS = [
        'discovery_optout',
    ];

    /** All keys the /me/privacy endpoint accepts and returns. */
    public const ALL_KEYS = [
        'watching_hidden',
        'reviews_hidden',
        'disputes_hidden',
        'delegations_hidden',
        'follower_count_hidden',
        'real_name_hidden',
        'email_hidden',
        'discovery_optout',
    ];

    /** wp_usermeta key prefix. Renaming requires a migration, not a code edit. */
    private const META_PREFIX = 'bcc_privacy_';

    /**
     * `peepso_users.usr_profile_acc` = 30, "friends only".
     *
     * ⚠ Spelled out rather than referenced from a PeepSo constant because
     * the constant is `PeepSoFriendsPlugin::ACCESS_FRIENDS`, defined in
     * the OPTIONAL peepso-friends plugin — and the clause that uses this
     * exists precisely for the case where that plugin is GONE. Referring
     * to it would make the guard undefined exactly when it is needed.
     * PeepSo core declares only 10, 20 and 40.
     */
    private const PROFILE_ACC_FRIENDS = 30;

    /**
     * The PeepSo user-meta key behind BCC's `hide_from_search` toggle.
     *
     * ⚠ NOT under `META_PREFIX`: this key is PeepSo's own, written by
     * `MyProfilePrefsEndpoint` so PeepSo's surfaces honour it too. The
     * `bcc_privacy_*` namespace is for keys BCC owns outright.
     */
    private const HIDE_FROM_LISTING_META = 'peepso_is_hide_profile_from_user_listing';

    /**
     * Read the §K2 flags for the user view-model. Always returns a
     * complete array — missing meta defaults to false (V1 baseline:
     * everything public per §K2).
     *
     * @return array{
     *   watching_hidden: bool,
     *   reviews_hidden: bool,
     *   disputes_hidden: bool,
     *   delegations_hidden: bool,
     *   follower_count_hidden: bool,
     *   real_name_hidden: bool,
     *   email_hidden: bool
     * }
     */
    public static function readProfile(int $userId): array
    {
        return [
            'watching_hidden'       => self::flag($userId, 'watching_hidden'),
            'reviews_hidden'        => self::flag($userId, 'reviews_hidden'),
            'disputes_hidden'       => self::flag($userId, 'disputes_hidden'),
            'delegations_hidden'    => self::flag($userId, 'delegations_hidden'),
            'follower_count_hidden' => self::flag($userId, 'follower_count_hidden'),
            'real_name_hidden'      => self::flag($userId, 'real_name_hidden'),
            'email_hidden'          => self::flag($userId, 'email_hidden'),
        ];
    }

    /**
     * Read all flags for the /me/privacy endpoint response.
     *
     * @return array<string, bool>
     */
    public static function readAll(int $userId): array
    {
        $out = [];
        foreach (self::ALL_KEYS as $key) {
            $out[$key] = self::flag($userId, $key);
        }
        return $out;
    }

    /**
     * Write a partial set of flags. Only keys in ALL_KEYS are written;
     * unknown keys are silently dropped (validation is the endpoint's
     * job — by the time we get here the input is already filtered).
     *
     * @param array<string, bool> $partial
     */
    public static function writePartial(int $userId, array $partial): void
    {
        if ($userId <= 0) {
            return;
        }
        foreach ($partial as $key => $value) {
            if (!in_array($key, self::ALL_KEYS, true)) {
                continue;
            }
            // Store as "1" / "0" strings (matches WP's boolean meta convention
            // and round-trips through FILTER_VALIDATE_BOOLEAN cleanly).
            update_user_meta($userId, self::META_PREFIX . $key, $value ? '1' : '0');
        }
    }

    /**
     * Single-flag read. Boolean-truthy parsing per UserViewService::metaFlag.
     */
    public static function flag(int $userId, string $key): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $raw = get_user_meta($userId, self::META_PREFIX . $key, true);
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Hook the discovery_optout flag into PeepSo's user-search filter.
     *
     * Called once at plugin boot. PeepSo's UserSearch class fires
     * `peepso_user_search_args` to let consumers narrow the WP_User_Query
     * args; we splice in a `meta_query` clause that excludes any user
     * with bcc_privacy_discovery_optout = '1'.
     *
     * Stays inside bcc-trust per §A4 — privacy decisions live in one
     * place, even when they affect a sibling plugin's surface.
     */
    public static function registerSearchFilter(): void
    {
        add_filter('peepso_user_search_args', [self::class, 'filterPeepSoSearchArgs'], 20, 1);

        // ⚠ TWO MECHANISMS, DELIBERATELY, because the settings live in
        // different tables and only one is reachable by `meta_query`.
        //
        //   hide_from_search  → `wp_usermeta`, so the args filter above
        //                       can carry it as a `meta_query` clause.
        //   profile level     → `peepso_users.usr_profile_acc`, which is
        //                       NOT usermeta, so no `meta_query` can
        //                       express it. It needs SQL — hence
        //                       `pre_user_query`.
        //
        // Priority 20 so it runs AFTER PeepSo's own `pre_user_query`
        // (registered at the default 10 inside `PeepSoUserSearch`). The
        // clause below brings its own join rather than reusing PeepSo's
        // `acc` alias, so it does not actually depend on that ordering —
        // but a predictable order is worth having anyway.
        add_action('pre_user_query', [self::class, 'filterPeepSoUserQuery'], 20, 1);
    }

    /**
     * BCC's own profile-level exclusion for PeepSo's user search.
     *
     * ⚠⚠ WHY THIS EXISTS: PeepSo CORE's search only excludes level 40
     * (`usr_profile_acc <> ACCESS_PRIVATE`) and gates level 20
     * (`IF(= ACCESS_MEMBERS, $viewer > 0, TRUE)`). The level-30 clause
     * came ONLY from the peepso-friends plugin
     * (`peepsofriends.php`, `IF(usr_profile_acc = 30, ps_fnd.fnd_id IS
     * NOT NULL, TRUE)`). With that add-on removed, nothing excludes 30 —
     * so a "friends only" profile becomes UNREACHABLE on its profile
     * routes (the gate denies it) while still being LISTED in the member
     * directory and its search. Listed but unreachable is precisely the
     * inconsistency F-02 exists to remove, and it would arrive by plugin
     * removal rather than by any code change.
     *
     * BCC exposes `profile_visibility` through its own API and documents
     * what each level means, so the guarantee has to be ours and must not
     * depend on which PeepSo add-ons happen to be installed.
     *
     * ⚠ ONLY APPLIED WHEN peepso-friends IS ABSENT. While that plugin is
     * present its clause already handles 30 correctly — a friend SEES a
     * level-30 user — and adding a blanket exclusion on top would hide
     * level-30 users from their own friends, which is stricter than the
     * setting means. So this fills a gap rather than duplicating a rule:
     *   plugin present → PeepSo's friends clause governs level 30;
     *   plugin absent  → this clause governs it, as owner + admins only,
     *                    which is the user's stated intent for a product
     *                    with no friends feature.
     *
     * Scoped to PeepSo's own searches by the `_peepso_user_id` query var
     * that `PeepSoUserSearch` always sets. ⚠ That scoping is load-bearing:
     * `pre_user_query` fires for EVERY `WP_User_Query` in the request,
     * including wp-admin's user list and other plugins' lookups, and a
     * privacy clause leaking into those would be a bug of its own.
     *
     * @param \WP_User_Query $query
     */
    public static function filterPeepSoUserQuery(\WP_User_Query $query): void
    {
        // Not a PeepSo search ⇒ not ours to touch. `query_vars` is always
        // an array on WP_User_Query, so this is the only guard needed.
        if (!array_key_exists('_peepso_user_id', $query->query_vars)) {
            return;
        }
        // peepso-friends present ⇒ its own clause owns level 30.
        if (class_exists('\\PeepSoFriendsPlugin')) {
            return;
        }
        // Admins see everyone, matching both `is_accessible_static` and
        // PeepSo's own treatment of its hide-from-listing clause.
        if (class_exists('\\PeepSo') && \PeepSo::is_admin()) {
            return;
        }

        $viewerId = (int) ($query->query_vars['_peepso_user_id'] ?? 0);

        // Own join and own alias, so this does not depend on PeepSo's
        // `acc` join existing or on hook ordering. The SQL itself belongs
        // to the repository that owns `peepso_users`; this method decides
        // WHETHER to exclude and what "friends only" means.
        $clause = \BCC\Trust\Core\Repositories\UserSyncRepository::listingProfileAccessClause(
            $viewerId,
            self::PROFILE_ACC_FRIENDS
        );

        // ⚠ `pre_user_query` is not guaranteed to fire once per query:
        // `WP_User_Query::prepare_query()` can run again on the same
        // object. A second copy of the join is not a cosmetic duplicate —
        // MySQL rejects the entire statement with "Not unique
        // table/alias", so the listing fails hard rather than returning
        // slightly wrong rows.
        if (str_contains($query->query_from, $clause['alias'])) {
            return;
        }

        $query->query_from  .= $clause['from'];
        $query->query_where .= $clause['where'];
    }

    /**
     * Splice a NOT-EXISTS-OR-FALSY meta_query clause into PeepSo's
     * WP_User_Query args so opted-out users are excluded.
     *
     * NOT EXISTS is required because users who have NEVER touched the
     * setting have no meta row at all — a plain `value != '1'` clause
     * would skip them entirely. Combined with relation=AND so an
     * existing meta_query (e.g. PeepSo's VIP filter) is preserved.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function filterPeepSoSearchArgs(array $args): array
    {
        $optoutClause = [
            'relation' => 'OR',
            [
                'key'     => self::META_PREFIX . 'discovery_optout',
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => self::META_PREFIX . 'discovery_optout',
                'value'   => '1',
                'compare' => '!=',
            ],
        ];

        // ── hide_from_search, honoured unconditionally ────────────────
        //
        // ⚠ PeepSo HAS a clause for this, but it is gated on its own
        // admin option: `1 === intval(get_option('allow_hide_user_from_user_listing', 0))`.
        // That option is OFF on production, so the setting has never
        // actually worked — while BCC's contract has promised since v1.64
        // that it "excludes the user from directory + member search".
        //
        // The promise is OURS: the toggle is exposed through BCC's own
        // `PATCH /me/profile-prefs`. Leaving it contingent on a
        // third-party admin checkbox means an admin can silently
        // un-break or re-break our documented behaviour with no signal.
        // So this clause is unconditional and ADDITIVE — if the PeepSo
        // option is ever enabled, both clauses apply and agree.
        //
        // Same NOT-EXISTS-OR-FALSY shape as the opt-out above, for the
        // same reason: users who never touched the setting have no meta
        // row at all, and a bare `value != '1'` would exclude them.
        $hideClause = [
            'relation' => 'OR',
            [
                'key'     => self::HIDE_FROM_LISTING_META,
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => self::HIDE_FROM_LISTING_META,
                'value'   => '1',
                'compare' => '!=',
            ],
        ];

        // ⚠ ADMINS ARE EXEMPT FROM hide_from_search, per the user's
        // "admins exempt" rule — otherwise a moderator cannot find a
        // hidden member in member search, which is the one job that rule
        // exists for. Mirrors `PeepSo::is_admin()`, the same predicate
        // `is_accessible_static` and the level-30 clause use, so the three
        // cannot drift.
        //
        // ⛔ SCOPED TO THE HIDE CLAUSE ONLY, NOT AN EARLY RETURN FROM
        // THIS METHOD. A blanket exemption would also drop
        // `bcc_privacy_discovery_optout`, which is a DIFFERENT setting
        // with its own history and which has always applied to admins
        // too. Loosening it is a product decision nobody has made, so it
        // is deliberately left alone and raised separately rather than
        // changed under cover of an admin fix.
        $isAdmin = class_exists('\\PeepSo') && \PeepSo::is_admin();

        $bccClauses = $isAdmin
            ? ['relation' => 'AND', $optoutClause]
            : ['relation' => 'AND', $optoutClause, $hideClause];

        if (isset($args['meta_query']) && is_array($args['meta_query'])) {
            $existing = $args['meta_query'];
            $args['meta_query'] = [
                'relation' => 'AND',
                $existing,
                $bccClauses,
            ];
        } else {
            $args['meta_query'] = $bccClauses;
        }

        return $args;
    }
}
