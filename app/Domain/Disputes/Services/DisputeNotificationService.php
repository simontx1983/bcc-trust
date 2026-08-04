<?php

namespace BCC\Trust\Disputes\Services;

use BCC\Trust\Disputes\Repositories\DisputeRepository;
use BCC\Trust\Disputes\Repositories\UserReportRepository;
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
        // Poll-lifecycle notices to the disputed voter (a dispute party).
        add_action('bcc_disputes_email_voter_opened', function (int $dispute_id, int $voter_id) {
            self::emailVoterDisputeOpened($dispute_id, $voter_id);
        }, 10, 2);

        add_action('bcc_disputes_email_voter_result', function (int $dispute_id, int $voter_id, string $outcome) {
            self::emailVoterResult($dispute_id, $voter_id, $outcome);
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

    /**
     * Open notice to the disputed voter — their vote is under dispute
     * and the community vote has opened. Courtesy-only path: fired once
     * from submit, no claim marker, no reconcile sweep. NO tallies.
     */
    public static function emailVoterDisputeOpened(int $dispute_id, int $voter_id): void
    {
        $user = get_userdata($voter_id);
        if (!$user || !$user->user_email) {
            return;
        }

        $subject = '[BCC] A vote you cast is under dispute';
        $body    = sprintf(
            "Hello %s,\n\nA vote you cast has been disputed by the page owner, and a community review is now open. "
            . "No action is required from you — the community will decide the outcome, and we'll let you know how it resolves.\n\nDispute ID: #%d\n",
            $user->display_name,
            $dispute_id
        );

        // Courtesy notice: log-and-return on failure (no claim marker, so
        // throwing for an AS retry could double-send on a slow SMTP).
        if (!wp_mail($user->user_email, $subject, $body)) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] wp_mail failed', [
                'type'       => 'voter_opened_notification',
                'user_id'    => $voter_id,
                'dispute_id' => $dispute_id,
            ]);
            self::recordMailFailure('voter_opened_notification');
        }
    }

    /**
     * Outcome notice to the disputed voter. Mirrors the reporter-result
     * vocabulary; carries NO tallies. Courtesy-only path (see
     * emailVoterDisputeOpened for the no-claim rationale).
     */
    public static function emailVoterResult(int $dispute_id, int $voter_id, string $outcome): void
    {
        $user = get_userdata($voter_id);
        if (!$user || !$user->user_email) {
            return;
        }

        if ($outcome === 'accepted') {
            $subject = '[BCC] Dispute outcome — your vote was removed';
            $body    = 'The community reviewed a dispute over a vote you cast and agreed with the dispute. The vote has been removed.';
        } elseif ($outcome === 'timeout_no_quorum') {
            $subject = '[BCC] Dispute outcome — no decision reached';
            $body    = 'The community review of a dispute over a vote you cast ended without a decision. Your vote stands unchanged.';
        } else {
            // 'rejected' — the dispute failed; the vote stands.
            $subject = '[BCC] Dispute outcome — your vote stands';
            $body    = 'The community reviewed a dispute over a vote you cast and decided the vote was valid. Your vote stands unchanged.';
        }

        $body = sprintf("Hello %s,\n\n%s\n\nDispute ID: #%d\n", $user->display_name, $body, $dispute_id);

        if (!wp_mail($user->user_email, $subject, $body)) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] wp_mail failed', [
                'type'       => 'voter_result_notification',
                'user_id'    => $voter_id,
                'dispute_id' => $dispute_id,
            ]);
            self::recordMailFailure('voter_result_notification');
        }
    }

    public static function emailReportedUser(int $report_id, WP_User $reported_user): void
    {
        // Claim-before-send: flip the marker from NULL → $ts atomically
        // BEFORE calling wp_mail so two concurrent AS workers cannot
        // both send (see emailReporterResult for the full rationale).
        $ts = gmdate('Y-m-d H:i:s');
        if (!UserReportRepository::markReportNotified($report_id, $ts)) {
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
                UserReportRepository::clearReportNotified($report_id, $ts);
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
        // Claim-before-send (see emailReporterResult). Duplicate
        // admin emails previously leaked via the check-then-mark TOCTOU and
        // looked like a spam attack to moderators.
        $ts = gmdate('Y-m-d H:i:s');
        if (!UserReportRepository::markAdminReportNotified($report_id, $ts)) {
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
                UserReportRepository::clearAdminReportNotified($report_id, $ts);
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
        // Claim-before-send: flip resolved_notified_at from NULL → $ts
        // atomically BEFORE calling wp_mail. Two concurrent AS workers
        // scheduled for the same dispute cannot both pass the claim, so
        // only one sends. This is the most exposed handler because
        // DisputeResolver::handle AND the reconciliation Phase B retry
        // can both enqueue this hook for the same dispute during a
        // slow-adjudicator race. Claim-then-send closes the
        // double-email window.
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
            $body    = 'Good news! The community reviewed your dispute and agreed the vote was invalid. It has been removed from your trust score.';
        } elseif ($outcome === 'timeout_no_quorum') {
            // Distinct from 'rejected': the community vote ended
            // inconclusive (quorum/majority never met before expiry), so
            // the dispute was NOT judged on its merits. No penalty was
            // applied. The disputed vote still stands, but the reporter is
            // not treated as having filed a bad-faith report.
            $subject = '[BCC] Your dispute could not be decided — not enough community votes';
            $body    = 'Your dispute could not be decided because not enough qualified community votes were cast within the review period. '
                     . 'The vote remains on your profile, but no penalty was applied to you. You may file a new dispute if you still believe the vote is invalid.';
        } else {
            // 'rejected' (quorum reached, majority voted to keep the vote).
            $subject = '[BCC] Your dispute was reviewed — vote stands';
            $body    = 'The community reviewed your dispute and decided the vote was valid. The vote remains on your profile.';
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
