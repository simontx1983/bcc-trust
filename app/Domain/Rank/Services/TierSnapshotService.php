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

    /**
     * F-13: the day-scoped snapshot cursor, {day, after, complete}. See
     * runDailySnapshot().
     */
    private const CURSOR_OPTION = 'bcc_rank_tier_snapshot_cursor';

    /** Seconds before a budget-cut snapshot's continuation runs. */
    private const CONTINUATION_DELAY = 60;

    /** @var array<int, true> Request-scoped ensureTodayRow memo. */
    private static array $todayMemo = [];

    public function __construct(
        private readonly TierDaysRepository $tierDays,
        private readonly ReputationRepository $reputation,
        private readonly RankScoringConfig $config
    ) {
    }

    /**
     * The daily sweep body: TODAY's tier row for every user.
     *
     * ⚠ F-13. This used to start every run at cursor 0 under a 20-second
     * budget, and a user with no row for a day has a NON-QUALIFYING day
     * (fail-safe strict). So on a population larger than one budget's
     * worth, the users past it never got a row and could never qualify —
     * and resuming tomorrow would not help, because each row is for one day.
     * Now:
     *   - the cursor is DAY-SCOPED ({day, after, complete}) and persisted;
     *   - a budget-cut pass schedules ONE same-day continuation, so every
     *     user gets TODAY's row today;
     *   - a cursor left from an earlier day is abandoned (logged) and today
     *     starts at 0 — a past day's tail cannot be backfilled honestly;
     *   - a completed day is recorded, so a second trigger the same day is
     *     a no-op, and the retention purge runs once, at completion;
     *   - the cursor advances only after recordDays() succeeds; a write
     *     failure or a failed read (listUserIdsAfter is fail-closed) throws
     *     to RankScheduler, which records `rank_scoring.tier_snapshot_failed`,
     *     and the cursor stays for the retry.
     */
    public function runDailySnapshot(): void
    {
        if (!$this->tierDays->acquireSnapshotLock()) {
            return; // Another process is running — skip this tick.
        }

        try {
            $day      = $this->today();
            $deadline = $this->now() + self::TIME_BUDGET_SECONDS;
            $state    = $this->readSnapshotCursor();

            if ($state !== null && $state['day'] === $day && $state['complete']) {
                return; // Today's snapshot is already complete.
            }

            $cursor = 0;
            if ($state !== null && $state['day'] === $day) {
                $cursor = $state['after'];
            } elseif ($state !== null && !$state['complete']) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] rank tier snapshot: an earlier day was left unfinished; starting today from the beginning', [
                    'stale_day' => $state['day'],
                    'after'     => $state['after'],
                ]);
            }

            $written = 0;
            while (true) {
                $userIds = $this->tierDays->listUserIdsAfter($cursor, self::BATCH_SIZE);
                if ($userIds === []) {
                    break;
                }

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
                $cursor   = max($userIds);

                if (count($userIds) < self::BATCH_SIZE) {
                    break; // A short batch is the last batch.
                }

                if ($this->now() >= $deadline) {
                    $this->writeSnapshotCursor(['day' => $day, 'after' => $cursor, 'complete' => false]);
                    $this->scheduleSnapshotContinuation();
                    \BCC\Core\Log\Logger::warning('[bcc-trust] rank tier snapshot: time budget hit, continuing shortly', [
                        'written' => $written,
                        'cursor'  => $cursor,
                    ]);
                    return;
                }
            }

            $this->writeSnapshotCursor(['day' => $day, 'after' => $cursor, 'complete' => true]);
            \BCC\Core\Log\Logger::info('[bcc-trust] rank tier snapshot complete', [
                'written' => $written,
            ]);

            $this->purgeExpired($deadline);
        } finally {
            $this->tierDays->releaseSnapshotLock();
        }
    }

    // ── F-13 sweep collaborators (overridden by RankSweepCursorTest) ──

    protected function now(): int
    {
        return time();
    }

    protected function today(): string
    {
        return gmdate('Y-m-d');
    }

    /** @return array{day: string, after: int, complete: bool}|null */
    protected function readSnapshotCursor(): ?array
    {
        $raw = get_option(self::CURSOR_OPTION, null);
        if (!is_array($raw) || !isset($raw['day']) || !is_string($raw['day'])) {
            return null;
        }
        return [
            'day'      => $raw['day'],
            'after'    => (int) ($raw['after'] ?? 0),
            'complete' => (bool) ($raw['complete'] ?? false),
        ];
    }

    /** @param array{day: string, after: int, complete: bool} $state */
    protected function writeSnapshotCursor(array $state): void
    {
        update_option(self::CURSOR_OPTION, $state, false);
    }

    protected function scheduleSnapshotContinuation(): void
    {
        if (!wp_next_scheduled(RankScheduler::EVENT_DAILY_TIER_SNAPSHOT_CONTINUE)) {
            wp_schedule_single_event(time() + self::CONTINUATION_DELAY, RankScheduler::EVENT_DAILY_TIER_SNAPSHOT_CONTINUE);
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
