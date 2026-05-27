<?php
/**
 * WalletAddressValidator — shared address validation + display
 * helpers used across REST endpoints and AJAX handlers.
 *
 * Replaces three identical copies of the chain-type → regex match:
 *   - WalletController::validateAddressFormat (legacy AJAX path)
 *   - AuthEndpoint::validateAddressFormat (V1 REST path)
 *   - any future caller (third one would have triggered extraction
 *     under the audit rule; we extract proactively here as part of
 *     the contract-correction batch)
 *
 * Validation is format-only (regex). Cryptographic verification of
 * a signed challenge lives in BCC\Core\Crypto\WalletVerifier and is
 * not the concern of this validator.
 *
 * @package BCC\Trust\Core\Support
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class WalletAddressValidator
{
    /**
     * True when the address string syntactically matches the chain's
     * address format. Returns true for unknown chain_types provided
     * the length is plausible — better to accept and let the verifier
     * reject than to hard-code rejection of new chains.
     */
    public static function validate(string $address, string $chainType): bool
    {
        return match ($chainType) {
            'evm'    => (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', $address),
            'solana' => (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address),
            'cosmos' => (bool) preg_match('/^[a-z]{1,20}1[a-z0-9]{38,58}$/', $address),
            // SS58: base58check over (network_prefix || pubkey || checksum).
            // Polkadot mainnet (prefix 0) yields 47–48 char addresses;
            // wider Substrate prefixes (Kusama 2, Astar 5, Acala 10,
            // etc.) round out the 46–50 range. Format-only — the
            // checksum + cryptographic validity are verified by
            // @polkadot/util-crypto on the Next.js verifier route.
            'polkadot' => (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]{46,50}$/', $address),
            default  => strlen($address) >= 10 && strlen($address) <= 128,
        };
    }

    /**
     * Display-friendly truncation: "0xabcdef…1234" / "cosmos1abc…wxyz".
     * Used in API responses (`address_short`) so the frontend can render
     * a shortened identifier without re-implementing the rule.
     */
    public static function shorten(string $address): string
    {
        $len = strlen($address);
        if ($len <= 14) {
            return $address;
        }
        return substr($address, 0, 8) . '…' . substr($address, -4);
    }
}
