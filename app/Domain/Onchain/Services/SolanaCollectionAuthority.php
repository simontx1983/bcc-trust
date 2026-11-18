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
 * Three answers, the same rule as the EVM owner() check (F-14,
 * BlockchainQueryService::evmContractOwnerVerdict):
 *
 *   true   the wallet holds full authority over the collection mint today
 *   false  a DIFFERENT, real address holds full authority today — the only
 *          decided "no"
 *   null   UNKNOWN — everything else: no Helius key, transport, non-2xx,
 *          rate limit, an undocumented error, a not-found mint, a response
 *          we cannot read, an unreadable entry, and a RENOUNCED authority
 *          (no full authority listed, or one handed to the system program
 *          or the incinerator)
 *
 * ⚠ Product rule (user, F-14 decision A): unknown never grants and never
 * revokes. Renouncing does not make someone "not the creator", exactly as
 * an EVM owner() of the zero address reads unknown. The claim path turns
 * null into "couldn't verify"; the re-verify sweep keeps the claim.
 *
 * ⚠ Base58 addresses are case-sensitive: compared exactly, never folded.
 *
 * ⚠ The scope must be `full`. A narrower scope (e.g. metadata-only) is a
 * delegated authority: it neither proves the wallet is the owner nor
 * proves that someone else is.
 */
final class SolanaCollectionAuthority
{
    public const SCOPE_FULL = 'full';

    /**
     * Addresses an authority is handed to in order to renounce it: the
     * System Program (Solana's all-ones "zero" key) and the incinerator.
     * Holding them is nobody holding it.
     */
    public const RENOUNCED_ADDRESSES = [
        '11111111111111111111111111111111',
        '1nc1nerator11111111111111111111111111111111',
    ];

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

        // Every failure is unknown — a not-found mint included: a collection
        // we can no longer read proves nobody's authority, and must never
        // revoke a creator (F-14 decision A).
        if (!$r['ok']) {
            return null;
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

        $unreadable  = false;
        $otherHolder = false;
        foreach ($authorities as $entry) {
            if (!is_array($entry) || !is_string($entry['address'] ?? null) || !is_array($entry['scopes'] ?? null)) {
                $unreadable = true;
                continue;
            }
            if (!in_array(self::SCOPE_FULL, $entry['scopes'], true)) {
                continue;
            }
            if ($entry['address'] === $wallet) {
                return true;
            }
            if (in_array($entry['address'], self::RENOUNCED_ADDRESSES, true)) {
                continue;
            }
            $otherHolder = true;
        }

        // A malformed entry could have been this wallet's: unknown, not no.
        if ($unreadable) {
            return null;
        }

        return $otherHolder ? false : null;
    }
}
