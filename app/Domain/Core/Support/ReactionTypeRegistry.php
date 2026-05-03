<?php
/**
 * ReactionTypeRegistry — runtime accessor for the §D5 BCC reaction
 * type IDs (the post.IDs of the seeded peepso_reaction_user CPT posts).
 *
 * Storage: WordPress option `bcc_reaction_ids`, a JSON-encoded map
 * of {kind: post_id} written by ReactionSeeder at plugin activation.
 *
 * Per §D5:
 *   - solid        — agree / "+1"
 *   - vouch        — back this
 *   - stand_behind — stake my rep
 *
 * Returns null when the seeder hasn't run (or PeepSo isn't installed)
 * — callers MUST handle the null case as "structurally 0 reactions
 * of that kind exist." This is the same contract as the V1.0
 * pre-seed stubs: the surface returns 0 when the type isn't resolvable.
 *
 * Per-request memoization: the option lookup happens at most once
 * per request. Subsequent calls hit the in-memory cache.
 *
 * @package BCC\Trust\Core\Support
 * @since V1 (2026-04, §D5 reactions)
 */

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class ReactionTypeRegistry
{
    /** Option key for the resolved reaction-id map. */
    public const OPTION_KEY = 'bcc_reaction_ids';

    public const KIND_SOLID        = 'solid';
    public const KIND_VOUCH        = 'vouch';
    public const KIND_STAND_BEHIND = 'stand_behind';

    /** @var list<string> */
    public const ALL_KINDS = [
        self::KIND_SOLID,
        self::KIND_VOUCH,
        self::KIND_STAND_BEHIND,
    ];

    /** @var array<string, int|null>|null per-request cache; null = not loaded */
    private static ?array $cache = null;

    /**
     * Reset the per-request cache. Called by the seeder after a fresh
     * write so the rest of the request sees the new IDs.
     */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Resolved post.ID for the given reaction kind, or null when the
     * seeder hasn't run / the option is malformed.
     */
    public static function idFor(string $kind): ?int
    {
        $map = self::all();
        $value = $map[$kind] ?? null;
        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * Convenience accessors — the only kinds in V1.
     */
    public static function solidId(): ?int        { return self::idFor(self::KIND_SOLID); }
    public static function vouchId(): ?int        { return self::idFor(self::KIND_VOUCH); }
    public static function standBehindId(): ?int  { return self::idFor(self::KIND_STAND_BEHIND); }

    /**
     * Full kind → id map. Missing or malformed entries → null.
     *
     * @return array<string, int|null>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $raw = get_option(self::OPTION_KEY, '');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        $map = [
            self::KIND_SOLID        => null,
            self::KIND_VOUCH        => null,
            self::KIND_STAND_BEHIND => null,
        ];

        if (is_array($decoded)) {
            foreach (self::ALL_KINDS as $kind) {
                if (isset($decoded[$kind]) && is_numeric($decoded[$kind])) {
                    $value = (int) $decoded[$kind];
                    $map[$kind] = $value > 0 ? $value : null;
                }
            }
        }

        self::$cache = $map;
        return $map;
    }

    /**
     * Persist the resolved map. Called by ReactionSeeder.
     *
     * @param array<string, int> $map kind => post.ID
     */
    public static function persist(array $map): void
    {
        $sanitized = [];
        foreach (self::ALL_KINDS as $kind) {
            if (isset($map[$kind]) && is_int($map[$kind]) && $map[$kind] > 0) {
                $sanitized[$kind] = $map[$kind];
            }
        }
        update_option(self::OPTION_KEY, wp_json_encode($sanitized), false);

        // update_option() typically clears its own object-cache entry,
        // but on installations with non-persistent or partial alloptions
        // caching the next read can still return stale. Explicit
        // wp_cache_delete on the 'options' group is belt-and-suspenders
        // — costs ~zero and removes ambiguity.
        wp_cache_delete(self::OPTION_KEY, 'options');

        self::flush();
    }
}
