<?php

namespace BCC\Trust\Onchain\Fetchers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\Contracts\FetcherInterface;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Services\NftSpamFilter;
use BCC\Trust\Onchain\Services\V1FetchFailureTracker;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Workers\NftEthIndexerWorker;

/**
 * EVM Chain Fetcher
 *
 * V1 NFT discovery path. `fetch_collections()` queries Alchemy's NFT API v3
 * (`getContractsForOwner`) to enumerate the contracts a wallet currently
 * holds NFTs from — ERC-721 and ERC-1155 in the same response. Result feeds
 * `wp_bcc_onchain_collections` (the read-time TTL'd table behind the Verify
 * Collections admin page, user gallery refresh, and claim-flow candidate
 * lists).
 *
 * Ownership semantics: current holdings as Alchemy's index shows them. The
 * V2 confirmation-gated indexer (`NftEthIndexerWorker`) lives on a separate,
 * trust-grade path against `wp_bcc_nft_holdings` and uses
 * `alchemy_getAssetTransfers` — that path is unchanged. See
 * `project_v1_v2_nft_path_separation.md` in memory.
 *
 * Spam filtering is defense-in-depth, free-tier-compatible:
 *   1. `NftSpamContractRepository` rule table (operator overrides; RULE_ALLOW
 *      wins over upstream signals, RULE_DENY drops unconditionally).
 *   2. Alchemy `isSpam` field on the response (preserved even when the paid
 *      `excludeFilters[]=SPAM` query param has no effect on free tier).
 *   3. `NftSpamFilter::isSpam` heuristic match on collection name.
 *
 * CU accounting: each `getContractsForOwner` page = 320 CU against the same
 * `wp_bcc_chain_checkpoints.cu_used_today` gauge the V2 indexer uses. Hard
 * pagination cap of `ALCHEMY_NFT_MAX_PAGES` keeps a single fetch bounded so
 * a whale wallet can't drain the daily budget in one call.
 *
 * Etherscan dependency on this method is fully removed. `BCC_ETHERSCAN_API_KEY`
 * is still required by `SignalFetcher` (wallet-age / tx-count signals); that
 * code path is unrelated and out of scope.
 *
 * @phpstan-import-type ChainRow from ChainRepository
 *
 * @phpstan-type AlchemyContractRow array{
 *     address?: string,
 *     name?: string|null,
 *     symbol?: string|null,
 *     totalSupply?: string|null,
 *     tokenType?: string|null,
 *     isSpam?: bool|null,
 *     spamClassifications?: list<string>|null,
 *     openSeaMetadata?: array{
 *         floorPrice?: float|null,
 *         imageUrl?: string|null,
 *         collectionName?: string|null
 *     }|null
 * }
 */
class EvmFetcher implements FetcherInterface
{
    private const HTTP_TIMEOUT          = 12;
    private const ALCHEMY_MAX_COUNT     = 1000; // alchemy_getAssetTransfers per-page cap (spike 1)
    private const ALCHEMY_NFT_PAGE_SIZE = 100;  // getContractsForOwner pageSize (max per Alchemy docs)
    private const ALCHEMY_NFT_MAX_PAGES = 5;    // Hard cap. 5 × 100 = 500 contracts/wallet/fetch — covers whales without unbounded CU spend.
    private const ALCHEMY_NFT_CU_PER_CALL = 320;

    /** @var ChainRow */
    private object $chain;

    /** @param ChainRow $chain */
    public function __construct(object $chain)
    {
        $this->chain = $chain;
    }

    /** @return ChainRow */
    public function get_chain(): object
    {
        return $this->chain;
    }

    public function last_fetch_error(): ?string
    {
        // EVM has no validator-fetch path (fetch_all_validators returns [],
        // supports_feature('validator') is false). No transport calls,
        // no error to surface.
        return null;
    }

    public function supports_feature(string $feature): bool
    {
        // 'holdings_list' deliberately omitted — EVM gallery requires
        // Alchemy/Moralis, committed in a later PR. count_holdings uses
        // plain eth_call balanceOf() and works with any RPC endpoint.
        return in_array($feature, ['collection', 'top_collections', 'holdings_count'], true);
    }

    /** @return array<string, mixed> */
    public function fetch_validator(string $address): array
    {
        return []; // EVM chains don't expose validator data via Etherscan API
    }

    /** @return array<int, array<string, mixed>> */
    public function fetch_all_validators(): array
    {
        // EVM chains have no indexable validator enumeration in BCC's model.
        // The supports_feature('validator') gate above keeps callers from
        // reaching this; the empty return is a belt-and-suspenders
        // contract satisfaction so the interface remains uniform.
        return [];
    }

    /** @return array<string, mixed> */
    public function enrich_validator(string $address, ?object $existingRow = null): array
    {
        // Same posture as fetch_all_validators — non-supporting driver.
        return [];
    }

    /** @return array<int, array{validator_address: string, shares?: string|null, amount?: float|null}> */
    public function fetch_delegations(string $delegatorAddress): array
    {
        return []; // No native account-level delegation on EVM.
    }

    // ── Holdings (per-wallet ERC-721 balance) ──────────────────────────────

    /**
     * ERC-721 balanceOf(address) via eth_call. Works with any EVM RPC
     * endpoint — admin must configure chain->rpc_url with a full API key
     * (the seeded Alchemy URLs ship with /v2/ placeholder and will fail
     * until a key is appended).
     *
     * ERC-721 only by design. ERC-1155 contracts expose
     * `balanceOf(address, uint256)` which requires a token_id and a
     * different selector (0x00fdd58e); answering "owns any token in
     * the contract" via RPC would need either `balanceOfBatch` over
     * a known token-id list or per-id calls — neither composes cleanly
     * here. The holder gate sidesteps this entirely by routing 1155
     * lookups through `NftHoldingsRepository::countVisibleByContract()`
     * (the persistent transfer index, see HoldingsService::countFromCacheOrFetch).
     */
    public function count_holdings(string $wallet, string $contract): ?int
    {
        $addr = strtolower($wallet);
        if (!preg_match('/^0x[a-f0-9]{40}$/', $addr)) {
            // Malformed input, not a provider outage — a definite "no
            // holdings for this nonsense address." Stays a real 0.
            return 0;
        }

        $to = strtolower($contract);
        if (!preg_match('/^0x[a-f0-9]{40}$/', $to)) {
            return 0;
        }

        // balanceOf(address) selector = first 4 bytes of keccak256("balanceOf(address)")
        $selector = '70a08231';
        $paddedWallet = str_pad(substr($addr, 2), 64, '0', STR_PAD_LEFT);
        $data = '0x' . $selector . $paddedWallet;

        // ethCall returns null ONLY on transport/RPC error (WP_Error,
        // non-200, JSON-RPC error envelope, unparseable body). A
        // SUCCESSFUL balanceOf always returns a hex string — "0x0" decodes
        // to a real zero below. So null here = UNKNOWN, never "owns none".
        $result = $this->ethCall($to, $data);
        if ($result === null) {
            return null;
        }

        $hex = ltrim(substr($result, 2), '0');
        if ($hex === '') {
            // Successful call, all-zero hex word = genuine balance of 0.
            return 0;
        }

        // Realistic NFT balances fit in PHP_INT_MAX comfortably.
        // A pathological return > 2^63 would wrap, but that's
        // a broken contract, not user data worth preserving.
        return (int) hexdec($hex);
    }

    /**
     * Full NFT enumeration on EVM needs Alchemy getNFTs / Moralis / similar.
     * Stubbed until the provider decision lands — see HoldingsService.
     *
     * @return array{items: list<array{contract_address: string, token_id: string, chain_id: int, collection_name: ?string, name: ?string, image_url: ?string, metadata_uri: ?string, token_standard: ?string}>, truncated: bool, cursor: ?string}
     */
    public function list_holdings(string $wallet, ?string $cursor = null): array
    {
        return ['items' => [], 'truncated' => false, 'cursor' => null];
    }

    /**
     * Fetch ERC-721/1155 Transfer events between two block heights via
     * Alchemy's `alchemy_getAssetTransfers`. Used by NftEthIndexerWorker
     * to ingest confirmation-gated mints/transfers/burns.
     *
     * Per spike 1: each call costs 120 CU flat regardless of category
     * filter or maxCount. The worker tracks CU usage in
     * `wp_bcc_chain_checkpoints.cu_used_today`; this method does NOT
     * track it (separation of concerns — fetcher only fetches).
     *
     * Pagination: a non-null `page_key` in the return value means more
     * results exist; the caller passes it back via `$pageKey` to
     * continue. `page_key === null` means the range is fully drained.
     *
     * FAILURE SEMANTICS: returns `null` on any path where we cannot
     * prove the range was read — transport error (WP_Error), non-JSON /
     * malformed body, JSON-RPC error member, or a missing `result`
     * (e.g. a public RPC that doesn't implement the Alchemy method).
     * Historically these collapsed into the empty-success shape, which
     * the worker read as "range fully drained" and ADVANCED THE
     * CHECKPOINT over blocks it never read — silent data loss on any
     * transient provider failure. `null` is now the explicit "unknown,
     * do not advance" signal; a genuinely-empty `result.transfers`
     * stays the `['transfers' => [], 'page_key' => null]` success shape.
     *
     * Returns events in the indexer's normalized TransferEvent shape:
     * see NftHoldingsIndexer phpstan-type for the contract.
     *
     * @return array{transfers: list<array<string, mixed>>, page_key: string|null}|null
     */
    public function fetch_transfers_since(int $fromBlock, int $toBlock, ?string $pageKey = null): ?array
    {
        $chainIdForLog = (int) ($this->chain->id ?? 0);

        $rpcUrl = (string) ($this->chain->rpc_url ?? '');
        if (!$rpcUrl || str_ends_with($rpcUrl, '/v2/')) {
            // Misconfiguration, not an empty range — the caller must not
            // treat this as "drained".
            \BCC\Core\Log\Logger::error('[EVM Fetcher] alchemy_getAssetTransfers: rpc_url missing or placeholder', [
                'chain_id' => $chainIdForLog,
            ]);
            return null;
        }
        if ($fromBlock < 0 || $toBlock < $fromBlock) {
            // Caller bug — surface as failure rather than fake success.
            \BCC\Core\Log\Logger::error('[EVM Fetcher] alchemy_getAssetTransfers: invalid block range', [
                'chain_id'   => $chainIdForLog,
                'from_block' => $fromBlock,
                'to_block'   => $toBlock,
            ]);
            return null;
        }

        $chainId = (int) $this->chain->id;

        $params = [
            'fromBlock'        => '0x' . dechex($fromBlock),
            'toBlock'          => '0x' . dechex($toBlock),
            'category'         => ['erc721', 'erc1155'],
            'withMetadata'     => true,
            'excludeZeroValue' => false,
            'maxCount'         => '0x' . dechex(self::ALCHEMY_MAX_COUNT),
            'order'            => 'asc',
        ];
        if (is_string($pageKey) && $pageKey !== '') {
            $params['pageKey'] = $pageKey;
        }

        $body = wp_json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'alchemy_getAssetTransfers',
            'params'  => [$params],
        ]);

        $response = ApiRetry::post($rpcUrl, [
            'timeout'   => self::HTTP_TIMEOUT,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => $body,
            'sslverify' => true,
        ], [
            'label'    => 'EVM alchemy_getAssetTransfers',
            'chain_id' => $chainId,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[EVM Fetcher] alchemy_getAssetTransfers transport error: ' . $response->get_error_message(), [
                'chain_id' => $chainId,
            ]);
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json)) {
            \BCC\Core\Log\Logger::error('[EVM Fetcher] alchemy_getAssetTransfers: non-JSON response body', [
                'chain_id' => $chainId,
            ]);
            return null;
        }
        if (isset($json['error'])) {
            // JSON-RPC error member — includes public RPCs that don't
            // support the Alchemy-proprietary method.
            $msg = is_array($json['error']) && isset($json['error']['message']) && is_string($json['error']['message'])
                ? $json['error']['message']
                : '(no error.message)';
            \BCC\Core\Log\Logger::error('[EVM Fetcher] alchemy_getAssetTransfers RPC error: ' . $msg, [
                'chain_id' => $chainId,
            ]);
            return null;
        }
        if (!isset($json['result']) || !is_array($json['result'])) {
            \BCC\Core\Log\Logger::error('[EVM Fetcher] alchemy_getAssetTransfers: missing result field', [
                'chain_id' => $chainId,
            ]);
            return null;
        }

        $rawTransfers = $json['result']['transfers'] ?? [];
        $newPageKey   = $json['result']['pageKey'] ?? null;
        if (!is_array($rawTransfers)) {
            \BCC\Core\Log\Logger::error('[EVM Fetcher] alchemy_getAssetTransfers: result.transfers not an array', [
                'chain_id' => $chainId,
            ]);
            return null;
        }

        $transfers = [];
        foreach ($rawTransfers as $t) {
            if (!is_array($t)) {
                continue;
            }
            $contract = strtolower((string) ($t['rawContract']['address'] ?? ''));
            $tokenId  = (string) ($t['tokenId'] ?? '');
            if ($contract === '' || $tokenId === '') {
                continue;
            }

            $category = (string) ($t['category'] ?? '');
            $tokenStd = $category === 'erc721' ? 'ERC-721'
                : ($category === 'erc1155' ? 'ERC-1155' : null);

            $blockNumHex = (string) ($t['blockNum'] ?? '0x0');
            $blockNum    = $blockNumHex !== '' ? (int) hexdec(substr($blockNumHex, 2)) : 0;

            // For 1155, value is amount; for 721, value is 1 (or 0 in some
            // edge cases — coerce to 1 since holding a 721 means balance=1).
            $rawValue = $t['value'] ?? 1;
            $amount   = $category === 'erc1155'
                ? max(1, (int) (is_numeric($rawValue) ? $rawValue : 1))
                : 1;

            $blockTimestamp = (string) ($t['metadata']['blockTimestamp'] ?? '');
            $confirmedAt    = self::isoToMysqlUtc($blockTimestamp);

            // Alchemy returns hex token_id; convert to decimal for storage
            // consistency (standard practice — exchanges/marketplaces
            // store token_id as decimal strings).
            $tokenIdDecimal = self::hexToDecimalString($tokenId);

            $transfers[] = [
                'chain_id'         => $chainId,
                'contract_address' => $contract,
                'token_id'         => $tokenIdDecimal,
                'token_standard'   => $tokenStd,
                'from_address'     => strtolower((string) ($t['from'] ?? '')),
                'to_address'       => strtolower((string) ($t['to'] ?? '')),
                'amount'           => $amount,
                'block_number'     => $blockNum,
                'confirmed_at'     => $confirmedAt,
                'collection_name'  => isset($t['asset']) ? (string) $t['asset'] : null,
            ];
        }

        return [
            'transfers' => $transfers,
            'page_key'  => is_string($newPageKey) && $newPageKey !== '' ? $newPageKey : null,
        ];
    }

    /**
     * Fetch enrichment metadata for a single (contract, tokenId) pair
     * via Alchemy's `getNFTMetadata` JSON-RPC method. Used by the
     * V2 Phase 1c NftEnrichmentService to backfill name + image_url
     * + collection_name on persistent holdings rows AND by the V2 Phase 6
     * §H1 NFT-piece view-model builder for the §3.7 metadata superset
     * (description, image_url_thumb, attributes[]).
     *
     * Returns null on transport / 4xx / 5xx; non-null with
     * partially-empty fields on success (Alchemy sometimes returns a
     * row with no media — we persist what's there and let the
     * gallery render with a placeholder if image_url is null).
     *
     * The Phase-6 superset is ADDITIVE — Phase-1c callers continue to
     * read `name`, `image_url`, `metadata_uri`, `collection_name`
     * unchanged. New keys: `description`, `image_url_thumb`,
     * `attributes[]`.
     *
     * @return array{
     *     name: ?string,
     *     description: ?string,
     *     image_url: ?string,
     *     image_url_thumb: ?string,
     *     metadata_uri: ?string,
     *     collection_name: ?string,
     *     attributes: list<array{trait_type: string, value: string|int|float|bool, rarity_pct?: float}>
     * }|null
     */
    public function fetchMetadataForToken(string $contract, string $tokenId): ?array
    {
        $rpcUrl = (string) ($this->chain->rpc_url ?? '');
        if (!$rpcUrl || str_ends_with($rpcUrl, '/v2/')) {
            return null;
        }
        $contractLc = strtolower($contract);
        if (!preg_match('/^0x[a-f0-9]{40}$/', $contractLc) || $tokenId === '') {
            return null;
        }

        // Alchemy expects token_id in either decimal or hex; pass our
        // canonical decimal representation and let Alchemy normalize.
        $body = wp_json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'alchemy_getNFTMetadata',
            'params'  => [[
                'contractAddress' => $contractLc,
                'tokenId'         => $tokenId,
                'refreshCache'    => false,
            ]],
        ]);
        if ($body === false) {
            return null;
        }

        $response = ApiRetry::post($rpcUrl, [
            'timeout'   => self::HTTP_TIMEOUT,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => $body,
            'sslverify' => true,
        ], [
            'label'    => 'EVM alchemy_getNFTMetadata',
            'chain_id' => (int) $this->chain->id,
        ]);

        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json) || !isset($json['result']) || !is_array($json['result'])) {
            return null;
        }
        $r = $json['result'];

        $name        = is_string($r['name'] ?? null) && $r['name'] !== '' ? $r['name'] : null;
        $description = is_string($r['description'] ?? null) && $r['description'] !== '' ? $r['description'] : null;
        $tokenUri    = is_array($r['tokenUri'] ?? null) ? ($r['tokenUri']['gateway'] ?? null) : null;

        // Pick the gateway URL of the first media entry as `image_url`,
        // and the thumbnail (if any) as `image_url_thumb`. Alchemy's
        // `media[]` format is `{gateway, thumbnailUrl?, raw?}` per asset.
        $imgUrl   = null;
        $imgThumb = null;
        if (isset($r['media']) && is_array($r['media'])) {
            foreach ($r['media'] as $m) {
                if (!is_array($m)) {
                    continue;
                }
                if ($imgUrl === null && isset($m['gateway']) && is_string($m['gateway']) && $m['gateway'] !== '') {
                    $imgUrl = $m['gateway'];
                }
                if ($imgThumb === null && isset($m['thumbnailUrl']) && is_string($m['thumbnailUrl']) && $m['thumbnailUrl'] !== '') {
                    $imgThumb = $m['thumbnailUrl'];
                }
                if ($imgUrl !== null && $imgThumb !== null) {
                    break;
                }
            }
        }
        // Fall back per §3.7 rule: when no resize is available,
        // `image_url_thumb` mirrors `image_url`.
        if ($imgThumb === null && $imgUrl !== null) {
            $imgThumb = $imgUrl;
        }

        $collName = null;
        if (isset($r['contractMetadata']) && is_array($r['contractMetadata'])) {
            $collName = is_string($r['contractMetadata']['name'] ?? null) ? $r['contractMetadata']['name'] : null;
        }

        // attributes[]: Alchemy returns these under either
        // `metadata.attributes` (legacy) or `rawMetadata.attributes`
        // (current). Both follow the OpenSea convention:
        //   [{ trait_type: string, value: string|number|bool }, ...]
        // §3.7 allows an OPTIONAL `rarity_pct`; Alchemy doesn't surface
        // rarity by default, so we omit it and let a future enricher
        // backfill via a separate code path.
        $attributes = self::extractAttributes($r);

        return [
            'name'            => $name,
            'description'     => $description,
            'image_url'       => $imgUrl,
            'image_url_thumb' => $imgThumb,
            'metadata_uri'    => is_string($tokenUri) ? $tokenUri : null,
            'collection_name' => $collName,
            'attributes'      => $attributes,
        ];
    }

    /**
     * Map Alchemy `metadata.attributes` / `rawMetadata.attributes` to
     * the §3.7 attribute shape. Returns `[]` when the source array is
     * missing or malformed (per the §3.7 "never null" rule).
     *
     * @param array<string, mixed> $alchemyResult
     * @return list<array{trait_type: string, value: string|int|float|bool, rarity_pct?: float}>
     */
    private static function extractAttributes(array $alchemyResult): array
    {
        $candidates = [];
        if (isset($alchemyResult['rawMetadata']) && is_array($alchemyResult['rawMetadata'])) {
            $rm = $alchemyResult['rawMetadata'];
            if (isset($rm['attributes']) && is_array($rm['attributes'])) {
                $candidates = $rm['attributes'];
            }
        }
        if ($candidates === [] && isset($alchemyResult['metadata']) && is_array($alchemyResult['metadata'])) {
            $md = $alchemyResult['metadata'];
            if (isset($md['attributes']) && is_array($md['attributes'])) {
                $candidates = $md['attributes'];
            }
        }

        $out = [];
        foreach ($candidates as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $traitType = $attr['trait_type'] ?? null;
            $value     = $attr['value']      ?? null;
            if (!is_string($traitType) || $traitType === '' || $value === null) {
                continue;
            }
            // Coerce scalar values; drop anything else (objects/arrays).
            if (is_string($value)) {
                $coerced = $value;
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $coerced = $value;
            } else {
                continue;
            }
            $out[] = [
                'trait_type' => $traitType,
                'value'      => $coerced,
            ];
        }
        return $out;
    }

    /**
     * Convert a hex string (with or without 0x prefix) to a decimal
     * string. Uses gmp/bcmath for arbitrary precision; falls back to
     * intval for small values when neither extension is available.
     */
    private static function hexToDecimalString(string $hex): string
    {
        $clean = strtolower(ltrim($hex, '0x'));
        if ($clean === '') {
            return '0';
        }
        if (function_exists('gmp_strval')) {
            return gmp_strval(gmp_init('0x' . $clean, 16), 10);
        }
        if (function_exists('bcadd')) {
            $dec = '0';
            for ($i = 0, $n = strlen($clean); $i < $n; $i++) {
                $dec = bcadd(bcmul($dec, '16'), (string) hexdec($clean[$i]));
            }
            return $dec;
        }
        // Fallback — only safe for small token_ids.
        return (string) hexdec($clean);
    }

    /**
     * Convert Alchemy ISO-8601 ('2024-08-01T12:34:56.000Z') to MySQL
     * DATETIME UTC ('2024-08-01 12:34:56'). Returns the current time
     * if parsing fails — confirmed_at is load-bearing for
     * indexer observability so a missing value is logged-and-defaulted
     * rather than dropped.
     */
    private static function isoToMysqlUtc(string $iso): string
    {
        if ($iso === '') {
            return current_time('mysql', true);
        }
        try {
            $dt = new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return current_time('mysql', true);
        }
    }

    /**
     * Thin JSON-RPC wrapper for read-only contract calls. Returns the
     * raw hex result string or null on any error.
     */
    private function ethCall(string $to, string $data): ?string
    {
        $rpcUrl = (string) ($this->chain->rpc_url ?? '');
        // Seeded Alchemy URLs ship as "https://eth-mainnet.g.alchemy.com/v2/"
        // with no key appended. Skip rather than fire a guaranteed 401.
        if (!$rpcUrl || str_ends_with($rpcUrl, '/v2/')) {
            return null;
        }

        $body = wp_json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'eth_call',
            'params'  => [
                ['to' => $to, 'data' => $data],
                'latest',
            ],
        ]);

        $response = ApiRetry::post($rpcUrl, [
            'timeout'   => self::HTTP_TIMEOUT,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => $body,
            'sslverify' => true,
        ], [
            'label'    => 'EVM eth_call balanceOf',
            'chain_id' => (int) $this->chain->id,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[EVM Fetcher] eth_call error: ' . $response->get_error_message());
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json) || isset($json['error'])) {
            return null;
        }

        $result = $json['result'] ?? null;
        return is_string($result) && str_starts_with($result, '0x') ? $result : null;
    }

    /**
     * Discover NFT collections currently held by an address (ERC-721 + ERC-1155).
     *
     * Strategy: page through Alchemy's
     * `GET /nft/v3/{apiKey}/getContractsForOwner` (matched grain to
     * `wp_bcc_onchain_collections` — one row per contract, not per token),
     * normalise into the upsert shape, and apply the three-tier spam chain
     * (rule table → Alchemy `isSpam` → local heuristic).
     *
     * Bounded by `ALCHEMY_NFT_MAX_PAGES`. Each page costs
     * `ALCHEMY_NFT_CU_PER_CALL` CU against the same daily budget the V2
     * indexer tracks — V1/V2 share physical quota by design (see
     * `project_v1_v2_nft_path_separation.md`).
     *
     * Returns `[]` rather than falling back to any other provider when
     * Alchemy is unreachable or the chain's `rpc_url` is not an Alchemy
     * endpoint. Fail-loud-and-empty is the locked policy — a fallback
     * with different ownership semantics would create silent drift.
     *
     * Creator/deployer role verification (claim flow) is handled
     * authoritatively downstream by `BlockchainQueryService::getEthRole()`
     * via on-chain `owner()` RPC and does NOT depend on this fetcher.
     *
     * @param string $walletAddress  Wallet address to query.
     * @param int    $chainId        Chain ID override (defaults to $this->chain->id).
     * @return array<int, array<string, mixed>> Array of normalized collection rows.
     */
    public function fetch_collections(string $walletAddress, int $chainId = 0): array
    {
        $chainId = $chainId ?: (int) $this->chain->id;

        $rpcUrl  = (string) ($this->chain->rpc_url ?? '');
        $nftBase = $this->alchemyNftBaseFromRpcUrl($rpcUrl);
        if ($nftBase === null) {
            // Fail-loud-and-empty: chains on non-Alchemy RPCs (e.g.
            // public-RPC Avalanche / BSC) have no V1 NFT discovery
            // until their rpc_url is migrated to Alchemy. Logged once
            // per call so operator can grep for the gap.
            \BCC\Core\Log\Logger::warning('[EvmFetcher.fetch_collections] chain has non-Alchemy rpc_url; V1 NFT discovery unavailable', [
                'chain_id' => $chainId,
                'chain'    => (string) ($this->chain->slug ?? ''),
            ]);
            return [];
        }

        // Ensure a checkpoint row exists so the shared CU gauge accounts
        // for V1 spend on this chain even if the V2 worker has never
        // ticked here. State stays whatever it is (`disabled` by default,
        // or whatever the operator set) — V1 doesn't gate on chain
        // state, only on CU budget.
        ChainCheckpointRepository::ensureExists($chainId);

        $dailyBudget = defined('BCC_ETH_DAILY_RPC_BUDGET')
            ? (int) constant('BCC_ETH_DAILY_RPC_BUDGET')
            : NftEthIndexerWorker::DEFAULT_DAILY_BUDGET;

        $native = (string) ($this->chain->native_token ?? 'ETH');

        /** @var array<string, array<string, mixed>> $contracts keyed by lowercase contract address (final dedupe pass) */
        $contracts = [];
        $pageKey   = null;

        for ($pageNum = 0; $pageNum < self::ALCHEMY_NFT_MAX_PAGES; $pageNum++) {
            // CU budget gate. Shared physical quota with V2. If the
            // remaining budget can't cover one more page, stop — the
            // partial result we have so far is honest data (rather
            // than silently dropping rows to a half-paginated state
            // the operator can't see in the gauge).
            $remaining = ChainCheckpointRepository::cuRemainingForToday($chainId, $dailyBudget);
            if ($remaining < self::ALCHEMY_NFT_CU_PER_CALL) {
                \BCC\Core\Log\Logger::warning('[EvmFetcher.fetch_collections] daily CU budget exhausted; returning partial result', [
                    'chain_id'      => $chainId,
                    'pages_fetched' => $pageNum,
                    'remaining_cu'  => $remaining,
                ]);
                break;
            }

            $page = $this->alchemyNftGet(
                $nftBase . '/getContractsForOwner',
                [
                    'owner'        => $walletAddress,
                    'withMetadata' => 'true',
                    'pageSize'     => (string) self::ALCHEMY_NFT_PAGE_SIZE,
                    'excludeSpam'  => 'true', // paid-tier hint; free tier still returns isSpam per row (defense-in-depth below)
                    'pageKey'      => $pageKey,
                ]
            );
            ChainCheckpointRepository::addCuUsage($chainId, self::ALCHEMY_NFT_CU_PER_CALL);

            if ($page === null) {
                // Transport / parse failure already logged with body
                // preserved by alchemyNftGet. Fail loud-and-empty for
                // the page; return what we have if anything.
                break;
            }

            $rows = isset($page['contracts']) && is_array($page['contracts']) ? $page['contracts'] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                /** @var AlchemyContractRow $row */
                $normalized = $this->normalizeAlchemyContract($row, $chainId, $native);
                if ($normalized === null) {
                    continue;
                }
                $key = strtolower((string) $normalized['contract_address']);
                if ($key === '' || isset($contracts[$key])) {
                    continue;
                }
                $contracts[$key] = $normalized;
            }

            $pageKey = isset($page['pageKey']) && is_string($page['pageKey']) && $page['pageKey'] !== ''
                ? $page['pageKey']
                : null;
            if ($pageKey === null) {
                break;
            }
        }

        if ($contracts === []) {
            return [];
        }

        return array_values($contracts);
    }

    /**
     * Three-tier spam-aware normalisation. Returns null to drop the
     * contract entirely. The order is:
     *
     *   1. NftSpamContractRepository rule table — RULE_ALLOW overrides
     *      everything (operator whitelist), RULE_DENY drops unconditionally.
     *   2. Alchemy `isSpam` boolean — drops if true and no RULE_ALLOW.
     *   3. NftSpamFilter::isSpam — heuristic regex match on collection
     *      name; already consults the rule table internally so RULE_ALLOW
     *      remains a hard escape hatch.
     *
     * Tokens without a recognisable ERC-721 / ERC-1155 type are dropped
     * (Alchemy occasionally returns `UNKNOWN` or `NO_SUPPORTED_NFT_STANDARD`
     * for proxies and oddball contracts).
     *
     * @param AlchemyContractRow $row
     * @return array<string, mixed>|null
     */
    private function normalizeAlchemyContract(array $row, int $chainId, string $native): ?array
    {
        $contractAddr = isset($row['address']) && is_string($row['address']) ? $row['address'] : '';
        if ($contractAddr === '') {
            return null;
        }

        $tokenStandard = $this->mapAlchemyTokenType($row['tokenType'] ?? null);
        if ($tokenStandard === null) {
            return null;
        }

        $alchemyName   = isset($row['name']) && is_string($row['name']) ? $row['name'] : null;
        $openSea       = isset($row['openSeaMetadata']) && is_array($row['openSeaMetadata']) ? $row['openSeaMetadata'] : [];
        $openSeaName   = isset($openSea['collectionName']) && is_string($openSea['collectionName']) ? $openSea['collectionName'] : null;
        $collectionName = $alchemyName !== null && $alchemyName !== '' ? $alchemyName : $openSeaName;

        // Spam tier 1: rule table.
        $rule = NftSpamContractRepository::getRule($chainId, $contractAddr);
        if ($rule === NftSpamContractRepository::RULE_DENY) {
            return null;
        }

        if ($rule !== NftSpamContractRepository::RULE_ALLOW) {
            // Spam tier 2: Alchemy upstream signal.
            $alchemyIsSpam = isset($row['isSpam']) && $row['isSpam'] === true;
            if ($alchemyIsSpam) {
                return null;
            }

            // Spam tier 3: local heuristic (re-consults rule table cheaply).
            if (NftSpamFilter::isSpam($chainId, $contractAddr, $collectionName)) {
                return null;
            }
        }

        $floorPrice = isset($openSea['floorPrice']) && is_numeric($openSea['floorPrice'])
            ? (float) $openSea['floorPrice']
            : null;
        $imageUrl   = isset($openSea['imageUrl']) && is_string($openSea['imageUrl']) && $openSea['imageUrl'] !== ''
            ? $openSea['imageUrl']
            : null;

        $totalSupply = isset($row['totalSupply']) && is_string($row['totalSupply']) && $row['totalSupply'] !== ''
            ? $row['totalSupply']
            : null;

        return [
            'contract_address'   => $contractAddr,
            'collection_name'    => $collectionName,
            'chain_id'           => $chainId,
            'token_standard'     => $tokenStandard,
            'total_supply'       => $totalSupply,
            'floor_price'        => $floorPrice,
            'floor_currency'     => $native,
            'total_volume'       => null,
            'unique_holders'     => null, // requires getOwnersForContract (480 CU/contract) — out of scope for this fetch
            'listed_percentage'  => null,
            'royalty_percentage' => null,
            'metadata_storage'   => null,
            'image_url'          => $imageUrl,
        ];
    }

    /**
     * Map Alchemy's `tokenType` enum to the dashed form already in
     * `wp_bcc_onchain_collections`. Returns null for unrecognised types
     * so the caller drops the row.
     */
    private function mapAlchemyTokenType(?string $tokenType): ?string
    {
        if ($tokenType === 'ERC721') {
            return 'ERC-721';
        }
        if ($tokenType === 'ERC1155') {
            return 'ERC-1155';
        }
        return null;
    }

    // ── Bulk Collection Indexing ───────────────────────────────────────────

    /**
     * Fetch top NFT collections for this EVM chain via Reservoir API.
     *
     * Reservoir provides free-tier access (no API key, 4 req/sec) to the same
     * data shown on etherscan.io/nft-top-contracts: name, floor, volume,
     * holders, supply, image.
     *
     * @param int $limit Number of top collections to fetch (max 100 per call).
     * @return array<int, array<string, mixed>> Array of normalized collection data rows.
     */
    /**
     * Contract-level metadata via Alchemy NFT API v3 `getContractMetadata`.
     * Powers `NftEnrichmentService` collection-row backfill — holds the
     * "what is this contract?" enrichment for the discovery rows that
     * `CollectionRepository::ensureExistsBatch` writes when the holdings
     * indexer first sees a contract.
     *
     * Endpoint: `https://{network}.g.alchemy.com/nft/v3/{key}/getContractMetadata`
     *
     * The chain's `rpc_url` is the JSON-RPC URL (`/v2/{key}`); we derive
     * the NFT v3 base from it via a regex swap rather than hardcoding a
     * per-chain map. Same key, same vendor, same circuit-breaker chain
     * id — works for every Alchemy-backed EVM chain (ethereum, polygon,
     * arbitrum, optimism, base) without further config. Public-RPC
     * chains (avalanche, bsc, etc.) don't match the regex and return
     * null — discovery rows for those chains stay un-enriched until a
     * dedicated fetcher is added.
     *
     * @return array{
     *     collection_name: ?string,
     *     image_url: ?string,
     *     total_supply: ?int,
     *     floor_price: ?float,
     *     floor_currency: ?string,
     *     token_standard: ?string
     * }|null
     */
    public function fetchContractMetadata(string $contract): ?array
    {
        $rpcUrl = (string) ($this->chain->rpc_url ?? '');
        if ($rpcUrl === '') {
            return null;
        }
        $contractLc = strtolower($contract);
        if (!preg_match('/^0x[a-f0-9]{40}$/', $contractLc)) {
            return null;
        }

        // Derive the NFT v3 base from the JSON-RPC URL. Anchored regex
        // so a non-Alchemy rpc_url (custom RPC, public node) returns null
        // rather than producing a malformed request.
        if (!preg_match(
            '#^(https://[a-z0-9-]+\.g\.alchemy\.com)/v2/([A-Za-z0-9_-]+)/?$#',
            $rpcUrl,
            $m
        )) {
            return null;
        }
        $nftV3Base = $m[1] . '/nft/v3/' . $m[2];

        $url = $nftV3Base . '/getContractMetadata?contractAddress=' . rawurlencode($contractLc);

        $response = ApiRetry::get($url, [
            'timeout'   => self::HTTP_TIMEOUT,
            'headers'   => ['Accept' => 'application/json'],
            'sslverify' => true,
        ], [
            'label'    => 'EVM alchemy_getContractMetadata',
            'chain_id' => (int) $this->chain->id,
        ]);

        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json)) {
            return null;
        }

        // Alchemy NFT v3 returns top-level `name`, `symbol`, `tokenType`,
        // `totalSupply`, `openSeaMetadata.collectionName`, `openSeaMetadata.imageUrl`,
        // `openSeaMetadata.floorPrice`. Older v2 response shapes are
        // documented but not used here — v3 is the supported endpoint.
        $name      = is_string($json['name'] ?? null) && $json['name'] !== ''
            ? $json['name']
            : null;
        $tokenType = is_string($json['tokenType'] ?? null) && $json['tokenType'] !== ''
            ? $json['tokenType']
            : null;
        $supply    = isset($json['totalSupply']) && is_numeric($json['totalSupply'])
            ? (int) $json['totalSupply']
            : null;

        $os = isset($json['openSeaMetadata']) && is_array($json['openSeaMetadata'])
            ? $json['openSeaMetadata']
            : [];
        $imageUrl   = is_string($os['imageUrl'] ?? null) && $os['imageUrl'] !== ''
            ? $os['imageUrl']
            : null;
        $floorPrice = isset($os['floorPrice']) && is_numeric($os['floorPrice'])
            ? (float) $os['floorPrice']
            : null;
        $collName   = is_string($os['collectionName'] ?? null) && $os['collectionName'] !== ''
            ? $os['collectionName']
            : $name;

        // Normalize Alchemy's tokenType (e.g. "ERC721", "ERC1155", "NOT_A_CONTRACT")
        // to the codebase's hyphenated form used in wp_bcc_onchain_collections.
        $standardOut = null;
        if ($tokenType === 'ERC721') {
            $standardOut = 'ERC-721';
        } elseif ($tokenType === 'ERC1155') {
            $standardOut = 'ERC-1155';
        }

        $native = is_string($this->chain->native_token ?? null) && $this->chain->native_token !== ''
            ? (string) $this->chain->native_token
            : 'ETH';

        return [
            'collection_name' => $collName,
            'image_url'       => $imageUrl,
            'total_supply'    => $supply,
            'floor_price'     => $floorPrice,
            'floor_currency'  => $floorPrice !== null ? $native : null,
            'token_standard'  => $standardOut,
        ];
    }

    public function fetch_top_collections(int $limit = 100): array
    {
        // Reservoir's public API (api.reservoir.tools / api-{chain}.reservoir.tools)
        // was sunset in 2024 after the OpenSea acquisition; the DNS no longer
        // resolves and every call returned a curl-6 WP_Error. Rather than swap
        // in another single-vendor "top collections by volume" feed (same risk
        // class — every NFT data API has churned hard in the last 3 years),
        // EVM collection discovery is now driven by user holdings: the
        // NftHoldingsIndexer ingests transfers via Alchemy and bridges new
        // (chain, contract) pairs into wp_bcc_onchain_collections via
        // CollectionRepository::ensureExistsBatch, then NftEnrichmentService
        // backfills metadata using EvmFetcher::fetchContractMetadata against
        // Alchemy NFT v3 (one vendor, already-configured key, no marketing
        // discovery surface to maintain).
        //
        // This method intentionally returns [] so the existing
        // ChainRefreshService::index_collections cron loop keeps running but
        // is a no-op for EVM chains. Cosmos and Solana keep their own working
        // top-collections fetchers via this same interface.
        return [];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Derive the Alchemy NFT API v3 base URL from the chain's JSON-RPC URL.
     *
     * Maps `https://{network}.g.alchemy.com/v2/{key}` to
     *      `https://{network}.g.alchemy.com/nft/v3/{key}`.
     *
     * Returns `null` for any URL that isn't a recognisable Alchemy v2
     * endpoint — public RPCs (Avalanche on api.avax.network, BSC on
     * bsc-dataseed.binance.org) flow this branch and the caller
     * fail-loud-and-empties for the chain. Strict regex prevents
     * api-key leakage to non-Alchemy hosts.
     */
    private function alchemyNftBaseFromRpcUrl(string $rpcUrl): ?string
    {
        if ($rpcUrl === '') {
            return null;
        }
        if (!preg_match('~^(https://[a-z0-9.-]+\.g\.alchemy\.com)/v2/([A-Za-z0-9_-]+)/?$~', $rpcUrl, $m)) {
            return null;
        }
        return $m[1] . '/nft/v3/' . $m[2];
    }

    /**
     * GET an Alchemy NFT API v3 endpoint. Returns the decoded JSON body
     * (associative array) or `null` on transport / parse / shape failure.
     *
     * Preserves upstream error bodies in the log — mirrors the
     * `fetchHeadBlock` observability pattern so a misconfigured chain
     * surfaces the actual cause ("MATIC_MAINNET is not enabled for this
     * app") instead of a generic "collection fetch failed."
     *
     * @param array<string, scalar|null> $queryParams
     * @return array<string, mixed>|null
     */
    private function alchemyNftGet(string $url, array $queryParams): ?array
    {
        $filtered = [];
        foreach ($queryParams as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $filtered[$k] = (string) $v;
        }
        $fullUrl  = add_query_arg($filtered, $url);
        $chainId  = (int) $this->chain->id;

        // X4 attempt counter — every alchemyNftGet invocation is an
        // "attempt" regardless of outcome. Per-page granularity is the
        // right scope for failure-rate diagnostics (a fetch_collections
        // call may produce 1–5 attempts).
        V1FetchFailureTracker::recordAttempt($chainId);

        $response = ApiRetry::get($fullUrl, [
            'timeout'   => self::HTTP_TIMEOUT,
            'sslverify' => true,
        ], [
            'label'    => 'Alchemy NFT getContractsForOwner',
            'chain_id' => $chainId,
        ]);

        if (is_wp_error($response)) {
            $errStr = 'transport: ' . $response->get_error_message();
            \BCC\Core\Log\Logger::error('[EvmFetcher.alchemyNftGet] transport failed', [
                'chain_id' => $chainId,
                'error'    => $response->get_error_message(),
            ]);
            V1FetchFailureTracker::recordFailure($chainId, $errStr);
            return null;
        }

        $rawBody = wp_remote_retrieve_body($response);
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            // Most commonly: Alchemy returning a plain-text body like
            // "MATIC_MAINNET is not enabled for this app." Preserve it
            // verbatim (truncated) so the log row tells the operator
            // exactly what to fix.
            $bodyExcerpt = function_exists('mb_substr')
                ? mb_substr((string) preg_replace('/\s+/', ' ', trim($rawBody)), 0, 200)
                : substr((string) preg_replace('/\s+/', ' ', trim($rawBody)), 0, 200);
            \BCC\Core\Log\Logger::error('[EvmFetcher.alchemyNftGet] non-JSON response', [
                'chain_id'     => $chainId,
                'http_status'  => (int) wp_remote_retrieve_response_code($response),
                'body_excerpt' => $bodyExcerpt,
            ]);
            V1FetchFailureTracker::recordFailure(
                $chainId,
                'non-JSON HTTP ' . (int) wp_remote_retrieve_response_code($response) . ': ' . $bodyExcerpt
            );
            return null;
        }

        // JSON-RPC-style error object on the v3 NFT API uses { error: ... }
        // shape; surface it cleanly.
        if (isset($decoded['error'])) {
            $errMsg = is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error']);
            \BCC\Core\Log\Logger::error('[EvmFetcher.alchemyNftGet] API error', [
                'chain_id' => $chainId,
                'error'    => is_string($errMsg) ? $errMsg : '(unencodable)',
            ]);
            V1FetchFailureTracker::recordFailure(
                $chainId,
                'API error: ' . (is_string($errMsg) ? $errMsg : '(unencodable)')
            );
            return null;
        }

        return $decoded;
    }
}
