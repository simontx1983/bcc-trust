<?php

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * F-584: the validator status a member sees is the one the chain last
 * reported, and only while BCC can still reach that chain.
 *
 * `bcc_onchain_validators.status` is written only when a fetch returns the
 * row. When a chain's fetches keep failing, every validator on it keeps its
 * last value ("active") indefinitely. So a reader that shows the status to
 * members passes it through here, and a chain with no successful provider
 * call in STALE_AFTER reads `unknown`.
 *
 * The signal is {@see OnchainCircuitBreaker::getStaleChains()} (any
 * successful call for the chain advances it), the same one behind the admin
 * "Chain data is stale" notice. That notice warns at its own 48 h default,
 * so admins hear about a chain a day before members see `unknown`. A chain
 * with no recorded success, or an unknown chain id, counts as stale.
 *
 * Nothing is written. The stored row keeps the provider's value, so a chain
 * that answers again reads its real status on the next request.
 *
 * Raw on purpose, never passed through here: admin views, the operator's
 * own `chain_reported_status` (MeValidatorPagesEndpoint), and every writer.
 *
 * @package BCC\Trust\Onchain\Support
 */
final class ValidatorStatusProjection
{
    /** 72 hours: 18 missed runs of the 4-hourly validator index. */
    public const STALE_AFTER = 259200;

    /** What a stale chain's validators read. */
    public const UNKNOWN = 'unknown';

    /** @var array<int, bool> chain id => stale, for this request */
    private static array $stale = [];

    /** Is $chainId's data too old to show its validators' status? */
    public static function isStale(int $chainId): bool
    {
        if ($chainId <= 0) {
            return true;
        }

        return self::$stale[$chainId] ??= OnchainCircuitBreaker::getStaleChains([$chainId], self::STALE_AFTER) !== [];
    }

    /** The status a member sees: the stored one, or `unknown` on a stale chain. */
    public static function effective(string $status, int $chainId): string
    {
        return self::isStale($chainId) ? self::UNKNOWN : $status;
    }

    /**
     * The chains among $chainIds whose validators' status is current, for an
     * SQL allowlist. Unknown or non-positive ids are left out.
     *
     * @param list<int> $chainIds
     * @return list<int>
     */
    public static function currentAmong(array $chainIds): array
    {
        $current = [];
        foreach ($chainIds as $chainId) {
            $chainId = (int) $chainId;
            if (!isset($current[$chainId]) && !self::isStale($chainId)) {
                $current[$chainId] = true;
            }
        }

        return array_keys($current);
    }

    /** Forget this request's answers (tests, long-running CLI). */
    public static function reset(): void
    {
        self::$stale = [];
    }
}
