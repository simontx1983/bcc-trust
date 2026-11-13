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
 * ⭐ ONE FLAG FOR "HIDDEN FROM MEMBER SEARCH" (F-257, option A).
 * `discovery_optout` (here, /me/privacy) and `hide_from_search` (PeepSo's
 * `peepso_is_hide_profile_from_user_listing`, /me/profile-prefs) were two
 * settings with one meaning, stored apart, so they could disagree.
 * PeepSo's key is now the single source of truth: `discovery_optout` is an
 * ALIAS that reads and writes it (KEY_ALIASES), the one-shot migration
 * fold_discovery_optout_v1 copied every ON opt-out into it, and search
 * excludes on it alone. The legacy `bcc_privacy_discovery_optout` rows are
 * left in place but nothing reads them once the migration is done.
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
    public const HIDE_FROM_LISTING_META = 'peepso_is_hide_profile_from_user_listing';

    /**
     * Public keys stored under a meta key other than `bcc_privacy_<key>`.
     * `discovery_optout` IS `hide_from_search` (F-257): the field stays on
     * /me/privacy for compatibility, but reads and writes the one flag.
     */
    private const KEY_ALIASES = [
        'discovery_optout' => self::HIDE_FROM_LISTING_META,
    ];

    /** The pre-F-257 discovery opt-out key. Read only until the fold migration is done. */
    public const LEGACY_DISCOVERY_OPTOUT_META = 'bcc_privacy_discovery_optout';

    /**
     * Done-marker of fold_discovery_optout_v1 (migration-runner.php). Until
     * it is set, search ALSO honours the legacy key, so nobody hidden before
     * the deploy is listed while the migration is still copying.
     */
    public const FOLD_DONE_OPTION = 'bcc_trust_discovery_optout_folded';

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
            update_user_meta($userId, self::metaKey($key), $value ? '1' : '0');
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
        $raw = get_user_meta($userId, self::metaKey($key), true);
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The wp_usermeta key behind flag($key), for a repository that has to
     * apply the flag inside SQL (F-340: `real_name_hidden` attestors are
     * left out of the profile vouch line). The same key flag() reads.
     */
    public static function metaKeyFor(string $key): string
    {
        return self::metaKey($key);
    }

    /** The wp_usermeta key a public flag is stored under. */
    private static function metaKey(string $key): string
    {
        return self::KEY_ALIASES[$key] ?? self::META_PREFIX . $key;
    }

    /**
     * Hook the hidden-from-search flag into PeepSo's user-search filter.
     *
     * Called once at plugin boot. PeepSo's UserSearch class fires
     * `peepso_user_search_args` to let consumers narrow the WP_User_Query
     * args; we splice in a `meta_query` clause that excludes any user
     * whose hide_from_search (= discovery_optout, F-257) is on.
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
        // NOT-EXISTS-OR-FALSY: users who never touched the setting have
        // no meta row at all, and a bare `value != '1'` would exclude them.
        //
        // F-257: this is the ONE hidden-from-search flag; /me/privacy's
        // `discovery_optout` reads and writes this same key.
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

        // ⚠ ADMINS ARE EXEMPT FROM BOTH BCC CLAUSES, per the user's
        // "admins exempt" rule — otherwise a moderator cannot find a
        // hidden or opted-out member in member search, which is the one
        // job that rule exists for. Mirrors `PeepSo::is_admin()`, the
        // same predicate `is_accessible_static` and the level-30 clause
        // use, so the three cannot drift.
        //
        // §F-190 widened this. #475 scoped it to the hide clause and
        // said why: `bcc_privacy_discovery_optout` is a DIFFERENT setting
        // that had always applied to admins too, and loosening it was a
        // product decision nobody had made. The decision has since been
        // made, so the exemption is now blanket.
        //
        // ⚠ An EARLY RETURN, not an empty clause list: with no bcc
        // clauses to add there is nothing to merge, and any `meta_query`
        // the caller already set (PeepSo's own VIP filter, for one) must
        // be handed back exactly as it arrived rather than rewrapped in
        // an `AND` around nothing.
        $isAdmin = class_exists('\\PeepSo') && \PeepSo::is_admin();
        if ($isAdmin) {
            return $args;
        }

        $bccClauses = ['relation' => 'AND', $hideClause];

        // F-257 rollout: until fold_discovery_optout_v1 has copied every
        // legacy opt-out into the one flag, keep honouring the legacy key
        // too, so nobody hidden before the deploy is listed meanwhile. A
        // migration that never completes keeps this on: fail closed.
        if (!get_option(self::FOLD_DONE_OPTION)) {
            $bccClauses[] = [
                'relation' => 'OR',
                [
                    'key'     => self::LEGACY_DISCOVERY_OPTOUT_META,
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => self::LEGACY_DISCOVERY_OPTOUT_META,
                    'value'   => '1',
                    'compare' => '!=',
                ],
            ];
        }

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
