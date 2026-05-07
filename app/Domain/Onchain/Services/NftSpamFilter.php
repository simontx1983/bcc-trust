<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Write-path NFT spam filter (V2 Phase 1a).
 *
 * Resolution order:
 *   1. Repository rule wins:
 *      'allow' → not spam (overrides every heuristic)
 *      'deny'  → spam
 *   2. If no rule → run heuristic checks against the contract's
 *      collection name (when available). Patterns target known
 *      airdrop / phishing styles ("visit X to claim", "free mint
 *      airdrop"). Heuristics are intentionally conservative; admins
 *      curate the deny list as the primary filter.
 *
 * Filterable via the WP filter `bcc_nft_spam_heuristic_patterns` so
 * the pattern list can be tuned without a redeploy.
 *
 * @phpstan-type SpamCheckInput array{
 *     chain_id: int,
 *     contract_address: string,
 *     collection_name?: ?string
 * }
 */
final class NftSpamFilter
{
    /** Default heuristic regexes (case-insensitive). */
    private const DEFAULT_PATTERNS = [
        '~\b(?:claim|airdrop|free\s*mint|visit|reward|bonus|gift)\b~i',
        '~\.(?:com|io|net|xyz|fi|app)\b~i',                    // bare domains in collection names
        '~(?:visit|go\s*to|check)\s+\w+\.(?:com|io|net|xyz)~i', // "visit example.com"
    ];

    /**
     * Decide whether a (chain, contract) pair should be flagged as
     * spam at the indexer write path. Returns true when the row should
     * be persisted with metadata_status = STATUS_SPAM (admin-only).
     */
    public static function isSpam(int $chainId, string $contract, ?string $collectionName = null): bool
    {
        if ($chainId <= 0 || $contract === '') {
            return false;
        }

        // 1. Repository rule beats every heuristic.
        $rule = NftSpamContractRepository::getRule($chainId, $contract);
        if ($rule === NftSpamContractRepository::RULE_ALLOW) {
            return false;
        }
        if ($rule === NftSpamContractRepository::RULE_DENY) {
            return true;
        }

        // 2. No explicit rule — check heuristics if we have a name to match.
        if ($collectionName === null || $collectionName === '') {
            return false;
        }

        $patterns = apply_filters('bcc_nft_spam_heuristic_patterns', self::DEFAULT_PATTERNS);
        if (!is_array($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }
            // Suppress PCRE warnings on a malformed user-supplied pattern;
            // failing closed (treat as no match) is the right default.
            $matched = @preg_match($pattern, $collectionName);
            if ($matched === 1) {
                return true;
            }
        }

        return false;
    }
}
