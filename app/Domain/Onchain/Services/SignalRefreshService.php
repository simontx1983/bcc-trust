<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Core\PeepSo\PeepSo;
use BCC\Core\ServiceLocator;
use BCC\Trust\Onchain\Repositories\SignalRepository;
use BCC\Trust\Onchain\Support\ChainSupport;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetches, stores, and scores on-chain signal data for wallets.
 *
 * Handles both per-page refreshes (all wallets) and per-wallet refreshes
 * (single newly-verified wallet). Delegates bonus application to BonusService.
 */
final class SignalRefreshService
{
    /**
     * Fetch (or load from cache) on-chain signals for all wallets connected
     * to the owner of $pageId, store them, and return the rows.
     *
     * @return array<int, array<string, mixed>> Array of signal rows.
     */
    public static function fetchAndStoreForPage(int $pageId, bool $force = false, ?float $batchStartTime = null): array
    {
        $ownerId = PeepSo::get_page_owner($pageId);
        if (!$ownerId) {
            return [];
        }

        $wallets = SignalFetcher::get_connected_wallets($ownerId);
        $results = [];

        foreach ($wallets as $chain => $addresses) {
            foreach ($addresses as $address) {
                // Check time budget between wallet fetches (not just between pages).
                if ($batchStartTime !== null && (microtime(true) - $batchStartTime) >= self::BATCH_TIME_BUDGET) {
                    if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                        \BCC\Core\Log\Logger::info('[SignalRefresh] Time budget exceeded during page ' . $pageId . ', skipping remaining wallets');
                    }
                    break 2;
                }

                $cached = $force ? null : SignalRepository::get_cached($address, $chain);

                if ($cached) {
                    $results[] = $cached;
                    continue;
                }

                $signals = SignalFetcher::fetch($address, $chain, $force);
                if ($signals === null) {
                    continue;
                }

                $score = SignalScorer::score($signals);
                $row   = array_merge($signals, [
                    'user_id'            => $ownerId,
                    'wallet_address'     => $address,
                    'chain'              => $chain,
                    'score_contribution' => $score,
                ]);

                SignalRepository::upsert($row);
                $results[] = $row;
            }
        }

        if (!empty($results)) {
            // Use recomputeAndApply() which holds the per-page advisory lock,
            // preventing TOCTOU races where concurrent writes overwrite each other.
            BonusService::recomputeAndApply($pageId, $ownerId);
        }

        return $results;
    }

    /**
     * Fetch and store signals for a single wallet, then recalculate the page bonus.
     *
     * @return array<string, mixed>|null Signal row, or null on API error.
     */
    public static function fetchAndStoreWallet(int $userId, int $pageId, string $chain, string $address, bool $force = false): ?array
    {
        $cached = $force ? null : SignalRepository::get_cached($address, $chain);
        if ($cached) {
            return $cached;
        }

        $signals = SignalFetcher::fetch($address, $chain, $force);
        if ($signals === null) {
            return null;
        }

        $score = SignalScorer::score($signals);
        $row   = array_merge($signals, [
            'user_id'            => $userId,
            'wallet_address'     => $address,
            'chain'              => $chain,
            'score_contribution' => $score,
        ]);

        SignalRepository::upsert($row);

        // Recalculate total bonus from all wallets for this page.
        // Skip bonus recalculation when called without a real page (e.g. seed before page exists).
        // Use recomputeAndApply() which holds the per-page advisory lock,
        // preventing TOCTOU races where concurrent writes overwrite each other.
        if ($pageId > 0) {
            BonusService::recomputeAndApply($pageId, $userId);
        }

        return $row;
    }

    /** Max seconds a single cron batch can run before yielding. */
    private const BATCH_TIME_BUDGET = 45;

    /** Pages processed per batch iteration. */
    private const BATCH_SIZE = 20;

    /**
     * Daily cron: kick off the batch refresh cycle.
     *
     * Instead of scheduling O(N) individual wp_cron events (which bloats
     * the serialized _cron option), we store the batch offset in a
     * transient and schedule a SINGLE continuation event. Each tick
     * processes BATCH_SIZE pages within a time budget, then re-schedules
     * itself if more pages remain.
     *
     * Hooked to: bcc_onchain_daily_refresh
     */
    public static function dailyRefresh(): void
    {
        if (!\BCC\Trust\Onchain\Repositories\LockRepository::acquire('bcc_onchain_daily_refresh', 0)) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-onchain-signals] daily_refresh_lock_held', [
                    'message' => 'Could not acquire advisory lock — previous run may still be active.',
                ]);
            }
            return;
        }

        try {
            // Process any pending bonus retries first.
            BonusRetryService::processAll();

            // Reset the batch cursor and start processing.
            delete_option('bcc_onchain_refresh_offset');
            self::processBatch();
        } finally {
            \BCC\Trust\Onchain\Repositories\LockRepository::release('bcc_onchain_daily_refresh');
        }
    }

    /**
     * Process a batch of pages within the time budget.
     *
     * If more pages remain after the budget is exhausted, schedules a
     * single continuation event 30 seconds in the future. This keeps
     * the _cron option at O(1) size instead of O(N).
     *
     * Hooked to: bcc_onchain_refresh_batch
     */
    public static function processBatch(): void
    {
        // Acquire advisory lock to prevent overlapping batch runs from
        // concurrent continuation events.
        if (!\BCC\Trust\Onchain\Repositories\LockRepository::acquire('bcc_onchain_refresh_batch', 0)) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::info('[SignalRefresh] processBatch lock held — skipping to avoid overlap');
            }
            return;
        }

        try {
        $startTime    = time();
        $startTimeMic = microtime(true);
        // Use wp_option (persistent) instead of transient for the batch
        // cursor. Transients have a TTL that can expire mid-batch if the
        // full refresh takes longer than HOUR_IN_SECONDS, losing the cursor
        // and causing pages to be skipped or re-processed.
        $offset    = (int) get_option('bcc_onchain_refresh_offset', 0);

        $walletService = ServiceLocator::resolveWalletLinkRead();
        $supported     = ChainSupport::supported();
        $hasMore       = false;

        do {
            // Time budget check — yield to avoid PHP timeout.
            if ((time() - $startTime) >= self::BATCH_TIME_BUDGET) {
                $hasMore = true;
                break;
            }

            $owners = $walletService->getUserIdsWithLinks($supported, self::BATCH_SIZE, $offset);

            if (empty($owners)) {
                break;
            }

            foreach ($owners as $ownerId) {
                $pageId = ServiceLocator::resolvePageOwnerResolver()->getPageForOwner($ownerId);

                if ($pageId) {
                    self::fetchAndStoreForPage($pageId, false, $startTimeMic);
                }

                // Re-check time budget after each page (API calls are slow).
                // Wall clock advances during fetchAndStoreForPage() — re-evaluate.
                if (self::isTimeBudgetExceeded($startTime)) {
                    $hasMore = true;
                    break 2;
                }
            }

            $offset += self::BATCH_SIZE;

            // If we got a full batch, there may be more.
            if (count($owners) === self::BATCH_SIZE) {
                $hasMore = true;
            } else {
                $hasMore = false;
            }
        } while (true);

        if ($hasMore) {
            // Save cursor and schedule continuation — single event, O(1).
            update_option('bcc_onchain_refresh_offset', $offset, false);

            if (!wp_next_scheduled('bcc_onchain_refresh_batch')) {
                \BCC\Core\Cron\AsyncDispatcher::scheduleSingle(
                    time() + 30,
                    'bcc_onchain_refresh_batch',
                    [],
                    'bcc-onchain'
                );
            }
        } else {
            // All pages processed — clean up cursor.
            delete_option('bcc_onchain_refresh_offset');
        }

        } finally {
            \BCC\Trust\Onchain\Repositories\LockRepository::release('bcc_onchain_refresh_batch');
        }
    }

    /**
     * Per-page cron: refresh signals for a single page.
     *
     * Hooked to: bcc_onchain_refresh_page
     */
    public static function refreshPage(int $pageId): void
    {
        self::fetchAndStoreForPage($pageId, false);
    }

    /**
     * Check whether the batch time budget has been exceeded.
     *
     * Extracted to a method so PHPStan doesn't narrow time() across
     * function calls that perform blocking I/O.
     */
    private static function isTimeBudgetExceeded(int $startTime): bool
    {
        return (time() - $startTime) >= self::BATCH_TIME_BUDGET;
    }
}
