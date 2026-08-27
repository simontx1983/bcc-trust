<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WHY A BOUNDED PASS STOPPED, IN ONE MACHINE-READABLE TOKEN.
 *
 * ── WHY THIS IS ITS OWN CLASS ───────────────────────────────────────────
 * Lifted verbatim out of the supervised one-shot WP-CLI command, which was
 * the only caller until the admin runner needed the same answer.
 *
 * (That command is deliberately not named here. A test asserts that NO file
 * under `app/` outside its own definition mentions it by name — a raw
 * content match, so even a docblock reference counts — because a mention is
 * one edit away from a call, and a CLI-only entry point reachable from
 * web-served code stops being CLI-only.)
 *
 * The alternative was to re-derive it in the admin handler. That is exactly
 * the shape this codebase has been bitten by twice: two copies of one rule,
 * written to agree, drifting anyway. An operator reading "budget exhausted"
 * in wp-admin and "runtime deadline reached" from the CLI for the same pass
 * would raise the wrong ceiling — and would have no way to know which
 * surface was lying.
 *
 * The extraction mirrors what {@see AlchemyEndpoint} did for the Alchemy
 * regex: one fact, one home, both callers pointed at it.
 *
 * ── THE CLOCK IS CHECKED BEFORE THE BUDGET ──────────────────────────────
 * Same ordering as {@see CosmwasmTickBudget::exhausted()}, and for the same
 * reason: a pass with requests left but no time left stopped because of the
 * clock. Reporting `request_budget_exhausted` there would send an operator
 * to raise a ceiling that was never the constraint.
 *
 * ── IT DESCRIBES, IT DOES NOT DECIDE ────────────────────────────────────
 * Pure. Reads a budget, returns a token. It performs no I/O, writes nothing,
 * and can never change what a pass did.
 */
final class CosmwasmPassStopReason
{
    /** A concurrent holder had the per-chain advisory lock. Nothing ran. */
    public const LOCK_CONTENDED = 'lock_contended';

    /** The chain refused to prepare: paused, unsupported, breaker open, no driver. */
    public const CHAIN_REFUSED_TO_PREPARE = 'chain_refused_to_prepare';

    /** The pass threw. */
    public const EXECUTION_FAILED = 'execution_failed';

    /** The wall clock ran out. Checked BEFORE the request budget. */
    public const RUNTIME_DEADLINE_REACHED = 'runtime_deadline_reached';

    /** The request budget ran out with time still on the clock. */
    public const REQUEST_BUDGET_EXHAUSTED = 'request_budget_exhausted';

    /** The pass ran to its own conclusion inside both ceilings. */
    public const PASS_COMPLETED = 'pass_completed';

    /**
     * PURE.
     *
     * @param string $outcome one of the `CosmwasmDiscoveryWorker::PASS_*` constants
     */
    public static function forOutcome(string $outcome, CosmwasmTickBudget $budget): string
    {
        if ($outcome === CosmwasmDiscoveryWorker::PASS_LOCKED) {
            return self::LOCK_CONTENDED;
        }
        if ($outcome === CosmwasmDiscoveryWorker::PASS_SKIPPED) {
            return self::CHAIN_REFUSED_TO_PREPARE;
        }
        if ($outcome === CosmwasmDiscoveryWorker::PASS_FAILED) {
            return self::EXECUTION_FAILED;
        }
        if ($budget->timedOut()) {
            return self::RUNTIME_DEADLINE_REACHED;
        }
        if ($budget->remaining() <= 0) {
            return self::REQUEST_BUDGET_EXHAUSTED;
        }

        return self::PASS_COMPLETED;
    }

    /**
     * PURE. Did the pass stop short of its own conclusion?
     *
     * The load-bearing question for any surface that reports a run: only
     * {@see PASS_COMPLETED} may be described as a finished pass. Every other
     * token — including the two ceiling tokens, which follow a pass that did
     * real work — means what was found is a SUBSET.
     *
     * Written as an identity test against the one completing value rather
     * than a list of exclusions, so a token from a newer build is treated as
     * incomplete rather than silently reported as done.
     */
    public static function isPartial(string $stopReason): bool
    {
        return $stopReason !== self::PASS_COMPLETED;
    }
}
