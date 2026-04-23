<?php

namespace BCC\Trust\Disputes\Services;

use BCC\Trust\Disputes\Repositories\DisputeRepository;
use WP_User;

if (!defined('ABSPATH')) {
    exit;
}

class DisputeNotificationService
{
    /**
     * Register async action handlers for deferred email notifications.
     * Called once during plugin boot (init hook).
     */
    public static function registerAsyncHandlers(): void
    {
        add_action('bcc_disputes_notify_panelist', function (int $uid, int $dispute_id, int $page_id) {
            self::notifyPanelist($uid, $dispute_id, $page_id);
        }, 10, 3);

        add_action('bcc_disputes_email_reported_user', function (int $report_id, int $reported_user_id) {
            $user = get_userdata($reported_user_id);
            if ($user) {
                self::emailReportedUser($report_id, $user);
            }
        }, 10, 2);

        add_action('bcc_disputes_email_admin_report', function (int $report_id, int $reporter_id, int $reported_user_id, string $reason_key, string $reason_detail) {
            $reported_user = get_userdata($reported_user_id);
            if ($reported_user) {
                self::emailAdminReport($report_id, $reporter_id, $reported_user, $reason_key, $reason_detail);
            }
        }, 10, 5);

        add_action('bcc_disputes_email_reporter_result', function (int $dispute_id, int $reporter_id, string $outcome) {
            self::emailReporterResult($dispute_id, $reporter_id, $outcome);
        }, 10, 3);
    }

    /**
     * Enqueue an async action under the 'bcc-disputes' Action Scheduler group.
     *
     * Thin convenience wrapper over {@see \BCC\Core\Cron\AsyncDispatcher::enqueueAsync()}
     * that pins the AS group label so dispute callers do not have to repeat it.
     * The list-vs-array assertion and the AS / wp-cron fallback are inherited
     * from the core dispatcher.
     *
     * @param list<mixed> $args MUST be a zero-indexed, gap-free list — see
     *                          AsyncDispatcher for why this is enforced.
     * @return bool True if the backend accepted the enqueue, false on soft
     *              failure. Callers MUST check this — a false return means
     *              no worker will run unless a reconciliation sweep finds it
     *              via its idempotency marker (e.g. notified_at IS NULL,
     *              status='reviewing').
     */
    public static function enqueueAsync(string $hook, array $args): bool
    {
        return \BCC\Core\Cron\AsyncDispatcher::enqueueAsync($hook, $args, 'bcc-disputes');
    }

    // ── Mail-failure metrics (hourly buckets) ─────────────────────────────

    /**
     * Storage prefix for hourly mail-failure counters.
     *
     * Bucket key format: bcc_mail_failures_YYYYMMDDHH (UTC). With a
     * persistent object cache, wp_cache_incr is used atomically so high-
     * concurrency outages produce an accurate count. Without a persistent
     * cache, falls back to the transient read-modify-write path (non-atomic
     * but functional).
     */
    private const MAIL_FAILURE_KEY_PREFIX = 'bcc_mail_failures_';
    private const MAIL_FAILURE_CACHE_GROUP = 'bcc_mail_failures';
    private const MAIL_FAILURE_TTL         = 7200; // 2 hours

    /**
     * Build the counter key for a given UTC hour.
     */
    private static function mailFailureKey(int $timestamp): string
    {
        return self::MAIL_FAILURE_KEY_PREFIX . gmdate('YmdH', $timestamp);
    }

    /**
     * Record one wp_mail failure in the current UTC-hour bucket.
     *
     * Atomic under a persistent object cache (wp_cache_incr → Redis INCR).
     * Transient fallback is non-atomic but functional — documented trade-off
     * for sites without Redis/Memcached.
     */
    public static function recordMailFailure(string $type): void
    {
        $key     = self::mailFailureKey(time());
        $newCount = 0;

        if (wp_using_ext_object_cache()) {
            // wp_cache_add seeds the bucket atomically on first failure;
            // returns false when the key already exists. wp_cache_incr then
            // performs an atomic Redis INCR on subsequent failures.
            if (wp_cache_add($key, 1, self::MAIL_FAILURE_CACHE_GROUP, self::MAIL_FAILURE_TTL)) {
                $newCount = 1;
            } else {
                $incr = wp_cache_incr($key, 1, self::MAIL_FAILURE_CACHE_GROUP);
                if ($incr === false) {
                    // Cache backend hiccup — fall through to transient so the
                    // increment is not lost entirely.
                    $current = (int) get_transient($key);
                    $newCount = $current + 1;
                    set_transient($key, $newCount, self::MAIL_FAILURE_TTL);
                } else {
                    $newCount = (int) $incr;
                }
            }
        } else {
            // No persistent cache: non-atomic RMW on the transient.
            $current = (int) get_transient($key);
            $newCount = $current + 1;
            set_transient($key, $newCount, self::MAIL_FAILURE_TTL);
        }

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[bcc-disputes] mail_failure_recorded', [
                'type'   => $type,
                'bucket' => $key,
                'count'  => $newCount,
            ]);
        }
    }

    /**
     * Read the count for a given bucket. Checks wp_cache first (authoritative
     * when persistent cache is active) and falls back to transient otherwise.
     */
    private static function readMailFailureBucket(string $key): int
    {
        if (wp_using_ext_object_cache()) {
            $cached = wp_cache_get($key, self::MAIL_FAILURE_CACHE_GROUP);
            if ($cached !== false) {
                return (int) $cached;
            }
        }
        $value = get_transient($key);
        return $value === false ? 0 : (int) $value;
    }

    /**
     * Failures recorded in the current UTC hour bucket. Missing key → 0.
     */
    public static function getMailFailuresLastHour(): int
    {
        return self::readMailFailureBucket(self::mailFailureKey(time()));
    }

    /**
     * Failures recorded in the previous UTC hour bucket. Missing key → 0.
     */
    public static function getMailFailuresPrevHour(): int
    {
        return self::readMailFailureBucket(self::mailFailureKey(time() - HOUR_IN_SECONDS));
    }

    public static function notifyPanelist(int $uid, int $dispute_id, int $page_id): void
    {
        // Claim-before-send: flip notified_at from NULL → $ts atomically BEFORE
        // calling wp_mail. Two concurrent AS workers scheduled for the same
        // (dispute_id, panelist_user_id) cannot both pass the claim, so only one
        // worker actually sends. A previous check-then-mail-then-mark pattern
        // had a TOCTOU gap: both workers read NULL, both sent wp_mail, then
        // one lost the mark race. Under AS replay / reconcile overlap that
        // produced duplicate panelist emails.
        $ts = gmdate('Y-m-d H:i:s');
        if (!DisputeRepository::markPanelistNotified($dispute_id, $uid, $ts)) {
            // Someone else already claimed or completed the send.
            return;
        }

        $user = get_userdata($uid);
        if (!$user || !$user->user_email) {
            // Nothing to send — keep the claim so we don't thrash AS retries
            // on a permanently-unresolvable user. The claim row is a tombstone
            // for "no mail possible".
            return;
        }
        $page = get_post($page_id);
        $subject = '[BCC] You have been selected as a dispute panelist';
        $body = sprintf(
            "Hello %s,\n\nA dispute has been filed against a vote on \"%s\". As a Gold/Platinum member, you've been selected to help review it.\n\nLog in and visit your dispute queue to cast your vote within %d days.\n\nDispute ID: #%d\n",
            $user->display_name,
            $page ? $page->post_title : "a project page",
            BCC_DISPUTES_TTL_DAYS,
            $dispute_id
        );

        // Throw on wp_mail failure so Action Scheduler retries with backoff.
        // Previously we logged-and-returned, which marked the action as
        // "successful" in AS, permanently losing the notification. Throwing
        // here is the correct AS failure-protocol: AS will retry up to its
        // configured limit before giving up.
        $sent = false;
        try {
            $sent = (bool) wp_mail($user->user_email, $subject, $body);
        } finally {
            if (!$sent) {
                // Release our claim so the next AS retry (or reconciliation
                // sweep) can re-attempt. Scoped to $ts so we never clear a
                // concurrent successful send's marker.
                DisputeRepository::clearPanelistNotified($dispute_id, $uid, $ts);
            }
        }

        if (!$sent) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] wp_mail failed', [
                'type' => 'panelist_notification',
                'user_id' => $uid,
                'dispute_id' => $dispute_id,
            ]);
            self::recordMailFailure('panelist_notification');
            throw new \RuntimeException(
                "wp_mail failed for panelist notification (dispute={$dispute_id}, user={$uid}) — Action Scheduler will retry"
            );
        }
    }

    public static function emailReportedUser(int $report_id, WP_User $reported_user): void
    {
        // Claim-before-send: see notifyPanelist() for rationale.
        $ts = gmdate('Y-m-d H:i:s');
        if (!DisputeRepository::markReportNotified($report_id, $ts)) {
            return;
        }

        $site_name = get_bloginfo('name');
        $subject   = sprintf('[%s] Your account has received a report', $site_name);
        $body      = sprintf(
            "Hello %s,\n\nWe wanted to let you know that a report has been submitted regarding your account on %s.\n\nOur moderation team will review the report and take appropriate action if necessary. No action is required from you at this time.\n\nIf you believe this report was made in error, you can contact us by replying to this email.\n\nThe %s Team",
            $reported_user->display_name,
            $site_name,
            $site_name
        );

        $sent = false;
        try {
            $sent = (bool) wp_mail($reported_user->user_email, $subject, $body);
        } finally {
            if (!$sent) {
                DisputeRepository::clearReportNotified($report_id, $ts);
            }
        }

        if (!$sent) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] wp_mail failed', [
                'type' => 'reported_user_notification',
                'report_id' => $report_id,
            ]);
            self::recordMailFailure('reported_user_notification');
            throw new \RuntimeException(
                "wp_mail failed for reported-user notification (report={$report_id}) — Action Scheduler will retry"
            );
        }
    }

    public static function emailAdminReport(int $report_id, int $reporter_id, WP_User $reported_user, string $reason_key, string $reason_detail): void
    {
        // Claim-before-send: see notifyPanelist() for rationale. Duplicate
        // admin emails previously leaked via the check-then-mark TOCTOU and
        // looked like a spam attack to moderators.
        $ts = gmdate('Y-m-d H:i:s');
        if (!DisputeRepository::markAdminReportNotified($report_id, $ts)) {
            return;
        }

        $admin_email   = get_option('admin_email');
        $site_name     = get_bloginfo('name');
        $reporter      = get_userdata($reporter_id);
        $admin_url     = admin_url('admin.php?page=bcc-reports');

        $reason_labels = [
            'spam'            => 'Spam or unsolicited content',
            'harassment'      => 'Harassment or bullying',
            'fraud'           => 'Fraudulent activity or scam',
            'misinformation'  => 'False or misleading information',
            'inappropriate'   => 'Inappropriate content',
            'impersonation'   => 'Impersonating another person',
            'other'           => 'Other',
        ];

        $subject = sprintf('[%s Admin] User Report #%d — %s', $site_name, $report_id, $reason_labels[$reason_key] ?? $reason_key);
        $body    = sprintf(
            "A new user report has been submitted on %s.\n\n" .
            "REPORT DETAILS\n" .
            "--------------\n" .
            "Report ID:       #%d\n" .
            "Date/Time:       %s\n\n" .
            "REPORTED USER\n" .
            "-------------\n" .
            "User ID:         %d\n" .
            "Display Name:    %s\n" .
            "Email:           %s\n" .
            "Profile URL:     %s\n\n" .
            "REPORTER\n" .
            "--------\n" .
            "User ID:         %d\n" .
            "Display Name:    %s\n" .
            "Email:           %s\n\n" .
            "REASON\n" .
            "------\n" .
            "Category:        %s\n" .
            "Details:         %s\n\n" .
            "Review this report in the admin dashboard:\n%s\n",
            $site_name,
            $report_id,
            gmdate('Y-m-d H:i:s'),
            $reported_user->ID,
            $reported_user->display_name,
            $reported_user->user_email,
            get_author_posts_url($reported_user->ID),
            $reporter_id,
            $reporter ? $reporter->display_name : 'Unknown',
            $reporter ? $reporter->user_email   : 'Unknown',
            $reason_labels[$reason_key] ?? $reason_key,
            $reason_detail ?: '(none provided)',
            $admin_url
        );

        $sent = false;
        try {
            $sent = (bool) wp_mail($admin_email, $subject, $body);
        } finally {
            if (!$sent) {
                DisputeRepository::clearAdminReportNotified($report_id, $ts);
            }
        }

        if (!$sent) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] wp_mail failed', [
                'type' => 'admin_report_notification',
                'report_id' => $report_id,
            ]);
            self::recordMailFailure('admin_report_notification');
            throw new \RuntimeException(
                "wp_mail failed for admin report notification (report={$report_id}) — Action Scheduler will retry"
            );
        }
    }

    public static function emailReporterResult(int $disputeId, int $reporterId, string $outcome): void
    {
        // Claim-before-send: see notifyPanelist() for rationale. This is the
        // most exposed of the four handlers because DisputeResolver::handle
        // AND the reconciliation Phase B retry can both enqueue this hook for
        // the same dispute during a slow-adjudicator race. Claim-then-send
        // closes the double-email window.
        $ts = gmdate('Y-m-d H:i:s');
        if (!DisputeRepository::markResolvedNotified($disputeId, $ts)) {
            return;
        }

        $reporter = get_userdata($reporterId);
        if (!$reporter || !$reporter->user_email) {
            // Tombstone claim — no address to retry against.
            return;
        }

        if ($outcome === 'accepted') {
            $subject = '[BCC] Your dispute was accepted — vote removed';
            $body    = 'Good news! The community panel reviewed your dispute and agreed the vote was invalid. It has been removed from your trust score.';
        } elseif ($outcome === 'timeout_no_quorum') {
            // Distinct from 'rejected': the panel never reached quorum, so
            // the dispute was NOT judged on its merits. No penalty was
            // applied. The disputed vote still stands, but the reporter is
            // not treated as having filed a bad-faith report.
            $subject = '[BCC] Your dispute could not be decided — not enough panel votes';
            $body    = 'Your dispute could not be decided because not enough community panelists voted within the review period. '
                     . 'The vote remains on your profile, but no penalty was applied to you. You may file a new dispute if you still believe the vote is invalid.';
        } else {
            // 'rejected' (quorum reached, majority voted reject).
            $subject = '[BCC] Your dispute was reviewed — vote stands';
            $body    = 'The community panel reviewed your dispute and decided the vote was valid. The vote remains on your profile.';
        }

        $sent = false;
        try {
            $sent = (bool) wp_mail($reporter->user_email, $subject, $body);
        } finally {
            if (!$sent) {
                DisputeRepository::clearResolvedNotified($disputeId, $ts);
            }
        }

        if (!$sent) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] wp_mail failed', [
                'type' => 'reporter_result_notification',
                'dispute_id' => $disputeId,
                'reporter_id' => $reporterId,
            ]);
            self::recordMailFailure('reporter_result_notification');
            throw new \RuntimeException(
                "wp_mail failed for reporter-result notification (dispute={$disputeId}) — Action Scheduler will retry"
            );
        }
    }
}
