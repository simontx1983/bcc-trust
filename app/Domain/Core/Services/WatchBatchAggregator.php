<?php
/**
 * Watch Batch Aggregator — §C3 implementation.
 *
 * Per §C3:
 *   - Watches accumulate into a single batch while the user keeps
 *     watching cards.
 *   - The batch closes after exactly 10 minutes of watch inactivity.
 *   - At close, server emits exactly ONE watch_batch feed item.
 *   - Once emitted, the batch is frozen — subsequent unwatches don't
 *     retroactively edit, recount, or remove the feed post.
 *   - Maximum 3 cards shown in post body + "+N more".
 *
 * Storage: no new table. The user's open batch is the set of
 * bcc_watch_meta rows where batch_id IS NULL belonging to that user
 * (via JOIN to peepso_user_followers per the §C2 single-graph rule).
 *
 * Trigger: 1-minute recurring WP-Cron sweep. Each tick:
 *   1. Find users whose open batches' most-recent watched_at is
 *      ≤ now-10min — these are the batches due to close.
 *   2. For each user, generate a fresh batch_id and stamp every
 *      open watch_meta row with it (single UPDATE query, idempotent
 *      via `WHERE batch_id IS NULL`).
 *   3. Emit `bcc_watch_batch_emitted` event with batch metadata.
 *
 * Why a recurring sweep (not one-shot scheduling per watch): WP-Cron's
 * `wp_schedule_single_event` dedups identical hook+args pairs scheduled
 * within a 10-minute window, which collides exactly with our case
 * (same user watching twice in <10 min). The recurring sweep avoids
 * that hazard at the cost of a small constant-time query each minute.
 *
 * Frozen-history rule: the §C3 contract says emitted batches don't
 * retroactively change. This service writes the batch_id and emits
 * the event — the feed item itself is written by a separate
 * subscriber (the activity-stream writer) per §A3. That subscriber
 * MUST NOT re-write the feed item on subsequent unwatch events; the
 * §C3 frozen-history rule lives there, not here.
 *
 * Cron hook self-heal: Plugin.php's plugins_loaded handler unschedules
 * the pre-rename `bcc_pull_batch_sweep` hook on every load to drain
 * stale events from sites that upgrade mid-flight (mitigates the
 * cron-drift incident class).
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Repositories\WatchingRepository;
use BCC\Trust\Core\Repositories\WatchMetaRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class WatchBatchAggregator
{
    /** §C3 inactivity window — 10 minutes (in seconds). */
    public const INACTIVITY_WINDOW_SECONDS = 600;

    /**
     * Recurring cron hook for the periodic sweep.
     *
     * Renamed 2026-05-13 from `bcc_pull_batch_sweep`. Plugin.php
     * self-heals via wp_clear_scheduled_hook() on plugins_loaded so
     * sites that upgrade without reactivating don't orphan the legacy
     * hook.
     */
    public const SWEEP_HOOK = 'bcc_watch_batch_sweep';

    /**
     * Custom WP-Cron interval registered for the sweep.
     *
     * The interval slug `bcc_minute` is the historical name registered
     * in `cron_schedules`; keeping the existing slug avoids a second
     * migration of internal-only names that no public consumer reads.
     */
    public const SWEEP_INTERVAL = 'bcc_minute';

    /** §C3 cap: post body shows up to 3 cards + "+N more". */
    public const TOP_CARDS_DISPLAY = 3;

    /**
     * Default cap on users processed per sweep tick. Override via the
     * `bcc_watch_batch_sweep_limit` filter for high-volume installs:
     *
     *   add_filter('bcc_watch_batch_sweep_limit', fn() => 500);
     *
     * Hard-clamped to [1, 500] regardless of filter return.
     */
    private const SWEEP_USER_LIMIT_DEFAULT = 100;

    public function __construct(
        private readonly WatchingRepository $watchingRepo,
        private readonly WatchMetaRepository $watchMetaRepo
    ) {
    }

    /**
     * Periodic sweep — find users whose open batches are due to close
     * and close them. Idempotent; safe under cron retries.
     *
     * Invoked from WP-Cron via the SWEEP_HOOK action registered in
     * Plugin::registerAsyncJobs.
     */
    public function sweep(): void
    {
        $limit = self::resolveSweepLimit();
        $cutoffMysql = gmdate('Y-m-d H:i:s', time() - self::INACTIVITY_WINDOW_SECONDS);

        $userIds = $this->watchingRepo->findUsersWithExpiredOpenBatches($cutoffMysql, $limit);

        if ($userIds === []) {
            // Quiet path — no log noise when there's nothing to do
            // (this fires every minute under cron).
            return;
        }

        Logger::info('[WatchBatchAggregator] sweep tick', [
            'user_count' => count($userIds),
            'cutoff'     => $cutoffMysql,
            'limit'      => $limit,
        ]);

        foreach ($userIds as $userId) {
            $this->closeForUser($userId);
        }
    }

    /**
     * Close a single user's open batch if it is past the inactivity
     * window. Idempotent: a second concurrent call sees zero rows
     * stamped and skips event emission.
     *
     * Public so admin tooling / tests can force-close without waiting
     * for the next sweep tick.
     */
    public function closeForUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $openRows = $this->watchingRepo->findOpenWatchMetaForUser($userId);
        if ($openRows === []) {
            return;
        }

        // Re-confirm the inactivity window in this worker — between
        // the sweep query and now, the user may have watched again.
        $latestWatchEpoch = 0;
        $earliestWatchMysql = '';
        $latestWatchMysql   = '';
        foreach ($openRows as $row) {
            $ts = strtotime($row->watched_at . ' UTC');
            if ($ts === false) {
                continue;
            }
            if ($ts > $latestWatchEpoch) {
                $latestWatchEpoch = $ts;
                $latestWatchMysql = $row->watched_at;
            }
            if ($earliestWatchMysql === '' || $row->watched_at < $earliestWatchMysql) {
                $earliestWatchMysql = $row->watched_at;
            }
        }
        if ($latestWatchEpoch === 0) {
            return; // defensive — shouldn't happen with NOT NULL watched_at
        }
        if (time() - $latestWatchEpoch < self::INACTIVITY_WINDOW_SECONDS) {
            // A watch arrived after the sweep query; defer to the next
            // tick after this batch quiets again.
            return;
        }

        $followIds = [];
        foreach ($openRows as $row) {
            $followIds[] = (int) $row->follow_id;
        }

        // Deterministic batch_id: hash of (userId, sorted follow_ids,
        // watched_at range). Same inputs always yield the same id;
        // useful for debugging, replay, and reconciliation.
        $batchId = self::generateBatchId($userId, $followIds, $earliestWatchMysql, $latestWatchMysql);
        $stamped = $this->watchMetaRepo->stampBatch($followIds, $batchId);

        if ($stamped === 0) {
            // Race lost: another worker already stamped these rows
            // (with the same deterministic batch_id, since inputs are
            // identical). The other worker fired the event; we exit
            // silently to avoid duplicate emission.
            Logger::info('[WatchBatchAggregator] batch close skipped — already stamped', [
                'user_id'  => $userId,
                'batch_id' => $batchId,
            ]);
            return;
        }

        $cardCount    = count($followIds);
        $topFollowIds = array_slice($followIds, 0, self::TOP_CARDS_DISPLAY);
        $handles      = $this->watchingRepo->findHandlesForFollowIds($topFollowIds);

        $topCards = [];
        foreach ($topFollowIds as $followId) {
            $topCards[] = [
                'follow_id'   => $followId,
                'card_handle' => $handles[$followId] ?? '',
            ];
        }
        $moreCount = max(0, $cardCount - self::TOP_CARDS_DISPLAY);

        Logger::info('[WatchBatchAggregator] batch emitted', [
            'user_id'    => $userId,
            'batch_id'   => $batchId,
            'card_count' => $cardCount,
            'more_count' => $moreCount,
        ]);

        /**
         * Fires when a §C3 watch batch closes. Subscribers (activity-
         * stream writer per §A3, notification dispatcher, feed surfaces)
         * react asynchronously — this service does NOT write the feed
         * item directly.
         *
         * @param int    $userId    The watching user.
         * @param string $batchId   Deterministic identifier; also stamped on every watch_meta row in the batch.
         * @param int    $cardCount Total cards in the batch.
         * @param list<array{follow_id: int, card_handle: string}> $topCards  First TOP_CARDS_DISPLAY items, enriched with handle so subscribers can render without re-fetching.
         * @param int    $moreCount cardCount - count(topCards), for the "+N more" affordance.
         */
        do_action('bcc_watch_batch_emitted', $userId, $batchId, $cardCount, $topCards, $moreCount);
    }

    /**
     * Resolve the per-tick user-cap with a filter override. Hard-clamped
     * to [1, 500] regardless of filter return — sanity guard so a
     * bad filter can't blow up the query.
     */
    private static function resolveSweepLimit(): int
    {
        /** @var int|mixed $filtered */
        $filtered = apply_filters('bcc_watch_batch_sweep_limit', self::SWEEP_USER_LIMIT_DEFAULT);
        $limit = is_int($filtered) ? $filtered : self::SWEEP_USER_LIMIT_DEFAULT;
        return max(1, min(500, $limit));
    }

    /**
     * Deterministic batch_id from the batch's content.
     *
     * Format: pb-{userId}-{first8charsOfSha256(payload)}
     * (the `pb-` prefix is a stored, deterministic identifier token —
     * NOT user-facing vocabulary — kept stable so existing batch_ids
     * resolve.)
     * Payload: userId | sorted follow_ids | earliest watched_at | latest watched_at
     *
     * Properties:
     *   - Same inputs ⇒ same id (idempotent under retries)
     *   - First-8 of sha256 ⇒ 32 bits of entropy per user (collisions
     *     vanishingly rare given the per-user namespace).
     *   - Total length ≤ 22 chars (matches schema VARCHAR(64)).
     *
     * @param list<int> $followIds  Members of the batch (ordering not significant — we sort here).
     */
    private static function generateBatchId(
        int $userId,
        array $followIds,
        string $earliestWatchedAt,
        string $latestWatchedAt
    ): string {
        $sortedIds = $followIds;
        sort($sortedIds, SORT_NUMERIC);

        $payload = sprintf(
            '%d|%s|%s|%s',
            $userId,
            implode(',', $sortedIds),
            $earliestWatchedAt,
            $latestWatchedAt
        );

        $hash = substr(hash('sha256', $payload), 0, 8);
        return sprintf('pb-%d-%s', $userId, $hash);
    }
}
