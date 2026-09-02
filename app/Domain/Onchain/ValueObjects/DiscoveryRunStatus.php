<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The closed lifecycle of one administrator-requested discovery run.
 *
 * ── WHY FIVE STATES AND NOT SEVEN ───────────────────────────────────────
 * `stopped` and `expired` were both proposed and both rejected during
 * design, for the same reason: they duplicate information another column
 * already carries.
 *
 *   - A pass that hit its time or item budget SUCCEEDED at what it
 *     attempted. That is {@see SUCCEEDED} with `partial = 1` and the exact
 *     `stop_reason`. A `stopped` state would mean two places could disagree
 *     about whether a budget stop was a failure.
 *   - A run whose executor never came back needs the same operator response
 *     as any other failure. Its provenance is preserved precisely by
 *     `error_code = lease_expired` / `max_attempts_exhausted`, without
 *     widening the state machine.
 *
 * ── AGE IS NOT A FAILURE ────────────────────────────────────────────────
 * There is deliberately NO `queued -> failed` transition. A queued run that
 * nobody has collected is not broken — on an installation whose cron is
 * disabled or externally driven it is simply waiting, and the maintenance
 * sweep re-dispatches it when cron returns. Age only derives the read-time
 * `pickup_overdue` flag. Failing a run for being old would destroy a
 * perfectly good request that was about to run.
 */
final class DiscoveryRunStatus
{
    /** Recorded and durable; no executor has claimed it yet. */
    public const QUEUED = 'queued';

    /** Claimed under a lease. The only state that holds a lease token. */
    public const RUNNING = 'running';

    /** Terminal. The pass reached a conclusion — possibly a partial one. */
    public const SUCCEEDED = 'succeeded';

    /** Terminal. A fault, or attempts exhausted. */
    public const FAILED = 'failed';

    /** Terminal. An administrator withdrew a run that had not started. */
    public const CANCELLED = 'cancelled';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::QUEUED, self::RUNNING, self::SUCCEEDED, self::FAILED, self::CANCELLED];
    }

    /** @return list<string> */
    public static function terminal(): array
    {
        return [self::SUCCEEDED, self::FAILED, self::CANCELLED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /**
     * Is this a state a run rests in permanently?
     *
     * Written as membership in the terminal list rather than "not queued
     * and not running", so a status token from a newer build is treated as
     * NON-terminal. An unknown state that reads as terminal would clear
     * `active_marker` and let a second run start beside a live one.
     */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::terminal(), true);
    }

    /**
     * The legal transition table. Everything not listed here is refused.
     *
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::QUEUED => [
                self::RUNNING,    // executor claim (compare-and-swap, attempt +1)
                self::CANCELLED,  // administrator withdrawal
            ],
            self::RUNNING => [
                self::SUCCEEDED,  // terminal result written and confirmed
                self::FAILED,     // fault, or attempts exhausted
                self::QUEUED,     // lease expired: reaper returns it, attempt NOT bumped
            ],
            self::SUCCEEDED => [],
            self::FAILED    => [],
            self::CANCELLED => [],
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        if (!self::isValid($from) || !self::isValid($to)) {
            return false;
        }

        return in_array($to, self::transitions()[$from] ?? [], true);
    }
}
