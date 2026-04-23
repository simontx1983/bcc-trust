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
     * @param ChainRow $chain
     * @throws \InvalidArgumentException If no driver exists for this chain type.
     */
    public static function make_for_chain(object $chain): FetcherInterface
    {
        $type = $chain->chain_type;

        if (!isset(self::$drivers[$type])) {
            throw new \InvalidArgumentException("No fetcher driver for chain type: {$type}");
        }

        // Validate external URLs to prevent SSRF against internal hosts.
        $urls = [
            'rpc_url'      => $chain->rpc_url ?? '',
            'rest_url'     => $chain->rest_url ?? '',
            'explorer_url' => $chain->explorer_url ?? '',
        ];
        foreach ($urls as $urlField => $url) {
            if ($url !== '' && !self::isExternalUrl($url)) {
                throw new \InvalidArgumentException("Blocked internal/invalid URL in {$urlField} for chain: {$chain->slug}");
            }
        }

        $class = self::$drivers[$type];

        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Fetcher driver class not loaded: {$class}");
        }

        /** @var FetcherInterface */
        return new $class($chain);
    }

    /**
     * Validate that a URL points to an external host (not localhost/private IPs).
     */
    private static function isExternalUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            return false;
        }
        $host = $parsed['host'] ?? '';
        if (!$host) {
            return false;
        }

        // Resolve hostname to IP to defeat DNS rebinding attacks.
        $ip = filter_var($host, FILTER_VALIDATE_IP)
            ? $host
            : gethostbyname($host);

        // gethostbyname returns the hostname unchanged on failure.
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            return false; // DNS resolution failed — block.
        }

        // Block private, reserved, loopback, and link-local IPs.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // Block cloud metadata endpoints by hostname.
        $blocked_hosts = ['metadata.google.internal', 'metadata.google.com'];
        if (in_array(strtolower($host), $blocked_hosts, true)) {
            return false;
        }

        return true;
    }

    /**
     * Check if a driver exists for a given chain type.
     */
    public static function has_driver(string $chain_type): bool
    {
        return isset(self::$drivers[$chain_type]) && class_exists(self::$drivers[$chain_type]);
    }
}
