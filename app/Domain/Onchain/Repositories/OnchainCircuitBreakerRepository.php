<?php

namespace BCC\Trust\Onchain\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage for the per-chain DB-backed failure counter used by
 * {@see \BCC\Trust\Onchain\Support\OnchainCircuitBreaker}.
 *
 * The counter lives in wp_options as a non-autoloaded option so it never
 * bloats the bootstrap options preload. It is incremented with an atomic
 * INSERT … ON DUPLICATE KEY UPDATE using LAST_INSERT_ID(expr) so parallel
 * failure storms cannot drop increments (the get→compute→set lost-update
 * race that a wp_cache_incr-based counter suffered on non-atomic drop-ins).
 *
 * §1 remediation: the breaker's raw $wpdb counter ops moved here verbatim —
 * the atomic-increment statement is race-safety-critical and is preserved
 * byte-for-byte. Static to match the surrounding Onchain repository idiom
 * (e.g. ChainRepository), since the breaker that owns it is fully static.
 */
final class OnchainCircuitBreakerRepository
{
    /**
     * Atomically increment the per-chain failure counter and return the new
     * value.
     *
     * Uses INSERT … ON DUPLICATE KEY UPDATE with LAST_INSERT_ID(expr) so the
     * incremented value is exposed via $wpdb->insert_id without a second
     * round-trip — closing the get→compute→set lost-update race. Returns:
     *   - null on DB error (caller falls back to read-modify-write of state),
     *   - 1 on a fresh insert,
     *   - the new count otherwise (falling back to a SELECT if insert_id did
     *     not propagate).
     *
     * Bounded by the option_name unique key.
     */
    public static function incrementFailureCounter(string $optionName): ?int
    {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE
               option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1)",
            $optionName
        ));

        if ($result === false) {
            return null;
        }

        if ($result === 1) {
            // Fresh insert — counter is exactly 1.
            return 1;
        }

        // Existing row updated; LAST_INSERT_ID(expr) exposes the new count
        // via $wpdb->insert_id without a second round-trip.
        $failures = (int) $wpdb->insert_id;
        if ($failures <= 0) {
            // Defensive: insert_id not propagated for some reason. Fall back
            // to a SELECT rather than report 0.
            $raw = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $optionName
            ));
            $failures = max(1, (int) $raw);
        }

        return $failures;
    }

    /**
     * Delete the per-chain failure counter row. Bounded by the option_name
     * unique key. The next failure re-creates it from 1 via
     * incrementFailureCounter()'s INSERT … ON DUPLICATE KEY UPDATE.
     */
    public static function deleteCounter(string $optionName): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s",
            $optionName
        ));
    }
}
