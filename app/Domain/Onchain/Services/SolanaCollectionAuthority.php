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
 * {@see verdict()} answers one of four; {@see isAuthority()} folds the two
 * unknowns into null, the same rule as the EVM owner() check (F-14,
 * BlockchainQueryService::evmContractOwnerVerdict):
 *
 *   authority      true   the wallet holds full authority today
 *   not_authority  false  a DIFFERENT, real address holds full authority
 *                         today — the only decided "no"
 *   unverifiable   null   the chain cannot prove anyone is the creator, and
 *                         asking again will not change that: a RENOUNCED
 *                         authority (no full authority listed, or one handed
 *                         to the System Program or the incinerator), a
 *                         delegated (non-full) scope alone, a not-found
 *                         mint, or no mint to ask about
 *   unavailable    null   we could not get a usable answer this time: no
 *                         Helius key, transport, non-2xx, rate limit, an
 *                         undocumented error, or a response we cannot read
 *
 * ⚠ Product rule (user, F-14 decision A): unknown never grants and never
 * revokes. Renouncing does not make someone "not the creator", exactly as
 * an EVM owner() of the zero address reads unknown. At claim time the two
 * unknowns read differently (user, F-339): `unavailable` is a retryable
 * "couldn't verify", `unverifiable` a final "can't confirm the creator".
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

    public const VERDICT_AUTHORITY     = 'authority';
    public const VERDICT_NOT_AUTHORITY = 'not_authority';
    public const VERDICT_UNVERIFIABLE  = 'unverifiable';
    public const VERDICT_UNAVAILABLE   = 'unavailable';

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

    /** true / false / null (unknown, either kind) — for the re-verify sweep. */
    public function isAuthority(string $wallet, string $collectionMint): ?bool
    {
        return match ($this->verdict($wallet, $collectionMint)) {
            self::VERDICT_AUTHORITY     => true,
            self::VERDICT_NOT_AUTHORITY => false,
            default                     => null,
        };
    }

    /**
     * @return self::VERDICT_*
     */
    public function verdict(string $wallet, string $collectionMint): string
    {
        $wallet         = trim($wallet);
        $collectionMint = trim($collectionMint);
        if ($wallet === '') {
            return self::VERDICT_NOT_AUTHORITY;
        }
        if ($collectionMint === '') {
            return self::VERDICT_UNVERIFIABLE;
        }

        $r = $this->fetcher->assetResult($collectionMint);

        if (!$r['ok']) {
            // A documented not-found is an answer: there is no asset whose
            // authority could prove a creator, and asking again won't make
            // one. Every other failure is this attempt's, so retryable.
            return ($r['kind'] ?? '') === 'not_found' ? self::VERDICT_UNVERIFIABLE : self::VERDICT_UNAVAILABLE;
        }

        $asset = $r['result'];
        if (!is_array($asset)) {
            return self::VERDICT_UNAVAILABLE;
        }

        // An answer about a different asset is no answer about this one.
        if (isset($asset['id']) && $asset['id'] !== $collectionMint) {
            return self::VERDICT_UNAVAILABLE;
        }

        $authorities = $asset['authorities'] ?? null;
        if (!is_array($authorities)) {
            return self::VERDICT_UNAVAILABLE;
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
                return self::VERDICT_AUTHORITY;
            }
            if (in_array($entry['address'], self::RENOUNCED_ADDRESSES, true)) {
                continue;
            }
            $otherHolder = true;
        }

        // A malformed entry could have been this wallet's: not a no.
        if ($unreadable) {
            return self::VERDICT_UNAVAILABLE;
        }

        return $otherHolder ? self::VERDICT_NOT_AUTHORITY : self::VERDICT_UNVERIFIABLE;
    }
}
