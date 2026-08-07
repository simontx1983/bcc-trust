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

    public function __construct(?int $requests = null, ?int $runtimeSeconds = null)
    {
        $this->remaining = $requests ?? CosmwasmDiscoveryGate::requestBudget();
        $seconds         = $runtimeSeconds ?? CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS;
        $this->deadline  = microtime(true) + (float) max(1, $seconds);
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
        return $this->timedOut() || $this->remaining <= 0;
    }

    /** Can we afford $n more requests (and do we still have the clock)? */
    public function canSpend(int $n = 1): bool
    {
        return !$this->timedOut() && $this->remaining >= max(1, $n);
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
