<?php

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-invocation ceiling on provider work: how many requests, and for how long.
 *
 * ── WHY THIS IS NOT A SCANNER TYPE ──────────────────────────────────────
 * This began as the CosmWasm discovery tick's budget and read its two
 * defaults from `CosmwasmDiscoveryGate`. That made every ownership surface
 * that bounds provider work — join, stance, the eligible-group listing, the
 * profile badge, reconciliation and the revoke sweep — depend on the
 * full-chain scanner for a number that has nothing to do with scanning.
 * Ownership must keep working with the scanner frozen, so the primitive is
 * neutral: it holds no scanner constant, names no scanner class, and both
 * ceilings are supplied BY THE CALLER.
 *
 * Every caller therefore states its own ceiling: the ownership surfaces from
 * `HoldingsService::SURFACE_BUDGETS`, the scanner from `CosmwasmDiscoveryGate`.
 * Neither can silently inherit the other's number. Guarded by
 * ProviderRequestBudgetIsNeutralTest.
 *
 * TWO independent ceilings, and the WALL CLOCK ALWAYS WINS.
 *
 *   1. Wall clock — seconds from construction. Hostinger Business shared caps
 *      PHP `max_execution_time` at 30s. Being killed mid-write is the failure
 *      mode that actually costs progress, so work stops on the deadline EVEN
 *      IF requests remain. {@see exhausted()} checks the clock first for
 *      exactly that reason.
 *   2. Request/page budget — so a fast node cannot turn a 20-second window
 *      into hundreds of provider calls.
 *
 * ⚠ A budget that runs out is NOT evidence about what a member holds. Callers
 * must surface an exhausted budget as UNKNOWN (fail open), never as a
 * non-holding — see `EligibilityVerdict::REASON_BUDGET_EXHAUSTED`.
 *
 * The object is intentionally dumb and injectable: a caller builds one, hands
 * it to every step, and tests construct one with a tiny budget to pin "this
 * stops at its budget" without any timing dependency.
 */
final class ProviderRequestBudget
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

    /**
     * Both ceilings are REQUIRED. There is deliberately no default: a default
     * here could only come from one subsystem, and that is exactly the
     * coupling this type was split out of. A caller that does not know its own
     * ceiling does not have one.
     *
     * @param int $requests       Provider calls this invocation may spend.
     * @param int $runtimeSeconds Wall-clock seconds from now; at least 1.
     */
    public function __construct(int $requests, int $runtimeSeconds)
    {
        $this->remaining = $requests;
        $this->deadline  = microtime(true) + (float) max(1, $runtimeSeconds);
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
