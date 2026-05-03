<?php
/**
 * Auto-Hide Service — §K1 Phase C threshold-based content hiding.
 *
 * Subscribes to `bcc_content_reported`. After each report lands, counts
 * the pending reports against that target; once the count crosses the
 * configured threshold, marks the activity hidden via
 * HiddenActivityRepository and emits `bcc_content_auto_hidden` for
 * downstream subscribers (notification dispatcher, audit log).
 *
 * Idempotent: if the row is already hidden the threshold check
 * short-circuits — no double events, no double DB writes.
 *
 * Threshold:
 *   - Default: 3 (three distinct reporters).
 *   - Override: `wp_options['bcc_autohide_threshold']` (positive int).
 *   - Disable: set to 0 (still allows admin Hide; just won't auto).
 *
 * Out of scope (V1):
 *   - Reporter trust-weighting (one report = one count regardless of tier)
 *   - Per-reason thresholds (spam might warrant 5, hate 1) — V1.5 work
 *   - Cool-down / reversal (admin must explicitly Restore)
 *
 * §A4 single-source-of-trust: this service does NOT decide *what is bad
 * content*. It counts reports the community filed and applies the
 * threshold rule. The taxonomy + the action live in ContentReportService
 * (taxonomy) + this service + the admin queue (manual override).
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §K1 Phase C)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Repositories\ContentReportRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class AutoHideService
{
    public const DEFAULT_THRESHOLD = 3;

    public const REASON_AUTOHIDE_THRESHOLD = 'autohide_threshold';

    public function __construct(
        private readonly ContentReportRepository $reportRepo,
        private readonly HiddenActivityRepository $hiddenRepo
    ) {
    }

    /**
     * Subscriber for bcc_content_reported.
     *
     * Fired with: ($reporterUserId, $targetKind, $targetId, $reportId, $reasonCode).
     * V1 only acts on `target_kind === 'feed_item'` — future kinds need
     * their own hide path (e.g. profile pages don't live in
     * peepso_activities).
     */
    public function onContentReported(
        int $reporterUserId,
        string $targetKind,
        int $targetId,
        int $reportId,
        string $reasonCode
    ): void {
        unset($reporterUserId, $reasonCode); // not needed for the
                                              // count-and-hide decision

        if ($targetKind !== 'feed_item' || $targetId <= 0) {
            return;
        }

        $threshold = self::resolveThreshold();
        if ($threshold <= 0) {
            return; // Auto-hide disabled by config.
        }

        // Already hidden? Bail before the count query.
        if ($this->hiddenRepo->exists($targetId)) {
            return;
        }

        $count = $this->reportRepo->countPendingForTarget($targetKind, $targetId);
        if ($count < $threshold) {
            return;
        }

        $added = $this->hiddenRepo->add(
            $targetId,
            0, // auto-hide rows have hidden_by_user_id = 0
            self::REASON_AUTOHIDE_THRESHOLD,
            $reportId > 0 ? $reportId : null
        );

        if (!$added) {
            Logger::warning('[AutoHideService] failed to mark hidden', [
                'act_id'        => $targetId,
                'pending_count' => $count,
                'threshold'     => $threshold,
            ]);
            return;
        }

        Logger::info('[AutoHideService] activity auto-hidden', [
            'act_id'        => $targetId,
            'pending_count' => $count,
            'threshold'     => $threshold,
            'related_report_id' => $reportId,
        ]);

        // §A3 event bus — Phase C+ subscribers (notification dispatcher
        // for the post author, audit logger) attach independently.
        do_action('bcc_content_auto_hidden', $targetId, $count, $threshold, $reportId);
    }

    /**
     * Read the configurable threshold. wp_options is the V1 store —
     * admin-tunable from a settings page later. Defends against
     * negative / non-numeric values by falling back to the default.
     */
    private static function resolveThreshold(): int
    {
        $raw = get_option('bcc_autohide_threshold', self::DEFAULT_THRESHOLD);
        if (!is_numeric($raw)) {
            return self::DEFAULT_THRESHOLD;
        }
        $val = (int) $raw;
        // Negative values are nonsense; 0 disables. Values of 1 are
        // legitimate ("one report hides it") for high-stakes contexts.
        return max(0, $val);
    }
}
