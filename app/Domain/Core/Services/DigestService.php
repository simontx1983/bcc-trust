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
 *   - **Bounded per-user volume.** Each digest tops out at a max-items
 *     cap (default 25) + a "+N more" footer. Power users with hundreds
 *     of unread rows get a digestible summary, not a wall of text.
 *   - **Admin-controlled (Email Digest page).** On/off, weekday + hour,
 *     lookback window and max items live in {@see DigestSettings}; every
 *     run is recorded there (last 8). Suspended and PeepSo-banned members
 *     are skipped. Only `email_digest = 1` members ever receive it —
 *     consent is never inferred (PeepSo's own digest flag is NOT read).
 *   - **One-click unsubscribe in the mail client too.** Every email carries
 *     `List-Unsubscribe` + `List-Unsubscribe-Post` (RFC 8058) pointing at
 *     the same signed URL, which accepts POST as well as GET.
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
use BCC\Trust\Core\Support\DigestSettings;
use BCC\Trust\Core\Support\FrontendRedirect;
use BCC\Trust\Core\Support\NotificationPrefs;

if (!defined('ABSPATH')) {
    exit;
}

final class DigestService
{
    /** HMAC over uid|exp, valid for 90 days. */
    private const UNSUBSCRIBE_TTL_SECONDS = 7_776_000;

    /** Opt-in ids read per page (keyset-paged; no silent upper cap). */
    private const OPT_IN_PAGE = 500;

    /** Hard stop on pages per run — 500 × 400 = 200k members. */
    private const MAX_PAGES = 400;

    public const RESULT_SENT      = 'sent';
    public const RESULT_NO_UNREAD = 'no_unread';
    public const RESULT_NO_EMAIL  = 'no_email';
    public const RESULT_FAILED    = 'failed';

    public const TRIGGER_CRON   = 'cron';
    public const TRIGGER_MANUAL = 'manual';

    public function __construct(
        private readonly NotificationRepository $notificationRepo
    ) {
    }

    /**
     * Cron entry point — invoked by the `bcc_trust_weekly_digest` hook, and
     * by the admin "Run now" actions with TRIGGER_MANUAL.
     *
     * Returns the count of emails sent so callers (e.g. an admin
     * manual-trigger button) can display "Sent N emails." Every run that
     * gets past the on/off check is recorded in {@see DigestSettings::runs()}.
     */
    public function sendWeeklyDigest(string $trigger = self::TRIGGER_CRON): int
    {
        // ⚠ Belt and braces: DigestScheduler unschedules the hook when the
        // digest is switched off, but a stray event (or an older scheduler)
        // must still not mail anyone. A manual run is an explicit admin act.
        if ($trigger === self::TRIGGER_CRON && !DigestSettings::isEnabled()) {
            return 0;
        }

        $settings = DigestSettings::get();
        $started  = time();
        $since    = gmdate('Y-m-d H:i:s', $started - ($settings['window_days'] * DAY_IN_SECONDS));

        $counts = [
            'opted_in'             => 0,
            'excluded'             => 0,
            self::RESULT_SENT      => 0,
            self::RESULT_NO_UNREAD => 0,
            self::RESULT_NO_EMAIL  => 0,
            self::RESULT_FAILED    => 0,
        ];

        $after = 0;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $ids = NotificationPrefs::findEmailDigestOptIns(self::OPT_IN_PAGE, $after);
            if ($ids === []) {
                break;
            }
            foreach ($ids as $userId) {
                $after = max($after, $userId);
                $counts['opted_in']++;

                if (self::isExcluded($userId)) {
                    $counts['excluded']++;
                    continue;
                }
                try {
                    $result = $this->dispatchToUser($userId, $since, $settings['max_items']);
                } catch (\Throwable $e) {
                    Logger::warning('[DigestService] dispatch failed', [
                        'user_id' => $userId,
                        'error'   => $e->getMessage(),
                    ]);
                    $result = self::RESULT_FAILED;
                }
                $counts[$result]++;
            }
            if (count($ids) < self::OPT_IN_PAGE) {
                break;
            }
        }

        DigestSettings::recordRun([
            'started_at'  => $started,
            'finished_at' => time(),
            'trigger'     => $trigger === self::TRIGGER_MANUAL ? self::TRIGGER_MANUAL : self::TRIGGER_CRON,
            'opted_in'    => $counts['opted_in'],
            'excluded'    => $counts['excluded'],
            'sent'        => $counts[self::RESULT_SENT],
            'no_unread'   => $counts[self::RESULT_NO_UNREAD],
            'no_email'    => $counts[self::RESULT_NO_EMAIL],
            'failed'      => $counts[self::RESULT_FAILED],
        ]);

        return $counts[self::RESULT_SENT];
    }

    /**
     * The digest $userId would receive right now — for the admin preview of
     * the admin's OWN digest. Null when there is nothing to send. Never
     * sends; ignores the opt-in (it shows content, it does not mail).
     *
     * @return array{subject: string, body: string, count: int}|null
     */
    public function previewFor(int $userId): ?array
    {
        $settings = DigestSettings::get();
        $since    = gmdate('Y-m-d H:i:s', time() - ($settings['window_days'] * DAY_IN_SECONDS));
        $message  = $this->compose($userId, $since, $settings['max_items']);

        return $message === null ? null : [
            'subject' => $message['subject'],
            'body'    => $message['body'],
            'count'   => $message['count'],
        ];
    }

    /**
     * Send $userId their own digest, marked [TEST]. Admin "send test to me"
     * only — the caller passes the CURRENT admin's id. Ignores the opt-in
     * and the on/off switch; mails nobody else.
     *
     * @return self::RESULT_* constant
     */
    public function sendTestTo(int $userId): string
    {
        $settings = DigestSettings::get();
        $since    = gmdate('Y-m-d H:i:s', time() - ($settings['window_days'] * DAY_IN_SECONDS));

        return $this->dispatchToUser($userId, $since, $settings['max_items'], true);
    }

    /**
     * Suspended (BCC) or banned (PeepSo) members get no digest (user
     * decision 2026-11-11). Reuses the canonical checks — the same two
     * `AnnouncementViewerStanding` reads. An unreadable state EXCLUDES:
     * for a mailer, not sending is the safe failure.
     */
    private static function isExcluded(int $userId): bool
    {
        try {
            if (class_exists('\BCC\Core\Permissions\Permissions')
                && !\BCC\Core\Permissions\Permissions::is_not_suspended($userId, false)
            ) {
                return true;
            }
            if (class_exists('PeepSoUser')
                && \PeepSoUser::get_instance($userId)->get_user_role() === 'ban'
            ) {
                return true;
            }
        } catch (\Throwable $e) {
            Logger::warning('[DigestService] standing lookup failed; skipping member', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return true;
        }
        return false;
    }

    /**
     * Build + send the digest for a single user.
     *
     * @return self::RESULT_* constant
     */
    private function dispatchToUser(int $userId, string $sinceMysql, int $maxItems, bool $test = false): string
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) {
            return self::RESULT_NO_EMAIL;
        }
        $email = (string) $user->user_email;
        if ($email === '') {
            return self::RESULT_NO_EMAIL;
        }

        $message = $this->compose($userId, $sinceMysql, $maxItems, $user);
        if ($message === null) {
            if (!$test) {
                return self::RESULT_NO_UNREAD;
            }
            // A test with nothing unread still proves delivery.
            $message = [
                'subject' => 'Your weekly Floor digest — 0 notifications',
                'body'    => self::emptyTestBody(),
                'count'   => 0,
                'unsub'   => $this->buildUnsubscribeUrl($userId),
            ];
        }

        $subject = $test ? '[TEST] ' . $message['subject'] : $message['subject'];
        $sent    = wp_mail($email, $subject, $message['body'], self::headers($message['unsub']));
        if (!$sent) {
            Logger::warning('[DigestService] wp_mail returned false', [
                'user_id' => $userId,
            ]);
            return self::RESULT_FAILED;
        }
        return self::RESULT_SENT;
    }

    /**
     * @return array{subject: string, body: string, count: int, unsub: string}|null
     */
    private function compose(int $userId, string $sinceMysql, int $maxItems, ?\WP_User $user = null): ?array
    {
        $rows = $this->notificationRepo->findUnreadSince(
            $userId,
            $sinceMysql,
            $maxItems + 1 // +1 sentinel to detect "more" overflow
        );
        if ($rows === []) {
            return null;
        }

        $user = $user ?? get_userdata($userId);
        if (!$user instanceof \WP_User) {
            return null;
        }

        $hasOverflow = count($rows) > $maxItems;
        if ($hasOverflow) {
            // Drop the sentinel — we'll surface "+N more" in the footer.
            array_pop($rows);
        }

        $handle = (string) get_user_meta($userId, 'bcc_handle', true);
        if ($handle === '') {
            $handle = $user->user_login;
        }

        $unsub = $this->buildUnsubscribeUrl($userId);

        return [
            'subject' => sprintf(
                'Your weekly Floor digest — %d %s',
                count($rows),
                count($rows) === 1 ? 'notification' : 'notifications'
            ),
            'body'    => $this->composeBody($handle, $rows, $hasOverflow, $maxItems, $unsub),
            'count'   => count($rows),
            'unsub'   => $unsub,
        ];
    }

    /**
     * RFC 2369 / RFC 8058 one-click unsubscribe headers. The URL is the same
     * signed link the body carries; DigestUnsubscribeEndpoint accepts the
     * mail client's POST as well as a click's GET.
     *
     * @return list<string>
     */
    public static function headers(string $unsubscribeUrl): array
    {
        return [
            'Content-Type: text/plain; charset=UTF-8',
            'List-Unsubscribe: <' . $unsubscribeUrl . '>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
        ];
    }

    private static function emptyTestBody(): string
    {
        return "This is a test of the weekly Floor digest.\n\n"
            . "You have no unread notifications in the digest window, so a real\n"
            . "digest would not be sent to you this week.\n";
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
    private function composeBody(string $handle, array $rows, bool $hasOverflow, int $maxItems, string $unsubUrl): string
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

        $overflowNote = $hasOverflow
            ? "\n\n…and more, capped at " . $maxItems . " for the digest."
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
