<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use BCC\Trust\Onchain\Support\CosmwasmScanEligibility;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Operator health summary for the CosmWasm CW-721 scanner.
 *
 * ── FOUR DB READS, TOTAL, FOR EVERY CHAIN ───────────────────────────────
 * Same discipline as {@see NftIndexerHealthSnapshot::buildSummary()}: a
 * fixed, tiny number of BOUNDED reads up front, then ALL derivation in
 * PHP over the already-loaded data. The reads are:
 *
 *   1. {@see ChainCheckpointRepository::getAllOrFail()}           per-chain worker state
 *   2. {@see CosmwasmCodeFamilyRepository::countsByChainAndClassification()}
 *   3. {@see CosmwasmCodeFamilyRepository::pendingCountsByChain()}
 *   4. {@see CosmwasmContractRepository::inventoryByChain()}
 *
 * Reads 2–4 are GROUP BY aggregates keyed by `chain_id`, so the cost does
 * not grow with the number of chains. `ChainRepository::getActive()` is
 * served from the chains cache and adds no query on a warm request.
 *
 * All four are FAIL-CLOSED: they throw {@see RepositoryReadFailure}
 * rather than answer a failed query with an empty set, and
 * {@see buildSummary()} turns that into an explicit "unavailable" panel.
 * The alternative is the failure mode this whole class would otherwise
 * have: four empty results derive perfectly happily into a GREEN scanner
 * with zero families, zero contracts and nothing pending.
 *
 * WHAT THIS DELIBERATELY IS NOT: a loop over chains calling
 * {@see CosmwasmDiscoveryService::chainSummary()}. That method is the
 * SINGLE-chain view (7 reads); calling it per chain would be ~63 reads to
 * paint one admin panel, which is the per-row recompute this class exists
 * to avoid. Both derive the same numbers from the same aggregates.
 *
 * ── NO PERCENTAGES, EVER ────────────────────────────────────────────────
 * There is no progress bar and no "N% complete". `pagination.count_total`
 * is honoured by exactly ONE of the supported cosmos chains (Jackal —
 * measured 2026-08-06), so for every other chain there is no trustworthy
 * denominator. Progress is reported the only honest way available: the
 * code-id watermark, whether a resumable cursor is open, and counts.
 *
 * @phpstan-import-type CheckpointRow from ChainCheckpointRepository
 *
 * @phpstan-type ScheduleEntry array{
 *     hook: string,
 *     label: string,
 *     interval: string,
 *     scheduled: bool,
 *     next_run_at: int|null,
 *     overdue_seconds: int
 * }
 * @phpstan-type ChainPanelRow array{
 *     chain_id: int,
 *     slug: string,
 *     name: string,
 *     state: string,
 *     state_label: string,
 *     paused: bool,
 *     unsupported: bool,
 *     discovery_opted_in: bool,
 *     eligibility: string,
 *     eligibility_reason: string,
 *     backfill_complete: bool,
 *     progress_label: string,
 *     max_code_id: int,
 *     cursor_open: bool,
 *     backfill_completed_at: string|null,
 *     last_discovery_at: string|null,
 *     last_discovery_age_seconds: int|null,
 *     metadata_refreshed_at: string|null,
 *     last_error: string|null,
 *     families_total: int,
 *     families_pending: int,
 *     families_errored: int,
 *     families_by_classification: array<string, int>,
 *     contracts_total: int,
 *     contracts_inspected: int,
 *     contracts_denied: int,
 *     contracts_by_classification: array<string, int>,
 *     candidates: int,
 *     candidates_awaiting_emit: int
 * }
 */
final class CosmwasmDiscoveryHealthSnapshot
{
    public const STATUS_GREEN    = 'green';
    public const STATUS_YELLOW   = 'yellow';
    public const STATUS_RED      = 'red';
    /** The gate is off. Not a failure — a deliberate configuration. */
    public const STATUS_DISABLED = 'disabled';
    /**
     * No chain is opted in, so there is nothing for the scanner to do.
     *
     * ── THE FOURTH KIND OF ANSWER ───────────────────────────────────────
     * An operator who has opted no chain in is not looking at a healthy
     * scanner, a degraded one, or a broken one. They are looking at one
     * that has not been pointed at anything yet, which is a perfectly
     * ordinary way to run this plugin and must not be dressed up as a
     * finding.
     *
     * WHAT IT REPLACES: {@see deriveStatus()} counted every chain that was
     * neither paused nor unsupported as "eligible", including chains the
     * worker would never touch because nobody opted them in. With the gate
     * on and zero opt-ins that arithmetic landed on YELLOW — a degraded
     * verdict about a system that is not degraded, and the single fastest
     * way to teach an operator that yellow means nothing.
     *
     * WHAT IT IS NOT: it is not {@see STATUS_DISABLED}, which is a
     * statement about `BCC_COSMWASM_DISCOVERY_ENABLED` and nothing else;
     * and it is emphatically not green, because "nothing is wrong" and
     * "everything is working" are different sentences and only the first
     * one is true here.
     *
     * The literal collides with {@see ChainCheckpointRepository::CW_STATE_IDLE}
     * by coincidence of vocabulary, not by design. They are read off
     * different keys — this one off `status`, that one off a chain row's
     * `state` — and neither is ever compared against the other.
     */
    public const STATUS_IDLE = 'idle';
    /**
     * Chains ARE opted in, and not one of them can be scanned.
     *
     * ── THE HALF OF THE OPT-IN BUG THAT SURVIVED {@see STATUS_IDLE} ─────
     * That constant fixed the case where nobody had opted anything in. The
     * counting loop underneath it kept the rest of the defect: it walked
     * EVERY chain in the registry, skipped the paused and the unsupported
     * ones, and counted everything else as "eligible" — including chains
     * the worker will never touch because no operator asked for them.
     *
     * Reproduced against the deployed code before this value existed:
     * chains = [opted-in + unsupported, not-opted-in + supported] derived
     * GREEN. An operator whose ENTIRE selection could not be scanned was
     * told everything was fine, on the strength of a chain they had
     * deliberately left switched off.
     *
     * ── AND THE HALF THAT SURVIVED THE FIRST FIX OF THIS ────────────────
     * The counting loop then kept its OWN `paused || unsupported` test
     * beside the eligibility column and never consulted the canary
     * allowlist at all. So an opted-in, supported, unpaused chain sitting
     * outside `BCC_COSMWASM_CHAIN_ALLOWLIST` still counted as scannable
     * and could read GREEN — while `eligible_chain_count` on the SAME
     * summary reported 0 and the worker skipped the chain. There is now
     * one verdict ({@see CosmwasmScanEligibility::verdict()}) and one
     * reader of it ({@see scannable()}), shared with the worker.
     *
     * WHAT IT MEANS, EXACTLY: at least one chain is opted in, and not one
     * opted-in chain is scannable — every one of them is unsupported (no
     * wasm module), paused, outside the canary allowlist, or carrying an
     * opt-in column nobody could read. There is no work for the scanner to
     * do even with the gate open and cron running perfectly.
     *
     * WHAT IT IS NOT:
     *   - not GREEN. Nothing is running, so nothing is working.
     *   - not IDLE. Somebody DID point the scanner at something. Idle is
     *     "no selection"; this is "a selection that cannot produce work",
     *     and only the first is answered by opting a chain in.
     *   - not DISABLED. That is a statement about
     *     `BCC_COSMWASM_DISCOVERY_ENABLED` alone, and it is still named in
     *     the copy when it happens to be undefined as well.
     *   - not merely RED, which is where this case landed before. Red was
     *     not WRONG — `eligible === 0` caught it — it just explained
     *     nothing, and it sent an operator looking for a fault when the
     *     answer is a row in the table below saying "No wasm module".
     */
    public const STATUS_BLOCKED = 'blocked';
    /**
     * A DB read failed, so there is no picture to paint.
     *
     * Distinct from every other value on purpose. `green`/`yellow`/`red`
     * are verdicts ABOUT the scanner; this one says we could not look. It
     * is not `red` because red is a real, derived answer an operator is
     * meant to act on, and it is emphatically not `green` with zeroes.
     */
    public const STATUS_UNAVAILABLE = 'unavailable';

    // ── PER-CHAIN ELIGIBILITY ───────────────────────────────────────────
    //
    // The panel used to list every active Cosmos chain with no indication
    // of which ones the scanner will actually walk, so an operator read a
    // table of rows that were never going to move and had nothing to tell
    // them why. These six values are the answer, and they are the ONLY
    // values the panel may key on.
    //
    // THEY ARE ALIASES, NOT A SECOND VOCABULARY. Every one of them IS the
    // constant of the same meaning on {@see CosmwasmScanEligibility} —
    // the class that decides the verdict for the worker AND for this
    // panel. There is one literal per value in the codebase, and it lives
    // there. The aliases exist so the display layer keeps naming its
    // values in its own idiom (`ELIGIBILITY_*` beside `STATUS_*`) without
    // that idiom becoming a place a value could drift.
    //
    // EXACTLY ONE OF THEM MEANS "WILL BE SCANNED": ELIGIBLE. Every other
    // value — including the one that means "we could not tell" — is a NO,
    // and the test for it is {@see CosmwasmScanEligibility::isScannable()},
    // never a hand-written list of exclusions.

    /** Nothing is blocking this chain; it is in the scanner's rotation. */
    public const ELIGIBILITY_ELIGIBLE = CosmwasmScanEligibility::ELIGIBLE;

    /** OPERATOR INTENT: `wp_bcc_chains.cosmwasm_nft_discovery_enabled` = 0. */
    public const ELIGIBILITY_NOT_OPTED_IN = CosmwasmScanEligibility::NOT_OPTED_IN;

    /** MEASURED CAPABILITY: the chain's wasm module answered with a 501. */
    public const ELIGIBILITY_UNSUPPORTED = CosmwasmScanEligibility::UNSUPPORTED;

    /**
     * OPERATOR HOLD: `cw_discovery_state = paused`.
     *
     * ── THE SECOND HALF OF THE SAME DEFECT ──────────────────────────────
     * Pause used to be invisible to this verdict. The panel's arithmetic
     * had its own `if ($chain['paused'] || $chain['unsupported'])` line
     * beside the eligibility column, so the two disagreed by construction:
     * a paused chain read "Eligible" in the Discovery column while the
     * same page's status counted it as unscannable. The worker disagreed
     * with both — it resolved the chain as eligible and only dropped it
     * one layer down, in `prepareChain()`.
     *
     * There is now one verdict and every reader takes it from here.
     */
    public const ELIGIBILITY_PAUSED = CosmwasmScanEligibility::PAUSED;

    /** Outside the temporary `BCC_COSMWASM_CHAIN_ALLOWLIST` canary scope. */
    public const ELIGIBILITY_ALLOWLIST_EXCLUDED = CosmwasmScanEligibility::ALLOWLIST_EXCLUDED;

    /**
     * The opt-in could not be read at all — the projection carries no
     * `cosmwasm_nft_discovery_enabled` property, which is what a
     * pre-migration install (or a stale pre-migration transient) looks
     * like.
     *
     * It is a SEPARATE value from `not_opted_in` because the two are
     * different facts: one says an operator decided no, the other says
     * nobody has been able to decide anything yet. Both are treated as
     * NOT eligible, which is the same answer
     * {@see CosmwasmDiscoveryWorker::eligibleChainIds()} gives — because
     * it is literally the same function that decides it.
     */
    public const ELIGIBILITY_UNKNOWN = CosmwasmScanEligibility::UNKNOWN;

    /**
     * A chain that has not been touched in this long, while discovery is
     * enabled and the chain is neither paused nor unsupported, is stale.
     *
     * Sized off the DAILY pass with a full day of slack: the incremental
     * hooks run daily, so 48h without a stamp means two consecutive
     * misses, which is a real signal rather than cron jitter.
     */
    public const CHAIN_STALE_SECONDS = 172800;

    /** Cron lateness that means wp-cron itself is probably not running. */
    public const CRON_OVERDUE_SECONDS = 3600;

    /**
     * The whole operator view, derived in one pass.
     *
     * ── IT CAN ALSO SAY "I DON'T KNOW" ──────────────────────────────────
     * All four reads are FAIL-CLOSED. If any of them does not run, this
     * returns `data_unavailable => true`, `status => unavailable`, no
     * chain rows and `totals => null` — NOT a zeroed picture. `totals` is
     * nullable precisely so a renderer cannot accidentally print an
     * invented 0: there is no number to read, so the type says so and the
     * panel has to handle it.
     *
     * `eligible_chain_count` is nullable for exactly the same reason: "no
     * chain is eligible" and "nobody could work out which chains are
     * eligible" are different facts, and 0 is only allowed to mean the
     * first one.
     *
     * When it is NOT null it is required to equal
     * `count(CosmwasmDiscoveryWorker::eligibleChainIds())` for the same
     * site — same chains, same ids, not merely the same total. Both sides
     * reach it through {@see CosmwasmScanEligibility::verdict()};
     * CosmwasmScannerStatusParityTest asserts the two sets are identical
     * for every fixture, including the ones where the answer is "none".
     *
     * @return array{
     *     discovery_enabled: bool,
     *     backfill_enabled: bool,
     *     disabled_reason: string|null,
     *     status: string,
     *     data_unavailable: bool,
     *     unavailable_reason: string|null,
     *     schedule: list<ScheduleEntry>,
     *     chains: list<ChainPanelRow>,
     *     allowlist_chain_ids: list<int>|null,
     *     eligible_chain_count: int|null,
     *     working_chain: array{chain_id: int, slug: string}|null,
     *     next_chain: array{chain_id: int, slug: string}|null,
     *     totals: array{
     *         families: int,
     *         families_pending: int,
     *         families_cw721: int,
     *         families_not_cw721: int,
     *         families_inconclusive: int,
     *         contracts: int,
     *         contracts_inspected: int,
     *         candidates: int,
     *         candidates_awaiting_emit: int,
     *         denied: int
     *     }|null,
     *     issues: list<string>
     * }
     */
    public static function buildSummary(): array
    {
        $discoveryEnabled = CosmwasmDiscoveryGate::discoveryEnabled();
        $backfillEnabled  = CosmwasmDiscoveryGate::backfillEnabled();
        $now              = time();

        // The canary scope, read ONCE for the whole panel. null = the
        // constant is undefined (no extra restriction); [] = defined but
        // names no usable chain id, which means NOTHING is scanned. See
        // CosmwasmDiscoveryGate::chainAllowlist() — the two-value contract
        // is the whole point, and collapsing it here would reintroduce the
        // fail-open bug at the display layer.
        $allowlist = CosmwasmDiscoveryGate::chainAllowlist();

        // READ 0 (cached): the chain registry, filtered to CosmWasm-capable
        // chains in PHP. Mirrors CosmwasmDiscoveryWorker::cosmosChainIds().
        $chainRows = [];
        foreach (ChainRepository::getActive() as $chain) {
            if ((string) ($chain->chain_type ?? '') !== 'cosmos') {
                continue;
            }
            $chainId = (int) ($chain->id ?? 0);
            if ($chainId > 0) {
                $chainRows[$chainId] = $chain;
            }
        }

        // READS 1-5. Everything below this line is PHP over these arrays.
        // All five throw rather than answering a failed query with an
        // empty set, because every one of them is a number this panel
        // prints as fact.
        //
        // `erroredCountsByChain()` joins this block rather than being
        // read defensively somewhere calmer FOR THE REASON THE BLOCK
        // EXISTS: its zero is the sentence that turns the panel green, so
        // a failed read must reach STATUS_UNAVAILABLE and not "no errors".
        try {
            $checkpoints   = ChainCheckpointRepository::getAllOrFail();
            $familyCounts  = CosmwasmCodeFamilyRepository::countsByChainAndClassification();
            $familyPending = CosmwasmCodeFamilyRepository::pendingCountsByChain(CosmwasmClassifier::VERSION);
            $familyErrored = CosmwasmCodeFamilyRepository::erroredCountsByChain();
            $contractStats = CosmwasmContractRepository::inventoryByChain();
        } catch (RepositoryReadFailure $e) {
            return self::unavailableSummary($discoveryEnabled, $backfillEnabled, $now, $e);
        }

        $checkpointByChain = [];
        foreach ($checkpoints as $cp) {
            $checkpointByChain[(int) $cp->chain_id] = $cp;
        }

        $chains = [];
        $totals = [
            'families'                 => 0,
            'families_pending'         => 0,
            'families_cw721'           => 0,
            'families_not_cw721'       => 0,
            'families_inconclusive'    => 0,
            'contracts'                => 0,
            'contracts_inspected'      => 0,
            'candidates'               => 0,
            'candidates_awaiting_emit' => 0,
            'denied'                   => 0,
        ];

        $eligibleCount = 0;

        foreach ($chainRows as $chainId => $chain) {
            $row = self::deriveChainRow(
                $chainId,
                (string) ($chain->slug ?? ''),
                (string) ($chain->name ?? ''),
                $checkpointByChain[$chainId] ?? null,
                $familyCounts[$chainId] ?? [],
                $familyPending[$chainId] ?? 0,
                $familyErrored[$chainId] ?? 0,
                $contractStats[$chainId] ?? null,
                $now,
                // THE SAME READER THE WORKER USES, not a second copy of the
                // presence check. A panel that computed the opt-in its own
                // way could disagree with the chokepoint, and the operator
                // would believe the panel.
                CosmwasmDiscoveryWorker::discoveryOptInState($chain),
                $allowlist
            );

            $chains[] = $row;

            // THE SAME READER deriveStatus() counts with. Two ways of
            // asking "is this one eligible?" in one method is how the
            // summary line and the status badge came to contradict each
            // other on the same screen.
            if (self::scannable($row)) {
                $eligibleCount++;
            }

            $totals['families']                 += $row['families_total'];
            $totals['families_pending']         += $row['families_pending'];
            $totals['families_cw721']           += self::cw721Total($row['families_by_classification']);
            $totals['families_not_cw721']       += $row['families_by_classification'][CosmwasmClassifier::NOT_CW721] ?? 0;
            $totals['families_inconclusive']    += ($row['families_by_classification'][CosmwasmClassifier::INCONCLUSIVE] ?? 0)
                + ($row['families_by_classification'][CosmwasmClassifier::UNREACHABLE] ?? 0);
            $totals['contracts']                += $row['contracts_total'];
            $totals['contracts_inspected']      += $row['contracts_inspected'];
            $totals['candidates']               += $row['candidates'];
            $totals['candidates_awaiting_emit'] += $row['candidates_awaiting_emit'];
            $totals['denied']                   += $row['contracts_denied'];
        }

        $schedule = self::deriveSchedule($now);
        $issues   = self::deriveIssues($discoveryEnabled, $backfillEnabled, $chains, $schedule);

        return [
            'discovery_enabled'  => $discoveryEnabled,
            'backfill_enabled'   => $backfillEnabled,
            'disabled_reason'    => self::disabledReason($discoveryEnabled, $backfillEnabled),
            'status'             => self::deriveStatus($discoveryEnabled, $chains, $schedule),
            'data_unavailable'   => false,
            'unavailable_reason' => null,
            'schedule'             => $schedule,
            'chains'               => $chains,
            'allowlist_chain_ids'  => $allowlist,
            'eligible_chain_count' => $eligibleCount,
            'working_chain'        => self::deriveWorkingChain($chains),
            'next_chain'           => self::deriveNextChain($chains),
            'totals'               => $totals,
            'issues'               => $issues,
        ];
    }

    /**
     * The summary for "a read failed, so there is nothing to report".
     *
     * Everything derived from the DB is ABSENT rather than zero: no chain
     * rows, no working/next chain, `totals => null`. The cron schedule
     * survives because `wp_next_scheduled()` is not a database read and
     * "is the pass even registered?" is still answerable — and still worth
     * answering, since a stalled cron and a broken DB look identical from
     * the outside otherwise.
     *
     * `eligible_chain_count` is null here for the same reason `totals` is.
     * The eligibility of a chain depends on the checkpoint read that just
     * failed, so "0 chains are eligible" would be an invented answer to a
     * question nobody managed to ask — and it is the WORST invented answer
     * available, because 0 eligible chains is also a real and alarming
     * state an operator would act on.
     *
     * ── `unavailable` OUTRANKS `idle`, AND THIS IS WHERE THAT HAPPENS ───
     * This method hard-codes {@see STATUS_UNAVAILABLE}; it does not ask
     * {@see deriveStatus()} for a verdict. It must never start: the chain
     * list here is EMPTY because nobody could read it, and "no chains" is
     * one guard away from deriving into the calmest status this class can
     * produce. A failed read reported as "Idle — nothing to do" is the
     * same lie as GREEN with zeroes, in a quieter voice.
     *
     * @return array{
     *     discovery_enabled: bool,
     *     backfill_enabled: bool,
     *     disabled_reason: string|null,
     *     status: string,
     *     data_unavailable: bool,
     *     unavailable_reason: string|null,
     *     schedule: list<ScheduleEntry>,
     *     chains: list<ChainPanelRow>,
     *     allowlist_chain_ids: list<int>|null,
     *     eligible_chain_count: null,
     *     working_chain: array{chain_id: int, slug: string}|null,
     *     next_chain: array{chain_id: int, slug: string}|null,
     *     totals: null,
     *     issues: list<string>
     * }
     */
    private static function unavailableSummary(
        bool $discoveryEnabled,
        bool $backfillEnabled,
        int $now,
        RepositoryReadFailure $failure
    ): array {
        $reason = sprintf(
            'A scanner database read failed (%s), so none of these numbers could be loaded. '
                . 'Nothing below is being reported as zero — it is simply not known right now. '
                . 'The scanner itself is unaffected by this page; check the bcc-trust error log.',
            $failure->repositoryMethod()
        );

        return [
            'discovery_enabled'  => $discoveryEnabled,
            'backfill_enabled'   => $backfillEnabled,
            'disabled_reason'    => self::disabledReason($discoveryEnabled, $backfillEnabled),
            'status'               => self::STATUS_UNAVAILABLE,
            'data_unavailable'     => true,
            'unavailable_reason'   => $reason,
            'schedule'             => self::deriveSchedule($now),
            'chains'               => [],
            // The allowlist is read from a CONSTANT, not the database, so
            // it survives the failure the way the cron schedule does.
            'allowlist_chain_ids'  => CosmwasmDiscoveryGate::chainAllowlist(),
            'eligible_chain_count' => null,
            'working_chain'        => null,
            'next_chain'           => null,
            'totals'               => null,
            'issues'               => [$reason],
        ];
    }

    /**
     * PURE. One chain's panel row, assembled from the already-loaded
     * checkpoint + aggregate slices. No I/O.
     *
     * ── THE TWO ELIGIBILITY ARGUMENTS DEFAULT TO "DON'T KNOW" ───────────
     * `$discoveryOptedIn` is `?bool`, not `bool`, because there are three
     * distinct answers and only two of them are booleans: yes, no, and
     * "this projection has no such column" (a pre-migration install). Its
     * default is null — a caller that does not supply the opt-in gets
     * {@see ELIGIBILITY_UNKNOWN}, which the panel renders as NOT eligible.
     * That direction is not an accident: an omitted argument must never be
     * able to produce an "eligible" row.
     *
     * @param  CheckpointRow|null           $checkpoint
     * @param  array<string, int>           $familyCounts
     * @param  array{total: int, inspected: int, denied: int, candidates: int, candidates_awaiting_emit: int, by_classification: array<string, int>}|null $contractStats
     * @param  bool|null                    $discoveryOptedIn null = the opt-in column is absent from the projection
     * @param  list<int>|null               $allowlist        null = BCC_COSMWASM_CHAIN_ALLOWLIST is undefined
     * @return ChainPanelRow
     */
    public static function deriveChainRow(
        int $chainId,
        string $slug,
        string $name,
        ?object $checkpoint,
        array $familyCounts,
        int $familyPending,
        int $familiesErrored,
        ?array $contractStats,
        int $now,
        ?bool $discoveryOptedIn = null,
        ?array $allowlist = null
    ): array {
        $state = $checkpoint !== null
            ? (string) $checkpoint->cw_discovery_state
            : ChainCheckpointRepository::CW_STATE_IDLE;

        $maxCodeId = $checkpoint !== null ? (int) $checkpoint->cw_max_code_id : 0;
        $cursor    = $checkpoint !== null ? $checkpoint->cw_code_cursor : null;
        $cursorOpen = is_string($cursor) && $cursor !== '';

        $completedAt = $checkpoint !== null && is_string($checkpoint->cw_backfill_completed_at)
            ? $checkpoint->cw_backfill_completed_at
            : null;
        $lastAt = $checkpoint !== null && is_string($checkpoint->cw_last_discovery_at)
            ? $checkpoint->cw_last_discovery_at
            : null;
        $metadataAt = $checkpoint !== null && is_string($checkpoint->cw_metadata_refreshed_at)
            ? $checkpoint->cw_metadata_refreshed_at
            : null;
        $lastError = $checkpoint !== null && is_string($checkpoint->cw_last_error) && $checkpoint->cw_last_error !== ''
            ? $checkpoint->cw_last_error
            : null;

        $familyTotal = 0;
        foreach ($familyCounts as $count) {
            $familyTotal += $count;
        }

        $stats = $contractStats ?? [
            'total'                    => 0,
            'inspected'                => 0,
            'denied'                   => 0,
            'candidates'               => 0,
            'candidates_awaiting_emit' => 0,
            'by_classification'        => [],
        ];

        // THE SHARED VERDICT — the same function
        // CosmwasmDiscoveryWorker::eligibleChainIds() filters on, given the
        // same three facts. Not a mirror of it, not a display-side
        // approximation of it: the same lines of code.
        //
        // A chain with NO checkpoint row is handed `null` rather than the
        // `idle` stand-in used for display below, so the predicate sees
        // exactly what the worker sees: "nobody has measured this yet".
        $eligibility = CosmwasmScanEligibility::verdict(
            $chainId,
            $checkpoint !== null ? $state : null,
            $discoveryOptedIn,
            $allowlist
        );

        // Kept as their own row fields because they are FACTS the table
        // prints (the State pill, the Controls cell), not policy. Nothing
        // may derive "will this be scanned?" from them — that answer is
        // `eligibility`, above, and there is only one of it.
        $unsupported = $state === ChainCheckpointRepository::CW_STATE_UNSUPPORTED;

        return [
            'chain_id'                    => $chainId,
            'slug'                        => $slug,
            'name'                        => $name !== '' ? $name : $slug,
            'state'                       => $state,
            'state_label'                 => self::stateLabel($state),
            'paused'                      => $state === ChainCheckpointRepository::CW_STATE_PAUSED,
            'unsupported'                 => $unsupported,
            // FAIL CLOSED: only a literal true is "opted in". null (column
            // absent) collapses to false here, and the reason WHY it is
            // false stays visible in `eligibility` as `unknown`.
            'discovery_opted_in'          => $discoveryOptedIn === true,
            'eligibility'                 => $eligibility,
            'eligibility_reason'          => self::eligibilityReason($eligibility, $allowlist),
            'backfill_complete'           => $state === ChainCheckpointRepository::CW_STATE_BACKFILLED,
            'progress_label'              => self::progressLabel($state, $maxCodeId, $cursorOpen, $familyTotal),
            'max_code_id'                 => $maxCodeId,
            'cursor_open'                 => $cursorOpen,
            'backfill_completed_at'       => $completedAt,
            'last_discovery_at'           => $lastAt,
            'last_discovery_age_seconds'  => self::ageSeconds($lastAt, $now),
            'metadata_refreshed_at'       => $metadataAt,
            'last_error'                  => $lastError,
            'families_total'              => $familyTotal,
            'families_pending'            => $familyPending,
            'families_errored'            => $familiesErrored,
            'families_by_classification'  => $familyCounts,
            'contracts_total'             => $stats['total'],
            'contracts_inspected'         => $stats['inspected'],
            'contracts_denied'            => $stats['denied'],
            'contracts_by_classification' => $stats['by_classification'],
            'candidates'                  => $stats['candidates'],
            'candidates_awaiting_emit'    => $stats['candidates_awaiting_emit'],
        ];
    }

    /**
     * PURE. Will the scanner walk this chain? THE ONE READER, used by
     * every number on this panel that describes the scanner's workload.
     *
     * ── IT DERIVES NOTHING ──────────────────────────────────────────────
     * The verdict was already decided, once, by
     * {@see CosmwasmScanEligibility::verdict()} — the same function
     * {@see CosmwasmDiscoveryWorker::eligibleChainIds()} filters on — and
     * stored on the row in {@see deriveChainRow()}. This reads it back.
     *
     * It deliberately does NOT re-examine `paused`, `unsupported` or
     * anything else on the row. That is exactly what the old arithmetic
     * did, with its own `if ($chain['paused'] || $chain['unsupported'])`
     * beside the eligibility column, and it is how the panel came to
     * report a healthy scanner twice in a fortnight: first counting chains
     * nobody had opted in, then counting chains outside the canary
     * allowlist. A second reading of the same facts is a second policy.
     *
     * FAIL CLOSED: a row with no `eligibility` key, a non-string one, or a
     * value from a newer build is NOT scannable. See
     * {@see CosmwasmScanEligibility::isScannable()}.
     *
     * @param array<string, mixed> $chain
     */
    public static function scannable(array $chain): bool
    {
        $verdict = $chain['eligibility'] ?? null;

        return is_string($verdict) && CosmwasmScanEligibility::isScannable($verdict);
    }

    /**
     * PURE. How many of these chains the scanner will walk.
     *
     * This is the number `eligible_chain_count` reports, and it is
     * REQUIRED to equal `count(CosmwasmDiscoveryWorker::eligibleChainIds())`
     * for the same site. That equality is not a coincidence to be
     * maintained by hand — both sides run
     * {@see CosmwasmScanEligibility::verdict()} over the same three facts
     * — and CosmwasmScannerStatusParityTest asserts it, per fixture, as
     * matching SETS of chain ids rather than merely matching counts.
     *
     * @param list<ChainPanelRow> $chains
     */
    public static function scannableChainCount(array $chains): int
    {
        $count = 0;
        foreach ($chains as $chain) {
            if (self::scannable($chain)) {
                $count++;
            }
        }

        return $count;
    }

    /** PURE. Short operator label for an eligibility value. */
    public static function eligibilityLabel(string $eligibility): string
    {
        switch ($eligibility) {
            case self::ELIGIBILITY_ELIGIBLE:
                return 'Eligible';
            case self::ELIGIBILITY_NOT_OPTED_IN:
                return 'Not opted in';
            case self::ELIGIBILITY_UNSUPPORTED:
                return 'No wasm module';
            case self::ELIGIBILITY_PAUSED:
                return 'Paused';
            case self::ELIGIBILITY_ALLOWLIST_EXCLUDED:
                return 'Outside canary scope';
            default:
                // Every unrecognised value — including a missing one — is
                // "we do not know", never "eligible".
                return 'Unknown';
        }
    }

    /**
     * PURE. The same answer in a sentence. Plain text — escape at render.
     *
     * @param list<int>|null $allowlist null = the constant is undefined
     */
    public static function eligibilityReason(string $eligibility, ?array $allowlist): string
    {
        switch ($eligibility) {
            case self::ELIGIBILITY_ELIGIBLE:
                return 'Nothing is blocking this chain — it is in the rotation whenever discovery runs.';

            case self::ELIGIBILITY_NOT_OPTED_IN:
                return 'Discovery is switched off for this chain, so no pass will scan it. '
                    . 'Turn it on with Enable discovery below; nothing starts until you do.';

            case self::ELIGIBILITY_UNSUPPORTED:
                // Same register as the existing panel copy for this case:
                // it is a fact about the chain, not a failure.
                //
                // ── "PERMANENT" IS A CLAIM ABOUT THE CODE, NOT THE WORLD ─
                // The code does prove it, and the sentence says exactly
                // what it proves rather than more. CosmwasmDiscoveryWorker::
                // prepareChain() returns null on this state ("durable,
                // never retried"); the shared eligibility verdict excludes
                // it, so no scheduled pass resolves the chain at all;
                // ChainCheckpointRepository::pauseCwDiscovery() REFUSES
                // when the state is already unsupported; and
                // cwResumeState()/resumeCwDiscovery() require the state to
                // be exactly `paused`, so Resume cannot clear it either.
                // No other writer moves a chain out of `unsupported`.
                //
                // What that does NOT license is "it can never change".
                // Somebody with database access can still edit the row,
                // and a later build could add a re-measurement path. So
                // the copy claims the guarantee — no scheduled pass, no
                // control on this page — and names the one thing that
                // would, instead of asserting a fact about the universe.
                return 'This chain has no CosmWasm module — it answered the code listing with a 501. '
                    . 'No scheduled pass retries that verdict and no control on this page clears it, '
                    . 'so opting the chain in changes nothing; only a direct database change would.';

            case self::ELIGIBILITY_PAUSED:
                return 'An operator paused this chain, so no pass runs for it — no backfill, no daily '
                    . 'pass, no retries. Its progress is kept exactly where it is; use Resume in the '
                    . 'Controls column to put it back in the rotation.';

            case self::ELIGIBILITY_ALLOWLIST_EXCLUDED:
                // null cannot reach here from deriveEligibility() — it only
                // returns this value once it has established the constant is
                // defined. It is folded in with [] anyway rather than given a
                // cheerier branch, because the fallback for a value nobody can
                // produce still has to point AWAY from "eligible".
                if ($allowlist === null || $allowlist === []) {
                    return 'BCC_COSMWASM_CHAIN_ALLOWLIST is defined but names no usable chain id, '
                        . 'so NO chain is scanned while it stays that way. Fix or remove the constant in wp-config.php.';
                }

                return sprintf(
                    'Opted in, but outside the canary scope: BCC_COSMWASM_CHAIN_ALLOWLIST names only %s %s, '
                        . 'so this chain is not scanned. Widen or remove the constant in wp-config.php.',
                    count($allowlist) === 1 ? 'chain' : 'chains',
                    implode(', ', $allowlist)
                );

            default:
                return 'The discovery opt-in for this chain could not be read, so it is treated as NOT eligible. '
                    . 'That is what a pre-migration install looks like — the cosmwasm_nft_discovery_enabled column '
                    . 'is missing from the chain registry. Check the bcc-trust error log.';
        }
    }

    /**
     * PURE. The cron picture: one entry per discovery hook, with whether
     * it is registered at all and how late it is.
     *
     * "Registered" and "enabled" are different questions and both are
     * shown: the hooks self-heal onto the schedule regardless of the
     * gate, and every handler re-checks the gate before doing work, so a
     * scheduled hook on a gated-off site is a no-op, not a promise.
     *
     * @return list<ScheduleEntry>
     */
    public static function deriveSchedule(int $now): array
    {
        $hooks = [
            [CosmwasmDiscoveryWorker::BACKFILL_HOOK, 'Historical backfill slice', CosmwasmDiscoveryWorker::BACKFILL_INTERVAL],
            [CosmwasmDiscoveryWorker::DAILY_HOOK,    'New code IDs + new contracts', CosmwasmDiscoveryWorker::DAILY_INTERVAL],
            [CosmwasmDiscoveryWorker::WEEKLY_HOOK,   'Retry sweep (backed off)',     CosmwasmDiscoveryWorker::WEEKLY_INTERVAL],
            [CosmwasmDiscoveryWorker::METADATA_HOOK, 'Migration + metadata (monthly guard)', CosmwasmDiscoveryWorker::METADATA_INTERVAL],
        ];

        $out = [];
        foreach ($hooks as [$hook, $label, $interval]) {
            $next = wp_next_scheduled($hook);
            $ts   = is_int($next) ? $next : null;

            $out[] = [
                'hook'            => $hook,
                'label'           => $label,
                'interval'        => $interval,
                'scheduled'       => $ts !== null,
                'next_run_at'     => $ts,
                'overdue_seconds' => $ts !== null ? max(0, $now - $ts) : 0,
            ];
        }

        return $out;
    }

    /**
     * PURE. Which chain the backfill is currently working through.
     *
     * "Currently" is the most-recently-stamped chain that is still
     * mid-backfill. There is no live "worker is running right now" flag —
     * a tick is at most 20 seconds long and holds an advisory lock, so
     * inventing one would be a lie for 99% of the time it was displayed.
     *
     * @param  list<ChainPanelRow> $chains
     * @return array{chain_id: int, slug: string}|null
     */
    public static function deriveWorkingChain(array $chains): ?array
    {
        $best     = null;
        $bestSeen = '';

        foreach ($chains as $chain) {
            if ($chain['state'] !== ChainCheckpointRepository::CW_STATE_BACKFILLING) {
                continue;
            }
            $seen = $chain['last_discovery_at'] ?? '';
            if ($best === null || $seen > $bestSeen) {
                $best     = ['chain_id' => $chain['chain_id'], 'slug' => $chain['slug']];
                $bestSeen = $seen;
            }
        }

        return $best;
    }

    /**
     * PURE. Which chain the NEXT backfill tick will pick up.
     *
     * A faithful PHP mirror of
     * {@see ChainCheckpointRepository::nextCwDiscoveryChain()}: skip
     * `unsupported` and `paused`, then least-recently-worked first with
     * never-worked ahead of everything. IF THAT SQL CHANGES, CHANGE THIS
     * — an admin panel that names a different chain than the worker will
     * take is worse than naming none.
     *
     * @param  list<ChainPanelRow> $chains
     * @return array{chain_id: int, slug: string}|null
     */
    public static function deriveNextChain(array $chains): ?array
    {
        $best     = null;
        $bestSeen = null;

        foreach ($chains as $chain) {
            // The SQL is only ever handed the ids
            // CosmwasmDiscoveryWorker::eligibleChainIds() resolved, so the
            // set this walks has to be the same one — which is what
            // scannable() answers. It used to skip only paused and
            // unsupported rows, so the panel could name a chain nobody had
            // opted in (or one outside the canary allowlist) as the next
            // one to be worked, which the worker would never take.
            if (!self::scannable($chain)) {
                continue;
            }
            $seen = $chain['last_discovery_at'];

            if ($best === null) {
                $best     = ['chain_id' => $chain['chain_id'], 'slug' => $chain['slug']];
                $bestSeen = $seen;
                continue;
            }
            // NULL sorts first (never worked), then oldest stamp.
            if ($bestSeen === null) {
                continue;
            }
            if ($seen === null || $seen < $bestSeen) {
                $best     = ['chain_id' => $chain['chain_id'], 'slug' => $chain['slug']];
                $bestSeen = $seen;
            }
        }

        return $best;
    }

    /**
     * PURE. Overall RGB.
     *
     * `disabled` and `idle` are their own values rather than colours: an
     * operator who has not turned discovery on, or who has opted no chain
     * in, is not looking at a broken system, and painting either red would
     * train them to ignore red.
     *
     * ── THE PRECEDENCE, WHICH IS THE POINT OF THIS METHOD ───────────────
     *   1. unavailable — NOT DECIDED HERE. {@see buildSummary()} returns
     *      {@see unavailableSummary()} before this method is ever called,
     *      so a failed read can never be reported as any verdict below.
     *      That ordering is load-bearing: "a read failed" outranks every
     *      tidy answer, and idle is the tidiest one there is.
     *   2. idle    — nobody opted a chain in.
     *   3. blocked — chains ARE opted in and not one of them is scannable:
     *      every opt-in is paused, unsupported, outside the canary
     *      allowlist, or its opt-in column could not be read.
     *   4. disabled — the constant is undefined.
     *   5. red / yellow / green — the arithmetic, over the SCANNABLE set
     *      only ({@see scannable()}).
     *
     * ── THE ARITHMETIC ONLY EVER LOOKED AT THE WRONG SET ────────────────
     * The loop below used to decide for itself which chains counted, and
     * it got the answer wrong twice in a fortnight. First it ran over
     * EVERY chain in the registry and skipped only the paused and
     * unsupported ones, so a chain nobody had opted in counted as a
     * healthy eligible chain — an operator whose only opt-in was an
     * unsupported chain read GREEN off a chain they had switched off.
     * Then, with the opt-in added, it still keyed on `paused ||
     * unsupported` and never consulted the canary allowlist, so an
     * opted-in chain OUTSIDE `BCC_COSMWASM_CHAIN_ALLOWLIST` counted as
     * scannable — while `eligible_chain_count` on the same summary said 0
     * and the worker skipped it.
     *
     * Both were the same bug: a second definition of "scannable", written
     * here, maintained by hand beside the real one. There is now no
     * definition here at all. {@see scannable()} reads the verdict
     * {@see CosmwasmScanEligibility::verdict()} already reached — the same
     * function {@see CosmwasmDiscoveryWorker::eligibleChainIds()} filters
     * on — so every number below describes exactly the chains the worker
     * would walk, and adding a sixth condition to the policy reaches this
     * arithmetic without anybody editing this method.
     *
     * ── WHY IDLE AND BLOCKED OUTRANK DISABLED ───────────────────────────
     * Same reason, ruled once and applied twice. Both are intentional
     * configuration, so neither is a fault and the only question is which
     * fact an operator needs told first. With nothing opted in — or with
     * nothing opted in that CAN be scanned — defining
     * `BCC_COSMWASM_DISCOVERY_ENABLED` would change NOTHING, so leading
     * with the constant sends someone to edit wp-config.php for no effect.
     * The nearer truth is the selection, and it is the one with an action
     * attached. Neither hides the constant: the panel still carries
     * `disabled_reason`, and both notices name it when it is undefined.
     *
     * Blocked outranks the red/yellow/green arithmetic for the same
     * reason: an unscheduled or overdue cron pass is real, but it changes
     * nothing while there is no chain for that pass to walk.
     *
     * ── AND WHY EMPTY CHAINS ARE NEITHER IDLE NOR BLOCKED ───────────────
     * `$chains === []` means the registry lists no active Cosmos chain at
     * all. That is not an operator declining to scan anything, nor an
     * operator selecting chains that cannot be scanned — it is a registry
     * with nothing in it, and it keeps the RED it always had. Both guards
     * are also a second lock on precedence 1 above: the unavailable
     * summary carries no chain rows, so an empty list must never be able
     * to derive into a calm "idle" or an explanatory "blocked".
     *
     * @param list<ChainPanelRow> $chains
     * @param list<ScheduleEntry> $schedule
     */
    public static function deriveStatus(bool $discoveryEnabled, array $chains, array $schedule): string
    {
        $optedIn = self::optedInChainCount($chains);

        if ($chains !== [] && $optedIn === 0) {
            return self::STATUS_IDLE;
        }

        $eligible = 0;
        $errored  = 0;
        $stale    = 0;
        foreach ($chains as $chain) {
            // NOT SCANNABLE, NOT COUNTED — in either direction. A chain
            // the worker will not walk cannot make the scanner look
            // healthy (the bug this fixes, twice over) and it cannot make
            // it look degraded either: it has no last run to be stale and
            // no error of its own to answer for.
            if (!self::scannable($chain)) {
                continue;
            }
            $eligible++;
            // TWO INDEPENDENT WAYS A SCANNED CHAIN IS DEGRADED, and the
            // second one is why this chain rendered green while it was
            // broken.
            //
            //   `last_error`      — the CHAIN's own last pass failed.
            //   `families_errored` — the pass finished, but families
            //                        inside it hold unresolved reasons.
            //
            // They are OR-ed because either alone is a true statement
            // about a degraded chain. Relying on the first only is the
            // measured Dungeon bug: `advanceCwCodeWatermark()` clears
            // `cw_last_error` when the code read succeeds, so a chain with
            // 15 breaker-blocked families reported no error at all.
            //
            // A count, not a proportion: `pagination.count_total` is
            // honoured by one of nine cosmos chains, so there is no
            // trustworthy denominator — and "12% of families failed" is a
            // worse operator sentence than "12 families failed" anyway.
            if ($chain['last_error'] !== null || $chain['families_errored'] > 0) {
                $errored++;
            }
            $age = $chain['last_discovery_age_seconds'];
            if ($age === null || $age > self::CHAIN_STALE_SECONDS) {
                $stale++;
            }
        }

        // Opt-ins exist, and not one of them survived the loop above.
        // Stated as `$optedIn > 0` rather than leaning on the idle guard,
        // because the two conditions are different sentences and a reader
        // should not have to prove the first one from ten lines away.
        if ($optedIn > 0 && $eligible === 0) {
            return self::STATUS_BLOCKED;
        }

        if (!$discoveryEnabled) {
            return self::STATUS_DISABLED;
        }

        $unscheduled = 0;
        $overdue     = 0;
        foreach ($schedule as $entry) {
            if (!$entry['scheduled']) {
                $unscheduled++;
            } elseif ($entry['overdue_seconds'] > self::CRON_OVERDUE_SECONDS) {
                $overdue++;
            }
        }

        // `$eligible === 0` survives as the BACKSTOP it always was, and it
        // now only answers for the empty registry: every other route to
        // zero eligible chains is an opt-in that cannot be scanned, which
        // the blocked guard above has already claimed with a sentence an
        // operator can act on. It is kept rather than tidied away because
        // the fail-closed direction is the whole point — an unforeseen way
        // to reach zero must land on RED, never on green with no chains.
        if ($eligible === 0 || $unscheduled > 0) {
            return self::STATUS_RED;
        }
        if ($errored > 0 || $stale === $eligible || $overdue > 0) {
            return self::STATUS_YELLOW;
        }

        return self::STATUS_GREEN;
    }

    /**
     * PURE. How many chains an operator has actually opted in.
     *
     * FAIL CLOSED, in the same direction as everything else here: only a
     * literal `true` counts. A row assembled by older code with no
     * `discovery_opted_in` key, or one carrying the raw `'1'` a database
     * projection hands over, is NOT opted in as far as this is concerned.
     * The decision was already made — and already fail-closed — in
     * {@see deriveChainRow()}; this only reads it back, and a reader that
     * accepted a truthy value would let an absent field switch the scanner
     * on.
     *
     * It counts the OPT-IN, not eligibility, because those are different
     * questions and only one of them is "did an operator ask for this?".
     * A chain that is opted in but paused, unsupported or outside the
     * canary allowlist still counts here: somebody asked for it, so the
     * system is not idle — it has something to explain instead, and
     * {@see STATUS_BLOCKED} or the red/yellow arithmetic is what explains
     * it.
     *
     * @param list<ChainPanelRow> $chains
     */
    public static function optedInChainCount(array $chains): int
    {
        $count = 0;
        foreach ($chains as $chain) {
            if (self::optedIn($chain)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * PURE. Did an operator ask for this chain? ONE reader, used by both
     * the count above and the arithmetic in {@see deriveStatus()}.
     *
     * It is one function because the two callers must never drift: a
     * status that counted opt-ins one way and eligible chains another
     * could report "no chain is opted in" and "one chain is eligible" out
     * of the same array. FAIL CLOSED — only a literal `true`; see
     * {@see optedInChainCount()} for why a truthy value is not enough.
     *
     * @param ChainPanelRow $chain
     */
    private static function optedIn(array $chain): bool
    {
        return ($chain['discovery_opted_in'] ?? null) === true;
    }

    /**
     * PURE. Actionable operator lines, most severe first. Plain text —
     * escape at render, not here.
     *
     * @param  list<ChainPanelRow> $chains
     * @param  list<ScheduleEntry> $schedule
     * @return list<string>
     */
    public static function deriveIssues(
        bool $discoveryEnabled,
        bool $backfillEnabled,
        array $chains,
        array $schedule
    ): array {
        $issues = [];

        if (!$discoveryEnabled) {
            $issues[] = 'Discovery is OFF because `BCC_COSMWASM_DISCOVERY_ENABLED` is not defined in wp-config.php. '
                . 'The gate fails closed on purpose — a missing constant never means "enabled" — so no scheduled pass '
                . 'does any work and the controls below cannot start one.';

            return $issues;
        }

        if (!$backfillEnabled) {
            $issues[] = 'The historical backfill is OFF because `BCC_COSMWASM_BACKFILL_ENABLED` is not defined. '
                . 'Incremental discovery still runs; the one-time walk of each chain\'s full code listing does not.';
        }

        foreach ($schedule as $entry) {
            if (!$entry['scheduled']) {
                $issues[] = sprintf(
                    'Cron `%s` (%s) is not scheduled — that pass will never run. It self-heals on the next request; if it does not, reactivate the plugin.',
                    $entry['hook'],
                    $entry['label']
                );
            } elseif ($entry['overdue_seconds'] > self::CRON_OVERDUE_SECONDS) {
                $issues[] = sprintf(
                    'Cron `%s` (%s) is %s overdue. WP-Cron may be stalled.',
                    $entry['hook'],
                    $entry['label'],
                    self::formatDuration($entry['overdue_seconds'])
                );
            }
        }

        foreach ($chains as $chain) {
            if ($chain['unsupported']) {
                $issues[] = sprintf(
                    '%s has no CosmWasm module — it answered with a 501, so no scheduled pass asks it again and '
                        . 'no control on this page clears that. This is a fact about the chain, not a failure.',
                    $chain['slug']
                );
                continue;
            }
            if ($chain['paused']) {
                $issues[] = sprintf(
                    '%s is PAUSED by an operator. Nothing runs for it — no backfill, no daily pass, no retries — until it is resumed.',
                    $chain['slug']
                );
                continue;
            }
            if ($chain['last_error'] !== null) {
                $issues[] = sprintf(
                    '%s recorded an error on its last pass. Its progress is kept and it will be retried; see the chain row for the recorded reason.',
                    $chain['slug']
                );
            }
            $age = $chain['last_discovery_age_seconds'];
            if ($age !== null && $age > self::CHAIN_STALE_SECONDS) {
                $issues[] = sprintf(
                    '%s has not been touched in %s, which is more than two daily passes.',
                    $chain['slug'],
                    self::formatDuration($age)
                );
            }
        }

        return $issues;
    }

    /**
     * PURE. Why the panel is showing a disabled scanner, or null when it
     * is not.
     */
    public static function disabledReason(bool $discoveryEnabled, bool $backfillEnabled): ?string
    {
        if (!$discoveryEnabled) {
            return 'BCC_COSMWASM_DISCOVERY_ENABLED is not defined in wp-config.php.';
        }
        if (!$backfillEnabled) {
            return 'BCC_COSMWASM_BACKFILL_ENABLED is not defined in wp-config.php.';
        }

        return null;
    }

    /**
     * PURE. How far a chain has got — expressed as a watermark and a
     * cursor, NEVER as a percentage. See the class docblock for why there
     * is no trustworthy denominator to divide by.
     */
    public static function progressLabel(string $state, int $maxCodeId, bool $cursorOpen, int $familiesKnown): string
    {
        switch ($state) {
            case ChainCheckpointRepository::CW_STATE_UNSUPPORTED:
                return 'No wasm module on this chain — nothing to scan.';

            case ChainCheckpointRepository::CW_STATE_BACKFILLED:
                return sprintf(
                    'Backfill complete — %s code families inventoried, highest code ID %s. Incremental only from here.',
                    self::formatCount($familiesKnown),
                    self::formatCount($maxCodeId)
                );

            case ChainCheckpointRepository::CW_STATE_BACKFILLING:
                return sprintf(
                    'Backfill in progress — %s code families so far, highest code ID %s.%s',
                    self::formatCount($familiesKnown),
                    self::formatCount($maxCodeId),
                    $cursorOpen ? ' A resume point is open, so the next slice continues from there.' : ''
                );

            case ChainCheckpointRepository::CW_STATE_PAUSED:
                return sprintf(
                    'Paused — %s code families inventoried, highest code ID %s. Progress is kept.',
                    self::formatCount($familiesKnown),
                    self::formatCount($maxCodeId)
                );

            default:
                return $familiesKnown > 0
                    ? sprintf('Idle — %s code families inventoried.', self::formatCount($familiesKnown))
                    : 'Never started.';
        }
    }

    /** PURE. Operator label for a `cw_discovery_state` value. */
    public static function stateLabel(string $state): string
    {
        switch ($state) {
            case ChainCheckpointRepository::CW_STATE_BACKFILLING:
                return 'Backfilling';
            case ChainCheckpointRepository::CW_STATE_BACKFILLED:
                return 'Backfilled';
            case ChainCheckpointRepository::CW_STATE_UNSUPPORTED:
                return 'Unsupported';
            case ChainCheckpointRepository::CW_STATE_PAUSED:
                return 'Paused';
            default:
                return 'Idle';
        }
    }

    /**
     * PURE. "2h 14m" / "47m" / "12s". Shared with the render layer so the
     * panel and the issue lines never disagree about a duration.
     */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return ((int) floor($seconds / 60)) . 'm';
        }
        if ($seconds < 86400) {
            $h = (int) floor($seconds / 3600);
            $m = (int) floor(($seconds - $h * 3600) / 60);

            return $h . 'h ' . $m . 'm';
        }

        $d = (int) floor($seconds / 86400);
        $h = (int) floor(($seconds - $d * 86400) / 3600);

        return $d . 'd ' . $h . 'h';
    }

    /**
     * PURE. Thousands-separated integer.
     *
     * Deliberately PHP's `number_format`, not WordPress's
     * `number_format_i18n`: this class is pure derivation and is unit
     * tested outside WordPress, so pulling a WP formatting function in
     * here would make the tested helpers untestable without a shim. The
     * render layer, which is already inside WordPress, uses the i18n
     * variant for the raw numbers it prints.
     */
    public static function formatCount(int $value): string
    {
        return number_format($value);
    }

    /**
     * PURE. Age of a stored UTC `Y-m-d H:i:s` stamp, or null when absent
     * or unparseable.
     *
     * The ' UTC' suffix is not decoration: MySQL stores these with the
     * session time zone set to SYSTEM on this deployment, and the plugin
     * writes them with `current_time('mysql', true)` — i.e. UTC. Parsing
     * them in the server's local zone would skew every age by the offset.
     */
    public static function ageSeconds(?string $stamp, int $now): ?int
    {
        if ($stamp === null || $stamp === '') {
            return null;
        }
        $ts = strtotime($stamp . ' UTC');
        if ($ts === false) {
            return null;
        }

        return max(0, $now - $ts);
    }

    /**
     * PURE. How many families are CW-721 (confirmed + probable).
     *
     * @param array<string, int> $byClassification
     */
    public static function cw721Total(array $byClassification): int
    {
        return ($byClassification[CosmwasmClassifier::CONFIRMED] ?? 0)
            + ($byClassification[CosmwasmClassifier::PROBABLE] ?? 0);
    }
}
