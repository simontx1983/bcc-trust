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
     *
     * `binder_hidden` is doubled with `watching_hidden` during release N
     * per the additive-deprecation runway (api-contract §1.1.1). Both
     * keys carry the same boolean value via the lazy-migration read
     * path; writes go to `watching_hidden` only. `binder_hidden`
     * removed in release N+1.
     */
    public const PROFILE_KEYS = [
        'binder_hidden',
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
        'binder_hidden',
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
     * Read the §K2 flags for the user view-model. Always returns a
     * complete array — missing meta defaults to false (V1 baseline:
     * everything public per §K2).
     *
     * `binder_hidden` / `watching_hidden` are mirrored: the watching
     * flag is resolved via readWatchingHidden() (canonical key with
     * lazy migration from the legacy `binder_hidden` user_meta key),
     * and the legacy field on the response carries the same boolean.
     * Removed in release N+1.
     *
     * @return array{
     *   binder_hidden: bool,
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
        $watchingHidden = self::readWatchingHidden($userId);
        return [
            // Doubled — identical value. Legacy field, dropped in N+1.
            'binder_hidden'         => $watchingHidden,
            'watching_hidden'       => $watchingHidden,
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
     * `binder_hidden` and `watching_hidden` are mirrored — both carry
     * the same value after the read-side lazy migration.
     *
     * @return array<string, bool>
     */
    public static function readAll(int $userId): array
    {
        $watchingHidden = self::readWatchingHidden($userId);
        $out = [];
        foreach (self::ALL_KEYS as $key) {
            if ($key === 'binder_hidden' || $key === 'watching_hidden') {
                $out[$key] = $watchingHidden;
                continue;
            }
            $out[$key] = self::flag($userId, $key);
        }
        return $out;
    }

    /**
     * Write a partial set of flags. Only keys in ALL_KEYS are written;
     * unknown keys are silently dropped (validation is the endpoint's
     * job — by the time we get here the input is already filtered).
     *
     * `binder_hidden` and `watching_hidden` both route to the canonical
     * `bcc_privacy_watching_hidden` user_meta key (legacy key is left
     * inert after the lazy migration in readWatchingHidden). This
     * preserves a single source of truth even when the frontend sends
     * the legacy field name during release N.
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
            // Legacy `binder_hidden` writes funnel to the canonical
            // `watching_hidden` user_meta key — keeps the two flags
            // structurally indistinguishable during release N and
            // means dropping the legacy field in N+1 needs no data
            // migration.
            $storageKey = ($key === 'binder_hidden') ? 'watching_hidden' : $key;
            // Store as "1" / "0" strings (matches WP's boolean meta convention
            // and round-trips through FILTER_VALIDATE_BOOLEAN cleanly).
            update_user_meta($userId, self::META_PREFIX . $storageKey, $value ? '1' : '0');
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
     * Read the canonical `watching_hidden` flag with lazy migration from
     * the legacy `binder_hidden` user_meta key.
     *
     * Resolution order:
     *   1. `bcc_privacy_watching_hidden` (canonical, set on first write
     *      or migration). Empty meta = '' → treated as "not set".
     *   2. `bcc_privacy_binder_hidden`   (legacy) — when present and
     *      non-empty, copy to the canonical key and DELETE the legacy
     *      row so the next read short-circuits at step 1.
     *
     * Returns false when neither key is set (V1 baseline: public).
     *
     * Idempotent: a second concurrent reader for a user who already
     * migrated sees the new key at step 1 and skips the migration.
     */
    private static function readWatchingHidden(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $canonical = get_user_meta($userId, self::META_PREFIX . 'watching_hidden', true);
        if (is_string($canonical) && $canonical !== '') {
            return filter_var($canonical, FILTER_VALIDATE_BOOLEAN);
        }

        // Legacy fallback + lazy migration.
        $legacy = get_user_meta($userId, self::META_PREFIX . 'binder_hidden', true);
        if (!is_string($legacy) || $legacy === '') {
            return false;
        }

        // Migrate: copy the legacy value to the canonical key, then
        // delete the legacy row. update_user_meta is a no-op if the
        // canonical key was set between our get + here (idempotent).
        update_user_meta($userId, self::META_PREFIX . 'watching_hidden', $legacy);
        delete_user_meta($userId, self::META_PREFIX . 'binder_hidden');

        return filter_var($legacy, FILTER_VALIDATE_BOOLEAN);
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

        if (isset($args['meta_query']) && is_array($args['meta_query'])) {
            $existing = $args['meta_query'];
            $args['meta_query'] = [
                'relation' => 'AND',
                $existing,
                $optoutClause,
            ];
        } else {
            $args['meta_query'] = $optoutClause;
        }

        return $args;
    }
}
