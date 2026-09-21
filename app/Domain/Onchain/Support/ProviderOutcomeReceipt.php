<?php

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the transport layer already told the circuit breaker about ONE logical
 * provider request — handed back to the caller so a domain verdict about the
 * same request cannot charge it a second time.
 *
 * ── THE PROBLEM THIS SOLVES ─────────────────────────────────────────────
 * Ten executable call sites charge {@see OnchainCircuitBreaker}. TWO are
 * inside {@see ApiRetry} — the single settlement helper and the batch wave —
 * where a wire outcome exists; the other eight are domain judgements ("the
 * validator index came back empty", "eth_blockNumber returned 0"). On the
 * paths where both fire, ONE failing operation charged the breaker FIVE
 * times — four per-attempt transport charges plus the caller's verdict —
 * against a threshold of five. A single failing head poll opened the
 * chain-wide breaker on its own.
 *
 * (ApiRetry had FOUR charge sites before this change: 429, 5xx, transport
 * and the batch. The first three were funnelled into one helper, which is
 * what makes "at most one charge per logical request" structural. The count
 * is pinned by BreakerAttributionTest's caller inventory.)
 *
 * The per-attempt half is fixed inside {@see ApiRetry} by settling once when
 * the retry sequence ends. This receipt fixes the other half: the caller can
 * ask whether the transport layer already recorded a failure for the very
 * request it is about to judge.
 *
 * ── WHY IT IS AN EXPLICIT ARGUMENT AND NOT AMBIENT STATE ────────────────
 * A static "current operation", a singleton, a transient or a per-chain flag
 * would all work in a single-threaded test and then leak in production: one
 * wallet's result would suppress the next wallet's, one page's would be
 * inherited by the following page, and two workers on one chain would read
 * each other's. So a receipt is created BY the caller, for ONE endpoint + one
 * subject + one operation, passed down explicitly, and thrown away. A receipt
 * constructed inside a loop body cannot outlive its iteration; there is no
 * global anyone could read by accident.
 *
 * ── WHAT "ONE LOGICAL REQUEST" MEANS ────────────────────────────────────
 * One endpoint, one subject, one API/RPC operation, INCLUDING every retry
 * attempt of that same operation. A different page, wallet, contract or
 * method is a different logical request and gets its own receipt. A cron run,
 * a worker tick, a chain or a pagination walk is never one logical request.
 *
 * ⚠ THIS OBJECT NEVER TOUCHES THE BREAKER. It only records what already
 * happened, so reading it has no side effect and a caller that ignores it
 * behaves exactly as it did before.
 */
final class ProviderOutcomeReceipt
{
    /** Nothing was settled — no provider call was made, or its outcome charged nothing (3xx, non-429 4xx, an application error). */
    public const NONE = 'none';

    /** The transport layer credited a success, which cleared the chain's failure counter. */
    public const SUCCESS = 'success';

    /** The transport layer charged exactly one failure for this logical request. */
    public const FAILURE = 'failure';

    /** The breaker was already open, so no request was made and nothing was observed. */
    public const BLOCKED = 'blocked';

    private string $last = self::NONE;
    private ?string $lastFailureKind = null;
    private int $failureCharges = 0;
    private int $successCredits = 0;

    /**
     * Record that the transport layer just charged the breaker one failure.
     *
     * @param string|null $kind a {@see \BCC\Trust\Onchain\ValueObjects\ProviderFailureKind}
     *                          token, or null when there was no nameable wire outcome
     */
    public function recordTransportFailureCharge(?string $kind = null): void
    {
        $this->last = self::FAILURE;
        $this->lastFailureKind = $kind;
        $this->failureCharges++;
    }

    /** Record that the transport layer just credited a success. */
    public function recordTransportSuccessCredit(): void
    {
        $this->last = self::SUCCESS;
        $this->successCredits++;
    }

    /** Record that an already-open breaker refused the request before it was made. */
    public function recordBlockedByOpenBreaker(): void
    {
        $this->last = self::BLOCKED;
    }

    /**
     * The outcome of the LAST settlement on this receipt.
     *
     * A receipt may be threaded through a helper that makes several calls in
     * sequence (a paginated validator index, for example). The question a
     * domain verdict asks is always about the call that ENDED the operation,
     * so the last settlement is the one that answers it.
     */
    public function lastOutcome(): string
    {
        return $this->last;
    }

    /** Did the transport layer already charge a failure for this request? */
    public function transportChargedFailure(): bool
    {
        return $this->last === self::FAILURE;
    }

    /**
     * May the caller charge its own domain verdict for this request?
     *
     * TRUE when the transport layer charged nothing (so a semantic failure is
     * this operation's only charge) and when it credited a SUCCESS — a
     * provider that answers 200 with an unusable payload has genuinely failed
     * the operation, and that failure is not a duplicate of anything.
     *
     * FALSE when transport already charged (the verdict would be the second
     * charge for one logical request) and when an open breaker refused the
     * call (nothing was observed, so there is nothing to judge, and piling
     * charges onto an already-open breaker only inflates its counter).
     */
    public function domainMayCharge(): bool
    {
        return $this->last === self::NONE || $this->last === self::SUCCESS;
    }

    /** How many failures the transport layer charged through this receipt. */
    public function failureCharges(): int
    {
        return $this->failureCharges;
    }

    /** How many successes the transport layer credited through this receipt. */
    public function successCredits(): int
    {
        return $this->successCredits;
    }

    /** The bounded failure token of the last charge, or null when none was nameable. */
    public function lastFailureKind(): ?string
    {
        return $this->lastFailureKind;
    }
}
