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
 * ⚠ Reads go through bcc-core's UserMetaRepository, never get_user_meta(),
 * so a flag that could not be read is known to be unread, and it reads as
 * hidden; see flag(). Rationale: M3 (private tracker).
 *
 * @package BCC\Trust\Core\Support
 * @since V1 (2026-04, §K2 privacy UI)
 */

namespace BCC\Trust\Core\Support;

use BCC\Core\Repositories\UserMetaRepository;

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
     * Is the legacy discovery opt-out still read? The one answer every
     * listing gives (search here, and the listing fragment
     * UserSyncRepository::listingOptOutSql() that the public index and the
     * directory share): true until fold_discovery_optout_v1's done-marker
     * reads as set. The runner stores time(), so "set" is truthy.
     *
     * Missing, falsy or unreadable (get_option() then answers false) all
     * give true: keep honouring the legacy key, the side that cannot list
     * someone who opted out. After the fold it must be false, because the
     * fold left the legacy rows in place and a member who later turned
     * listing back on still holds a legacy '1'.
     */
    public static function legacyOptOutStillRead(): bool
    {
        return !get_option(self::FOLD_DONE_OPTION);
    }

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
        $flags = self::flagsOrClosed($userId);

        return [
            'watching_hidden'       => $flags['watching_hidden'],
            'reviews_hidden'        => $flags['reviews_hidden'],
            'disputes_hidden'       => $flags['disputes_hidden'],
            'delegations_hidden'    => $flags['delegations_hidden'],
            'follower_count_hidden' => $flags['follower_count_hidden'],
            'real_name_hidden'      => $flags['real_name_hidden'],
            'email_hidden'          => $flags['email_hidden'],
        ];
    }

    /**
     * Read all flags for the /me/privacy endpoint response.
     *
     * An unreadable set shows every toggle ON for that response (see
     * {@see flag()}). Nothing is persisted from it: PATCH /me/privacy is
     * a partial write of only the keys the member sends.
     *
     * @return array<string, bool>
     */
    public static function readAll(int $userId): array
    {
        // `discovery_optout` answers as search applies it (searchOptOut()).
        $flags = self::flagsOrClosed($userId, true);

        $out = [];
        foreach (self::ALL_KEYS as $key) {
            $out[$key] = $flags[$key];
        }
        return $out;
    }

    /**
     * Read many members' flags in one query (per UserMetaRepository::BATCH_MAX),
     * so a list that renders each member through {@see flag()} or
     * {@see readProfile()} costs one read rather than one per member.
     * Optional: an unprimed member is read on first use.
     *
     * @param list<int> $userIds
     */
    public static function prime(array $userIds): void
    {
        $ids = [];
        foreach ($userIds as $id) {
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        foreach (array_chunk(array_values($ids), UserMetaRepository::BATCH_MAX) as $chunk) {
            UserMetaRepository::valuesOrUnknown($chunk, self::metaKeys());
        }
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
        // The repository also forgets on the user-meta write hooks; this
        // covers a runtime where those hooks are not wired.
        UserMetaRepository::forget($userId);

        // F-294: let caches that embed a member's name or listing react to a
        // privacy change (the anon hot feed, on real_name_hidden). Only the
        // keys this call actually wrote are passed.
        $written = array_values(array_intersect(array_keys($partial), self::ALL_KEYS));
        if ($written !== []) {
            do_action('bcc_privacy_settings_changed', $userId, $written);
        }
    }

    /** Flag reads this request that could not be trusted and read as hidden. */
    private static int $unreadReads = 0;

    /**
     * How many flag reads this request could not be trusted, each read as
     * hidden. Monotonic within a request, so a cache shared by everyone
     * snapshots it around a build and declines to keep a page whose names
     * were masked that way (FeedRankingService's hot feed). Never decreases.
     */
    public static function unreadReads(): int
    {
        return self::$unreadReads;
    }

    /**
     * Single-flag read. Boolean-truthy parsing per UserViewService::metaFlag.
     *
     * ⚠⚠ AN UNREADABLE FLAG READS AS ON, i.e. HIDDEN. Every key here is a
     * "hide this" switch, so ON is the closed answer. {@see flagOrUnknown()}
     * gives the three-way answer for a caller that wants to choose.
     * Rationale: M3 (private tracker).
     */
    public static function flag(int $userId, string $key): bool
    {
        $flag = self::flagOrUnknown($userId, $key);
        if ($flag === null) {
            self::$unreadReads++;

            return true;
        }

        return $flag;
    }

    /**
     * true / false when the member's setting was READ; null when the read
     * cannot be trusted (see UserMetaRepository). No member (id <= 0) has
     * nothing hidden, as before.
     *
     * Duplicate rows for one key: the strictest wins (any ON row means
     * ON), the direction that hides rather than leaks. WordPress's
     * update_user_meta() rewrites every row, so they only diverge when
     * something called add_user_meta() directly.
     *
     * `discovery_optout` answers as search applies it: {@see searchOptOut()}.
     */
    public static function flagOrUnknown(int $userId, string $key): ?bool
    {
        if ($userId <= 0) {
            return false;
        }
        $metaKey = self::metaKey($key);
        $keys    = self::metaKeys();
        if (!in_array($metaKey, $keys, true)) {
            $keys = [$metaKey];
        }
        $isSearchOptOut = $key === 'discovery_optout';
        if ($isSearchOptOut) {
            $keys[] = self::LEGACY_DISCOVERY_OPTOUT_META;
        }

        $values = UserMetaRepository::valuesOrUnknown([$userId], $keys);
        if ($values === null) {
            return null;
        }

        $byKey = $values[$userId] ?? [];

        return $isSearchOptOut ? self::searchOptOut($byKey) : self::strictest($byKey[$metaKey] ?? []);
    }

    /**
     * Every public flag for one member, read once. An unreadable set reads
     * as ALL HIDDEN, as {@see flag()} does.
     *
     * `$withSearchOptOut` also reads the legacy opt-out, so that
     * `discovery_optout` answers as search applies it
     * ({@see searchOptOut()}). Without it, as for {@see readProfile()},
     * which never returns that key, the read stays the ALL_KEYS one that
     * {@see prime()} batches.
     *
     * @return array<string, bool> public key => hidden
     */
    private static function flagsOrClosed(int $userId, bool $withSearchOptOut = false): array
    {
        if ($userId <= 0) {
            return array_fill_keys(self::ALL_KEYS, false);
        }

        $keys = self::metaKeys();
        if ($withSearchOptOut) {
            $keys[] = self::LEGACY_DISCOVERY_OPTOUT_META;
        }
        $values = UserMetaRepository::valuesOrUnknown([$userId], $keys);
        if ($values === null) {
            self::$unreadReads++;

            return array_fill_keys(self::ALL_KEYS, true);
        }

        $byKey = $values[$userId] ?? [];
        $flags = [];
        foreach (self::ALL_KEYS as $key) {
            $flags[$key] = self::strictest($byKey[self::metaKey($key)] ?? []);
        }
        if ($withSearchOptOut) {
            $flags['discovery_optout'] = self::searchOptOut($byKey);
        }

        return $flags;
    }

    /**
     * `discovery_optout` (= /me/profile-prefs `hide_from_search`) as search
     * applies it: the per-member form of
     * {@see \BCC\Trust\Core\Repositories\UserSyncRepository::listingOptOutSql()}.
     * Hidden when any row of the one flag reads as on, or, until the F-257
     * fold has completed ({@see legacyOptOutStillRead()}), any row of the
     * legacy opt-out does. So the setting a member is shown is what search
     * does to them (F-519).
     *
     * The fold-done option is read only when the one flag is off and a
     * legacy row is on.
     *
     * @param array<string, list<string>> $byKey
     */
    private static function searchOptOut(array $byKey): bool
    {
        if (self::strictest($byKey[self::HIDE_FROM_LISTING_META] ?? [])) {
            return true;
        }

        return self::strictest($byKey[self::LEGACY_DISCOVERY_OPTOUT_META] ?? [])
            && self::legacyOptOutStillRead();
    }

    /** @param list<string> $rawValues */
    private static function strictest(array $rawValues): bool
    {
        foreach ($rawValues as $raw) {
            if (filter_var($raw, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The meta keys behind ALL_KEYS, read together so one query (and one
     * memo entry) serves every flag of a member.
     *
     * @return list<string>
     */
    private static function metaKeys(): array
    {
        $keys = [];
        foreach (self::ALL_KEYS as $key) {
            $keys[] = self::metaKey($key);
        }

        return $keys;
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
    /**
     * `PeepSoUserSearch` arg: leave the search opt-out (hide_from_search)
     * out of this listing. Not a search and not the directory — see
     * {@see filterPeepSoSearchArgs()}.
     */
    public const ARG_IGNORE_SEARCH_OPTOUT = '_bcc_ignore_search_optout';

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

        // F-295: ONE block system. BCC enforces blocks always (it reads
        // peepso_blocks directly through PeepSoBlockRepository), but
        // PeepSo's own SQL honours them only while its admin option
        // `user_blocking_enable` is on: the user search behind our member
        // search and the mention gate, plus PeepSo activity, photos and
        // messages. Pin the option ON so both agree whatever the toggle
        // says. Same posture as the hide-from-listing args filter above.
        add_filter('peepso_filter_get_option_user_blocking_enable', [self::class, 'pinBlockingEnabled'], 20, 1);
    }

    /**
     * F-295: PeepSo's `user_blocking_enable` always reads as ON.
     *
     * PeepSoConfigSettings::get_option() applies
     * `peepso_filter_get_option_{name}` on every read, and both
     * PeepSo::get_option() and PeepSo::get_option_new() go through it. So
     * PeepSo's admin toggle also READS as on even when it is saved off,
     * which is intended: blocks are not optional on BCC.
     *
     * @param mixed $stored the saved value (ignored)
     */
    public static function pinBlockingEnabled($stored): int
    {
        unset($stored);
        return 1;
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
     * ⚠ A caller that is NOT a search or the directory passes
     * {@see self::ARG_IGNORE_SEARCH_OPTOUT}: hide_from_search scopes to
     * member search and the directory only (user decision 2026-11-12,
     * F-257 copy). Group rosters follow profile privacy instead, so they
     * keep every other leg of PeepSo's listing rule and skip just this one.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function filterPeepSoSearchArgs(array $args): array
    {
        if (!empty($args[self::ARG_IGNORE_SEARCH_OPTOUT])) {
            unset($args[self::ARG_IGNORE_SEARCH_OPTOUT]);
            return $args;
        }

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
        //
        // ⚠ NOTHING WRITES the legacy key any more — this is a READ that
        // exists only until the fold completes, not a second live setting.
        // Worth saying, because the clause below looks like one: a reader
        // who takes it for a setting still in use will add it to cache-bust
        // lists and invalidation triggers, where it would be dead weight
        // implying a dependency that no longer exists. When
        // FOLD_DONE_OPTION is set this whole branch stops running, and the
        // rows can then be dropped.
        if (self::legacyOptOutStillRead()) {
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
