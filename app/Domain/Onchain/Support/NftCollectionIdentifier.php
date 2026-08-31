<?php
/**
 * The single chain-aware normalization boundary for NFT collection identity.
 *
 * ── WHAT A COLLECTION'S IDENTITY IS ─────────────────────────────────────
 * Its contract or mint. Never its name, symbol, marketplace alias, slug or
 * any other metadata. Magic Eden's `popular_collections` returns a `symbol`
 * ("mad_lads"); that is a curated-feed alias, not an identity, and this
 * service refuses it.
 *
 * ── WHY CANONICALISATION IS CHAIN-AWARE ─────────────────────────────────
 * The three families disagree about what "the same identifier" means:
 *
 *   evm     hex, case-insensitive by consensus (EIP-55 case is a checksum,
 *           not identity) -> canonical form is LOWERCASE.
 *   cosmos  bech32, defined lowercase; mixed case is invalid, and the
 *           checksum is verified -> canonical form is LOWERCASE.
 *   solana  base58. The alphabet deliberately contains both cases and they
 *           are DIFFERENT bytes -> canonical form is EXACT, never folded.
 *
 * Folding Solana is not a cosmetic bug: two distinct mints can differ only
 * by case, so a case-insensitive identity silently merges two collections
 * into one. Making everything case-SENSITIVE is equally wrong in the other
 * direction — it would let `0xAB…` and `0xab…` become two rows for one EVM
 * contract. Only a per-family rule is correct, which is why this class
 * exists and why it must be the only place the rule lives.
 *
 * ── THE FAMILY IS AN INPUT, NEVER A GUESS ───────────────────────────────
 * Callers pass the family from `wp_bcc_chains.chain_type` (via
 * ChainRepository). This service never infers it from the identifier's
 * appearance. Shape-sniffing would be self-defeating: a 42-character
 * lowercase hex string is a valid EVM contract AND a valid Injective
 * bech32 address prefix-length, and a base58 mint can look like anything.
 *
 * ── FAIL CLOSED ─────────────────────────────────────────────────────────
 * `wp_bcc_chains` carries families this service has no rule for — `near`,
 * `thorchain`, `polkadot`, `utxo`. `utxo` in particular has no fetcher
 * driver and no address validator anywhere in the codebase. An unknown
 * family is REFUSED, so a future chain must add a deliberate rule here
 * before its collections can acquire an identity. Guessing "probably
 * case-sensitive" would reintroduce the EVM duplicate-row bug on the first
 * chain that turns out to be case-insensitive.
 *
 * @package BCC\Trust\Onchain\Support
 * @since PR 5a — canonical NFT collection identity
 */

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class NftCollectionIdentifier
{
    // ── Chain families this service has an explicit rule for ────────────
    public const FAMILY_EVM    = 'evm';
    public const FAMILY_COSMOS = 'cosmos';
    public const FAMILY_SOLANA = 'solana';

    /**
     * Storage width of `wp_bcc_onchain_collections.canonical_identifier`.
     * Longer input is refused rather than silently truncated — a truncated
     * identifier is a different collection.
     */
    public const MAX_LENGTH = 128;

    /** Base58 (Bitcoin/Solana alphabet): no 0, O, I or l. */
    private const BASE58 = '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/';

    /** EIP-55-agnostic EVM contract address. */
    private const EVM_HEX = '/^0x[0-9a-fA-F]{40}$/';

    /**
     * Canonicalise a collection identifier for storage and comparison.
     *
     * @param string $chainFamily `wp_bcc_chains.chain_type` — NOT inferred.
     * @param string $identifier  raw contract address / mint as supplied.
     */
    public static function canonicalize(string $chainFamily, string $identifier): NftCollectionIdentity
    {
        // Surrounding whitespace is a transport artefact, never part of an
        // identity, so trimming it is a safe formatting correction. Nothing
        // else about the input is altered before validation.
        $value = trim($identifier);

        if ($value === '') {
            return NftCollectionIdentity::refuse(NftCollectionIdentity::REASON_EMPTY);
        }

        if (strlen($value) > self::MAX_LENGTH) {
            return NftCollectionIdentity::refuse(NftCollectionIdentity::REASON_TOO_LONG);
        }

        switch ($chainFamily) {
            case self::FAMILY_EVM:
                if (preg_match(self::EVM_HEX, $value) !== 1) {
                    return NftCollectionIdentity::refuse(NftCollectionIdentity::REASON_BAD_EVM_SHAPE);
                }
                return NftCollectionIdentity::accept(strtolower($value));

            case self::FAMILY_COSMOS:
                // Full checksum verification via the shared Bech32
                // implementation — not a regex. `decode()` also rejects
                // mixed case, which bech32 forbids outright.
                if (Bech32::decode($value) === null) {
                    return NftCollectionIdentity::refuse(NftCollectionIdentity::REASON_BAD_BECH32);
                }
                return NftCollectionIdentity::accept(strtolower($value));

            case self::FAMILY_SOLANA:
                // Length 32-44 is what separates a real mint from a Magic
                // Eden symbol: every one of the 99 legacy alias rows is
                // 4-31 characters, and 46 of them contain `_` or `-`,
                // which are not in the base58 alphabet at all.
                if (preg_match(self::BASE58, $value) !== 1) {
                    return NftCollectionIdentity::refuse(NftCollectionIdentity::REASON_NOT_BASE58_MINT);
                }
                // Byte-for-byte. No case folding, ever.
                return NftCollectionIdentity::accept($value);

            default:
                return NftCollectionIdentity::refuse(NftCollectionIdentity::REASON_UNSUPPORTED_FAMILY);
        }
    }

    /**
     * True when this service has an explicit rule for the family.
     *
     * Lets a caller distinguish "this chain cannot carry collections yet"
     * from "this particular identifier is malformed" without parsing a
     * refusal reason.
     */
    public static function supportsFamily(string $chainFamily): bool
    {
        return $chainFamily === self::FAMILY_EVM
            || $chainFamily === self::FAMILY_COSMOS
            || $chainFamily === self::FAMILY_SOLANA;
    }
}
