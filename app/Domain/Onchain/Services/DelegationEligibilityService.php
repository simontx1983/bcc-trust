<?php
/**
 * Live delegation-eligibility verdicts for validator/delegator communities.
 *
 * Eligibility uses the LIVE LCD check (honest, like the NFT gate), not the
 * seed-once wp_bcc_onchain_delegations index: one
 * {@see CosmosFetcher::fetch_delegations_result} call per verified wallet
 * returns the wallet's ENTIRE delegation set, so evaluating N delegator
 * communities costs the same wallet fetches as evaluating one.
 *
 * Caching + write-through:
 *   - 5-minute per-wallet object-cache entry so the join endpoint, the
 *     buckets list, and the revoke sweep don't hammer the LCD for the
 *     same wallet. Transport failures (null) are NEVER cached — no
 *     poisoning UNKNOWN into a fake "no delegations."
 *   - Every successful live fetch writes through to
 *     {@see DelegationRepository::replaceForWalletLink}, keeping the
 *     who-to-follow recommender's index warm as a side effect (that
 *     table is otherwise seed-once at wallet-verify).
 *
 * Verdict semantics: see {@see DelegationVerdict}. Stake comparison uses
 * the highest REAL single-wallet delegated amount (mirror of the NFT
 * gate's max-single-wallet balance semantics, deliberately NOT a
 * cross-wallet sum).
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\DelegationRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use BCC\Trust\Onchain\ValueObjects\DelegationVerdict;
use BCC\Trust\Onchain\ValueObjects\ValidatorGatedGroupConfig;

if (!defined('ABSPATH')) {
    exit;
}

final class DelegationEligibilityService
{
    private const CACHE_GROUP = 'bcc_onchain';

    /** Per-wallet delegation-set cache TTL (seconds). */
    private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

    /**
     * Verdict for a single delegator-community config.
     */
    public function verdictFor(int $userId, ValidatorGatedGroupConfig $config): DelegationVerdict
    {
        $verdicts = $this->verdictsForUser($userId, [$config]);
        return $verdicts[$config->groupId]
            ?? DelegationVerdict::unknown($config->minStake, null);
    }

    /**
     * Batched verdicts across N delegator communities. Wallet fetches are
     * amortized per chain: each verified Cosmos wallet is fetched ONCE
     * (cache-first) and its delegation set evaluated against every config
     * on that chain.
     *
     * Configs whose chain can't be resolved to a delegations-capable
     * Cosmos fetcher yield UNKNOWN (fail closed — never a fake
     * "ineligible" that could feed a revoke).
     *
     * @param list<ValidatorGatedGroupConfig> $configs
     * @return array<int, DelegationVerdict> keyed by group_id
     */
    public function verdictsForUser(int $userId, array $configs): array
    {
        if ($userId <= 0 || $configs === []) {
            return [];
        }

        $byChain = [];
        foreach ($configs as $cfg) {
            $byChain[$cfg->chainId][] = $cfg;
        }

        $wallets = WalletRepository::getForUser($userId, null, true);

        $out = [];
        foreach ($byChain as $chainId => $chainConfigs) {
            $fetcher = $this->resolveDelegationsFetcher($chainId);
            if ($fetcher === null) {
                foreach ($chainConfigs as $cfg) {
                    $out[$cfg->groupId] = DelegationVerdict::unknown($cfg->minStake, null);
                }
                continue;
            }

            $chainWallets = [];
            foreach ($wallets as $w) {
                if ((int) $w->chain_id === $chainId) {
                    $chainWallets[] = $w;
                }
            }

            if ($chainWallets === []) {
                // No verified wallet on this chain — definite non-delegator,
                // not an outage.
                foreach ($chainConfigs as $cfg) {
                    $out[$cfg->groupId] = DelegationVerdict::ineligible($cfg->minStake, 0.0);
                }
                continue;
            }

            // One (cache-first) LCD call per wallet; null = transport
            // failure for that wallet.
            $setsByWallet = [];
            foreach ($chainWallets as $w) {
                $setsByWallet[] = $this->delegationsForWallet($fetcher, $w, $chainId);
            }

            foreach ($chainConfigs as $cfg) {
                $out[$cfg->groupId] = $this->evaluateConfig($cfg, $setsByWallet);
            }
        }

        return $out;
    }

    /**
     * Reduce per-wallet delegation sets to a verdict for one config.
     *
     * Per wallet:
     *   - set === null            → UNKNOWN wallet (transport failure).
     *   - matched row, real amt   → real stake for this wallet.
     *   - matched row, null amt   → UNKNOWN wallet (row exists but the
     *                               amount is unreadable — cannot compare
     *                               against minStake).
     *   - no matched row          → real 0 (the LCD answered; this wallet
     *                               does not delegate to the validator).
     *
     * @param list<array<int, array{validator_address: string, shares: string|null, amount: float|null}>|null> $setsByWallet
     */
    private function evaluateConfig(ValidatorGatedGroupConfig $config, array $setsByWallet): DelegationVerdict
    {
        $best       = null;
        $sawUnknown = false;

        foreach ($setsByWallet as $set) {
            if ($set === null) {
                $sawUnknown = true;
                continue;
            }

            $stake = 0.0;
            $matchedWithoutAmount = false;
            foreach ($set as $row) {
                if (strtolower($row['validator_address']) !== $config->operatorAddress) {
                    continue;
                }
                if ($row['amount'] === null) {
                    $matchedWithoutAmount = true;
                    continue;
                }
                if ((float) $row['amount'] > $stake) {
                    $stake = (float) $row['amount'];
                }
            }

            if ($matchedWithoutAmount && $stake <= 0.0) {
                // The wallet DOES delegate, but we can't read how much —
                // cannot prove it clears (or fails) the dust gate.
                $sawUnknown = true;
                continue;
            }

            if ($best === null || $stake > $best) {
                $best = $stake;
            }
        }

        if ($best !== null && $best >= $config->minStake) {
            return DelegationVerdict::eligible($config->minStake, $best);
        }
        if ($sawUnknown) {
            return DelegationVerdict::unknown($config->minStake, $best);
        }
        return DelegationVerdict::ineligible($config->minStake, $best ?? 0.0);
    }

    /**
     * Resolve the chain to a delegations-capable Cosmos fetcher, or null.
     * Non-Cosmos chains are a V1 cut (the gate requires chain_type='cosmos').
     */
    private function resolveDelegationsFetcher(int $chainId): ?CosmosFetcher
    {
        $chain = ChainRepository::getById($chainId);
        if ($chain === null || (string) $chain->chain_type !== 'cosmos') {
            return null;
        }
        if (!FetcherFactory::has_driver((string) $chain->chain_type)) {
            return null;
        }

        $fetcher = FetcherFactory::make_for_chain($chain);
        if (!$fetcher instanceof CosmosFetcher || !$fetcher->supports_feature('delegations')) {
            return null;
        }
        return $fetcher;
    }

    /**
     * Cache-first live delegation set for one verified wallet.
     *
     * null (transport failure) is returned but NEVER cached — the next
     * call retries. Successful fetches (including empty sets) are cached
     * for 5 minutes and written through to DelegationRepository so the
     * recommender index stays warm.
     *
     * @param object{id: string|int, wallet_address: string} $wallet
     * @return array<int, array{validator_address: string, shares: string|null, amount: float|null}>|null
     */
    private function delegationsForWallet(CosmosFetcher $fetcher, object $wallet, int $chainId): ?array
    {
        $walletLinkId = (int) $wallet->id;
        $cacheKey     = 'delegations:' . $walletLinkId;

        $cached = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if (is_array($cached)) {
            /** @var array<int, array{validator_address: string, shares: string|null, amount: float|null}> $cached */
            return $cached;
        }

        $rows = $fetcher->fetch_delegations_result((string) $wallet->wallet_address);
        if ($rows === null) {
            // Don't cache transport failures.
            return null;
        }

        wp_cache_set($cacheKey, $rows, self::CACHE_GROUP, self::CACHE_TTL);

        // Opportunistic write-through: keeps wp_bcc_onchain_delegations
        // (the shared-validator-backing recommender's source) warm. A
        // write failure must never break the live gate — log and move on.
        try {
            DelegationRepository::replaceForWalletLink($walletLinkId, $chainId, $rows);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] delegation write-through failed', [
                'wallet_link_id' => $walletLinkId,
                'error'          => $e->getMessage(),
            ]);
        }

        return $rows;
    }
}
