<?php
/**
 * DigestService — §I1 weekly email digest.
 *
 * Cron-driven re-engagement loop. Every week, walks users who have
 * `bcc_notif_pref_email_digest = 1` and emails them a plain-text
 * summary of their unread BCC notifications from the past 7 days.
 *
 * Design choices:
 *   - **Plain text only.** No HTML template. Emails get to the inbox
 *     more reliably + easier to debug. V2 may bring HTML once we have
 *     the volume to justify a template pipeline.
 *   - **Cursor by timestamp, not by "last digest at".** Each run looks
 *     at the trailing 7 days of unread rows. A user who logs in and
 *     reads everything between digests gets nothing the next week —
 *     which is the right outcome.
 *   - **One-click unsubscribe.** Every email carries a signed URL
 *     that flips `email_digest = false` without a login. Token =
 *     HMAC-SHA256(uid|exp, wp_salt('auth')); 90-day expiry.
 *   - **Fail-soft.** A wp_mail() failure for one user logs but does
 *     not abort the cron. The next run sees the same unread rows.
 *   - **Bounded per-user volume.** Each digest tops out at 25
 *     notifications + a "+N more" footer. Power users with hundreds
 *     of unread rows get a digestible summary, not a wall of text.
 *
 * Frontend origin: pulls from FrontendRedirect::firstOrigin() so the
 * email links land on the headless Next.js host. Falls back to
 * home_url() when BCC_FRONTEND_ORIGIN is unconfigured (dev convenience).
 *
 * @package BCC\Trust\Core\Services
 * @since V1.5 (§I1 email digest)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Repositories\NotificationRepository;
use BCC\Trust\Core\Support\FrontendRedirect;
use BCC\Trust\Core\Support\NotificationPrefs;

if (!defined('ABSPATH')) {
    exit;
}

final class DigestService
{
    /** Trailing window — every digest covers the past 7 days of unread rows. */
    private const WINDOW_DAYS = 7;

    /** Per-user cap on rows enumerated in the email body. */
    private const MAX_ROWS_PER_USER = 25;

    /** HMAC over uid|exp, valid for 90 days. */
    private const UNSUBSCRIBE_TTL_SECONDS = 7_776_000;

    public function __construct(
        private readonly NotificationRepository $notificationRepo
    ) {
    }

    /**
     * Cron entry point — invoked by the `bcc_trust_weekly_digest` hook.
     * Walks every email-digest opt-in and dispatches one email each.
     *
     * Returns the count of emails sent so callers (e.g. an admin
     * manual-trigger button) can display "Sent N emails."
     */
    public function sendWeeklyDigest(): int
    {
        $optIns = NotificationPrefs::findEmailDigestOptIns();
        if ($optIns === []) {
            return 0;
        }

        $sinceTs = time() - (self::WINDOW_DAYS * DAY_IN_SECONDS);
        $sinceMysql = gmdate('Y-m-d H:i:s', $sinceTs);

        $sent = 0;
        foreach ($optIns as $userId) {
            try {
                if ($this->dispatchToUser($userId, $sinceMysql)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                Logger::warning('[DigestService] dispatch failed', [
                    'user_id' => $userId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
        return $sent;
    }

    /**
     * Build + send the digest for a single user. Returns true when an
     * email actually went out (false when the user had no unread rows
     * worth emailing about).
     */
    private function dispatchToUser(int $userId, string $sinceMysql): bool
    {
        $rows = $this->notificationRepo->findUnreadSince(
            $userId,
            $sinceMysql,
            self::MAX_ROWS_PER_USER + 1 // +1 sentinel to detect "more" overflow
        );
        if ($rows === []) {
            return false;
        }

        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) {
            return false;
        }
        $email = (string) $user->user_email;
        if ($email === '') {
            return false;
        }

        $hasOverflow = count($rows) > self::MAX_ROWS_PER_USER;
        if ($hasOverflow) {
            // Drop the sentinel — we'll surface "+N more" in the footer.
            array_pop($rows);
        }

        $handle = (string) get_user_meta($userId, 'bcc_handle', true);
        if ($handle === '') {
            $handle = $user->user_login;
        }

        $body = $this->composeBody($handle, $rows, $hasOverflow, $userId);
        $subject = sprintf(
            'Your weekly Floor digest — %d %s',
            count($rows),
            count($rows) === 1 ? 'notification' : 'notifications'
        );

        $sent = wp_mail($email, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
        if (!$sent) {
            Logger::warning('[DigestService] wp_mail returned false', [
                'user_id' => $userId,
                'email'   => $email,
            ]);
            return false;
        }
        return true;
    }

    /**
     * Compose the plain-text body. Format:
     *
     *   Hey @handle,
     *
     *   Here's what happened on the Floor this past week:
     *
     *   - <message>
     *   - <message>
     *   ... +N more
     *
     *   See it all: <link>
     *
     *   ---
     *   Don't want these emails? Unsubscribe: <signed URL>
     *
     * @param list<object> $rows
     */
    private function composeBody(string $handle, array $rows, bool $hasOverflow, int $userId): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $message = isset($row->not_message) ? (string) $row->not_message : '';
            if ($message === '') {
                continue;
            }
            $lines[] = '- ' . wp_strip_all_tags($message);
        }

        $bullets = implode("\n", $lines);

        $floorUrl = FrontendRedirect::defaultReturn('/notifications');
        $unsubUrl = $this->buildUnsubscribeUrl($userId);

        $overflowNote = $hasOverflow
            ? "\n\n…and more, capped at " . self::MAX_ROWS_PER_USER . " for the digest."
            : '';

        return sprintf(
            "Hey @%s,\n\n"
            . "Here's what happened on the Floor this past week:\n\n"
            . "%s%s\n\n"
            . "See it all: %s\n\n"
            . "---\n"
            . "Don't want these emails? Unsubscribe (one click): %s\n",
            $handle,
            $bullets,
            $overflowNote,
            $floorUrl,
            $unsubUrl
        );
    }

    /**
     * Build a signed unsubscribe URL. The DigestUnsubscribeEndpoint
     * verifies + flips email_digest = false on click.
     */
    private function buildUnsubscribeUrl(int $userId): string
    {
        $exp   = time() + self::UNSUBSCRIBE_TTL_SECONDS;
        $payload = $userId . '|' . $exp;
        $sig   = hash_hmac('sha256', $payload, wp_salt('auth'));

        $params = http_build_query([
            'uid' => $userId,
            'exp' => $exp,
            'sig' => $sig,
        ]);

        // Land directly on the WP REST endpoint — it returns an HTML
        // confirmation page so the user doesn't need a frontend round-trip.
        return rest_url('bcc/v1/digest/unsubscribe') . '?' . $params;
    }

    /**
     * Verify a signed unsubscribe token. Returns the user id on
     * success, 0 on any failure (bad sig, expired, malformed).
     */
    public static function verifyUnsubscribeToken(int $userId, int $exp, string $sig): int
    {
        if ($userId <= 0 || $exp <= 0 || $sig === '') {
            return 0;
        }
        if (time() > $exp) {
            return 0; // expired
        }
        $expected = hash_hmac('sha256', $userId . '|' . $exp, wp_salt('auth'));
        if (!hash_equals($expected, $sig)) {
            return 0;
        }
        return $userId;
    }
}
