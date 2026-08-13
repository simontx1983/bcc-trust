<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
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
    // them why. These five values are the answer, and they are the ONLY
    // values the panel may key on.
    //
    // EXACTLY ONE OF THEM MEANS "WILL BE SCANNED": ELIGIBLE. Every other
    // value — including the one that means "we could not tell" — is a NO.
    // That is deliberate: this is a display of a fail-closed rule, and a
    // display that guesses in the permissive direction is worse than no
    // display, because it invites an operator to conclude a chain is
    // covered when it is not.

    /** Nothing is blocking this chain; it is in the scanner's rotation. */
    public const ELIGIBILITY_ELIGIBLE = 'eligible';

    /** OPERATOR INTENT: `wp_bcc_chains.cosmwasm_nft_discovery_enabled` = 0. */
    public const ELIGIBILITY_NOT_OPTED_IN = 'not_opted_in';

    /** MEASURED CAPABILITY: the chain's wasm module answered with a 501. */
    public const ELIGIBILITY_UNSUPPORTED = 'unsupported';

    /** Outside the temporary `BCC_COSMWASM_CHAIN_ALLOWLIST` canary scope. */
    public const ELIGIBILITY_ALLOWLIST_EXCLUDED = 'allowlist_excluded';

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
     * {@see CosmwasmDiscoveryWorker::eligibleChainIds()} gives.
     */
    public const ELIGIBILITY_UNKNOWN = 'unknown';

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

        // READS 1-4. Everything below this line is PHP over these arrays.
        // All four throw rather than answering a failed query with an
        // empty set, because every one of them is a number this panel
        // prints as fact.
        try {
            $checkpoints   = ChainCheckpointRepository::getAllOrFail();
            $familyCounts  = CosmwasmCodeFamilyRepository::countsByChainAndClassification();
            $familyPending = CosmwasmCodeFamilyRepository::pendingCountsByChain(CosmwasmClassifier::VERSION);
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

            if ($row['eligibility'] === self::ELIGIBILITY_ELIGIBLE) {
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

        $unsupported = $state === ChainCheckpointRepository::CW_STATE_UNSUPPORTED;
        $eligibility = self::deriveEligibility($chainId, $unsupported, $discoveryOptedIn, $allowlist);

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
     * PURE. Will the scanner walk this chain, and if not, why not?
     *
     * ── IT MIRRORS THE CHOKEPOINT, IT IS NOT A SECOND COPY OF IT ────────
     * The authority is {@see CosmwasmDiscoveryWorker::eligibleChainIds()};
     * this answers the SAME question for display, over data the caller has
     * already loaded. The two must never disagree on the BOOLEAN — a panel
     * that says "eligible" about a chain the worker skips is worse than a
     * panel with no eligibility column at all — so every condition here is
     * one of the worker's, in the same direction:
     *
     *     unsupported            → checkpoint.cw_discovery_state
     *     opted in               → chains.cosmwasm_nft_discovery_enabled
     *     inside the canary list → BCC_COSMWASM_CHAIN_ALLOWLIST
     *
     * WHAT IT DOES NOT MIRROR is the ORDER, and only because the worker's
     * order is a short-circuit and this one is an explanation. The worker
     * drops a chain at the first failing condition and never looks at the
     * rest; a reader wants the reason that is hardest to act on named
     * FIRST, so `unsupported` — a measured fact about the chain that no
     * operator action can change, and the one case where opting in would
     * achieve nothing — is checked before the opt-in. The set of eligible
     * chains is identical either way; only the sentence differs.
     *
     * The `is_active`/`chain_type` conditions are not repeated because the
     * caller's chain list is already filtered to active Cosmos chains.
     *
     * @param list<int>|null $allowlist null = the constant is undefined
     */
    public static function deriveEligibility(
        int $chainId,
        bool $unsupported,
        ?bool $optedIn,
        ?array $allowlist
    ): string {
        if ($unsupported) {
            return self::ELIGIBILITY_UNSUPPORTED;
        }
        // ORDER MATTERS between these two: "nobody could read the column"
        // is not the same statement as "an operator said no", and telling
        // an operator they declined something they were never offered
        // sends them looking for a switch that is not there.
        if ($optedIn === null) {
            return self::ELIGIBILITY_UNKNOWN;
        }
        if ($optedIn === false) {
            return self::ELIGIBILITY_NOT_OPTED_IN;
        }
        // `$allowlist === []` lands here too — defined but naming no usable
        // chain id, which the gate documents as "scan nothing". in_array on
        // an empty list is false, so it needs no special case, but it does
        // get its own sentence in eligibilityReason().
        if ($allowlist !== null && !in_array($chainId, $allowlist, true)) {
            return self::ELIGIBILITY_ALLOWLIST_EXCLUDED;
        }

        return self::ELIGIBILITY_ELIGIBLE;
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
                return 'This chain has no CosmWasm module — it answered the code listing with a 501, '
                    . 'so it is permanently skipped. Opting it in would change nothing.';

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
            if ($chain['paused'] || $chain['unsupported']) {
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
     * `disabled` is its own value rather than a colour: an operator who
     * has not turned discovery on is not looking at a broken system, and
     * painting it red would train them to ignore red.
     *
     * @param list<ChainPanelRow> $chains
     * @param list<ScheduleEntry> $schedule
     */
    public static function deriveStatus(bool $discoveryEnabled, array $chains, array $schedule): string
    {
        if (!$discoveryEnabled) {
            return self::STATUS_DISABLED;
        }

        $eligible = 0;
        $errored  = 0;
        $stale    = 0;
        foreach ($chains as $chain) {
            if ($chain['paused'] || $chain['unsupported']) {
                continue;
            }
            $eligible++;
            if ($chain['last_error'] !== null) {
                $errored++;
            }
            $age = $chain['last_discovery_age_seconds'];
            if ($age === null || $age > self::CHAIN_STALE_SECONDS) {
                $stale++;
            }
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

        if ($eligible === 0 || $unscheduled > 0) {
            return self::STATUS_RED;
        }
        if ($errored > 0 || $stale === $eligible || $overdue > 0) {
            return self::STATUS_YELLOW;
        }

        return self::STATUS_GREEN;
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
                    '%s has no CosmWasm module, so it is permanently skipped. This is a fact about the chain, not a failure — the scanner stopped asking on purpose.',
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
