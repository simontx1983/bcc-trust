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
     * @param string $feature One of: 'validator', 'nft', 'dao', 'contracts'
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
