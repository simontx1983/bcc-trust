<?php

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only client for the Stargaze marketplace REST API on the Cosmos
 * Hub (`marketplace-api.cosmos.stargaze-apis.com`) — the indexer behind
 * stargaze.zone after the 2026 Stargaze → Hub migration.
 *
 * Why this exists: the Hub LCD cannot enumerate "which CW-721
 * collections does this wallet hold" (wasmd has no owner→contracts
 * index), so wallet-link-time collection discovery on Cosmos needs an
 * off-chain indexer. This API is the marketplace's own data source —
 * open (no auth) but NOT a supported public contract, so every caller
 * must treat it as best-effort enhancement: null on any failure, never
 * a hard dependency. Discovery rows it feeds land `is_verified = 0`
 * and only become gate-relevant after the operator verifies them
 * (curated-only posture), so a poisoned response cannot grant access
 * to anything.
 *
 * Coverage: Cosmos Hub collections only. Other CW-721 chains
 * (Injective / Kujira / Dungeon) are NOT indexed here — callers gate on
 * the `cosmos` chain slug.
 *
 * @phpstan-type ProfileCollection array{
 *     contract_address: string,
 *     collection_name: ?string,
 *     owned_count: int,
 *     total_supply: ?int,
 *     image_url: ?string
 * }
 */
final class StargazeMarketplaceApi
{
    private const BASE_URL = 'https://marketplace-api.cosmos.stargaze-apis.com/api/v1';

    /** API page size (server cap is 100). */
    private const PAGE_SIZE = 100;

    /**
     * Pagination ceiling — 3 pages = 300 collections per wallet. A
     * hoarder wallet past that is indistinguishable from junk for
     * discovery purposes; callers cap far lower anyway.
     */
    private const MAX_PAGES = 3;

    /**
     * Successful rollups are cached per wallet. 6h balances freshness
     * (a newly bought collection shows up on the next admin-page
     * render / relink within hours) against hammering an unsupported
     * third-party API from the demand-count path, which fans out over
     * every linked Cosmos wallet.
     */
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    private const CACHE_GROUP = 'bcc_onchain';

    /**
     * Per-wallet collection rollup: every collection the wallet
     * currently holds, with owned counts.
     *
     * Returns null when the API could not be read (transport error,
     * non-200, unparseable body) — callers must treat that as UNKNOWN,
     * not "holds nothing". A wallet that genuinely holds nothing
     * returns []. Failures are never cached.
     *
     * @return list<ProfileCollection>|null
     */
    public static function profileCollections(string $address): ?array
    {
        $address = strtolower(trim($address));
        // Hub account addresses only (bech32, 39-char body). Contract
        // addresses (59-char body) and other HRPs are caller bugs.
        if (!preg_match('/^cosmos1[02-9ac-hj-np-z]{38}$/', $address)) {
            return null;
        }

        $cacheKey = 'sg_profile_collections_' . $address;
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if (is_array($cached)) {
            /** @var list<ProfileCollection> $cached */
            return $cached;
        }

        $out = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $url = sprintf(
                '%s/profiles/%s/collections?limit=%d&offset=%d',
                self::BASE_URL,
                rawurlencode($address),
                self::PAGE_SIZE,
                $page * self::PAGE_SIZE
            );

            $response = ApiRetry::get($url, [
                'timeout' => 12,
                'headers' => ['Accept' => 'application/json'],
            ], [
                'label' => 'Stargaze marketplace profile collections',
            ]);

            if (is_wp_error($response)) {
                \BCC\Core\Log\Logger::warning('[StargazeMarketplaceApi] transport error: ' . $response->get_error_message(), [
                    'address' => $address,
                ]);
                return null;
            }
            if ((int) wp_remote_retrieve_response_code($response) !== 200) {
                \BCC\Core\Log\Logger::warning('[StargazeMarketplaceApi] non-200 response', [
                    'address' => $address,
                    'code'    => (int) wp_remote_retrieve_response_code($response),
                ]);
                return null;
            }

            $json = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($json) || !isset($json['collections']) || !is_array($json['collections'])) {
                \BCC\Core\Log\Logger::warning('[StargazeMarketplaceApi] unexpected body shape', [
                    'address' => $address,
                ]);
                return null;
            }

            foreach ($json['collections'] as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $row = self::normalizeCollection($c);
                if ($row !== null) {
                    $out[] = $row;
                }
            }

            // `total` is the full rollup size; stop as soon as we've
            // consumed it (or the page came back short).
            $total = isset($json['total']) && is_numeric($json['total']) ? (int) $json['total'] : null;
            $seen  = ($page + 1) * self::PAGE_SIZE;
            if (count($json['collections']) < self::PAGE_SIZE || ($total !== null && $seen >= $total)) {
                break;
            }
        }

        wp_cache_set($cacheKey, $out, self::CACHE_GROUP, self::CACHE_TTL);
        return $out;
    }

    /**
     * @param array<string, mixed> $c
     * @return ProfileCollection|null
     */
    private static function normalizeCollection(array $c): ?array
    {
        $contract = strtolower(trim((string) ($c['contractAddress'] ?? '')));
        // CW-721 contract addresses on the Hub are 32-byte (59-char body)
        // bech32. Anything else is not a contract we can query.
        if (!preg_match('/^cosmos1[02-9ac-hj-np-z]{58}$/', $contract)) {
            return null;
        }

        $owned = isset($c['ownedTokensCount']) && is_numeric($c['ownedTokensCount'])
            ? (int) $c['ownedTokensCount']
            : 0;
        if ($owned < 1) {
            return null; // Rollup rows the wallet no longer holds.
        }

        $name = isset($c['name']) && is_string($c['name']) && trim($c['name']) !== ''
            ? trim($c['name'])
            : null;

        $supply = isset($c['totalTokensCount']) && is_numeric($c['totalTokensCount']) && (int) $c['totalTokensCount'] > 0
            ? (int) $c['totalTokensCount']
            : null;

        $image = null;
        if (isset($c['media']) && is_array($c['media'])) {
            foreach (['url', 'fallbackUrl'] as $k) {
                if (isset($c['media'][$k]) && is_string($c['media'][$k]) && $c['media'][$k] !== '') {
                    $image = esc_url_raw($c['media'][$k]);
                    break;
                }
            }
        }

        return [
            'contract_address' => $contract,
            'collection_name'  => $name,
            'owned_count'      => $owned,
            'total_supply'     => $supply,
            'image_url'        => $image !== '' ? $image : null,
        ];
    }
}
