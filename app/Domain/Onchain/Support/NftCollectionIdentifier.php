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
 *   solana  base58 decoding to EXACTLY 32 bytes. The alphabet deliberately
 *           contains both cases and they are DIFFERENT bytes -> canonical
 *           form is the encoded text, EXACT, never folded.
 *
 * Each family is validated, not pattern-matched. Cosmos verifies the real
 * bech32 checksum; Solana decodes the value and requires a 32-byte public
 * key. A shape regex would accept `str_repeat('a', 44)` as a mint — it
 * decodes to 33 bytes and is not a key.
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

    /**
     * A Solana public key is exactly 32 bytes. Base58 encodes 32 bytes as
     * 32-44 characters, so this is a cheap PRE-FILTER only — the authority
     * is {@see Base58::decode()} plus the byte-length check below.
     */
    private const BASE58_SHAPE = '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/';

    /** Solana public keys are Ed25519 points / PDAs — always 32 bytes. */
    public const SOLANA_KEY_BYTES = 32;

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
                // Cheap shape pre-filter, then the real test. The pattern
                // alone is NOT the rule: `str_repeat('a', 32)` and
                // `str_repeat('a', 44)` both match it and decode to 24 and
                // 33 bytes respectively, so neither is a public key.
                if (preg_match(self::BASE58_SHAPE, $value) !== 1) {
                    return NftCollectionIdentity::refuse(NftCollectionIdentity::REASON_NOT_BASE58_MINT);
                }

                // Decode the whole value and require exactly 32 bytes.
                // Deliberately NOT an Ed25519 on-curve check: program-derived
                // addresses are chosen to be OFF-curve, and a collection can
                // legitimately be a PDA. 32 bytes is the real invariant.
                if (Base58::decodedLength($value) !== self::SOLANA_KEY_BYTES) {
                    return NftCollectionIdentity::refuse(NftCollectionIdentity::REASON_NOT_BASE58_MINT);
                }

                // The canonical identity is the ENCODED text, byte-for-byte —
                // never the decoded bytes, and never case-folded.
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

    /**
     * True when `$candidate` names the same collection as `$canonicalTarget`.
     *
     * ── WHY EQUALITY NEEDS ITS OWN METHOD ───────────────────────────────
     * PR 5a gave storage one chain-aware rule but left COMPARISON to
     * whoever needed it, and three call sites independently reached for
     * `strtolower()`. On EVM and Cosmos that happens to agree with the
     * canonical rule, so nobody noticed. On Solana it does not: base58 is
     * case-SENSITIVE, so folding a mint produces a different key, and a
     * folded comparison can never match what Solana DAS actually returns.
     * That is the whole PR 5b defect — a comparison that could only ever
     * be false, whose `0` was then read as a confident "you don't hold it".
     *
     * So equality lives HERE, beside the rule it depends on, and every
     * identity comparison routes through it.
     *
     * ── A REFUSED CANDIDATE IS NOT A MATCH, NEVER A FALLBACK ────────────
     * When `$candidate` cannot be canonicalised this returns `false` — it
     * does NOT degrade to a case-insensitive compare. Falling back would
     * reintroduce exactly the drift this method exists to remove, and it
     * would do so silently, on the one path where being wrong is worst.
     *
     * ── THE TARGET MUST ALREADY BE CANONICAL ────────────────────────────
     * Callers pass an identifier that has already been through
     * {@see canonicalize()} — in practice `canonical_identifier` straight
     * out of the collections row, validated by GateIdentityResolver.
     * Canonicalising it again on every iteration of a holdings loop would
     * be wasted work.
     *
     * This fails CLOSED if that contract is broken: a non-canonical target
     * simply never equals a canonical candidate, so the answer is `false`
     * (no match) rather than an accidental true. Pinned by a test.
     *
     * @param string $chainFamily     `wp_bcc_chains.chain_type` — NOT inferred.
     * @param string $canonicalTarget already-canonical identity to match against.
     * @param string $candidate       raw value from a provider, cache or row.
     */
    public static function matches(string $chainFamily, string $canonicalTarget, string $candidate): bool
    {
        if ($canonicalTarget === '' || $candidate === '') {
            return false;
        }

        $identity = self::canonicalize($chainFamily, $candidate);

        if (!$identity->isAccepted()) {
            return false;
        }

        return $identity->canonical() === $canonicalTarget;
    }
}
