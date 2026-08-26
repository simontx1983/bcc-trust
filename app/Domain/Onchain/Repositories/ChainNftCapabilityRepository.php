<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use BCC\Trust\Onchain\ValueObjects\ChainNftCapabilityOverrides;

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
 * ── "NO OVERRIDES" AND "COULD NOT READ THE OVERRIDES" ARE DIFFERENT ─────
 * This repository returns {@see ChainNftCapabilityOverrides}, not a list,
 * because an empty list is ambiguous in a way that fails OPEN:
 *
 *   read succeeded, zero rows  -> registry defaults apply
 *   read failed / unavailable  -> we know NOTHING about operator intent
 *
 * Since an absent row MEANS "registry default", answering the second case
 * with an empty list would silently restore every driver an operator had
 * disabled — precisely when the database is least healthy. So a failure is
 * its own value, and the capability verdict fails closed on it.
 *
 * ── THE BOUND IS ENFORCED, NOT ASSUMED ──────────────────────────────────
 * The query is bounded (§4). A bounded read that comes back FULL may be a
 * SUBSET of what the operator configured — and applying a subset would
 * honour some restrictions while silently dropping others, which is the
 * same fail-open shape by a different route. So the query asks for one row
 * more than the ceiling, and the presence of that extra row makes the whole
 * set unavailable rather than partially applied.
 *
 * ── NOTHING WRITES TO THIS TABLE YET ────────────────────────────────────
 * PR 2 is a scaffold. The capability editor that populates it lands with the
 * admin surface. The read path and the generation-counter invalidation are
 * built now so the editor cannot invent its own.
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
     *  200 is far above any legitimate value; exceeding it means the table
     *  is corrupt or hostile, not that a chain is unusually configured.
     */
    private const MAX_ROWS_PER_CHAIN = 200;

    public static function table(): string
    {
        return DB::table('chain_nft_capabilities');
    }

    /**
     * Override rows for one chain.
     *
     * Bounded by `WHERE chain_id = %d` plus an explicit `LIMIT`, and ordered
     * deterministically so a caller rendering the set sees a stable list.
     *
     * Returns an UNAVAILABLE result — never an empty list — when the chain
     * id is unusable, the read fails, the table is absent, a row is
     * structurally broken, or the bound was hit. Callers must branch on
     * {@see ChainNftCapabilityOverrides::isAvailable()}.
     */
    public static function getForChain(int $chainId): ChainNftCapabilityOverrides
    {
        if ($chainId <= 0) {
            return self::unavailable($chainId, ChainNftCapabilityOverrides::REASON_INVALID_CHAIN);
        }

        global $wpdb;
        $table = self::table();

        // Ask for ONE MORE than the ceiling. If that extra row comes back,
        // the operator's configuration is larger than we are willing to read
        // and anything we did read is a subset — see the class docblock.
        $prepared = $wpdb->prepare(
            "SELECT " . self::COLUMNS . "
               FROM {$table}
              WHERE chain_id = %d
              ORDER BY priority ASC, id ASC
              LIMIT %d",
            $chainId,
            self::MAX_ROWS_PER_CHAIN + 1
        );

        // wpdb::prepare() returns an empty string when the placeholder count
        // does not match the arguments. Handing that to get_results() would
        // query nothing and read as "no overrides".
        if (!is_string($prepared) || $prepared === '') {
            return self::unavailable($chainId, ChainNftCapabilityOverrides::REASON_READ_FAILED);
        }

        $suppressed = $wpdb->suppress_errors(true);
        /** @var list<object>|null $rows */
        $rows = $wpdb->get_results($prepared);
        $error = (string) $wpdb->last_error;
        $wpdb->suppress_errors($suppressed);

        // A successful SELECT always yields an array — `null` means the query
        // failed (missing table, permissions, dropped connection). `$rows`
        // being `[]` with an error set is the same story.
        if (!is_array($rows) || $error !== '') {
            return self::unavailable($chainId, ChainNftCapabilityOverrides::REASON_READ_FAILED);
        }

        if (count($rows) > self::MAX_ROWS_PER_CHAIN) {
            return self::unavailable($chainId, ChainNftCapabilityOverrides::REASON_OVERFLOW);
        }

        $out = [];
        foreach ($rows as $row) {
            $operation = isset($row->operation) ? (string) $row->operation : '';
            $driverKey = isset($row->driver_key) ? (string) $row->driver_key : '';

            // Structurally unusable. NOT the same as "names a driver this
            // build does not implement" — that is a normal, expected row
            // (an older or newer build wrote it) and the registry
            // intersection discards it harmlessly. An EMPTY key or operation
            // means the row itself is broken, so the set is untrustworthy.
            if ($operation === '' || $driverKey === '') {
                return self::unavailable($chainId, ChainNftCapabilityOverrides::REASON_MALFORMED);
            }

            $out[] = [
                'operation'  => $operation,
                'driver_key' => $driverKey,
                'enabled'    => (int) ($row->enabled ?? 0) === 1,
                'priority'   => (int) ($row->priority ?? 0),
            ];
        }

        return ChainNftCapabilityOverrides::loaded($out);
    }

    /**
     * Record an unavailable read and return the value.
     *
     * Logs the CHAIN and a coarse REASON only — never the SQL, the table
     * name, `$wpdb->last_error`, or anything else that could carry schema or
     * connection detail into a log an operator pastes into a ticket.
     */
    private static function unavailable(int $chainId, string $reason): ChainNftCapabilityOverrides
    {
        \BCC\Core\Log\Logger::error(
            '[ChainNftCapabilityRepository] NFT driver overrides unavailable; capability reads fail closed',
            ['chain_id' => $chainId, 'reason' => $reason]
        );

        return ChainNftCapabilityOverrides::unavailable($reason);
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
