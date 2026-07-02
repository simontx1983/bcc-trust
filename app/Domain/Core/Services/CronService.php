<?php

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Repositories\WalletSignalRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cron job handlers for the Trust Engine.
 *
 * Each public method corresponds to a WordPress cron hook.
 * All database access is delegated to repository classes.
 * Registered in bootstrap.php via Plugin::instance()->cronService().
 */
class CronService
{
    private VoteRepository $voteRepo;
    private ScoreRepository $scoreRepo;

    public function __construct(
        VoteRepository $voteRepo,
        ScoreRepository $scoreRepo
    ) {
        $this->voteRepo  = $voteRepo;
        $this->scoreRepo = $scoreRepo;
    }

    // ── Daily cleanup ───────────────────────────────────────────────────

    public function dailyCleanup(): void
    {
        if (!$this->acquireLock('bcc_cron_cleanup_lock', DAY_IN_SECONDS)) {
            return;
        }

        try {
        // Audit-log retention is handled by archiveActivity() below (line ~74),
        // which archives 90+ day rows into bcc_trust_activity_archive in 5000-row
        // batches and only then DELETEs from the source table. A prior standalone
        // AuditLogger::cleanOldLogs() call lived here and preempted the archive
        // path (hard-deleted the same rows the archive would have copied), so
        // the archive table was silently empty in production. cleanOldLogs()
        // remains as a callable helper on AuditLogger but is no longer in the
        // production hot path.

        // Clean old fingerprint records
        Plugin::instance()->deviceFingerprinter()->cleanOldRecords();

        // Clean old soft-deleted votes
        $this->voteRepo->cleanupOldDeleted();

        // Clean expired fraud analysis records
        $fraudRepo = Plugin::instance()->fraudAnalysisRepository();
        if ($fraudRepo->tableExists()) {
            $fraudRepo->deleteExpiredRecords();
        }

        // Clean expired behavior patterns
        $patternRepo = Plugin::instance()->patternRepository();
        if ($patternRepo->tableExists()) {
            $patternRepo->deleteExpired();
        }

        // Fraud score time decay — reduce by 1/day for non-suspended users idle 30+ days.
        // Respects peak_fraud_score: score can never decay below 50% of peak.
        $userInfoRepo = Plugin::instance()->userInfoRepository();
        if ($userInfoRepo->tableExists()) {
            $userInfoRepo->applyDailyFraudDecay();
        }

        // Archive old activity logs (90+ days) to archive table.
        Plugin::instance()->userLifecycleService()->archiveActivity();

        // Retention sweep for the append-only trust ledgers. These tables
        // had no cleanup and grew unbounded under the daily recalc loop.
        // Each repo method is batched + capped + fail-loud and runs under
        // the bcc_cron_cleanup_lock already held above. Horizons in
        // includes/config/limits.php (score 90d, resolved reports 90d).
        // content_reports cleanup never touches pending rows.
        Plugin::instance()->scoreEventRepository()->cleanupOld();
        Plugin::instance()->contentReportRepository()->cleanupResolved();
        } finally {
            $this->releaseLock('bcc_cron_cleanup_lock');
        }
    }

    // ── Hourly score recalculation ──────────────────────────────────────

    /**
     * Hourly safety-net recalculation.
     *
     * Processes pages that are stale (last_calculated_at > 1 hour) regardless
     * of the recalculate_required flag. This catches:
     *   - Pages where flagging failed
     *   - Pages that drifted without anyone noticing
     *   - Quarantined pages (resets their failure counter so they get a fresh retry)
     *
     * This is the ultimate drift guarantee: no page stays stale longer than
     * 1 hour even if the 5-minute processor is down, overloaded, or skipping
     * a specific page due to repeated failures.
     */
    public function hourlyRecalc(): void
    {
        // Unified advisory-lock key with processRecalculations() so the
        // two cron paths can never thunder on overlapping page sets
        // concurrently (earlier divergence: 'bcc_cron_recalc_lock' vs
        // 'bcc_recalc_lock' let both run simultaneously).
        if (!$this->acquireLock('bcc_recalc_lock', 2 * HOUR_IN_SECONDS)) {
            return;
        }

        try {
            // Reset quarantined pages so they get a fresh retry cycle.
            $resetCount = $this->scoreRepo->resetQuarantinedPages(self::RECALC_MAX_FAILURES);
            if ($resetCount > 0) {
                AuditLogger::log('recalc_quarantine_reset', 0, [
                    'pages_reset' => $resetCount,
                ], 'system');
            }

            // Process stale pages (regardless of flag)
            $stalePages = $this->scoreRepo->getStalePageIds(100);

            if (empty($stalePages)) {
                return;
            }

            // Wall-clock time budget. Without it one slow page's FOR UPDATE
            // transaction can starve the remaining 99 and exceed
            // max_execution_time on sites with hot rows.
            $deadline    = time() + (int) apply_filters('bcc_trust_hourly_recalc_budget', 120);
            $voteService = Plugin::instance()->voteService();
            $processed   = 0;
            $skipped     = 0;
            foreach ($stalePages as $page_id) {
                if (time() >= $deadline) {
                    $skipped = count($stalePages) - $processed;
                    \BCC\Core\Log\Logger::info('[CronService] hourlyRecalc hit time budget', [
                        'processed' => $processed,
                        'skipped'   => $skipped,
                        'total'     => count($stalePages),
                    ]);
                    break;
                }
                try {
                    // recalculateScore() already derives endorsement_bonus
                    // from SUM(weight) of active endorsements inside its
                    // FOR UPDATE transaction (see recalculateFromVotes()).
                    // A separate recomputeEndorsementBonus() call here would
                    // run OUTSIDE that transaction, creating a TOCTOU race
                    // with live EndorsementService::applyEndorsementBonus()
                    // calls — the cron's stale SUM could overwrite a real-time
                    // endorsement delta that landed between the two commits.
                    // clearRecalcFlag=true: clear the recalculation flag
                    // inside the FOR UPDATE transaction to prevent the race
                    // where a live vote re-flags the page between COMMIT and
                    // an external flag-clearing UPDATE.
                    $voteService->recalculateScore((int) $page_id, true);
                    do_action('bcc_trust_score_recalculated', (int) $page_id);
                    $processed++;
                } catch (\Exception $e) {
                    // Individual page failure should not stop the batch.
                    \BCC\Core\Log\Logger::error('[CronService] hourlyRecalc failed', [
                        'page_id' => $page_id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            if ($processed > 0) {
                AuditLogger::log('hourly_recalc_complete', 0, [
                    'pages_processed'  => $processed,
                    'pages_skipped'    => $skipped,
                    'quarantine_reset' => $resetCount,
                ], 'system');
            }
        } finally {
            $this->releaseLock('bcc_recalc_lock');
        }
    }

    // ── Daily ML fraud update ───────────────────────────────────────────

    /**
     * Daily fraud score refresh for active users.
     *
     * Runs FraudDetector::getEnhancedFraudScore() on each active user, which:
     *  - Executes the full fraud analysis pipeline (behavioral, device, trust graph)
     *  - Stores results in user_info.fraud_score and fraud_analysis table
     *  - Returns the blended score (70% new analysis, 30% existing)
     *
     * High-risk users are flagged for admin review.
     */
    public function dailyFraudRefresh(): void
    {
        if (!$this->acquireLock('bcc_cron_fraud_refresh_lock', 2 * HOUR_IN_SECONDS)) {
            return;
        }

        try {
            $userInfoRepo = Plugin::instance()->userInfoRepository();

            // Rotate through all active users across successive runs.
            // Without the cursor, sites with >1000 active users saw the
            // same MySQL-heap-ordered subset processed forever while the
            // rest never got a fraud refresh.
            $cursorOption = 'bcc_trust_fraud_refresh_cursor';
            $afterId      = (int) get_option($cursorOption, 0);
            $active_users = $userInfoRepo->getActiveUserIds(30, 1000, $afterId);

            if (empty($active_users)) {
                // End of cursor — reset so the next run starts over.
                update_option($cursorOption, 0, false);
                return;
            }

            // Advance cursor to the largest returned id. If the batch was
            // short (< limit), reset to 0 so next run wraps to the start.
            $maxReturned = (int) max($active_users);
            $nextCursor  = count($active_users) < 1000 ? 0 : $maxReturned;
            update_option($cursorOption, $nextCursor, false);

            // Batch-fetch suspended user IDs to skip them.
            $suspended_ids = $userInfoRepo->getSuspendedUserIds($active_users);
            $suspended_set = array_flip($suspended_ids);

            // Time-budgeted processing: max 120 seconds to avoid cron timeout.
            $deadline  = time() + 120;
            $processed = 0;
            $affectedUserIds = [];

            foreach ($active_users as $user_id) {
                if (time() >= $deadline) {
                    \BCC\Core\Log\Logger::info('[bcc-trust] dailyFraudRefresh hit time budget', [
                        'processed' => $processed, 'total' => count($active_users),
                    ]);
                    break;
                }

                if (isset($suspended_set[(int) $user_id])) {
                    continue;
                }

                try {
                    $score = \BCC\Trust\Core\Security\FraudDetector::getEnhancedFraudScore((int) $user_id);
                } catch (\Throwable $e) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] Fraud refresh failed for user', [
                        'user_id' => $user_id, 'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                $processed++;
                $affectedUserIds[] = (int) $user_id;

                // Bust fraud + user_info + eligibility caches so subsequent
                // reads use the freshly-computed score and the eligibility
                // gate re-evaluates on the next vote attempt.
                \BCC\Trust\Core\Security\FraudDetector::clearCache((int) $user_id);
                \BCC\Trust\Core\Support\CacheManager::delete(
                    "user_info_{$user_id}",
                    'bcc_trust',
                    'daily_fraud_refresh'
                );
                \BCC\Trust\Core\Services\Vote\VoteEligibilityChecker::invalidateForUser((int) $user_id);

                if ($score >= BCC_TRUST_FRAUD_HIGH) {
                    AuditLogger::log('daily_fraud_high_risk', (int) $user_id, [
                        'fraud_score' => $score,
                    ], 'user');
                }
            }

            // Bust page data caches and mark pages dirty for read model
            // sync so trust scores reflect the updated fraud weights.
            // Without this, pages owned by fraud-affected users show
            // stale scores until the next vote/action triggers a sync.
            //
            // Single batched IN() query replaces the prior per-user loop
            // (N get_posts queries + N mark-dirty loops) — both cache-bust
            // and dirty-mark now share the same page id list, eliminating
            // the N+1 pattern that starved the cron on sites with >500
            // fraud-affected users.
            $pageIds = Plugin::instance()->scoreRepository()->getPageIdsByOwners($affectedUserIds);
            \BCC\Trust\Core\Services\PageDataLoader::bustMany($pageIds);
            foreach ($pageIds as $pid) {
                PageReadModelSync::markDirty((int) $pid);
            }
        } finally {
            $this->releaseLock('bcc_cron_fraud_refresh_lock');
        }
    }

    // ── Daily graph update (batch PageRank) ─────────────────────────────

    /**
     * Daily graph analysis: PageRank recalculation + ring detection.
     *
     * Merged from the former separate hourlyRingDetection job — both
     * operations walk the trust graph, so running them together avoids
     * loading the edge set twice.
     */
    public function dailyGraphUpdate(): void
    {
        if (!$this->acquireLock('bcc_cron_graph_lock', 2 * HOUR_IN_SECONDS)) {
            return;
        }

        try {
            $graph = Plugin::instance()->trustGraph();

            // PageRank
            $graph->batchCalculateAllRanks();

            // Ring / sybil detection (cache results for FraudDetector lookups)
            $ringMinSize = defined('BCC_TRUST_RING_MIN_SIZE') ? BCC_TRUST_RING_MIN_SIZE : 3;
            $rings       = $graph->getSuspiciousClusters($ringMinSize);
            // Shared cache key with AdminDashboardRepository::getRingsData()
            wp_cache_set('ring_results', $rings, 'bcc_trust_admin', DAY_IN_SECONDS);
        } finally {
            $this->releaseLock('bcc_cron_graph_lock');
        }
    }

    /**
     * Weekly slow-ring endorsement scan — scale-hardening Phase 3.
     *
     * Detects paced reciprocal-endorse rings that evade the existing
     * burst gates (3-in-300s, 6-in-1h, 3-pages-24h in EndorsementService)
     * by spreading endorsements over multiple days. Patience-evasion.
     *
     * Why a separate weekly cron (not merged into dailyGraphUpdate):
     *   1. Different cadence — slow rings by definition don't need daily
     *      detection; weekly is honest about the signal class.
     *   2. Different lock domain — daily graph update holds its lock for
     *      up to 2h; this scan should never block the daily graph work.
     *   3. Cheap enough to be free — TrustGraph caches the result for
     *      one hour anyway; a second daily caller would be a cache hit.
     *
     * Soft-flag posture: detectSlowEndorsementRings stores patterns +
     * writes audit logs but does NOT auto-increment fraud_score. Ops
     * escalates rings to action manually after reviewing the pattern.
     */
    public function weeklySlowRingScan(): void
    {
        if (!$this->acquireLock('bcc_cron_slow_ring_lock', HOUR_IN_SECONDS)) {
            return;
        }

        try {
            $graph = Plugin::instance()->trustGraph();

            $windowDays = defined('BCC_TRUST_SLOW_RING_WINDOW_DAYS')
                ? (int) BCC_TRUST_SLOW_RING_WINDOW_DAYS
                : 14;

            $rings = $graph->detectSlowEndorsementRings($windowDays);

            \BCC\Core\Log\Logger::info(
                '[bcc-trust] weekly slow-ring scan complete',
                [
                    'rings_found' => count($rings),
                    'window_days' => $windowDays,
                ]
            );
        } finally {
            $this->releaseLock('bcc_cron_slow_ring_lock');
        }
    }

    // ── Daily vote vesting ──────────────────────────────────────────────

    /**
     * Graduate votes through 5-stage vesting:
     *   0→1 at 30 days (10%→20%), 1→2 at 90 days (20%→50%),
     *   2→3 at 152 days (50%→75%), 3→4 at 365 days (75%→100%).
     */
    public function dailyVesting(): void
    {
        if (!$this->acquireLock('bcc_cron_vesting_lock', DAY_IN_SECONDS)) {
            return;
        }

        try {
        $batch_size = 1000;

        // Hard time budget: if the first run after launch (or any day with a
        // huge backlog) cannot drain every stage, resume on the next daily
        // tick instead of dragging the cron past its wall-clock limit.
        $deadline = time() + 300;

        // Each transition: [from_stage, to_stage, days_threshold, weight_pct, is_final]
        // Uses shared constants from trust-weights.php — single source of truth.
        $transitions = [
            [0, 1, BCC_TRUST_VESTING_STAGE_1_DAYS, BCC_TRUST_VESTING_STAGE_1_PCT, false],  // 10% → 20%
            [1, 2, BCC_TRUST_VESTING_STAGE_2_DAYS, BCC_TRUST_VESTING_STAGE_2_PCT, false],  // 20% → 50%
            [2, 3, BCC_TRUST_VESTING_STAGE_3_DAYS, BCC_TRUST_VESTING_STAGE_3_PCT, false],  // 50% → 75%
            [3, 4, BCC_TRUST_VESTING_STAGE_4_DAYS, BCC_TRUST_VESTING_STAGE_4_PCT, true],   // 75% → 100%
        ];

        foreach ($transitions as [$fromStage, $toStage, $days, $pct, $isFinal]) {
            if (time() >= $deadline) {
                break;
            }

            $cursorKey = "bcc_vesting_cursor_stage{$fromStage}";
            $cursor    = (int) get_option($cursorKey, 0);
            $affected  = 0;

            do {
                $result = $this->voteRepo->graduateVestingBatch(
                    $fromStage, $toStage, $days, $pct, $isFinal, $cursor, $batch_size
                );

                $affected = $result['affected'];
                $cursor   = $result['cursor'];

                if ($affected < 0) {
                    break; // DB error
                }

                // Persist cursor after each successful batch so a mid-run
                // crash does not lose progress or skip unprocessed votes.
                if ($affected > 0) {
                    update_option($cursorKey, $cursor, false);
                }

                if (time() >= $deadline) {
                    break 2; // Exit both loops; cursor persists for next run.
                }
            } while ($affected >= $batch_size);

            // Stage fully processed — remove cursor so next run starts fresh.
            // Guarded by `$affected < $batch_size` so a deadline-interrupted
            // stage (where the last batch was full) stays marked incomplete.
            if ($affected >= 0 && $affected < $batch_size) {
                delete_option($cursorKey);
            }
        }

        // Flag page scores for recalculation where votes just fully vested
        $this->voteRepo->flagScoresForFullyVestedVotes();

        // ── Endorsement vesting ────────────────────────────────────────
        // Graduate endorsements through Stage 0 → 1 → 2 and flag
        // affected pages for recalculation so endorsement_bonus is
        // re-derived from SUM(endorsements.weight) with the new weights.
        if (time() < $deadline) {
            try {
                $endorsementsGraduated = Plugin::instance()
                    ->endorsementService()
                    ->processEndorsementVesting();

                if ($endorsementsGraduated > 0) {
                    AuditLogger::log('daily_vesting_endorsements', 0, [
                        'endorsements_graduated' => $endorsementsGraduated,
                    ], 'system');
                }
            } catch (\Throwable $e) {
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error(
                        '[bcc-trust] Endorsement vesting failed during daily cron',
                        ['error' => $e->getMessage()]
                    );
                }
            }
        } else {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::info(
                    '[bcc-trust] dailyVesting hit time budget before endorsement stage — deferred to next run'
                );
            }
        }
        } finally {
            $this->releaseLock('bcc_cron_vesting_lock');
        }
    }

    // ── Nightly operator-reliability recompute (daily) ──────────────────

    /**
     * Recompute the operator-reliability cache (Slice 3).
     *
     * The AttestationOutcomeClassifier is expensive per operator, so the
     * /me/reliability + standing read paths memoize it in
     * bcc_attestor_reliability_cache. This sweep recomputes every active
     * attestor's row nightly: it pages forward by attestor user_id
     * (cursor option), classifies each operator, derives the §J.3.2
     * standing via ReliabilityStandingComputer::fromReliability, computes
     * the week-over-week trend, and upserts.
     *
     * Bounded + time-budgeted: batch size 200, $deadline = now + 300s.
     * The cursor persists each batch so a deadline-interrupted run resumes
     * next tick; a short final batch wraps the cursor to 0. Per-operator
     * failures are isolated (logged + counted) so one bad row never aborts
     * the sweep. The advisory lock is released in finally.
     */
    public function sweepAttestorReliability(): void
    {
        if (!$this->acquireLock('bcc_cron_reliability_sweep_lock', DAY_IN_SECONDS)) {
            return;
        }

        $plugin     = Plugin::instance();
        $attestRepo = $plugin->attestationRepository();
        $cacheRepo  = $plugin->attestorReliabilityCacheRepository();
        $classifier = $plugin->attestationOutcomeClassifier();

        $batchSize    = 200;
        $cursorOption = 'bcc_attestor_reliability_cursor';
        $cursor       = (int) get_option($cursorOption, 0);
        $deadline     = time() + 300;

        $highlyMin     = (float) apply_filters('bcc_reliability_highly_min', BCC_RELIABILITY_HIGHLY_MIN);
        $consistentMin = (float) apply_filters('bcc_reliability_consistent_min', BCC_RELIABILITY_CONSISTENT_MIN);
        $minForStanding = (int) apply_filters('bcc_reliability_min_for_standing', BCC_RELIABILITY_MIN_FOR_STANDING);
        $epsilon        = (float) apply_filters('bcc_reliability_trend_epsilon', BCC_RELIABILITY_TREND_EPSILON);

        $processed = 0;
        $failed    = 0;

        try {
            do {
                $userIds = $attestRepo->listDistinctAttestorIdsAfter($cursor, $batchSize);
                if ($userIds === []) {
                    // End of cursor — wrap to 0 so the next tick restarts.
                    if ($cursor > 0) {
                        update_option($cursorOption, 0, false);
                    }
                    break;
                }

                foreach ($userIds as $userId) {
                    try {
                        $this->recomputeAttestorReliabilityRow(
                            $userId,
                            $classifier,
                            $cacheRepo,
                            $highlyMin,
                            $consistentMin,
                            $minForStanding,
                            $epsilon
                        );
                        $processed++;
                    } catch (\Throwable $e) {
                        $failed++;
                        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                            \BCC\Core\Log\Logger::warning(
                                '[bcc-trust] reliability sweep: per-operator recompute failed',
                                ['user_id' => $userId, 'error' => $e->getMessage()]
                            );
                        }
                    }
                }

                // Advance + persist the cursor to the largest id returned so a
                // mid-run crash resumes instead of reprocessing.
                $cursor = (int) end($userIds);
                update_option($cursorOption, $cursor, false);

                if (count($userIds) < $batchSize) {
                    // Short batch — reached the end; wrap for the next run.
                    update_option($cursorOption, 0, false);
                    break;
                }

                if (time() >= $deadline) {
                    break; // cursor persisted; resume next tick.
                }
            } while (true);

            \BCC\Core\Log\Logger::info('[bcc-trust] Attestor reliability sweep complete', [
                'processed' => $processed,
                'failed'    => $failed,
                'cursor'    => $cursor,
            ]);
        } finally {
            $this->releaseLock('bcc_cron_reliability_sweep_lock');
        }
    }

    /**
     * Recompute + upsert one attestor's reliability cache row.
     *
     * Reads the existing cache row to resolve the week-over-week baseline:
     * when the baseline is unset or ≥ 7 days old, it rolls forward to the
     * PREVIOUS operator_reliability and re-stamps baseline_at=now. The
     * trend direction compares the freshly computed reliability against
     * that baseline within a ±$epsilon dead-band (steady), above
     * (improving), below (softening) — the §J.5 contract enum.
     */
    private function recomputeAttestorReliabilityRow(
        int $userId,
        \BCC\Trust\Core\Services\AttestationOutcomeClassifier $classifier,
        \BCC\Trust\Core\Repositories\AttestorReliabilityCacheRepository $cacheRepo,
        float $highlyMin,
        float $consistentMin,
        int $minForStanding,
        float $epsilon
    ): void {
        $classification = $classifier->classifyForAttestor($userId);
        $reliability    = $classification['reliability'];
        $countedCasts   = $classification['counted'];
        $totalCasts     = (int) ($classification['track_record']['total_attestations'] ?? 0);

        // Slice 4 — Early Read sub-tracks (SELF-ONLY). Persisted so a cache
        // HIT serves the same /me/reliability sub-track values as a MISS.
        $consensusReliability = (float) ($classification['consensus_reliability'] ?? 0.0);
        $earlyReadAccuracy    = (float) ($classification['early_read_accuracy'] ?? 0.0);

        // The outcome breakdown — persisted so a cache HIT serves the same
        // /me/reliability track_record as a cache MISS (live classify).
        $rawOutcomes = $classification['track_record']['outcomes'] ?? [];
        $outcomes = [
            'targets_disputed_and_upheld'           => (int) ($rawOutcomes['targets_disputed_and_upheld'] ?? 0),
            'targets_disputed_and_dismissed'        => (int) ($rawOutcomes['targets_disputed_and_dismissed'] ?? 0),
            'targets_received_further_attestations' => (int) ($rawOutcomes['targets_received_further_attestations'] ?? 0),
            'targets_clean_and_active'              => (int) ($rawOutcomes['targets_clean_and_active'] ?? 0),
        ];

        $standing = ReliabilityStandingComputer::fromReliability(
            $reliability,
            $countedCasts,
            $highlyMin,
            $consistentMin,
            $minForStanding
        );

        $now    = gmdate('Y-m-d H:i:s');
        $nowTs  = time();
        $sevenDays = 7 * DAY_IN_SECONDS;

        $existing = $cacheRepo->getByUserId($userId);

        $baseline   = $reliability;   // default for a first-ever row
        $baselineAt = $now;

        if ($existing !== null) {
            $prevReliability = isset($existing->operator_reliability)
                ? (float) $existing->operator_reliability
                : 0.0;
            $prevBaseline = isset($existing->reliability_baseline)
                ? (float) $existing->reliability_baseline
                : $prevReliability;
            $prevBaselineAtRaw = isset($existing->baseline_at) && is_string($existing->baseline_at) && $existing->baseline_at !== ''
                ? (string) $existing->baseline_at
                : null;

            $baselineAge = null;
            if ($prevBaselineAtRaw !== null) {
                $parsed = strtotime($prevBaselineAtRaw . ' UTC');
                if ($parsed !== false) {
                    $baselineAge = $nowTs - $parsed;
                }
            }

            if ($prevBaselineAtRaw === null || $baselineAge === null || $baselineAge >= $sevenDays) {
                // Roll the baseline forward to the previous reliability and
                // re-stamp. This makes the trend a week-over-week comparison.
                $baseline   = $prevReliability;
                $baselineAt = $now;
            } else {
                // Within the current week — keep the standing baseline.
                $baseline   = $prevBaseline;
                $baselineAt = $prevBaselineAtRaw;
            }
        }

        // Direction enum is the §J.5 / api-contract-v1.md contract value:
        // "softening" | "steady" | "improving" (NOT "rising").
        $trend = 'steady';
        $delta = $reliability - $baseline;
        if ($delta > $epsilon) {
            $trend = 'improving';
        } elseif ($delta < -$epsilon) {
            $trend = 'softening';
        }

        $cacheRepo->upsert(
            $userId,
            $reliability,
            $standing,
            $totalCasts,
            $trend,
            $baseline,
            $baselineAt,
            $outcomes,
            $now,
            $consensusReliability,
            $earlyReadAccuracy
        );
    }

    // ── Process flagged recalculations (every 5 min) ────────────────────

    /** Maximum time budget per cron run (seconds). */
    private const RECALC_TIME_BUDGET = 120;

    /** Pages to fetch per DB round-trip. */
    private const RECALC_FETCH_SIZE = 100;

    /** After this many consecutive failures, stop retrying automatically. */
    private const RECALC_MAX_FAILURES = 3;

    /**
     * Process all flagged pages within a time budget.
     *
     * Adaptive batching: instead of a fixed LIMIT, fetches pages in chunks
     * and processes until the time budget is exhausted or the queue is empty.
     * This guarantees that a backlog of 200+ pages from a vesting batch is
     * cleared in a single cron run (≤2 minutes) rather than queuing across
     * 4+ runs over 20 minutes.
     *
     * Failure tracking: pages that throw during recalculation get their
     * recalc_failures counter incremented. After RECALC_MAX_FAILURES (3)
     * consecutive failures, the page is removed from the queue and logged.
     * This prevents one broken page from consuming a queue slot forever.
     *
     * Max drift guarantee: 10 minutes. Cron fires every 5 minutes.
     * Each run processes the full queue (time-bounded). A page flagged at
     * T=0 is processed by T=5 (next cron) or T=10 (if prior run was busy).
     */
    public function processRecalculations(): void
    {
        if (!$this->acquireLock('bcc_recalc_lock', 300)) {
            return;
        }

        try {
            $voteService = Plugin::instance()->voteService();
            $deadline    = time() + self::RECALC_TIME_BUDGET;
            $processed   = 0;
            $failed      = 0;
            $quarantined = 0;

            // Process in chunks until queue is empty or time budget exhausted
            do {
                $pageIds = $this->scoreRepo->getFlaggedPageIds(
                    self::RECALC_MAX_FAILURES,
                    self::RECALC_FETCH_SIZE
                );

                if (empty($pageIds)) {
                    break;
                }

                foreach ($pageIds as $pageId) {
                    if (time() >= $deadline) {
                        break 2; // Exit both loops — time's up
                    }

                    try {
                        $voteService->recalculateScore((int) $pageId, true);
                        do_action('bcc_trust_score_recalculated', (int) $pageId);
                        $processed++;

                    } catch (\Exception $e) {
                        $failed++;

                        // Increment failure counter
                        $this->scoreRepo->incrementRecalcFailures((int) $pageId);

                        // Check if page has hit the failure threshold
                        $failures = $this->scoreRepo->getRecalcFailures((int) $pageId);

                        if ($failures >= self::RECALC_MAX_FAILURES) {
                            // Quarantine: remove from queue, log for investigation
                            $this->scoreRepo->clearRecalcFlag((int) $pageId);
                            $quarantined++;

                            AuditLogger::log('recalc_quarantined', (int) $pageId, [
                                'failures'  => $failures,
                                'last_error' => substr($e->getMessage(), 0, 255),
                            ], 'system');

                            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                                \BCC\Core\Log\Logger::error(
                                    '[bcc-trust] Page quarantined from recalculation queue',
                                    [
                                        'page_id'    => (int) $pageId,
                                        'failures'   => $failures,
                                        'error'      => $e->getMessage(),
                                    ]
                                );
                            }
                        }
                    }
                }
            } while (time() < $deadline);

            // Log batch summary if anything happened
            if ($processed > 0 || $failed > 0) {
                AuditLogger::log('recalc_batch_complete', 0, [
                    'processed'   => $processed,
                    'failed'      => $failed,
                    'quarantined' => $quarantined,
                    'time_used'   => time() + self::RECALC_TIME_BUDGET - $deadline,
                ], 'system');
            }

            // Heartbeat: record that the recalc cron completed successfully.
            // The bcc-core /system/ping endpoint reads this timestamp to
            // detect stalled cron (>15 min stale triggers HTTP 503).
            update_option('bcc_trust_last_recalc_run', time(), false);
        } finally {
            $this->releaseLock('bcc_recalc_lock');
        }
    }

    // ── Hot-feed warm (anon first page) ─────────────────────────────────

    /**
     * Minutely warm of the anonymous /feed/hot first page (§F2).
     *
     * Rebuilds the post-hydration `{items, pagination}` payload and
     * stores it unconditionally (refresh even on hit), so anonymous
     * cold hits never pay the inline build (~20s measured cold on
     * Local — docs/capacity-model.md baseline).
     *
     * No DegradationMetric subsystem here — deliberate scope cut: the
     * failure mode is benign (requests fall back to the inline build)
     * and a new subsystem would require the cross-repo canonical-map +
     * docs registration dance.
     */
    public function warmHotFeed(): void
    {
        try {
            Plugin::instance()->feedRankingService()->warmHotFeed(20);
        } catch (\Throwable $e) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] hot-feed warm failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ── Cron scheduling ─────────────────────────────────────────────────

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function addCronIntervals(array $schedules): array
    {
        // V2 Phase 1a: NftEthIndexerWorker ticks every minute. Tighter
        // than wp-core's 5-min default because chain indexing is
        // time-sensitive — also why the production-cron staleness
        // detector runs with a 5-min threshold instead of 15.
        if (!isset($schedules['bcc_one_minute'])) {
            $schedules['bcc_one_minute'] = [
                'interval' => 60,
                'display'  => 'Every Minute',
            ];
        }
        if (!isset($schedules['bcc_five_minutes'])) {
            $schedules['bcc_five_minutes'] = [
                'interval' => 300,
                'display'  => 'Every 5 Minutes',
            ];
        }
        // §I1 weekly digest cadence. WP core registered `weekly` only
        // recently and inconsistently across versions — registering our
        // own keeps the schedule stable.
        if (!isset($schedules['bcc_weekly'])) {
            $schedules['bcc_weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => 'Once Weekly (BCC)',
            ];
        }
        return $schedules;
    }

    public static function scheduleAll(): void
    {
        $jobs = [
            'bcc_trust_daily_cleanup'          => 'daily',    // cleanup + archive + fraud decay
            'bcc_trust_hourly_recalc'          => 'hourly',   // stale page recalc
            'bcc_trust_daily_ml_update'        => 'daily',    // daily fraud refresh (renamed from ML)
            'bcc_trust_daily_graph_update'     => 'daily',    // PageRank + ring detection (merged)
            'bcc_trust_daily_vesting'          => 'daily',    // vote/endorsement vesting
            'bcc_trust_process_recalculations' => 'bcc_five_minutes', // recalc queue processor
            'bcc_trust_daily_maintenance'      => 'daily',            // read model sync safety net
            'bcc_trust_weekly_digest'          => 'bcc_weekly',       // §I1 email digest
            'bcc_trust_weekly_slow_ring_scan'  => 'bcc_weekly',       // scale-hardening: slow endorsement-ring detection
            'bcc_trust_daily_contribution_recovery' => 'daily',      // trust recovery through contribution
            'bcc_attestor_reliability_sweep'   => 'daily',           // Slice 3: nightly operator-reliability recompute
            'bcc_trust_feed_hot_warm'          => 'bcc_one_minute',  // anon /feed/hot first-page payload warm
        ];

        // Clear retired hooks so they don't fire orphaned actions.
        $retired = [
            'bcc_trust_hourly_graph_update',    // legacy alias for daily_graph_update — @retire-after: production confirms zero scheduled instances for 30+ days
            'bcc_trust_hourly_ring_detection',  // merged into daily_graph_update
            'bcc_trust_hourly_risk_refresh',    // user_risk table merged into user_info
            'bcc_trust_backfill_edges',         // fresh system, no history to backfill
            'bcc_trust_archive_activity_event', // merged into daily_cleanup
        ];
        foreach ($retired as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        foreach ($jobs as $hook => $interval) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time(), $interval, $hook);
            }
        }
    }

    public static function maybeReschedule(): void
    {
        if (get_option('bcc_trust_cron_version') !== BCC_TRUST_VERSION) {
            self::scheduleAll();
            update_option('bcc_trust_cron_version', BCC_TRUST_VERSION, false);
        }
    }

    // ── Cache invalidation hooks ────────────────────────────────────────

    public static function registerCacheInvalidation(): void
    {
        add_action('bcc_trust_vote_cast', function ($voterId, $pageId) {
            PageDataLoader::bust((int) $pageId);
        }, 10, 2);

        add_action('bcc_trust_vote_removed', function ($voterId, $pageId) {
            PageDataLoader::bust((int) $pageId);
        }, 10, 2);

        add_action('bcc_trust_vote_changed', function ($voterId, $pageId) {
            PageDataLoader::bust((int) $pageId);
        }, 10, 2);

        // Priority 5: run BEFORE the onchain-signals WalletSeedService listener
        // (priority 10) which makes network calls that could fail/throw.
        // This ensures the onchain_signals row and cache bust always happen
        // even if downstream seed calls hit a timeout.
        add_action('bcc_wallet_verified', function ($userId, $chain = '', $address = '') {
            try {
                PageDataLoader::bustForUser((int) $userId);

                // Ensure an onchain_signals row exists so wallets verified via
                // the Onchain domain's AJAX path still appear in trust scoring.
                // Without this, only wallets verified through the Core domain's
                // REST path would have scoring rows.
                if ($chain && $address) {
                    $existing = WalletSignalRepository::getForUserChain((int) $userId, $chain);
                    if (!$existing) {
                        WalletSignalRepository::upsert(
                            (int) $userId,
                            $chain,
                            $address,
                            'none',  // No contract_address available from hook — role stays 'none'
                            0.0,
                            0
                        );
                    }
                }
            } catch (\Throwable $e) {
                if (class_exists('BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] wallet_verified listener failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }, 5, 3);

        // When a wallet is disconnected via the onchain-signals UI,
        // zero the scoring row so trust scores update immediately.
        add_action('bcc_wallet_disconnected', function ($userId, $chain) {
            WalletSignalRepository::disconnect((int) $userId, $chain);
            PageDataLoader::bustForUser((int) $userId);
        }, 10, 2);

        add_action('save_post', function ($postId, $post) {
            if (in_array($post->post_type, ['peepso-page', 'post', 'page'], true)) {
                PageDataLoader::bust((int) $postId);
            }
        }, 10, 2);
    }

    // ── Admin notices ───────────────────────────────────────────────────

    public static function systemRequirementsNotice(): void
    {
        global $wp_version;
        if (version_compare($wp_version, '5.8', '<')) {
            echo '<div class="notice notice-warning"><p>';
            echo 'BCC Trust Engine recommends WordPress 5.8 or higher for optimal performance.';
            echo '</p></div>';
        }

        $table_exists = Plugin::instance()->scoreRepository()->tableExists();
        if (!$table_exists) {
            echo '<div class="notice notice-error"><p>';
            echo 'BCC Trust Engine database tables were not created. Please deactivate and reactivate the plugin.';
            echo '</p></div>';
        }

        // Warn if DISABLE_WP_CRON is set but critical cron events haven't fired.
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $criticalHook = 'bcc_trust_process_recalculations';
            $nextScheduled = wp_next_scheduled($criticalHook);
            // If the event is overdue by more than 15 minutes, system cron is likely missing.
            if ($nextScheduled && $nextScheduled < (time() - 900)) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>BCC Trust Engine:</strong> ';
                echo 'DISABLE_WP_CRON is enabled but the score recalculation cron is overdue. ';
                echo 'Trust scores will not update until a system cron is configured. ';
                echo 'Add to your server crontab: <code>*/5 * * * * curl -s ';
                echo esc_html(site_url('/wp-cron.php?doing_wp_cron'));
                echo ' >/dev/null 2>&1</code>';
                echo '</p></div>';
            }
        }
    }

    // ── Private helpers ─────────────────────────────────────────────────

    /**
     * Acquire a MySQL advisory lock (atomic, session-scoped).
     *
     * Unlike the previous transient-based lock, GET_LOCK is atomic:
     * two concurrent processes cannot both acquire the same lock.
     * The lock is automatically released if the PHP process crashes.
     *
     * @param string $key Lock name (max 64 chars).
     * @param int    $ttl Unused — advisory locks are session-scoped. Kept for API compat.
     */
    private function acquireLock(string $key, int $ttl = 0): bool
    {
        return \BCC\Core\DB\AdvisoryLock::acquire($key, 0);
    }

    private function releaseLock(string $key): void
    {
        \BCC\Core\DB\AdvisoryLock::release($key);
    }

}
