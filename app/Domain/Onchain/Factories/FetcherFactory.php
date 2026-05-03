<?php

namespace BCC\Trust\Onchain\Factories;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\Contracts\FetcherInterface;
use BCC\Trust\Onchain\Fetchers\EvmFetcher;
use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Fetchers\SolanaFetcher;
use BCC\Trust\Onchain\Fetchers\ThorchainFetcher;
use BCC\Trust\Onchain\Fetchers\PolkadotFetcher;
use BCC\Trust\Onchain\Fetchers\NearFetcher;
use BCC\Trust\Onchain\Repositories\ChainRepository;

/**
 * Chain Fetcher Factory
 *
 * Resolves chain_id → chain_type → driver class.
 *
 * @phpstan-import-type ChainRow from ChainRepository
 */
class FetcherFactory
{
    /**
     * Map of chain_type → driver class name.
     *
     * @var array<string, string>
     */
    private static array $drivers = [
        'evm'        => EvmFetcher::class,
        'cosmos'     => CosmosFetcher::class,
        'solana'     => SolanaFetcher::class,
        'thorchain'  => ThorchainFetcher::class,
        'polkadot'   => PolkadotFetcher::class,
        'near'       => NearFetcher::class,
    ];

    /**
     * Create a fetcher by chain_id.
     *
     * Looks up the chain row from the DB, then delegates to make_for_chain().
     *
     * @param int $chainId FK to bcc_chains.id.
     * @return FetcherInterface
     * @throws \InvalidArgumentException If chain not found or no driver.
     */
    public static function make(int $chainId): FetcherInterface
    {
        $chain = ChainRepository::getById($chainId);

        if (!$chain) {
            throw new \InvalidArgumentException("Chain not found: {$chainId}");
        }

        return self::make_for_chain($chain);
    }

    /**
     * Create a fetcher from a chain object.
     *
     * SSRF defence (private-IP blocking, DNS pinning, metadata blocklist) is
     * applied at the HTTP-call layer by {@see \BCC\Core\Http\SafeHttpClient},
     * which every fetcher reaches through {@see \BCC\Trust\Onchain\Support\ApiRetry}.
     * No URL validation is performed here so that constructing a fetcher stays
     * a pure, side-effect-free operation safe to call during admin renders.
     *
     * @param ChainRow $chain
     * @throws \InvalidArgumentException If no driver exists for this chain type.
     */
    public static function make_for_chain(object $chain): FetcherInterface
    {
        $type = $chain->chain_type;

        if (!isset(self::$drivers[$type])) {
            throw new \InvalidArgumentException("No fetcher driver for chain type: {$type}");
        }

        $class = self::$drivers[$type];

        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Fetcher driver class not loaded: {$class}");
        }

        /** @var FetcherInterface */
        return new $class($chain);
    }

    /**
     * Check if a driver exists for a given chain type.
     */
    public static function has_driver(string $chain_type): bool
    {
        return isset(self::$drivers[$chain_type]) && class_exists(self::$drivers[$chain_type]);
    }
}
