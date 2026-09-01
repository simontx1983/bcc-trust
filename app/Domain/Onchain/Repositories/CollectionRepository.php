<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Onchain\Services\CollectionStateClassifier;
use BCC\Trust\Onchain\Support\NftCollectionIdentifier;
use BCC\Trust\Onchain\Support\NftCollectionIdentity;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type CollectionRow object{
 *     id: string,
 *     wallet_link_id: string|null,
 *     contract_address: string,
 *     canonical_identifier: string|null,
 *     chain_id: string,
 *     collection_name: string|null,
 *     token_standard: string|null,
 *     total_supply: string|null,
 *     floor_price: string|null,
 *     floor_currency: string|null,
 *     unique_holders: string|null,
 *     total_volume: string|null,
 *     listed_percentage: string|null,
 *     royalty_percentage: string|null,
 *     metadata_storage: string|null,
 *     image_url: string|null,
 *     show_on_profile: string,
 *     is_verified: string,
 *     fetched_at: string,
 *     expires_at: string
 * }
 *
 * @phpstan-type CollectionWithChain object{
 *     id: string,
 *     wallet_link_id: string|null,
 *     contract_address: string,
 *     canonical_identifier: string|null,
 *     chain_id: string,
 *     collection_name: string|null,
 *     token_standard: string|null,
 *     total_supply: string|null,
 *     floor_price: string|null,
 *     floor_currency: string|null,
 *     unique_holders: string|null,
 *     total_volume: string|null,
 *     listed_percentage: string|null,
 *     royalty_percentage: string|null,
 *     metadata_storage: string|null,
 *     image_url: string|null,
 *     show_on_profile: string,
 *     fetched_at: string,
 *     expires_at: string,
 *     chain_slug: string,
 *     chain_name: string,
 *     explorer_url: string|null,
 *     native_token: string|null
 * }
 *
 * @phpstan-type CollectionIdWithChain object{
 *     id: string,
 *     wallet_link_id: string|null,
 *     contract_address: string,
 *     canonical_identifier: string|null,
 *     chain_id: string,
 *     collection_name: string|null,
 *     token_standard: string|null,
 *     total_supply: string|null,
 *     floor_price: string|null,
 *     unique_holders: string|null,
 *     total_volume: string|null,
 *     is_verified: string,
 *     provisioning_state: string,
 *     provisioning_requested_at: string|null,
 *     provisioning_requested_by: string|null,
 *     provisioning_failure_code: string|null,
 *     chain_slug: string,
 *     chain_type: string
 * }
 *
 * @phpstan-type CollectionProvisioningRow object{
 *     id: string,
 *     is_verified: string,
 *     canonical_identifier: string|null,
 *     collection_name: string|null,
 *     chain_id: string,
 *     provisioning_state: string,
 *     provisioning_requested_at: string|null,
 *     provisioning_requested_by: string|null,
 *     provisioning_failure_code: string|null
 * }
 *
 * @phpstan-type CollectionRequestedRow object{
 *     id: string,
 *     chain_id: string,
 *     contract_address: string,
 *     canonical_identifier: string|null,
 *     collection_name: string|null,
 *     is_verified: string,
 *     provisioning_state: string,
 *     provisioning_requested_at: string|null,
 *     provisioning_requested_by: string|null,
 *     chain_slug: string,
 *     chain_type: string
 * }
 *
 * @phpstan-type CollectionAdminStateRow object{
 *     id: string,
 *     contract_address: string,
 *     canonical_identifier: string|null,
 *     collection_name: string|null,
 *     token_standard: string|null,
 *     total_supply: string|null,
 *     unique_holders: string|null,
 *     image_url: string|null,
 *     is_verified: string,
 *     source: string,
 *     provisioning_state: string,
 *     provisioning_requested_at: string|null,
 *     provisioning_requested_by: string|null,
 *     provisioning_failure_code: string|null,
 *     has_community: string,
 *     is_hidden: string,
 *     chain_id: string,
 *     chain_slug: string,
 *     chain_type: string,
 *     explorer_url: string|null
 * }
 *
 * @phpstan-type CollectionCountByChain object{
 *     chain_id: string,
 *     cnt: string,
 *     last_fetched: string|null
 * }
 *
 * Display shape consumed by CollectionService::enrichWithBadges() and mergeWithManual().
 * Superset of CollectionWithChain plus UI decoration props populated post-fetch.
 * Decoration props are optional because repository rows start without them.
 *
 * @phpstan-type CollectionDisplay object{
 *     id: string|null,
 *     wallet_link_id?: string|null,
 *     contract_address: string,
 *     chain_id?: string,
 *     collection_name: string|null,
 *     token_standard: string|null,
 *     total_supply: string|int|null,
 *     floor_price: string|float|null,
 *     floor_currency: string|null,
 *     unique_holders: string|int|null,
 *     total_volume: string|float|null,
 *     listed_percentage: string|float|null,
 *     royalty_percentage: string|float|null,
 *     metadata_storage: string|null,
 *     image_url?: string|null,
 *     show_on_profile: int|string,
 *     fetched_at: string|null,
 *     expires_at?: string,
 *     chain_slug: string,
 *     chain_name: string,
 *     explorer_url: string|null,
 *     native_token: string|null,
 *     is_creator?: bool,
 *     viewer_holds?: bool,
 *     data_source?: string,
 *     can_toggle?: bool
 * }
 */
final class CollectionRepository
{
    /** @var string Explicit column list — must match schema-collections.php. */
    private const COLUMNS = 'id, wallet_link_id, contract_address, canonical_identifier, chain_id, collection_name,
                 token_standard, total_supply, floor_price, floor_currency, unique_holders,
                 total_volume, listed_percentage, royalty_percentage, metadata_storage,
                 image_url, show_on_profile, is_verified, fetched_at, expires_at';

    public static function table(): string
    {
        return DB::table('onchain_collections');
    }

    // ── Canonical identity (PR 5a) ──────────────────────────────────────

    /**
     * Resolve a collection identifier to its canonical database identity.
     *
     * The chain family is READ FROM THE REGISTRY, never inferred from how
     * the identifier looks — that is the whole point of routing every
     * writer through one service. An unresolvable chain yields an empty
     * family, which {@see NftCollectionIdentifier} refuses: fail closed.
     */
    private static function canonicalIdentityFor(int $chainId, string $contract): NftCollectionIdentity
    {
        $chain  = ChainRepository::getById($chainId);
        $family = is_object($chain) ? (string) ($chain->chain_type ?? '') : '';

        return NftCollectionIdentifier::canonicalize($family, $contract);
    }

    /**
     * Record a refused identity as a bounded, non-secret count.
     *
     * Logs the REASON and the chain, never the rejected value: a refused
     * identifier is attacker- or third-party-supplied text, and the reason
     * codes are a closed set that is enough to diagnose a bad feed.
     */
    private static function logRefusedIdentity(string $writer, int $chainId, NftCollectionIdentity $identity): void
    {
        \BCC\Core\Log\Logger::warning(
            '[CollectionRepository] refused a non-canonical collection identity; no row was written',
            ['writer' => $writer, 'chain_id' => $chainId, 'reason' => $identity->reason()]
        );
    }

    // ── NULL-safe SQL value fragments ───────────────────────────────────
    //
    // `wpdb::prepare()` cannot express SQL NULL: '%d'/'%f' turn null into 0
    // and '%s' turns it into ''. Every writer on this table therefore has to
    // build its own literal fragment, or it silently records a fake value
    // where it meant "unknown".
    //
    // That matters twice over, because the conflict clauses preserve an
    // existing value with COALESCE(VALUES(col), col) — which only fires on a
    // real SQL NULL. A '' arriving from prepare() is a value, so it
    // OVERWRITES. That was #212 in upsert() and #220 in bulkUpsert() and
    // addManual(); the rule now lives in one place so the third copy cannot
    // drift back.

    /**
     * A string column's value, or NULL when nothing was reported.
     *
     * NULL, '' and whitespace-only all mean "not provided". An automated
     * refresh that carries a blank is saying "I don't know", never "clear
     * it" — and no interface in this codebase uses a blank to request
     * deletion: the admin Add Collection handler already maps '' to null
     * before it reaches a writer. Deliberate clearing, if it is ever wanted,
     * needs its own explicit action rather than an absent field.
     *
     * Deliberately NOT empty(): that would also discard the legitimate
     * string "0". Numeric columns use the helpers below, so a genuine zero
     * supply, floor or holder count still writes 0.
     *
     * @param  scalar|null $value
     */
    private static function sqlStringOrNull($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        $string = (string) $value;

        global $wpdb;

        return trim($string) === '' ? 'NULL' : $wpdb->prepare('%s', $string);
    }

    /**
     * An integer column's value, or NULL when absent. A real 0 writes 0.
     *
     * @param  scalar|null $value
     */
    private static function sqlIntOrNull($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        global $wpdb;

        return $wpdb->prepare('%d', (int) $value);
    }

    /**
     * A float column's value, or NULL when absent. A real 0.0 writes 0.
     *
     * @param  scalar|null $value
     */
    private static function sqlFloatOrNull($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        global $wpdb;

        return $wpdb->prepare('%f', (float) $value);
    }

    /**
     * Insert or refresh the canonical collection row for
     * (chain_id, contract_address) — the key this table actually enforces.
     *
     * ── WHAT WAS WRONG (#212) ───────────────────────────────────────────
     * This method used to LOOK UP on three columns (wallet_link_id,
     * chain_id, contract_address) and then run a bare INSERT when the
     * lookup missed. The table's only unique key is
     * `uq_chain_contract (chain_id, contract_address)` — no wallet. So for
     * a collection already recorded against a DIFFERENT wallet the lookup
     * always missed, MySQL rejected the INSERT, `insert_id` was 0, and the
     * write was discarded. Silently: all three callers ignored the return
     * value, `$wpdb->insert()` does not throw, and no backoff or breaker
     * saw it, so the same rejection repeated every refresh cycle.
     *
     * The rejected INSERT never deleted or overwrote the existing row —
     * that collection was left untouched. What was lost is the metadata
     * refresh and the second wallet's association.
     *
     * ── WHAT THIS CHANGES ───────────────────────────────────────────────
     * One atomic INSERT … ON DUPLICATE KEY UPDATE against the real key,
     * matching the three sibling writers ({@see bulkUpsert},
     * {@see addManual}, {@see ensureExistsBatch}). The metadata refresh now
     * always lands, and the check-then-insert race — which existed even
     * when the keys DID agree — is gone along with the read.
     *
     * ── WHAT THIS DELIBERATELY DOES NOT DO ──────────────────────────────
     * It does NOT record the second wallet's ownership. One row cannot
     * carry two owners, so `wallet_link_id` stays first-writer-wins and is
     * never rewritten on the update path. Many-to-many ownership needs a
     * relationship table and is tracked separately — this method is
     * containment, not the ownership model.
     *
     * Also protected on the update path: `is_verified` and
     * `show_on_profile` (operator decisions) and `source` (so a curated
     * 'manual' row is never demoted by an automated wallet refresh).
     *
     * Metadata is COALESCE'd, and for the nullable string columns NULL,
     * '' and whitespace-only all count as "not reported" — an automated
     * refresh saying nothing about a field must not be read as an
     * instruction to clear it. So a sparse fetch may FILL a blank column
     * but may never BLANK a populated one, and one wallet's thin response
     * cannot erase another wallet's richer data. Numeric columns keep
     * their own NULL-safe helpers, so a genuine zero supply, floor or
     * holder count still writes 0.
     *
     * @param array<string, mixed> $data  Normalized collection data from a fetcher.
     * @param int   $walletLinkId  Wallet link that observed it. Recorded only on INSERT.
     * @param int   $ttlSeconds    Cache TTL before the row is considered expired.
     * @return array{status: 'created'|'updated'|'failed', id: int}
     */
    public static function upsert(array $data, int $walletLinkId, int $ttlSeconds = 4 * HOUR_IN_SECONDS): array
    {
        $chainId  = (int) ($data['chain_id'] ?? 0);
        $contract = isset($data['contract_address']) ? (string) $data['contract_address'] : '';

        if ($chainId <= 0 || $contract === '') {
            return ['status' => 'failed', 'id' => 0];
        }

        // PR 5a: a new collection identity must canonicalise, or there is no
        // write at all. A refusal is not a soft failure to be logged and
        // stepped over — it means the value is not a contract or mint (a
        // Magic Eden symbol, a malformed address, an unsupported chain
        // family), and admitting it would manufacture another row that no
        // canonical lookup can ever reach.
        $identity = self::canonicalIdentityFor($chainId, $contract);
        if (!$identity->isAccepted()) {
            self::logRefusedIdentity('upsert', $chainId, $identity);
            return ['status' => 'failed', 'id' => 0];
        }

        global $wpdb;
        $table     = self::table();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $now       = current_time('mysql', true);

        $sqlWallet  = $walletLinkId > 0 ? $wpdb->prepare('%d', $walletLinkId) : 'NULL';
        $sqlSupply  = self::sqlIntOrNull($data['total_supply'] ?? null);
        $sqlHolders = self::sqlIntOrNull($data['unique_holders'] ?? null);
        $sqlFloor   = self::sqlFloatOrNull($data['floor_price'] ?? null);
        $sqlVolume  = self::sqlFloatOrNull($data['total_volume'] ?? null);
        $sqlListed  = self::sqlFloatOrNull($data['listed_percentage'] ?? null);
        $sqlRoyalty = self::sqlFloatOrNull($data['royalty_percentage'] ?? null);

        // `esc_url_raw` because the value is ultimately rendered as an
        // `<img src>` on the Verify Collections page.
        $sqlImage = self::sqlStringOrNull(
            isset($data['image_url']) && is_string($data['image_url'])
                ? esc_url_raw($data['image_url'])
                : null
        );
        $sqlName     = self::sqlStringOrNull(isset($data['collection_name']) ? sanitize_text_field((string) $data['collection_name']) : null);
        $sqlStandard = self::sqlStringOrNull(isset($data['token_standard']) ? sanitize_text_field((string) $data['token_standard']) : null);
        $sqlCurrency = self::sqlStringOrNull(isset($data['floor_currency']) ? sanitize_text_field((string) $data['floor_currency']) : null);
        $sqlMetaStor = self::sqlStringOrNull(isset($data['metadata_storage']) ? sanitize_text_field((string) $data['metadata_storage']) : null);

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (wallet_link_id, contract_address, canonical_identifier, chain_id, collection_name, token_standard,
                 total_supply, floor_price, floor_currency, unique_holders, total_volume,
                 listed_percentage, royalty_percentage, metadata_storage, image_url,
                 source, fetched_at, expires_at)
             VALUES ({$sqlWallet}, %s, %s, %d, {$sqlName}, {$sqlStandard}, {$sqlSupply}, {$sqlFloor},
                     {$sqlCurrency}, {$sqlHolders}, {$sqlVolume}, {$sqlListed}, {$sqlRoyalty},
                     {$sqlMetaStor}, {$sqlImage}, 'discovery', %s, %s)
             ON DUPLICATE KEY UPDATE
                -- canonical_identifier is INSERT-only and deliberately not
                -- listed below. `uq_chain_contract` is case-INSENSITIVE, so
                -- a write for `mint1` collides with an existing row storing
                -- `Mint1`; updating the column here would silently re-point
                -- that collection's identity at a different mint. An
                -- existing row's identity is immutable.
                collection_name    = COALESCE(VALUES(collection_name), collection_name),
                token_standard     = COALESCE(VALUES(token_standard), token_standard),
                total_supply       = COALESCE(VALUES(total_supply), total_supply),
                floor_price        = COALESCE(VALUES(floor_price), floor_price),
                floor_currency     = COALESCE(VALUES(floor_currency), floor_currency),
                unique_holders     = COALESCE(VALUES(unique_holders), unique_holders),
                total_volume       = COALESCE(VALUES(total_volume), total_volume),
                listed_percentage  = COALESCE(VALUES(listed_percentage), listed_percentage),
                royalty_percentage = COALESCE(VALUES(royalty_percentage), royalty_percentage),
                metadata_storage   = COALESCE(VALUES(metadata_storage), metadata_storage),
                image_url          = COALESCE(VALUES(image_url), image_url),
                fetched_at         = VALUES(fetched_at),
                expires_at         = VALUES(expires_at)",
            $contract,
            $identity->canonical(),
            $chainId,
            $now,
            $expiresAt
        ));

        if ($result === false) {
            return ['status' => 'failed', 'id' => 0];
        }

        // MySQL's ON DUPLICATE KEY UPDATE affected-rows contract: 1 = a row was
        // inserted, 2 = an existing row was updated, 0 = it matched but nothing
        // changed. Used for the LABEL ONLY. A zero here is a SUCCESSFUL no-op
        // replay, not a failure — wpdb::query() returns int 0, and the guard
        // above is a strict `=== false`, so it cannot be confused for one.
        $affected = (int) $result;

        // The id is ALWAYS resolved from the canonical key, never from
        // insert_id. insert_id is connection-sticky, and under a client
        // configured with CLIENT_FOUND_ROWS an unchanged update also reports
        // affected=1 — so trusting it there could hand back a different row
        // entirely. One extra lookup on a unique index is the cheaper bargain.
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE chain_id = %d AND contract_address = %s
             LIMIT 1",
            $chainId,
            $contract
        ));

        if ($id <= 0) {
            return ['status' => 'failed', 'id' => 0];
        }

        return ['status' => $affected === 1 ? 'created' : 'updated', 'id' => $id];
    }

    /**
     * Bulk-upsert collections for a chain (no wallet_link_id required).
     * Used by the chain-level indexing cron. Matches on (chain_id, contract_address).
     *
     * @param array<int, array<string, mixed>> $collections Normalized collection rows from fetch_top_collections().
     * @param int     $ttlSeconds  TTL for expires_at.
     * @return int Number of rows written.
     */
    public static function bulkUpsert(array $collections, int $ttlSeconds = 4 * HOUR_IN_SECONDS): int
    {
        if (empty($collections)) {
            return 0;
        }

        global $wpdb;
        $table     = self::table();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $now       = current_time('mysql', true);
        $count     = 0;

        // Transaction wrapper: without this, a PHP timeout or fatal
        // mid-loop left the top-collections list partially updated
        // (first N rows current-cycle, remaining N from hours earlier).
        // Mirrors ValidatorRepository::bulkUpsert's atomicity guarantee.
        try {

        // Fail closed if the transaction never opened (DB failover): a no-op
        // START leaves the batch in autocommit, defeating the mid-loop
        // rollback this wrapper provides.
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('START TRANSACTION failed');
        }

        foreach ($collections as $data) {
            // PR 5a: canonicalise before anything else. A refused row is
            // SKIPPED, not aborted — a curated feed carrying one bad entry
            // must not discard the good ones, and it must not roll back the
            // batch. The skip is counted, and `$count` (the return value)
            // only ever counts rows actually written.
            $rowChainId  = (int) ($data['chain_id'] ?? 0);
            $rowContract = isset($data['contract_address']) ? (string) $data['contract_address'] : '';
            $identity    = $rowChainId > 0
                ? self::canonicalIdentityFor($rowChainId, $rowContract)
                : NftCollectionIdentity::refuse(NftCollectionIdentity::REASON_UNSUPPORTED_FAMILY);

            if (!$identity->isAccepted()) {
                self::logRefusedIdentity('bulkUpsert', $rowChainId, $identity);
                continue;
            }

            // Every nullable column routes through the shared helpers, so an
            // absent value reaches MySQL as a real NULL rather than 0 or ''
            // — which is what lets the COALESCE below preserve instead of
            // overwrite (#220). The string columns were the gap: they used to
            // ride wpdb::prepare('%s', null) straight into ''.
            $sqlSupply   = self::sqlIntOrNull($data['total_supply'] ?? null);
            $sqlHolders  = self::sqlIntOrNull($data['unique_holders'] ?? null);
            $sqlFloor    = self::sqlFloatOrNull($data['floor_price'] ?? null);
            $sqlVolume   = self::sqlFloatOrNull($data['total_volume'] ?? null);
            $sqlListed   = self::sqlFloatOrNull($data['listed_percentage'] ?? null);
            $sqlRoyalty  = self::sqlFloatOrNull($data['royalty_percentage'] ?? null);
            $sqlName     = self::sqlStringOrNull($data['collection_name'] ?? null);
            $sqlStandard = self::sqlStringOrNull($data['token_standard'] ?? null);
            $sqlImage    = self::sqlStringOrNull($data['image_url'] ?? null);
            // floor_currency and metadata_storage are INSERT-only here — they
            // are deliberately absent from the conflict clause below, so this
            // only stops a blank being recorded on first write. Widening their
            // update behaviour is out of scope for #220.
            $sqlCurrency = self::sqlStringOrNull($data['floor_currency'] ?? null);
            $sqlMetaStor = self::sqlStringOrNull($data['metadata_storage'] ?? null);

            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table}
                    (wallet_link_id, contract_address, canonical_identifier, chain_id, collection_name, token_standard,
                     total_supply, floor_price, floor_currency, unique_holders, total_volume,
                     listed_percentage, royalty_percentage, metadata_storage, image_url,
                     source, fetched_at, expires_at)
                 VALUES (NULL, %s, %s, %d, {$sqlName}, {$sqlStandard}, {$sqlSupply}, {$sqlFloor}, {$sqlCurrency}, {$sqlHolders}, {$sqlVolume}, {$sqlListed}, {$sqlRoyalty}, {$sqlMetaStor}, {$sqlImage}, 'toplist', %s, %s)
                 ON DUPLICATE KEY UPDATE
                    -- canonical_identifier is INSERT-only; see upsert().
                    -- An existing row's identity is never re-pointed.
                    -- COALESCE, not a bare VALUES(): a sync that does not
                    -- report a field is saying it does not know, never that
                    -- the field should be cleared. So a later thin response
                    -- may FILL a blank column but can never BLANK a populated
                    -- one (#220). Applies to the numerics too — an absent
                    -- supply or floor must not null a figure we already have
                    -- — while a genuine 0 still writes, because 0 is not NULL.
                    collection_name    = COALESCE(VALUES(collection_name), collection_name),
                    token_standard     = COALESCE(VALUES(token_standard), token_standard),
                    total_supply       = COALESCE(VALUES(total_supply), total_supply),
                    floor_price        = COALESCE(VALUES(floor_price), floor_price),
                    unique_holders     = COALESCE(VALUES(unique_holders), unique_holders),
                    total_volume       = COALESCE(VALUES(total_volume), total_volume),
                    listed_percentage  = COALESCE(VALUES(listed_percentage), listed_percentage),
                    royalty_percentage = COALESCE(VALUES(royalty_percentage), royalty_percentage),
                    image_url          = COALESCE(VALUES(image_url), image_url),
                    -- Preserve operator curation: a 'manual' row that later
                    -- shows up in a top-collections sync keeps source='manual'.
                    -- ('toplist' was 'stargaze' before the 2026 Stargaze →
                    -- Cosmos Hub migration; retire-stargaze-chain.php renames
                    -- the legacy rows.)
                    source             = IF(source = 'manual', 'manual', VALUES(source)),
                    fetched_at         = VALUES(fetched_at),
                    expires_at         = VALUES(expires_at)",
                $rowContract,
                $identity->canonical(),
                $rowChainId,
                $now,
                $expiresAt
            ));

            if ($result !== false) {
                $count++;
            }
        }

        $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        return $count;
    }

    /**
     * Insert (or update) a single operator-curated collection by
     * (chain_id, contract_address). Powers the admin "Add Collection"
     * form on the Verify Collections page — the only path that creates
     * a collection row for a chain with no auto-discovery (e.g. a
     * curated Cosmos Hub CW-721 contract).
     *
     * Mirrors bulkUpsert()'s row shape and NULL-preservation, scoped to
     * one row. wallet_link_id stays NULL (chain-level, not wallet-owned);
     * is_verified stays 0 so the operator must still flip it via the
     * existing verification checkbox before any holdings are queried.
     *
     * @param array{
     *     chain_id: int,
     *     contract_address: string,
     *     collection_name?: ?string,
     *     token_standard?: ?string,
     *     total_supply?: ?int,
     *     image_url?: ?string,
     *     show_on_profile?: int
     * } $data
     * @param int $ttlSeconds  TTL for expires_at. Defaults long (30 days) so a
     *                         curated entry isn't garbage-collected like a
     *                         transient discovery row.
     * @return int|false  Row ID on success, false on failure.
     */
    public static function addManual(array $data, int $ttlSeconds = 30 * DAY_IN_SECONDS)
    {
        $chainId  = (int) ($data['chain_id'] ?? 0);
        $contract = isset($data['contract_address']) ? trim((string) $data['contract_address']) : '';

        if ($chainId <= 0 || $contract === '') {
            return false;
        }

        // PR 5a: the admin Add Collection form is the one writer where a
        // human types the identifier, and before this it validated nothing
        // outside Cosmos — a non-Cosmos chain got a "trusted as entered"
        // warning and the string went straight to the column. It now goes
        // through the same service as every automated writer.
        $identity = self::canonicalIdentityFor($chainId, $contract);
        if (!$identity->isAccepted()) {
            self::logRefusedIdentity('addManual', $chainId, $identity);
            return false;
        }

        global $wpdb;
        $table     = self::table();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $now       = current_time('mysql', true);

        // Build NULL-safe SQL fragments for the nullable numeric column
        // (wpdb::prepare with %d turns null into 0 — see bulkUpsert note).
        $sqlSupply   = self::sqlIntOrNull($data['total_supply'] ?? null);
        $sqlName     = self::sqlStringOrNull(isset($data['collection_name']) ? sanitize_text_field((string) $data['collection_name']) : null);
        $sqlStandard = self::sqlStringOrNull(isset($data['token_standard']) ? sanitize_text_field((string) $data['token_standard']) : null);
        $sqlImage    = self::sqlStringOrNull(
            isset($data['image_url']) && is_string($data['image_url'])
                ? esc_url_raw($data['image_url'])
                : null
        );

        // `show_on_profile` is a VISIBILITY decision — the member's own
        // showcase toggle ({@see setShowOnProfile}) and the operator's
        // hide/unhide action both write it. The admin Add Collection form
        // never submits it, so updating it unconditionally meant re-adding an
        // existing contract silently UNHID a row somebody had deliberately
        // hidden (#220). It is now only touched when the caller actually
        // provides it; a fresh INSERT still defaults to visible.
        $showProvided  = isset($data['show_on_profile']);
        $showOnProfile = $showProvided ? (int) (bool) $data['show_on_profile'] : 1;
        $showSetClause = $showProvided
            ? "\n                show_on_profile = VALUES(show_on_profile),"
            : '';

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (wallet_link_id, contract_address, canonical_identifier, chain_id, collection_name, token_standard,
                 total_supply, image_url, show_on_profile, is_verified, source, fetched_at, expires_at)
             VALUES (NULL, %s, %s, %d, {$sqlName}, {$sqlStandard}, {$sqlSupply}, {$sqlImage}, %d, 0, 'manual', %s, %s)
             ON DUPLICATE KEY UPDATE
                -- canonical_identifier is INSERT-only; see upsert().
                -- COALESCE for the same reason as bulkUpsert: an operator
                -- leaving a field blank on the Add Collection form has
                -- nothing to add, and is not asking for the stored value to
                -- be erased. The handler already maps blanks to null before
                -- they reach here.
                collection_name = COALESCE(VALUES(collection_name), collection_name),
                token_standard  = COALESCE(VALUES(token_standard), token_standard),
                total_supply    = COALESCE(VALUES(total_supply), total_supply),
                image_url       = COALESCE(VALUES(image_url), image_url),{$showSetClause}
                source          = VALUES(source),
                fetched_at      = VALUES(fetched_at),
                expires_at      = VALUES(expires_at)",
            $contract,
            $identity->canonical(),
            $chainId,
            $showOnProfile,
            $now,
            $expiresAt
        ));

        if ($result === false) {
            return false;
        }

        // Per-chain count display (admin Chains page) is a 1h TTL cache;
        // drop it so the new row shows up immediately rather than lagging.
        wp_cache_delete('collection_counts_by_chain', 'bcc_onchain');

        // insert_id is 0 on a pure ON DUPLICATE KEY UPDATE; resolve the
        // existing row id in that case so callers always get the row id.
        $insertId = (int) $wpdb->insert_id;
        if ($insertId > 0) {
            return $insertId;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE chain_id = %d AND contract_address = %s
             LIMIT 1",
            $chainId,
            $contract
        )) ?: false;
    }

    /**
     * Delete a single collection row by id. Powers the admin "Remove"
     * action on the Verify Collections page — primarily for cleaning up
     * a mistyped manual add. Bounded by the primary key.
     *
     * Returns the number of rows deleted (0 if the id didn't exist, 1 on
     * success). Group teardown is intentionally NOT handled here — a
     * verified collection's community is a separate concern; the caller
     * decides whether to block deletion of provisioned rows.
     */
    public static function deleteById(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        global $wpdb;
        $table = self::table();

        $deleted = (int) $wpdb->delete($table, ['id' => $id], ['%d']);

        if ($deleted > 0) {
            wp_cache_delete('collection_counts_by_chain', 'bcc_onchain');
        }

        return $deleted;
    }

    /**
     * Get top collections filtered by chain type (evm, solana, cosmos).
     * Each chain type is ranked independently — no cross-chain mixing.
     *
     * @param string $chainType One of: 'evm', 'solana', 'cosmos'.
     * @return array{items: list<CollectionWithChain>, total: int, pages: int}
     */
    public static function getTopCollectionsByChainType(
        string $chainType,
        int $page = 1,
        int $perPage = 20,
        string $orderBy = 'total_volume'
    ): array {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        $allowedOrder = ['total_volume', 'floor_price', 'unique_holders', 'total_supply'];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'total_volume';
        }

        $offset = ($page - 1) * $perPage;

        $countSql = $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table} c
             JOIN {$chains} ch ON ch.id = c.chain_id
             WHERE ch.chain_type = %s",
            $chainType
        );

        $mainSql = $wpdb->prepare(
            "SELECT c.id, c.wallet_link_id, c.contract_address, c.chain_id, c.collection_name,
                    c.token_standard, c.total_supply, c.floor_price, c.floor_currency,
                    c.unique_holders, c.total_volume, c.listed_percentage, c.royalty_percentage,
                    c.metadata_storage, c.image_url, c.show_on_profile, c.fetched_at, c.expires_at,
                    ch.slug AS chain_slug, ch.name AS chain_name, ch.explorer_url, ch.native_token
             FROM {$table} c
             JOIN {$chains} ch ON ch.id = c.chain_id
             WHERE ch.chain_type = %s
             ORDER BY c.{$orderBy} DESC
             LIMIT %d OFFSET %d",
            $chainType,
            $perPage,
            $offset
        );

        $total = (int) $wpdb->get_var($countSql);
        // LIMIT %d OFFSET %d is in the $mainSql prepared above.
        /** @var list<CollectionWithChain>|null $items */
        $items = $wpdb->get_results($mainSql);

        return [
            'items' => $items ?: [],
            'total' => $total,
            'pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /**
     * @param bool $includeHidden If true, returns all collections regardless of show_on_profile.
     *                            Used by the page owner's dashboard. Public views pass false.
     * @return array{items: list<CollectionWithChain>, total: int, pages: int}
     */
    public static function getForProject(int $postId, int $page = 1, int $perPage = 8, string $orderBy = 'total_volume', bool $includeHidden = false): array
    {
        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();
        $chains  = ChainRepository::table();

        $allowedOrder = ['total_volume', 'floor_price', 'unique_holders', 'total_supply', 'collection_name'];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'total_volume';
        }

        $offset = ($page - 1) * $perPage;

        $visibilityFilter = $includeHidden ? '' : ' AND c.show_on_profile = 1';

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} c JOIN {$wallets} w ON w.id = c.wallet_link_id WHERE w.post_id = %d{$visibilityFilter}",
            $postId
        ));

        /** @var list<CollectionWithChain>|null $items */
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.wallet_link_id, c.contract_address, c.chain_id, c.collection_name,
                    c.token_standard, c.total_supply, c.floor_price, c.floor_currency,
                    c.unique_holders, c.total_volume, c.listed_percentage, c.royalty_percentage,
                    c.metadata_storage, c.image_url, c.show_on_profile, c.fetched_at, c.expires_at,
                    ch.slug AS chain_slug, ch.name AS chain_name, ch.explorer_url, ch.native_token
             FROM {$table} c
             JOIN {$wallets} w ON w.id = c.wallet_link_id
             JOIN {$chains} ch ON ch.id = c.chain_id
             WHERE w.post_id = %d{$visibilityFilter}
             ORDER BY c.{$orderBy} DESC
             LIMIT %d OFFSET %d",
            $postId, $perPage, $offset
        ));

        return ['items' => $items ?: [], 'total' => $total, 'pages' => (int) ceil($total / $perPage)];
    }

    /**
     * Check whether a user holds NFTs from any of the given contract addresses.
     *
     * A "hold" means the user has a wallet_link whose address appears in the
     * onchain_collections table for that contract. This is an approximation:
     * the collection was fetched from that wallet, implying the wallet
     * interacted with (minted from) the contract.
     *
     * @param int      $userId           WordPress user ID.
     * @param string[] $contractAddresses Contract addresses to check.
     * @return array<string, bool> Keyed by lowercase contract address.
     */
    public static function getUserHoldings(int $userId, array $contractAddresses): array
    {
        if (empty($contractAddresses)) {
            return [];
        }

        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();

        $placeholders = implode(',', array_fill(0, count($contractAddresses), '%s'));
        $lowerAddrs   = array_map('strtolower', $contractAddresses);

        $args = array_merge([$userId], $lowerAddrs);

        $held = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT LOWER(c.contract_address)
             FROM {$table} c
             JOIN {$wallets} w ON w.id = c.wallet_link_id
             WHERE w.user_id = %d AND LOWER(c.contract_address) IN ({$placeholders})",
            ...$args
        ));

        $result = [];
        foreach ($lowerAddrs as $addr) {
            $result[$addr] = in_array($addr, $held, true);
        }

        return $result;
    }

    /**
     * Toggle show_on_profile for a collection row owned by a user.
     *
     * @param int  $collectionId  Collection row ID.
     * @param int  $userId        Must own the wallet_link.
     * @param bool $show          Whether to show on profile.
     * @return bool True if updated.
     */
    public static function setShowOnProfile(int $collectionId, int $userId, bool $show): bool
    {
        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();

        // Verify the user owns this collection row via wallet_link
        $owned = $wpdb->get_var($wpdb->prepare(
            "SELECT c.id
             FROM {$table} c
             JOIN {$wallets} w ON w.id = c.wallet_link_id
             WHERE c.id = %d AND w.user_id = %d
             LIMIT 1",
            $collectionId, $userId
        ));

        if (!$owned) {
            return false;
        }

        return (bool) $wpdb->update(
            $table,
            ['show_on_profile' => $show ? 1 : 0],
            ['id' => $collectionId],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Load a collection with chain metadata. Used by ClaimService.
     *
     * @return CollectionIdWithChain|null
     */
    public static function getByIdWithChain(int $collectionId): ?object
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        /** @var CollectionIdWithChain|null */
        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.id, c.wallet_link_id, c.contract_address, c.canonical_identifier, c.chain_id,
                    c.collection_name, c.token_standard, c.total_supply,
                    c.floor_price, c.unique_holders, c.total_volume, c.is_verified,
                    c.provisioning_state, c.provisioning_requested_at,
                    c.provisioning_requested_by, c.provisioning_failure_code,
                    ch.slug AS chain_slug, ch.chain_type
             FROM {$table} c
             INNER JOIN {$chains} ch ON ch.id = c.chain_id
             WHERE c.id = %d",
            $collectionId
        ));
    }

    /**
     * Resolve post_id for a collection via wallet_link. Used for cache invalidation.
     */
    public static function getPostIdForCollection(int $collectionId): int
    {
        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT w.post_id
             FROM {$table} c
             JOIN {$wallets} w ON w.id = c.wallet_link_id
             WHERE c.id = %d LIMIT 1",
            $collectionId
        ));
    }

    /**
     * Exponential backoff: push expires_at forward by 2x the original TTL,
     * capped at 7 days to prevent collections from disappearing from
     * refresh cycles indefinitely (uncapped would reach 170+ days after
     * 10 failures).
     */
    public static function backoffRow(int $rowId): bool
    {
        global $wpdb;
        $table   = self::table();
        $maxSecs = 7 * DAY_IN_SECONDS; // 604800 seconds = 7 days cap

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET expires_at = DATE_ADD(NOW(), INTERVAL LEAST(
                 TIMESTAMPDIFF(SECOND, fetched_at, expires_at) * 2,
                 %d
             ) SECOND)
             WHERE id = %d",
            $maxSecs,
            $rowId
        ));

        return $result !== false;
    }

    /**
     * Expired collections that belong to a LINKED WALLET.
     *
     * `wallet_link_id IS NOT NULL` is part of the query rather than a
     * filter in the caller, and that placement is the point.
     *
     * This used to be an unscoped `getExpired()`, and the wallet-less rows
     * it returned were rows nothing on the refresh path could act on — a
     * chain-level collection has no wallet address to re-fetch from. The
     * caller dealt with them by calling {@see backoffRow()} to push
     * `expires_at` forward so they would not be selected again before the
     * next `bcc_index_collections` sweep re-fetched them.
     *
     * That sweep is retired. Left unscoped, every chain-level expired row
     * would be re-selected on every cycle, consume the `BATCH_SIZE` budget
     * and be skipped — starving the wallet-linked rows the sweep exists to
     * refresh, while writing a rolling `expires_at` to say so. Excluding
     * them here is what makes the batch budget mean "wallets to refresh".
     *
     * @return list<CollectionRow>
     */
    public static function getExpiredWalletLinked(int $limit = 50): array
    {
        global $wpdb;
        $table = self::table();

        /** @var list<CollectionRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$table}
              WHERE expires_at < NOW()
                AND wallet_link_id IS NOT NULL
              ORDER BY expires_at ASC
              LIMIT %d",
            $limit
        ));

        return $rows ?: [];
    }

    /**
     * Check whether any collection rows exist for a given wallet_link.
     * Used by WalletSeedService to skip redundant API calls.
     */
    public static function existsForWalletLink(int $walletLinkId): bool
    {
        global $wpdb;
        $table = self::table();

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE wallet_link_id = %d LIMIT 1",
            $walletLinkId
        ));
    }

    /**
     * Listing for the admin "Verify Collections" page. Ordered by
     * unique_holders DESC so popular collections surface first for
     * verification decisions.
     *
     * `token_standard` is included so the admin UI can label rows by
     * their on-chain standard (ERC-721 / ERC-1155). The holder gate
     * supports both standards: ERC-721 via `EvmFetcher::count_holdings`
     * (eth_call) and ERC-1155 via `NftHoldingsRepository::countVisibleByContract`
     * (persistent transfer index).
     *
     * Optional `$chainSlug` filter scopes the listing to a single chain
     * (e.g. "ethereum", "polygon"). Unknown slugs return an empty result
     * rather than the unfiltered list — admins should never silently see
     * cross-chain rows when they asked for one chain.
     *
     * Optional `$verified` filter splits the listing by verification
     * state — `true` = verified only, `false` = unverified only, `null` =
     * both. Powers the Verified / Unverified sub-tabs on the admin page.
     *
     * `total_supply` and `explorer_url` ride along for the CosmWasm
     * scanner detail the admin page renders under each Cosmos row (token
     * count when the upstream reported one; the chain's explorer base).
     * Both come from rows the query already touches, so projecting them
     * here is what keeps the page from issuing a per-row second lookup.
     *
     * @return array{items: list<object{
     *     id: string,
     *     contract_address: string,
     *     collection_name: string|null,
     *     token_standard: string|null,
     *     total_supply: string|null,
     *     unique_holders: string|null,
     *     image_url: string|null,
     *     is_verified: string,
     *     source: string,
     *     chain_id: string,
     *     chain_slug: string,
     *     chain_type: string,
     *     explorer_url: string|null
     * }>, total: int, pages: int}
     */
    public static function listForAdminVerification(
        int $page = 1,
        int $perPage = 50,
        ?string $chainSlug = null,
        ?string $tokenStandard = null,
        ?bool $verified = null
    ): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $chainId = null;
        if ($chainSlug !== null && $chainSlug !== '') {
            $chain = ChainRepository::getBySlug($chainSlug);
            if ($chain === null) {
                return ['items' => [], 'total' => 0, 'pages' => 0];
            }
            $chainId = (int) $chain->id;
        }

        // Build WHERE fragments + matching params. Each fragment is a
        // hardcoded string with %d/%s placeholders only — user input
        // flows through $wpdb->prepare placeholders, never into the SQL
        // string itself.
        $conditions = [];
        $params     = [];

        if ($chainId !== null) {
            $conditions[] = 'c.chain_id = %d';
            $params[]     = $chainId;
        }

        if ($tokenStandard !== null && $tokenStandard !== '') {
            $conditions[] = 'c.token_standard = %s';
            $params[]     = $tokenStandard;
        }

        if ($verified !== null) {
            $conditions[] = 'c.is_verified = %d';
            $params[]     = $verified ? 1 : 0;
        }

        $whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        if ($params === []) {
            $total = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} c {$whereSql}"
            );
        } else {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} c {$whereSql}",
                ...$params
            ));
        }

        // `total_supply` and `explorer_url` are projected for the admin
        // candidate detail (token count when the upstream reported one, and
        // the per-chain explorer base). Both were already on the joined
        // rows; naming them here is what keeps the admin page from issuing
        // a second per-row lookup for either. §2: still an explicit column
        // list, never SELECT *.
        /** @var list<object{
         *     id: string,
         *     contract_address: string,
         *     collection_name: string|null,
         *     token_standard: string|null,
         *     total_supply: string|null,
         *     unique_holders: string|null,
         *     image_url: string|null,
         *     is_verified: string,
         *     source: string,
         *     chain_id: string,
         *     chain_slug: string,
         *     chain_type: string,
         *     explorer_url: string|null
         * }>|null $items */
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.contract_address, c.collection_name, c.token_standard,
                    c.total_supply, c.unique_holders, c.image_url, c.is_verified,
                    c.source, c.chain_id,
                    ch.slug AS chain_slug, ch.chain_type, ch.explorer_url
               FROM {$table} c
          LEFT JOIN {$chains} ch ON ch.id = c.chain_id
               {$whereSql}
               ORDER BY c.unique_holders DESC, c.id DESC
               LIMIT %d OFFSET %d",
            ...array_merge($params, [$perPage, $offset])
        ));

        return [
            'items' => $items ?: [],
            'total' => $total,
            'pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /**
     * DISTINCT non-empty `token_standard` values currently present in
     * the collections table. Used to populate the admin "Verify
     * Collections" page's token-standard filter dropdown so new
     * standards added by the indexer appear automatically.
     *
     * Bounded by an explicit LIMIT — there are only a handful of
     * standards in existence (ERC721, ERC1155, SPL, CW721, …), so 50
     * is well above any realistic cardinality but caps pathological
     * cases (corrupted data, fuzzing).
     *
     * @return list<string>
     */
    public static function getDistinctTokenStandards(): array
    {
        global $wpdb;
        $table = self::table();

        /** @var list<string>|null $rows */
        $rows = $wpdb->get_col(
            "SELECT DISTINCT token_standard
               FROM {$table}
              WHERE token_standard IS NOT NULL AND token_standard <> ''
              ORDER BY token_standard ASC
              LIMIT 50"
        );

        return $rows ?: [];
    }

    /**
     * Count collections split by verification state, honouring the same
     * optional chain / token-standard filters as the admin listing.
     * Powers the "Unverified (N)" / "Verified (N)" sub-tab labels so the
     * counts stay accurate under an active filter.
     *
     * Single bounded aggregate (GROUP BY on a 0/1 column → at most two
     * rows), no LIMIT needed.
     *
     * @return array{verified: int, unverified: int}
     */
    public static function countByVerification(
        ?string $chainSlug = null,
        ?string $tokenStandard = null
    ): array {
        global $wpdb;
        $table = self::table();

        $conditions = [];
        $params     = [];

        if ($chainSlug !== null && $chainSlug !== '') {
            $chain = ChainRepository::getBySlug($chainSlug);
            if ($chain === null) {
                return ['verified' => 0, 'unverified' => 0];
            }
            $conditions[] = 'chain_id = %d';
            $params[]     = (int) $chain->id;
        }

        if ($tokenStandard !== null && $tokenStandard !== '') {
            $conditions[] = 'token_standard = %s';
            $params[]     = $tokenStandard;
        }

        $whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $sql      = "SELECT is_verified, COUNT(*) AS cnt FROM {$table} {$whereSql} GROUP BY is_verified";

        /** @var list<object{is_verified: string, cnt: string}>|null $rows */
        $rows = $params === []
            ? $wpdb->get_results($sql)
            : $wpdb->get_results($wpdb->prepare($sql, ...$params));

        $out = ['verified' => 0, 'unverified' => 0];
        foreach ($rows ?: [] as $row) {
            if ((int) $row->is_verified === 1) {
                $out['verified'] = (int) $row->cnt;
            } else {
                $out['unverified'] = (int) $row->cnt;
            }
        }

        return $out;
    }

    /**
     * Single-row lookup by (chain_id, contract_address). Used by the
     * V2 Phase 6 §H1 NFT-piece view-model builder to assemble the
     * `collection` embed (§3.7). Returns null when no row exists —
     * the builder falls through to a read-time Cosmos fetch or
     * returns 404 for indexed chains.
     *
     * Bounded by the unique key (chain_id, contract_address) + LIMIT 1.
     *
     * @return CollectionWithChain|null
     */
    public static function findByChainContract(int $chainId, string $contract): ?object
    {
        if ($chainId <= 0 || $contract === '') {
            return null;
        }

        // PR 5a: STRICT canonical lookup. This used to be
        // `contract_address = strtolower($contract)`, which folded case for
        // every chain family — right for EVM, wrong for Solana, and it only
        // ever "worked" because the column collation is case-insensitive.
        //
        // A value the service refuses is NOT a collection identity, so this
        // returns null rather than degrading to an alias match. There is
        // deliberately no fallback: a silent drop from canonical lookup to
        // case-insensitive alias lookup would make an alias indistinguishable
        // from a mint, which is the confusion this column exists to end.
        // Callers that genuinely need the legacy behaviour must ask for it by
        // name — see {@see findLegacyByChainContractInsensitive}.
        $identity = self::canonicalIdentityFor($chainId, $contract);
        if (!$identity->isAccepted()) {
            return null;
        }

        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        /** @var CollectionWithChain|null */
        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.id, c.wallet_link_id, c.contract_address, c.canonical_identifier, c.chain_id, c.collection_name,
                    c.token_standard, c.total_supply, c.floor_price, c.floor_currency,
                    c.unique_holders, c.total_volume, c.listed_percentage, c.royalty_percentage,
                    c.metadata_storage, c.image_url, c.show_on_profile, c.fetched_at, c.expires_at,
                    ch.slug AS chain_slug, ch.name AS chain_name, ch.explorer_url, ch.native_token
               FROM {$table} c
               JOIN {$chains} ch ON ch.id = c.chain_id
              WHERE c.chain_id = %d
                AND c.canonical_identifier = %s
              LIMIT 1",
            $chainId,
            $identity->canonical()
        ));
    }

    /**
     * LEGACY compatibility lookup — case-insensitive match on the ORIGINAL
     * `contract_address`, bypassing canonical identity entirely.
     *
     * ── WHEN THIS IS THE RIGHT METHOD ───────────────────────────────────
     * Only for reaching rows whose identity is not yet canonicalisable:
     * pre-PR-5a Solana rows holding a Magic Eden *symbol* rather than a
     * mint, some of which are verified and back a holder community. They
     * carry `canonical_identifier IS NULL`, so {@see findByChainContract}
     * cannot and must not find them.
     *
     * As of PR 5a this method has NO production caller — pinned by
     * LegacyAliasRouteCompatibilityTest, because the one route that could
     * plausibly need it rejects such values upstream at
     * WalletAddressValidator. It exists for PR 5b's repair path and for
     * direct administrative use.
     *
     * ── WHY IT IS NOT A FALLBACK ────────────────────────────────────────
     * Chaining this after a failed canonical lookup would restore exactly
     * the ambiguity PR 5a removes — an alias would silently satisfy a
     * request for a mint. A caller must decide, explicitly and in its own
     * code, that legacy semantics are what it wants.
     *
     * This method is expected to be DELETED by PR 5b, once the legacy
     * aliases are resolved to real mints.
     *
     * @return CollectionWithChain|null
     */
    public static function findLegacyByChainContractInsensitive(int $chainId, string $contract): ?object
    {
        if ($chainId <= 0 || trim($contract) === '') {
            return null;
        }

        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        /** @var CollectionWithChain|null */
        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.id, c.wallet_link_id, c.contract_address, c.canonical_identifier, c.chain_id, c.collection_name,
                    c.token_standard, c.total_supply, c.floor_price, c.floor_currency,
                    c.unique_holders, c.total_volume, c.listed_percentage, c.royalty_percentage,
                    c.metadata_storage, c.image_url, c.show_on_profile, c.fetched_at, c.expires_at,
                    ch.slug AS chain_slug, ch.name AS chain_name, ch.explorer_url, ch.native_token
               FROM {$table} c
               JOIN {$chains} ch ON ch.id = c.chain_id
              WHERE c.chain_id = %d
                AND c.contract_address = %s
              LIMIT 1",
            $chainId,
            trim($contract)
        ));
    }

    /**
     * Resolve the on-chain `token_standard` for a (chain, contract) pair.
     *
     * Reads from `wp_bcc_onchain_collections.token_standard` — populated by
     * the indexer (Alchemy `category` field for EVM, fetcher metadata for
     * Cosmos/Solana). Returns the raw stored string (e.g. "ERC-721",
     * "ERC-1155", "SPL", "CW-721") or null when the row exists but the
     * standard is unknown / when no row exists.
     *
     * Used by the holder-gate path to decide between the ERC-721 RPC
     * route and the ERC-1155 persistent-index route.
     */
    public static function findTokenStandard(int $chainId, string $contract): ?string
    {
        if ($chainId <= 0 || $contract === '') {
            return null;
        }

        global $wpdb;
        $table = self::table();

        // PR 5a: strict canonical, same reasoning as findByChainContract().
        // A refusal degrades to "standard unknown", which every caller
        // already handles — the holder gate falls back to its RPC route
        // rather than the persistent index. Failing closed here is safe.
        $identity = self::canonicalIdentityFor($chainId, $contract);
        if (!$identity->isAccepted()) {
            return null;
        }

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT token_standard
               FROM {$table}
              WHERE chain_id = %d
                AND canonical_identifier = %s
              LIMIT 1",
            $chainId,
            $identity->canonical()
        ));

        if ($value === null) {
            return null;
        }
        $str = trim((string) $value);
        return $str === '' ? null : $str;
    }

    /**
     * Toggle the admin `is_verified` flag.
     *
     * Verified collections become candidates for auto-provisioning of
     * holder groups (see GatedGroupProvisioningService). Sync paths
     * never write this column — admin-only.
     */
    public static function setVerified(int $collectionId, bool $verified): bool
    {
        if ($collectionId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        return (bool) $wpdb->update(
            $table,
            ['is_verified' => $verified ? 1 : 0],
            ['id' => $collectionId],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Bulk-toggle is_verified across two id sets in two UPDATE statements.
     * Each UPDATE is gated by `is_verified <> target` so unchanged rows
     * are not touched (no recurring no-op writes).
     *
     * @param list<int> $idsToVerify
     * @param list<int> $idsToUnverify
     * @return int Total rows actually changed
     */
    public static function setVerifiedBulk(array $idsToVerify, array $idsToUnverify): int
    {
        $verify   = array_values(array_filter(array_map('intval', $idsToVerify),   static fn ($id) => $id > 0));
        $unverify = array_values(array_filter(array_map('intval', $idsToUnverify), static fn ($id) => $id > 0));

        if ($verify === [] && $unverify === []) {
            return 0;
        }

        global $wpdb;
        $table = self::table();
        $changed = 0;

        if ($verify !== []) {
            $ph = implode(',', array_fill(0, count($verify), '%d'));
            $changed += (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET is_verified = 1 WHERE id IN ({$ph}) AND is_verified <> 1",
                ...$verify
            ));
        }

        if ($unverify !== []) {
            $ph = implode(',', array_fill(0, count($unverify), '%d'));
            $changed += (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET is_verified = 0 WHERE id IN ({$ph}) AND is_verified <> 0",
                ...$unverify
            ));
        }

        return $changed;
    }

    /**
     * Bulk-fetch collections by id with chain slug/type and market
     * stats. Used by the holder-groups REST surface and the cross-kind
     * groups discovery endpoint to enrich each gated group with its
     * underlying collection metadata + decision-grade trade signals.
     *
     * ── THE RETURN IS A MAP KEYED BY COLLECTION ID ──────────────────
     * `collection id → row`, NOT a positional list, and NOT ordered:
     * ids that matched no row are simply absent, so the result is
     * shorter than `$ids` whenever one is unknown. Callers index by
     * the id they asked for (`$map[$id] ?? null`) or iterate the pairs.
     * A caller that reads `$result[0]` gets null for every real id —
     * that was a live defect in the Verify Collections hide/unhide
     * button, which answered "collection not found" for every
     * collection until 2026-08-13. Any test double for this method
     * MUST key by id too; the one that returned a convenient
     * zero-indexed list is what let the defect ship green.
     *
     * @param list<int> $ids
     * @return array<int, object{
     *     id: string,
     *     chain_id: string,
     *     contract_address: string,
     *     canonical_identifier: string|null,
     *     collection_name: string|null,
     *     image_url: string|null,
     *     token_standard: string|null,
     *     total_supply: string|null,
     *     unique_holders: string|null,
     *     floor_price: string|null,
     *     floor_currency: string|null,
     *     total_volume: string|null,
     *     listed_percentage: string|null,
     *     royalty_percentage: string|null,
     *     chain_slug: string,
     *     chain_type: string
     * }>
     */
    public static function findManyByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        global $wpdb;
        $table        = self::table();
        $chains       = ChainRepository::table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        /** @var list<object{
         *     id: string,
         *     chain_id: string,
         *     contract_address: string,
         *     canonical_identifier: string|null,
         *     collection_name: string|null,
         *     image_url: string|null,
         *     token_standard: string|null,
         *     total_supply: string|null,
         *     unique_holders: string|null,
         *     floor_price: string|null,
         *     floor_currency: string|null,
         *     total_volume: string|null,
         *     listed_percentage: string|null,
         *     royalty_percentage: string|null,
         *     chain_slug: string,
         *     chain_type: string
         * }>|null $rows */
        // PR 5b: `canonical_identifier` joins the projection because the
        // holder gate resolves identity from THIS row, not from the gate's
        // own `_bcc_gate_contract_address` post meta. Selected explicitly
        // (§2 — never `SELECT *`); `chain_type` was already here, so the
        // gate resolver gets the row and its chain family in one read.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.chain_id, c.contract_address, c.canonical_identifier,
                    c.collection_name, c.image_url,
                    c.token_standard, c.total_supply, c.unique_holders,
                    c.floor_price, c.floor_currency, c.total_volume,
                    c.listed_percentage, c.royalty_percentage,
                    ch.slug AS chain_slug, ch.chain_type
               FROM {$table} c
               JOIN {$chains} ch ON ch.id = c.chain_id
              WHERE c.id IN ({$placeholders})
              LIMIT 200",
            ...$ids
        ));

        // Keyed by the ROW's own id (not the requested one) so the key and
        // the row can never disagree — see the contract note above.
        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int) $row->id] = $row;
        }
        return $map;
    }

    /**
     * Verified collections, joined to chains for the slug + chain_type.
     * Drives the holder-group provisioning sweep.
     *
     * @return list<object{
     *     id: string,
     *     chain_id: string,
     *     contract_address: string,
     *     canonical_identifier: string|null,
     *     collection_name: string|null,
     *     image_url: string|null,
     *     chain_slug: string,
     *     chain_type: string
     * }>
     */
    public static function listVerified(int $limit = 200): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        /** @var list<object{
         *     id: string,
         *     chain_id: string,
         *     contract_address: string,
         *     canonical_identifier: string|null,
         *     collection_name: string|null,
         *     image_url: string|null,
         *     chain_slug: string,
         *     chain_type: string
         * }>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.chain_id, c.contract_address, c.canonical_identifier,
                    c.collection_name, c.image_url,
                    ch.slug AS chain_slug, ch.chain_type
             FROM {$table} c
             JOIN {$chains} ch ON ch.id = c.chain_id
             WHERE c.is_verified = 1
             ORDER BY c.id ASC
             LIMIT %d",
            $limit
        ));

        return $rows ?: [];
    }

    /**
     * Verified collections scoped to a single chain.
     *
     * Sibling of {@see listVerified()} — same JOIN + column list,
     * scoped to one chain_id. Used by V2 Phase 2's
     * `CosmosFetcher::list_holdings` to enumerate which CW-721
     * contracts to query per refresh.
     *
     * Ordered by `unique_holders DESC` so the most popular
     * collections are queried first when the per-refresh cap (set by
     * caller, default 30 contracts/chain via
     * `BCC_COSMOS_HOLDINGS_CONTRACT_CAP`) is hit. NULL holders sort
     * last so unenriched rows don't push popular ones out of the cap.
     *
     * @return list<object{
     *     id: string,
     *     chain_id: string,
     *     contract_address: string,
     *     canonical_identifier: string|null,
     *     collection_name: string|null,
     *     image_url: string|null,
     *     chain_slug: string,
     *     chain_type: string
     * }>
     */
    public static function listVerifiedByChain(int $chainId, int $limit = 30): array
    {
        if ($chainId <= 0) {
            return [];
        }
        $limit = max(1, min(200, $limit));

        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        /** @var list<object{
         *     id: string,
         *     chain_id: string,
         *     contract_address: string,
         *     canonical_identifier: string|null,
         *     collection_name: string|null,
         *     image_url: string|null,
         *     chain_slug: string,
         *     chain_type: string
         * }>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.chain_id, c.contract_address, c.canonical_identifier,
                    c.collection_name, c.image_url,
                    ch.slug AS chain_slug, ch.chain_type
             FROM {$table} c
             JOIN {$chains} ch ON ch.id = c.chain_id
             WHERE c.is_verified = 1
               AND c.chain_id = %d
             ORDER BY c.unique_holders IS NULL ASC,
                      c.unique_holders DESC,
                      c.id ASC
             LIMIT %d",
            $chainId,
            $limit
        ));

        return $rows ?: [];
    }


    /**
     * Verification map for a bounded set of contracts on one chain:
     * `strtolower(contract) => bool`. Contracts with NO collection row
     * are absent from the map — callers treat absent as unverified.
     *
     * Powers the gallery's per-item `collection_verified` annotation
     * (HoldingsService); chunked IN() keeps the query bounded per §4.
     *
     * @param list<string> $contracts
     * @return array<string, bool>
     */
    public static function verifiedMapForContracts(int $chainId, array $contracts): array
    {
        if ($chainId <= 0 || $contracts === []) {
            return [];
        }

        $contracts = array_values(array_unique(array_map('strtolower', $contracts)));

        global $wpdb;
        $table = self::table();
        $map   = [];

        foreach (array_chunk($contracts, 100) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '%s'));
            /** @var list<object{contract_address: string, is_verified: string}>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT contract_address, is_verified
                 FROM {$table}
                 WHERE chain_id = %d
                   AND contract_address IN ({$ph})",
                array_merge([$chainId], $chunk)
            ));
            foreach ($rows ?: [] as $row) {
                $map[strtolower((string) $row->contract_address)] = ((int) $row->is_verified === 1);
            }
        }

        return $map;
    }

    /**
     * Get collection counts grouped by chain_id.
     * Used by the admin Chains page to show per-chain stats.
     *
     * @return array<int, CollectionCountByChain> Keyed by chain_id, each with ->cnt and ->last_fetched.
     */
    public static function getCountsByChain(): array
    {
        $cached = wp_cache_get('collection_counts_by_chain', 'bcc_onchain');
        if (is_array($cached)) {
            /** @var array<int, CollectionCountByChain> $cached */
            return $cached;
        }

        global $wpdb;
        $table = self::table();

        /** @var list<CollectionCountByChain>|null $rows */
        $rows = $wpdb->get_results(
            "SELECT chain_id, COUNT(*) AS cnt,
                    MAX(fetched_at) AS last_fetched
             FROM {$table}
             GROUP BY chain_id
             LIMIT 100"
        );

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int) $row->chain_id] = $row;
        }

        wp_cache_set('collection_counts_by_chain', $map, 'bcc_onchain', 3600);

        return $map;
    }

    // ── Discovery bridge (holdings indexer → collection rows) ───────────

    /**
     * Idempotently ensure a collection row exists for every (chain_id,
     * contract_address) pair the holdings indexer encounters. Used as a
     * write-side bridge so collections appear in the admin Verify list as
     * soon as a connected wallet's transfers ingest, without depending on
     * any external "top collections" feed.
     *
     * Discovery rows are minimal: `wallet_link_id = NULL` (matches the
     * chain-level pattern from `bulkUpsert`), `token_standard` populated
     * from the transfer event when known, all market-stat fields NULL.
     * NftEnrichmentService backfills `collection_name`, `image_url`,
     * `total_supply`, and `floor_price` on a separate cron tick via
     * `findPendingEnrichment` / `applyEnrichment`.
     *
     * On collision (row already exists) we INSERT IGNORE plus a defensive
     * `token_standard` fill via `COALESCE(token_standard, VALUES(...))` —
     * never overwrites an existing non-null standard. This means a row
     * that was already enriched will not have its data clobbered, while
     * a stub row with `token_standard = NULL` gets corrected as soon as
     * a transfer event provides the standard.
     *
     * @param list<array{chain_id: int, contract_address: string, token_standard?: ?string}> $rows
     * @param int $ttlSeconds  Initial expires_at horizon. Matches bulkUpsert default.
     * @return int Rows actually inserted (existing rows count as 0 from `rows_affected`).
     */
    public static function ensureExistsBatch(array $rows, int $ttlSeconds = 4 * HOUR_IN_SECONDS): int
    {
        if ($rows === []) {
            return 0;
        }

        // Dedupe by (chain_id, CANONICAL identity) — the indexer's batch
        // typically contains many holdings rows for the same contract.
        //
        // PR 5a: the dedupe key was `strtolower($contract)`, which is right
        // for EVM and wrong for Solana — two distinct base58 mints differing
        // only by case collapsed into one entry before the database ever saw
        // them. The key is now whatever the chain-aware service says the
        // identity is, so the fold happens only where the chain actually
        // folds.
        $deduped = [];
        foreach ($rows as $r) {
            $chainId = (int) ($r['chain_id'] ?? 0);
            $raw     = trim((string) ($r['contract_address'] ?? ''));
            if ($chainId <= 0 || $raw === '') {
                continue;
            }

            $identity = self::canonicalIdentityFor($chainId, $raw);
            if (!$identity->isAccepted()) {
                self::logRefusedIdentity('ensureExistsBatch', $chainId, $identity);
                continue;
            }

            // The original text is what lands in `contract_address`; the
            // canonical form is what lands in `canonical_identifier`.
            $contract  = $raw;
            $canonical = $identity->canonical();
            $key       = $chainId . '|' . $canonical;
            // Last-write-wins on token_standard within the dedupe pass —
            // any non-null standard supersedes a null. Lets a single batch
            // with both 721 and 1155 transfers for the same contract
            // (rare but possible across token_ids) end up tagged correctly.
            $existing = $deduped[$key]['token_standard'] ?? null;
            $incoming = isset($r['token_standard']) && is_string($r['token_standard']) && $r['token_standard'] !== ''
                ? $r['token_standard']
                : null;
            $deduped[$key] = [
                'chain_id'             => $chainId,
                'contract_address'     => $contract,
                'canonical_identifier' => $canonical,
                'token_standard'       => $existing ?? $incoming,
            ];
        }

        if ($deduped === []) {
            return 0;
        }

        global $wpdb;
        $table     = self::table();
        $now       = current_time('mysql', true);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $inserted  = 0;

        foreach ($deduped as $entry) {
            $chainId   = $entry['chain_id'];
            $contract  = $entry['contract_address'];
            $canonical = $entry['canonical_identifier'];
            $std       = $entry['token_standard'];

            // Inline the token_standard literal so we can pass NULL when
            // unknown without %s coercing it to an empty string. Same
            // pattern bulkUpsert uses for nullable numeric fields.
            $stdSql = $std !== null
                ? $wpdb->prepare('%s', $std)
                : 'NULL';

            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table}
                    (wallet_link_id, contract_address, canonical_identifier, chain_id, token_standard,
                     show_on_profile, is_verified, fetched_at, expires_at)
                 VALUES (NULL, %s, %s, %d, {$stdSql}, 1, 0, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    -- canonical_identifier is INSERT-only; see upsert().
                    token_standard = COALESCE(token_standard, VALUES(token_standard))",
                $contract,
                $canonical,
                $chainId,
                $now,
                $expiresAt
            ));

            // rows_affected = 1 → newly inserted; 2 → row updated via
            // ON DUPLICATE KEY (existed); 0 → existed and update was a
            // no-op (token_standard already populated). We only count
            // genuine inserts so a re-ingestion sweep returns 0.
            if ($result !== false && (int) $wpdb->rows_affected === 1) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * Pull rows that have not yet been enriched with collection-level
     * metadata. "Pending" here means `collection_name IS NULL` — every
     * row written by `ensureExistsBatch` starts with name null and only
     * gets a name from a successful enrichment call.
     *
     * Bounded by `$limit` (capped at 100 to fit the per-chain enrichment
     * cron's 5-minute cadence without runaway). Ordered by `id ASC` so
     * the oldest discoveries enrich first — newer arrivals queue up
     * behind them rather than starving the backlog under sustained load.
     *
     * @return list<object{
     *     id: string,
     *     chain_id: string,
     *     contract_address: string,
     *     token_standard: string|null
     * }>
     */
    public static function findPendingEnrichment(int $chainId, int $limit = 50): array
    {
        if ($chainId <= 0) {
            return [];
        }
        $limit = max(1, min(100, $limit));

        global $wpdb;
        $table = self::table();

        /** @var list<object{id: string, chain_id: string, contract_address: string, token_standard: string|null}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, chain_id, contract_address, token_standard
               FROM {$table}
              WHERE chain_id = %d
                AND collection_name IS NULL
              ORDER BY id ASC
              LIMIT %d",
            $chainId,
            $limit
        ));

        return $rows ?: [];
    }

    /**
     * Write enrichment fields for a single collection row. Mirrors the
     * shape `EvmFetcher::fetchContractMetadata` returns.
     *
     * Two write disciplines, picked per-column:
     *
     *   IDENTITY fields (`collection_name`, `image_url`, `token_standard`)
     *   — preserve-on-write via COALESCE. Once a row gets a real name,
     *   no future enrichment can wipe it. Defends against partial
     *   Alchemy responses that return e.g. `name=null` mid-incident.
     *
     *   MARKET fields (`floor_price`, `floor_currency`, `total_supply`)
     *   — overwrite when the input is non-null, leave alone when null.
     *   These genuinely move (a floor of 0.05 ETH today may be 0.5 ETH
     *   tomorrow); preserve-once would silently freeze stale data.
     *   Symmetric with `bulkUpsert`'s `VALUES(floor_price)` semantics.
     *
     *   In both cases a null input field is omitted from the SET list,
     *   so a partial response can't wipe a previously-good column —
     *   the divergence is only in what happens when both old AND new
     *   values are non-null (preserve old vs. take new).
     *
     * `fetched_at` is unconditionally bumped so the admin "last refresh"
     * column reflects the attempt even when no individual column changed.
     *
     * @param array{
     *     collection_name?: ?string,
     *     image_url?: ?string,
     *     total_supply?: ?int,
     *     floor_price?: ?float,
     *     floor_currency?: ?string,
     *     token_standard?: ?string
     * } $fields
     */
    public static function applyEnrichment(int $collectionId, array $fields): bool
    {
        if ($collectionId <= 0) {
            return false;
        }

        $name      = isset($fields['collection_name']) && is_string($fields['collection_name']) && $fields['collection_name'] !== ''
            ? $fields['collection_name']
            : null;
        $image     = isset($fields['image_url']) && is_string($fields['image_url']) && $fields['image_url'] !== ''
            ? $fields['image_url']
            : null;
        $supply    = isset($fields['total_supply']) && is_numeric($fields['total_supply'])
            ? (int) $fields['total_supply']
            : null;
        $floor     = isset($fields['floor_price']) && is_numeric($fields['floor_price'])
            ? (float) $fields['floor_price']
            : null;
        $currency  = isset($fields['floor_currency']) && is_string($fields['floor_currency']) && $fields['floor_currency'] !== ''
            ? $fields['floor_currency']
            : null;
        $standard  = isset($fields['token_standard']) && is_string($fields['token_standard']) && $fields['token_standard'] !== ''
            ? $fields['token_standard']
            : null;

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        // Build SET list dynamically: omit any column whose input is null
        // so a partial fetch never touches that column at all. Identity
        // columns wrap the value in COALESCE; market columns overwrite.
        $setClauses = [];
        $params     = [];

        // Identity fields (preserve-once via COALESCE).
        if ($name !== null) {
            $setClauses[] = 'collection_name = COALESCE(collection_name, %s)';
            $params[]     = $name;
        }
        if ($image !== null) {
            $setClauses[] = 'image_url = COALESCE(image_url, %s)';
            $params[]     = $image;
        }
        if ($standard !== null) {
            $setClauses[] = 'token_standard = COALESCE(token_standard, %s)';
            $params[]     = $standard;
        }

        // Market fields (refresh when fresh data arrives).
        if ($supply !== null) {
            $setClauses[] = 'total_supply = %d';
            $params[]     = $supply;
        }
        if ($floor !== null) {
            $setClauses[] = 'floor_price = %f';
            $params[]     = $floor;
        }
        if ($currency !== null) {
            $setClauses[] = 'floor_currency = %s';
            $params[]     = $currency;
        }

        // Always bump fetched_at — the "we tried" signal that survives
        // an all-null partial response.
        $setClauses[] = 'fetched_at = %s';
        $params[]     = $now;

        $params[] = $collectionId;

        $sql    = "UPDATE {$table} SET " . implode(', ', $setClauses) . " WHERE id = %d";
        $result = $wpdb->query($wpdb->prepare($sql, $params));

        return $result !== false;
    }

    // ── PR 6: collection-state tabs and provisioning intent ─────────────

    /**
     * One page of one administrator state tab, with an EXACT total.
     *
     * ── WHY SET-BASED AND NOT "FETCH 500 GATED IDS FIRST" ───────────────
     * The obvious shape is to load every gated collection id and every deny
     * rule into PHP once, then classify. It is wrong for a reason that only
     * shows up later: any such read is bounded, and the moment the install
     * passes that ceiling the subset silently misclassifies every row beyond
     * it — a `verified_with_community` collection would start appearing under
     * `needs_attention` with no error anywhere. Pagination would also be
     * wrong, because the page is a slice of a filtered set the database has
     * to compute.
     *
     * So community-existence and hidden-ness are EXISTS subqueries evaluated
     * by MySQL as part of the same statement. One query for the page, one for
     * the count, no N+1, no ceiling, and a total that matches the rows.
     *
     * `has_community` and `is_hidden` are also PROJECTED, so the row carries
     * the two facts the classifier needs without a second lookup per row.
     *
     * @param string      $tab           one of CollectionStateClassifier::tabs()
     * @param int         $page          1-based
     * @param int         $perPage       clamped to 1..100
     * @param string|null $chainSlug     optional chain filter
     * @param string|null $tokenStandard optional standard filter
     * @return array{items: list<CollectionAdminStateRow>, total: int, pages: int, available: bool}
     *         `available` is false when the read failed — the caller must
     *         render "unavailable", never an empty or partial tab.
     */
    public static function listForAdminState(
        string $tab,
        int $page = 1,
        int $perPage = 50,
        ?string $chainSlug = null,
        ?string $tokenStandard = null
    ): array {
        global $wpdb;

        $unavailable = ['items' => [], 'total' => 0, 'pages' => 0, 'available' => false];

        if (!CollectionStateClassifier::isTab($tab)) {
            // An unknown tab must never degrade to "everything".
            return $unavailable;
        }

        $table  = self::table();
        $chains = ChainRepository::table();

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $conditions = [CollectionStateClassifier::sqlForTab($tab, $wpdb->postmeta, $wpdb->posts)];
        $params     = [];

        if ($chainSlug !== null && $chainSlug !== '') {
            $chain = ChainRepository::getBySlug($chainSlug);
            if ($chain === null) {
                // A filter naming a chain that does not exist genuinely
                // matches nothing. That is an empty result, not a failure.
                return ['items' => [], 'total' => 0, 'pages' => 0, 'available' => true];
            }
            $conditions[] = 'c.chain_id = %d';
            $params[]     = (int) $chain->id;
        }

        if ($tokenStandard !== null && $tokenStandard !== '') {
            $conditions[] = 'c.token_standard = %s';
            $params[]     = $tokenStandard;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $conditions);

        // `id > %d` is a no-op filter (ids are positive) that guarantees at
        // least one placeholder: wpdb::prepare() warns on a placeholderless
        // query and the suite runs with failOnWarning.
        $countSql = "SELECT COUNT(*) FROM {$table} c {$whereSql} AND c.id > %d";
        $total    = $wpdb->get_var($wpdb->prepare($countSql, ...array_merge($params, [0])));

        // A failed query and a genuine no-rows result are BOTH `[]` from
        // `wpdb::get_results()` (it returns `last_result`, and returns null only
        // for an empty query string), so `last_error` is the only discriminator
        // there is. Without it, "this tab could not be read" is indistinguishable
        // from "this tab is empty" and an operator is shown a confident zero.
        //
        // Read as its own condition rather than the right-hand side of an `||`:
        // the WordPress stub gives `last_error` a `''` default that static
        // analysis folds into a literal when it appears as a boolean operand.
        $readFailed = $total === null;
        if (!$readFailed && $wpdb->last_error) {
            $readFailed = true;
        }
        
        if ($readFailed) {
            \BCC\Core\Log\Logger::error(
                '[bcc-trust] collection-state count failed; reporting the tab as unavailable',
                ['tab' => $tab, 'db_error' => $wpdb->last_error]
            );
            return $unavailable;
        }
        $total = (int) $total;

        $hasCommunity = CollectionStateClassifier::sqlHasCommunity($wpdb->postmeta, $wpdb->posts);
        $isHidden     = CollectionStateClassifier::sqlIsHidden();

        /** @var list<CollectionAdminStateRow>|null $items */
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.contract_address, c.canonical_identifier, c.collection_name,
                    c.token_standard, c.total_supply, c.unique_holders, c.image_url,
                    c.is_verified, c.source,
                    c.provisioning_state, c.provisioning_requested_at,
                    c.provisioning_requested_by, c.provisioning_failure_code,
                    ({$hasCommunity}) AS has_community,
                    ({$isHidden}) AS is_hidden,
                    c.chain_id, ch.slug AS chain_slug, ch.chain_type, ch.explorer_url
               FROM {$table} c
          LEFT JOIN {$chains} ch ON ch.id = c.chain_id
               {$whereSql}
               ORDER BY c.id DESC
               LIMIT %d OFFSET %d",
            ...array_merge($params, [$perPage, $offset])
        ));

        // A failed query and a genuine no-rows result are BOTH `[]` from
        // `wpdb::get_results()` (it returns `last_result`, and returns null only
        // for an empty query string), so `last_error` is the only discriminator
        // there is. Without it, "this tab could not be read" is indistinguishable
        // from "this tab is empty" and an operator is shown a confident zero.
        //
        // Read as its own condition rather than the right-hand side of an `||`:
        // the WordPress stub gives `last_error` a `''` default that static
        // analysis folds into a literal when it appears as a boolean operand.
        $readFailed = $items === null;
        if (!$readFailed && $wpdb->last_error) {
            $readFailed = true;
        }
        
        if ($readFailed) {
            \BCC\Core\Log\Logger::error(
                '[bcc-trust] collection-state page read failed; reporting the tab as unavailable',
                ['tab' => $tab, 'db_error' => $wpdb->last_error]
            );
            return $unavailable;
        }

        return [
            'items'     => $items,
            'total'     => $total,
            'pages'     => (int) ceil($total / $perPage),
            'available' => true,
        ];
    }

    /**
     * EXACT row counts for all four tabs under the same filters.
     *
     * One statement, four conditional sums, so the four numbers are computed
     * over one consistent snapshot of the table. Four separate COUNT queries
     * could disagree with each other and with the page if a write lands
     * between them — a small race, but one that surfaces as a tab whose
     * header contradicts its own contents.
     *
     * @return array{counts: array<string, int>, available: bool}
     */
    public static function countsByState(
        ?string $chainSlug = null,
        ?string $tokenStandard = null
    ): array {
        global $wpdb;

        $zeroes = [];
        foreach (CollectionStateClassifier::tabs() as $tab) {
            $zeroes[$tab] = 0;
        }
        $unavailable = ['counts' => $zeroes, 'available' => false];

        $table  = self::table();
        $params = [];
        $where  = [];

        if ($chainSlug !== null && $chainSlug !== '') {
            $chain = ChainRepository::getBySlug($chainSlug);
            if ($chain === null) {
                return ['counts' => $zeroes, 'available' => true];
            }
            $where[]  = 'c.chain_id = %d';
            $params[] = (int) $chain->id;
        }

        if ($tokenStandard !== null && $tokenStandard !== '') {
            $where[]  = 'c.token_standard = %s';
            $params[] = $tokenStandard;
        }

        $whereSql = $where === [] ? 'WHERE c.id > %d' : 'WHERE ' . implode(' AND ', $where) . ' AND c.id > %d';

        $selects = [];
        foreach (CollectionStateClassifier::tabs() as $tab) {
            // Column aliases are derived from the tab constants, which are
            // this class's own literals — never caller input.
            $selects[] = 'SUM(CASE WHEN ' . CollectionStateClassifier::sqlForTab($tab, $wpdb->postmeta, $wpdb->posts)
                . ' THEN 1 ELSE 0 END) AS ' . $tab;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . implode(', ', $selects) . " FROM {$table} c {$whereSql}",
            ...array_merge($params, [0])
        ));

        // A failed query and a genuine no-rows result are BOTH `[]` from
        // `wpdb::get_results()` (it returns `last_result`, and returns null only
        // for an empty query string), so `last_error` is the only discriminator
        // there is. Without it, "this tab could not be read" is indistinguishable
        // from "this tab is empty" and an operator is shown a confident zero.
        //
        // Read as its own condition rather than the right-hand side of an `||`:
        // the WordPress stub gives `last_error` a `''` default that static
        // analysis folds into a literal when it appears as a boolean operand.
        $readFailed = $row === null;
        if (!$readFailed && $wpdb->last_error) {
            $readFailed = true;
        }
        
        if ($readFailed) {
            \BCC\Core\Log\Logger::error(
                '[bcc-trust] collection-state counts failed; reporting counts as unavailable',
                ['db_error' => $wpdb->last_error]
            );
            return $unavailable;
        }

        $counts = [];
        foreach (CollectionStateClassifier::tabs() as $tab) {
            // SUM() over zero rows is NULL, which is a legitimate zero here.
            $counts[$tab] = (int) ($row->{$tab} ?? 0);
        }

        return ['counts' => $counts, 'available' => true];
    }

    /**
     * The provisioning queue: collections with RECORDED INTENT, in id order,
     * after a cursor.
     *
     * ── WHY THIS REPLACES `listVerified()` FOR PROVISIONING ─────────────
     * `listVerified()` selects on `is_verified = 1` alone — it IS the
     * authorization decision that PR 6 exists to remove. This method selects
     * on `provisioning_state = 'requested'`, so a collection reaches the
     * sweep only because an administrator explicitly asked. Verification is
     * still required, and is re-checked at the moment of provisioning, but it
     * no longer authorizes anything by itself.
     *
     * ── WHY CLAMPED AND CURSORED, WHEN `listVerified()` IS NEITHER ──────
     * `listVerified()` passes an unclamped caller `$limit` straight to
     * `LIMIT %d`, and `ORDER BY id ASC` with no cursor means the daily sweep
     * re-reads the same first 200 rows forever — anything past id-rank 200
     * is never reached. Inheriting that into a queue would starve it
     * silently, which is precisely the failure a queue must not have.
     *
     * @param int $afterId cursor; 0 starts at the beginning
     * @param int $limit   clamped to 1..200
     * @return list<CollectionRequestedRow>
     */
    public static function listRequested(int $afterId = 0, int $limit = 50): array
    {
        global $wpdb;

        $table  = self::table();
        $chains = ChainRepository::table();
        $limit  = max(1, min(200, $limit));
        $afterId = max(0, $afterId);

        /** @var list<CollectionRequestedRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.chain_id, c.contract_address, c.canonical_identifier,
                    c.collection_name, c.is_verified,
                    c.provisioning_state, c.provisioning_requested_at, c.provisioning_requested_by,
                    ch.slug AS chain_slug, ch.chain_type
               FROM {$table} c
               JOIN {$chains} ch ON ch.id = c.chain_id
              WHERE c.provisioning_state = %s
                AND c.id > %d
              ORDER BY c.id ASC
              LIMIT %d",
            ProvisioningState::REQUESTED,
            $afterId,
            $limit
        ));

        return $rows ?: [];
    }

    /**
     * Read exactly the provisioning fields, under a row lock when one is
     * available.
     *
     * ── WHY `FOR UPDATE` IS CONDITIONAL ─────────────────────────────────
     * `SELECT … FOR UPDATE` outside a transaction takes no lock and succeeds
     * silently, which is worse than not locking at all because the code
     * reads as though it were safe. The lock is requested only inside
     * `TransactionManager::run()`, and the caller that needs serialisation
     * is responsible for being in one.
     *
     * @return CollectionProvisioningRow|null
     */
    public static function readProvisioningRow(int $collectionId, bool $forUpdate = false): ?object
    {
        global $wpdb;

        if ($collectionId <= 0) {
            return null;
        }

        $table  = self::table();
        $suffix = '';
        if ($forUpdate && TransactionManager::isInRunTransaction()) {
            $suffix = ' FOR UPDATE';
        }

        /** @var CollectionProvisioningRow|null */
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, is_verified, canonical_identifier, collection_name, chain_id,
                    provisioning_state, provisioning_requested_at,
                    provisioning_requested_by, provisioning_failure_code
               FROM {$table}
              WHERE id = %d" . $suffix,
            $collectionId
        ));
    }

    /**
     * Move one collection to a new provisioning state, refusing anything the
     * transition table forbids.
     *
     * ── WHY THE EXPECTED CURRENT STATE IS IN THE WHERE CLAUSE ───────────
     * Checking the transition in PHP and then writing unconditionally is a
     * TOCTOU: two operators clicking Request and Withdraw at the same moment
     * both read `none`/`requested`, both validate, and the later write wins
     * regardless of order. Putting the expected state in the UPDATE makes the
     * database the arbiter — a zero-row result means someone else moved
     * first, and the caller is told so rather than believing it succeeded.
     *
     * The field invariants are enforced here too, so no caller can write a
     * `failed` row with no failure code or a `none` row that still names a
     * requester. {@see ProvisioningState::fieldViolations()}
     *
     * @param int         $collectionId
     * @param string      $expectedFrom the state the caller believes it is in
     * @param string      $to
     * @param int|null    $requestedBy  required for requested/failed
     * @param string|null $requestedAt  UTC 'Y-m-d H:i:s'; required for requested
     * @param string|null $failureCode  required for failed, forbidden otherwise
     * @return bool true when exactly one row moved
     */
    public static function setProvisioningState(
        int $collectionId,
        string $expectedFrom,
        string $to,
        ?int $requestedBy = null,
        ?string $requestedAt = null,
        ?string $failureCode = null
    ): bool {
        global $wpdb;

        if ($collectionId <= 0) {
            return false;
        }

        if (!ProvisioningState::canTransition($expectedFrom, $to)) {
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] refused an illegal provisioning transition',
                ['collection_id' => $collectionId, 'from' => $expectedFrom, 'to' => $to]
            );
            return false;
        }

        $violations = ProvisioningState::fieldViolations($to, $requestedAt, $requestedBy, $failureCode);
        if ($violations !== []) {
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] refused a provisioning write that would leave a contradictory row',
                ['collection_id' => $collectionId, 'to' => $to, 'violations' => $violations]
            );
            return false;
        }

        $table = self::table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET provisioning_state        = %s,
                    provisioning_requested_at = %s,
                    provisioning_requested_by = %s,
                    provisioning_failure_code = %s
              WHERE id = %d
                AND provisioning_state = %s",
            $to,
            $requestedAt,
            $requestedBy,
            $failureCode,
            $collectionId,
            $expectedFrom
        ));

        if ($affected === false) {
            \BCC\Core\Log\Logger::error(
                '[bcc-trust] provisioning state write failed',
                ['collection_id' => $collectionId, 'to' => $to, 'db_error' => $wpdb->last_error]
            );
            return false;
        }

        // A guarded UPDATE that matches nothing means the row was not in the
        // expected state. That is a legitimate concurrent outcome, not an
        // error, but it is emphatically not success.
        return (int) $affected === 1;
    }

    /**
     * Withdraw a PENDING request because verification was removed.
     *
     * Deliberately a separate, narrow method rather than a
     * `setProvisioningState()` call: it must be impossible to reach
     * `provisioned` with this, whatever the caller passes. The WHERE clause
     * names the only two states that may be withdrawn, so a provisioned
     * community can never be un-provisioned by an unverify — the asymmetry
     * issue #215 requires.
     *
     * @return int number of rows withdrawn (0 or 1)
     */
    public static function withdrawPendingProvisioning(int $collectionId): int
    {
        global $wpdb;

        if ($collectionId <= 0) {
            return 0;
        }

        $table = self::table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET provisioning_state        = %s,
                    provisioning_requested_at = NULL,
                    provisioning_requested_by = NULL,
                    provisioning_failure_code = NULL
              WHERE id = %d
                AND provisioning_state IN (%s, %s)",
            ProvisioningState::NONE,
            $collectionId,
            ProvisioningState::REQUESTED,
            ProvisioningState::FAILED
        ));

        if ($affected === false) {
            \BCC\Core\Log\Logger::error(
                '[bcc-trust] withdraw of a pending provisioning request failed',
                ['collection_id' => $collectionId, 'db_error' => $wpdb->last_error]
            );
            return 0;
        }

        return (int) $affected;
    }


}
