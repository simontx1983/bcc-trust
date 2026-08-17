<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE ONE ANSWER TO "WILL THE SCANNER WALK THIS CHAIN, AND IF NOT, WHY
 * NOT?" — shared by the worker that acts on it and the panel that displays
 * it.
 *
 * ── WHY THIS CLASS EXISTS ───────────────────────────────────────────────
 * It used to be two rules. {@see \BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker::eligibleChainIds()}
 * decided which chains a pass may touch; {@see \BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot}
 * decided, separately, what to tell an operator. They were written to
 * agree, and they drifted anyway — twice, in the same fortnight:
 *
 *   1. The panel counted every chain that was neither paused nor
 *      unsupported as "eligible", including chains nobody had opted in. A
 *      site whose only opt-in was an unsupported chain read GREEN.
 *   2. The panel's status arithmetic then keyed on `paused || unsupported`
 *      and never consulted the allowlist at all, so an opted-in chain
 *      sitting OUTSIDE `BCC_COSMWASM_CHAIN_ALLOWLIST` still counted as
 *      scannable — while `eligible_chain_count` on the same summary said
 *      0 and the worker skipped it.
 *
 * Both were the same bug: two definitions of "scannable" maintained by
 * hand. Two definitions is how a dashboard starts lying, and a dashboard
 * that disagrees with the worker is worse than no dashboard, because it is
 * believed. So there is now exactly ONE definition and both sides call it.
 *
 * ── WHAT IS AND IS NOT SHARED ───────────────────────────────────────────
 * The worker RESOLVES chains from the database (`getActive()` + the
 * checkpoint table + the allowlist constant); the panel works over rows it
 * has already fetched for other reasons. They cannot share the query — and
 * should not, since the panel's whole discipline is four bounded reads and
 * no per-row lookup. What they share is the PREDICATE: given the same
 * three facts about a chain, both get the same verdict from the same
 * lines of code.
 *
 * @see \BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker::eligibleChainIds()
 * @see \BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot::deriveChainRow()
 */
final class CosmwasmScanEligibility
{
    // ── THE VERDICTS ────────────────────────────────────────────────────
    //
    // EXACTLY ONE OF THEM MEANS "WILL BE SCANNED": ELIGIBLE. Every other
    // value — including the one that means "we could not tell" — is a NO.
    // That is deliberate: this is a fail-closed rule, and a display of it
    // that guessed in the permissive direction would invite an operator to
    // conclude a chain is covered when it is not.

    /** Nothing is blocking this chain; it is in the scanner's rotation. */
    public const ELIGIBLE = 'eligible';

    /** OPERATOR INTENT: `wp_bcc_chains.cosmwasm_nft_discovery_enabled` = 0. */
    public const NOT_OPTED_IN = 'not_opted_in';

    /** MEASURED CAPABILITY: the chain's wasm module answered with a 501. */
    public const UNSUPPORTED = 'unsupported';

    /**
     * OPERATOR HOLD: `cw_discovery_state = paused`.
     *
     * A separate verdict from {@see NOT_OPTED_IN} because the two are
     * different decisions with different exits: one is "do not consider
     * this chain at all", the other is "stop for now, keep the progress".
     * Both are a NO, and both are reversed with one click — from different
     * columns of the panel.
     */
    public const PAUSED = 'paused';

    /** Outside the temporary `BCC_COSMWASM_CHAIN_ALLOWLIST` canary scope. */
    public const ALLOWLIST_EXCLUDED = 'allowlist_excluded';

    /**
     * The opt-in could not be read at all — the projection carries no
     * `cosmwasm_nft_discovery_enabled` property, which is what a
     * pre-migration install (or a stale pre-migration transient) looks
     * like.
     *
     * A SEPARATE value from {@see NOT_OPTED_IN} because the two are
     * different facts: one says an operator decided no, the other says
     * nobody has been able to decide anything yet. Both are NOT scannable.
     */
    public const UNKNOWN = 'unknown';

    /**
     * PURE. The verdict for one chain.
     *
     * ── THE FIVE CONDITIONS ─────────────────────────────────────────────
     *   checkpoint.cw_discovery_state != 'unsupported'
     *                                     MEASURED CAPABILITY — the chain
     *                                     answered the code listing with a
     *                                     501, and no operator action can
     *                                     make a wasm module appear.
     *   cosmwasm_nft_discovery_enabled = 1
     *                                     OPERATOR INTENT.
     *   checkpoint.cw_discovery_state != 'paused'
     *                                     OPERATOR HOLD.
     *   allowlist undefined OR id IN allowlist
     *                                     TEMPORARY CANARY SCOPE.
     *
     * `is_active` and `chain_type` are NOT repeated here: both callers
     * enumerate from a chain list that is already filtered to active
     * Cosmos chains, and re-asking would be a third place to get it wrong.
     *
     * ── ORDER IS EXPLANATION, NOT LOGIC ─────────────────────────────────
     * The SET of eligible chains is the same whatever order these are
     * asked in — every one of them is an AND. The order chosen is the one
     * that produces the most useful SENTENCE when the answer is no: the
     * reason no operator action can change (`unsupported`) is named ahead
     * of the reasons they can, and "nobody could read the column"
     * (`unknown`) is named ahead of "an operator said no", because telling
     * somebody they declined something they were never offered sends them
     * looking for a switch that is not there.
     *
     * ── EVERY UNSURE BRANCH RETURNS "NOT SCANNABLE" ─────────────────────
     * `$optedIn === null` (the column is absent from the projection) is a
     * NO, not a skipped filter. `$allowlist === []` — the gate's "defined
     * but names no usable chain id" answer — excludes every chain, because
     * `in_array()` on an empty list is false; it needs no special case
     * here, and it must never fall through to "no restriction". That
     * fall-through is the fail-OPEN shape this codebase already shipped
     * once, as `if (!defined(...)) return true;`.
     *
     * ── A MISSING CHECKPOINT ROW IS ALLOWED THROUGH ─────────────────────
     * `$cwDiscoveryState === null` means no pass has ever measured this
     * chain. It is neither unsupported nor paused, so it can be scannable
     * — and it has to be: the first pass is what CREATES the measurement,
     * so refusing an unmeasured chain would be a permanent deadlock
     * dressed up as caution. The operator flag is the gate, not the
     * checkpoint table.
     *
     * @param  int            $chainId
     * @param  string|null    $cwDiscoveryState null = no checkpoint row yet
     * @param  bool|null      $optedIn          null = the opt-in column is absent from the projection
     * @param  list<int>|null $allowlist        null = BCC_COSMWASM_CHAIN_ALLOWLIST is undefined
     * @return string one of the six verdict constants on this class
     */
    public static function verdict(
        int $chainId,
        ?string $cwDiscoveryState,
        ?bool $optedIn,
        ?array $allowlist
    ): string {
        if ($cwDiscoveryState === ChainCheckpointRepository::CW_STATE_UNSUPPORTED) {
            return self::UNSUPPORTED;
        }
        if ($optedIn === null) {
            return self::UNKNOWN;
        }
        if ($optedIn === false) {
            return self::NOT_OPTED_IN;
        }
        if ($cwDiscoveryState === ChainCheckpointRepository::CW_STATE_PAUSED) {
            return self::PAUSED;
        }
        if ($allowlist !== null && !in_array($chainId, $allowlist, true)) {
            return self::ALLOWLIST_EXCLUDED;
        }

        return self::ELIGIBLE;
    }

    /**
     * PURE. Is this verdict the one that means the scanner will walk the
     * chain?
     *
     * Written as an identity test against ONE value rather than a list of
     * exclusions on purpose. A verdict from a newer build, a typo, an
     * empty string or a value a partially-populated row never set is
     * therefore NOT scannable — which is the direction that costs a
     * skipped tick rather than a false "everything is covered".
     */
    public static function isScannable(string $verdict): bool
    {
        return $verdict === self::ELIGIBLE;
    }
}
