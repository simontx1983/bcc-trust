<?php
/**
 * API Retry Helper
 *
 * Wraps wp_remote_get/post with retry logic, exponential backoff,
 * and 429 rate-limit handling. Every external HTTP call in the plugin
 * should go through this class to ensure resilient API consumption.
 *
 * Retry policy:
 *   - Retries on: timeouts, network errors (WP_Error), 5xx, 429
 *   - Does NOT retry on: 4xx (except 429)
 *   - Backoff: exponential (2s, 5s, 15s) with optional jitter
 *   - 429: respects Retry-After header, falls back to 15s
 *
 * @package BCC\Trust\Onchain\Support
 */

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class ApiRetry
{
    // ── Defaults ────────────────────────────────────────────────────────────
    const DEFAULT_MAX_RETRIES   = 3;
    const DEFAULT_BACKOFF_BASE  = 2;      // seconds
    const DEFAULT_BACKOFF_MAX   = 30;     // seconds
    const DEFAULT_429_FALLBACK  = 15;     // seconds if no Retry-After header
    const BACKOFF_MULTIPLIER    = 2.5;    // 2s → 5s → 12.5s → 30s (capped)

    /**
     * Execute an HTTP request with automatic retry on transient failures.
     *
     * @param callable $fn        Must return a WP HTTP response array or WP_Error.
     *                            Signature: fn(): array|WP_Error
     * @param array<string, mixed> $options   {
     *     @type int    $max_retries   Max retry attempts (default 3).
     *     @type string $label         Human-readable label for logging (e.g. "Cosmos LCD /validators").
     *     @type int    $chain_id      Chain ID for circuit breaker integration.
     * }
     * @return array<string, mixed>|\WP_Error  The final HTTP response or WP_Error after all retries exhausted.
     */
    public static function request(callable $fn, array $options = [])
    {
        $maxRetries = (int) ($options['max_retries'] ?? self::DEFAULT_MAX_RETRIES);
        $label      = $options['label'] ?? 'API call';
        $chainId    = (int) ($options['chain_id'] ?? 0);

        // Circuit breaker: check before attempting
        if ($chainId > 0 && CircuitBreaker::isOpen($chainId)) {
            self::log("BLOCKED by circuit breaker: {$label} (chain {$chainId})");
            return new \WP_Error('circuit_breaker_open', "Circuit breaker open for chain {$chainId}");
        }

        // Per-chain budget: check before attempting
        if ($chainId > 0
            && class_exists('\\BCC\\Trust\\Onchain\\Services\\EnrichmentScheduler')
            && \BCC\Trust\Onchain\Services\EnrichmentScheduler::isChainBudgetExceeded($chainId)
        ) {
            self::log("BLOCKED by chain budget: {$label} (chain {$chainId})");
            return new \WP_Error('chain_budget_exceeded', "API budget exceeded for chain {$chainId}");
        }

        $attempt     = 0;
        $lastResponse = null;

        try {

        while ($attempt <= $maxRetries) {
            $lastResponse = $fn();

            // ── Budget accounting ────────────────────────────────────────
            // Charge per-attempt (5xx retries each count) so a failing
            // chain cannot loop through the budget for free via retries.
            // WP_Error = pre-connect failure (DNS, SSRF block, pre-send
            // timeout) → do not charge.
            //
            // 4xx (except 429) → also do NOT charge: validation rejection
            // is almost always a bug in our code (malformed payload, wrong
            // path, missing auth). Charging budget for our own bugs lets a
            // single regression burn through MAX_API_CALLS_PER_CHAIN in
            // seconds and block legitimate traffic for the cache TTL —
            // which is exactly how the Stargaze CW-721 regression
            // (unpadded base64) presented: every test click returned
            // chain_budget_exceeded for 10 minutes after the bug fix
            // landed. 429 is explicitly rate-limit signalling — the
            // server processed the request and is throttling, so it
            // counts as legitimate consumption. 5xx still counts because
            // most providers (Alchemy CU, etc.) bill the request even
            // when the response is a server-side failure.
            if (!is_wp_error($lastResponse) && $chainId > 0 && class_exists('\\BCC\\Trust\\Onchain\\Services\\EnrichmentScheduler')) {
                $code = (int) wp_remote_retrieve_response_code($lastResponse);
                $isClientErrorNon429 = ($code >= 400 && $code < 500 && $code !== 429);
                if (!$isClientErrorNon429) {
                    \BCC\Trust\Onchain\Services\EnrichmentScheduler::trackApiCall($chainId);
                }
            }

            // ── Success path ────────────────────────────────────────────
            if (!is_wp_error($lastResponse)) {
                $code = (int) wp_remote_retrieve_response_code($lastResponse);

                if ($code >= 200 && $code < 300) {
                    // Success — record for circuit breaker
                    if ($chainId > 0) {
                        CircuitBreaker::recordSuccess($chainId);
                    }
                    return $lastResponse;
                }

                // ── 429 Rate Limited ────────────────────────────────────
                if ($code === 429) {
                    $delay = self::parseRetryAfter($lastResponse);
                    self::log(sprintf(
                        'RATE LIMITED (429) %s — attempt %d/%d, waiting %ds',
                        $label, $attempt + 1, $maxRetries + 1, $delay
                    ));

                    if ($chainId > 0) {
                        CircuitBreaker::recordFailure($chainId);
                    }

                    // Do NOT sleep — return immediately and let the caller
                    // (EnrichmentScheduler) decide whether to skip this chain.
                    // Sleeping in a cron loop can exceed PHP max_execution_time.
                    return $lastResponse;
                }

                // ── 5xx Server Error — retryable with short delay ───────
                if ($code >= 500) {
                    self::log(sprintf(
                        'SERVER ERROR (%d) %s — attempt %d/%d',
                        $code, $label, $attempt + 1, $maxRetries + 1
                    ));

                    if ($chainId > 0) {
                        CircuitBreaker::recordFailure($chainId);
                    }

                    // Retryable: exhaust attempts before returning the failure.
                    if ($attempt < $maxRetries) {
                        $attempt++;
                        $delay = min(
                            self::DEFAULT_BACKOFF_MAX,
                            (int) (self::DEFAULT_BACKOFF_BASE * ($attempt ** self::BACKOFF_MULTIPLIER))
                        );
                        // Cap cron-context sleep at 3s so we cannot exceed typical
                        // per-job budgets even at max_retries=3.
                        $delay = min($delay, 3);
                        if ($delay > 0) {
                            sleep($delay);
                        }
                        continue;
                    }
                    return $lastResponse;
                }

                // ── 4xx Client Error (not 429) — NOT retryable ──────────
                if ($code >= 400) {
                    self::log(sprintf(
                        'CLIENT ERROR (%d) %s — not retrying',
                        $code, $label
                    ));
                    return $lastResponse;
                }

                // Other codes (3xx, etc.) — return as-is
                return $lastResponse;
            }

            // ── WP_Error (timeout, DNS, connection refused) — retryable ─
            $errorMsg = $lastResponse->get_error_message();
            self::log(sprintf(
                'NETWORK ERROR %s — attempt %d/%d: %s',
                $label, $attempt + 1, $maxRetries + 1, $errorMsg
            ));

            if ($chainId > 0) {
                CircuitBreaker::recordFailure($chainId);
            }

            // Retryable: exhaust attempts before returning the failure.
            if ($attempt < $maxRetries) {
                $attempt++;
                $delay = min(
                    self::DEFAULT_BACKOFF_MAX,
                    (int) (self::DEFAULT_BACKOFF_BASE * ($attempt ** self::BACKOFF_MULTIPLIER))
                );
                $delay = min($delay, 3);
                if ($delay > 0) {
                    sleep($delay);
                }
                continue;
            }
            break;
        }

        return $lastResponse;

        } finally {
            // Guarantee the HALF-OPEN probe lock is released on every
            // exit path. recordSuccess/recordFailure release it too,
            // but 3xx and non-429 4xx returns above don't call either —
            // without this finally the lock would stay held until the
            // PHP worker exits, blocking the next probe attempt for
            // minutes. release() is idempotent so the double-release
            // when recordSuccess/recordFailure already ran is harmless.
            if ($chainId > 0) {
                CircuitBreaker::releaseProbe($chainId);
            }
        }
    }

    /**
     * Convenience: wp_remote_get with retry + SSRF hardening.
     *
     * SSRF defence (private-IP blocking, DNS pinning, metadata blocklist) is
     * delegated to {@see \BCC\Core\Http\SafeHttpClient}. This method adds
     * the onchain-domain layers on top: retry, circuit breaker, per-chain
     * API budget tracking.
     *
     * @param string $url     Request URL.
     * @param array<string, mixed>  $args    wp_remote_get args (timeout, headers, etc.).
     * @param array<string, mixed>  $options ApiRetry options (max_retries, label, chain_id).
     * @return array<string, mixed>|\WP_Error
     */
    public static function get(string $url, array $args = [], array $options = [])
    {
        $secured = \BCC\Core\Http\SafeHttpClient::prepareArgs($url, $args);
        if ($secured instanceof \WP_Error) {
            return $secured;
        }

        return self::request(
            fn() => wp_remote_get($url, $secured),
            $options
        );
    }

    /**
     * Convenience: wp_remote_post with retry + SSRF hardening.
     *
     * SSRF defence delegated to {@see \BCC\Core\Http\SafeHttpClient}.
     *
     * @param string $url     Request URL.
     * @param array<string, mixed>  $args    wp_remote_post args (timeout, headers, body, etc.).
     * @param array<string, mixed>  $options ApiRetry options (max_retries, label, chain_id).
     * @return array<string, mixed>|\WP_Error
     */
    public static function post(string $url, array $args = [], array $options = [])
    {
        $secured = \BCC\Core\Http\SafeHttpClient::prepareArgs($url, $args);
        if ($secured instanceof \WP_Error) {
            return $secured;
        }

        return self::request(
            fn() => wp_remote_post($url, $secured),
            $options
        );
    }

    // ── Internal ────────────────────────────────────────────────────────────

    /**
     * Parse the Retry-After header from a 429 response.
     * Returns seconds to wait. Falls back to DEFAULT_429_FALLBACK.
     *
     * @param array<string, mixed>|\WP_Error $response
     */
    private static function parseRetryAfter($response): int
    {
        $header = wp_remote_retrieve_header($response, 'retry-after');

        if (!is_string($header) || $header === '') {
            return self::DEFAULT_429_FALLBACK;
        }

        // Retry-After can be seconds (integer) or HTTP-date.
        if (is_numeric($header)) {
            return max(1, min(120, (int) $header));  // Cap at 2 minutes
        }

        // Try parsing as HTTP-date
        $timestamp = strtotime($header);
        if ($timestamp !== false && $timestamp > time()) {
            return min(120, $timestamp - time());
        }

        return self::DEFAULT_429_FALLBACK;
    }

    private static function log(string $message): void
    {
        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[ApiRetry] ' . $message);
        }
    }
}
