<?php
/**
 * TierSnapshotService — daily Trust-Tier qualification history writer.
 *
 * Fills `bcc_trust_tier_days` with one row per (user, UTC day): the
 * member's resolved tier ordinal that day. Two writers, both INSERT
 * IGNORE (first write for a day wins):
 *
 *   - runDailySnapshot() — the `bcc_rank_daily_tier_snapshot` cron body.
 *     Advisory-locked, time-budgeted, pages wp_users by id cursor and
 *     resolves tiers in batches via ReputationRepository::getTiersForUsers
 *     (the canonical batched read). Members without a self-page score row
 *     resolve to the default Neutral tier (§13.1 — the default score 50
 *     counts as Neutral eligibility). Ends with a bounded retention purge.
 *
 *   - ensureTodayRow() — request-time lazy fallback (per-request memo).
 *     Shipped now, consumed by the Phase 5 promotion engine: evaluating
 *     a user whose row the sweep hasn't written yet must not read a gap
 *     for TODAY. Historical gaps stay gaps — a missing day is a
 *     non-qualifying day (fail-safe strict; a partial sweep can only
 *     delay a promotion, never grant one).
 *
 * @package BCC\Trust\Rank\Services
 * @since Rank redesign Phase 1 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Services;

use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Rank\Repositories\TierDaysRepository;
use BCC\Trust\Rank\Support\RankScoringConfig;

if (!defined('ABSPATH')) {
    exit;
}

class TierSnapshotService
{
    /** Users per resolve/write batch (matches getTiersForUsers callers' scale). */
    private const BATCH_SIZE = 200;

    /** Wall-clock budget for one sweep run, seconds. */
    private const TIME_BUDGET_SECONDS = 20;

    /** Rows per retention-purge batch. */
    private const PURGE_BATCH = 1000;

    /** @var array<int, true> Request-scoped ensureTodayRow memo. */
    private static array $todayMemo = [];

    public function __construct(
        private readonly TierDaysRepository $tierDays,
        private readonly ReputationRepository $reputation,
        private readonly RankScoringConfig $config
    ) {
    }

    /**
     * The daily sweep body. Partial coverage is logged, not fatal — a
     * user the budget missed simply has a non-qualifying day (fail-safe
     * strict), and tomorrow's run covers them again. Throws propagate to
     * RankScheduler, which records `rank_scoring.tier_snapshot_failed`.
     */
    public function runDailySnapshot(): void
    {
        if (!$this->tierDays->acquireSnapshotLock()) {
            return; // Another process is running — skip this tick.
        }

        try {
            $day      = gmdate('Y-m-d');
            $deadline = time() + self::TIME_BUDGET_SECONDS;
            $cursor   = 0;
            $written  = 0;
            $partial  = false;

            while (true) {
                $userIds = $this->tierDays->listUserIdsAfter($cursor, self::BATCH_SIZE);
                if ($userIds === []) {
                    break;
                }
                $cursor = max($userIds);

                $tiers = $this->reputation->getTiersForUsers($userIds);

                $batch = [];
                foreach ($userIds as $userId) {
                    // Missing score row → default Neutral (§13.1).
                    $tier           = $tiers[$userId] ?? 'neutral';
                    $batch[$userId] = $this->config->tierOrdFor($tier);
                }

                if (!$this->tierDays->recordDays($batch, $day)) {
                    throw new \RuntimeException('tier_days batch write failed');
                }
                $written += count($batch);

                if (time() >= $deadline) {
                    $partial = true;
                    break;
                }
            }

            if ($partial) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] rank tier snapshot: time budget hit, partial pass', [
                    'written' => $written,
                    'cursor'  => $cursor,
                ]);
            } else {
                \BCC\Core\Log\Logger::info('[bcc-trust] rank tier snapshot complete', [
                    'written' => $written,
                ]);
            }

            $this->purgeExpired($deadline);
        } finally {
            $this->tierDays->releaseSnapshotLock();
        }
    }

    /**
     * Request-time lazy fallback: make sure TODAY's row exists for one
     * user. Cheap — per-request memo, then a single INSERT IGNORE.
     * Consumed by the Phase 5 promotion engine; shipped in Phase 1 so
     * the plane is complete.
     */
    public function ensureTodayRow(int $userId): void
    {
        if ($userId <= 0 || isset(self::$todayMemo[$userId])) {
            return;
        }
        self::$todayMemo[$userId] = true;

        $tier = $this->reputation->getTier($userId);
        $this->tierDays->recordDay($userId, gmdate('Y-m-d'), $this->config->tierOrdFor($tier));
    }

    /**
     * Bounded retention purge within whatever budget the sweep left.
     * Retention window comes from config (tier_days_days).
     */
    private function purgeExpired(int $deadline): void
    {
        $beforeDay = gmdate('Y-m-d', time() - ($this->config->tierDaysRetentionDays * 86400));

        do {
            $deleted = $this->tierDays->purgeOlderThan($beforeDay, self::PURGE_BATCH);
            if ($deleted === false) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] rank tier snapshot: retention purge query failed', []);
                return;
            }
        } while ($deleted === self::PURGE_BATCH && time() < $deadline);
    }
}
