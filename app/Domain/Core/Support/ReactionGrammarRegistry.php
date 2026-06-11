<?php
/**
 * ReactionGrammarRegistry — resolution layer for the v1.5 layered
 * reaction model. Maps reaction-kind names to their underlying
 * peepso_reaction_user post.IDs.
 *
 * The contract layer (post_kind → grammar, kind name strings, kind
 * lists per grammar) lives in bcc-core's
 * {@see \BCC\Core\Feed\ReactionGrammarMap}. This registry is the
 * implementation layer — it composes BCC-seeded IDs (trust three +
 * Fire, owned by ReactionTypeRegistry) with PeepSo-default IDs
 * (Like / Love / Haha / Wow, looked up by post_title).
 *
 * Why two classes:
 *   - bcc-core can't depend on bcc-trust (one-way dependency rule).
 *   - The FeedItemNormalizer (bcc-core) needs to emit `kind_grammar`
 *     in its empty-reactions fallback, which needs the post_kind →
 *     grammar map but not the IDs.
 *   - The hydrator + endpoint (bcc-trust) need full ID resolution
 *     across BCC-seeded + PeepSo-default kinds.
 *
 * Storage: PeepSo-default reaction IDs (Like/Love/Haha/Wow) are
 * cached in WordPress option `bcc_grammar_reaction_ids` after the
 * first lookup. The cache is invalidated by `flush()` (call after
 * admin renames a CPT post or PeepSo upgrades reseed defaults).
 *
 * Defensive posture: when a default reaction post can't be resolved
 * (admin deleted "Wow"), it's omitted from idToKindMap. The
 * hydrator then leaves that kind's count at 0; the rail still
 * renders the surviving kinds. Reactions are supplementary chrome —
 * they MUST NOT block the page from rendering.
 *
 * @package BCC\Trust\Core\Support
 * @since v1.5 (2026-05, social grammar)
 */

namespace BCC\Trust\Core\Support;

use BCC\Core\Feed\ReactionGrammarMap;

if (!defined('ABSPATH')) {
    exit;
}

final class ReactionGrammarRegistry
{
    /** Option key for the resolved PeepSo-default reaction-id map. */
    public const OPTION_KEY = 'bcc_grammar_reaction_ids';

    /**
     * post_title → kind for PeepSo's seeded defaults the social
     * grammar curates. Source: peepso/install/activate.php
     * `reactions_install()`. Stable across PeepSo English installs;
     * non-English locales translate the post_title at install time,
     * which means a fresh non-English install will fail this lookup
     * and the missing kinds will show zero counts. Fix is admin work
     * (rename one CPT post per missing kind), not code work.
     *
     * @var array<string, string>
     */
    private const PEEPSO_DEFAULT_TITLES = [
        'Like' => ReactionGrammarMap::KIND_LIKE,
        'Love' => ReactionGrammarMap::KIND_LOVE,
        'Haha' => ReactionGrammarMap::KIND_HAHA,
        'Wow'  => ReactionGrammarMap::KIND_WOW,
    ];

    /** @var array<string, int|null>|null per-request cache; null = not loaded */
    private static ?array $cache = null;

    /**
     * Reset the per-request + persisted PeepSo-default ID cache.
     * Call after admin renames a CPT post or PeepSo upgrades reseed
     * the defaults.
     */
    public static function flush(): void
    {
        self::$cache = null;
        delete_option(self::OPTION_KEY);
    }

    /**
     * Resolve a kind to its peepso_reaction_user post.ID. Returns
     * null when the kind isn't seeded / discoverable.
     *
     * Trust + Fire kinds are BCC-seeded — resolved via
     * ReactionTypeRegistry. PeepSo-default kinds (like/love/haha/wow)
     * are looked up by post_title via the cached default-id map.
     */
    public static function idFor(string $kind): ?int
    {
        $bcc = ReactionTypeRegistry::idFor($kind);
        if ($bcc !== null) {
            return $bcc;
        }

        $map = self::peepSoDefaultMap();
        $value = $map[$kind] ?? null;
        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * Reverse map: peepso_reaction_user post.ID → kind name. Used by
     * FeedRankingService::hydrateReactions to translate raw count
     * rows into the grammar's kind-keyed counts dict.
     *
     * Only includes kinds that resolve to a positive ID — missing
     * defaults (admin deleted "Wow") are omitted, and the hydrator
     * leaves their counts at 0.
     *
     * @return array<int, string>
     */
    public static function idToKindMap(): array
    {
        $map = [];
        foreach (ReactionTypeRegistry::ALL_KINDS as $kind) {
            $id = ReactionTypeRegistry::idFor($kind);
            if ($id !== null) {
                $map[$id] = $kind;
            }
        }
        foreach (self::peepSoDefaultMap() as $kind => $id) {
            if (is_int($id) && $id > 0) {
                $map[$id] = $kind;
            }
        }
        return $map;
    }

    /**
     * Lookup-by-post_title for PeepSo's seeded defaults. Cached in
     * the OPTION_KEY option after the first resolve. Per-request
     * memoization on top.
     *
     * @return array<string, int|null>
     */
    private static function peepSoDefaultMap(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $raw = get_option(self::OPTION_KEY, '');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (is_array($decoded)) {
            $map = self::sanitizeDecoded($decoded);
            self::$cache = $map;
            return $map;
        }

        $map = self::resolvePeepSoDefaults();
        update_option(self::OPTION_KEY, wp_json_encode($map), false);
        self::$cache = $map;
        return $map;
    }

    /**
     * Query wp_posts for the PeepSo-default reaction CPT entries.
     * One get_posts() per title — read-only, runs at most once per
     * cache lifetime, ~4 cheap queries on first miss.
     *
     * @return array<string, int|null>
     */
    private static function resolvePeepSoDefaults(): array
    {
        $map = [];
        foreach (array_values(self::PEEPSO_DEFAULT_TITLES) as $kind) {
            $map[$kind] = null;
        }

        foreach (self::PEEPSO_DEFAULT_TITLES as $title => $kind) {
            // PeepSo stores its default reaction TYPE posts as
            // `peepso_reaction`; `peepso_reaction_user` rows are users'
            // cast reactions (plus the BCC-seeded type posts, which
            // ReactionTypeRegistry resolves separately). Accept both so
            // the lookup survives PeepSo versions that renamed the CPT.
            $posts = get_posts([
                'post_type'      => ['peepso_reaction', 'peepso_reaction_user'],
                'post_status'    => 'publish',
                'title'          => $title,
                'posts_per_page' => 1,
                'no_found_rows'  => true,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);
            if (is_array($posts) && isset($posts[0])) {
                $map[$kind] = (int) $posts[0];
            }
        }
        return $map;
    }

    /**
     * @param array<mixed, mixed> $decoded
     * @return array<string, int|null>
     */
    private static function sanitizeDecoded(array $decoded): array
    {
        $map = [];
        foreach (array_values(self::PEEPSO_DEFAULT_TITLES) as $kind) {
            $map[$kind] = null;
            if (isset($decoded[$kind]) && is_numeric($decoded[$kind])) {
                $value = (int) $decoded[$kind];
                if ($value > 0) {
                    $map[$kind] = $value;
                }
            }
        }
        return $map;
    }
}
