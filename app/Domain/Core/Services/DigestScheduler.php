<?php
/**
 * DigestScheduler — keeps `bcc_trust_weekly_digest` on the admin-chosen
 * weekday + hour, or off.
 *
 * Ownership hand-off: CronService owns every bcc-trust recurring hook and
 * self-heals a missing one by scheduling it at time() — which, for a mailer,
 * would mean "send now". Once an admin has saved the Email Digest page
 * (`custom_schedule`), this class removes the digest from CronService's
 * owned list through the `bcc_trust_owned_cron_jobs` filter (the filter's
 * documented opt-out) and keeps the slot itself:
 *   - on save ({@see self::apply()}): clear, then schedule the next slot if
 *     enabled;
 *   - daily ({@see self::ensure()}, on `bcc_trust_daily_maintenance`):
 *     re-add a missing slot at the next CHOSEN time, never at time(); clear
 *     a stray event while disabled.
 * Before the first save nothing here acts, and CronService keeps the job.
 * While disabled, the hook is also dropped from `bcc_expected_cron_hooks`,
 * so the drift detector and heartbeat do not report an intentional "off"
 * as MISSING / stale.
 *
 * @package BCC\Trust\Core\Services
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Support\DigestSettings;

if (!defined('ABSPATH')) {
    exit;
}

final class DigestScheduler
{
    public const HOOK     = 'bcc_trust_weekly_digest';
    public const SCHEDULE = 'bcc_weekly';

    public static function register(): void
    {
        add_filter('bcc_trust_owned_cron_jobs', [self::class, 'filterOwnedJobs']);
        add_action('bcc_trust_daily_maintenance', [self::class, 'ensure']);
        // After bcc-trust's own contributor (priority 10): a digest an admin
        // switched OFF is not "missing", and its heartbeat is not "stale".
        add_filter('bcc_expected_cron_hooks', [self::class, 'filterExpectedHooks'], 20);
    }

    /**
     * @param mixed $hooks
     * @return mixed
     */
    public static function filterExpectedHooks($hooks)
    {
        if (is_array($hooks) && !DigestSettings::isEnabled()) {
            unset($hooks[self::HOOK]);
        }
        return $hooks;
    }

    /**
     * @param mixed $jobs
     * @return mixed
     */
    public static function filterOwnedJobs($jobs)
    {
        if (is_array($jobs) && DigestSettings::get()['custom_schedule']) {
            unset($jobs[self::HOOK]);
        }
        return $jobs;
    }

    /** Re-schedule from the saved settings. Called after every save. */
    public static function apply(): void
    {
        wp_clear_scheduled_hook(self::HOOK);

        $s = DigestSettings::get();
        if ($s['enabled']) {
            wp_schedule_event(
                DigestSettings::nextRunTimestamp($s['weekday'], $s['hour']),
                self::SCHEDULE,
                self::HOOK
            );
        }
    }

    public static function ensure(): void
    {
        $s = DigestSettings::get();
        if (!$s['custom_schedule']) {
            return;
        }

        $next = wp_next_scheduled(self::HOOK);
        if (!$s['enabled']) {
            if ($next !== false) {
                wp_clear_scheduled_hook(self::HOOK);
            }
            return;
        }
        if ($next === false) {
            wp_schedule_event(
                DigestSettings::nextRunTimestamp($s['weekday'], $s['hour']),
                self::SCHEDULE,
                self::HOOK
            );
        }
    }

    /**
     * After a cron run: put the next event back on the chosen weekday + hour.
     *
     * WP-cron reschedules a recurring event as "last run + 7 × 86400" before
     * calling the hook, so across a DST change the slot drifts an hour (and
     * stays drifted). Recomputing from weekday + hour in the site timezone
     * corrects it. No-op until an admin has chosen a slot, or while off.
     */
    public static function realign(): void
    {
        $s = DigestSettings::get();
        if (!$s['custom_schedule'] || !$s['enabled']) {
            return;
        }

        $want = DigestSettings::nextRunTimestamp($s['weekday'], $s['hour']);
        $have = wp_next_scheduled(self::HOOK);
        if ($have === (int) $want) {
            return;
        }
        wp_clear_scheduled_hook(self::HOOK);
        wp_schedule_event($want, self::SCHEDULE, self::HOOK);
    }

    /** Next scheduled run (UTC timestamp), or null. */
    public static function next(): ?int
    {
        $next = wp_next_scheduled(self::HOOK);
        return $next === false ? null : (int) $next;
    }
}
