<?php
/**
 * Bech32 encoding/decoding with checksum verification.
 *
 * Shared implementation used by ClaimService (address comparison) and
 * CosmosFetcher (valoper/valcons address derivation). Consolidates
 * previously duplicated logic and adds the checksum verification that
 * both call-sites were missing.
 *
 * @package BCC\Trust\Onchain\Support
 */

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

class Bech32
{
    /** @var string Bech32 character set (BIP-173). */
    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    /**
     * Decode a bech32 string into its HRP and 5-bit data values.
     *
     * Performs full checksum verification. Returns null on any invalid
     * input (bad characters, failed checksum, too short, etc.).
     *
     * @return array{hrp: string, data: int[]}|null  Data values are 5-bit; does NOT include the 6-char checksum.
     */
    public static function decode(string $bech32): ?array
    {
        // Bech32 is case-insensitive but must not mix cases.
        $lower = strtolower($bech32);
        $upper = strtoupper($bech32);
        if ($bech32 !== $lower && $bech32 !== $upper) {
            return null;
        }
        $bech32 = $lower;

        // Split at the last '1' separator.
        $lastOne = strrpos($bech32, '1');
        if ($lastOne === false || $lastOne < 1) {
            return null;
        }

        $hrp      = substr($bech32, 0, $lastOne);
        $dataPart = substr($bech32, $lastOne + 1);

        // Need at least 6 characters for the checksum.
        if (strlen($dataPart) < 6) {
            return null;
        }

        // Decode data characters to 5-bit values.
        $values = [];
        for ($i = 0, $len = strlen($dataPart); $i < $len; $i++) {
            $pos = strpos(self::CHARSET, $dataPart[$i]);
            if ($pos === false) {
                return null;
            }
            $values[] = $pos;
        }

        // Verify checksum: polymod(hrpExpand(hrp) || values) must equal 1.
        if (self::polymod(array_merge(self::hrpExpand($hrp), $values)) !== 1) {
            return null;
        }

        // Strip the 6-value checksum from the data.
        $data = array_slice($values, 0, -6);

        return [
            'hrp'  => $hrp,
            'data' => $data,
        ];
    }

    /**
     * Encode an HRP and raw binary data into a bech32 string.
     *
     * @param string $hrp  Human-readable part (lowercase).
     * @param string $data Raw binary data (e.g. 20-byte address).
     */
    public static function encode(string $hrp, string $data): string
    {
        $unpacked = unpack('C*', $data);
        if ($unpacked === false) {
            throw new \RuntimeException('Bech32::encode: unpack() failed on binary data.');
        }

        $values = self::convertBits(
            array_values($unpacked),
            8,
            5,
            true
        );

        $polymod = self::polymod(array_merge(
            self::hrpExpand($hrp),
            $values,
            [0, 0, 0, 0, 0, 0]
        )) ^ 1;

        $checksum = [];
        for ($i = 0; $i < 6; $i++) {
            $checksum[] = ($polymod >> (5 * (5 - $i))) & 31;
        }

        $result = $hrp . '1';
        foreach (array_merge($values, $checksum) as $v) {
            $result .= self::CHARSET[$v];
        }

        return $result;
    }

    /**
     * Decode a bech32 address to raw address bytes (binary string).
     *
     * Convenience method that decodes, verifies the checksum, and
     * converts 5-bit data back to 8-bit bytes in a single call.
     *
     * @return string|null Raw bytes or null on invalid input / failed checksum.
     */
    public static function decodeToBytes(string $bech32): ?string
    {
        $decoded = self::decode($bech32);
        if ($decoded === null) {
            return null;
        }

        $bytes = self::convertBits($decoded['data'], 5, 8, false);

        $raw = '';
        foreach ($bytes as $b) {
            $raw .= chr($b);
        }

        return $raw;
    }

    // ── Internal helpers ────────────────────────────────────────────────

    /**
     * Bech32 polymod checksum function (BIP-173).
     *
     * @param int[] $values
     */
    public static function polymod(array $values): int
    {
        $gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        $chk = 1;
        foreach ($values as $v) {
            $b = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $v;
            for ($i = 0; $i < 5; $i++) {
                $chk ^= (($b >> $i) & 1) ? $gen[$i] : 0;
            }
        }
        return $chk;
    }

    /**
     * Expand the HRP for checksum computation.
     *
     * @return int[]
     */
    public static function hrpExpand(string $hrp): array
    {
        $expand = [];
        $len = strlen($hrp);
        for ($i = 0; $i < $len; $i++) {
            $expand[] = ord($hrp[$i]) >> 5;
        }
        $expand[] = 0;
        for ($i = 0; $i < $len; $i++) {
            $expand[] = ord($hrp[$i]) & 31;
        }
        return $expand;
    }

    /**
     * Convert between bit groups (e.g. 8-bit to 5-bit or vice versa).
     *
     * @param int[] $data
     * @return int[]
     */
    public static function convertBits(array $data, int $fromBits, int $toBits, bool $pad): array
    {
        $acc    = 0;
        $bits   = 0;
        $result = [];
        $maxv   = (1 << $toBits) - 1;

        foreach ($data as $value) {
            $acc = ($acc << $fromBits) | $value;
            $bits += $fromBits;
            while ($bits >= $toBits) {
                $bits -= $toBits;
                $result[] = ($acc >> $bits) & $maxv;
            }
        }

        if ($pad && $bits > 0) {
            $result[] = ($acc << ($toBits - $bits)) & $maxv;
        }

        return $result;
    }
}
