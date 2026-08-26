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

            foreach ($chains as $chain) {
                $chainId = (int) $chain->id;

                // Skip chains whose circuit breaker is open (consistently failing).
                if (OnchainCircuitBreaker::isOpen($chainId)) {
                    \BCC\Core\Log\Logger::info('[Onchain] Skipping index for ' . $chain->name . ' — circuit breaker open');
                    continue;
                }

                try {
                    if (!FetcherFactory::has_driver($chain->chain_type)) {
                        continue;
                    }

                    $fetcher = FetcherFactory::make_for_chain($chain);

                    if (!$fetcher->supports_feature('validator')) {
                        continue;
                    }

                    $validators = $fetcher->fetch_all_validators();

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
                        }
                    } else {
                        // Empty result from an active chain is suspicious
                        $hasPartialFetch = true;
                        OnchainCircuitBreaker::recordFailure($chainId);
                        \BCC\Core\Log\Logger::warning('[Onchain] Validator index returned empty for ' . $chain->name);
                    }
                } catch (\Exception $e) {
                    $hasPartialFetch = true;
                    OnchainCircuitBreaker::recordFailure($chainId);
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
            // Capped per invocation and idempotent — re-running is a no-op
            // once the placeholders exist. Skipped on partial fetch to
            // avoid minting pages for a chain whose indexer just hiccuped.
            if (!$hasPartialFetch) {
                try {
                    $mintResult = ValidatorPageMinter::mintMissing(null, false);
                    if ($mintResult['minted'] > 0 || $mintResult['errors'] > 0) {
                        \BCC\Core\Log\Logger::info(sprintf(
                            '[Onchain] Validator placeholder pages — scanned=%d, minted=%d, errors=%d',
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
