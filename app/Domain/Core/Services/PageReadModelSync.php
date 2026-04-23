<?php
/**
 * Page Read Model Sync
 *
 * Listens to trust engine events and keeps the denormalized
 * bcc_page_read_model table up to date.
 *
 * Event sources:
 *   - bcc_trust_vote_changed       (VoteService)
 *   - bcc_trust_endorsement_added  (EndorsementService)
 *   - bcc_trust_endorsement_removed(EndorsementService)
 *   - bcc.trust.recalculate_score  (helpers.php / ModerationService)
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\PageReadModelRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class PageReadModelSync
{
    /** @var string Object-cache group for dirty flags. */
    private const DIRTY_GROUP = 'bcc_rm_sync';

    /** @var int Pages per chunk before yielding (jitter between chunks). */
    private const BATCH_SIZE = 150;

    /** @var int Hard cap: max total pages per cron tick (prevents runaway). */
    private const MAX_PAGES_PER_TICK = 500;

    /** @var int Interval in seconds for the deferred sync cron. */
    private const SYNC_INTERVAL = 30;

    /**
     * Register the 30-second cron interval used by the deferred read-model
     * sync processor.
     *
     * Hooked from bootstrap.php at plugin-load time so the interval is
     * available to wp_schedule_event() from any context — activation, cron
     * run, or plugins_loaded — regardless of whether register() has been
     * called yet. WordPress dedupes this add_filter registration by
     * callable signature, so re-adding it is a no-op.
     *
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function registerIntervals(array $schedules): array
    {
        if (!isset($schedules['bcc_thirty_seconds'])) {
            $schedules['bcc_thirty_seconds'] = [
                'interval' => self::SYNC_INTERVAL,
                'display'  => 'Every 30 Seconds (BCC Read Model)',
            ];
        }
        return $schedules;
    }

    /**
     * Register all hooks. Called once from Plugin boot.
     *
     * High-frequency events (votes, endorsements) use DEFERRED sync:
     * they mark the page as dirty and a 30-second processor batch-syncs
     * all dirty pages in one pass. This collapses N events on the same
     * page into 1 sync instead of N syncs (write amplification fix).
     *
     * Low-frequency events (wallet verified, social changed, cron
     * recalculated) still sync IMMEDIATELY because they're rare enough
     * that the per-event cost is negligible and the user expects to see
     * the change reflected instantly.
     */
    public static function register(): void
    {
        // ── High-frequency events → DEFERRED (dirty flag) ───────────────
        add_action('bcc_trust_vote_changed',          [self::class, 'onVoteChanged'], 20, 2);
        add_action('bcc_trust_endorsement_added',     [self::class, 'onEndorsementChanged'], 20, 2);
        add_action('bcc_trust_endorsement_removed',   [self::class, 'onEndorsementChanged'], 20, 2);

        // ── Low-frequency events → IMMEDIATE sync ───────────────────────
        add_action('bcc.trust.recalculate_score',     [self::class, 'onScoreRecalculated'], 20, 1);
        add_action('bcc_trust_score_recalculated',    [self::class, 'onScoreRecalculated'], 20, 1);
        add_action('bcc_wallet_verified',             [self::class, 'onOwnerDataChanged'], 20, 1);
        add_action('bcc_trust_verification_changed',  [self::class, 'onOwnerDataChanged'], 20, 1);

        // ── Deferred sync processor (runs every 30 seconds) ─────────────
        add_action('bcc_trust_deferred_rm_sync', [self::class, 'processDirtyPages']);

        // NOTE: the `bcc_thirty_seconds` cron_schedules filter is registered
        // at plugin-load time in bootstrap.php via registerIntervals() — not
        // here — so activation and early cron paths see the interval without
        // depending on plugins_loaded having fired first.

        // Schedule — or reschedule — the deferred sync cron. If SYNC_INTERVAL
        // ever changes in a future release, the already-stored event keeps
        // its original cadence until manually cleared. Version-gate via an
        // option so a schedule change propagates on the first request after
        // deploy (mirrors CronService::maybeReschedule()).
        $storedInterval = (string) get_option('bcc_trust_rm_sync_interval', '');
        if ($storedInterval !== (string) self::SYNC_INTERVAL) {
            wp_clear_scheduled_hook('bcc_trust_deferred_rm_sync');
            update_option('bcc_trust_rm_sync_interval', (string) self::SYNC_INTERVAL, false);
        }
        if (!wp_next_scheduled('bcc_trust_deferred_rm_sync')) {
            wp_schedule_event(time(), 'bcc_thirty_seconds', 'bcc_trust_deferred_rm_sync');
        }

        // ── Shutdown fallback: flush dirty pages on request end ──────────
        // If WP-Cron is disabled, the 30-second cron won't fire via web
        // traffic. As a safety net, process dirty pages on PHP shutdown
        // to guarantee ≤1 request of staleness.
        add_action('shutdown', [self::class, 'shutdownFlush'], 999);

        // ── Daily full sync (safety net) ────────────────────────────────
        add_action('bcc_trust_daily_maintenance', [self::class, 'onDailySync'], 50);
        add_action('bcc_trust_initial_read_model_sync', [self::class, 'onDailySync']);
    }

    // ── Event handlers ──────────────────────────────────────────────────

    /**
     * A vote was cast or removed — mark page as dirty for deferred sync.
     */
    public static function onVoteChanged(int $voterId, int $pageId): void
    {
        self::markDirty($pageId);
    }

    /**
     * An endorsement was added or removed — mark page as dirty.
     */
    public static function onEndorsementChanged(int $endorserUserId, int $pageId): void
    {
        self::markDirty($pageId);
    }

    /**
     * Score recalculated (cron, moderation) — sync immediately.
     * These are already batched by the 5-minute recalc cron, so
     * deferring further adds latency without reducing work.
     */
    public static function onScoreRecalculated(int $pageId): void
    {
        self::syncPageImmediate($pageId);
    }

    /**
     * Owner-level data changed (wallet, social) — sync immediately.
     */
    public static function onOwnerDataChanged(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $resolver = Plugin::instance()->pageOwnerResolver();
        $pageId   = $resolver->getPageForOwner($userId);

        if ($pageId > 0) {
            // Flag for score recalculation so the onchain_bonus / wallet
            // verification status is reflected in the trust score, not
            // just the read model's has_wallet flag. Without this, the
            // read model shows "verified" but the score & tier lag behind
            // until the next 5-minute recalc cron cycle.
            Plugin::instance()->scoreRepository()->flagForRecalculation($pageId);

            self::syncPageImmediate($pageId);
        }
    }

    /**
     * Daily full sync — catches any drift.
     */
    public static function onDailySync(): void
    {
        if (!\BCC\Trust\Core\Repositories\DatabaseLockRepository::acquire('bcc_cron_read_model_sync', 0)) {
            return;
        }
        try {
            Plugin::instance()->pageReadModelRepository()->syncAll();
        } finally {
            \BCC\Trust\Core\Repositories\DatabaseLockRepository::release('bcc_cron_read_model_sync');
        }
    }

    // ── Dirty-flag mechanism ────────────────────────────────────────────

    /**
     * Mark a page as needing a read-model sync.
     *
     * Uses wp_cache (Redis SADD-like) with a per-page flag key.
     * A process-local array tracks dirty pages for the shutdown fallback.
     */
    /** @var array<int, bool> */
    private static array $dirtyThisRequest = [];

    public static function markDirty(int $pageId): void
    {
        if ($pageId <= 0) {
            return;
        }

        // Track in process memory for shutdown fallback.
        self::$dirtyThisRequest[$pageId] = true;

        // Per-page cache flag for fast dirty checks in shutdownFlush.
        wp_cache_set("dirty:{$pageId}", 1, self::DIRTY_GROUP, 300);

        // Durable cross-process queue: INSERT IGNORE is atomic and
        // race-safe — concurrent requests marking the same page are
        // de-duped by the PRIMARY KEY constraint.
        PageReadModelRepository::enqueueDirty($pageId);
    }

    // ── Batch processor ─────────────────────────────────────────────────

    /**
     * Process all dirty pages in one batch. Called by the 30-second cron.
     *
     * Atomically reads and clears the dirty set, then syncs each page.
     * If a page is marked dirty again during processing, it will be
     * picked up in the next 30-second cycle.
     *
     * Guarded by a MySQL advisory lock so that concurrent wp-cron firings
     * (common when DISABLE_WP_CRON is false and multiple requests arrive
     * simultaneously) cannot double-process the same dirty set and fight
     * with live vote FOR UPDATE locks on score rows.
     */
    public static function processDirtyPages(): void
    {
        $lockKey = 'bcc_rm_deferred_sync';
        if (!\BCC\Trust\Core\Repositories\DatabaseLockRepository::acquire($lockKey, 0)) {
            // Another worker is already processing this tick. Skip.
            return;
        }

        try {
            self::processDirtyPagesInner();
        } finally {
            \BCC\Trust\Core\Repositories\DatabaseLockRepository::release($lockKey);
        }
    }

    private static function processDirtyPagesInner(): void
    {
        // Capture the snapshot timestamp BEFORE fetching so any enqueue
        // that lands during this tick (which bumps created_at to NOW(6)
        // via ON DUPLICATE KEY UPDATE in enqueueDirty) is preserved past
        // the post-sync cleanup. Without this snapshot the row would be
        // deleted unconditionally and the second mutation lost forever.
        $cutoff    = PageReadModelRepository::nowSnapshot();
        $dirtyRows = PageReadModelRepository::fetchDirtyPages(self::MAX_PAGES_PER_TICK);

        if (empty($dirtyRows)) {
            self::correctDrift();
            return;
        }

        $repo        = Plugin::instance()->pageReadModelRepository();
        $processed   = 0;
        $successIds  = [];

        foreach ($dirtyRows as $row) {
            $pageId = (int) $row->page_id;
            if ($pageId <= 0) {
                $successIds[] = $pageId; // remove invalid entries
                continue;
            }

            // Jitter between chunks: every BATCH_SIZE pages, sleep 5-20ms
            // to avoid spiking DB load when a large dirty set is processed.
            if ($processed > 0 && $processed % self::BATCH_SIZE === 0) {
                usleep(random_int(5000, 20000)); // 5-20ms
            }

            // Clear the per-page cache flag.
            wp_cache_delete("dirty:{$pageId}", self::DIRTY_GROUP);

            try {
                $repo->syncPage($pageId);
                $successIds[] = $pageId;
                // Reset quarantine escalation on a clean sync — prior
                // failures were transient and should not make the next
                // incident start at the 24-hour backoff ceiling.
                delete_transient('bcc_sync_quar_' . $pageId);
            } catch (\Throwable $e) {
                $failKey = 'bcc_sync_fail_' . $pageId;
                $failures = (int) get_transient($failKey);
                $failures++;
                if ($failures >= 5) {
                    // Quarantine with exponential backoff. Previously every
                    // quarantine used a fixed 1-hour delay, so a page stuck
                    // behind a persistent issue (replication lag, schema
                    // drift, missing dependency) would re-fail on the hour
                    // forever with no escalating alarm to operators. Now
                    // the delay doubles on each successive quarantine up
                    // to a 24-hour ceiling, and the log carries a CRITICAL
                    // marker so ops filters can page on repeat offenders.
                    $quarKey   = 'bcc_sync_quar_' . $pageId;
                    $quarCount = (int) get_transient($quarKey) + 1;

                    // 1h → 4h → 24h → 24h …
                    $delayHours = match (true) {
                        $quarCount <= 1 => 1,
                        $quarCount === 2 => 4,
                        default         => 24,
                    };

                    PageReadModelRepository::quarantineDirtyPage($pageId, $delayHours);
                    set_transient($quarKey, $quarCount, 7 * DAY_IN_SECONDS);
                    delete_transient($failKey);

                    $severity = $quarCount >= 2 ? '[CRITICAL]' : '[WARN]';
                    \BCC\Core\Log\Logger::error(
                        "{$severity} [PageReadModelSync] Quarantined page {$pageId} (retry in {$delayHours}h, quarantine #{$quarCount}) after {$failures} sync failures: " . $e->getMessage(),
                        [
                            'page_id'         => $pageId,
                            'quarantine_count'=> $quarCount,
                            'delay_hours'     => $delayHours,
                            'severity'        => $quarCount >= 2 ? 'critical' : 'warning',
                        ]
                    );
                } else {
                    set_transient($failKey, $failures, 3600);
                    \BCC\Core\Log\Logger::error("[PageReadModelSync] Sync failed for page {$pageId} (attempt {$failures}/5): " . $e->getMessage());
                }
                continue;
            }

            $processed++;
        }

        // Delete successfully processed pages from the queue, but only
        // entries whose created_at <= the snapshot we took before
        // fetching. Entries that were re-enqueued during this tick have
        // a newer created_at and survive for the next cycle.
        PageReadModelRepository::removeDirtyPages($successIds, $cutoff);

        // ── Reactive drift correction ───────────────────────────────
        // After processing dirty pages, check for read model drift and
        // auto-correct up to 10 divergent pages per cycle. This turns
        // drift detection from passive monitoring into active containment.
        // Runs every 30 seconds as part of the batch processor — no
        // separate cron needed.
        self::correctDrift();
    }

    /**
     * Find pages where the read model score diverges from the live score
     * by more than 1 point, and immediately resync + flag them for
     * authoritative recalculation.
     *
     * Bounded to 10 pages per cycle to keep the batch processor fast.
     */
    private static function correctDrift(): void
    {
        $healthRepo = Plugin::instance()->readModelHealthRepository();
        $drifted    = $healthRepo->getDriftedPageIds(10);

        if (empty($drifted)) {
            return;
        }

        $repo      = Plugin::instance()->pageReadModelRepository();
        $scoreRepo = Plugin::instance()->scoreRepository();

        // Time-gate the drift correction: skip pages that had a vote in
        // the last 10 seconds. The drift check reads `bcc_page_read_model`
        // and live `bcc_trust_page_scores` outside any transaction; an
        // in-flight vote's post-commit sync can land between those two
        // reads, making correctDrift see "drift" that is actually the
        // new-vote state propagating. Applying the resync backwards in
        // that window flickers the read-model for ≤30s. Skipping
        // recently-active pages lets the deferred sync own the
        // convergence for them.

        foreach ($drifted as $pageId) {
            $pageId = (int) $pageId;

            // Skip if a vote committed in the last 10s — deferred sync
            // will handle this page on the next tick. Repository-only DB
            // access: the existence query is now on ScoreRepository so
            // this Services/ class stays free of raw $wpdb.
            if ($scoreRepo->hasVoteSince($pageId, 10)) {
                continue;
            }

            // Immediate resync from source tables.
            try {
                $repo->syncPage($pageId);
            } catch (\Throwable $e) {
                // Non-fatal — will retry next cycle.
                continue;
            }

            // Audit HIGH-8: do NOT unconditionally flagForRecalculation here.
            // syncPage already wrote the authoritative live total_score
            // into the read model, so drift is 0 immediately. Flagging
            // created a feedback loop — the 5-min recalc would re-derive
            // the score, sync would refresh the read model, and the next
            // drift probe would observe a fresh diff, re-flagging the
            // same page every 30s. Only flag when the live score itself
            // is stale (i.e. recalc is genuinely needed).
            if ($scoreRepo->needsRecalculation($pageId)) {
                $scoreRepo->flagForRecalculation($pageId);
            }
        }

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::info('[ReadModelSync] drift_corrected', [
                'pages' => count($drifted),
            ]);
        }
    }

    /**
     * Shutdown fallback: flush dirty pages accumulated during this request.
     *
     * Runs at PHP shutdown to guarantee that even without WP-Cron,
     * dirty pages are synced within the same request lifecycle.
     * Only processes pages dirtied by THIS request (not the global set)
     * to keep shutdown time bounded.
     */
    public static function shutdownFlush(): void
    {
        if (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) {
            return; // Cron handles sync; shutdown flush only needed when cron is disabled
        }

        if (empty(self::$dirtyThisRequest)) {
            return;
        }

        $repo   = Plugin::instance()->pageReadModelRepository();
        $synced = [];

        // Snapshot taken BEFORE the sync loop so any concurrent enqueue
        // (which bumps created_at to NOW(6) via ON DUPLICATE KEY UPDATE)
        // is preserved past the post-sync DELETE — same race-fix the
        // 30-second cron uses.
        $cutoff = PageReadModelRepository::nowSnapshot();

        foreach (self::$dirtyThisRequest as $pageId => $flag) {
            $pageId = (int) $pageId;
            // Only sync if the dirty flag is still set (not already
            // processed by the 30-second cron during this request).
            $stillDirty = wp_cache_get("dirty:{$pageId}", self::DIRTY_GROUP);
            if ($stillDirty !== false) {
                wp_cache_delete("dirty:{$pageId}", self::DIRTY_GROUP);
                try {
                    $repo->syncPage($pageId);
                    $synced[] = $pageId;
                } catch (\Throwable $e) {
                    // Leave in DB queue for the 30s cron to retry.
                    if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                        \BCC\Core\Log\Logger::error('[ReadModelSync] shutdownFlush failed', [
                            'page_id' => $pageId,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // Remove successfully synced pages from the DB queue, but only
        // entries whose created_at <= the cutoff snapshot. Concurrent
        // re-enqueues bump created_at past the cutoff and survive.
        PageReadModelRepository::removeDirtyPages($synced, $cutoff);

        self::$dirtyThisRequest = [];
    }

    // ── Direct sync (for low-frequency events) ──────────────────────────

    private static function syncPageImmediate(int $pageId): void
    {
        if ($pageId <= 0) {
            return;
        }

        Plugin::instance()->pageReadModelRepository()->syncPage($pageId);
    }
}
