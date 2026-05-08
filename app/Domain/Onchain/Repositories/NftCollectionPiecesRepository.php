<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-token metadata cache for the §4.17 NFT-piece detail endpoint.
 *
 * Owns all DB access for `wp_bcc_onchain_collection_pieces`. One row
 * per (chain_id, contract_address, token_id) triple — distinct from
 * `bcc_nft_holdings` which keys on wallet × token (ownership ledger).
 *
 * Read API:
 *   - findByTriple — primary lookup. Returns the row regardless of
 *     freshness (callers compare `expires_at` to NOW to decide FRESH
 *     vs STALE; this lets §4.17 surface `X-BCC-Cache: FRESH | STALE
 *     | MISS` distinctly).
 *
 * Write API:
 *   - upsert — INSERT … ON DUPLICATE KEY UPDATE with identity-vs-market
 *     discipline (see schema-collection-pieces.php docblock).
 *
 * Cache invalidation: pieces are addressed by their unique key on every
 * read, so no generation counter is needed (matches `CollectionRepository`'s
 * pattern). TTL on `expires_at` is the only freshness gate.
 *
 * @phpstan-type PieceRow object{
 *     id: string,
 *     chain_id: string,
 *     contract_address: string,
 *     token_id: string,
 *     name: string|null,
 *     description: string|null,
 *     image_url: string|null,
 *     image_url_thumb: string|null,
 *     attributes_json: string|null,
 *     fetched_at: string,
 *     expires_at: string
 * }
 *
 * @phpstan-type PieceUpsertFields array{
 *     name?: ?string,
 *     description?: ?string,
 *     image_url?: ?string,
 *     image_url_thumb?: ?string,
 *     attributes_json?: ?string
 * }
 */
final class NftCollectionPiecesRepository
{
    /** @var string Explicit column list — must match schema-collection-pieces.php. */
    private const COLUMNS = 'id, chain_id, contract_address, token_id, name, description,
                 image_url, image_url_thumb, attributes_json, fetched_at, expires_at';

    public static function table(): string
    {
        return DB::table('onchain_collection_pieces');
    }

    /**
     * Single-row lookup via the unique (chain_id, contract_address, token_id)
     * index. Returns the row whether or not `expires_at` is in the past —
     * the §4.17 endpoint distinguishes FRESH vs STALE vs MISS by
     * comparing `expires_at` to NOW at the call site.
     *
     * Bounded by the unique index + LIMIT 1.
     *
     * @return PieceRow|null
     */
    public static function findByTriple(int $chainId, string $contract, string $tokenId): ?object
    {
        if ($chainId <= 0 || $contract === '' || $tokenId === '') {
            return null;
        }

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        // contract_address is stored lowercased (matches the convention
        // used by NftHoldingsRepository and bcc_onchain_collections).
        // tokenId stays verbatim — CW-721 token IDs are case-sensitive
        // and may contain non-ASCII data.
        /** @var PieceRow|null */
        return $wpdb->get_row($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id = %d
                AND contract_address = %s
                AND token_id = %s
              LIMIT 1",
            $chainId,
            strtolower($contract),
            $tokenId
        ));
    }

    /**
     * Insert or update a piece row by the (chain_id, contract_address,
     * token_id) unique key.
     *
     * Identity-vs-market write discipline (mirrors CollectionRepository::applyEnrichment):
     *   - IDENTITY (`name`, `image_url`, `image_url_thumb`) — preserve-on-write
     *     via COALESCE. Once a row gets a real name/image, no future enrichment
     *     can wipe it.
     *   - MARKET-ish (`description`, `attributes_json`) — overwrite when the
     *     incoming value is non-null. Creators legitimately update these
     *     post-mint (rarity recompute, description tweaks).
     *
     * `fetched_at` is unconditionally bumped; `expires_at` is reset to
     * NOW + ttlSeconds.
     *
     * @param PieceUpsertFields $fields
     */
    public static function upsert(
        int $chainId,
        string $contract,
        string $tokenId,
        array $fields,
        int $ttlSeconds = 4 * HOUR_IN_SECONDS
    ): bool {
        if ($chainId <= 0 || $contract === '' || $tokenId === '') {
            return false;
        }

        global $wpdb;
        $table     = self::table();
        $now       = current_time('mysql', true);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + max(60, $ttlSeconds));

        $contractLc = strtolower($contract);

        $name        = self::normalizeNullableString($fields['name']             ?? null, 255);
        $description = self::normalizeNullableString($fields['description']      ?? null, 65000);
        $imageUrl    = self::normalizeNullableString($fields['image_url']        ?? null, 65000);
        $imageThumb  = self::normalizeNullableString($fields['image_url_thumb']  ?? null, 65000);
        $attrsJson   = self::normalizeNullableString($fields['attributes_json']  ?? null, 4_000_000);

        // Inline NULL literals for fields we want to pass as actual NULL
        // (so the COALESCE/overwrite logic on the duplicate-key branch
        // sees a true NULL, not an empty string from %s coercion).
        $nameSql        = $name        !== null ? $wpdb->prepare('%s', $name)        : 'NULL';
        $descriptionSql = $description !== null ? $wpdb->prepare('%s', $description) : 'NULL';
        $imageSql       = $imageUrl    !== null ? $wpdb->prepare('%s', $imageUrl)    : 'NULL';
        $thumbSql       = $imageThumb  !== null ? $wpdb->prepare('%s', $imageThumb)  : 'NULL';
        $attrsSql       = $attrsJson   !== null ? $wpdb->prepare('%s', $attrsJson)   : 'NULL';

        $sql = $wpdb->prepare(
            "INSERT INTO {$table}
                (chain_id, contract_address, token_id, name, description,
                 image_url, image_url_thumb, attributes_json,
                 fetched_at, expires_at)
             VALUES (%d, %s, %s, {$nameSql}, {$descriptionSql},
                 {$imageSql}, {$thumbSql}, {$attrsSql},
                 %s, %s)
             ON DUPLICATE KEY UPDATE
                name            = COALESCE(name, VALUES(name)),
                image_url       = COALESCE(image_url, VALUES(image_url)),
                image_url_thumb = COALESCE(image_url_thumb, VALUES(image_url_thumb)),
                description     = COALESCE(VALUES(description), description),
                attributes_json = COALESCE(VALUES(attributes_json), attributes_json),
                fetched_at      = VALUES(fetched_at),
                expires_at      = VALUES(expires_at)",
            $chainId,
            $contractLc,
            $tokenId,
            $now,
            $expiresAt
        );

        $result = $wpdb->query($sql);
        return $result !== false;
    }

    /**
     * Trim + bound a nullable string field. Returns null when the
     * input is null, empty, or non-scalar — matches the upstream
     * fetchers' convention of "absent fields are null, not empty
     * strings."
     */
    private static function normalizeNullableString(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > $maxLength) {
            return mb_substr($trimmed, 0, $maxLength);
        }
        return $trimmed;
    }
}
