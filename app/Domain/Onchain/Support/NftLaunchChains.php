<?php

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The closed list of EVM chains BCC validates NFT contracts on at launch.
 *
 * ── DECISION 7, SETTLED: ETHEREUM AND BASE, BOTH ALCHEMY-KEYED ──────────
 * This is that decision expressed once, in code, so it cannot be widened by
 * accident.
 *
 * ── WHY A CLOSED LIST AND NOT A CAPABILITY FLAG ─────────────────────────
 * {@see \BCC\Trust\Onchain\Services\Validation\EvmContractProbe} works on ANY
 * EVM RPC — `supportsInterface` is a plain `eth_call`. `NftDriverRegistry`
 * likewise offers the EVM drivers to every EVM chain. So without this list,
 * ticking `bcc_supports_nft_collections` on Polygon would put a chain nobody
 * approved straight into the intake path. On a chain with no Alchemy key it
 * would then fail at metadata time — AFTER the provider requests had already
 * been made and charged.
 *
 * The list is therefore consulted BEFORE any transport: a non-launch chain
 * costs zero provider requests.
 *
 * ── WHY chain_id_hex AND NOT THE SLUG ───────────────────────────────────
 * `chain_id_hex` is the EIP-155 chain id: the chain's identity, assigned
 * outside BCC and not editable here. `slug` is operator text — renaming a row
 * "ethereum" must not move it into launch scope, and renaming the real
 * Ethereum row must not move it out. A test pins exactly that.
 *
 * ⚠ ADDING A CHAIN HERE IS A PRODUCT DECISION, NOT A CONFIGURATION CHANGE.
 * It needs an Alchemy key for that chain (metadata has no non-Alchemy source
 * in this build) and a decision recorded alongside DECISION 7.
 */
final class NftLaunchChains
{
    /** Ethereum mainnet — EIP-155 chain id 1. */
    public const ETHEREUM = '0x1';

    /** Base — EIP-155 chain id 8453. */
    public const BASE = '0x2105';

    /**
     * The whole list. Normalised form: lowercase `0x` + no leading zeros.
     *
     * @var list<string>
     */
    private const LAUNCH_CHAIN_IDS = [
        self::ETHEREUM,
        self::BASE,
    ];

    /**
     * Is this chain inside the approved EVM launch scope?
     *
     * ⚠ FAILS CLOSED on everything it cannot positively identify: a null or
     * empty `chain_id_hex`, a non-hex value, and every family other than EVM.
     * An unidentifiable chain is not on a closed list.
     *
     * @param object $chain a `wp_bcc_chains` row
     */
    public static function isLaunchChain(object $chain): bool
    {
        if (strtolower((string) ($chain->chain_type ?? '')) !== 'evm') {
            // Cosmos and Solana have their own validators and their own gates.
            // This list answers an EVM question only, so for anything else the
            // honest answer is "not on this list".
            return false;
        }

        $normalised = self::normaliseChainId($chain->chain_id_hex ?? null);
        if ($normalised === null) {
            return false;
        }

        return in_array($normalised, self::LAUNCH_CHAIN_IDS, true);
    }

    /**
     * The launch list, for tests and for an operator-facing explanation.
     *
     * @return list<string>
     */
    public static function launchChainIds(): array
    {
        return self::LAUNCH_CHAIN_IDS;
    }

    /**
     * `0X2105`, `0x2105` and `0x00002105` are one chain; `not-hex` is none.
     *
     * Returns the canonical lowercase, zero-stripped form, or null when the
     * value is not a usable chain id.
     */
    private static function normaliseChainId(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $value = strtolower(trim($raw));
        if ($value === '' || !str_starts_with($value, '0x')) {
            return null;
        }

        $digits = substr($value, 2);
        if ($digits === '' || !ctype_xdigit($digits)) {
            return null;
        }

        $stripped = ltrim($digits, '0');

        return '0x' . ($stripped === '' ? '0' : $stripped);
    }
}
