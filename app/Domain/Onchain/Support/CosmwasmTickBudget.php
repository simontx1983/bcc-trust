<?php

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-invocation budget for a CosmWasm discovery tick.
 *
 * TWO independent ceilings, and the WALL CLOCK ALWAYS WINS.
 *
 *   1. Wall clock — {@see CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS}.
 *      Hostinger Business shared caps PHP `max_execution_time` at 30s.
 *      Being killed mid-write is the failure mode that actually costs
 *      progress, so the tick stops on the deadline EVEN IF requests
 *      remain. {@see exhausted()} checks the clock first for exactly
 *      that reason.
 *   2. Request/page budget — a configurable ceiling (default 50 per
 *      invocation, `BCC_COSMWASM_REQUEST_BUDGET`) so a fast node cannot
 *      turn a 20-second window into hundreds of LCD calls.
 *
 * The object is intentionally dumb and injectable: the worker builds
 * one, hands it to every step, and tests construct one with a tiny
 * budget to pin "the backfill stops at its budget" without any timing
 * dependency.
 */
final class CosmwasmTickBudget
{
    private float $deadline;
    private int $remaining;
    private int $spent = 0;

    /**
     * Requests the CURRENT stage may not touch, held for later stages.
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────
     * The incremental pass runs four stages in a fixed order against ONE
     * budget: family classification, confirmed-family enumeration,
     * contract classification, then emission. Nothing stopped the first
     * stage spending all 50 requests, and on a chain with a classification
     * backlog it reliably did — so enumeration, contract classification
     * and emission never ran. Measured on Dungeon: a confirmed CW-721
     * family and an already-emittable contract sat untouched while the
     * queue in front of them was worked through, pass after pass. The
     * pipeline was healthy at every stage and produced nothing.
     *
     * ── IT ONLY EVER RESTRICTS ──────────────────────────────────────────
     * The reserve is subtracted from what a caller may spend. It CANNOT
     * grant anyone extra: there is still one budget, one ceiling, and one
     * object. Setting it to 0 is exactly today's behaviour.
     *
     * ── AND IT IS CHECKED ON EVERY SPEND, NOT PER STAGE ─────────────────
     * A guard at the top of a stage is not enough. `classifyFamily()` can
     * cost up to 10 requests across four separate `canSpend()` calls, so a
     * stage that was affordable when it started can still overshoot its
     * allocation mid-item. Because {@see canSpend()} and {@see exhausted()}
     * both read the reserve, the floor holds at the granularity of a
     * single request — the sample loop inside a family stops as soon as
     * one more probe would eat into the next stage's share.
     */
    private int $reserve = 0;

    public function __construct(?int $requests = null, ?int $runtimeSeconds = null)
    {
        $this->remaining = $requests ?? CosmwasmDiscoveryGate::requestBudget();
        $seconds         = $runtimeSeconds ?? CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS;
        $this->deadline  = microtime(true) + (float) max(1, $seconds);
    }

    /**
     * Hold back `$n` requests from whoever spends next.
     *
     * Callers set this to the total maximum cost of one useful unit of
     * work in each stage that still has to run. Lowering it hands the
     * held requests to the next stage; 0 releases everything.
     */
    public function reserve(int $n): void
    {
        $this->reserve = max(0, $n);
    }

    /** What the current caller may actually spend. */
    public function available(): int
    {
        return max(0, $this->remaining - $this->reserve);
    }

    /** TRUE once the wall clock is spent — checked before the request budget. */
    public function timedOut(): bool
    {
        return microtime(true) >= $this->deadline;
    }

    /**
     * TRUE when this tick must stop.
     *
     * Deliberately clock-first: a tick with 40 requests left but no time
     * left must stop, or the next write lands after the process is
     * killed.
     */
    public function exhausted(): bool
    {
        return $this->timedOut() || $this->available() <= 0;
    }

    /**
     * Can we afford $n more requests (and do we still have the clock)?
     *
     * Reads {@see available()}, not the raw remainder, so an active
     * {@see reserve()} stops a multi-request item mid-flight rather than
     * only at the stage boundary.
     */
    public function canSpend(int $n = 1): bool
    {
        return !$this->timedOut() && $this->available() >= max(1, $n);
    }

    /** Charge $n requests. Charged even on failure — the call was made. */
    public function spend(int $n = 1): void
    {
        $n = max(1, $n);
        $this->remaining -= $n;
        $this->spent     += $n;
    }

    public function remaining(): int
    {
        return max(0, $this->remaining);
    }

    public function spent(): int
    {
        return $this->spent;
    }
}
