<?php
/**
 * DigestSettings — admin-controlled settings + run history for the §I1
 * weekly email digest.
 *
 * Two wp_options rows, both autoload=false (read on the cron run and the
 * admin page only):
 *   - `bcc_digest_settings` — on/off, weekday + hour (SITE timezone),
 *     lookback window, max items per email.
 *   - `bcc_digest_runs`     — the last {@see self::MAX_RUNS} runs, newest
 *     first. Bounded, so it never needs a table or a prune job.
 *
 * ⚠ "Never saved" is a real state, not a default to paper over. Until an
 * admin saves the page, `custom_schedule` is false and the digest keeps the
 * schedule CronService gave it — enabled, every 7 days from first
 * scheduling — so shipping this changes nothing on its own.
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class DigestSettings
{
    public const OPTION      = 'bcc_digest_settings';
    public const RUNS_OPTION = 'bcc_digest_runs';
    public const MAX_RUNS    = 8;

    public const WINDOW_DEFAULT = 7;
    public const WINDOW_MIN     = 1;
    public const WINDOW_MAX     = 30;

    public const ITEMS_DEFAULT = 25;
    public const ITEMS_MIN     = 5;
    // NotificationRepository::findUnreadSince() caps at 50 and the digest
    // asks for max+1 (overflow sentinel), so stay well under it.
    public const ITEMS_MAX     = 40;

    /** Monday 09:00 site time — used only once an admin saves the page. */
    public const WEEKDAY_DEFAULT = 1;
    public const HOUR_DEFAULT    = 9;

    /**
     * @return array{enabled: bool, custom_schedule: bool, weekday: int, hour: int, window_days: int, max_items: int}
     */
    public static function get(): array
    {
        $raw = get_option(self::OPTION, []);
        $raw = is_array($raw) ? $raw : [];

        return self::normalise($raw, isset($raw['custom_schedule']) && (bool) $raw['custom_schedule']);
    }

    /**
     * Clamp and store. Saving always marks the schedule as admin-chosen.
     *
     * @param array<string, mixed> $input
     * @return array{enabled: bool, custom_schedule: bool, weekday: int, hour: int, window_days: int, max_items: int}
     */
    public static function save(array $input): array
    {
        $settings = self::normalise($input, true);
        update_option(self::OPTION, $settings, false);

        return $settings;
    }

    public static function isEnabled(): bool
    {
        return self::get()['enabled'];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{enabled: bool, custom_schedule: bool, weekday: int, hour: int, window_days: int, max_items: int}
     */
    public static function normalise(array $raw, bool $customSchedule): array
    {
        return [
            // Absent ⇒ enabled: the digest ran before this page existed.
            'enabled'         => array_key_exists('enabled', $raw) ? (bool) $raw['enabled'] : true,
            'custom_schedule' => $customSchedule,
            'weekday'         => self::clampInt($raw['weekday'] ?? null, 0, 6, self::WEEKDAY_DEFAULT),
            'hour'            => self::clampInt($raw['hour'] ?? null, 0, 23, self::HOUR_DEFAULT),
            'window_days'     => self::clampInt($raw['window_days'] ?? null, self::WINDOW_MIN, self::WINDOW_MAX, self::WINDOW_DEFAULT),
            'max_items'       => self::clampInt($raw['max_items'] ?? null, self::ITEMS_MIN, self::ITEMS_MAX, self::ITEMS_DEFAULT),
        ];
    }

    /**
     * Next UTC timestamp at $weekday (0 = Sunday) $hour:00 in $tz, strictly
     * after $now. Site timezone by default.
     */
    public static function nextRunTimestamp(int $weekday, int $hour, ?int $now = null, ?\DateTimeZone $tz = null): int
    {
        $now = $now ?? time();
        $tz  = $tz ?? wp_timezone();

        $local = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
        $days  = ($weekday - (int) $local->format('w') + 7) % 7;
        $at    = $local->setTime($hour, 0)->modify('+' . $days . ' days');
        if ($at->getTimestamp() <= $now) {
            $at = $at->modify('+7 days');
        }

        return $at->getTimestamp();
    }

    /**
     * Prepend one run, keeping the newest {@see self::MAX_RUNS}.
     *
     * @param array<string, int|string> $run
     */
    public static function recordRun(array $run): void
    {
        $runs = self::runs();
        array_unshift($runs, $run);
        update_option(self::RUNS_OPTION, array_slice($runs, 0, self::MAX_RUNS), false);
    }

    /**
     * @return list<array<string, int|string>>
     */
    public static function runs(): array
    {
        $raw = get_option(self::RUNS_OPTION, []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $run) {
            if (is_array($run)) {
                /** @var array<string, int|string> $run */
                $out[] = $run;
            }
        }
        return $out;
    }

    /**
     * @param mixed $value
     */
    private static function clampInt($value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, (int) $value));
    }
}
