<?php

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-chain V1 NFT-discovery failure tracker (X4).
 *
 * Lightweight rolling-window counters for `EvmFetcher::fetch_collections`
 * page attempts and transport failures. Powers the "V1 Discovery"
 * operator surface — answers "is V1 ownership discovery actually
 * working right now?" alongside the freshness/progression/CU signals
 * already on the R/Y/G card.
 *
 * Storage model:
 *   - Two rolling counters per chain in `wp_cache` (group `bcc_v1_fetch`),
 *     TTL = WINDOW_SECONDS. Restarts on cache flush — that's the right
 *     semantic for "in the last hour."
 *   - One consolidated `bcc_v1_fetch_last_errors` option (autoload=false)
 *     keyed by chain_id with the most recent error message + timestamp
 *     per chain. Bounded (~10 chains, single short error string each).
 *
 * Semantics:
 *   - "attempt" — every `alchemyNftGet` call. A fetch_collections
 *     invocation can produce up to ALCHEMY_NFT_MAX_PAGES attempts
 *     (page-level granularity is the most useful for rate diagnostics).
 *   - "failure" — every `alchemyNftGet` call that returned null
 *     (transport / parse / API error).
 *   - Successful empty result (e.g. wallet holds only spam contracts) is
 *     NOT a failure. Non-Alchemy `rpc_url` (chain on public RPC) is NOT
 *     a failure — the fetcher early-bails without calling
 *     `recordAttempt`, so those chains correctly show 0 attempts and
 *     are absent from the rate calculation.
 *
 * Write cost: one `wp_cache_incr` per attempt, one per failure, one
 * `update_option` only when a failure occurs (rare path). All
 * non-blocking, autoload=false.
 */
final class V1FetchFailureTracker
{
    public const WINDOW_SECONDS = 3600;
    public const CACHE_GROUP    = 'bcc_v1_fetch';

    private const KEY_ATTEMPTS_PREFIX = 'bcc_v1_fetch_attempts:';
    private const KEY_FAILURES_PREFIX = 'bcc_v1_fetch_failures:';

    /**
     * Option holding the consolidated last-error-per-chain map.
     * Keyed by chain_id. Shape per entry: {error: string, at: int}.
     * Public so the dashboard and any future surface can read it.
     */
    public const OPTION_LAST_ERRORS = 'bcc_v1_fetch_last_errors';

    /** Max bytes of the upstream error stored per chain. Cap so a
     *  pathological error body doesn't blow up the option payload. */
    private const ERROR_TRUNCATE_BYTES = 250;

    public static function recordAttempt(int $chainId): void
    {
        if ($chainId <= 0) {
            return;
        }
        $key = self::KEY_ATTEMPTS_PREFIX . $chainId;
        $r = wp_cache_incr($key, 1, self::CACHE_GROUP);
        if ($r === false) {
            wp_cache_set($key, 1, self::CACHE_GROUP, self::WINDOW_SECONDS);
        }
    }

    public static function recordFailure(int $chainId, string $errorMessage): void
    {
        if ($chainId <= 0) {
            return;
        }
        $key = self::KEY_FAILURES_PREFIX . $chainId;
        $r = wp_cache_incr($key, 1, self::CACHE_GROUP);
        if ($r === false) {
            wp_cache_set($key, 1, self::CACHE_GROUP, self::WINDOW_SECONDS);
        }

        // Update the last-errors map. Single autoload=false option for
        // ALL chains — keeps the option count flat regardless of chain
        // pool size. Error message is single-line + truncated so the
        // payload stays bounded.
        $errors = get_option(self::OPTION_LAST_ERRORS, []);
        if (!is_array($errors)) {
            $errors = [];
        }
        $cleaned = (string) preg_replace('/\s+/', ' ', trim($errorMessage));
        if (function_exists('mb_substr')) {
            $cleaned = mb_substr($cleaned, 0, self::ERROR_TRUNCATE_BYTES);
        } else {
            $cleaned = substr($cleaned, 0, self::ERROR_TRUNCATE_BYTES);
        }
        $errors[(string) $chainId] = [
            'error' => $cleaned,
            'at'    => time(),
        ];
        update_option(self::OPTION_LAST_ERRORS, $errors, false);
    }

    /**
     * Read per-chain rolling-window counters.
     *
     * @return array{attempts: int, failures: int}
     */
    public static function getStats(int $chainId): array
    {
        if ($chainId <= 0) {
            return ['attempts' => 0, 'failures' => 0];
        }
        $attempts = (int) (wp_cache_get(self::KEY_ATTEMPTS_PREFIX . $chainId, self::CACHE_GROUP) ?: 0);
        $failures = (int) (wp_cache_get(self::KEY_FAILURES_PREFIX . $chainId, self::CACHE_GROUP) ?: 0);
        return ['attempts' => $attempts, 'failures' => $failures];
    }

    /**
     * Read the last-error map. Returns chain_id => {error, at}.
     *
     * @return array<int, array{error: string, at: int}>
     */
    public static function getLastErrors(): array
    {
        $raw = get_option(self::OPTION_LAST_ERRORS, []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $cid => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $error = isset($entry['error']) && is_string($entry['error']) ? $entry['error'] : null;
            $at    = isset($entry['at']) && is_numeric($entry['at']) ? (int) $entry['at'] : null;
            if ($error === null || $at === null) {
                continue;
            }
            $out[(int) $cid] = ['error' => $error, 'at' => $at];
        }
        return $out;
    }
}
