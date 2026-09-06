<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;

/**
 * The ceilings that bound ONE administrator-authorized scan session, and the
 * single pure decision "may this run take another chunk?".
 *
 * ── THE DISTINCTION THIS CLASS EXISTS TO HOLD ───────────────────────────
 * **Automatic scan CREATION is forbidden. Bounded CONTINUATION of an already
 * authorized run is permitted.**
 *
 * Nothing here can select a chain, and nothing here can create a run. It is
 * handed a run row that an administrator already created and answers yes or
 * no. Every "no" is a bounded reason an operator can read.
 *
 * ── WHY A SESSION AT ALL ────────────────────────────────────────────────
 * Measured on the two Cosmos Hub staging canaries, 2026-09-04:
 *
 *   run 1 `461db4c0…` historical  — 48 requests, 17 s, 737 families
 *                                   enumerated,  5 classified
 *   run 2 `d948e894…` incremental — 48 requests, 16 s,  7 classified,
 *                                   5 new code ids, 104 contracts
 *
 * A chunk settles about SEVEN families. With 730 remaining that is roughly
 * 104 administrator clicks to finish one chain. One click that authorizes
 * {@see MAX_CHUNKS} chunks turns that into four or five.
 *
 * ── WHAT IS *NOT* RELAXED ───────────────────────────────────────────────
 * The per-chunk ceilings are untouched: {@see CosmwasmDiscoveryGate::requestBudget()}
 * and {@see CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS} still bound every
 * chunk exactly as they bounded every single-pass run. Making one chunk
 * bigger would just move the problem into a PHP process Hostinger will kill;
 * many small recoverable chunks is the whole point.
 */
final class DiscoveryScanSession
{
    /**
     * Chunks one administrator click may authorize.
     *
     * 25 × ~7 families ≈ 175 families per click, so Cosmos Hub's 730-family
     * backlog needs four or five sessions instead of ~104. Deliberately not
     * larger: a session should finish in minutes and be easy to reason about
     * when something goes wrong halfway through.
     */
    public const MAX_CHUNKS = 25;

    /**
     * Cumulative provider requests across the whole session.
     *
     * 25 chunks × the 50-request default ceiling. Enforced against the run's
     * own accumulated `requests_used`, so a chunk that somehow spent more
     * than its budget still cannot push the session past this.
     *
     * ⚠ `requests_used` is SMALLINT UNSIGNED (max 65535). This ceiling is two
     * orders of magnitude below that, so the column cannot wrap.
     */
    public const MAX_REQUESTS = 1250;

    /**
     * Wall-clock age of the session, measured from `requested_at`.
     *
     * 25 chunks at ~16 s plus a 15 s gap each is about 13 minutes. An hour
     * leaves generous room for Action Scheduler jitter while still bounding
     * a session whose chunks have stopped being picked up.
     */
    public const MAX_AGE_SECONDS = 3600;

    /**
     * Gap between chunks.
     *
     * At 48 requests per ~16 s chunk plus this gap, the session averages
     * roughly 1.5 requests per second against a free public LCD proxy, and
     * never runs two discovery PHP processes back to back on shared hosting.
     */
    public const CHUNK_DELAY_SECONDS = 15;

    /**
     * Chunks reporting pass-level provider errors before the session stops.
     *
     * One. A session is a convenience, not a retry engine: if the tail read
     * or a page fetch failed, the honest thing is to finish, say so, and let
     * the operator decide. Per-FAMILY `temporarily_unreachable` is NOT a
     * pass-level error and does not end a session — that verdict has its own
     * 6 h → 28 d backoff in {@see CosmwasmClassifier}, which this class does
     * not touch.
     */
    public const MAX_ERROR_CHUNKS = 1;

    // ── bounded reasons a session stops ─────────────────────────────────
    //
    // All ≤ 40 characters: they are written to `bcc_discovery_runs.stop_reason`.

    /** Every authorized chunk was used and work remains. */
    public const STOP_CHUNK_CEILING = 'session_chunk_ceiling';

    /** Cumulative provider requests reached the session ceiling. */
    public const STOP_REQUEST_CEILING = 'session_request_ceiling';

    /** The session outlived its wall-clock window. */
    public const STOP_AGE_CEILING = 'session_age_ceiling';

    /** A chunk reported pass-level provider errors. */
    public const STOP_PROVIDER_ERRORS = 'session_provider_errors';

    /** Support, opt-in, allowlist or pause changed under the session. */
    public const STOP_NOT_READY = 'session_not_ready';

    /**
     * Nothing is claimable right now, but requeueable work exists.
     *
     * ⚠ NOT "complete". The classifier's minimum backoff is six hours and the
     * session's whole window is one, so delayed work can never become
     * eligible inside a session — waiting would be a guaranteed busy loop.
     * The session finishes and the panel reports the delayed count.
     */
    public const STOP_DELAYED_WORK = 'session_delayed_work';

    /** The queue is genuinely empty. The ordinary successful ending. */
    public const STOP_COMPLETED = 'pass_completed';

    /**
     * May this run take another chunk?
     *
     * PURE. It reads no database and contacts nothing; every input is
     * supplied by the executor, which is also the only thing that acts on
     * the answer.
     *
     * Order is the safety property. Readiness and cancellation are asked
     * BEFORE any ceiling, so a chain that lost its support cannot be kept
     * alive by having chunks left; and the ceilings are asked before
     * "is there work", so a runaway chain cannot outrun its own budget.
     *
     * ⚠ `chunks_used` INCLUDES THE CHUNK THAT JUST FINISHED. The caller adds
     * it, because the ledger row is only incremented when the chunk is
     * released — and a ceiling that ignored the chunk in hand would authorise
     * one more than it says.
     *
     * @param array{
     *     chunks_used: int,
     *     requests_used: int,
     *     age_seconds: int,
     *     error_chunks: int,
     *     ready: bool,
     *     cancelled: bool,
     *     eligible_now: int,
     *     delayed: int
     * } $ctx
     * @return array{continue: bool, reason: string}
     */
    public static function decide(array $ctx): array
    {
        $stop = static fn(string $reason): array => ['continue' => false, 'reason' => $reason];

        // ── 1. authorization first ──────────────────────────────────────
        // A withdrawn run and a chain that lost its support both mean the
        // administrator's authorization no longer covers more provider work.
        if (($ctx['cancelled'] ?? false) === true) {
            return $stop(self::STOP_NOT_READY);
        }

        if (($ctx['ready'] ?? false) !== true) {
            return $stop(self::STOP_NOT_READY);
        }

        // ── 2. then the ceilings ────────────────────────────────────────
        if ((int) ($ctx['error_chunks'] ?? 0) >= self::MAX_ERROR_CHUNKS) {
            return $stop(self::STOP_PROVIDER_ERRORS);
        }

        if ((int) ($ctx['chunks_used'] ?? 0) >= self::MAX_CHUNKS) {
            return $stop(self::STOP_CHUNK_CEILING);
        }

        if ((int) ($ctx['requests_used'] ?? 0) >= self::MAX_REQUESTS) {
            return $stop(self::STOP_REQUEST_CEILING);
        }

        if ((int) ($ctx['age_seconds'] ?? 0) >= self::MAX_AGE_SECONDS) {
            return $stop(self::STOP_AGE_CEILING);
        }

        // ── 3. only then, is there anything to do ───────────────────────
        //
        // ⚠ `eligible_now`, NEVER the panel's `remaining_families`. A family
        // whose last fetch failed is still "remaining" but carries a future
        // `next_attempt_at`, so scheduling a chunk for it would burn the
        // whole session on empty passes.
        if ((int) ($ctx['eligible_now'] ?? 0) > 0) {
            return ['continue' => true, 'reason' => ''];
        }

        return $stop(
            (int) ($ctx['delayed'] ?? 0) > 0 ? self::STOP_DELAYED_WORK : self::STOP_COMPLETED
        );
    }

    /**
     * Is this stop reason one of the session's own?
     *
     * Lets the panel tell "your authorized session ended, press Continue"
     * apart from a single pass's budget stop, without either of them having
     * to know the other's vocabulary.
     */
    public static function isSessionStop(string $reason): bool
    {
        return in_array($reason, [
            self::STOP_CHUNK_CEILING,
            self::STOP_REQUEST_CEILING,
            self::STOP_AGE_CEILING,
            self::STOP_PROVIDER_ERRORS,
            self::STOP_NOT_READY,
            self::STOP_DELAYED_WORK,
        ], true);
    }

    /**
     * Operator-facing sentence for a session ending.
     *
     * Never promises a completion time, and never says the chain has no NFTs.
     */
    public static function stopSentence(string $reason): string
    {
        switch ($reason) {
            case self::STOP_CHUNK_CEILING:
                return __(
                    'This session used all of its protected batches. Work remains, so it stopped rather than continuing unattended.',
                    'bcc-trust'
                );
            case self::STOP_REQUEST_CEILING:
                return __(
                    'This session reached its limit on requests to the chain. Work remains.',
                    'bcc-trust'
                );
            case self::STOP_AGE_CEILING:
                return __(
                    'This session reached its time limit. Work remains.',
                    'bcc-trust'
                );
            case self::STOP_PROVIDER_ERRORS:
                return __(
                    'The chain did not answer reliably, so the session stopped early. Nothing was recorded as a result.',
                    'bcc-trust'
                );
            case self::STOP_NOT_READY:
                return __(
                    'Scanning is no longer enabled for this chain, so the session stopped.',
                    'bcc-trust'
                );
            case self::STOP_DELAYED_WORK:
                return __(
                    'The families that remain are waiting to be retried later. Nothing more can be checked right now.',
                    'bcc-trust'
                );
            default:
                return '';
        }
    }

    /**
     * Seconds until the next chunk may claim.
     *
     * ⚠ It must exceed nothing in particular except zero — the lease is
     * released before this is scheduled, so the delay is politeness to the
     * provider and to shared hosting, not a correctness device.
     */
    public static function nextChunkDelay(): int
    {
        return max(1, self::CHUNK_DELAY_SECONDS);
    }

    /**
     * The per-chunk ceilings, for display and for documentation.
     *
     * Reads the canonical owners rather than restating them, so a change to
     * the request budget cannot leave this class quoting a stale number.
     *
     * @return array{requests: int, seconds: int}
     */
    public static function chunkCeilings(): array
    {
        return [
            'requests' => CosmwasmDiscoveryGate::requestBudget(),
            'seconds'  => CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS,
        ];
    }

    /**
     * Upper bound on cumulative EXECUTION time for one session, in seconds.
     *
     * Derived, not stored: every chunk is already bounded by the gate's
     * runtime deadline, so the session cannot exceed chunks × that deadline.
     * A separate durable counter would re-enforce a bound the per-chunk
     * budget already guarantees.
     */
    public static function maxExecutionSeconds(): int
    {
        return self::MAX_CHUNKS * CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS;
    }

    /**
     * Attempts a single chunk may consume before the run is failed.
     *
     * Restates the repository's own constant so the panel and the docs have
     * one place to read it from. It is per CHUNK, not per session:
     * {@see DiscoveryRunRepository::releaseForNextChunk()} resets the counter
     * when a chunk succeeds, because a completed chunk is proof the worker is
     * alive. The session's own bound is {@see MAX_CHUNKS}.
     */
    public static function attemptsPerChunk(): int
    {
        return DiscoveryRunRepository::MAX_ATTEMPTS;
    }
}
