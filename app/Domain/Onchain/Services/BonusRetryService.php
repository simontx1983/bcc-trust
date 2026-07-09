<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\SignalRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the retry queue for failed trust-score bonus applications.
 *
 * Stores pending retries in wp_options (auto-loads false).
 * The retry cron processes the queue idempotently: recalculates
 * the bonus from stored signals so stale values are impossible.
 */
final class BonusRetryService
{
    private const OPTION_KEY    = 'bcc_onchain_pending_bonus';
    private const LOCK_KEY      = 'bcc_bonus_retry';
    private const MAX_ATTEMPTS       = 20;
    private const QUARANTINE_KEY     = 'bcc_onchain_quarantined_bonus';

    /**
     * Queue a failed bonus application for retry.
     *
     * Uses a MySQL advisory lock to prevent concurrent read-modify-write races.
     */
    public static function queue(int $pageId, float $bonus): void
    {
        if (!\BCC\Core\DB\AdvisoryLock::acquire(self::LOCK_KEY, 5)) {
            return; // Could not acquire lock — will be picked up by retry cron.
        }

        try {
            /** @var array<int, array{bonus: float, queued_at: int, attempts: int}> $pending */
            $pending = get_option(self::OPTION_KEY, []);
            $pending[$pageId] = [
                'bonus'     => $bonus,
                'queued_at' => time(),
                'attempts'  => $pending[$pageId]['attempts'] ?? 0,
            ];
            update_option(self::OPTION_KEY, $pending, false);

            if (class_exists('BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-onchain-signals] bonus_queued_for_retry', [
                    'page_id'  => $pageId,
                    'bonus'    => $bonus,
                    'attempts' => $pending[$pageId]['attempts'],
                ]);
            }
        } finally {
            \BCC\Core\DB\AdvisoryLock::release(self::LOCK_KEY);
        }
    }

    /**
     * Clear a page from the pending retry queue (called on success).
     *
     * Uses a MySQL advisory lock to prevent concurrent read-modify-write races.
     */
    public static function clear(int $pageId): void
    {
        if (!\BCC\Core\DB\AdvisoryLock::acquire(self::LOCK_KEY, 5)) {
            return;
        }

        try {
            $pending = get_option(self::OPTION_KEY, []);
            if (isset($pending[$pageId])) {
                unset($pending[$pageId]);
                update_option(self::OPTION_KEY, $pending, false);
            }
        } finally {
            \BCC\Core\DB\AdvisoryLock::release(self::LOCK_KEY);
        }
    }

    /**
     * Process all pending bonus retries.
     *
     * Idempotent: recalculates from stored signals + claims (source of truth).
     * Protected by advisory lock to prevent concurrent cron overlap.
     *
     * The lock is released before calling applyBonus() to avoid blocking
     * concurrent queue() calls from other processes. The pending list is
     * snapshotted under the lock, processed outside it, then results are
     * written back under a re-acquired lock.
     */
    private const PROCESS_LOCK_KEY = 'bcc_bonus_retry_process';

    public static function processAll(): void
    {
        // Exclusive processing lock — prevents concurrent processAll() calls
        // from applying the same bonuses twice. Separate from LOCK_KEY which
        // protects the queue option read/write.
        if (!\BCC\Core\DB\AdvisoryLock::acquire(self::PROCESS_LOCK_KEY, 0)) {
            return; // Another processAll() is already running.
        }

        try {

        // ── Step 1: snapshot pending entries under queue lock ────────────
        if (!\BCC\Core\DB\AdvisoryLock::acquire(self::LOCK_KEY, 5)) {
            return;
        }

        $pending = get_option(self::OPTION_KEY, []);
        \BCC\Core\DB\AdvisoryLock::release(self::LOCK_KEY);

        if (empty($pending)) {
            return;
        }

        if (!class_exists('\\BCC\\Core\\ServiceLocator')) {
            return;
        }

        if (!\BCC\Core\ServiceLocator::hasRealService(\BCC\Core\Contracts\ScoreContributorInterface::class)) {
            return; // Trust engine not active — retry next cycle without burning attempts
        }

        $contributor = \BCC\Core\ServiceLocator::resolveScoreContributor();

        // ── Step 2: process each entry WITHOUT holding the lock ──────────
        // This allows concurrent queue() calls to succeed.
        $succeeded = [];
        $failed    = [];
        $exhausted = [];

        foreach ($pending as $pageId => $entry) {
            if (($entry['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
                if (class_exists('BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-onchain-signals] bonus_retry_exhausted_quarantined', [
                        'page_id'  => $pageId,
                        'attempts' => $entry['attempts'],
                    ]);
                }
                // Quarantine instead of silently dropping. Admin can inspect
                // and manually retry via wp_options → bcc_onchain_quarantined_bonus.
                self::quarantine($pageId, $entry);
                $exhausted[] = $pageId;
                continue;
            }

            // Serialize with live recomputeAndApply() — that path acquires
            // the same bcc_onchain_bonus_<pageId> advisory lock around its
            // read+compute+apply sequence. Without this, a retry iteration
            // that read signals at T0 could write an older snapshot AFTER
            // a concurrent live recompute at T1 wrote fresher data,
            // regressing onchain_bonus until the next trigger. On lock
            // conflict we defer to the live path: skip this page this
            // cycle (no attempts bump) — the queue entry stays put and we
            // retry next tick when contention has cleared.
            $pageIdInt = (int) $pageId;
            if (!\BCC\Core\DB\AdvisoryLock::acquire('bcc_onchain_bonus_' . $pageIdInt, 5)) {
                continue;
            }

            try {
                // Recalculate from signals + claims (full bonus, not signals only).
                $signalBonus = array_sum(array_column(
                    SignalRepository::get_for_page($pageIdInt),
                    'score_contribution'
                ));

                $ownerId = \BCC\Core\PeepSo\PeepSo::get_page_owner($pageIdInt);
                $claimBonus = 0.0;
                if ($ownerId) {
                    $claimBonus = \BCC\Trust\Onchain\Repositories\ClaimRepository::computePageClaimBonus($ownerId);
                }

                $totalBonus = min($signalBonus + $claimBonus, BCC_ONCHAIN_MAX_TOTAL_BONUS);
                $applied    = $contributor->applyBonus($pageIdInt, 'onchain', $totalBonus);

                if ($applied) {
                    $succeeded[] = $pageId;
                } else {
                    $failed[$pageId] = ($entry['attempts'] ?? 0) + 1;
                }
            } finally {
                \BCC\Core\DB\AdvisoryLock::release('bcc_onchain_bonus_' . $pageIdInt);
            }
        }

        // ── Step 3: write results back under lock ───────────────────────
        $lockAcquired = \BCC\Core\DB\AdvisoryLock::acquire(self::LOCK_KEY, 5);
        if (!$lockAcquired) {
            return; // Could not re-acquire — results will be picked up next cycle.
        }

        try {
            // Re-read option to merge with any entries added while we were processing.
            $current = get_option(self::OPTION_KEY, []);

            foreach ($succeeded as $pageId) {
                // Only unset if the entry still references the same snapshot
                // we just processed. A queue() call between snapshot and
                // write-back replaces the entry with fresh data; keep it.
                $snapshot = $pending[$pageId] ?? null;
                if (isset($current[$pageId])
                    && $snapshot !== null
                    && ($current[$pageId]['queued_at'] ?? 0) === ($snapshot['queued_at'] ?? -1)
                ) {
                    unset($current[$pageId]);
                }
            }
            foreach ($exhausted as $pageId) {
                $snapshot = $pending[$pageId] ?? null;
                if (isset($current[$pageId])
                    && $snapshot !== null
                    && ($current[$pageId]['queued_at'] ?? 0) === ($snapshot['queued_at'] ?? -1)
                ) {
                    unset($current[$pageId]);
                }
            }
            foreach ($failed as $pageId => $attempts) {
                $snapshot = $pending[$pageId] ?? null;
                // Only stamp attempts if the queue entry is the same one we
                // just processed — prevents clobbering a fresh re-queue.
                if (isset($current[$pageId])
                    && $snapshot !== null
                    && ($current[$pageId]['queued_at'] ?? 0) === ($snapshot['queued_at'] ?? -1)
                ) {
                    $current[$pageId]['attempts'] = $attempts;
                }
            }

            update_option(self::OPTION_KEY, $current, false);
        } finally {
            \BCC\Core\DB\AdvisoryLock::release(self::LOCK_KEY);
        }

        } finally {
            \BCC\Core\DB\AdvisoryLock::release(self::PROCESS_LOCK_KEY);
        }
    }

    /**
     * Move a permanently failed entry to a quarantine option instead of
     * silently dropping it. Admins can inspect and manually retry via
     * wp_options → bcc_onchain_quarantined_bonus.
     *
     * @param int                                          $pageId
     * @param array{bonus: float, queued_at: int, attempts: int} $entry
     */
    private static function quarantine(int $pageId, array $entry): void
    {
        // Acquire advisory lock to prevent concurrent get_option + update_option
        // from losing entries under concurrency (lost-update race).
        if (!\BCC\Core\DB\AdvisoryLock::acquire(self::LOCK_KEY, 5)) {
            return; // Will be retried next cycle.
        }

        try {
            /** @var array<int, array{bonus: float, queued_at: int, attempts: int, quarantined_at: int}> $quarantined */
            $quarantined = get_option(self::QUARANTINE_KEY, []);
            $quarantined[$pageId] = [
                'bonus'          => $entry['bonus'] ?? 0.0,
                'queued_at'      => $entry['queued_at'] ?? 0,
                'attempts'       => $entry['attempts'] ?? 0,
                'quarantined_at' => time(),
            ];
            update_option(self::QUARANTINE_KEY, $quarantined, false);
        } finally {
            \BCC\Core\DB\AdvisoryLock::release(self::LOCK_KEY);
        }
    }

    /**
     * Prune quarantine entries older than 14 days and cap at 100 rows.
     *
     * Call from a daily cron hook to prevent unbounded growth.
     *
     * Holds LOCK_KEY around the read-modify-write to prevent a
     * concurrent quarantine() call from having its newly-added entry
     * clobbered by our update_option with a filtered-but-stale snapshot
     * (classic lost-update race). LOCK_KEY is the same advisory lock
     * that queue() / clear() / quarantine() all use, so writers serialize.
     */
    public static function pruneQuarantine(): void
    {
        if (!\BCC\Core\DB\AdvisoryLock::acquire(self::LOCK_KEY, 5)) {
            return; // Contention — daily cron will retry tomorrow.
        }

        try {
            /** @var array<int, array{bonus: float, queued_at: int, attempts: int, quarantined_at: int}> $quarantined */
            $quarantined = get_option(self::QUARANTINE_KEY, []);

            if (empty($quarantined)) {
                return;
            }

            $cutoff  = time() - (14 * DAY_IN_SECONDS);
            $pruned  = array_filter(
                $quarantined,
                static fn(array $entry): bool => $entry['quarantined_at'] >= $cutoff
            );

            // Cap at 100 entries — keep the most recent by quarantined_at.
            if (count($pruned) > 100) {
                uasort($pruned, static fn(array $a, array $b): int => $b['quarantined_at'] <=> $a['quarantined_at']);
                $pruned = array_slice($pruned, 0, 100, true);
            }

            if (count($pruned) !== count($quarantined)) {
                update_option(self::QUARANTINE_KEY, $pruned, false);
            }
        } finally {
            \BCC\Core\DB\AdvisoryLock::release(self::LOCK_KEY);
        }
    }
}
