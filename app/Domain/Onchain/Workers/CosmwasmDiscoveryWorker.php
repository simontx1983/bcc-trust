<?php

namespace BCC\Trust\Onchain\Workers;

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryService;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use BCC\Trust\Onchain\Support\CosmwasmPassReport;
use BCC\Trust\Onchain\Support\CosmwasmScanEligibility;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CosmWasm CW-721 discovery worker.
 *
 * ── DISCOVERY IS OPERATOR-INITIATED. THERE ARE NO SCHEDULED PASSES. ─────
 * This worker owns no cron hooks. Chain-wide CW-721 discovery runs only
 * when a human starts it, one chain at a time, and every entry point here
 * takes a single chain id:
 *
 *   {@see runSupervisedSingleChainPass()} — ONE incremental pass on ONE
 *       chain, driven from WP-CLI by an operator who is watching.
 *   {@see runBackfillForChain()} — ONE historical backfill slice on ONE
 *       chain, driven from the admin Chains page, behind its own explicit
 *       switch on top of the master gate.
 *
 * Nothing in this class iterates chains. A caller names the chain or no
 * work happens — which is what makes "starting a scan for one chain
 * cannot touch another chain's fetcher" true by construction rather than
 * by review.
 *
 * Verification and provisioning stay manual regardless: nothing here sets
 * `is_verified` or calls a provisioning service.
 *
 * ── WHAT A PASS DOES ────────────────────────────────────────────────────
 * {@see dailyChainStep()} is the canonical incremental pass: (a) newly-
 * uploaded code ids, via a reverse tail read of the code listing against
 * the numeric watermark; (b) newly-instantiated contracts under
 * CONFIRMED/PROBABLE families; (c) classify what is queued; (d) emit the
 * classified CW-721s.
 *
 * Retry needs no pass of its own. The ordinary pending queries ALREADY
 * encode the whole policy — cap, staged backoff, exclusion of settled
 * negatives — so a retry is just the next pass reaching a row whose
 * `next_attempt_at` has come round. CONFIRMED `not_cw721` is never
 * retried, and that exclusion lives in SQL, in
 * {@see CosmwasmCodeFamilyRepository::findPendingClassification()}, so no
 * caller can forget it.
 *
 * Nothing routinely reprocesses a previously-inspected contract: the
 * durable inventory row IS the memory, and every work query filters on
 * it. A classifier-version bump requeues ONLY the explicitly-affected
 * classifications (never settled negatives).
 *
 * ── OPERATIONAL SHAPE (copied from {@see NftEthIndexerWorker}) ──────────
 *   - {@see CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS} wall-clock
 *     deadline that WINS over the request budget;
 *   - one chain slice per backfill invocation;
 *   - request/page budget as a configurable ceiling (default 50 per
 *     invocation);
 *   - per-chain NON-BLOCKING `AdvisoryLock` in try/finally, so two
 *     overlapping invocations cannot corrupt cursor state;
 *   - durable SAFE progress written BEFORE the deadline can bite, so a
 *     cut-short pass resumes rather than restarts;
 *   - `OnchainCircuitBreaker` consulted per chain;
 *   - retry/backoff on the row, not in memory;
 *   - failure isolation — the pass runs in its own try/catch and stamps
 *     its own last-run time;
 *   - manual pause/resume via `cw_discovery_state = paused`.
 *
 * Injective is INCLUDED, in the sense that nothing here excludes it by
 * name. Its curated Talis-whitelist path stays as-is, but the whole point
 * of this feature is that discovery must not be bounded by what someone
 * already curated — a whitelist is a curation, so code-id discovery runs
 * there too, once an operator opts the chain in and starts a pass.
 *
 * ── WHICH CHAINS MAY BE SCANNED ─────────────────────────────────────────
 * Exactly one function RESOLVES them: {@see eligibleChainIds()}, reached
 * through the {@see isChainScannable()} membership test every entry point
 * gates on. Exactly one function DECIDES per chain:
 * {@see CosmwasmScanEligibility::verdict()} — which the admin panel calls
 * as well, so the dashboard and the worker cannot answer "may this chain
 * be scanned?" differently. Both fail closed on every unsure branch.
 * `wp_bcc_chains.cosmwasm_nft_discovery_enabled` ships DEFAULT 0, so a
 * fresh install scans nothing until someone says otherwise, per chain.
 *
 * @phpstan-import-type CheckpointRow from ChainCheckpointRepository
 */
final class CosmwasmDiscoveryWorker
{
    private const ADVISORY_LOCK_PREFIX = 'bcc_cosmwasm_chain_';

    /**
     * The per-chain operator opt-in column, as it appears in the
     * `ChainRepository::getActive()` projection.
     *
     * Named here rather than dereferenced inline so the one place that
     * reads it is greppable from the column name. It must stay in
     * {@see ChainRepository}'s COLUMNS list; the integration test
     * `ChainCosmwasmDiscoveryFlagIntegrationTest` pins the two together
     * against a real MySQL, because a column silently dropped from that
     * projection would make every chain look ineligible — a change that
     * fails SILENT and SAFE, and would therefore never be noticed.
     */
    private const DISCOVERY_FLAG_COLUMN = 'cosmwasm_nft_discovery_enabled';

    /** Families classified per chain per incremental pass. */
    private const FAMILIES_PER_PASS = 25;

    /** Contracts classified per chain per incremental pass. */
    private const CONTRACTS_PER_PASS = 25;

    /** Families re-opened for new contracts per daily pass. */
    private const FAMILIES_ENUMERATED_PER_PASS = 10;

    /** Collections emitted per chain per pass. */
    private const EMIT_PER_PASS = 25;

    /** Rows requeued per chain when the classifier version moves. */
    private const REQUEUE_PER_PASS = 100;

    // ── DOWNSTREAM BUDGET RESERVES ──────────────────────────────────────
    //
    // The pass runs four stages in a fixed order against ONE shared
    // CosmwasmTickBudget. Nothing stopped the first stage spending all 50
    // requests, and on a chain with a classification backlog it reliably
    // did. Measured on Dungeon: a confirmed CW-721 family and an
    // already-emittable contract sat untouched while the queue ahead of
    // them was worked through, pass after pass. Every stage was correct;
    // the pipeline still produced nothing.
    //
    // ── THE MAXIMA, READ OFF THE CONTROL FLOW ───────────────────────────
    // These are the total cost of ONE unit of work in each stage, counted
    // from the `spend()` calls themselves — not estimated from row counts:
    //
    //   classifyFamily()        1 contracts page
    //                         + up to FAMILY_SAMPLE_SIZE (3) samples
    //                         x up to 3 probes each          = 10
    //   enumerateFamilyTail()   up to CONTRACT_TAIL_MAX_PAGES = 3
    //   enumerateFamilyPage()   exactly one spend, no loop    = 1
    //   classifyContract()      one spend of count(outcomes)  = 3
    //   emitCollections()       one spend per candidate       = 1
    //
    // NOTE the asymmetry: the TAIL walks up to 3 pages, the PAGE walks
    // exactly one. They are different stages and cost different amounts.
    //
    // ── THE FLOORS, WORKED BACKWARDS FROM EMISSION ──────────────────────
    // Each stage holds back the cost of one unit of every stage still to
    // come, so the last stage can always afford at least one candidate:
    //
    //   during contract classification  reserve emit(1)            =  1
    //   during enumeration              reserve contract(3)+emit(1)=  4
    //   during family classification    reserve enum(1)+4          =  5
    //   during the drained-family tail  reserve family(10)+5       = 15
    //
    // Stage (a), the code-id walk, is deliberately unreserved: it is
    // bounded at CODE_TAIL_MAX_PAGES (5) of a 50-request budget and it is
    // the source of every downstream row. Starving it would starve
    // everything.

    /** Emission: one candidate. */
    private const RESERVE_EMIT = 1;

    /** After enumeration: one contract classification + one emission. */
    private const RESERVE_AFTER_ENUMERATION = 3 + self::RESERVE_EMIT;

    /** After family classification: one enumeration page + the above. */
    private const RESERVE_AFTER_FAMILIES = 1 + self::RESERVE_AFTER_ENUMERATION;

    /** After the drained-family tail: one whole family + the above. */
    private const RESERVE_AFTER_TAIL = 10 + self::RESERVE_AFTER_FAMILIES;

    // ── Historical backfill ─────────────────────────────────────────────

    /**
     * Backfill one chain. Public so an admin "Run now" can drive it.
     *
     * The advisory lock is NON-BLOCKING: if another invocation (a
     * admin click, a CLI run) already holds it, this one skips rather
     * than interleaving cursor writes.
     *
     * ── IT NOW SAYS WHAT HAPPENED. THE WORK IS UNCHANGED ────────────────
     * This used to return `void`, and the admin handler that drove it could
     * therefore prove nothing: not that the lock was taken, not that a
     * request was made, not that progress moved. So its audit event had to
     * be called `..._requested`, because `_ran` would have been a claim the
     * signature could not support.
     *
     * It now returns one of the same `PASS_*` constants
     * {@see runSupervisedSingleChainPass()} returns, and threads the same
     * OPTIONAL {@see CosmwasmPassReport}. Not one line of the backfill
     * itself moved — every gate, every write, every early return is exactly
     * where it was. The only difference is that the outcome is no longer
     * discarded on the way out, so a caller can report it honestly instead
     * of hedging.
     *
     * Existing callers that ignore the return value are unaffected.
     *
     * ── WHAT IT STILL DOES NOT DO ───────────────────────────────────────
     * It does not catch. A throw propagates, exactly as before, so the
     * caller's own failure path (correlation id, durable row) still owns
     * that case. There is deliberately no `PASS_FAILED` return here.
     *
     * @param ?CosmwasmPassReport $report OPTIONAL write-only telemetry.
     * @return string one of the PASS_* constants
     */
    public static function runBackfillForChain(
        int $chainId,
        ?CosmwasmTickBudget $budget = null,
        ?CosmwasmPassReport $report = null
    ): string {
        if ($chainId <= 0 || !CosmwasmDiscoveryGate::backfillEnabled()) {
            return self::PASS_SKIPPED;
        }

        // THE ELIGIBILITY SET, ASKED HERE AND NOT ONLY AT THE CALLER.
        //
        // A round-robin backfill tick used to resolve the chain through
        // eligibleChainIds() before ever reaching this method, so operator
        // opt-in and the canary allowlist were enforced upstream and this
        // method inherited them. That tick is gone: the admin "Run backfill
        // slice" control is now the ONLY way in, and it gates on the two
        // environment constants and the pause state — not on opt-in and not
        // on the allowlist.
        //
        // Without this check, retiring the tick would have QUIETLY WIDENED
        // what a backfill may touch: a chain nobody opted in, or one
        // deliberately held outside BCC_COSMWASM_CHAIN_ALLOWLIST, would
        // have become backfillable by pressing a button. Membership is
        // tested against the same selector the supervised pass uses, so
        // both operator entry points enforce one rule.
        if (!self::isChainScannable($chainId)) {
            \BCC\Core\Log\Logger::info('[CosmwasmDiscoveryWorker] backfill refused — chain is not in the eligible set', [
                'chain_id' => $chainId,
            ]);

            return self::PASS_SKIPPED;
        }

        $lockKey = self::ADVISORY_LOCK_PREFIX . $chainId;
        if (!\BCC\Core\DB\AdvisoryLock::acquire($lockKey, 0)) {
            \BCC\Core\Log\Logger::info('[CosmwasmDiscoveryWorker] backfill skipped — concurrent run holds the lock', [
                'chain_id' => $chainId,
            ]);
            return self::PASS_LOCKED;
        }

        try {
            return self::backfillInsideLock($chainId, $budget ?? new CosmwasmTickBudget(), $report);
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lockKey);
        }
    }

    /**
     * @param ?CosmwasmPassReport $report OPTIONAL write-only telemetry.
     * @return string one of the PASS_* constants
     */
    private static function backfillInsideLock(
        int $chainId,
        CosmwasmTickBudget $budget,
        ?CosmwasmPassReport $report = null
    ): string {
        $context = self::prepareChain($chainId);
        if ($context === null) {
            return self::PASS_SKIPPED;
        }
        $fetcher    = $context['fetcher'];
        $checkpoint = $context['checkpoint'];

        $state = (string) $checkpoint->cw_discovery_state;

        // Phase A — drain the code listing (the inventory).
        if ($state !== ChainCheckpointRepository::CW_STATE_BACKFILLED) {
            $cursor = is_string($checkpoint->cw_code_cursor) && $checkpoint->cw_code_cursor !== ''
                ? (string) $checkpoint->cw_code_cursor
                : null;
            $maxCodeId = (int) $checkpoint->cw_max_code_id;

            // The stored cursor is an OPAQUE key minted by whichever node
            // answered last time, and `rest.cosmos.directory` round-robins.
            // Track that we are resuming so a rejected or unintelligible
            // key restarts the walk instead of truncating it.
            $resuming = $cursor !== null;

            while (!$budget->exhausted()) {
                $page = CosmwasmDiscoveryService::ingestCodePage($chainId, $fetcher, $cursor, $budget);

                if (!$page['ok']) {
                    if ($resuming && !CosmwasmDiscoveryService::isUnsupportedChainError($page['error_kind'], $page['http_code'])) {
                        // A stored key the node would not accept. Drop it —
                        // a restart is cheap and safe (`uk_chain_code` makes
                        // every write idempotent), a poisoned cursor is not.
                        ChainCheckpointRepository::requestCwBackfillRestart(
                            $chainId,
                            'stale pagination cursor rejected — restarting walk: '
                                . CosmwasmClassifier::sanitizeExcerpt($page['message'])
                        );
                        \BCC\Core\Log\Logger::warning('[CosmwasmDiscoveryWorker] stale code cursor — walk will restart', [
                            'chain_id' => $chainId,
                            'error'    => $page['message'],
                        ]);

                        // The pass DID run and then aborted on a provider
                        // answer. Recorded on the report so a surface that
                        // shows the run cannot present this as a clean
                        // finish: the budget may be untouched, so the stop
                        // reason alone would read `pass_completed`.
                        $report?->addError(
                            'stale pagination cursor rejected — walk will restart: '
                                . CosmwasmClassifier::sanitizeExcerpt($page['message'])
                        );

                        return self::PASS_RAN;
                    }

                    if (CosmwasmDiscoveryService::isUnsupportedChainError($page['error_kind'], $page['http_code'])) {
                        // Durable and terminal: this chain has no wasm
                        // module. Retrying it every pass forever would be
                        // budget burned on a guaranteed failure.
                        ChainCheckpointRepository::setCwDiscoveryState(
                            $chainId,
                            ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
                            'wasm module not available (HTTP ' . $page['http_code'] . ')'
                        );
                        \BCC\Core\Log\Logger::info('[CosmwasmDiscoveryWorker] chain has no wasm module — discovery disabled for it', [
                            'chain_id'  => $chainId,
                            'http_code' => $page['http_code'],
                        ]);

                        $report?->addError(
                            'wasm module not available (HTTP ' . $page['http_code'] . ') — '
                                . 'discovery is now marked unsupported for this chain'
                        );

                        return self::PASS_RAN;
                    }

                    // Merely unreachable: keep the cursor, record why,
                    // retry next tick. The state is NOT settled.
                    OnchainCircuitBreaker::recordFailure($chainId);
                    ChainCheckpointRepository::setCwDiscoveryState(
                        $chainId,
                        ChainCheckpointRepository::CW_STATE_BACKFILLING,
                        CosmwasmClassifier::sanitizeExcerpt($page['message'])
                    );

                    $report?->addError(
                        'code listing unreachable — cursor kept for the next pass: '
                            . CosmwasmClassifier::sanitizeExcerpt($page['message'])
                    );

                    return self::PASS_RAN;
                }

                // A RESUMED page that is empty AND final is, on the wire,
                // indistinguishable from a node that could not interpret
                // another node's key. We know this chain had more codes
                // (a cursor only exists because an earlier page returned
                // some), so accepting "complete" here would silently
                // truncate the inventory. Restart instead.
                if ($resuming && $page['families'] === 0 && $page['next_key'] === null) {
                    ChainCheckpointRepository::requestCwBackfillRestart(
                        $chainId,
                        'resumed pagination cursor returned an empty final page — restarting walk'
                    );
                    \BCC\Core\Log\Logger::warning('[CosmwasmDiscoveryWorker] resumed code page was empty — treating cursor as stale', [
                        'chain_id'       => $chainId,
                        'max_code_id'    => $maxCodeId,
                    ]);

                    $report?->addError(
                        'resumed pagination cursor returned an empty final page — walk will restart'
                    );

                    return self::PASS_RAN;
                }
                $resuming = false;

                $report?->addCodePages(1);

                $maxCodeId = max($maxCodeId, $page['max_code_id']);
                $cursor    = $page['next_key'];

                // SAFE PROGRESS WRITTEN NOW — before the deadline can bite.
                ChainCheckpointRepository::recordCwCodeProgress(
                    $chainId,
                    $cursor,
                    $maxCodeId,
                    $cursor === null
                );

                if ($cursor === null) {
                    break; // Inventory drained.
                }
            }

            OnchainCircuitBreaker::recordSuccess($chainId);
        }

        // Phase B — spend whatever budget is left classifying families and
        // enumerating the CW-721 ones. Phase A owns the tick early on;
        // once the inventory is drained the whole budget lands here.
        self::classifyAndEnumerate($chainId, $fetcher, $budget, $report);

        return self::PASS_RAN;
    }

    // ── The incremental pass ────────────────────────────────────────────

    /**
     * THE CANONICAL INCREMENTAL PASS, for ONE chain.
     *
     * One body of code defines what "a discovery pass" means, and the
     * operator entry point reaches it: {@see runSupervisedSingleChainPass()}.
     *
     * There is deliberately NO second implementation. A hand-written
     * "just run the important bits" variant is how an operator ends up
     * watching something that is not the thing the system does, and
     * concluding from it.
     *
     * ── EMISSION IS PART OF THIS PASS ───────────────────────────────────
     * Step (c)+(d) is {@see classifyAndEnumerate()}, which ENDS with
     * {@see CosmwasmDiscoveryService::emitCollections()}. Emission is not
     * separable from the canonical pass and no caller may ask for the
     * pass without it.
     *
     * @param ?CosmwasmPassReport $report OPTIONAL write-only telemetry.
     */
    private static function dailyChainStep(
        int $chainId,
        CosmosFetcher $fetcher,
        CosmwasmTickBudget $budget,
        ?CosmwasmPassReport $report = null
    ): void {
        // A caller may hand in a budget that already carries a reserve
        // from earlier work. Start from zero so this pass's own stage
        // floors are the only ones in force. Stage (a) below is
        // deliberately unreserved — see the RESERVE_* constants.
        $budget->reserve(0);

        // A classifier-version bump requeues ONLY the affected
        // classifications. Settled `not_cw721` is excluded by the
        // default affected set, so a version bump never resurrects a
        // family we already ruled out.
        CosmwasmCodeFamilyRepository::requeueForClassifierVersion(
            $chainId,
            CosmwasmClassifier::VERSION,
            self::REQUEUE_PER_PASS
        );
        CosmwasmContractRepository::requeueForClassifierVersion(
            $chainId,
            CosmwasmClassifier::VERSION,
            self::REQUEUE_PER_PASS
        );

        // (a) newly-uploaded code ids — a REVERSE walk against the
        //     numeric watermark. Newest-first, stop at the first
        //     `code_id <= cw_max_code_id`. Typical daily cost: ONE
        //     request per chain.
        //
        //     This replaced an offset tail read that was MEASURED
        //     broken on cosmoshub, juno, osmosis and injective (a
        //     non-zero offset returns an empty 200 — see
        //     CosmosFetcher::listCodeFamilies). Do not reintroduce it.
        $checkpoint = ChainCheckpointRepository::get($chainId);
        $watermark  = $checkpoint !== null ? (int) $checkpoint->cw_max_code_id : 0;

        $tail = CosmwasmDiscoveryService::ingestNewCodeFamilies(
            $chainId,
            $fetcher,
            $watermark,
            $budget,
            CosmwasmDiscoveryGate::CODE_TAIL_MAX_PAGES
        );
        $report?->addCodePages($tail['pages']);

        if (!$tail['ok']) {
            $report?->addError('code tail read failed: ' . $tail['message']);
            if (CosmwasmDiscoveryService::isUnsupportedChainError($tail['error_kind'], $tail['http_code'])) {
                ChainCheckpointRepository::setCwDiscoveryState(
                    $chainId,
                    ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
                    'wasm module not available (HTTP ' . $tail['http_code'] . ')'
                );

                return;
            }
            // Transport/node failure — retried next pass, nothing settled.
            OnchainCircuitBreaker::recordFailure($chainId);
        } elseif ($tail['reached_watermark']) {
            // AUTHORITATIVE: the walk actually met the watermark (or the
            // chain has nothing more), so the contiguous high-water mark
            // may move.
            if ($tail['newest_code_id'] > 0) {
                ChainCheckpointRepository::advanceCwCodeWatermark($chainId, $tail['newest_code_id']);
            }
        } else {
            // NOT AUTHORITATIVE. Either the page budget ran out before
            // the watermark, or the read came back empty while we KNOW
            // code ids exist. Advancing the watermark here would strand
            // every id between it and this walk's lowest page, and
            // calling it "nothing new" would be exactly the fake-healthy
            // failure the offset bug had. Hand the catch-up to the
            // historical backfill and leave the chain visibly degraded.
            $anomaly = $tail['anomaly']
                ? 'incremental reverse read returned an empty page despite a non-zero watermark'
                : 'incremental reverse read did not reach the watermark within its page budget';
            ChainCheckpointRepository::requestCwBackfillRestart($chainId, $anomaly);
            $report?->addError($anomaly);
            \BCC\Core\Log\Logger::warning('[CosmwasmDiscoveryWorker] incremental code tail did not reach the watermark', [
                'chain_id'  => $chainId,
                'watermark' => $watermark,
                'pages'     => $tail['pages'],
                'ingested'  => $tail['ingested'],
                'lowest'    => $tail['lowest_code_id'],
                'anomaly'   => $tail['anomaly'],
            ]);
        }

        // (b) new contracts under CONFIRMED/PROBABLE families whose
        //     forward walk already DRAINED — a reverse tail stopping at
        //     the first already-inventoried address. Families still
        //     mid-walk are left to the forward cursor in (c). Settled
        //     `not_cw721` families are excluded by the query, so they
        //     are never re-walked.
        $families = CosmwasmCodeFamilyRepository::findEnumerable(
            $chainId,
            self::FAMILIES_ENUMERATED_PER_PASS,
            false
        );
        // Hold back one whole family classification plus everything after
        // it, so a long tail walk cannot consume the pass. Lowered again
        // by classifyAndEnumerate() for each stage in turn.
        $budget->reserve(self::RESERVE_AFTER_TAIL);
        foreach ($families as $family) {
            if ($budget->exhausted()) {
                break;
            }
            if ((int) $family->enumeration_complete !== 1) {
                continue; // Still draining — (c) continues its cursor.
            }
            $enumerated = CosmwasmDiscoveryService::enumerateFamilyTail(
                $chainId,
                $fetcher,
                $family,
                $budget,
                CosmwasmDiscoveryGate::CONTRACT_TAIL_MAX_PAGES
            );
            $report?->addContractPages($enumerated['pages']);
        }

        // (c) + (d)
        self::classifyAndEnumerate($chainId, $fetcher, $budget, $report);
    }

    // ── Shared machinery ────────────────────────────────────────────────

    /**
     * Classify queued families, classify queued contracts, then emit.
     *
     * Ordered cheapest-signal-first so a small budget still makes
     * forward progress: family classification can settle thousands of
     * contracts at once (or, via a checksum twin, for free), whereas
     * per-contract probing is linear.
     */
    private static function classifyAndEnumerate(
        int $chainId,
        CosmosFetcher $fetcher,
        CosmwasmTickBudget $budget,
        ?CosmwasmPassReport $report = null
    ): void {
        $priority = CosmwasmDiscoveryGate::priorityCodeIds(self::chainSlug($chainId));

        // ── STAGE ORDER IS UNCHANGED; ONLY THE CEILING PER STAGE MOVES ──
        // Each `reserve()` below holds back one unit of work for every
        // stage still to come. It is read by canSpend()/exhausted() on
        // EVERY spend, so a family that has already bought its contracts
        // page stops before a sample that would breach the floor — the
        // partial work is durable and the family stays pending, which is
        // the same resumption path an exhausted budget always used.
        $families = CosmwasmCodeFamilyRepository::findPendingClassification(
            $chainId,
            self::FAMILIES_PER_PASS,
            CosmwasmClassifier::VERSION,
            $priority
        );
        $budget->reserve(self::RESERVE_AFTER_FAMILIES);
        foreach ($families as $family) {
            if ($budget->exhausted()) {
                break;
            }
            CosmwasmDiscoveryService::classifyFamily($chainId, $fetcher, $family, $budget);
            $report?->countFamilyClassified();
        }

        // Drain contract listings for families that ARE CW-721.
        // Re-read AFTER classification: a family confirmed moments ago in
        // this same pass is enumerable now, which is what lets work created
        // upstream flow downstream within one invocation.
        $enumerable = CosmwasmCodeFamilyRepository::findEnumerable(
            $chainId,
            self::FAMILIES_ENUMERATED_PER_PASS,
            true
        );
        $budget->reserve(self::RESERVE_AFTER_ENUMERATION);
        foreach ($enumerable as $family) {
            if ($budget->exhausted()) {
                break;
            }
            CosmwasmDiscoveryService::enumerateFamilyPage($chainId, $fetcher, $family, $budget);
            $report?->addContractPages(1);
        }

        $contracts = CosmwasmContractRepository::findPendingClassification(
            $chainId,
            self::CONTRACTS_PER_PASS,
            CosmwasmClassifier::VERSION
        );
        $budget->reserve(self::RESERVE_EMIT);
        foreach ($contracts as $row) {
            if ($budget->exhausted()) {
                break;
            }
            CosmwasmDiscoveryService::classifyContract($chainId, $fetcher, $row, $budget);
            $report?->countContractClassified();
        }

        // Last stage: nothing follows it, so it may use what is left.
        $budget->reserve(0);
        if (!$budget->exhausted()) {
            $emitted = CosmwasmDiscoveryService::emitCollections($chainId, $fetcher, $budget, self::EMIT_PER_PASS);
            $report?->addEmitted($emitted['emitted'], $emitted['denied']);
        }
    }

    // ── The outcome of ONE chain's pass ─────────────────────────────────
    //
    // The SUPERVISED one-shot maps them onto process exit codes, so
    // an operator (or a script) can tell "another worker holds the lock"
    // apart from "the chain refused to prepare" apart from "the pass
    // threw". They are constants on the worker rather than on the CLI
    // command because the worker is what produces them.

    /** The step ran. It may still have been cut short by its budget. */
    public const PASS_RAN = 'ran';

    /** A concurrent holder has the per-chain advisory lock. Nothing ran. */
    public const PASS_LOCKED = 'locked';

    /** {@see prepareChain()} refused: paused, unsupported, breaker open, no driver. */
    public const PASS_SKIPPED = 'skipped';

    /** The step threw. Recorded on the row; the throw does not escape. */
    public const PASS_FAILED = 'failed';

    /**
     * ONE chain's pass — THE single implementation of the per-chain
     * envelope, and the reason there is no second one.
     *
     * Lock, prepare, run, circuit breaker, and the `finally` that stamps
     * `cw_last_discovery_at` and releases the lock: every caller gets all
     * of it or none of it. {@see runSupervisedSingleChainPass()} and
     * {@see runBackfillForChain()} both go through it; neither owns a
     * copy.
     *
     * ── ORDERING: NOTHING IS WRITTEN BEFORE THE LOCK ────────────────────
     * `ensureExists()` used to run BEFORE `acquire()`, which meant a chain
     * whose lock was held by a peer still had a checkpoint row upserted by
     * the loser. It is now inside the lock. Behaviour on the ACQUIRED path
     * is unchanged, because {@see prepareChain()} calls `ensureExists()` as
     * its own first statement — the pre-lock call was always redundant
     * there, and only ever observable on the path that does no work.
     *
     * @param callable(int, CosmosFetcher, CosmwasmTickBudget): void $step
     * @return string one of the PASS_* constants
     */
    private static function runChainPass(int $chainId, callable $step, CosmwasmTickBudget $budget): string
    {
        $lockKey = self::ADVISORY_LOCK_PREFIX . $chainId;
        if (!\BCC\Core\DB\AdvisoryLock::acquire($lockKey, 0)) {
            return self::PASS_LOCKED;
        }

        $outcome = self::PASS_RAN;

        try {
            ChainCheckpointRepository::ensureExists($chainId);

            $context = self::prepareChain($chainId);
            if ($context === null) {
                return self::PASS_SKIPPED;
            }
            $step($chainId, $context['fetcher'], $budget);

            // ── NO recordSuccess() HERE. READ THIS BEFORE ADDING ONE ────
            //
            // There used to be an unconditional
            // `OnchainCircuitBreaker::recordSuccess($chainId)` on this
            // line, and it was WRONG in the one case that matters.
            //
            // "The step returned" is not "the chain is healthy".
            // {@see dailyChainStep()} deliberately swallows per-family
            // failures — an unreachable node is recorded on the FAMILY
            // ROW and the loop moves on, so the step returns normally
            // whether every probe succeeded or every probe failed. The
            // call therefore RESET A BREAKER THAT REAL FAILURES HAD JUST
            // OPENED, erasing the protection at the exact moment it was
            // earned. Measured on Dungeon 2026-08-19: 15 families were
            // blocked by an open breaker and the pass closed it again on
            // the way out.
            //
            // The breaker does not need this call. It is already driven
            // by ACTUAL TRANSPORT EVIDENCE, per response, inside
            // {@see ApiRetry::request()}: recordSuccess on every 2xx,
            // recordFailure on every 429/5xx/WP_Error. That is a real
            // observation of the chain; this was an assumption about it.
            // Removing it means a genuinely open breaker stays open, and
            // recovery happens the way the breaker itself defines it —
            // the cooldown plus a half-open probe.
        } catch (\Throwable $e) {
            // FAILURE ISOLATION: one broken chain must not stop the
            // others, so the throw dies here and the loop continues.
            $outcome = self::PASS_FAILED;
            \BCC\Core\Log\Logger::error('[CosmwasmDiscoveryWorker] chain pass failed', [
                'chain_id' => $chainId,
                'error'    => $e->getMessage(),
            ]);
            OnchainCircuitBreaker::recordFailure($chainId);
            ChainCheckpointRepository::setCwDiscoveryState(
                $chainId,
                ChainCheckpointRepository::CW_STATE_BACKFILLING,
                CosmwasmClassifier::sanitizeExcerpt($e->getMessage())
            );
        } finally {
            ChainCheckpointRepository::touchCwDiscovery($chainId);
            \BCC\Core\DB\AdvisoryLock::release($lockKey);
        }

        return $outcome;
    }

    // ── The supervised operator one-shot ────────────────────────────────

    /**
     * EXACTLY ONE incremental discovery pass, for EXACTLY ONE chain,
     * driven by a human at a terminal.
     *
     * ── IT IS THE ONLY INCREMENTAL ENTRY POINT ──────────────────────────
     * It runs {@see dailyChainStep()} inside {@see runChainPass()} — the
     * canonical pass inside the canonical per-chain envelope. It cannot
     * reach the historical backfill: that is {@see runBackfillForChain()},
     * behind its own switch, and this method does not name it.
     *
     * ── AUTHORIZATION IS THE CALLER'S JOB, AND IT IS STRICTER ───────────
     * This method deliberately does NOT check
     * {@see CosmwasmDiscoveryGate::discoveryEnabled()} — bypassing that
     * one constant is the entire point of a supervised run. EVERY other
     * condition still applies and is enforced by the CLI command before it
     * gets here, via {@see isChainScannable()} — membership in the same
     * eligibility set every other entry point resolves, so this path can
     * never be the most permissive one.
     *
     * @param CosmwasmPassReport $report write-only telemetry for the summary
     * @return string one of the PASS_* constants
     */
    public static function runSupervisedSingleChainPass(
        int $chainId,
        CosmwasmTickBudget $budget,
        CosmwasmPassReport $report
    ): string {
        if ($chainId <= 0) {
            return self::PASS_SKIPPED;
        }

        return self::runChainPass(
            $chainId,
            static function (int $id, CosmosFetcher $fetcher, CosmwasmTickBudget $tickBudget) use ($report): void {
                self::dailyChainStep($id, $fetcher, $tickBudget, $report);
            },
            $budget
        );
    }

    /**
     * Is this ONE chain in the set an operator may start a scan on?
     *
     * ── MEMBERSHIP, NOT A RE-DERIVATION ─────────────────────────────────
     * Implemented as `in_array($chainId, eligibleChainIds())` on purpose.
     * The obvious alternative — ask
     * {@see CosmwasmScanEligibility::verdict()} directly with facts
     * gathered here — would be a THIRD place that knows how to resolve
     * those facts, and the two prior times this rule was maintained in two
     * places they drifted inside a fortnight. Asking the production
     * selector for the production set and testing membership cannot
     * drift.
     *
     * Public only so the supervised CLI command can gate on it.
     */
    public static function isChainScannable(int $chainId): bool
    {
        if ($chainId <= 0) {
            return false;
        }

        return in_array($chainId, self::eligibleChainIds(), true);
    }

    /**
     * Resolve the per-chain preconditions: checkpoint row, operator
     * pause, durable "no wasm module", circuit breaker, and a CosmWasm
     * fetcher.
     *
     * ── THE PAUSE AND UNSUPPORTED CHECKS ARE NOT REDUNDANT ──────────────
     * {@see eligibleChainIds()} already drops both, so the scheduled
     * passes never reach here with a paused or unsupported chain. This
     * method is also on the MANUAL path: {@see runBackfillForChain()} is
     * public, an operator "Run backfill slice" click calls it with one
     * chain id, and that path never touches the selector. Deleting these
     * two lines would make the button ignore a pause.
     *
     * The circuit breaker and the fetcher checks live ONLY here on
     * purpose. They are runtime conditions that can change between the
     * moment a chain is selected and the moment it is worked, and they are
     * deliberately NOT part of the shared eligibility verdict — a panel
     * that reported "not eligible" for a chain whose breaker happens to be
     * open right now would be describing a transient as a configuration.
     *
     * @return array{fetcher: CosmosFetcher, checkpoint: CheckpointRow}|null
     */
    private static function prepareChain(int $chainId): ?array
    {
        ChainCheckpointRepository::ensureExists($chainId);

        $checkpoint = ChainCheckpointRepository::get($chainId);
        if ($checkpoint === null) {
            return null;
        }

        $cwState = (string) $checkpoint->cw_discovery_state;
        if ($cwState === ChainCheckpointRepository::CW_STATE_PAUSED) {
            return null; // Operator pause.
        }
        if ($cwState === ChainCheckpointRepository::CW_STATE_UNSUPPORTED) {
            return null; // No wasm module — durable, never retried.
        }

        if (OnchainCircuitBreaker::isOpen($chainId)) {
            return null;
        }

        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return null;
        }
        if (!FetcherFactory::has_driver((string) $chain->chain_type)) {
            return null;
        }
        $fetcher = FetcherFactory::make_for_chain($chain);
        if (!($fetcher instanceof CosmosFetcher)) {
            return null;
        }

        return ['fetcher' => $fetcher, 'checkpoint' => $checkpoint];
    }

    /**
     * THE ELIGIBILITY CHOKEPOINT — every chain this worker may scan, and
     * the only place that decision is made.
     *
     * Every entry point reaches it through {@see isChainScannable()},
     * which is a membership test against this set. A new entry point gets
     * the policy by construction as long as it gates the same way. It is
     * deliberately NOT named `cosmosChainIds()`: a reader who believed
     * that name would conclude the returned list is "the cosmos chains",
     * and would then quite reasonably filter it further somewhere else.
     *
     * ── IT DOES NOT OWN THE RULE, IT RESOLVES THE DATA FOR IT ───────────
     * The per-chain decision lives in
     * {@see CosmwasmScanEligibility::verdict()}, which the ADMIN PANEL
     * calls too. This method's job is the part that cannot be shared: two
     * bounded reads (the chain registry and the checkpoint table) plus the
     * allowlist constant, turned into the three facts the predicate wants.
     * The panel resolves the same three facts from rows it has already
     * fetched for other reasons.
     *
     * That split is the whole point. When the rule lived here AND in the
     * panel they drifted twice inside a fortnight — first the panel
     * counted chains nobody had opted in, then it counted chains outside
     * the canary allowlist — and both times the panel reported a healthy
     * scanner that was scanning nothing. Two definitions of "scannable" is
     * how a dashboard starts lying.
     *
     * ── THE CONDITIONS THIS METHOD STILL OWNS ───────────────────────────
     *   is_active = 1                          (chain registry)
     *   AND chain_type = 'cosmos'              (only cosmos speaks wasmd)
     * plus everything {@see CosmwasmScanEligibility::verdict()} decides:
     * operator intent, measured capability, operator pause, canary scope.
     *
     * Operator intent and measured capability are separate on purpose.
     * Intent is a decision a human makes; capability is a fact the chain
     * reported. Collapsing them into one hand-maintained
     * "supports_cosmwasm" column would mean an operator could assert a
     * capability the chain does not have, and the 501 the code already
     * learns would have nowhere to be written.
     *
     * ── PAUSE IS FILTERED HERE NOW, AND STILL RE-CHECKED LATER ──────────
     * `cw_discovery_state = paused` used to be caught only in
     * {@see prepareChain()}, one layer down — so a paused chain was
     * resolved as eligible, locked, `ensureExists()`-ed and stamped before
     * anything noticed. Excluding it here means a paused chain is not
     * considered at all, which is also what makes the panel's count able
     * to mean something. {@see prepareChain()} KEEPS its own pause check:
     * {@see runBackfillForChain()} is public and an operator "Run backfill
     * slice" click reaches it WITHOUT passing through this method.
     *
     * ── EVERY UNSURE BRANCH RETURNS FEWER CHAINS ────────────────────────
     * The bug shape being avoided is the one this codebase has shipped
     * twice: a guard whose "not configured" branch answers the permissive
     * way. The retired `BCC_CW721_DISCOVERY_ENABLED` literally read
     * `if (!defined(...)) return true;`. So:
     *   - the column missing from the cached projection (migration has not
     *     run yet, or a stale pre-migration transient) yields NO chains —
     *     never "the field is absent, so skip that filter";
     *   - a defined-but-unusable allowlist yields NO chains — never a
     *     fall-through to "all";
     *   - a checkpoint read that did not run yields NO chains.
     * A pass that scans nothing costs one operator invocation and is
     * corrected by fixing the configuration it named.
     *
     * @return list<int>
     */
    private static function eligibleChainIds(): array
    {
        // null = undefined (no canary restriction); [] = defined but names
        // no usable chain, which means scan nothing. See
        // CosmwasmDiscoveryGate::chainAllowlist().
        $allowlist = CosmwasmDiscoveryGate::chainAllowlist();
        if ($allowlist === []) {
            \BCC\Core\Log\Logger::warning(
                '[CosmwasmDiscoveryWorker] BCC_COSMWASM_CHAIN_ALLOWLIST is defined but names no usable chain id — scanning nothing',
                ['raw' => defined('BCC_COSMWASM_CHAIN_ALLOWLIST') ? gettype(constant('BCC_COSMWASM_CHAIN_ALLOWLIST')) : 'undefined']
            );

            return [];
        }

        // MEASURED CAPABILITY, in ONE bounded read rather than a checkpoint
        // query per chain. Fail-CLOSED: getAllOrFail() throws when the read
        // did not run, and "the read failed" is not evidence that a chain is
        // supported.
        try {
            $checkpoints = ChainCheckpointRepository::getAllOrFail();
        } catch (RepositoryReadFailure $e) {
            \BCC\Core\Log\Logger::error('[CosmwasmDiscoveryWorker] checkpoint read failed — no chain is eligible this tick', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        // The measured per-chain state, keyed for O(1) lookup. A chain with
        // NO entry here has never been measured, and the predicate is told
        // so as `null` rather than as some stand-in state — see
        // CosmwasmScanEligibility::verdict(), which lets an unmeasured
        // chain through on purpose (the first pass is what creates the
        // measurement, so refusing one would deadlock it forever).
        /** @var array<int, string> $stateByChain */
        $stateByChain = [];
        foreach ($checkpoints as $row) {
            $stateByChain[(int) $row->chain_id] = (string) $row->cw_discovery_state;
        }

        $ids = [];
        foreach (ChainRepository::getActive() as $chain) {
            // getActive() is is_active = 1 by construction.
            if ((string) ($chain->chain_type ?? '') !== 'cosmos') {
                continue;
            }

            $chainId = (int) ($chain->id ?? 0);
            if ($chainId <= 0) {
                continue;
            }

            // THE SHARED PREDICATE. Operator intent, measured capability,
            // operator pause and canary scope are all decided in one place,
            // by the same lines the admin panel reads its verdict from.
            $verdict = CosmwasmScanEligibility::verdict(
                $chainId,
                $stateByChain[$chainId] ?? null,
                self::discoveryOptInState($chain),
                $allowlist
            );
            if (!CosmwasmScanEligibility::isScannable($verdict)) {
                continue;
            }

            $ids[] = $chainId;
        }

        return $ids;
    }

    /**
     * Has an operator opted this chain in to CosmWasm NFT discovery — yes,
     * no, or "this install cannot say"?
     *
     * ── ONE READER, THREE ANSWERS ───────────────────────────────────────
     * Typed `object`, not the ChainRow shape, because the honest answer
     * depends on something the shape cannot express: whether the row was
     * projected BEFORE or AFTER the `cosmwasm_nft_discovery_enabled`
     * migration ran. A pre-migration row simply has no such property, and
     * reading it would raise a PHP warning and evaluate to null — so the
     * presence check comes first and answers `null`.
     *
     * The third answer is KEPT rather than collapsed to false here, and
     * collapsed by whoever needs a boolean:
     * {@see CosmwasmScanEligibility::verdict()} turns it into
     * {@see CosmwasmScanEligibility::UNKNOWN}, which is NOT scannable, and
     * the admin panel turns the same value into a sentence explaining that
     * the column is missing. "An operator switched this off" and "this
     * install has no such column" are different things to tell somebody,
     * and the second one sends them looking for a switch that is not there
     * yet.
     *
     * Every caller reads the column through THIS method so there is
     * exactly one place that knows how the flag is stored.
     *
     * @return bool|null null = the projection carries no such property,
     *                   i.e. the migration has not run on this install
     */
    public static function discoveryOptInState(object $chain): ?bool
    {
        $vars = get_object_vars($chain);
        if (!array_key_exists(self::DISCOVERY_FLAG_COLUMN, $vars)) {
            return null;
        }

        return (int) $vars[self::DISCOVERY_FLAG_COLUMN] === 1;
    }

    /** Chain slug, used only to resolve the operator priority hint. */
    private static function chainSlug(int $chainId): string
    {
        $chain = ChainRepository::getById($chainId);

        return $chain !== null ? (string) ($chain->slug ?? '') : '';
    }
}
