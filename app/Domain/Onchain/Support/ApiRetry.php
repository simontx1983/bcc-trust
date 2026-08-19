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
     *     @type callable $application_error  OPTIONAL. See below. Absent =
     *                                 today's behaviour, exactly.
     * }
     *
     * ── `application_error`: WHEN A 5xx IS NOT A SERVER PROBLEM ─────────
     * A cosmos LCD reports CONTRACT-level errors as HTTP 500. Asking a
     * DeFi contract a CW-721 question returns 500 with
     * `unknown variant \`num_tokens\`, expected one of \`config\`, …` —
     * the node is healthy, the query reached the contract, and the
     * contract answered. Measured on Dungeon 2026-08-19: that single
     * non-CW-721 contract cost 8 HTTP attempts and 16s of backoff, and
     * tripped the CHAIN-WIDE breaker, which then blocked 15 unrelated
     * code families.
     *
     * This layer cannot tell the difference — the distinction lives in a
     * response body it must not learn to parse. So the CALLER may supply
     * a predicate `fn(string $body, int $code): bool`. When it returns
     * TRUE for a 5xx, this method treats the response as the ANSWER it
     * is: return it immediately, DO NOT retry, and DO NOT touch the
     * circuit breaker. Domain classification then happens where it
     * belongs, on the returned body.
     *
     * IT IS OPT-IN AND NARROW. Absent (the default, and every caller
     * other than the CosmWasm smart-query path) nothing changes: 5xx
     * still retries and still counts against the breaker. It is not
     * consulted for WP_Error, timeouts, 429 or 4xx, so no transport
     * failure can be reclassified as an application answer.
     *
     * @return array<string, mixed>|\WP_Error  The final HTTP response or WP_Error after all retries exhausted.
     */
    public static function request(callable $fn, array $options = [])
    {
        $maxRetries = (int) ($options['max_retries'] ?? self::DEFAULT_MAX_RETRIES);
        $label      = $options['label'] ?? 'API call';
        $chainId    = (int) ($options['chain_id'] ?? 0);

        $isApplicationError = isset($options['application_error']) && is_callable($options['application_error'])
            ? $options['application_error']
            : null;

        // Circuit breaker: check before attempting
        if ($chainId > 0 && OnchainCircuitBreaker::isOpen($chainId)) {
            self::log("BLOCKED by circuit breaker: {$label} (chain {$chainId})");
            return new \WP_Error('circuit_breaker_open', "Circuit breaker open for chain {$chainId}");
        }

        // ┌─ PHASE X2 REMOVAL — DO NOT REINTRODUCE ─────────────────────────┐
        // │ Pre-2026-05-13: an `EnrichmentScheduler::isChainBudgetExceeded` │
        // │ pre-check used to live here. That check belongs INSIDE the     │
        // │ scheduler's own loop, not in transport — see the explanation  │
        // │ in `EnrichmentScheduler::scheduleEnrichmentBatch` and the      │
        // │ matching X2-removal comment on the post-response block below. │
        // │                                                                │
        // │ Original intent (correct, but at the wrong layer):             │
        // │   per-cycle fairness — no single chain should monopolize ONE  │
        // │   scheduler batch.                                             │
        // │                                                                │
        // │ How the abstraction leaked:                                    │
        // │   the gate was lifted into `ApiRetry::request` as a "general  │
        // │   per-chain rate limit." That conflated two responsibilities  │
        // │   at the wrong layer — transport should not consult a         │
        // │   scheduler-fairness counter.                                  │
        // │                                                                │
        // │ Why it was operationally dangerous:                            │
        // │   every ApiRetry caller (V1 fetch_collections, V2 worker,     │
        // │   ChainRefreshService) consulted a counter sized for ONE       │
        // │   scheduler batch (50/10min). V2 worker retries against a     │
        // │   degraded chain blew the counter, then V1 fetches on the     │
        // │   same chain pre-blocked here with `chain_budget_exceeded`    │
        // │   WITHOUT making the HTTP call.                                │
        // │                                                                │
        // │ User-facing symptom (discovered 2026-05-13 during the V1      │
        // │ Etherscan→Alchemy migration verification):                    │
        // │   V1 ownership discovery silently returned empty results      │
        // │   under degraded-chain retry pressure. Gallery refreshes      │
        // │   showed zero NFTs for wallets that visibly held them on the  │
        // │   chain explorer. No operator-visible signal — `fetch_        │
        // │   collections` returned `[]` in 5ms because ApiRetry          │
        // │   short-circuited before the HTTP call.                       │
        // │                                                                │
        // │ The transport-level guard that remains here is OnchainCircuit │
        // │ Breaker above. That one IS a transport concern — a circuit-  │
        // │ broken chain should fail-fast on every transport, regardless │
        // │ of caller. Per-chain fairness budget is not transport, it's   │
        // │ scheduler internals; do not re-add it here.                   │
        // └────────────────────────────────────────────────────────────────┘

        $attempt     = 0;
        $lastResponse = null;

        try {

        while ($attempt <= $maxRetries) {
            $lastResponse = $fn();

            // ┌─ PHASE X2 REMOVAL — DO NOT REINTRODUCE ─────────────────────┐
            // │ Pre-2026-05-13: a per-response `EnrichmentScheduler::      │
            // │ trackApiCall($chainId)` call lived here, charging the      │
            // │ scheduler's per-chain counter for EVERY ApiRetry response │
            // │ (success / 429 / 5xx — anything except WP_Error + 4xx).   │
            // │                                                            │
            // │ Pairing this with the pre-call gate at the top of this    │
            // │ method made `ApiRetry::request` the transport-layer       │
            // │ enforcer of a counter sized for scheduler fairness. The   │
            // │ counter is now owned exclusively by                       │
            // │ `EnrichmentScheduler::scheduleEnrichmentBatch` which      │
            // │ calls `trackApiCall` once per `enrichRow` invocation —    │
            // │ semantically "rows processed this cycle," not "HTTP calls │
            // │ made anywhere on this chain." Fairness behaviour is       │
            // │ preserved at the scope it was designed for; V1/V2 are    │
            // │ now coupled ONLY through the shared physical CU budget   │
            // │ (`wp_bcc_chain_checkpoints.cu_used_today`).               │
            // │                                                            │
            // │ The historic policy comment is preserved here for         │
            // │ archaeology — it remains correct guidance for any future │
            // │ counter that lives at this layer (none currently does):  │
            // │                                                            │
            // │   Charge per-attempt (5xx retries each count) so a       │
            // │   failing chain cannot loop through the budget for free. │
            // │   WP_Error = pre-connect failure → do not charge.        │
            // │   4xx (except 429) = code bug, not provider load → do    │
            // │   not charge (the Stargaze CW-721 unpadded-base64        │
            // │   regression burned through 50 calls in seconds and      │
            // │   blocked legitimate traffic for the cache TTL after the │
            // │   fix landed). 429 + 5xx = legitimate consumption.       │
            // └────────────────────────────────────────────────────────────┘

            // ── Success path ────────────────────────────────────────────
            if (!is_wp_error($lastResponse)) {
                $code = (int) wp_remote_retrieve_response_code($lastResponse);

                if ($code >= 200 && $code < 300) {
                    // Success — record for circuit breaker
                    if ($chainId > 0) {
                        OnchainCircuitBreaker::recordSuccess($chainId);
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
                        OnchainCircuitBreaker::recordFailure($chainId);
                    }

                    // Do NOT sleep — return immediately and let the caller
                    // (EnrichmentScheduler) decide whether to skip this chain.
                    // Sleeping in a cron loop can exceed PHP max_execution_time.
                    return $lastResponse;
                }

                // ── 5xx Server Error — retryable with short delay ───────
                if ($code >= 500) {
                    // …UNLESS the caller can tell us this 5xx is the
                    // CONTRACT's answer rather than the node's failure.
                    // Checked BEFORE the log, the breaker and the retry,
                    // because all three are wrong for an answer.
                    if ($isApplicationError !== null
                        && $isApplicationError((string) wp_remote_retrieve_body($lastResponse), $code)) {
                        self::log(sprintf(
                            'APPLICATION ERROR (%d) %s — the contract answered; not retried, breaker untouched',
                            $code, $label
                        ));

                        return $lastResponse;
                    }

                    self::log(sprintf(
                        'SERVER ERROR (%d) %s — attempt %d/%d',
                        $code, $label, $attempt + 1, $maxRetries + 1
                    ));

                    if ($chainId > 0) {
                        OnchainCircuitBreaker::recordFailure($chainId);
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
                OnchainCircuitBreaker::recordFailure($chainId);
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
                OnchainCircuitBreaker::releaseProbe($chainId);
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

    /**
     * Concurrent same-host batch GET with circuit-breaker integration.
     *
     * Wraps {@see \BCC\Core\Http\SafeHttpClient::getBatchSameHost} (raw
     * curl_multi, waves of 12) the way {@see get()} wraps the single
     * request: the per-chain {@see OnchainCircuitBreaker} is consulted
     * ONCE up front and its outcome recorded ONCE afterwards. All N URLs
     * MUST share a single host (the primitive enforces this per index).
     *
     * NO retry loop and NO backoff here (unlike {@see request()}): the
     * batch is the gallery cold-path, run under a user request, and a
     * per-URL retry storm across 30 collections would blow the request
     * budget. A failed URL simply comes back as its own WP_Error and the
     * caller treats it as "unknown" for that one collection. This mirrors
     * the load-bearing null-vs-empty distinction the single path relies on.
     *
     * Breaker outcome rule (documented, matches the single-path intent —
     * "the breaker tracks host reachability, not per-URL HTTP status"):
     *   - Breaker already OPEN → return an index-aligned array of
     *     `circuit_breaker_open` WP_Errors WITHOUT calling out (fail fast,
     *     same as {@see request()}'s top-of-method guard).
     *   - Batch returns and AT LEAST ONE entry is a real HTTP response
     *     (any status, including 404/500) → the host answered →
     *     `recordSuccess`. A per-URL mix of 200s and 404s is a SUCCESS for
     *     the breaker; a 404 means "that contract has no such query," not
     *     "the chain is down."
     *   - EVERY entry is a WP_Error (host-level failure: DNS, connection
     *     refused, all-timed-out, SSRF-blocked host) → `recordFailure`
     *     ONCE. We charge the breaker a single failure for the wave, not N,
     *     so one bad batch doesn't fast-trip a chain that a single call
     *     would have charged once.
     *
     * CU/budget note: {@see request()} does NOT meter a per-call CU budget
     * (see the two "PHASE X2 REMOVAL" blocks above — that counter was
     * deliberately moved out of transport into EnrichmentScheduler + the
     * physical `cu_used_today` checkpoint). There is therefore no per-call
     * meter for this batch path to replicate; the only transport-layer
     * concern is the CircuitBreaker, preserved above. The batch's own
     * fairness bound is its caller's contract cap (~30 collections) plus
     * the primitive's 12-wide concurrency wave cap.
     *
     * @param list<string>          $urls    all share ONE host
     * @param array<string, mixed>  $args    passed to SafeHttpClient::getBatchSameHost
     *                                        (timeout, headers, limit_response_size)
     * @param array<string, mixed>  $options {
     *     @type int    $chain_id  Chain ID for circuit-breaker integration.
     *     @type string $label     Human-readable label for logging.
     * }
     * @return array<int, array{code: int, body: string}|\WP_Error> index-aligned with $urls
     */
    public static function getBatchSameHost(array $urls, array $args = [], array $options = []): array
    {
        if ($urls === []) {
            return [];
        }

        $urls    = array_values($urls);
        $chainId = (int) ($options['chain_id'] ?? 0);
        $label   = (string) ($options['label'] ?? 'API batch');

        // Circuit breaker: check ONCE before the whole batch (mirrors the
        // single-request guard at the top of request()).
        if ($chainId > 0 && OnchainCircuitBreaker::isOpen($chainId)) {
            self::log("BLOCKED by circuit breaker: {$label} (chain {$chainId}, {" . count($urls) . '} urls)');
            $err = new \WP_Error('circuit_breaker_open', "Circuit breaker open for chain {$chainId}");
            return self::failEveryBatchIndex($urls, $err);
        }

        try {
            $results = \BCC\Core\Http\SafeHttpClient::getBatchSameHost($urls, $args);

            if ($chainId > 0) {
                $anyResponse = false;
                foreach ($results as $res) {
                    if (!is_wp_error($res)) {
                        $anyResponse = true;
                        break;
                    }
                }

                if ($anyResponse) {
                    // Host answered at least once → reachable → success.
                    OnchainCircuitBreaker::recordSuccess($chainId);
                } else {
                    // Every URL failed at transport → host-level failure.
                    self::log(sprintf(
                        'BATCH host-level failure %s — all %d urls errored',
                        $label,
                        count($urls)
                    ));
                    OnchainCircuitBreaker::recordFailure($chainId);
                }
            }

            return $results;
        } finally {
            // Match request()'s finally: release the HALF-OPEN probe lock
            // on every exit path so an unrecorded outcome can't hold it.
            if ($chainId > 0) {
                OnchainCircuitBreaker::releaseProbe($chainId);
            }
        }
    }

    // ── Internal ────────────────────────────────────────────────────────────

    /**
     * Build an index-aligned WP_Error result array for a whole batch —
     * used when the breaker is already open and we never call out.
     *
     * @param list<string> $urls
     * @return array<int, \WP_Error>
     */
    private static function failEveryBatchIndex(array $urls, \WP_Error $error): array
    {
        $out = [];
        foreach (array_keys($urls) as $i) {
            $out[$i] = $error;
        }
        return $out;
    }

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
