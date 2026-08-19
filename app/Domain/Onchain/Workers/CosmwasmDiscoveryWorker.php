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
 * ONE resumable historical backfill per chain, then incremental-only
 * work. Discovery is automatic; verification and provisioning stay
 * manual — nothing here sets `is_verified` or calls a provisioning
 * service.
 *
 * ── THE FOUR PASSES ─────────────────────────────────────────────────────
 *   {@see BACKFILL_HOOK} — HISTORICAL BACKFILL. Bounded, resumable, and
 *       behind its OWN explicit switch on top of the master gate. One
 *       chain slice per tick.
 *   {@see DAILY_HOOK} — INCREMENTAL. (a) newly-uploaded code ids, via an
 *       offset tail read of the code listing; (b) newly-instantiated
 *       contracts under CONFIRMED/PROBABLE families; (c) classify what
 *       is queued; (d) emit the classified CW-721s.
 *   {@see WEEKLY_HOOK} — RETRY. `temporarily_unreachable` and
 *       `inconclusive` only, under a retry cap and staged exponential
 *       backoff. CONFIRMED `not_cw721` IS NEVER RETRIED — that
 *       exclusion lives in SQL, in
 *       {@see CosmwasmCodeFamilyRepository::findPendingClassification()},
 *       so no caller can forget it.
 *   {@see METADATA_HOOK} — MIGRATION CHECK + MUTABLE METADATA, monthly.
 *       wp-cron HAS NO MONTHLY INTERVAL and we do not invent one: the
 *       hook is DAILY and a durable ≥30-day elapsed guard
 *       (`cw_metadata_refreshed_at`) is what makes the work monthly.
 *
 * Nothing routinely reprocesses a previously-inspected contract: the
 * durable inventory row IS the memory, and every work query filters on
 * it. A classifier-version bump requeues ONLY the explicitly-affected
 * classifications (never settled negatives).
 *
 * ── AND ONE NON-PASS: THE SUPERVISED ONE-SHOT ───────────────────────────
 * {@see runSupervisedSingleChainPass()} is NOT a fifth pass. It is the
 * DAILY pass ({@see dailyChainStep()}) inside the SAME per-chain envelope
 * ({@see runChainPass()}), for one chain, once, driven from WP-CLI by a
 * human who is watching. It exists because there was otherwise no way to
 * run exactly one supervised pass: defining
 * `BCC_COSMWASM_DISCOVERY_ENABLED` arms three scheduled hooks, and
 * unscheduling them does not hold — {@see register()} runs on
 * `plugins_loaded`, i.e. on every request, and re-adds anything missing.
 * It reaches NO other pass: not the backfill, not the weekly retry, not
 * the monthly metadata refresh.
 *
 * ── OPERATIONAL SHAPE (copied from {@see NftEthIndexerWorker}) ──────────
 *   - {@see CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS} wall-clock
 *     deadline that WINS over the request budget;
 *   - one chain slice per backfill tick;
 *   - request/page budget as a configurable ceiling (default 50 per
 *     invocation);
 *   - per-chain NON-BLOCKING `AdvisoryLock` in try/finally, so two
 *     overlapping invocations cannot corrupt cursor state;
 *   - durable SAFE progress written BEFORE the deadline can bite, so a
 *     cut-short tick resumes rather than restarts;
 *   - `OnchainCircuitBreaker` consulted per chain;
 *   - retry/backoff on the row, not in memory;
 *   - failure isolation — every chain runs in its own try/catch and
 *     stamps its own last-run time, so one broken chain never stops the
 *     others and never starves them;
 *   - manual pause/resume via `cw_discovery_state = paused`.
 *
 * Injective is INCLUDED, in the sense that nothing here excludes it by
 * name. Its curated Talis-whitelist path stays as-is for
 * `fetch_top_collections`, but the whole point of this feature is that
 * discovery must not be bounded by what someone already curated — a
 * whitelist is a curation, so code-id discovery runs there too, once an
 * operator opts the chain in.
 *
 * ── WHICH CHAINS ────────────────────────────────────────────────────────
 * Exactly one function RESOLVES them: {@see eligibleChainIds()}. All four
 * passes route through it. Exactly one function DECIDES per chain:
 * {@see CosmwasmScanEligibility::verdict()} — which the admin panel calls
 * as well, so the dashboard and the worker cannot answer "will this chain
 * be scanned?" differently. Both fail closed on every unsure branch.
 * `wp_bcc_chains.cosmwasm_nft_discovery_enabled` ships DEFAULT 0, so a
 * fresh install — and every install this migration lands on — scans
 * nothing until someone says otherwise, per chain.
 *
 * @phpstan-import-type CheckpointRow from ChainCheckpointRepository
 */
final class CosmwasmDiscoveryWorker
{
    public const BACKFILL_HOOK     = 'bcc_cosmwasm_backfill_tick';
    public const BACKFILL_INTERVAL = 'bcc_five_minutes';

    public const DAILY_HOOK     = 'bcc_cosmwasm_daily_discovery';
    public const DAILY_INTERVAL = 'daily';

    public const WEEKLY_HOOK     = 'bcc_cosmwasm_weekly_retry';
    public const WEEKLY_INTERVAL = 'bcc_weekly';

    /**
     * The "monthly" pass rides a DAILY hook. wp-cron has no monthly
     * interval; inventing one would put an unregistered interval in the
     * schedules filter and drift from the cron-hooks SSOT. The real
     * cadence is the durable
     * {@see CosmwasmDiscoveryGate::METADATA_REFRESH_MIN_ELAPSED} guard.
     */
    public const METADATA_HOOK     = 'bcc_cosmwasm_metadata_refresh';
    public const METADATA_INTERVAL = 'daily';

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

    /** Families migration-checked per chain per monthly pass. */
    private const MIGRATION_CHECKS_PER_PASS = 10;

    /** Rows requeued per chain when the classifier version moves. */
    private const REQUEUE_PER_PASS = 100;

    /**
     * Self-heal the cron registrations.
     *
     * Same anti-drift shape as {@see NftEthIndexerWorker::register()}: a
     * hook added by a plugin UPDATE (rather than a reactivation) would
     * otherwise never appear in `wp_options.cron` and the pass would
     * silently never run.
     *
     * REGISTRATION IS NOT PERMISSION. Scheduling a hook does not enable
     * discovery — every handler re-checks
     * {@see CosmwasmDiscoveryGate}, which fails CLOSED. A scheduled hook
     * on an environment that has not opted in is a no-op that costs one
     * function call.
     *
     * NEVER unschedules — cleanup is owned by the plugin's deactivation
     * hook, driven by includes/cron-hooks.php.
     */
    public static function register(): void
    {
        $hooks = [
            self::BACKFILL_HOOK => self::BACKFILL_INTERVAL,
            self::DAILY_HOOK    => self::DAILY_INTERVAL,
            self::WEEKLY_HOOK   => self::WEEKLY_INTERVAL,
            self::METADATA_HOOK => self::METADATA_INTERVAL,
        ];

        $offset = 120;
        foreach ($hooks as $hook => $interval) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time() + $offset, $interval, $hook);
            }
            $offset += 120;
        }
    }

    // ── Pass 1: historical backfill ─────────────────────────────────────

    /**
     * ONE chain slice of the historical backfill.
     *
     * Behind BOTH gates. One chain per tick, chosen least-recently-worked
     * first, so a chain that keeps failing cannot monopolise the ticks
     * and cannot starve the others.
     */
    public static function runBackfillTick(): void
    {
        if (!CosmwasmDiscoveryGate::backfillEnabled()) {
            return;
        }

        $chainIds = self::eligibleChainIds();
        if ($chainIds === []) {
            return;
        }

        foreach ($chainIds as $chainId) {
            ChainCheckpointRepository::ensureExists($chainId);
        }

        $chainId = ChainCheckpointRepository::nextCwDiscoveryChain($chainIds);
        if ($chainId === null) {
            return;
        }

        try {
            self::runBackfillForChain($chainId);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[CosmwasmDiscoveryWorker] backfill tick failed', [
                'chain_id' => $chainId,
                'error'    => $e->getMessage(),
            ]);
            ChainCheckpointRepository::setCwDiscoveryState(
                $chainId,
                ChainCheckpointRepository::CW_STATE_BACKFILLING,
                CosmwasmClassifier::sanitizeExcerpt($e->getMessage())
            );
        }
    }

    /**
     * Backfill one chain. Public so an admin "Run now" can drive it.
     *
     * The advisory lock is NON-BLOCKING: if another invocation (a
     * concurrent cron tick, an admin click) already holds it, this one
     * skips rather than interleaving cursor writes.
     */
    public static function runBackfillForChain(int $chainId, ?CosmwasmTickBudget $budget = null): void
    {
        if ($chainId <= 0 || !CosmwasmDiscoveryGate::backfillEnabled()) {
            return;
        }

        $lockKey = self::ADVISORY_LOCK_PREFIX . $chainId;
        if (!\BCC\Core\DB\AdvisoryLock::acquire($lockKey, 0)) {
            \BCC\Core\Log\Logger::info('[CosmwasmDiscoveryWorker] backfill skipped — concurrent run holds the lock', [
                'chain_id' => $chainId,
            ]);
            return;
        }

        try {
            self::backfillInsideLock($chainId, $budget ?? new CosmwasmTickBudget());
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lockKey);
        }
    }

    private static function backfillInsideLock(int $chainId, CosmwasmTickBudget $budget): void
    {
        $context = self::prepareChain($chainId);
        if ($context === null) {
            return;
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

                        return;
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

                        return;
                    }

                    // Merely unreachable: keep the cursor, record why,
                    // retry next tick. The state is NOT settled.
                    OnchainCircuitBreaker::recordFailure($chainId);
                    ChainCheckpointRepository::setCwDiscoveryState(
                        $chainId,
                        ChainCheckpointRepository::CW_STATE_BACKFILLING,
                        CosmwasmClassifier::sanitizeExcerpt($page['message'])
                    );

                    return;
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

                    return;
                }
                $resuming = false;

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
        self::classifyAndEnumerate($chainId, $fetcher, $budget);
    }

    // ── Pass 2: daily incremental discovery ─────────────────────────────

    /**
     * Daily: new code ids, new contracts under known CW-721 families,
     * classification of whatever is queued, and emit.
     *
     * Loops every ELIGIBLE chain ({@see eligibleChainIds()}) with per-chain
     * isolation and the shared wall-clock deadline (the
     * {@see NftEthIndexerWorker::runAllChains()} shape): a broken chain
     * records its failure and the loop moves on.
     */
    public static function runDailyDiscovery(): void
    {
        if (!CosmwasmDiscoveryGate::discoveryEnabled()) {
            return;
        }

        self::forEachChain(static function (int $chainId, CosmosFetcher $fetcher, CosmwasmTickBudget $budget): void {
            self::dailyChainStep($chainId, $fetcher, $budget);
        });
    }

    /**
     * THE CANONICAL INCREMENTAL PASS, for ONE chain.
     *
     * Extracted from {@see runDailyDiscovery()}'s closure so that exactly
     * one body of code defines what "a discovery pass" means, and both
     * callers reach it:
     *
     *   - the SCHEDULED daily hook, via {@see forEachChain()};
     *   - the SUPERVISED operator one-shot, via
     *     {@see runSupervisedSingleChainPass()}.
     *
     * There is deliberately NO second implementation for the supervised
     * path. A hand-written "just run the important bits" variant is how
     * an operator ends up watching something that is not the thing cron
     * runs, and concluding from it.
     *
     * ── EMISSION IS PART OF THIS PASS ───────────────────────────────────
     * Step (c)+(d) is {@see classifyAndEnumerate()}, which ENDS with
     * {@see CosmwasmDiscoveryService::emitCollections()}. Emission is not
     * separable from the canonical pass and no caller may ask for the
     * pass without it.
     *
     * @param ?CosmwasmPassReport $report OPTIONAL write-only telemetry.
     *                                    Null on every scheduled path.
     */
    private static function dailyChainStep(
        int $chainId,
        CosmosFetcher $fetcher,
        CosmwasmTickBudget $budget,
        ?CosmwasmPassReport $report = null
    ): void {
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

    // ── Pass 3: weekly retry ────────────────────────────────────────────

    /**
     * Weekly: retry `temporarily_unreachable` + `inconclusive` under the
     * cap and the staged backoff.
     *
     * There is no special "retry" query. The ordinary pending queries
     * ALREADY encode the whole policy — cap, backoff, exclusion of
     * settled negatives and of decided CW-721s — so this pass is the
     * same classification work on a slower cadence. One policy, one
     * place; a second retry query is exactly how the two would drift.
     */
    public static function runWeeklyRetry(): void
    {
        if (!CosmwasmDiscoveryGate::discoveryEnabled()) {
            return;
        }

        self::forEachChain(static function (int $chainId, CosmosFetcher $fetcher, CosmwasmTickBudget $budget): void {
            self::classifyAndEnumerate($chainId, $fetcher, $budget);
        });
    }

    // ── Pass 4: monthly migration + metadata ────────────────────────────

    /**
     * Monthly (daily hook + durable ≥30-day elapsed guard): re-read each
     * CW-721 family's sampled contract to notice a code MIGRATION, and
     * refresh mutable metadata.
     *
     * A migration re-points the existing inventory row. It never creates
     * a second contract row and never creates a second collection — the
     * address is the identity and its collection already exists.
     */
    public static function runMetadataRefresh(): void
    {
        if (!CosmwasmDiscoveryGate::discoveryEnabled()) {
            return;
        }

        $cutoffTs = time() - CosmwasmDiscoveryGate::METADATA_REFRESH_MIN_ELAPSED;
        $cutoff   = gmdate('Y-m-d H:i:s', $cutoffTs);

        self::forEachChain(static function (int $chainId, CosmosFetcher $fetcher, CosmwasmTickBudget $budget) use ($cutoff, $cutoffTs): void {
            $checkpoint = ChainCheckpointRepository::get($chainId);
            if ($checkpoint === null) {
                return;
            }

            // THE ELAPSED GUARD. This is what makes a daily hook monthly.
            $last = is_string($checkpoint->cw_metadata_refreshed_at)
                ? strtotime($checkpoint->cw_metadata_refreshed_at . ' UTC')
                : false;
            if ($last !== false && $last > $cutoffTs) {
                return;
            }

            $families = CosmwasmCodeFamilyRepository::findDueForMetadataCheck(
                $chainId,
                $cutoff,
                self::MIGRATION_CHECKS_PER_PASS
            );
            foreach ($families as $family) {
                if ($budget->exhausted()) {
                    break;
                }
                CosmwasmDiscoveryService::checkFamilyMigration($chainId, $fetcher, $family, $budget);
            }

            ChainCheckpointRepository::touchCwMetadataRefresh($chainId);
        });
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

        $families = CosmwasmCodeFamilyRepository::findPendingClassification(
            $chainId,
            self::FAMILIES_PER_PASS,
            CosmwasmClassifier::VERSION,
            $priority
        );
        foreach ($families as $family) {
            if ($budget->exhausted()) {
                break;
            }
            CosmwasmDiscoveryService::classifyFamily($chainId, $fetcher, $family, $budget);
            $report?->countFamilyClassified();
        }

        // Drain contract listings for families that ARE CW-721.
        $enumerable = CosmwasmCodeFamilyRepository::findEnumerable(
            $chainId,
            self::FAMILIES_ENUMERATED_PER_PASS,
            true
        );
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
        foreach ($contracts as $row) {
            if ($budget->exhausted()) {
                break;
            }
            CosmwasmDiscoveryService::classifyContract($chainId, $fetcher, $row, $budget);
            $report?->countContractClassified();
        }

        if (!$budget->exhausted()) {
            $emitted = CosmwasmDiscoveryService::emitCollections($chainId, $fetcher, $budget, self::EMIT_PER_PASS);
            $report?->addEmitted($emitted['emitted'], $emitted['denied']);
        }
    }

    /**
     * Run a callback for every eligible cosmos chain, with the shared
     * deadline, per-chain locking and failure isolation.
     *
     * Every chain gets its own advisory lock, its own try/catch, and its
     * own `cw_last_discovery_at` stamp — that stamp is what stops a
     * broken chain from being re-picked first forever and starving the
     * rest. All three live in {@see runChainPass()}, which is also what
     * the supervised one-shot calls, so the loop and the operator run
     * cannot diverge on any of them.
     *
     * @param callable(int, CosmosFetcher, CosmwasmTickBudget): void $step
     */
    private static function forEachChain(callable $step): void
    {
        $chainIds = self::eligibleChainIds();
        if ($chainIds === []) {
            return;
        }

        $budget = new CosmwasmTickBudget();

        foreach ($chainIds as $chainId) {
            if ($budget->exhausted()) {
                \BCC\Core\Log\Logger::info('[CosmwasmDiscoveryWorker] tick budget exhausted — deferring remaining chains', [
                    'spent' => $budget->spent(),
                ]);
                break;
            }

            self::runChainPass($chainId, $step, $budget);
        }
    }

    // ── The outcome of ONE chain's pass ─────────────────────────────────
    //
    // The scheduled loop ignores these — it isolates failures and moves
    // on. The SUPERVISED one-shot maps them onto process exit codes, so
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
     * of it or none of it. {@see forEachChain()} calls this in a loop;
     * {@see runSupervisedSingleChainPass()} calls it exactly once. Neither
     * of them owns a copy.
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
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────
     * There was no way to run one supervised pass. Defining
     * `BCC_COSMWASM_DISCOVERY_ENABLED` arms three scheduled hooks, and
     * unscheduling them does not hold: {@see register()} runs on
     * `plugins_loaded` — every request — and re-adds anything missing. So
     * the only ways to see a pass were "arm cron and hope" or "write a
     * second implementation", and the second one is how an operator ends
     * up watching something that is not what cron runs.
     *
     * ── WHAT IT IS NOT ──────────────────────────────────────────────────
     * It is NOT a fifth pass. It runs {@see dailyChainStep()} — the same
     * body {@see runDailyDiscovery()} runs — inside {@see runChainPass()}
     * — the same envelope {@see forEachChain()} uses. It cannot reach the
     * backfill, the weekly retry or the monthly metadata pass: those are
     * other methods and this one does not name them.
     *
     * ── AUTHORIZATION IS THE CALLER'S JOB, AND IT IS STRICTER ───────────
     * This method deliberately does NOT check
     * {@see CosmwasmDiscoveryGate::discoveryEnabled()} — bypassing that
     * one constant is the entire point of a supervised run. EVERY other
     * condition still applies and is enforced by the CLI command before it
     * gets here, via {@see isChainScannable()} — which is membership in
     * the very set the scheduled passes walk, so this path can never be
     * more permissive than cron.
     *
     * And the bypass is ONE-DIRECTIONAL: the caller runs this while the
     * constant is OFF and REFUSES while it is ON, because an armed
     * constant means the hooks above are live and a scheduled pass can
     * fire immediately before or after this one. The per-chain lock in
     * {@see runChainPass()} excludes SIMULTANEOUS execution and nothing
     * else, so it is not a substitute for that refusal.
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
     * Is this ONE chain in the set the scheduled passes would walk?
     *
     * ── MEMBERSHIP, NOT A RE-DERIVATION ─────────────────────────────────
     * Implemented as `in_array($chainId, eligibleChainIds())` on purpose.
     * The obvious alternative — ask
     * {@see CosmwasmScanEligibility::verdict()} directly with facts
     * gathered here — would be a THIRD place that knows how to resolve
     * those facts, and the two prior times this rule was maintained in two
     * places they drifted inside a fortnight. Asking the production
     * selector for the production set and testing membership cannot drift,
     * and cannot be more permissive than cron by construction.
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
     * All four passes reach it: {@see runBackfillTick()} calls it directly
     * before handing the list to
     * {@see ChainCheckpointRepository::nextCwDiscoveryChain()}, and
     * {@see runDailyDiscovery()}, {@see runWeeklyRetry()} and
     * {@see runMetadataRefresh()} all route through {@see forEachChain()},
     * which calls it. Adding a fifth pass gets the policy by construction
     * as long as it resolves its chains here. It is deliberately NOT named
     * `cosmosChainIds()` any more: a reader who believed that name would
     * conclude the returned list is "the cosmos chains", and would then
     * quite reasonably filter it further somewhere else.
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
     * A tick that scans nothing costs one cron invocation and self-heals.
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
