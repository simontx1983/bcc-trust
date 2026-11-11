<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Core\PeepSo\PeepSo;
use BCC\Core\ServiceLocator;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Repositories\SignalRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
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
     * The refresh cycle's cursor: the LAST owner (user_id) fully refreshed.
     * Persistent option, not a transient — see processBatch().
     */
    private const CURSOR_OPTION = 'bcc_onchain_refresh_cursor';

    /** The old OFFSET cursor (F-11). Deleted on the next daily run. */
    private const LEGACY_OFFSET_OPTION = 'bcc_onchain_refresh_offset';

    /** @var (\Closure(int): void)|null test-only page refresher */
    private static ?\Closure $pageRefresher = null;

    /** @var (\Closure(): int)|null test-only clock (seconds) */
    private static ?\Closure $clock = null;

    /**
     * @internal TESTS ONLY. Replaces the per-page refresh and the clock so
     * the cursor's behaviour across time-outs can be pinned without real
     * providers or real time. Production never calls this.
     *
     * @param (\Closure(int): void)|null $pageRefresher
     * @param (\Closure(): int)|null     $clock
     */
    public static function setTestSeams(?\Closure $pageRefresher, ?\Closure $clock): void
    {
        self::$pageRefresher = $pageRefresher;
        self::$clock         = $clock;
    }

    private static function now(): int
    {
        return self::$clock !== null ? (self::$clock)() : time();
    }

    /**
     * Daily cron: kick off the batch refresh cycle — or resume one.
     *
     * Instead of scheduling O(N) individual wp_cron events (which bloats
     * the serialized _cron option), the cycle keeps ONE cursor and schedules
     * a SINGLE continuation event. Each tick refreshes owners within a time
     * budget, then re-schedules itself while owners remain.
     *
     * ⚠ F-11: AN UNFINISHED CYCLE IS RESUMED, NOT RESTARTED. This used to
     * delete the cursor every day, so a cycle that did not finish within the
     * day started over at the first owner and the owners past that point
     * were never reached. A cursor present here means the previous cycle is
     * still in progress; the daily run carries on from it.
     *
     * Hooked to: bcc_onchain_daily_refresh
     */
    public static function dailyRefresh(): void
    {
        if (!\BCC\Core\DB\AdvisoryLock::acquire('bcc_onchain_daily_refresh', 0)) {
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

            // The old OFFSET cursor is meaningless under the keyset cursor.
            delete_option(self::LEGACY_OFFSET_OPTION);

            if (get_option(self::CURSOR_OPTION, null) !== null) {
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::info('[SignalRefresh] resuming unfinished refresh cycle', [
                        'after_user_id' => (int) get_option(self::CURSOR_OPTION, 0),
                    ]);
                }
            }

            self::processBatch();
        } finally {
            \BCC\Core\DB\AdvisoryLock::release('bcc_onchain_daily_refresh');
        }
    }

    /**
     * Refresh owners within the time budget, from the cursor.
     *
     * If owners remain when the budget is spent, schedules a single
     * continuation event 30 seconds in the future. This keeps the _cron
     * option at O(1) size instead of O(N).
     *
     * ⚠ F-11: KEYSET, PER-OWNER CURSOR.
     *   - Owners are read in ascending user_id AFTER the cursor (keyset), not
     *     by OFFSET over an unordered DISTINCT — which neither MySQL nor
     *     MariaDB keeps stable across ticks, so OFFSET skipped and repeated.
     *   - The cursor is the last owner FULLY refreshed and advances after
     *     EACH owner. It used to be the offset of the current page, so a
     *     time-out mid-page redid that page's owners next tick — and a tick
     *     that could not finish one page never advanced at all.
     *   - A failed read is NOT the end of the list: the cursor is kept and
     *     the next tick retries.
     *
     * The cursor is a persistent option, not a transient: a transient's TTL
     * can expire mid-cycle and lose it.
     *
     * Hooked to: bcc_onchain_refresh_batch
     */
    public static function processBatch(): void
    {
        // Acquire advisory lock to prevent overlapping batch runs from
        // concurrent continuation events.
        if (!\BCC\Core\DB\AdvisoryLock::acquire('bcc_onchain_refresh_batch', 0)) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::info('[SignalRefresh] processBatch lock held — skipping to avoid overlap');
            }
            return;
        }

        try {
            $startTime    = self::now();
            $startTimeMic = microtime(true);
            $cursor       = (int) get_option(self::CURSOR_OPTION, 0);
            $supported    = ChainSupport::supported();
            $hasMore      = false;

            do {
                if (self::isTimeBudgetExceeded($startTime)) {
                    $hasMore = true;
                    break;
                }

                try {
                    $owners = WalletRepository::getUserIdsWithChainSlugsAfter($supported, $cursor, self::BATCH_SIZE);
                } catch (RepositoryReadFailure $e) {
                    // Not "no more owners": keep the cursor, retry next tick.
                    if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                        \BCC\Core\Log\Logger::warning('[SignalRefresh] owner read failed; cycle kept, retrying next tick', [
                            'after_user_id' => $cursor,
                        ]);
                    }
                    $hasMore = true;
                    break;
                }

                if ($owners === []) {
                    break;
                }

                foreach ($owners as $ownerId) {
                    self::refreshOwner($ownerId, $startTimeMic);

                    // This owner is done: the cursor moves past it NOW, so a
                    // time-out below resumes on the next owner, not on this
                    // page's first.
                    $cursor = $ownerId;

                    // Re-check time budget after each owner (API calls are
                    // slow; the wall clock advanced during the refresh).
                    if (self::isTimeBudgetExceeded($startTime)) {
                        $hasMore = true;
                        break 2;
                    }
                }

                // A short page is the last page.
                $hasMore = count($owners) === self::BATCH_SIZE;
            } while ($hasMore);

            if ($hasMore) {
                // Save cursor and schedule continuation — single event, O(1).
                update_option(self::CURSOR_OPTION, $cursor, false);

                if (!wp_next_scheduled('bcc_onchain_refresh_batch')) {
                    \BCC\Core\Cron\AsyncDispatcher::scheduleSingle(
                        self::now() + 30,
                        'bcc_onchain_refresh_batch',
                        [],
                        'bcc-onchain'
                    );
                }
            } else {
                // Every owner refreshed — the cycle is complete.
                delete_option(self::CURSOR_OPTION);
            }
        } finally {
            \BCC\Core\DB\AdvisoryLock::release('bcc_onchain_refresh_batch');
        }
    }

    /**
     * Refresh one owner's page (or the test seam's stand-in).
     */
    private static function refreshOwner(int $ownerId, float $startTimeMic): void
    {
        if (self::$pageRefresher !== null) {
            (self::$pageRefresher)($ownerId);
            return;
        }

        $pageId = ServiceLocator::resolvePageOwnerResolver()->getPageForOwner($ownerId);
        if ($pageId) {
            self::fetchAndStoreForPage($pageId, false, $startTimeMic);
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
        return (self::now() - $startTime) >= self::BATCH_TIME_BUDGET;
    }
}
