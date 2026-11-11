<?php
/**
 * A provider-reported stake / bond / token amount as a string bccomp() can take.
 *
 * Extracted from PolkadotFetcher::bondedTotal() (F-66) so every validator
 * fetcher sorts its set the same safe way. Before this, Thorchain, Solana,
 * NEAR and Cosmos each passed the raw provider value straight to bccomp().
 *
 * ⚠ bccomp() THROWS on PHP 8 when an operand is not a well-formed decimal —
 * a ValueError (or a TypeError for an array), which is an Error, not an
 * Exception. Inside the validator index cron that used to escape the
 * per-chain catch and abort the run for every chain after it. An unreadable
 * amount now sorts as 0. Scale is left at the default, so numeric
 * comparisons are unchanged.
 *
 * ⚠⚠ TWO CHECKS, AND BOTH ARE LOAD-BEARING. The regex is the correctness
 * guard: bcmath accepts only well-formed decimals, so an exponent form like
 * '1e5' passes is_numeric() and still makes bccomp() throw. is_numeric() is
 * what makes the numeric-string return type TRUE rather than merely asserted
 * — it is the narrowing the analyser can verify, and it is a real statement
 * about the value, not a suppression.
 *
 * @package BCC\Trust\Onchain\Support
 */

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class StakeAmount
{
    /**
     * @return numeric-string
     */
    public static function sortable(mixed $raw): string
    {
        if (is_int($raw)) {
            return (string) $raw;
        }

        if (
            !is_string($raw)
            || preg_match('/^\d+(\.\d+)?$/', $raw) !== 1
            || !is_numeric($raw)
        ) {
            return '0';
        }

        return $raw;
    }

    /**
     * Descending comparator over one key of provider rows, for usort().
     *
     * @param array<array-key, mixed> $a
     * @param array<array-key, mixed> $b
     */
    public static function compareDesc(array $a, array $b, string $key): int
    {
        return bccomp(self::sortable($b[$key] ?? '0'), self::sortable($a[$key] ?? '0'));
    }
}
