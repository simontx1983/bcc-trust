<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;
use BCC\Trust\Onchain\Support\NftDriverRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-chain NFT driver OVERRIDES — `wp_bcc_chain_nft_capabilities`.
 *
 * ── WHAT THIS TABLE IS FOR, AND WHAT IT CAN NEVER DO ────────────────────
 * Rows exist ONLY to disable or reorder what {@see NftDriverRegistry}
 * already offers. An absent row means "registry default", which is why the
 * table is empty on every install and everything still works.
 *
 * A row can NEVER enable a driver the registry does not offer. That is not
 * enforced here by validating writes — it is enforced structurally in
 * {@see NftDriverRegistry::driversFor()}, which INTERSECTS these rows with
 * the code registry and discards anything unmatched. Enforcing it at the
 * read that matters means a row written by a future admin screen, a manual
 * `INSERT`, a botched migration or a restored backup from a build with
 * different drivers is inert rather than dangerous.
 *
 * This is the same narrow-only property `BCC_COSMWASM_CHAIN_ALLOWLIST` has,
 * and it exists for the same fail-closed reason.
 *
 * ── NOTHING WRITES TO THIS TABLE YET ────────────────────────────────────
 * PR 2 is a scaffold. The capability editor that populates it lands with the
 * admin surface. The read path and the generation-counter invalidation are
 * built now so the editor cannot invent its own.
 *
 * @see NftDriverRegistry            the code-owned registry these rows narrow
 * @see \BCC\Trust\Onchain\Support\NftChainCapability the verdict that consumes them
 */
final class ChainNftCapabilityRepository
{
    /**
     * @var string Explicit column list (§2 — no `SELECT *`). Must match
     *  includes/database/schema-chain-nft-capabilities.php.
     */
    private const COLUMNS = 'id, chain_id, operation, driver_key, enabled, priority, updated_at';

    /** @var string Cache group for the §5 generation counter. */
    private const CACHE_GROUP = 'bcc_chain_nft_capabilities';

    /** @var string Generation-counter key prefix (per chain). */
    private const GENERATION_KEY_PREFIX = 'gen_chain_nft_caps_';

    /**
     * @var int Hard row ceiling for one chain's overrides (§4 — bounded
     *  query). Nine drivers times six operations is 54 possible triples, so
     *  200 is far above any legitimate value and exists purely so a
     *  corrupted table cannot stream unbounded rows into a request.
     */
    private const MAX_ROWS_PER_CHAIN = 200;

    public static function table(): string
    {
        return DB::table('chain_nft_capabilities');
    }

    /**
     * Override rows for one chain, in the shape
     * {@see NftDriverRegistry::driversFor()} expects.
     *
     * Bounded by `WHERE chain_id = %d` plus an explicit `LIMIT`. Returns
     * `[]` for a missing table, a read failure, or a non-positive chain id —
     * and `[]` is the SAFE answer here, because "no overrides" means
     * "registry defaults apply", never "everything is permitted".
     *
     * @return list<array{operation: string, driver_key: string, enabled: bool, priority: int}>
     */
    public static function getForChain(int $chainId): array
    {
        if ($chainId <= 0) {
            return [];
        }

        global $wpdb;
        $table = self::table();

        /** @var list<object{operation: string, driver_key: string, enabled: string, priority: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT " . self::COLUMNS . "
               FROM {$table}
              WHERE chain_id = %d
              ORDER BY priority ASC, id ASC
              LIMIT %d",
            $chainId,
            self::MAX_ROWS_PER_CHAIN
        ));

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'operation'  => (string) ($row->operation ?? ''),
                'driver_key' => (string) ($row->driver_key ?? ''),
                'enabled'    => (int) ($row->enabled ?? 0) === 1,
                'priority'   => (int) ($row->priority ?? 0),
            ];
        }

        return $out;
    }

    // ── Cache invalidation (§5 generation counter) ───────────────────────

    /**
     * Bump the per-chain overrides generation counter.
     *
     * Called from any future write so a read-side cache keyed on the
     * generation sees the mutation on the next request. The defensive
     * `wp_cache_add` seeds the value because some object-cache drop-ins do
     * not auto-seed on `wp_cache_incr` — mirrors
     * {@see NftSelectionRepository::bumpUserGeneration()}.
     */
    public static function bumpChainGeneration(int $chainId): void
    {
        if ($chainId <= 0) {
            return;
        }
        $key = self::GENERATION_KEY_PREFIX . $chainId;
        if (wp_cache_get($key, self::CACHE_GROUP) === false) {
            wp_cache_add($key, 1, self::CACHE_GROUP);
            return;
        }
        wp_cache_incr($key, 1, self::CACHE_GROUP);
    }

    /**
     * Current per-chain overrides generation. `0` when the counter has not
     * been seeded (no writes since the last cache reset).
     */
    public static function getChainGeneration(int $chainId): int
    {
        if ($chainId <= 0) {
            return 0;
        }
        $value = wp_cache_get(self::GENERATION_KEY_PREFIX . $chainId, self::CACHE_GROUP);

        return is_numeric($value) ? (int) $value : 0;
    }
}
