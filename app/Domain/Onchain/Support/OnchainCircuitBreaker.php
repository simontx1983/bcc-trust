<?php
/**
 * Onchain Circuit Breaker
 *
 * Per-chain circuit breaker that pauses API fetching when a chain's
 * endpoint is consistently failing. Prevents wasting API budget on
 * chains that are down and gives failing endpoints time to recover.
 *
 * States:
 *   CLOSED   — Normal operation, requests flow through.
 *   OPEN     — Chain is failing, all requests blocked for COOLDOWN period.
 *   HALF-OPEN — Cooldown expired, allow ONE probe request.
 *              If it succeeds → CLOSED. If it fails → OPEN again.
 *
 * Storage: wp_cache (Redis-backed when available, transient fallback).
 *
 * Renamed from `CircuitBreaker` (V-04 of the Constitutional Violation
 * Scan). The old short name collided with bcc-trust's generic
 * {@see \BCC\Trust\Core\Support\CircuitBreaker}. The two breakers are
 * NOT interchangeable: Core is string-keyed + transient-backed +
 * minimal hardening for flaky external HTTP endpoints (Alchemy,
 * Cosmos RPC); this Onchain variant is integer-keyed (chain_id) with
 * 6-hour TTL, atomic DB counter, HALF-OPEN probe-lock state machine,
 * stale-chain detection, and rate-limited corruption logging — every
 * one of those a scar from a prior on-chain indexing incident. See
 * docs/pattern-registry.md "Same-name-different-class index" for the
 * V-04 resolution note.
 *
 * @package BCC\Trust\Onchain\Support
 */

namespace BCC\Trust\Onchain\Support;

use BCC\Trust\Onchain\Repositories\OnchainCircuitBreakerRepository;
use BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy;
use BCC\Trust\Onchain\ValueObjects\ProviderFailureKind;
use BCC\Trust\Onchain\ValueObjects\ProviderRequestClass;

if (!defined('ABSPATH')) {
    exit;
}

final class OnchainCircuitBreaker
{
    const FAILURE_THRESHOLD = 5;            // Consecutive failures to trip
    const COOLDOWN_SECONDS  = 300;          // 5 minutes before half-open probe
    const PROBE_LOCK_PREFIX = 'bcc_cb_probe_';
    const CACHE_GROUP       = 'bcc_circuit';
    // 6-hour TTL: long-running batch cron jobs (10k+ pages) can exceed the
    // previous 30-min TTL mid-run, causing an OPEN breaker to silently return
    // to CLOSED in the same cron tick because its cached state expired. The
    // TTL only needs to outlast the longest batch run; breaker state is
    // authoritatively reset by recordSuccess() so a longer TTL does not
    // extend actual cooldowns — it just prevents premature amnesia.
    const CACHE_TTL         = 21600;        // 6 hours

    /**
     * Check if the circuit breaker is OPEN for a chain.
     *
     * Returns true when the request should be blocked. Returns false for
     * CLOSED or when the caller has successfully claimed the HALF-OPEN
     * probe slot.
     *
     * Side effect (HALF-OPEN only): atomically claims a per-chain probe
     * via a MySQL advisory lock (GET_LOCK). Advisory locks are atomic
     * across connections and auto-release on session close, so they
     * work correctly regardless of whether the site has a persistent
     * object cache (Redis/Memcached) — an earlier wp_cache_add sentinel
     * silently degraded on vanilla WordPress where wp_cache is
     * request-scoped.
     *
     * The probe lock is released by {@see recordSuccess()} /
     * {@see recordFailure()} and by {@see releaseProbe()} (the
     * belt-and-braces release from {@see ApiRetry} for 3xx/4xx paths
     * that don't record an outcome). A crashed worker releases the
     * lock automatically when MySQL notices the connection drop.
     */
    public static function isOpen(int $chainId): bool
    {
        // ⚠ THE PHASE DECISION IS SHARED; ONLY THE SIDE EFFECT IS NOT.
        // This method used to inline its own copy of the arithmetic, which
        // is how it came to disagree with the two status readers below.
        $phase = self::phaseFor(self::getState($chainId), time());

        if ($phase === self::PHASE_CLOSED) {
            return false; // Requests flow.
        }

        if ($phase === self::PHASE_OPEN) {
            return true; // Still resting.
        }

        // HALF-OPEN — non-blocking (timeout=0) GET_LOCK. Succeeds for
        // exactly one caller across the cluster at a time, regardless of
        // object cache backend. If the lock is already held we stay in
        // blocked state and the caller backs off.
        if (\BCC\Core\DB\AdvisoryLock::acquire(self::PROBE_LOCK_PREFIX . $chainId, 0)) {
            return false; // We own the probe → allow the request through.
        }

        return true; // Another worker is probing → still blocked.
    }

    /** The breaker is passing traffic. */
    public const PHASE_CLOSED = 'closed';

    /** Tripped, still inside its cooldown. */
    public const PHASE_OPEN = 'open';

    /** Tripped, cooldown elapsed — one probe may be admitted. */
    public const PHASE_HALF_OPEN = 'half_open';

    /**
     * READ-ONLY lifecycle phase for a chain.
     *
     * ⚠ THE REASON THIS IS NOT `isOpen()`. {@see isOpen()} has a SIDE
     * EFFECT: in the half-open window it claims a cluster-wide advisory
     * lock, and the caller that wins it is expected to go and make a
     * request. Anything that merely wants to LOOK — a test asserting the
     * lifecycle, an operator surface describing the pause — must not
     * consume the probe slot to do it, or the observation itself becomes
     * the probe and a real worker is turned away.
     *
     * Pure with respect to breaker state: reads, never writes, never locks.
     */
    public static function phase(int $chainId): string
    {
        return self::phaseFor(self::getState($chainId), time());
    }

    /**
     * THE ONE PHASE CALCULATION. Every reader goes through here.
     *
     * ── WHY IT TAKES BOTH ARGUMENTS ─────────────────────────────────────
     * The STATE is passed in rather than fetched, because a caller that
     * already holds a snapshot must be judged on that snapshot — fetching
     * again here would let one logical read straddle two different states
     * (and `getStaleChains()` genuinely does hold one already).
     *
     * `$now` is passed in for the same reason at the other end: a caller
     * looping over many chains captures the clock ONCE, so a single sweep
     * cannot report one chain as OPEN and the next as HALF-OPEN purely
     * because the cooldown boundary elapsed between two iterations.
     *
     * ── ⚠ THE BUG THIS EXISTS TO KILL ───────────────────────────────────
     * There used to be FOUR copies of this arithmetic — `isOpen()`,
     * `phase()`, `getAllStatus()` and `getStaleChains()` — and they did not
     * agree. The two status readers computed `time() - $openedAt`
     * unconditionally, so with `opened_at = 0` the elapsed time was the
     * whole Unix epoch, which is comfortably past any cooldown: both
     * reported HALF-OPEN for a breaker the WORKER was treating as OPEN. An
     * administrator would have read "one probe is allowed through" off a
     * dashboard while every request was in fact being refused.
     *
     * The `opened_at <= 0` branch is therefore checked BEFORE any
     * subtraction, so the epoch arithmetic can never happen. A missing
     * timestamp cannot start a cooldown, so the honest reading is OPEN.
     *
     * @param array{failures?: int|string, opened_at?: int|string}|null $state
     */
    private static function phaseFor(?array $state, int $now): string
    {
        if ($state === null) {
            return self::PHASE_CLOSED; // No state → CLOSED.
        }

        $failures = (int) ($state['failures'] ?? 0);
        $openedAt = (int) ($state['opened_at'] ?? 0);

        if ($failures < self::FAILURE_THRESHOLD) {
            return self::PHASE_CLOSED; // Below threshold → CLOSED.
        }

        if ($openedAt <= 0) {
            return self::PHASE_OPEN; // Tripped, but no cooldown has started.
        }

        return ($now - $openedAt) >= self::COOLDOWN_SECONDS
            ? self::PHASE_HALF_OPEN
            : self::PHASE_OPEN;
    }

    /**
     * PURE. The hyphenated spelling the two status readers publish.
     *
     * ⚠ THE CASING IS PART OF EACH METHOD'S OUTPUT CONTRACT, and the three
     * surfaces disagree on it by history: `phase()` returns `half_open`,
     * {@see getAllStatus()} publishes `half-open`, and
     * {@see getStaleChains()} publishes `HALF-OPEN`. Unifying the DECISION
     * is the point of this PR; unifying the SPELLING would be a silent
     * output change for consumers that never asked for one. So the decision
     * is shared and the presentation is mapped, once, here.
     */
    private static function phaseHyphenated(string $phase): string
    {
        return $phase === self::PHASE_HALF_OPEN ? 'half-open' : $phase;
    }

    /**
     * Release the probe lock for a chain. Idempotent — safe to call even
     * when this session does not currently hold the lock. Intended for
     * finally-block semantics in the HTTP wrapper so an unrecorded
     * outcome (3xx/4xx) cannot leave the probe slot held for the rest
     * of the PHP worker's life.
     */
    public static function releaseProbe(int $chainId): void
    {
        \BCC\Core\DB\AdvisoryLock::release(self::PROBE_LOCK_PREFIX . $chainId);
    }

    /**
     * Forget everything the breaker knows about a chain's PREVIOUS endpoint.
     *
     * Called by the audited endpoint switch after the chain row has moved, so
     * the new provider starts from a clean slate instead of inheriting a
     * counter, an open state or an attribution earned by a host it replaced.
     *
     * ⚠ DELIBERATELY NOT {@see recordSuccess()}. That method would clear the
     * same three things — and then write `bcc_onchain_last_success_<id>` =
     * now, recording a successful fetch that never happened. That timestamp is
     * read by the stale-chain detector and shown to operators; faking it would
     * make a chain look freshly healthy at the exact moment nobody has
     * contacted its new endpoint yet. Forgetting is not succeeding.
     *
     * Clears, together, so no reader can see a half-cleared record:
     *   - the open-state struct (object cache + transient),
     *   - the atomic failure counter,
     *   - the half-open probe lock.
     *
     * @return bool whether anything was actually cleared — lets the caller
     *              record "breaker_cleared" as a fact rather than an intention.
     */
    public static function forgetForEndpointChange(int $chainId): bool
    {
        if ($chainId <= 0) {
            return false;
        }

        $hadState = self::getState($chainId) !== null;
        $hadCounter = get_option(self::counterOptionName($chainId), null) !== null;

        wp_cache_delete('cb_' . $chainId, self::CACHE_GROUP);
        delete_transient('bcc_cb_' . $chainId);
        OnchainCircuitBreakerRepository::deleteCounter(self::counterOptionName($chainId));
        self::releaseProbe($chainId);

        return $hadState || $hadCounter;
    }

    /**
     * Record a successful API call for a chain.
     * Resets the failure counter — circuit returns to CLOSED.
     */
    public static function recordSuccess(int $chainId): void
    {
        $state = self::getState($chainId);

        // Only write if there was a non-zero failure count to clear
        //
        // ⚠ THE ATTRIBUTION IS CLEARED WITH THE COUNTER AND THE OPEN STATE,
        // in the same write. Leaving `kind` behind would let a recovered
        // chain keep displaying the reason it was last broken — the same
        // class of lie as a stale counter outliving its window, which is what
        // stranded chain 18 at 3022 failures beside an expired transient.
        if ($state !== null && (int) ($state['failures'] ?? 0) > 0) {
            // ⚠ The fingerprint is cleared WITH the counter and the
            // attribution. A recovered chain holds no story about which
            // endpoint broke it, so leaving the marker behind would let a
            // later charge be judged "foreign" against a provider that is no
            // longer described by anything.
            self::setState($chainId, [
                'failures'      => 0,
                'opened_at'     => 0,
                'kind'          => null,
                'request_class' => null,
                'endpoint_fp'   => null,
            ]);
        }

        // Also reset the DB-backed counter used by recordFailure() so the
        // two tracking mechanisms stay in sync. Without this, a success
        // would clear the state-struct but leave the counter at its
        // pre-success value, and the next failure would increment from
        // that stale value instead of 1. DELETE is sufficient because
        // recordFailure()'s INSERT … ON DUPLICATE KEY UPDATE re-creates
        // the row from 1 on the next failure.
        OnchainCircuitBreakerRepository::deleteCounter(self::counterOptionName($chainId));

        // Release the probe lock so subsequent traffic flows freely.
        // Without this, a healthy chain would remain effectively blocked
        // until MySQL auto-releases on session close if the probe
        // succeeded quickly.
        self::releaseProbe($chainId);

        // Track last successful fetch time (persists across cache flushes)
        update_option('bcc_onchain_last_success_' . $chainId, time(), false);
    }

    /**
     * Identify chains with stale data (no successful fetch within $maxAgeSec).
     *
     * @param int[] $chainIds   Active chain IDs to check.
     * @param int   $maxAgeSec  Maximum acceptable age in seconds (default 48 hours).
     * @return array<int, array{last_success: int|null, age_human: string, circuit_status: string}>
     *               Keyed by chain ID. Only stale/never-fetched chains included.
     */
    public static function getStaleChains(array $chainIds, int $maxAgeSec = 172800): array
    {
        $stale = [];
        $now   = time();

        foreach ($chainIds as $id) {
            $id          = (int) $id;
            $lastSuccess = get_option('bcc_onchain_last_success_' . $id, null);

            $isStale = ($lastSuccess === null)
                || (($now - (int) $lastSuccess) > $maxAgeSec);

            if (!$isStale) {
                continue;
            }

            // Determine circuit breaker status for context.
            //
            // ⚠ THE SHARED DECISION, ON THE SAME $now THE STALENESS TEST
            // ABOVE USED. This branch used to compute `$now - $openedAt`
            // unconditionally and so reported HALF-OPEN whenever
            // `opened_at` was 0 — disagreeing with the worker, which
            // treated that same state as OPEN.
            $circuitStatus = strtoupper(
                self::phaseHyphenated(self::phaseFor(self::getState($id), $now))
            );

            // Human-readable age
            if ($lastSuccess === null) {
                $ageHuman = 'never fetched';
            } else {
                $ageSec   = $now - (int) $lastSuccess;
                $ageDays  = floor($ageSec / DAY_IN_SECONDS);
                $ageHours = floor(($ageSec % DAY_IN_SECONDS) / HOUR_IN_SECONDS);
                if ($ageDays > 0) {
                    $ageHuman = sprintf('last success: %d day%s ago', $ageDays, (int) $ageDays === 1 ? '' : 's');
                } else {
                    $ageHuman = sprintf('last success: %d hour%s ago', $ageHours, (int) $ageHours === 1 ? '' : 's');
                }
            }

            $stale[$id] = [
                'last_success'   => $lastSuccess !== null ? (int) $lastSuccess : null,
                'age_human'      => $ageHuman,
                'circuit_status' => $circuitStatus,
            ];
        }

        return $stale;
    }

    /**
     * Record a failed API call for a chain.
     * Increments failure counter. If threshold reached, records opened_at.
     *
     * ── ⚠ THE ATTRIBUTION PARAMETERS ARE OPTIONAL, AND THAT IS THE POINT ──
     * Twelve executable call sites charge this breaker. FOUR are inside
     * {@see ApiRetry}, where a wire outcome exists and can be named. The
     * other EIGHT are domain judgements — an empty validator index, a caught
     * exception, `eth_blockNumber` returning 0 — which have no HTTP status
     * and no transport error to name.
     *
     * Forcing one of the three tokens onto those eight would be the
     * fabricated diagnosis this whole effort exists to remove, so they pass
     * nothing and the attribution stays absent. `null` is the honest answer
     * to "which wire outcome was this?" when there was no wire outcome.
     *
     * ⚠ SOURCE-COMPATIBLE BY CONSTRUCTION: every existing caller compiles and
     * behaves exactly as before, and a record written without attribution is
     * indistinguishable from one written by the previous version.
     *
     * @param string|null $kind         a {@see ProviderFailureKind} token, or null
     * @param string|null $requestClass a {@see ProviderRequestClass} value, or null
     */
    /**
     * @param string|null $endpointFp the COMPLETE normalized endpoint identity
     *        this charge was earned against ({@see CosmosEndpointPolicy::fingerprint()}
     *        — scheme, host, effective port, base path and expected network).
     *        Optional and last, so all twelve existing callers stay
     *        source-compatible. Stored opaquely: the breaker serves nine
     *        chains and five subsystems and deliberately knows nothing about
     *        Cosmos endpoints beyond "this string identifies where the failure
     *        came from".
     */
    public static function recordFailure(
        int $chainId,
        ?string $kind = null,
        ?string $requestClass = null,
        ?string $endpointFp = null
    ): void {
        if ($endpointFp !== null && !CosmosEndpointPolicy::isFingerprint($endpointFp)) {
            $endpointFp = null;
        }
        // ⚠ VALIDATED, NOT TRUSTED — the same discipline
        // ChainCheckpointRepository::recordCwEnumerationFailure() uses. No
        // caller, present or future, can route a provider sentence, an
        // exception message or a URL into durable state through here.
        if ($kind !== null && !ProviderFailureKind::isValid($kind)) {
            $kind = null;
        }
        if ($requestClass !== null && !ProviderRequestClass::isValid($requestClass)) {
            $requestClass = null;
        }

        // Atomic DB-backed counter via INSERT … ON DUPLICATE KEY UPDATE
        // — closes the get→compute→set lost-update race. The earlier
        // implementation used wp_cache_incr which is non-atomic on
        // some drop-ins (LiteSpeed Object Cache implements it as a
        // request-local read-modify-write), letting parallel failure
        // storms drop increments and keeping the circuit closed beyond
        // its designed threshold — wasting API budget and risking IP
        // bans from providers.
        $counterOption = self::counterOptionName($chainId);

        // ── ⚠ THE COUNTER ONLY MEANS SOMETHING WHILE ITS STATE EXISTS ────
        //
        // Two stores, two lifetimes: `$failures` comes from the option
        // `_bcc_cb_counter_<id>`, which has NO expiry, while `opened_at`
        // lives in the {@see CACHE_TTL} state struct. When the struct
        // expires (or a cache flush drops it) the option survives — and
        // the counter it holds is a tally of failures inside a window that
        // no longer exists.
        //
        // The 2026-09-08 audit found chain 18 (THORChain) sitting at 3022
        // on production beside a transient that had expired four days
        // earlier. Under the old code the next single failure would have
        // read 3023, seen `opened_at === 0`, and tripped the breaker
        // instantly on ONE failure — an unexplained outage attributable to
        // nothing in the logs. "Consecutive failures" is only a meaningful
        // phrase relative to a live window, so when the window is gone the
        // count starts again.
        //
        // ⚠ THIS IS A RECONCILIATION, NOT A RESET. It runs only on the
        // failure path, only when the state is genuinely absent, and it
        // still records the failure in hand — the very next line counts it
        // as 1. An open breaker whose state is intact is never touched.
        $priorState = self::getState($chainId);
        if ($priorState === null) {
            OnchainCircuitBreakerRepository::deleteCounter($counterOption);
        }

        $incremented = OnchainCircuitBreakerRepository::incrementFailureCounter($counterOption);

        if ($incremented === null) {
            // DB error — fall back to read-modify-write of the state
            // struct so we at least record SOMETHING. Still better than
            // silently dropping the failure signal.
            $failures = (int) ($priorState['failures'] ?? 0) + 1;
        } else {
            // Atomic counter value (1 on fresh insert, the new count
            // otherwise; the repository already handles the insert_id
            // fallback SELECT).
            $failures = $incremented;
        }

        // Read from the SAME snapshot the reconciliation decided on. A
        // second `getState()` here could observe a concurrent writer and
        // resurrect the stale `opened_at` this method just invalidated.
        $openedAt = (int) ($priorState['opened_at'] ?? 0);

        // Restamp opened_at on:
        //   1. Initial threshold breach ($openedAt === 0), AND
        //   2. Failed probe during HALF-OPEN (cooldown already elapsed).
        // Without case 2 the breaker would re-enter HALF-OPEN on every
        // subsequent call forever, because (time() - openedAt) >= COOLDOWN
        // stays true — effectively disabling the breaker for the life of
        // the CACHE_TTL. Case 2 restarts the cooldown so the chain
        // actually gets the documented 5-minute rest.
        //
        // ⚠ THIS IS A WRITER'S QUESTION, NOT A PHASE REPORT, so it does not
        // go through {@see phaseFor()}. It asks "should I restamp?" about a
        // count that has just been incremented and a state that may have
        // been reconciled away a few lines above — neither of which the
        // phase calculation can see. It is safe from the epoch bug on its
        // own terms: the subtraction is guarded by `$openedAt > 0`, which
        // is exactly the guard the two status readers were missing.
        if ($failures >= self::FAILURE_THRESHOLD) {
            $now             = time();
            $cooldownElapsed = $openedAt > 0
                && ($now - $openedAt) >= self::COOLDOWN_SECONDS;
            if ($openedAt === 0 || $cooldownElapsed) {
                $previousOpenedAt = $openedAt;
                $openedAt         = $now;
                self::log(sprintf(
                    $previousOpenedAt === 0
                        ? 'Circuit OPEN for chain %d — %d consecutive failures, pausing for %ds'
                        : 'Circuit RE-OPEN (probe failed) for chain %d — %d failures, restarting %ds cooldown',
                    $chainId, $failures, self::COOLDOWN_SECONDS
                ));
                // Probe failed — release the lock so a FUTURE half-open
                // window (after the fresh cooldown) can claim a new probe.
                self::releaseProbe($chainId);
            }
        }

        // ⚠ THE ATTRIBUTION DESCRIBES THE MOST RECENT CHARGE, and is carried
        // forward when this charge could not name itself. Without the
        // carry-forward, one unattributed domain charge arriving after four
        // named transport charges would erase the only explanation the
        // operator had for a breaker that is still open for the same reason.
        $priorKind  = $priorState['kind'] ?? null;
        $priorClass = $priorState['request_class'] ?? null;
        $priorFp    = $priorState['endpoint_fp'] ?? null;

        // ⚠ A CHARGE FROM A DIFFERENT ENDPOINT DOES NOT INHERIT THE OLD ONE'S
        // STORY. When this charge names an endpoint and the stored state was
        // recorded against a different one, the carry-forward is dropped:
        // otherwise the first failure after a provider switch would be
        // described using the previous provider's kind and request class, and
        // the admin page would blame a host that had never served a request.
        $foreign = $endpointFp !== null && $priorFp !== null && $endpointFp !== $priorFp;

        self::setState($chainId, [
            'failures'      => $failures,
            'opened_at'     => $openedAt,
            'kind'          => $kind ?? ($foreign ? null : $priorKind),
            'request_class' => $requestClass ?? ($foreign ? null : $priorClass),
            'endpoint_fp'   => $endpointFp ?? $priorFp,
        ]);
    }

    /**
     * Why the breaker last charged, as bounded tokens — never prose.
     *
     * Returns nulls for a chain with no state, and for any record written
     * before attribution existed. An operator surface must treat a null as
     * "not recorded", never as "no failure".
     *
     * @return array{kind: string|null, request_class: string|null}
     */
    /**
     * @param string|null $expectedFp when supplied, attribution recorded
     *        against a DIFFERENT endpoint is withheld rather than shown.
     *
     * ⚠ SILENCE, NOT A GUESS. A caller that knows which endpoint the chain is
     * pointed at now must never be told "Provider server error" about a host
     * that has not served a single request — that is a fabricated diagnosis,
     * the exact class of lie PR 7.8 removed. `stale` says the state predates
     * the current endpoint so a surface can render "not recorded for this
     * endpoint" instead of inventing one.
     *
     * @return array{kind: string|null, request_class: string|null, endpoint_fp: string|null, stale: bool}
     */
    public static function attribution(int $chainId, ?string $expectedFp = null): array
    {
        $state = self::getState($chainId);
        $storedFp = $state['endpoint_fp'] ?? null;

        $stale = $expectedFp !== null && $storedFp !== null && $storedFp !== $expectedFp;

        return [
            'kind'          => $stale ? null : ($state['kind'] ?? null),
            'request_class' => $stale ? null : ($state['request_class'] ?? null),
            'endpoint_fp'   => $storedFp,
            'stale'         => $stale,
        ];
    }

    /**
     * Get circuit breaker status for all active chains (admin dashboard).
     *
     * @param int[] $chainIds
     * @return array<int, array{failures: int, opened_at: int, status: string, kind: string|null, request_class: string|null}>
     */
    public static function getAllStatus(array $chainIds): array
    {
        $result = [];

        // ⚠ ONE CLOCK FOR THE WHOLE SWEEP. Calling `time()` per chain let a
        // dashboard row cross the cooldown boundary mid-render, so two
        // chains in identical states could be reported differently.
        $now = time();

        foreach ($chainIds as $id) {
            $state    = self::getState((int) $id);
            $failures = (int) ($state['failures'] ?? 0);
            $openedAt = (int) ($state['opened_at'] ?? 0);

            // ⚠ THE SHARED DECISION. This branch used to compute
            // `time() - $openedAt` unconditionally and so reported
            // HALF-OPEN whenever `opened_at` was 0 — telling an
            // administrator a probe was allowed through while the worker
            // was refusing every request.
            $status = self::phaseHyphenated(self::phaseFor($state, $now));

            // ⚠ BOUNDED TOKENS ONLY, and null when nothing was recorded — a
            // pre-attribution record and a domain-level charge both read as
            // null here, which a surface must render as "not recorded"
            // rather than as "no failure".
            $result[(int) $id] = [
                'failures'      => $failures,
                'opened_at'     => $openedAt,
                'status'        => $status,
                'kind'          => $state['kind'] ?? null,
                'request_class' => $state['request_class'] ?? null,
                'endpoint_fp'   => $state['endpoint_fp'] ?? null,
            ];
        }
        return $result;
    }

    // ── Storage ─────────────────────────────────────────────────────────────

    /**
     * @return array{failures: int, opened_at: int, kind: string|null,
     *     request_class: string|null, endpoint_fp: string|null}|null
     *
     * The three optional fields are RE-VALIDATED here on the way out, not
     * merely on the way in: this state lives in a wp_cache entry plus a
     * transient, both writable by other code. A record written before any
     * of them existed reads back as null for each and behaves exactly as
     * it did before they were added.
     *
     * NOTE: when the cached value is present but malformed (schema drift,
     * mid-deploy legacy keys, cache layer corruption) we return null which
     * re-initialises the breaker. If that happens repeatedly for the SAME
     * chain, the breaker is effectively disabled. We log once per 5-minute
     * window per chain so repeated corruption surfaces in monitoring instead
     * of silently eroding the protection.
     */
    private static function getState(int $chainId): ?array
    {
        $key   = 'cb_' . $chainId;
        $value = wp_cache_get($key, self::CACHE_GROUP);

        if ($value === false) {
            // Fallback to transient if Redis cache misses
            $value = get_transient('bcc_cb_' . $chainId);
            if ($value === false) {
                return null;
            }
        }

        if (!is_array($value) || !isset($value['failures'], $value['opened_at'])) {
            self::reportPersistentCorruption($chainId);
            return null;
        }

        // ⚠ THE TWO LIFECYCLE FIELDS ARE STILL REQUIRED; THE ATTRIBUTION IS
        // NOT. A record written before attribution existed has neither key,
        // reads back as null for both, and drives exactly the behaviour it
        // drove before — the phase calculation above never consults them.
        //
        // ⚠ RE-VALIDATED ON THE WAY OUT, not merely on the way in. The state
        // lives in an object cache and a transient, both of which other code
        // can write; a value that is not a member of the closed vocabulary is
        // dropped rather than returned to a renderer.
        $kind = isset($value['kind']) && is_string($value['kind'])
            && ProviderFailureKind::isValid($value['kind'])
                ? $value['kind']
                : null;
        $requestClass = isset($value['request_class']) && is_string($value['request_class'])
            && ProviderRequestClass::isValid($value['request_class'])
                ? $value['request_class']
                : null;
        // ⚠ Re-validated on the way out like the other two, and for the same
        // reason: the store is a wp_cache entry plus a transient that other
        // code can write. A value that is not fingerprint-shaped is dropped
        // rather than compared — a malformed marker must never accidentally
        // MATCH a real endpoint and launder the old provider's attribution.
        $endpointFp = isset($value['endpoint_fp']) && is_string($value['endpoint_fp'])
            && CosmosEndpointPolicy::isFingerprint($value['endpoint_fp'])
                ? $value['endpoint_fp']
                : null;

        return [
            'failures'      => (int) $value['failures'],
            'opened_at'     => (int) $value['opened_at'],
            'kind'          => $kind,
            'request_class' => $requestClass,
            'endpoint_fp'   => $endpointFp,
        ];
    }

    /**
     * Rate-limited logger for malformed breaker state. Fires at most once per
     * 5-minute window per chain to avoid log flooding while still surfacing
     * persistent corruption patterns (e.g. a bad cache backend or a stuck
     * legacy key) to operators.
     *
     * CAVEAT — multi-node reliability: the dedup transient lives in whatever
     * backend WordPress is configured with. On a single node with Redis it is
     * consistent; on a multi-node setup WITHOUT a shared persistent object
     * cache, each node will log independently (their dedup transients are in
     * separate options tables). That's acceptable for this signal — the goal is
     * to surface the problem, and per-node logging actually HELPS diagnose
     * whether only one node's cache is corrupt. If this ever needs true global
     * dedup, move the key to a row in a small shared table.
     */
    private static function reportPersistentCorruption(int $chainId): void
    {
        $dedupKey = 'cb_corrupt_' . $chainId;
        if (get_transient($dedupKey) !== false) {
            return;
        }
        set_transient($dedupKey, 1, 5 * MINUTE_IN_SECONDS);

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[OnchainCircuitBreaker] malformed state — re-initialising', [
                'chain_id' => $chainId,
                'note'     => 'Repeated re-inits weaken protection. Investigate cache backend.',
            ]);
        }
    }

    /** @param array{failures: int, opened_at: int} $state */
    private static function setState(int $chainId, array $state): void
    {
        $key = 'cb_' . $chainId;
        wp_cache_set($key, $state, self::CACHE_GROUP, self::CACHE_TTL);
        // Transient fallback for environments without persistent object cache
        set_transient('bcc_cb_' . $chainId, $state, self::CACHE_TTL);
    }

    /**
     * wp_options row name for the per-chain DB-backed failure counter.
     * Stored as a non-autoloaded option so it never bloats the bootstrap
     * options preload.
     */
    private static function counterOptionName(int $chainId): string
    {
        return '_bcc_cb_counter_' . $chainId;
    }

    private static function log(string $message): void
    {
        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[OnchainCircuitBreaker] ' . $message);
        }
    }
}
