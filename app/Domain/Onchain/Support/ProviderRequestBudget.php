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

    /** @var \Closure(): float */
    private \Closure $clock;


    /**
     * Both ceilings are REQUIRED. There is deliberately no default: a default
     * here could only come from one subsystem, and that is exactly the
     * coupling this type was split out of. A caller that does not know its own
     * ceiling does not have one.
     *
     * `$clock` is for TESTS ONLY: it lets a test pin the clock arm ("this
     * stops on its deadline and resumes where it left off") without depending
     * on how fast the machine is. Production never passes it, so the deadline
     * is always real wall-clock time.
     *
     * @param int                    $requests       Provider calls this invocation may spend.
     * @param int                    $runtimeSeconds Wall-clock seconds from now; at least 1.
     * @param (\Closure(): float)|null $clock         Seconds now; defaults to microtime(true).
     */
    public function __construct(int $requests, int $runtimeSeconds, ?\Closure $clock = null)
    {
        $this->clock     = $clock ?? static fn (): float => microtime(true);
        $this->remaining = $requests;
        $this->deadline  = ($this->clock)() + (float) max(1, $runtimeSeconds);
    }


    /**
     * What the current caller may actually spend.
     *
     * ⚠ S8 REMOVED THE STAGE RESERVE. It existed so the incremental
     * discovery pass's four stages could not let the first one spend the
     * whole budget; it was only ever set by that pass, which is deleted.
     * With no writer, the reserve was permanently 0, so subtracting it was
     * arithmetic with no effect — and a method nothing can set is worse than
     * no method, because it reads as a control that works.
     */
    public function available(): int
    {
        return max(0, $this->remaining);
    }

    /** TRUE once the wall clock is spent — checked before the request budget. */
    public function timedOut(): bool
    {
        return ($this->clock)() >= $this->deadline;
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
     * Reads {@see available()} rather than the raw remainder so the clock and
     * the request ceiling are always consulted through one accessor.
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
