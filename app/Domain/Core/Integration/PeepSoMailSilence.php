<?php

namespace BCC\Trust\Core\Integration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PeepSo's email pipeline stays off: BCC is the user-facing email surface.
 *
 * BCC sends its own mail (AuthMailer, AccountSecurityMailer, DigestService,
 * DisputeNotificationService, bcc-core's BccMailer), straight through
 * wp_mail(). PeepSo's pipeline runs in parallel and reads its own per-user
 * keys (`peepso_email_intensity`, `peepso_notifications`), which BCC's
 * notification settings do not write. Left on, a member who opts out of
 * email in BCC could still get PeepSo's, and one who opts in could get two
 * of the same message.
 *
 * Two halves:
 *
 *  1. THE SEND LOOP. PeepSo's 5-minute mail-queue cron is unscheduled on
 *     every `init` (idempotent: a no-op once it is gone). This was an
 *     inline closure in bcc-trust.php before this class existed.
 *
 *  2. THE QUEUE. PeepSoMailQueue::add_message() asks the
 *     `peepso_mailqueue_allowed` filter before it queues anything. Answering
 *     false means nothing is queued:
 *       - the queue table stops growing with mail that would never be sent
 *         (rows are deleted only after a successful send);
 *       - PeepSo's send-immediately path, which queues and then runs its
 *         queue processor at once, sends nothing either. That path is how
 *         PeepSo's own password-changed message reached members alongside
 *         BCC's.
 *     The veto runs late (priority 999) so it is the final answer.
 *     It also stops the `peepso_mailqueue_add` action, which add_message()
 *     fires only after the filter allows: nothing listens to it today, but
 *     a PeepSo add-on that hooked it would be silenced too.
 *
 * ⚠ SCOPE. Only PeepSo's queue. Nothing here touches wp_mail() or any of its
 * filters, so BCC's own mail is unaffected. PeepSoMailSilenceTest pins both
 * halves and that scope.
 *
 * If PeepSo email is ever wanted again, remove this registration AND make
 * BCC's notification settings write PeepSo's keys, so the two surfaces
 * cannot disagree.
 */
final class PeepSoMailSilence
{
    /** PeepSo::CRON_MAILQUEUE; the literal is used when PeepSo has not loaded yet. */
    public const MAILQUEUE_CRON_HOOK = 'peepso_mailqueue_send_event';

    /** Asked by PeepSoMailQueue::add_message() before anything is queued. */
    public const QUEUE_FILTER = 'peepso_mailqueue_allowed';

    /** Late, so no earlier filter can turn the veto back into an allow. */
    public const QUEUE_FILTER_PRIORITY = 999;

    public static function register(): void
    {
        add_action('init', [self::class, 'unscheduleSendLoop'], 20);
        add_filter(self::QUEUE_FILTER, [self::class, 'vetoQueue'], self::QUEUE_FILTER_PRIORITY, 2);
    }

    /** Unschedule PeepSo's mail-queue cron. */
    public static function unscheduleSendLoop(): void
    {
        $hook = defined('PeepSo::CRON_MAILQUEUE')
            ? (string) constant('PeepSo::CRON_MAILQUEUE')
            : self::MAILQUEUE_CRON_HOOK;

        wp_clear_scheduled_hook($hook);
    }

    /**
     * PeepSo's filtered value is the recipient's user id (truthy = queue
     * it). Every recipient is answered no.
     */
    public static function vetoQueue(mixed $recipient = null, mixed $default = null): bool
    {
        return false;
    }
}
