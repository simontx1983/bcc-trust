<?php
/**
 * Circuit Breaker
 *
 * Per-chain circuit breaker that pauses API fetching when a chain's
 * endpoint is consistently failing. Prevents wasting API budget on
 * chains that are down and gives failing endpoints time to recover.
 *
 * States:
 *   CLOSED   — Normal operation, requests flow through.
 *   OPEN     — Chain is failing, all requests blocked for COOLDOWN period.
 *   HALF-OPEN — Cooldown expired, allow ONE probe request.
 *              If it succeeds → CLOSED. If it fails → OPEN again.
 *
 * Storage: wp_cache (Redis-backed when available, transient fallback).
 *
 * @package BCC\Trust\Onchain\Support
 */

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class CircuitBreaker
{
    const FAILURE_THRESHOLD = 5;            // Consecutive failures to trip
    const COOLDOWN_SECONDS  = 300;          // 5 minutes before half-open probe
    const PROBE_LOCK_PREFIX = 'bcc_cb_probe_';
    const CACHE_GROUP       = 'bcc_circuit';
    // 6-hour TTL: long-running batch cron jobs (10k+ pages) can exceed the
    // previous 30-min TTL mid-run, causing an OPEN breaker to silently return
    // to CLOSED in the same cron tick because its cached state expired. The
    // TTL only needs to outlast the longest batch run; breaker state is
    // authoritatively reset by recordSuccess() so a longer TTL does not
    // extend actual cooldowns — it just prevents premature amnesia.
    const CACHE_TTL         = 21600;        // 6 hours

    /**
     * Check if the circuit breaker is OPEN for a chain.
     *
     * Returns true when the request should be blocked. Returns false for
     * CLOSED or when the caller has successfully claimed the HALF-OPEN
     * probe slot.
     *
     * Side effect (HALF-OPEN only): atomically claims a per-chain probe
     * via a MySQL advisory lock (GET_LOCK). Advisory locks are atomic
     * across connections and auto-release on session close, so they
     * work correctly regardless of whether the site has a persistent
     * object cache (Redis/Memcached) — an earlier wp_cache_add sentinel
     * silently degraded on vanilla WordPress where wp_cache is
     * request-scoped.
     *
     * The probe lock is released by {@see recordSuccess()} /
     * {@see recordFailure()} and by {@see releaseProbe()} (the
     * belt-and-braces release from {@see ApiRetry} for 3xx/4xx paths
     * that don't record an outcome). A crashed worker releases the
     * lock automatically when MySQL notices the connection drop.
     */
    public static function isOpen(int $chainId): bool
    {
        $state = self::getState($chainId);

        if ($state === null) {
            return false; // No state → CLOSED
        }

        $failures = (int) ($state['failures'] ?? 0);
        $openedAt = (int) ($state['opened_at'] ?? 0);

        if ($failures < self::FAILURE_THRESHOLD) {
            return false; // Below threshold → CLOSED
        }

        // Tripped — check if cooldown has expired (HALF-OPEN).
        if ($openedAt > 0 && (time() - $openedAt) >= self::COOLDOWN_SECONDS) {
            // Non-blocking (timeout=0) GET_LOCK. Succeeds for exactly one
            // caller across the cluster at a time, regardless of object
            // cache backend. If the lock is already held we stay in
            // blocked state and the caller backs off.
            if (\BCC\Core\DB\AdvisoryLock::acquire(self::PROBE_LOCK_PREFIX . $chainId, 0)) {
                return false; // We own the probe → allow the request through.
            }
            return true; // Another worker is probing → still blocked.
        }

        return true; // Still in cooldown → OPEN
    }

    /**
     * Release the probe lock for a chain. Idempotent — safe to call even
     * when this session does not currently hold the lock. Intended for
     * finally-block semantics in the HTTP wrapper so an unrecorded
     * outcome (3xx/4xx) cannot leave the probe slot held for the rest
     * of the PHP worker's life.
     */
    public static function releaseProbe(int $chainId): void
    {
        \BCC\Core\DB\AdvisoryLock::release(self::PROBE_LOCK_PREFIX . $chainId);
    }

    /**
     * Record a successful API call for a chain.
     * Resets the failure counter — circuit returns to CLOSED.
     */
    public static function recordSuccess(int $chainId): void
    {
        $state = self::getState($chainId);

        // Only write if there was a non-zero failure count to clear
        if ($state !== null && (int) ($state['failures'] ?? 0) > 0) {
            self::setState($chainId, [
                'failures'  => 0,
                'opened_at' => 0,
            ]);
        }

        // Also reset the atomic counter used by recordFailure() so the
        // two tracking mechanisms stay in sync. Without this, a success
        // would clear the state-struct but leave the wp_cache_incr
        // counter at its pre-success value, and the next failure would
        // increment from that stale value instead of 1.
        wp_cache_set('counter:' . $chainId, 0, self::CACHE_GROUP, self::CACHE_TTL);

        // Release the probe lock so subsequent traffic flows freely.
        // Without this, a healthy chain would remain effectively blocked
        // until MySQL auto-releases on session close if the probe
        // succeeded quickly.
        self::releaseProbe($chainId);

        // Track last successful fetch time (persists across cache flushes)
        update_option('bcc_onchain_last_success_' . $chainId, time(), false);
    }

    /**
     * Identify chains with stale data (no successful fetch within $maxAgeSec).
     *
     * @param int[] $chainIds   Active chain IDs to check.
     * @param int   $maxAgeSec  Maximum acceptable age in seconds (default 48 hours).
     * @return array<int, array{last_success: int|null, age_human: string, circuit_status: string}>
     *               Keyed by chain ID. Only stale/never-fetched chains included.
     */
    public static function getStaleChains(array $chainIds, int $maxAgeSec = 172800): array
    {
        $stale = [];
        $now   = time();

        foreach ($chainIds as $id) {
            $id          = (int) $id;
            $lastSuccess = get_option('bcc_onchain_last_success_' . $id, null);

            $isStale = ($lastSuccess === null)
                || (($now - (int) $lastSuccess) > $maxAgeSec);

            if (!$isStale) {
                continue;
            }

            // Determine circuit breaker status for context
            $cbState  = self::getState($id);
            $failures = (int) ($cbState['failures'] ?? 0);
            $openedAt = (int) ($cbState['opened_at'] ?? 0);

            if ($failures >= self::FAILURE_THRESHOLD) {
                $elapsed       = $now - $openedAt;
                $circuitStatus = $elapsed >= self::COOLDOWN_SECONDS ? 'HALF-OPEN' : 'OPEN';
            } else {
                $circuitStatus = 'CLOSED';
            }

            // Human-readable age
            if ($lastSuccess === null) {
                $ageHuman = 'never fetched';
            } else {
                $ageSec   = $now - (int) $lastSuccess;
                $ageDays  = floor($ageSec / DAY_IN_SECONDS);
                $ageHours = floor(($ageSec % DAY_IN_SECONDS) / HOUR_IN_SECONDS);
                if ($ageDays > 0) {
                    $ageHuman = sprintf('last success: %d day%s ago', $ageDays, (int) $ageDays === 1 ? '' : 's');
                } else {
                    $ageHuman = sprintf('last success: %d hour%s ago', $ageHours, (int) $ageHours === 1 ? '' : 's');
                }
            }

            $stale[$id] = [
                'last_success'   => $lastSuccess !== null ? (int) $lastSuccess : null,
                'age_human'      => $ageHuman,
                'circuit_status' => $circuitStatus,
            ];
        }

        return $stale;
    }

    /**
     * Record a failed API call for a chain.
     * Increments failure counter. If threshold reached, records opened_at.
     */
    public static function recordFailure(int $chainId): void
    {
        // Atomic counter via wp_cache_incr — closes the get→compute→set
        // lost-update race. Under a parallel failure storm two workers
        // could both read failures=4 and both write failures=5, missing
        // an increment; the circuit stayed closed longer than designed,
        // burning more API budget (and risking IP bans from providers).
        $counterKey = 'counter:' . $chainId;

        // Ensure the counter exists before incrementing; wp_cache_incr
        // returns false on a missing key in some drop-ins.
        wp_cache_add($counterKey, 0, self::CACHE_GROUP, self::CACHE_TTL);
        $failures = wp_cache_incr($counterKey, 1, self::CACHE_GROUP);
        if (!is_int($failures) || $failures <= 0) {
            // Backend degraded — fall back to read-modify-write so we at
            // least record SOMETHING. Still better than silently dropping
            // the failure signal.
            $state    = self::getState($chainId) ?? ['failures' => 0, 'opened_at' => 0];
            $failures = (int) ($state['failures'] ?? 0) + 1;
        }

        $state    = self::getState($chainId) ?? ['failures' => 0, 'opened_at' => 0];
        $openedAt = (int) ($state['opened_at'] ?? 0);

        // Restamp opened_at on:
        //   1. Initial threshold breach ($openedAt === 0), AND
        //   2. Failed probe during HALF-OPEN (cooldown already elapsed).
        // Without case 2 the breaker would re-enter HALF-OPEN on every
        // subsequent call forever, because (time() - openedAt) >= COOLDOWN
        // stays true — effectively disabling the breaker for the life of
        // the CACHE_TTL. Case 2 restarts the cooldown so the chain
        // actually gets the documented 5-minute rest.
        if ($failures >= self::FAILURE_THRESHOLD) {
            $cooldownElapsed = $openedAt > 0
                && (time() - $openedAt) >= self::COOLDOWN_SECONDS;
            if ($openedAt === 0 || $cooldownElapsed) {
                $previousOpenedAt = $openedAt;
                $openedAt         = time();
                self::log(sprintf(
                    $previousOpenedAt === 0
                        ? 'Circuit OPEN for chain %d — %d consecutive failures, pausing for %ds'
                        : 'Circuit RE-OPEN (probe failed) for chain %d — %d failures, restarting %ds cooldown',
                    $chainId, $failures, self::COOLDOWN_SECONDS
                ));
                // Probe failed — release the lock so a FUTURE half-open
                // window (after the fresh cooldown) can claim a new probe.
                self::releaseProbe($chainId);
            }
        }

        self::setState($chainId, [
            'failures'  => $failures,
            'opened_at' => $openedAt,
        ]);
    }

    /**
     * Get circuit breaker status for all active chains (admin dashboard).
     *
     * @param int[] $chainIds
     * @return array<int, array{failures: int, opened_at: int, status: string}>
     */
    public static function getAllStatus(array $chainIds): array
    {
        $result = [];
        foreach ($chainIds as $id) {
            $state    = self::getState((int) $id);
            $failures = (int) ($state['failures'] ?? 0);
            $openedAt = (int) ($state['opened_at'] ?? 0);

            if ($failures >= self::FAILURE_THRESHOLD) {
                $elapsed = time() - $openedAt;
                $status  = $elapsed >= self::COOLDOWN_SECONDS ? 'half-open' : 'open';
            } else {
                $status = 'closed';
            }

            $result[(int) $id] = [
                'failures'  => $failures,
                'opened_at' => $openedAt,
                'status'    => $status,
            ];
        }
        return $result;
    }

    // ── Storage ─────────────────────────────────────────────────────────────

    /**
     * @return array{failures: int, opened_at: int}|null
     *
     * NOTE: when the cached value is present but malformed (schema drift,
     * mid-deploy legacy keys, cache layer corruption) we return null which
     * re-initialises the breaker. If that happens repeatedly for the SAME
     * chain, the breaker is effectively disabled. We log once per 5-minute
     * window per chain so repeated corruption surfaces in monitoring instead
     * of silently eroding the protection.
     */
    private static function getState(int $chainId): ?array
    {
        $key   = 'cb_' . $chainId;
        $value = wp_cache_get($key, self::CACHE_GROUP);

        if ($value === false) {
            // Fallback to transient if Redis cache misses
            $value = get_transient('bcc_cb_' . $chainId);
            if ($value === false) {
                return null;
            }
        }

        if (!is_array($value) || !isset($value['failures'], $value['opened_at'])) {
            self::reportPersistentCorruption($chainId);
            return null;
        }

        return [
            'failures'  => (int) $value['failures'],
            'opened_at' => (int) $value['opened_at'],
        ];
    }

    /**
     * Rate-limited logger for malformed breaker state. Fires at most once per
     * 5-minute window per chain to avoid log flooding while still surfacing
     * persistent corruption patterns (e.g. a bad cache backend or a stuck
     * legacy key) to operators.
     *
     * CAVEAT — multi-node reliability: the dedup transient lives in whatever
     * backend WordPress is configured with. On a single node with Redis it is
     * consistent; on a multi-node setup WITHOUT a shared persistent object
     * cache, each node will log independently (their dedup transients are in
     * separate options tables). That's acceptable for this signal — the goal is
     * to surface the problem, and per-node logging actually HELPS diagnose
     * whether only one node's cache is corrupt. If this ever needs true global
     * dedup, move the key to a row in a small shared table.
     */
    private static function reportPersistentCorruption(int $chainId): void
    {
        $dedupKey = 'cb_corrupt_' . $chainId;
        if (get_transient($dedupKey) !== false) {
            return;
        }
        set_transient($dedupKey, 1, 5 * MINUTE_IN_SECONDS);

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[CircuitBreaker] malformed state — re-initialising', [
                'chain_id' => $chainId,
                'note'     => 'Repeated re-inits weaken protection. Investigate cache backend.',
            ]);
        }
    }

    /** @param array{failures: int, opened_at: int} $state */
    private static function setState(int $chainId, array $state): void
    {
        $key = 'cb_' . $chainId;
        wp_cache_set($key, $state, self::CACHE_GROUP, self::CACHE_TTL);
        // Transient fallback for environments without persistent object cache
        set_transient('bcc_cb_' . $chainId, $state, self::CACHE_TTL);
    }

    private static function log(string $message): void
    {
        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[CircuitBreaker] ' . $message);
        }
    }
}
