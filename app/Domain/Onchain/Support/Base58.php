<?php
/**
 * Base58 (Bitcoin / Solana alphabet) decoding.
 *
 * ── WHY A DECODER AND NOT A REGEX ───────────────────────────────────────
 * `/^[1-9A-HJ-NP-Za-km-z]{32,44}$/` proves only that a string LOOKS like
 * base58. It does not prove the value it encodes is a 32-byte Solana public
 * key. `str_repeat('a', 32)` and `str_repeat('a', 44)` both satisfy that
 * pattern and neither is a key: they decode to 24 and 33 bytes.
 *
 * Collection identity is the thing uniqueness is built on, so "looks
 * plausible" is not a strong enough test. This decodes the whole value and
 * lets the caller require exactly 32 bytes.
 *
 * ── WHY NOT REUSE bcc-core's DECODER ────────────────────────────────────
 * `BCC\Core\Crypto\SolanaSignatureVerifier::base58Decode()` exists and is
 * correct, but it is `private`, it lives in a sibling plugin, and it is
 * built on **ext-gmp**. GMP is a hard requirement for signature
 * verification, but it is routinely ABSENT in local development — the
 * documented local Composer recipe is `--ignore-platform-req=ext-gmp`,
 * because switching PHP version in Local silently drops `php_gmp.dll`.
 * Making collection identity depend on it would mean every Solana write
 * fails closed on a developer machine, and the identity unit tests would
 * skip rather than run.
 *
 * This implementation uses no extension at all: the classic byte-array
 * long-division, which needs nothing wider than a 64-bit int
 * (58 * 255 + carry never approaches the limit).
 *
 * @package BCC\Trust\Onchain\Support
 * @since PR 5a — canonical NFT collection identity
 */

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class Base58
{
    /** Bitcoin / Solana alphabet — no 0, O, I or l. */
    public const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /**
     * Hard input ceiling.
     *
     * Decoding is O(len * bytes), so an unbounded input is a cheap way to
     * burn CPU. 64 characters is comfortably above the 44 that a 32-byte
     * value can produce, so nothing legitimate is turned away — the bound
     * exists to stop the pathological case reaching the loop at all.
     * Mirrors the pre-decode length bound in bcc-core's verifier.
     */
    public const MAX_INPUT_LENGTH = 64;

    /**
     * Decode a base58 string to raw bytes.
     *
     * @return string|null raw bytes, or null when the input is empty,
     *                     over-long, or contains a non-alphabet character.
     */
    public static function decode(string $input): ?string
    {
        $len = strlen($input);

        if ($len === 0 || $len > self::MAX_INPUT_LENGTH) {
            return null;
        }

        // Byte-array long division: bytes := bytes * 58 + digit, big-endian.
        $bytes = [0];

        for ($i = 0; $i < $len; $i++) {
            $digit = strpos(self::ALPHABET, $input[$i]);
            if ($digit === false) {
                return null;
            }

            $carry = (int) $digit;
            for ($j = count($bytes) - 1; $j >= 0; $j--) {
                $carry     += 58 * $bytes[$j];
                $bytes[$j]  = $carry & 0xff;
                $carry    >>= 8;
            }
            while ($carry > 0) {
                array_unshift($bytes, $carry & 0xff);
                $carry >>= 8;
            }
        }

        // Strip the leading zeros produced by the seed value and by
        // multiplication...
        $firstNonZero = 0;
        $count        = count($bytes);
        while ($firstNonZero < $count && $bytes[$firstNonZero] === 0) {
            $firstNonZero++;
        }
        $significant = array_slice($bytes, $firstNonZero);

        // ...then restore the ones that are genuine data: in base58 a
        // leading '1' IS a zero byte, and dropping them would silently
        // shorten the decoded value (this is what makes the all-zero
        // System Program id decode to 32 bytes rather than 0).
        $leadingOnes = 0;
        while ($leadingOnes < $len && $input[$leadingOnes] === '1') {
            $leadingOnes++;
        }

        $out = str_repeat("\x00", $leadingOnes);
        foreach ($significant as $b) {
            $out .= chr($b);
        }

        return $out;
    }

    /**
     * Decoded byte length, or null when the input is not valid base58.
     *
     * Convenience for callers that only care about the size — e.g. "is
     * this a 32-byte Solana public key?".
     */
    public static function decodedLength(string $input): ?int
    {
        $decoded = self::decode($input);

        return $decoded === null ? null : strlen($decoded);
    }
}
