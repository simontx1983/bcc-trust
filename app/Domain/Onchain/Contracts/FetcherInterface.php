<?php

namespace BCC\Trust\Onchain\Contracts;

use BCC\Trust\Onchain\Repositories\ChainRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contract for all chain-specific data fetchers.
 * Each driver (EVM, Cosmos, Solana) implements this interface
 * and declares which features it supports.
 *
 * @phpstan-import-type ChainRow from ChainRepository
 */
interface FetcherInterface
{
    /**
     * Check if this driver supports a specific feature.
     *
     * @param string $feature One of: 'validator', 'delegations', 'holdings_count',
     *                        'holdings_list', 'nft', 'dao', 'contracts'
     */
    public function supports_feature(string $feature): bool;

    /**
     * Fetch validator data for a given operator/wallet address.
     *
     * @param string $address Validator operator address or wallet address.
     * @return array<string, mixed> Validator data row (empty if not found).
     */
    public function fetch_validator(string $address): array;

    /**
     * Fetch the full set of validators on this chain.
     *
     * Not all chains support this — check supports_feature('validator')
     * first. Drivers that can't enumerate validators return []. Powers
     * the ValidatorRepository bulk upsert path (admin Refresh All
     * Chains + the per-chain refresh cron).
     *
     * @return array<int, array<string, mixed>> Normalized validator rows.
     */
    public function fetch_all_validators(): array;

    /**
     * Enrich a single validator row with up-to-date provider data
     * (commission %, last-block-signed, uptime, identity, etc.).
     *
     * Richer variant of fetch_validator() with skip-if-fresh logic
     * built into each implementation. Used by EnrichmentScheduler's
     * cron. Drivers that can't enrich return []; non-supporting drivers
     * are filtered out upstream by supports_feature('validator').
     *
     * Not all chains support this — check supports_feature('validator')
     * first.
     *
     * @param string  $address      The validator's operator address.
     * @param ?object $existingRow  Current row from wp_bcc_onchain_validators
     *                              (passed for skip-if-fresh checks).
     * @return array<string, mixed> Validator data, or [] if not supported / not refreshable.
     */
    public function enrich_validator(string $address, ?object $existingRow = null): array;

    /**
     * Fetch the set of validators a given delegator account stakes to.
     *
     * Treats the input as a delegator (account) address, not a validator
     * operator. Drivers that don't model delegation (e.g. EVM) return [].
     *
     * @param string $delegatorAddress Delegator / account address.
     * @return array<int, array{validator_address: string, shares?: string|null, amount?: float|null}>
     */
    public function fetch_delegations(string $delegatorAddress): array;

    /**
     * Count NFT tokens this wallet holds in a specific collection.
     *
     * Hot path — must be cheap. Used for gate checks on every protected
     * page load. Drivers that don't support NFT ownership queries
     * (e.g. Cosmos) return 0.
     *
     * @param string $wallet   Wallet / account address.
     * @param string $contract Collection contract / mint address.
     */
    public function count_holdings(string $wallet, string $contract): int;

    /**
     * Enumerate all NFTs this wallet holds across all collections on this chain.
     *
     * Cold path — expensive. Runs on wallet-verify or profile-view, cached
     * above the fetcher (see HoldingsService). Drivers paginate internally
     * up to a safety cap and flag `truncated: true` if more exist.
     *
     * @param string  $wallet Wallet / account address.
     * @param ?string $cursor Provider-specific pagination cursor. Pass null for first page.
     * @return array{items: list<array{contract_address: string, token_id: string, chain_id: int, collection_name: ?string, name: ?string, image_url: ?string, metadata_uri: ?string, token_standard: ?string}>, truncated: bool, cursor: ?string}
     */
    public function list_holdings(string $wallet, ?string $cursor = null): array;

    /**
     * Fetch NFT collection data for a wallet (collections created by this address).
     *
     * @param string $walletAddress Creator wallet address.
     * @param int    $chainId       Chain ID (FK to bcc_chains.id). 0 = use fetcher's chain.
     * @return array<int, array<string, mixed>> Array of normalized collection data rows.
     */
    public function fetch_collections(string $walletAddress, int $chainId = 0): array;

    /**
     * Fetch top collections for the chain's global leaderboard.
     * Not all chains support this — check supports_feature('top_collections') first.
     *
     * @param int $limit Max collections to return.
     * @return array<int, array<string, mixed>> Normalized collection rows matching bulkUpsert() shape.
     */
    public function fetch_top_collections(int $limit = 100): array;

    /**
     * Get the chain object this fetcher is configured for.
     *
     * @return ChainRow Chain row from wp_bcc_chains.
     */
    public function get_chain(): object;
}
