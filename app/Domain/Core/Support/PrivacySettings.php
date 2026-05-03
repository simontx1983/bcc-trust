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
        'binder_hidden',
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
     * Read the seven §K2 flags for the user view-model. Always returns a
     * complete array — missing meta defaults to false (V1 baseline:
     * everything public per §K2).
     *
     * @return array{
     *   binder_hidden: bool,
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
            'binder_hidden'         => self::flag($userId, 'binder_hidden'),
            'reviews_hidden'        => self::flag($userId, 'reviews_hidden'),
            'disputes_hidden'       => self::flag($userId, 'disputes_hidden'),
            'delegations_hidden'    => self::flag($userId, 'delegations_hidden'),
            'follower_count_hidden' => self::flag($userId, 'follower_count_hidden'),
            'real_name_hidden'      => self::flag($userId, 'real_name_hidden'),
            'email_hidden'          => self::flag($userId, 'email_hidden'),
        ];
    }

    /**
     * Read all eight flags for the /me/privacy endpoint response.
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
