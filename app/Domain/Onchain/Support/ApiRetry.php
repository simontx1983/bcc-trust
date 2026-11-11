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

use BCC\Trust\Onchain\ValueObjects\ProviderFailureKind;
use BCC\Trust\Onchain\ValueObjects\ProviderRequestClass;

if (!defined('ABSPATH')) {
    exit;
}

final class ApiRetry
{
    // ── Defaults ────────────────────────────────────────────────────────────
    /**
     * ── ⚠ RETRY ACCOUNTING IS PER LOGICAL REQUEST, NOT PER ATTEMPT ───────
     *
     * ONE logical provider request — one endpoint, one subject, one API or
     * RPC operation, including every retry of that same operation — charges
     * {@see OnchainCircuitBreaker::recordFailure()} AT MOST ONCE, when the
     * attempt sequence ends. A sequence that eventually succeeds charges
     * nothing and credits one success; a fully exhausted sequence charges
     * exactly one failure, carrying the kind of the attempt that exhausted
     * it.
     *
     * ── WHAT IT USED TO DO, AND WHAT THAT COST ──────────────────────────
     * Until PR B this class called `recordFailure()` once PER ATTEMPT, so a
     * single failing request charged FOUR against a threshold of five. The
     * 2026-09-08 audit measured it exactly: chain 8 opened on a counter of
     * 8 — 2 x 4 — inside a 23-second window.
     *
     * ⚠ THE OLD ARITHMETIC WAS NOT UNIFORM, AND THE DIFFERENCE MATTERS:
     *   - a TRANSPORT-ONLY failing request charged 4, so TWO of them (8)
     *     opened a threshold-five breaker;
     *   - the head-poll and transfer-page paths added their own domain
     *     verdict on top, charging 4 + 1 = 5, so ONE of them opened the
     *     chain-wide breaker by itself.
     * Both now charge exactly one, so both take five.
     *
     * ── WHAT DID NOT CHANGE, DELIBERATELY ───────────────────────────────
     * The retry COUNT and the backoff delays are untouched: a half-open
     * probe still gets its full 1 + DEFAULT_MAX_RETRIES attempts, because
     * reducing a recovery probe to a single HTTP attempt would be a
     * different behaviour change with a different risk profile. Nor is
     * there an {@see OnchainCircuitBreaker::isOpen()} re-check between
     * attempts: with the charge deferred to exhaustion, the breaker can no
     * longer open midway through this loop, so there is nothing for such a
     * check to catch.
     *
     * Intermediate failures are LOCAL RETRY STATE ONLY. Nothing is written
     * to the breaker and then cancelled — there is no pending charge, which
     * is why an eventual success cannot leave a stale increment behind.
     *
     * ── THE OUTCOME IS DECIDED AFTER SEMANTIC VALIDATION ────────────────
     * A 2xx is not automatically a success. A caller that can tell a usable
     * payload from an error carried inside a 200 supplies `validate_success`
     * (see the parameter docs), and an unusable payload settles as the one
     * failure for that request. Crediting at the status line and letting the
     * caller charge afterwards is a different thing entirely: it cleared the
     * open state, the counter and the probe first, so a failed recovery
     * probe read as recovery and 200-level semantic failures could never
     * accumulate past one.
     *
     * Pinned by {@see \BCC\Trust\Onchain\Tests\Unit\BreakerRetryAccountingTest}
     * and measured against the real counter by
     * {@see \BCC\Trust\Onchain\Tests\Unit\BreakerChargePerLogicalRequestTest}.
     */
    const DEFAULT_MAX_RETRIES   = 3;
    const DEFAULT_BACKOFF_BASE  = 2;      // seconds
    const DEFAULT_BACKOFF_MAX   = 30;     // seconds
    const DEFAULT_429_FALLBACK  = 15;     // seconds if no Retry-After header
    const BACKOFF_MULTIPLIER    = 2.5;

    /**
     * Hard cap on EVERY backoff sleep — and the constant that actually
     * governs the schedule.
     *
     * ⚠⚠ IT WAS AN UNNAMED `3` IN TWO PLACES, AND THAT IS WHY THE COMMENT
     * ABOVE USED TO LIE. `BACKOFF_MULTIPLIER` carried the trailing comment
     * `2s → 5s → 12.5s → 30s (capped)`, which is wrong twice over: the
     * formula is `BASE * attempt ** 2.5`, so it computes 2 → 11 → 30 (not
     * geometric), and this cap then flattens it to 2 → 3 → 3 before any of
     * that is reached. Two different readers derived two different wrong
     * budgets from that comment before it was corrected.
     *
     * ⇒ So the schedule is no longer described in prose at all. It is
     * computed by {@see backoffDelay()} and summed by
     * {@see worstCaseSleepTotal()}, both public and both asserted by
     * {@see \BCC\Trust\Onchain\Tests\Unit\ApiRetryDeadlineTest}, so a
     * change to any constant here moves the tests rather than leaving a
     * sentence behind to be believed.
     *
     * ⚠ A CONSEQUENCE WORTH KNOWING: with this cap in force
     * {@see DEFAULT_BACKOFF_MAX} and most of {@see BACKOFF_MULTIPLIER} are
     * UNREACHABLE — nothing can observe them. "There is a 30s backoff
     * ceiling" is a reassuring detail that does not apply.
     */
    const BACKOFF_SLEEP_CAP     = 3;      // seconds

    /**
     * Floor on an attempt once a deadline has capped it: the smallest
     * remaining budget worth spending an attempt on.
     *
     * Two uses, both needed. A retry is not STARTED unless this much time
     * is left after its sleep ({@see retryFitsDeadline()}), and an attempt
     * that IS started never gets a timeout below this
     * ({@see capTimeoutToDeadline()}).
     *
     * ⚠ WHY A FLOOR AT ALL. Without one, a deadline with 1s left would
     * still start an attempt with a 1s timeout — which against an RPC
     * endpoint is not a request, it is a formality that burns the last of
     * the budget and can still earn the chain a breaker charge. 5s is the
     * smallest timeout any call site in this tree uses (KeybaseLogoResolver,
     * on a logo fetch), so it is the tree's own lower bound on "a timeout
     * someone thought was viable" rather than a number invented here.
     */
    const MIN_ATTEMPT_BUDGET    = 5;      // seconds

    /**
     * WordPress's own default `timeout` for `wp_remote_*`, used only as the
     * fallback when deriving a default deadline. Every call site in this
     * tree sets `timeout` explicitly, so nothing currently relies on it.
     */
    const WP_DEFAULT_TIMEOUT    = 5;      // seconds

    /**
     * Execute an HTTP request with automatic retry on transient failures.
     *
     * @param callable $fn        Must return a WP HTTP response array or WP_Error.
     *                            Signature: fn(): array|WP_Error
     * @param array<string, mixed> $options   {
     *     @type int    $deadline_at   OPTIONAL. An ABSOLUTE unix instant after
     *                                 which no further attempt is started.
     *                                 Absent (the default for a direct
     *                                 {@see request()} caller) = no deadline,
     *                                 i.e. today's behaviour exactly.
     *                                 {@see get()} / {@see post()} supply one
     *                                 derived from the caller's own timeout,
     *                                 so their behaviour is unchanged too —
     *                                 see the note on {@see get()}.
     *
     *                                 ⚠⚠ AN INSTANT, NOT A DURATION, AND THE
     *                                 DISTINCTION IS THE WHOLE POINT. A
     *                                 duration bounds ONE logical request;
     *                                 the paths that need bounding issue
     *                                 SEVERAL per user request — the
     *                                 delegations gate walks up to
     *                                 `DELEGATIONS_MAX_PAGES` (5) pages, and
     *                                 the Cosmos NFT-piece route asks for
     *                                 metadata and then for the owner. Handing
     *                                 each call "20 seconds" bounds each call
     *                                 and multiplies by the page count; one
     *                                 shared instant bounds the WALK, which is
     *                                 what the user is waiting on.
     *     @type int    $max_retries   Max retry attempts (default 3).
     *     @type string $label         Human-readable label for logging (e.g. "Cosmos LCD /validators").
     *     @type int    $chain_id      Chain ID for circuit breaker integration.
     *     @type callable $application_error  OPTIONAL. See below. Absent =
     *                                 today's behaviour, exactly.
     *     @type callable $validate_success  OPTIONAL. See below. Absent =
     *                                 today's behaviour, exactly: any 2xx is a
     *                                 success and no response body is read.
     *     @type ProviderOutcomeReceipt $outcome  OPTIONAL. A receipt the CALLER
     *                                 owns, for THIS logical request. Filled in
     *                                 with whatever this method settles on the
     *                                 breaker, so a later domain verdict about
     *                                 the same request can avoid charging it a
     *                                 second time. Never read by this class.
     * }
     *
     * ── `validate_success`: WHEN A 200 IS NOT A SUCCESS ────────────────
     * Alchemy answers `MATIC_MAINNET is not enabled for this app` with HTTP
     * 200 and a JSON-RPC error object; a public RPC that does not implement
     * an Alchemy-proprietary method does the same. The host answered, so the
     * status line says success — and the operation failed.
     *
     * That mattered most on a HALF-OPEN recovery probe. `recordSuccess()`
     * clears the open state, deletes the counter, releases the probe and
     * advances `bcc_onchain_last_success_*`. Crediting it at the status line
     * therefore accepted a broken provider as recovered, and then the
     * caller's verdict added one failure to a freshly-cleared counter — so
     * the chain sat CLOSED at failures=1 and 200-level semantic failures
     * could NEVER reach the threshold, however long the provider stayed
     * broken.
     *
     * So the CALLER may supply `fn(string $body, int $code): bool` returning
     * TRUE when the payload is USABLE. When it returns FALSE this method
     * settles the request as its one failure: not retried (a payload-level
     * refusal is deterministic) and not attributed (there is no wire fault to
     * name, so the kind stays null, exactly as the domain sites record).
     *
     * IT IS OPT-IN AND NARROW, and it is consulted ONLY for a 2xx — never
     * for WP_Error, 429, 4xx or 5xx, so it cannot reclassify a transport
     * failure or collide with `application_error`.
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

        // An absolute instant, or 0 for "no deadline" — which is the default
        // and is today's behaviour exactly. See the parameter docs for why
        // this is an instant rather than a duration.
        $deadlineAt = (int) ($options['deadline_at'] ?? 0);

        $isApplicationError = isset($options['application_error']) && is_callable($options['application_error'])
            ? $options['application_error']
            : null;

        // OPT-IN semantic validation of a 2xx body. `fn(string $body, int $code): bool`
        // returning TRUE when the payload is USABLE. Absent = today's behaviour
        // exactly: any 2xx is a success and no body is ever read.
        $isUsablePayload = isset($options['validate_success']) && is_callable($options['validate_success'])
            ? $options['validate_success']
            : null;

        // ⚠ DERIVED FROM OPTIONS, NEVER FROM THE URL. The only signal read is
        // whether the caller opted into application-error handling, which
        // already distinguishes a question addressed to a CONTRACT from one
        // addressed to a NODE. No host, path, query or address is involved,
        // so nothing identifying can reach durable state through it.
        $requestClass = ProviderRequestClass::fromOptions($options);
        // Opaque marker for WHERE this charge was earned. Supplied by the
        // caller that knows the chain slug and endpoint; the retry loop
        // never interprets it.
        $endpointFp   = isset($options['endpoint_fp']) && is_string($options['endpoint_fp'])
            ? $options['endpoint_fp']
            : null;

        // The caller's receipt for THIS logical request, if it wants one.
        // Write-only from here: this method records what it settled and never
        // consults it, so passing one cannot change transport behaviour.
        $receipt = isset($options['outcome']) && $options['outcome'] instanceof ProviderOutcomeReceipt
            ? $options['outcome']
            : null;

        // Circuit breaker: check before attempting
        // ⚠⚠ DEADLINE CHECKED BEFORE THE BREAKER, AND THAT ORDER MATTERS.
        // `isOpen()` is the MUTATING reader — it CLAIMS the single HALF-OPEN
        // probe slot. Refusing after it would strand that slot on a request
        // that never calls out, which is precisely the failure #265 removed
        // from the four outer preflights. Nothing has been claimed yet here,
        // so returning is free.
        //
        // ⚠ AND IT DOES NOT CHARGE THE BREAKER. No attempt is made, so no
        // provider has been consulted and there is nothing to blame: the
        // budget ran out upstream of this request. Charging here would let a
        // tight deadline manufacture failures against a healthy chain and
        // open its breaker — the fabricated-diagnosis mistake PR 7.8 removed.
        if (!self::hasAttemptBudget($deadlineAt)) {
            self::log(sprintf(
                'DEADLINE ALREADY SPENT %s — no attempt made (needed %ds, had %ds)',
                $label,
                self::MIN_ATTEMPT_BUDGET,
                max(0, $deadlineAt - time())
            ));

            return new \WP_Error(
                'deadline_exceeded',
                "Deadline reached before any attempt for {$label}"
            );
        }

        if ($chainId > 0 && OnchainCircuitBreaker::isOpen($chainId)) {
            self::log("BLOCKED by circuit breaker: {$label} (chain {$chainId})");
            $receipt?->recordBlockedByOpenBreaker();
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
                    // ⚠ THE OUTCOME IS DECIDED AFTER SEMANTIC VALIDATION, NOT
                    // AT THE STATUS LINE. A caller that can tell a usable
                    // payload from an error carried inside a 200 supplies
                    // `validate_success`; when it says the body is unusable,
                    // this request FAILED and settles as a failure.
                    //
                    // Crediting first and letting the caller charge afterwards
                    // is not the same thing and was a real defect: the credit
                    // cleared the open state, the counter and the probe, and
                    // advanced `bcc_onchain_last_success_*`, so a failed
                    // recovery probe was accepted as recovery and 200-level
                    // semantic failures could never accumulate past one.
                    if ($isUsablePayload !== null
                        && !$isUsablePayload((string) wp_remote_retrieve_body($lastResponse), $code)) {
                        self::log(sprintf(
                            'SEMANTIC FAILURE (%d) %s — the host answered with an unusable payload',
                            $code, $label
                        ));

                        // ⚠ NOT RETRIED, DELIBERATELY. The host answered; a
                        // payload-level refusal ("this chain is not enabled
                        // for this app") is deterministic, and retrying it
                        // three more times is the exact waste the 2026-08-19
                        // Dungeon measurement recorded on the 5xx path.
                        //
                        // ⚠ AND NOT ATTRIBUTED. There is no wire fault to
                        // name — no transport error, no HTTP status outside
                        // 2xx — so the kind stays null, which is what the
                        // eight domain sites already record when they have no
                        // wire outcome. Inventing a token here would be the
                        // fabricated diagnosis PR 7.8 removed.
                        self::settleFailure($chainId, null, $requestClass, $endpointFp, $receipt);

                        return $lastResponse;
                    }

                    // Success — record for circuit breaker. This is the ONLY
                    // outcome for the whole sequence: any earlier failed
                    // attempt was local retry state and charged nothing, so
                    // there is no accumulated failure left to undo.
                    self::settleSuccess($chainId, $receipt);
                    return $lastResponse;
                }

                // ── 429 Rate Limited ────────────────────────────────────
                if ($code === 429) {
                    $delay = self::parseRetryAfter($lastResponse);
                    self::log(sprintf(
                        'RATE LIMITED (429) %s — attempt %d/%d, waiting %ds',
                        $label, $attempt + 1, $maxRetries + 1, $delay
                    ));

                    // TERMINAL by design — a 429 is never retried, so this IS
                    // the end of the logical request and its single charge.
                    self::settleFailure(
                        $chainId,
                        ProviderFailureKind::RATE_LIMITED,
                        $requestClass,
                        $endpointFp,
                        $receipt
                    );

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

                    // ⚠ NOT CHARGED HERE. A retryable 5xx is local retry state
                    // until the sequence ends: if a later attempt succeeds the
                    // logical request succeeded, and nothing should have been
                    // written to the breaker in the meantime.
                    //
                    // Retryable: exhaust attempts before returning the failure.
                    if ($attempt < $maxRetries) {
                        $delay = self::backoffDelay($attempt + 1);

                        // ⚠ CHECKED BEFORE `$attempt++`, DELIBERATELY. Falling
                        // through lands on the exhaustion settle below, whose
                        // log reads `$attempt + 1` — incrementing first would
                        // make it name an attempt that never ran.
                        if (self::retryFitsDeadline($deadlineAt, $delay)) {
                            $attempt++;
                            if ($delay > 0) {
                                sleep($delay);
                            }
                            continue;
                        }

                        self::log(sprintf(
                            'DEADLINE %s — stopping after attempt %d/%d (%ds backoff + %ds floor exceeds the %ds left)',
                            $label, $attempt + 1, $maxRetries + 1,
                            $delay, self::MIN_ATTEMPT_BUDGET, max(0, $deadlineAt - time())
                        ));
                    }

                    // Attempts exhausted on a 5xx — the logical request has
                    // failed, and this is its one and only charge.
                    self::settleFailure(
                        $chainId,
                        ProviderFailureKind::HTTP_5XX,
                        $requestClass,
                        $endpointFp,
                        $receipt
                    );
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

            // ⚠ NOT CHARGED HERE — same reasoning as the 5xx branch above.
            // Retryable: exhaust attempts before returning the failure.
            if ($attempt < $maxRetries) {
                $delay = self::backoffDelay($attempt + 1);

                // Same order as the 5xx branch above, for the same reason.
                if (self::retryFitsDeadline($deadlineAt, $delay)) {
                    $attempt++;
                    if ($delay > 0) {
                        sleep($delay);
                    }
                    continue;
                }

                self::log(sprintf(
                    'DEADLINE %s — stopping after attempt %d/%d (%ds backoff + %ds floor exceeds the %ds left)',
                    $label, $attempt + 1, $maxRetries + 1,
                    $delay, self::MIN_ATTEMPT_BUDGET, max(0, $deadlineAt - time())
                ));
            }

            // Attempts exhausted on a transport error — one charge for the
            // whole logical request.
            self::settleFailure(
                $chainId,
                ProviderFailureKind::TRANSPORT,
                $requestClass,
                $endpointFp,
                $receipt
            );
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
     * The deadline these two convenience wrappers hand to {@see request()}:
     * the caller's own, or the honest worst case.
     *
     * ⭐ THE DEFAULT IS WHAT KEEPS THIS PR BEHAVIOUR-PRESERVING across all
     * two dozen existing call sites: it must be a deadline that CANNOT
     * FIRE. The deadline only bites where a caller asked for one.
     *
     * ⚠⚠ AND IT IS ONE ATTEMPT WIDER THAN THE WORST CASE, DELIBERATELY.
     * `worstCaseSeconds($maxRetries, $timeout)` is the exact worst case and
     * leaves ZERO slack, which is not the same as unreachable. Work the last
     * retry's check through: it asks
     *
     *     deadline - (maxRetries x (timeout + overhead) + sleeps) >= FLOOR
     *
     * and with `deadline` set to the exact worst case that reduces to
     * `timeout - maxRetries x overhead >= MIN_ATTEMPT_BUDGET`. At
     * `timeout = 5` — KeybaseLogoResolver's, the smallest in this tree —
     * that is an equality with NO room: any real time not inside the
     * `timeout` budget drops the final attempt. And there always is some:
     * WordPress's own argument processing and response parsing, the
     * breaker's `get_option`/`update_option` round trips, and `time()`'s
     * one-second granularity, which can tick mid-attempt and cost a whole
     * second to rounding alone.
     *
     * ⇒ So the default budgets for one more attempt than can happen. That
     * is a reason rather than a chosen number: it scales with the timeout
     * instead of guessing an overhead, needs no new constant, and makes the
     * margin ~2.7s per attempt at `timeout = 5` and ~9s at 15, where a flat
     * `+ MIN_ATTEMPT_BUDGET` would have left only ~1.7s at the very call
     * site that exposed the problem. Being generous costs nothing here,
     * because an unreachable deadline's only job is to be unreachable.
     *
     * ⚠ It is still worth defaulting rather than leaving it at 0: with a
     * default in place, {@see capTimeoutToDeadline()} and the retry checks
     * are exercised on every single request instead of only on the two paths
     * that opt in — so a bug in them surfaces in ordinary traffic and in the
     * whole existing suite, not just in the new tests.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $secured
     */
    private static function resolveDeadline(array $options, array $secured): int
    {
        if (isset($options['deadline_at'])) {
            return (int) $options['deadline_at'];
        }

        $maxRetries = (int) ($options['max_retries'] ?? self::DEFAULT_MAX_RETRIES);

        // Every call site in this tree sets `timeout` explicitly; the
        // fallback mirrors WordPress's own default for the one that forgets.
        $timeout = (int) ($secured['timeout'] ?? self::WP_DEFAULT_TIMEOUT);

        // `$maxRetries + 1`: one attempt of slack. See the note above.
        return time() + self::worstCaseSeconds($maxRetries + 1, $timeout);
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

        $deadlineAt = self::resolveDeadline($options, $secured);
        $options['deadline_at'] = $deadlineAt;

        return self::request(
            fn() => wp_remote_get($url, self::capTimeoutToDeadline($secured, $deadlineAt)),
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

        $deadlineAt = self::resolveDeadline($options, $secured);
        $options['deadline_at'] = $deadlineAt;

        return self::request(
            fn() => wp_remote_post($url, self::capTimeoutToDeadline($secured, $deadlineAt)),
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
        $endpointFp = isset($options['endpoint_fp']) && is_string($options['endpoint_fp'])
            ? $options['endpoint_fp']
            : null;

        // Same write-only receipt contract as request(). The wave is ONE
        // logical request for the breaker's purposes, and it already charged
        // once — this only lets a caller see which way it settled.
        $receipt = isset($options['outcome']) && $options['outcome'] instanceof ProviderOutcomeReceipt
            ? $options['outcome']
            : null;

        if ($chainId > 0 && OnchainCircuitBreaker::isOpen($chainId)) {
            self::log("BLOCKED by circuit breaker: {$label} (chain {$chainId}, {" . count($urls) . '} urls)');
            $receipt?->recordBlockedByOpenBreaker();
            $err = new \WP_Error('circuit_breaker_open', "Circuit breaker open for chain {$chainId}");
            return self::failEveryBatchIndex($urls, $err);
        }

        // ⚠ NO RETRY LOOP HERE, so there is no retry for a deadline to
        // trim — the only lever is the wave's own timeout, and the only
        // refusal is "there is not even time for one wave". Neither of the
        // two paths threaded in this PR reaches the batch, but the gallery
        // cold path that does is itself run under a user request (see the
        // method docs), so a deadline arriving here is not hypothetical.
        $deadlineAt = (int) ($options['deadline_at'] ?? 0);

        if (!self::hasAttemptBudget($deadlineAt)) {
            self::log(sprintf(
                'DEADLINE ALREADY SPENT %s — batch of %d not attempted',
                $label,
                count($urls)
            ));

            // Not charged, for the same reason as the single path: no host
            // was contacted, so no host has failed.
            return self::failEveryBatchIndex(
                $urls,
                new \WP_Error('deadline_exceeded', "Deadline reached before the batch for {$label}")
            );
        }

        $args = self::capTimeoutToDeadline($args, $deadlineAt);

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
                    $receipt?->recordTransportSuccessCredit();
                } else {
                    // Every URL failed at transport → host-level failure.
                    self::log(sprintf(
                        'BATCH host-level failure %s — all %d urls errored',
                        $label,
                        count($urls)
                    ));
                    // Every URL came back a WP_Error, so this is a wire
                    // failure by construction — and it charges ONCE for the
                    // whole batch, not once per URL.
                    OnchainCircuitBreaker::recordFailure(
                        $chainId,
                        ProviderFailureKind::TRANSPORT,
                        ProviderRequestClass::BATCH_REQUEST,
                        $endpointFp
                    );
                    $receipt?->recordTransportFailureCharge(ProviderFailureKind::TRANSPORT);
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
     * The backoff sleep before attempt number `$attemptNumber`, in seconds.
     *
     * ⭐ PUBLIC AND COMPUTED ON PURPOSE. This used to be two copies of the
     * same expression inside the retry loop, described by a trailing comment
     * on {@see BACKOFF_MULTIPLIER} that disagreed with both of them. One
     * function, asserted by its tests, cannot drift from itself.
     *
     * At the shipped constants the real schedule is `2, 3, 3` — the formula
     * yields 2, 11, 30 and {@see BACKOFF_SLEEP_CAP} flattens the tail.
     *
     * @param int $attemptNumber 1-based: the sleep BEFORE this retry.
     */
    public static function backoffDelay(int $attemptNumber): int
    {
        $delay = min(
            self::DEFAULT_BACKOFF_MAX,
            (int) (self::DEFAULT_BACKOFF_BASE * ($attemptNumber ** self::BACKOFF_MULTIPLIER))
        );

        // Cap cron-context sleep so we cannot exceed typical per-job budgets
        // even at max_retries=3.
        return (int) min($delay, self::BACKOFF_SLEEP_CAP);
    }

    /**
     * Total time this class can spend ASLEEP for one logical request.
     *
     * `$maxRetries` retries means `$maxRetries` sleeps — one before each —
     * so at the default 3 this is `2 + 3 + 3 = 8`. Used to derive the
     * default deadline in {@see get()} / {@see post()}, which is why it is
     * computed rather than written down: a default that disagreed with the
     * real schedule would fire on healthy traffic.
     */
    public static function worstCaseSleepTotal(int $maxRetries): int
    {
        $total = 0;
        for ($i = 1; $i <= $maxRetries; $i++) {
            $total += self::backoffDelay($i);
        }

        return $total;
    }

    /**
     * The honest worst case for one logical request: every attempt timing
     * out, plus every backoff between them.
     *
     * ⭐ THIS IS WHAT MAKES THE DEADLINE ADDITIVE. {@see get()} and
     * {@see post()} default `deadline_at` to `now + this`, so for every
     * existing call site the deadline sits at or beyond the longest run that
     * was ever possible and therefore cannot fire. A caller that wants a
     * real bound passes its own instant; nobody else changes behaviour.
     *
     * ⚠ `$maxRetries + 1` attempts, not `$maxRetries`. The loop is
     * `$attempt = 0; while ($attempt <= $maxRetries)`, which is 1 + retries
     * executions — the arithmetic that got mis-stated twice while this was
     * being written.
     */
    public static function worstCaseSeconds(int $maxRetries, int $timeout): int
    {
        return ($maxRetries + 1) * $timeout + self::worstCaseSleepTotal($maxRetries);
    }

    /**
     * Is there room to START an attempt at all?
     *
     * `$deadlineAt <= 0` means no deadline is in force, which is the default
     * everywhere and must answer TRUE.
     */
    private static function hasAttemptBudget(int $deadlineAt): bool
    {
        if ($deadlineAt <= 0) {
            return true;
        }

        return ($deadlineAt - time()) >= self::MIN_ATTEMPT_BUDGET;
    }

    /**
     * Is there room for another attempt AFTER sleeping `$delay` seconds?
     *
     * The sleep is counted, not just the attempt: a 3s backoff with 4s left
     * leaves 1s, and a 1s attempt is the formality {@see MIN_ATTEMPT_BUDGET}
     * exists to refuse.
     */
    private static function retryFitsDeadline(int $deadlineAt, int $delay): bool
    {
        if ($deadlineAt <= 0) {
            return true;
        }

        return ($deadlineAt - (time() + $delay)) >= self::MIN_ATTEMPT_BUDGET;
    }

    /**
     * Shrink an attempt's `timeout` to what is left of the budget.
     *
     * ⚠⚠ WITHOUT THIS THE DEADLINE WOULD BE ADVISORY. The checks above
     * decide whether to START an attempt; nothing stops the attempt they
     * admitted from running its own full timeout past the deadline. On the
     * NFT-piece route that is a 30s Solana timeout against a 35s budget, so
     * the overshoot would be most of the bound.
     *
     * ⚠ Applied per attempt INSIDE the closure, not once to `$secured`:
     * each attempt has less budget than the last. `prepareArgs()` is still
     * called once outside the loop, so the DNS pin it establishes is
     * untouched — only `timeout` is rewritten here.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private static function capTimeoutToDeadline(array $args, int $deadlineAt): array
    {
        if ($deadlineAt <= 0) {
            return $args;
        }

        $remaining = $deadlineAt - time();
        $timeout   = (int) ($args['timeout'] ?? self::WP_DEFAULT_TIMEOUT);

        if ($remaining < $timeout) {
            $args['timeout'] = max(self::MIN_ATTEMPT_BUDGET, $remaining);
        }

        return $args;
    }

    /**
     * Settle ONE logical request as a failure: exactly one breaker charge.
     *
     * Every failure exit in {@see request()} funnels through here, which is
     * what makes "at most one charge per logical request" a property of the
     * code rather than a promise in a comment. It is called at a TERMINAL
     * point only — a 429 (never retried) or an exhausted retry sequence —
     * so there is no pending state and nothing to cancel.
     */
    private static function settleFailure(
        int $chainId,
        ?string $kind,
        ?string $requestClass,
        ?string $endpointFp,
        ?ProviderOutcomeReceipt $receipt
    ): void {
        if ($chainId <= 0) {
            return; // No chain, no breaker — and no receipt entry to mislead a caller.
        }

        OnchainCircuitBreaker::recordFailure($chainId, $kind, $requestClass, $endpointFp);
        $receipt?->recordTransportFailureCharge($kind);
    }

    /**
     * Settle ONE logical request as a success: exactly one breaker credit.
     *
     * The credit clears the chain's failure counter, exactly as before. What
     * changed is what it has to clear: earlier attempts in this sequence no
     * longer wrote anything, so a retry that eventually succeeds now leaves
     * the breaker in the state a first-attempt success would have.
     */
    private static function settleSuccess(int $chainId, ?ProviderOutcomeReceipt $receipt): void
    {
        if ($chainId <= 0) {
            return;
        }

        OnchainCircuitBreaker::recordSuccess($chainId);
        $receipt?->recordTransportSuccessCredit();
    }

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
