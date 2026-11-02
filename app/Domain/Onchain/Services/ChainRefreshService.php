<?php

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use BCC\Trust\Onchain\Services\CollectionService;
use BCC\Trust\Onchain\Services\ValidatorPageMinter;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\Support\ProviderOutcomeReceipt;

/**
 * Chain Refresh Cron
 *
 * Separate cron jobs per data type, each with its own interval.
 *
 * Jobs:
 *  - bcc_refresh_validators   (every 1 hour)
 *  - bcc_index_validators     (every 4 hours)
 *  - bcc_refresh_collections  (every 4 hours)
 *
 * COLLECTION DISCOVERY IS NOT HERE. `bcc_index_collections` used to sweep
 * every active EVM/Solana/Cosmos chain for "top collections" on a 4-hour
 * cadence; it is retired. Chain-wide NFT collection discovery is now
 * operator-initiated, one named chain at a time. What survives on this
 * class is validator indexing and refresh, plus the WALLET-LINKED half of
 * the collection refresh — a wallet whose owner verified it is not a
 * chain-wide scan.
 */
class ChainRefreshService
{
    const BATCH_SIZE = 50;

    /**
     * Register cron hooks.
     */
    public static function init(): void
    {
        add_filter('cron_schedules', [__CLASS__, 'add_cron_intervals']);

        add_action('bcc_refresh_validators',  [__CLASS__, 'refresh_validators']);
        add_action('bcc_refresh_collections', [__CLASS__, 'refresh_collections']);
        add_action('bcc_index_validators',   [__CLASS__, 'index_validators']);

        // Schedule crons directly — init() is already called during plugins_loaded,
        // so hooking plugins_loaded again would never fire.
        // wp_next_scheduled() prevents double-scheduling on every request.
        self::schedule_crons();
    }

    /**
     * Register custom cron intervals.
     *
     * Idempotent: only adds `every_4_hours` when no other plugin has
     * already defined it. Callers at plugin-load time, activation, and
     * init all converge on the same schedule table without clobbering.
     */
    /** @param array<string, array{interval: int, display: string}> $schedules
     *  @return array<string, array{interval: int, display: string}>
     */
    public static function add_cron_intervals(array $schedules): array
    {
        if (!isset($schedules['every_4_hours'])) {
            $schedules['every_4_hours'] = [
                'interval' => 4 * HOUR_IN_SECONDS,
                'display'  => 'Every 4 Hours',
            ];
        }
        return $schedules;
    }

    /**
     * Schedule recurring cron jobs.
     */
    public static function schedule_crons(): void
    {
        $jobs = [
            'bcc_refresh_validators'  => 'hourly',
            'bcc_refresh_collections' => 'every_4_hours',
            'bcc_index_validators'    => 'every_4_hours',
        ];

        foreach ($jobs as $hook => $interval) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time(), $interval, $hook);
            }
        }
    }

    /**
     * Clear all cron jobs on deactivation.
     */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('bcc_refresh_validators');
        wp_clear_scheduled_hook('bcc_refresh_collections');
        wp_clear_scheduled_hook('bcc_index_validators');
    }

    // ── Locking ──────────────────────────────────────────────────────────────

    private const LOCK_GROUP = 'bcc_cron';

    /**
     * Acquire a MySQL advisory lock for a cron job.
     * Advisory locks are cross-request and don't require persistent object cache.
     */
    /**
     * One run's TOTAL scan budget for validator placeholder minting,
     * shared across every healthy chain.
     *
     * ⚠ ValidatorPageMinter::MAX_PER_INVOCATION is clamped PER CALL, so
     * dispatching per chain without a shared budget would permit 500 x N
     * pages in one run. This is the run-wide ceiling.
     */
    private const MINT_SCAN_BUDGET = 500;

    private static function acquireLock(string $job): bool
    {
        $acquired = \BCC\Core\DB\AdvisoryLock::acquire(self::LOCK_GROUP . ':' . $job, 0);

        if (!$acquired) {
            \BCC\Core\Log\Logger::info('[Onchain] Skipping ' . $job . ' — previous run still locked.');
            return false;
        }

        return true;
    }

    private static function releaseLock(string $job): void
    {
        \BCC\Core\DB\AdvisoryLock::release(self::LOCK_GROUP . ':' . $job);
    }

    // ── Validator Indexing (bulk — all validators per chain) ────────────────

    /**
     * Fetch and store ALL active validators for every Cosmos chain.
     *
     * This is the discovery/seeding path — populates the validators table
     * with the full active set so the discovery UI and claim system have
     * data to display. Runs every 4 hours.
     *
     * The per-validator refresh cron (refresh_validators) handles expensive
     * enrichment (uptime, governance, delegations) on expired rows.
     */
    public static function index_validators(): void
    {
        if (!self::acquireLock('index_validators')) {
            return;
        }

        try {
            // Index all chain types that support validators.
            $chains = array_merge(
                ChainRepository::getActive('cosmos'),
                ChainRepository::getActive('thorchain'),
                ChainRepository::getActive('solana'),
                ChainRepository::getActive('polkadot'),
                ChainRepository::getActive('near')
            );

            $hasPartialFetch = false;

            // ⚠ PER-CHAIN, DELIBERATELY SEPARATE FROM $hasPartialFetch.
            // $hasPartialFetch is run-wide and stays that way, because
            // markDeadValidators() is a GLOBAL UPDATE with no chain_id
            // predicate — one truncated chain can make another chain's live
            // validators look absent, so that gate must see the whole run.
            // Page creation is the opposite shape: it acts on PRESENCE, so a
            // truncated fetch elsewhere can only mint a page LATER, never
            // wrongly. Hence a per-chain list for minting only.
            $healthyChainFetches = [];

            foreach ($chains as $chain) {
                $chainId = (int) $chain->id;

                // Skip chains whose circuit breaker is open (consistently failing).
                //
                // ⚠ NON-MUTATING. The driver and capability gates below can
                // `continue` without contacting anything, so this must not
                // consume the half-open recovery probe — see issue #264.
                if (OnchainCircuitBreaker::isResting($chainId)) {
                    \BCC\Core\Log\Logger::info('[Onchain] Skipping index for ' . $chain->name . ' — circuit breaker open');
                    continue;
                }

                // ⚠ ONE RECEIPT PER CHAIN, CONSTRUCTED INSIDE THE LOOP AND
                // ABOVE THE try. Inside the loop so the next chain cannot
                // inherit this one's outcome; above the try so the catch
                // below can always read it, including when the throw happened
                // before the fetch.
                $indexOutcome = new ProviderOutcomeReceipt();

                try {
                    if (!FetcherFactory::has_driver($chain->chain_type)) {
                        continue;
                    }

                    $fetcher = FetcherFactory::make_for_chain($chain);

                    if (!$fetcher->supports_feature('validator')) {
                        continue;
                    }

                    $validators = $fetcher->fetch_all_validators($indexOutcome);

                    if (!empty($validators)) {
                        $returnedCount = count($validators);

                        // Detect partial fetch: if we got significantly fewer
                        // validators than previously known for this chain, the
                        // RPC likely returned a truncated result (timeout, pagination).
                        // In that case, do NOT treat missing validators as gone.
                        $chainCounts = ValidatorRepository::getCountsByChain();
                        $knownCount  = isset($chainCounts[$chainId]) ? (int) $chainCounts[$chainId]->cnt : 0;
                        // Three detection rules (any triggers):
                        // 1. Relative: got < 70% of known validators (gradual truncation)
                        // 2. Absolute floor: known > 200 but got < 50 (catastrophic truncation)
                        // 3. First-run floor: no baseline yet and got < 30 — most
                        //    bonded sets have >= 30 validators, so this is almost
                        //    always a truncated first index, not a real small set.
                        //    Marks the run as partial so markDeadValidators is
                        //    deferred until a subsequent run establishes a baseline.
                        $isPartialFetch = ($knownCount > 10 && $returnedCount < (int) ($knownCount * 0.7))
                            || ($knownCount > 200 && $returnedCount < 50)
                            || ($knownCount === 0 && $returnedCount > 0 && $returnedCount < 30);

                        if ($isPartialFetch) {
                            \BCC\Core\Log\Logger::warning(sprintf(
                                '[Onchain] Partial fetch detected for %s: got %d, expected ~%d. Skipping dead-validator marking for this chain.',
                                $chain->name, $returnedCount, $knownCount
                            ));
                            $hasPartialFetch = true;
                        }

                        $stats = ValidatorRepository::bulkUpsert($validators, 4 * HOUR_IN_SECONDS);

                        // Persist per-chain stats for the admin dashboard.
                        $allStats = get_option('bcc_onchain_indexer_stats', []);
                        $allStats[$chain->slug] = array_merge($stats, [
                            'chain'     => $chain->name,
                            'timestamp' => current_time('mysql', true),
                            'partial'   => $isPartialFetch,
                        ]);
                        update_option('bcc_onchain_indexer_stats', $allStats, false);

                        \BCC\Core\Log\Logger::info(sprintf(
                            '[Onchain] Indexed %s: %d total, %d new, %d updated, %d unchanged, %d refreshed%s',
                            $chain->name, $stats['total'], $stats['new'], $stats['updated'],
                            $stats['unchanged'], $stats['refreshed'],
                            $isPartialFetch ? ' (PARTIAL)' : ''
                        ));

                        if (!$isPartialFetch) {
                            OnchainCircuitBreaker::recordSuccess($chainId);

                            // ⚠ THE ONLY PLACE A CHAIN IS DECLARED HEALTHY, and it is
                            // the site that already proves it this run. Anything that
                            // `continue`s earlier (breaker resting, no driver, no
                            // validator feature), returned empty, or threw never
                            // reaches here — so it is absent from the list and FAILS
                            // CLOSED by construction rather than by a check.
                            //
                            // ⛔ Never source this from bcc_onchain_indexer_stats:
                            // that option PERSISTS ACROSS RUNS and is months stale for
                            // several chains, so it would report a chain last indexed
                            // in June as healthy.
                            // ⚠ AN EXACT ALLOWLIST, NOT JUST THE CHAIN ID. A healthy
                            // chain proves the FETCH was good; it does not prove any
                            // particular stored row is still in the set. Eligibility
                            // reads `wallet_link_id IS NULL` + no page meta and never
                            // consults currency, and `status` cannot stand in for it:
                            // measured 2026-11-02, 149 of 693 staging candidates were
                            // absent from their chain's latest complete fetch and all
                            // 149 still read 'active', 56 unseen for over 90 days.
                            //
                            // `fetched_at` cannot answer it either — bulkUpsert only
                            // refreshes it when a row is new, changed, or already >6h
                            // stale, so a row present in this fetch may carry a stamp
                            // 6h old and be indistinguishable from one that was absent.
                            //
                            // So the allowlist is built from the addresses THIS fetch
                            // actually returned. An empty list means mint none.
                            $fetchedAddresses = [];
                            foreach ($validators as $fetched) {
                                $address = is_array($fetched)
                                    ? (string) ($fetched['operator_address'] ?? '')
                                    : (string) ($fetched->operator_address ?? '');
                                if ($address !== '') {
                                    $fetchedAddresses[] = $address;
                                }
                            }

                            $healthyChainFetches[$chainId] = ValidatorRepository::idsForOperatorAddresses(
                                $chainId,
                                $fetchedAddresses
                            );
                        }
                    } else {
                        // Empty result from an active chain is suspicious
                        $hasPartialFetch = true;

                        // ⚠ EMPTY IS TWO DIFFERENT FACTS, AND ONLY ONE OF THEM
                        // IS THIS VERDICT'S TO CHARGE. If the index came back
                        // empty because the provider failed, ApiRetry already
                        // charged that request once and this would be the same
                        // failure counted twice. If the provider ANSWERED and
                        // the answer was an empty validator set — a 200 with no
                        // rows, a cached-empty set, a driver that never called
                        // out — nothing was charged, the answer is unusable, and
                        // this verdict is correctly the operation's one charge.
                        if ($indexOutcome->domainMayCharge()) {
                            OnchainCircuitBreaker::recordFailure($chainId);
                        }
                        \BCC\Core\Log\Logger::warning('[Onchain] Validator index returned empty for ' . $chain->name);
                    }
                } catch (\Exception $e) {
                    $hasPartialFetch = true;

                    // ⚠ THIS CATCH SPANS MORE THAN ONE PROVIDER REQUEST — the
                    // whole index for this chain, plus the bulk upsert and the
                    // option writes after it. Most throws reaching here are
                    // therefore NOT provider faults at all, and the ones that
                    // are have already been charged once by ApiRetry. The
                    // receipt keeps this verdict from becoming that failure's
                    // second charge; a throw after a healthy request, or before
                    // any request, still charges exactly once.
                    if ($indexOutcome->domainMayCharge()) {
                        OnchainCircuitBreaker::recordFailure($chainId);
                    }
                    \BCC\Core\Log\Logger::error('[Onchain] Validator index failed for ' . $chain->name . ': ' . $e->getMessage());
                }
            }

            // After indexing, clean up validators that the indexer hasn't seen
            // in 30+ days and have exhausted retry attempts — they're gone.
            // Skip if ANY chain had a partial/failed fetch to prevent marking
            // validators dead due to transient RPC issues.
            if (!$hasPartialFetch) {
                EnrichmentScheduler::markDeadValidators();
            } else {
                \BCC\Core\Log\Logger::info('[Onchain] Skipped markDeadValidators — partial fetch detected in this run.');
            }

            // Mint placeholder peepso-page posts for any newly-indexed
            // validators that don't have one yet, so they surface in
            // /directory?kind=validator with the WANTED claim CTA.
            // Idempotent — re-running is a no-op once the placeholders exist.
            //
            // ⚠ SCOPED TO CHAINS PROVEN HEALTHY IN **THIS** RUN, not gated on
            // $hasPartialFetch. Previously one unhealthy chain blocked page
            // creation for all fourteen, which is why 228 validators across 9
            // chains had no page while only 5 chains were actually degraded.
            // A truncated fetch cannot invent a validator, so minting for a
            // validator that demonstrably EXISTS is safe regardless of another
            // chain's health; the worst case is that a page appears a run late.
            //
            // ⛔ markDeadValidators() above is UNCHANGED and still gated on the
            // run-wide $hasPartialFetch. It acts on ABSENCE and its UPDATE has
            // no chain_id predicate, so it must keep seeing the whole run.
            if ($healthyChainFetches !== []) {
                try {
                    $mintResult = self::mintForHealthyChains($healthyChainFetches, self::MINT_SCAN_BUDGET);
                    if ($mintResult['minted'] > 0 || $mintResult['errors'] > 0) {
                        \BCC\Core\Log\Logger::info(sprintf(
                            '[Onchain] Validator placeholder pages — chains=%d, scanned=%d, minted=%d, errors=%d',
                            $mintResult['chains'],
                            $mintResult['scanned'],
                            $mintResult['minted'],
                            $mintResult['errors']
                        ));
                    }
                } catch (\Throwable $e) {
                    \BCC\Core\Log\Logger::error('[Onchain] ValidatorPageMinter::mintMissing failed: ' . $e->getMessage());
                }
            }
        } finally {
            self::releaseLock('index_validators');
        }
    }

    // ── Validator Refresh (scheduler-driven) ─────────────────────────────────

    /**
     * Enrich validators via the EnrichmentScheduler.
     *
     * The scheduler handles: priority ordering, API budget control,
     * retry/backoff, staggered scheduling, and Redis-based overlap prevention.
     * This method is just the cron entry point.
     */
    /**
     * Mint placeholder pages for the given chains under ONE shared budget.
     *
     * ⚠ The budget is decremented by SCANNED, not minted. `scanned` is what
     * bounds the work: a chain whose rows all already have pages reports
     * minted=0 while still scanning, so decrementing by `minted` would let it
     * burn unbounded scans and starve the chains after it.
     *
     * ⚠ Each chain is passed its OWN id. Never null — null means every chain,
     * which is exactly the all-or-nothing behaviour this replaces.
     *
     * $minter exists so the budget arithmetic is testable without a database;
     * production always uses the default.
     *
     * ⚠ Keyed by chain id, VALUE is that chain's exact allowlist of validator
     * ids from this run's fetch. An empty allowlist is forwarded as `[]`, which
     * mintMissing() treats as MINT NONE — never as 'no filter'.
     *
     * @param array<int, list<int>> $chainFetches
     * @param (callable(int, int, list<int>): array{scanned:int,minted:int,skipped:int,errors:int})|null $minter
     * @return array{chains:int,scanned:int,minted:int,skipped:int,errors:int}
     */
    private static function mintForHealthyChains(
        array $chainFetches,
        int $budget,
        ?callable $minter = null
    ): array {
        // ⚠ Re-keyed to a true list of ints before it crosses the boundary.
        // mintMissing() distinguishes [] from null, so the shape matters: a
        // gappy or string-keyed array would still be non-empty and would skip
        // the fail-closed branch while composing a malformed IN list.
        $minter ??= static function (int $chainId, int $max, array $allowlist): array {
            /** @var list<int> $ids */
            $ids = array_values(array_map(static fn ($id): int => (int) $id, $allowlist));

            return ValidatorPageMinter::mintMissing($chainId, false, $max, $ids);
        };

        $totals = ['chains' => 0, 'scanned' => 0, 'minted' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($chainFetches as $chainId => $allowlist) {
            if ($budget <= 0) {
                break;
            }

            $result = $minter((int) $chainId, $budget, array_values((array) $allowlist));

            $totals['chains']++;
            $totals['scanned'] += (int) ($result['scanned'] ?? 0);
            $totals['minted']  += (int) ($result['minted'] ?? 0);
            $totals['skipped'] += (int) ($result['skipped'] ?? 0);
            $totals['errors']  += (int) ($result['errors'] ?? 0);

            // ⚠ A minter that reports scanning nothing must still not loop
            // forever across chains; the foreach bounds that. But guard the
            // budget against a negative drift so `<= 0` stays meaningful.
            $budget -= max(0, (int) ($result['scanned'] ?? 0));
        }

        return $totals;
    }

    public static function refresh_validators(): void
    {
        $stats = EnrichmentScheduler::run();

        // Persist enrichment stats for the admin dashboard.
        update_option('bcc_onchain_enrichment_stats', array_merge($stats, [
            'timestamp' => current_time('mysql', true),
        ]), false);
    }

    // ── Collection Refresh (WALLET-LINKED ONLY) ─────────────────────────────

    /**
     * Refresh the collections a VERIFIED WALLET holds.
     *
     * This is wallet-driven, not chain-wide: every row it touches is
     * anchored to a `wallet_link_id`, so the work is bounded by how many
     * wallets members have linked rather than by how large a chain is.
     * {@see CollectionRepository::getExpiredWalletLinked()} enforces that at the query,
     * so a chain-level row cannot reach this loop at all.
     *
     * The chain-level branch that used to live here called
     * {@see CollectionRepository::backoffRow()} on rows with no wallet,
     * purely to push them out of the expired queue until the next
     * `bcc_index_collections` sweep re-fetched them. That sweep is retired,
     * so there is nothing to defer to and nothing to defer — the rows are
     * excluded upstream instead of being re-dated on every cycle.
     */
    public static function refresh_collections(): void
    {
        if (!self::acquireLock('refresh_collections')) {
            return;
        }

        try {
            $expired = CollectionRepository::getExpiredWalletLinked(self::BATCH_SIZE);

            if (empty($expired)) {
                return;
            }

            foreach ($expired as $row) {
                try {
                    $chain = ChainRepository::getById((int) $row->chain_id);
                    if (!$chain || !FetcherFactory::has_driver($chain->chain_type)) {
                        continue;
                    }

                    $fetcher = FetcherFactory::make_for_chain($chain);

                    if (!$fetcher->supports_feature('collection')) {
                        // The chain has no wallet-discovery driver — since
                        // PR 7.12 that includes every Cosmos chain, whose
                        // only driver was the Stargaze marketplace.
                        //
                        // ⚠ BACK THE ROW OFF, don't just `continue`. The
                        // batch is selected by `expires_at < NOW()` ordered
                        // oldest-first with LIMIT 50 and is chain-agnostic,
                        // so a bare skip leaves these rows permanently at
                        // the head of the queue and they crowd out the EVM
                        // and Solana rows this cron exists to refresh.
                        // Backing off moves the watermark WITHOUT claiming a
                        // refresh happened — `markHoldingsRefreshed()` is
                        // deliberately not called here.
                        CollectionRepository::backoffRow((int) $row->id);
                        continue;
                    }

                    // Resolve the wallet address. getExpiredWalletLinked()
                    // guarantees wallet_link_id is set; a missing wallet row
                    // is a dangling reference, not a chain-level collection.
                    $wallet = WalletRepository::getById((int) $row->wallet_link_id);
                    if (!$wallet) {
                        continue;
                    }

                    $collections = $fetcher->fetch_collections($wallet->wallet_address, (int) $row->chain_id);

                    if (!empty($collections)) {
                        // #212: never discard the result. A failure here is a
                        // genuine write loss, not a transient — it must not
                        // stay invisible the way the old bare INSERT did.
                        $persisted = CollectionPersistBatch::persist(
                            $collections,
                            (int) $row->wallet_link_id,
                            4 * HOUR_IN_SECONDS
                        );
                        if (!CollectionPersistBatch::allPersisted($persisted)) {
                            \BCC\Core\Log\Logger::warning(sprintf(
                                '[Onchain] %d of %d collections failed to persist for wallet_link_id %d on chain %d',
                                $persisted['failed'],
                                $persisted['total'],
                                (int) $row->wallet_link_id,
                                (int) $row->chain_id
                            ));
                        }

                        if ((int) $wallet->post_id > 0) {
                            CollectionService::invalidate((int) $wallet->post_id);
                        }
                    } else {
                        // Empty response after retries — backoff to prevent tight re-fetch
                        // loop on wallets with deleted collections or failing chain APIs.
                        CollectionRepository::backoffRow((int) $row->id);
                    }
                } catch (\Exception $e) {
                    OnchainCircuitBreaker::recordFailure((int) $row->chain_id);
                    \BCC\Core\Log\Logger::error('[Onchain] Collection ' . $row->contract_address . ' refresh failed: ' . $e->getMessage());
                    CollectionRepository::backoffRow((int) $row->id);
                }
            }
        } finally {
            self::releaseLock('refresh_collections');
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

}
