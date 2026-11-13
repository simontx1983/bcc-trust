<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Fetchers\SolanaFetcher;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * F-339: is this wallet the CURRENT update authority of a Solana collection?
 *
 * The proof a Solana collection page's creator claim rests on: one DAS
 * `getAsset` on the collection mint, through the existing
 * {@see SolanaFetcher::assetResult()}, and an `authorities[]` entry whose
 * `address` is the wallet and whose `scopes` include `full`.
 *
 * Three answers, never two:
 *
 *   true   the wallet holds full authority over the collection mint today
 *   false  a DECIDED no — the asset was read and the wallet is not a full
 *          authority, or DAS documented the mint as not found
 *   null   INDETERMINATE — no Helius key, transport failure, non-2xx, rate
 *          limit, an undocumented error, or a response we cannot read
 *
 * ⚠ null is never a match and never a "no". An outage must not tell a real
 * creator that their wallet does not own their collection, and must never
 * grant a claim. The caller turns null into a retryable "couldn't verify".
 *
 * ⚠ Base58 addresses are case-sensitive: compared exactly, never folded.
 *
 * ⚠ The scope must be `full`. A narrower scope (e.g. metadata-only) is a
 * delegated authority, not the collection's owner.
 */
final class SolanaCollectionAuthority
{
    public const SCOPE_FULL = 'full';

    public function __construct(private readonly SolanaFetcher $fetcher)
    {
    }

    public function isAuthority(string $wallet, string $collectionMint): ?bool
    {
        $wallet         = trim($wallet);
        $collectionMint = trim($collectionMint);
        if ($wallet === '') {
            return false;
        }
        if ($collectionMint === '') {
            return null;
        }

        $r = $this->fetcher->assetResult($collectionMint);

        if (!$r['ok']) {
            // Only the documented getAsset not-found decides a negative;
            // every other kind is an outage or an unreadable answer.
            return ($r['kind'] ?? '') === 'not_found' ? false : null;
        }

        $asset = $r['result'];
        if (!is_array($asset)) {
            return null;
        }

        // An answer about a different asset is no answer about this one.
        if (isset($asset['id']) && $asset['id'] !== $collectionMint) {
            return null;
        }

        $authorities = $asset['authorities'] ?? null;
        if (!is_array($authorities)) {
            return null;
        }

        $unreadable = false;
        foreach ($authorities as $entry) {
            if (!is_array($entry) || !is_string($entry['address'] ?? null) || !is_array($entry['scopes'] ?? null)) {
                $unreadable = true;
                continue;
            }
            if ($entry['address'] === $wallet && in_array(self::SCOPE_FULL, $entry['scopes'], true)) {
                return true;
            }
        }

        // A malformed entry could have been this wallet's: undecided, not no.
        return $unreadable ? null : false;
    }
}
