<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;
use BCC\Trust\Onchain\ValueObjects\RepositoryWriteResult;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Chain registry repository.
 *
 * ─── CACHE INVARIANT (DO NOT BREAK) ─────────────────────────────────────────
 * Only validated, DB-derived chain data is ever written to the TRANSIENT
 * (`bcc_active_chains`). The transient is shared storage — on a multi-node
 * deployment it propagates across workers. Writing a failure sentinel or a
 * placeholder to the transient would poison every other node.
 *
 * The short-lived error sentinel goes to the OBJECT CACHE ONLY, which is
 * typically per-request / per-node. A node seeing transient DB errors will
 * have its own 5-second cooldown and will not damage healthy nodes.
 *
 * Any future cache write on this class must preserve this invariant:
 *   - valid data  → wp_cache_set + set_transient
 *   - failure     → wp_cache_set only (never the transient)
 * ────────────────────────────────────────────────────────────────────────────
 *
 * @phpstan-type ChainRow object{
 *     id: string,
 *     slug: string,
 *     name: string,
 *     chain_type: string,
 *     chain_id_hex: string|null,
 *     rpc_url: string|null,
 *     rest_url: string|null,
 *     explorer_url: string|null,
 *     native_token: string|null,
 *     decimals: string,
 *     bech32_prefix: string|null,
 *     icon_url: string|null,
 *     color: string|null,
 *     marketplace_template: string|null,
 *     description: string|null,
 *     is_testnet: string,
 *     is_active: string,
 *     cosmwasm_nft_discovery_enabled: string,
 *     bcc_supports_nft_collections: string,
 *     manual_collection_discovery_enabled: string,
 *     created_at: string
 * }
 */
final class ChainRepository
{
    /** @var string Explicit column list — must match schema-chains.php
     *  (CREATE TABLE + the description ALTER + the
     *  cosmwasm_nft_discovery_enabled ALTER + the NFT capability ALTERs)
     *  + schema-blog-chain-tags.php's ALTER (color).
     *
     *  `bcc_supports_nft_collections` and `manual_collection_discovery_enabled`
     *  MUST stay in this list. Their readers
     *  ({@see \BCC\Trust\Onchain\Support\NftChainCapability}) treat an absent
     *  property as "this install cannot say" and refuse — the correct
     *  fail-closed answer, and a completely SILENT one. Dropping either
     *  column from this projection would therefore make every chain
     *  permanently un-scannable with no error anywhere. Pinned by
     *  ChainNftCapabilityMigrationIntegrationTest. */
    private const COLUMNS = 'id, slug, name, chain_type, chain_id_hex, rpc_url, rest_url,
                 explorer_url, native_token, decimals, bech32_prefix, icon_url, color,
                 marketplace_template, description, is_testnet, is_active,
                 cosmwasm_nft_discovery_enabled, bcc_supports_nft_collections,
                 manual_collection_discovery_enabled, created_at';

    /** @var string Object-cache / transient group. */
    private const CACHE_GROUP = 'bcc_chains';

    /** @var int Default TTL in seconds (5 minutes). Filterable via bcc_chains_cache_ttl.
     *  Reduced from 1 hour: if a chain is deactivated, the old 1-hour TTL allowed
     *  wallet verifications on deactivated chains for up to 60 minutes. */
    private const DEFAULT_TTL = 300;

    /** @var string Sentinel value cached briefly after a DB failure so hot-path callers
     *  don't hammer the failing DB (and flood logs) on every request during a 10-30s
     *  outage. The sentinel is distinguishable from a real result (which is always an
     *  array) so we never confuse it with "zero active chains". */
    private const ERROR_SENTINEL = '__bcc_chains_db_error__';

    /** @var int Base TTL for the error sentinel (seconds). Actual TTL is jittered
     *  via errorSentinelTtl() to desynchronize retries across nodes during a long
     *  outage — without jitter, N nodes that all observed the failure at T+0 would
     *  all retry at T+5, producing a synchronized thundering herd against the
     *  recovering DB. */
    private const ERROR_SENTINEL_TTL_BASE = 5;

    /** @var int Max additional jitter in seconds (uniform [0, N]). */
    private const ERROR_SENTINEL_TTL_JITTER = 3;

    public static function table(): string
    {
        return DB::table('chains');
    }

    /** @return ChainRow|null */
    public static function getBySlug(string $slug): ?object
    {
        // Lookup from the cached active-chains set first.
        foreach (self::getActive() as $chain) {
            if ($chain->slug === $slug) {
                return $chain;
            }
        }

        return null;
    }

    /** @var array<int, ChainRow|null> Request-scoped memo for the getById()
     *  inactive-chain fallback. Callers loop getById() per feed/blog item,
     *  so an id outside the cached active set would otherwise hit the DB
     *  once per item. Stores misses (null) too. Request-scoped only — no
     *  shared-cache writes, so the CACHE INVARIANT is untouched. */
    private static array $byIdMemo = [];

    /** @return ChainRow|null */
    public static function getById(int $chainId): ?object
    {
        // Check the cached active set.
        foreach (self::getAllCached() as $chain) {
            if ((int) $chain->id === $chainId) {
                return $chain;
            }
        }

        if (array_key_exists($chainId, self::$byIdMemo)) {
            return self::$byIdMemo[$chainId];
        }

        // Fallback: inactive chain or cache miss — direct query.
        global $wpdb;
        $table = self::table();

        /** @var ChainRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$table} WHERE id = %d LIMIT 1",
            $chainId
        ));

        self::$byIdMemo[$chainId] = $row;

        return $row;
    }

    /** @return list<ChainRow> */
    public static function getActive(?string $chainType = null): array
    {
        $all = self::getAllCached();

        if ($chainType) {
            return array_values(array_filter(
                $all,
                fn($c) => $c->chain_type === $chainType
            ));
        }

        return $all;
    }

    public static function resolveId(string $slug): ?int
    {
        $chain = self::getBySlug($slug);
        return $chain ? (int) $chain->id : null;
    }

    /** @var array<string, int|null> Request-scoped memo for the slug fallback. */
    private static array $idBySlugMemo = [];

    /**
     * Resolve a chain id by slug INCLUDING deactivated chains.
     *
     * resolveId()/getBySlug() only see the active-chains cache. The gated-group
     * discovery filter must resolve a slug to its id even when the chain has
     * since been deactivated — parity with the pre-repository inline lookup
     * (`SELECT id FROM chains WHERE slug = %s`, no is_active filter); otherwise
     * groups gated on a now-inactive chain silently stop appearing. Mirrors
     * getById()'s inactive fallback (active set first, then a direct query).
     */
    public static function resolveIdAnyState(string $slug): ?int
    {
        foreach (self::getActive() as $chain) {
            if ($chain->slug === $slug) {
                return (int) $chain->id;
            }
        }

        if (array_key_exists($slug, self::$idBySlugMemo)) {
            return self::$idBySlugMemo[$slug];
        }

        global $wpdb;
        $table = self::table();

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE slug = %s LIMIT 1",
            $slug
        ));

        $resolved = $id !== null ? (int) $id : null;
        self::$idBySlugMemo[$slug] = $resolved;

        return $resolved;
    }

    /**
     * Map group_id → chain slug for the given peepso-group post IDs.
     *
     * Resolves from either of the two canonical chain-tag meta keys:
     *   - `_bcc_gate_chain_id` — NFT holder groups; written by the
     *     gate-config admin flow at claim time.
     *   - `_bcc_chain_tag`     — user-created plain groups; written at
     *     create-time by `PeepSoGroupWriter::createPlainGroup` and
     *     immutable thereafter (`add_post_meta unique=true`).
     *
     * The two keys are mutually exclusive in practice (one is NFT-side,
     * one is plain-side). If both somehow exist on the same post, the
     * first row returned wins — order is implementation-defined but the
     * outcome is deterministic per (post, request).
     *
     * Single bulk SELECT joining `wp_postmeta` → `bcc_onchain_chains` by
     * id. Bounded by `IN ($groupIds)`. Groups carrying neither key
     * (Locals, legacy pre-tag groups) are absent from the returned map
     * — callers treat absence as "no chain tag."
     *
     * Mirrors the CAST-on-meta-value direction used in
     * GroupsDiscoveryEndpoint::filterContextsByChain — `CAST(meta_value AS UNSIGNED)`
     * avoids the utf8mb4_unicode_ci vs utf8mb4_unicode_520_ci illegal-
     * mix-of-collations error that the inverse direction (`CAST(id AS CHAR)`)
     * triggers on this DB.
     *
     * @param list<int> $groupIds
     * @return array<int, string>
     */
    public static function resolveSlugsForGroups(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $ph    = implode(',', array_fill(0, count($groupIds), '%d'));

        /** @var list<object{post_id: numeric-string, slug: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id AS post_id, c.slug AS slug
               FROM {$wpdb->postmeta} pm
               JOIN {$table} c ON c.id = CAST(pm.meta_value AS UNSIGNED)
              WHERE pm.meta_key IN ('_bcc_gate_chain_id', '_bcc_chain_tag')
                AND pm.post_id IN ({$ph})",
            ...$groupIds
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $postId = (int) $row->post_id;
            if (!isset($out[$postId])) {
                $out[$postId] = (string) $row->slug;
            }
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────
    //  Internal
    // ──────────────────────────────────────────────────────────

    /**
     * Return all active chains, served from object cache (per-request)
     * backed by a transient (cross-request, works without Redis).
     *
     * @return list<ChainRow>
     */
    private static function getAllCached(): array
    {
        // 1. Object cache (fastest — lives for the current request / persistent if Redis is present).
        $cached = wp_cache_get('active_all', self::CACHE_GROUP);
        if (is_array($cached)) {
            /** @var list<ChainRow> $cached */
            return $cached;
        }
        // Short-lived negative cache: during a DB outage, a prior request marked
        // this key with ERROR_SENTINEL to stop the next ~5-8s of traffic from
        // hammering the DB and flooding logs. The is_string() guard keeps us
        // from ever matching a weird non-string cache payload (e.g. a serialized
        // object or an int left by an unrelated plugin writing to the same key).
        if (is_string($cached) && $cached === self::ERROR_SENTINEL) {
            return [];
        }

        // 2. Transient (survives across requests even without a persistent object cache).
        $transient = get_transient('bcc_active_chains');
        if (is_array($transient)) {
            /** @var list<ChainRow> $transient */
            // Re-populate object cache so the rest of this request is free.
            wp_cache_set('active_all', $transient, self::CACHE_GROUP, self::ttl());
            return $transient;
        }

        // 3. Cache miss — query the DB.
        global $wpdb;
        $table = self::table();

        /** @var list<ChainRow>|null $rows */
        $rows = $wpdb->get_results(
            "SELECT " . self::COLUMNS . " FROM {$table} WHERE is_active = 1 ORDER BY chain_type ASC, name ASC LIMIT 200"
        );
        // Capture the error string immediately — $wpdb->last_error is connection-global
        // state; any unrelated query that runs before we check it would clobber it.
        $lastError = (string) $wpdb->last_error;

        // Discriminate DB failure ($rows === null + last_error) from "zero active chains"
        // ($rows === [] with no error). Caching an empty list for 5 minutes after a
        // transient DB error would break every downstream chain lookup (wallet verify,
        // fetcher init) until the cache expires — that's a silent outage, not degradation.
        if (!is_array($rows)) {
            // Short negative cache: subsequent hits within ~5-8 seconds skip both
            // the DB query and the log line. Prevents thundering-herd and log
            // floods during transient outages. We deliberately do NOT write the
            // sentinel to the long-lived transient — a failing node must not
            // propagate "no chains" to other workers via shared transient storage.
            // (See CACHE INVARIANT at top of class.)
            wp_cache_set('active_all', self::ERROR_SENTINEL, self::CACHE_GROUP, self::errorSentinelTtl());

            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[ChainRepository] getAllCached DB error: ' . ($lastError !== '' ? $lastError : 'query returned null'));
            }
            return [];
        }

        $ttl = self::ttl();
        wp_cache_set('active_all', $rows, self::CACHE_GROUP, $ttl);
        set_transient('bcc_active_chains', $rows, $ttl);

        return $rows;
    }

    /**
     * Get ALL chains (including inactive). Admin use only.
     *
     * @return list<ChainRow>
     */
    public static function getAll(): array
    {
        global $wpdb;
        $table = self::table();

        /** @var list<ChainRow>|null $rows */
        $rows = $wpdb->get_results(
            "SELECT " . self::COLUMNS . " FROM {$table} ORDER BY chain_type ASC, name ASC LIMIT 200"
        );

        // Defensive: any non-array result (driver quirk, plugin interference, DB
        // failure) is treated as "no chains available" — this is the admin path
        // so a quiet empty render is preferable to a WSOD.
        return is_array($rows) ? $rows : [];
    }

    /**
     * Update the operator-editable chain-identity fields (the wp-admin
     * Chains ▸ Identity editor): the "About this chain" description, icon,
     * and accent color. Bounded to a single row by primary key.
     *
     * Callers pass ALREADY-sanitised values (ChainsPage runs
     * sanitize_textarea_field / esc_url_raw / sanitize_hex_color). This
     * method only builds the prepared UPDATE and busts the chains cache so
     * the Hall chain_profile payload reflects the edit immediately — no
     * fetcher run, no reseed, no other columns touched.
     *
     * A null value writes a true SQL NULL (not the empty string %s would
     * coerce), mirroring NftCollectionPiecesRepository::upsert's nullable-
     * text writes; the read projection treats '' and NULL identically, but
     * NULL is the canonical "unset".
     *
     * @return bool true when the UPDATE executed without a DB error.
     */
    public static function updateIdentity(
        int $chainId,
        ?string $description,
        ?string $iconUrl,
        ?string $color
    ): bool {
        if ($chainId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $descriptionSql = $description !== null ? $wpdb->prepare('%s', $description) : 'NULL';
        $iconUrlSql     = $iconUrl     !== null ? $wpdb->prepare('%s', $iconUrl)     : 'NULL';
        $colorSql       = $color       !== null ? $wpdb->prepare('%s', $color)       : 'NULL';

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET description = {$descriptionSql},
                    icon_url    = {$iconUrlSql},
                    color       = {$colorSql}
              WHERE id = %d
              LIMIT 1",
            $chainId
        ));

        // Bust the cache so a subsequent /halls/:slug read (chain_profile)
        // and the admin re-render both see the new values at once.
        self::clearCache();

        return $result !== false;
    }

    /**
     * Turn the per-chain CosmWasm NFT-discovery opt-in on or off.
     *
     * THE ONLY WRITE PATH for `cosmwasm_nft_discovery_enabled`, and it
     * busts the chains cache as part of the write rather than leaving that
     * to the caller. That is not politeness — {@see getActive()} serves the
     * scanner's eligibility read from a 5-minute object-cache/transient
     * pair, so a toggle that did not invalidate would leave the worker
     * scanning a just-disabled chain (or ignoring a just-enabled one) for
     * up to the whole TTL, with the admin screen showing the new value the
     * entire time. Putting the invalidation here means no future caller can
     * forget it.
     *
     * Bounded to a single row by primary key. Touches exactly one column —
     * an operator flipping discovery must never be able to disturb chain
     * identity, RPC config or `is_active`.
     *
     * @return bool true when the UPDATE executed without a DB error.
     */
    public static function setCosmwasmNftDiscoveryEnabled(int $chainId, bool $enabled): bool
    {
        if ($chainId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET cosmwasm_nft_discovery_enabled = %d
              WHERE id = %d
              LIMIT 1",
            $enabled ? 1 : 0,
            $chainId
        ));

        self::clearCache();

        return $result !== false;
    }

    // ── The NFT capability columns ───────────────────────────────────────
    //
    // THE ONLY WRITE PATH for `bcc_supports_nft_collections` and
    // `manual_collection_discovery_enabled`. Three narrowly named methods,
    // deliberately NOT one `setChainColumn($name, $value)`: a general column
    // updater would take the column name from its caller, and the one thing
    // this table must never allow is a caller deciding which column a
    // capability write lands in.
    //
    // Each one mirrors setCosmwasmNftDiscoveryEnabled(): bounded to a single
    // row by primary key, touching only the named column(s), and busting the
    // chains cache INSIDE the write so no caller can forget it.
    //
    // ── WHY THEY RETURN A RESULT AND NOT A BOOLEAN ───────────────────────
    // `$result !== false` cannot tell a refused statement from one that ran
    // and matched nothing, and the editor above has to distinguish them —
    // see {@see RepositoryWriteResult}. The cache is cleared regardless,
    // including on a zero-row result: a concurrent writer may have applied
    // the change, and leaving this request's memo in place would make the
    // caller's postcondition read answer from a projection taken BEFORE it.

    /**
     * Grant BCC product support for NFT collections on one chain.
     *
     * Touches that column and no other. In particular it does NOT enable
     * `manual_collection_discovery_enabled`: product support is BCC's
     * decision that a chain is in scope, and permission to START a discovery
     * is a second, separate grant. Fusing them would mean a product decision
     * silently armed an operator button.
     */
    public static function enableNftProductSupport(int $chainId): RepositoryWriteResult
    {
        if ($chainId <= 0) {
            return RepositoryWriteResult::failure();
        }

        global $wpdb;
        $table = self::table();

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET bcc_supports_nft_collections = 1
              WHERE id = %d
              LIMIT 1",
            $chainId
        ));

        self::clearCache();

        return RepositoryWriteResult::fromWpdb($result);
    }

    /**
     * Withdraw product support — AND the manual permission with it, in ONE
     * statement.
     *
     * ── WHY THE CASCADE IS PART OF THE SQL ───────────────────────────────
     * A dormant `manual_collection_discovery_enabled = 1` left behind on a
     * chain BCC no longer supports is a permission nobody can see: the
     * capability model reports `no_bcc_support` and stops, so the stale
     * permission is invisible on every surface — until product support is
     * granted again later, at which point the chain silently comes back
     * already permitted to start a discovery.
     *
     * Doing it as two statements would leave a window in which the first
     * succeeded and the second did not, which is precisely that state. So
     * both columns move in one `UPDATE` against one row, and there is no
     * ordering for a caller to get wrong.
     */
    public static function disableNftProductSupport(int $chainId): RepositoryWriteResult
    {
        if ($chainId <= 0) {
            return RepositoryWriteResult::failure();
        }

        global $wpdb;
        $table = self::table();

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET bcc_supports_nft_collections        = 0,
                    manual_collection_discovery_enabled = 0
              WHERE id = %d
              LIMIT 1",
            $chainId
        ));

        self::clearCache();

        return RepositoryWriteResult::fromWpdb($result);
    }

    /**
     * Set the permission for an administrator to START a chain-wide NFT
     * collection discovery.
     *
     * Whether the permission is ALLOWED to be granted — product support must
     * be on, and an administrator-started operation must actually exist for
     * the chain — is a domain question answered above this layer by
     * {@see \BCC\Trust\Onchain\Services\NftCapabilityEditor}. This method
     * stores the decision it is given and nothing more; a repository that
     * also arbitrated would be a second authority on the same rule.
     *
     * Granting it starts nothing. No cron reads this column — every
     * recurring discovery hook was retired and cannot re-arm — so it can
     * only ever be consulted by a human-initiated action that still has to
     * pass every other gate.
     */
    public static function setManualCollectionDiscoveryEnabled(
        int $chainId,
        bool $enabled
    ): RepositoryWriteResult {
        if ($chainId <= 0) {
            return RepositoryWriteResult::failure();
        }

        global $wpdb;
        $table = self::table();

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET manual_collection_discovery_enabled = %d
              WHERE id = %d
              LIMIT 1",
            $enabled ? 1 : 0,
            $chainId
        ));

        self::clearCache();

        return RepositoryWriteResult::fromWpdb($result);
    }

    /**
     * Clear the chains cache so new/updated chains appear immediately.
     */
    public static function clearCache(): void
    {
        self::$byIdMemo = [];
        wp_cache_delete('active_all', self::CACHE_GROUP);
        delete_transient('bcc_active_chains');
    }

    private static function ttl(): int
    {
        return (int) apply_filters('bcc_chains_cache_ttl', self::DEFAULT_TTL);
    }

    /**
     * Jittered TTL for the error sentinel. Uniform [BASE, BASE+JITTER] seconds.
     *
     * Without jitter, N nodes that all observe the DB failure at T+0 will all
     * expire their sentinels at T+5 and retry simultaneously — a synchronized
     * thundering herd against a recovering DB. Per-request jitter decorrelates
     * those retries across the cluster.
     */
    private static function errorSentinelTtl(): int
    {
        try {
            $jitter = random_int(0, self::ERROR_SENTINEL_TTL_JITTER);
        } catch (\Exception $e) {
            // random_int() can throw on exhausted entropy — extremely rare, but
            // the contract is documented. Fall back to the base TTL rather than
            // letting a crypto exception propagate out of a cache helper.
            $jitter = 0;
        }
        return self::ERROR_SENTINEL_TTL_BASE + $jitter;
    }
}
