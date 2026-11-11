<?php
/**
 * Auto-Hide Service — §K1 Phase C threshold-based content hiding.
 *
 * Subscribes to `bcc_content_reported`. After each report lands, counts
 * the pending reports against that target; once the count crosses the
 * configured threshold, marks the activity hidden via
 * HiddenActivityRepository and emits `bcc_content_auto_hidden`. The
 * audit-log consumer for that event is wired in Plugin.php (one
 * bcc_trust_activity row per auto-hide); a notification consumer for
 * the post author is intentionally NOT wired in V1.
 *
 * Idempotent: if the row is already hidden the threshold check
 * short-circuits — no double events, no double DB writes.
 *
 * Threshold:
 *   - Default: 3 (three distinct CREDIBLE reporters — see below).
 *   - Override: `wp_options['bcc_autohide_threshold']` (positive int).
 *   - Disable: set to 0 (still allows admin Hide; just won't auto).
 *
 * Reporter gating (F-73): only reporters passing
 * RankCredibilityGate::isCredible() count toward the threshold; every
 * report still files and queues.
 *
 * Out of scope (V1):
 *   - Reporter trust-WEIGHTING (a credible report = one count, any tier)
 *   - Per-reason thresholds (spam might warrant 5, hate 1) — V1.5 work
 *   - Reversal (admin must explicitly Restore). Since F-73 a Restore
 *     closes every pending report on the target and starts a
 *     REHIDE_COOLDOWN_SECONDS window in which auto-hide is withheld.
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
use BCC\Trust\Core\Announcements\AnnouncementModerationPolicy;
use BCC\Trust\Core\Repositories\ContentReportRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class AutoHideService
{
    public const DEFAULT_THRESHOLD = 3;

    public const REASON_AUTOHIDE_THRESHOLD = 'autohide_threshold';

    /**
     * F-73 re-hide cooldown (product decision 2026-11-10): after an admin
     * RESTORE, the post is immune from AUTOMATIC hiding for this long.
     * Reports still file and still queue for admins; only the automatic
     * action is withheld. An admin can still Hide at any time.
     */
    public const REHIDE_COOLDOWN_SECONDS = 7 * 86400;

    /**
     * Post meta on the activity's backing wp_post holding the cooldown's
     * expiry (unix seconds). PERSISTED, not a transient: an object-cache
     * eviction must not silently end the cooldown. '0' = no cooldown.
     */
    public const META_REHIDE_COOLDOWN_UNTIL = '_bcc_autohide_cooldown_until';

    public function __construct(
        private readonly ContentReportRepository $reportRepo,
        private readonly HiddenActivityRepository $hiddenRepo,
        private readonly \BCC\Trust\Rank\Services\RankCredibilityGate $credibility
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

        // Cheap pre-filter: credible reporters are a subset of all pending
        // reporters, so below-threshold totals can never hide.
        $pending = $this->reportRepo->countPendingForTarget($targetKind, $targetId);
        if ($pending < $threshold) {
            return;
        }

        // F-73 — only CREDIBLE reporters count toward the threshold: the
        // same RankCredibilityGate::isCredible() rule that decides who
        // counts as Rank evidence (Apprentice+, Trust tier Neutral+, not
        // suspended, fraud-clear). Anyone may still FILE a report — it
        // queues for admins — but brand-new / throwaway accounts can no
        // longer hide a post on their own. Stops counting once the
        // threshold is reached.
        $count = 0;
        foreach ($this->reportRepo->listPendingReporterIdsForTarget($targetKind, $targetId) as $reporterId) {
            if ($this->credibility->isCredible($reporterId)) {
                $count++;
                if ($count >= $threshold) {
                    break;
                }
            }
        }
        if ($count < $threshold) {
            Logger::info('[AutoHideService] auto-hide withheld: credible reporters below threshold', [
                'act_id'          => $targetId,
                'pending_count'   => $pending,
                'credible_count'  => $count,
                'threshold'       => $threshold,
            ]);
            return;
        }

        // F-73 — an admin restored this post recently: the community's
        // reports keep queueing, but the automatic hide is withheld until
        // the cooldown ends. Without it, one fresh report could re-hide a
        // post an admin had just judged fine. Checked only once the
        // threshold is crossed, so the extra lookup is rare.
        if (self::inRehideCooldown($targetId)) {
            Logger::info('[AutoHideService] auto-hide withheld: restore cooldown', [
                'act_id'        => $targetId,
                'pending_count' => $count,
                'threshold'     => $threshold,
            ]);
            return;
        }

        // §4.32.16 — ANNOUNCEMENTS ARE NEVER AUTOMATICALLY HIDDEN.
        //
        // Checked HERE rather than at the top of the method on purpose:
        // this is the last gate before the only write, so the extra
        // act-id -> module lookup runs only when a hide is actually about
        // to happen (rare) instead of on every report (common). The
        // guarantee is identical either way -- nothing is hidden.
        $autoHide = AnnouncementModerationPolicy::autoHideDecision($targetId);
        if ($autoHide !== AnnouncementModerationPolicy::AUTO_HIDE_ALLOWED) {
            if ($autoHide === AnnouncementModerationPolicy::AUTO_HIDE_BLOCKED_ANNOUNCEMENT) {
                // Ordinary policy, not a fault. The report stays filed and
                // the admin queue still shows it; only the automatic
                // action is withheld.
                Logger::info('[AutoHideService] auto-hide withheld: announcement', [
                    'act_id'        => $targetId,
                    'pending_count' => $count,
                    'threshold'     => $threshold,
                ]);
            } else {
                // ⚠ The module could not be read, so this activity cannot
                // be identified -- and an unidentifiable activity is not
                // one to hide. WARNING, because a persistent occurrence is
                // a fault rather than a decision.
                Logger::warning('[AutoHideService] auto-hide withheld: module unresolved', [
                    'act_id'        => $targetId,
                    'pending_count' => $count,
                    'threshold'     => $threshold,
                ]);
            }
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

        // §A3 event bus. The audit-logger consumer is wired in Plugin.php
        // (writes one content_auto_hidden audit row). A notification
        // consumer for the post author is intentionally NOT wired in V1.
        do_action('bcc_content_auto_hidden', $targetId, $count, $threshold, $reportId);
    }

    /**
     * Start the re-hide cooldown for an activity (called by the admin
     * Restore). Returns false when the activity's backing post cannot be
     * resolved — the restore still stands; only the cooldown is absent.
     */
    public static function startRehideCooldown(int $actId): bool
    {
        $postId = self::backingPostId($actId);
        if ($postId <= 0) {
            return false;
        }
        return update_post_meta(
            $postId,
            self::META_REHIDE_COOLDOWN_UNTIL,
            (string) (time() + self::REHIDE_COOLDOWN_SECONDS)
        ) !== false;
    }

    /** End the cooldown early (undo of a Restore). */
    public static function clearRehideCooldown(int $actId): void
    {
        $postId = self::backingPostId($actId);
        if ($postId > 0) {
            update_post_meta($postId, self::META_REHIDE_COOLDOWN_UNTIL, '0');
        }
    }

    public static function inRehideCooldown(int $actId): bool
    {
        $postId = self::backingPostId($actId);
        if ($postId <= 0) {
            return false;
        }
        $until = get_post_meta($postId, self::META_REHIDE_COOLDOWN_UNTIL, true);
        return is_numeric($until) && (int) $until > time();
    }

    /** The activity's wp_post id (act_external_id), 0 when unresolvable. */
    private static function backingPostId(int $actId): int
    {
        if ($actId <= 0) {
            return 0;
        }
        $row = \BCC\Core\Repositories\PeepSoActivityRepository::getById($actId);
        return $row !== null ? (int) ($row->act_external_id ?? 0) : 0;
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
