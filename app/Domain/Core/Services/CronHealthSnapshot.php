<?php
/**
 * CronHealthSnapshot — projects per-cron last-success freshness into the
 * bcc-core /system/health surface (Phase 4 operational-visibility).
 *
 * The blind spot this closes: of ~22 recurring BCC cron hooks, only a few
 * wrote a heartbeat, so a silently-stalled cleanup/recalc/graph cron was
 * invisible until a secondary symptom (unbounded table growth, stale read
 * model) appeared. Phase 4 registers a uniform success-heartbeat per hook
 * (`<hook>_last_success`, written at PHP_INT_MAX priority so a throwing
 * handler halts do_action before it records — a failing cron stays stale).
 * This snapshot reads those heartbeats and reports each job's age + a
 * pending/ok/stale state, mirroring the /system/ping recalc-freshness
 * precedent (0 ⇒ pending install-grace; age > threshold ⇒ stale).
 *
 * Contributed via the canonical `bcc_system_health` filter (the same seam
 * NftIndexerHealthSnapshot uses) — NOT a parallel endpoint. Reads only
 * wp_options (get_option) + the WP cron-schedule registry; touches no BCC
 * table, so it is not a Repository concern (§1).
 *
 * @package BCC\Trust\Core\Services
 * @since Phase 4 ops-visibility (2026-06-25)
 */

namespace BCC\Trust\Core\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type HookMeta array{interval?: string, description?: string, source?: string}
 * @phpstan-type JobHealth array{interval: string, description: string, last_success_ts: int|null, age_seconds: int|null, threshold_seconds: int|null, state: string}
 */
final class CronHealthSnapshot
{
    /** Multiplier on a job's interval before its heartbeat is "stale". */
    private const STALE_INTERVAL_MULTIPLIER = 2;

    /** Floor so sub-10-minute crons don't flap on shared-host cron jitter. */
    private const STALE_FLOOR_SECONDS = 600;

    /** Option key suffix written by the per-hook success heartbeat. */
    public const HEARTBEAT_SUFFIX = '_last_success';

    /**
     * bcc_system_health filter contributor. Gathers the live WP data and
     * defers all logic to the pure evaluate() so the rules stay testable.
     *
     * @param array<string, mixed> $health
     * @return array<string, mixed>
     */
    public static function contribute(array $health): array
    {
        /** @var array<string, HookMeta> $hooks */
        $hooks = (array) apply_filters('bcc_expected_cron_hooks', []);

        $lastSuccess     = [];
        $intervalSeconds = [];
        $schedules       = wp_get_schedules();

        foreach ($hooks as $hook => $meta) {
            $lastSuccess[$hook] = (int) get_option($hook . self::HEARTBEAT_SUFFIX, 0);
            $slug = is_array($meta) && isset($meta['interval']) ? (string) $meta['interval'] : '';
            if ($slug !== '' && isset($schedules[$slug]['interval'])) {
                $intervalSeconds[$slug] = (int) $schedules[$slug]['interval'];
            }
        }

        $health['crons'] = self::evaluate($hooks, $lastSuccess, $intervalSeconds, time());

        return $health;
    }

    /**
     * Pure freshness evaluation — no WP calls, fully unit-testable.
     *
     * @param array<string, HookMeta> $hooks            hook => meta (interval slug + description)
     * @param array<string, int>      $lastSuccess      hook => last-success unix ts (0 = never)
     * @param array<string, int>      $intervalSeconds  interval slug => seconds
     * @param int                     $now              current unix time
     * @return array{summary: array{total: int, ok: int, stale: int, pending: int, has_stale: bool}, jobs: array<string, JobHealth>}
     */
    public static function evaluate(array $hooks, array $lastSuccess, array $intervalSeconds, int $now): array
    {
        $jobs    = [];
        $ok      = 0;
        $stale   = 0;
        $pending = 0;

        foreach ($hooks as $hook => $meta) {
            $slug        = is_array($meta) && isset($meta['interval']) ? (string) $meta['interval'] : '';
            $description = is_array($meta) && isset($meta['description']) ? (string) $meta['description'] : '';
            $intervalSec = $intervalSeconds[$slug] ?? null;
            $ts          = $lastSuccess[$hook] ?? 0;

            $threshold = $intervalSec !== null
                ? max($intervalSec * self::STALE_INTERVAL_MULTIPLIER, self::STALE_FLOOR_SECONDS)
                : null;

            if ($ts <= 0) {
                // Never recorded a success yet — install grace, not an alarm
                // (mirrors /system/ping treating recalc-ts 0 as 'pending').
                $state = 'pending';
                $age   = null;
                $pending++;
            } else {
                $age = max(0, $now - $ts);
                if ($threshold !== null && $age > $threshold) {
                    $state = 'stale';
                    $stale++;
                } else {
                    $state = 'ok';
                    $ok++;
                }
            }

            $jobs[$hook] = [
                'interval'          => $slug,
                'description'       => $description,
                'last_success_ts'   => $ts > 0 ? $ts : null,
                'age_seconds'       => $age,
                'threshold_seconds' => $threshold,
                'state'             => $state,
            ];
        }

        return [
            'summary' => [
                'total'     => count($jobs),
                'ok'        => $ok,
                'stale'     => $stale,
                'pending'   => $pending,
                'has_stale' => $stale > 0,
            ],
            'jobs' => $jobs,
        ];
    }
}
