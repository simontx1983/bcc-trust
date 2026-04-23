<?php

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database operations for the sliding-window rate limiter.
 *
 * All rate-limit counters are stored as wp_options rows with a
 * "count|expires" value format. This repository isolates every
 * $wpdb call so that RateLimiter contains no direct DB access.
 *
 * @package BCC\Trust\Core\Repositories
 */
final class RateLimitRepository
{
    /**
     * Atomically increment (or initialise) a bucket counter and return the
     * post-increment count.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE with the LAST_INSERT_ID(expr)
     * trick so the new count is exposed via $wpdb->insert_id without a
     * second round-trip. The whole operation is a single atomic statement —
     * no read-then-write race.
     *
     * Return value:
     *   int   The new count after this increment (>= 1).
     *   null  Database failure — caller MUST fail closed (deny the request).
     *
     * @param string $optionKey  Full option_name for the bucket.
     * @param string $freshValue Initial value if the row does not exist ("1|<expires>").
     * @return int|null New count, or null on DB failure.
     */
    public static function incrementBucket(string $optionKey, string $freshValue): ?int
    {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %s, 'no')
             ON DUPLICATE KEY UPDATE
               option_value = CONCAT(
                 LAST_INSERT_ID(CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) + 1),
                 '|',
                 SUBSTRING_INDEX(option_value, '|', -1)
               )",
            $optionKey,
            $freshValue
        ));

        // Fail closed: any DB error must deny the request rather than
        // silently allow it. The previous implementation discarded the
        // return value entirely, so connection drops / deadlocks let
        // every subsequent rate-limit check pass.
        if ($result === false) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-trust] rate_limit_increment_failed', [
                    'bucket_key' => $optionKey,
                    'db_error'   => $wpdb->last_error,
                ]);
            }
            return null;
        }

        // INSERT ... ON DUPLICATE KEY UPDATE returns:
        //   1 = a fresh INSERT happened (count is exactly 1 from $freshValue)
        //   2 = the existing row was updated (count is in $wpdb->insert_id
        //       courtesy of the LAST_INSERT_ID(expr) call above)
        //   0 = no change (shouldn't happen for ODKU, but treat as failure)
        if ($result === 1) {
            // Fresh insert: write the companion _transient_timeout_* row so
            // WP's native transient-expiry path also reaps these keys. The
            // bcc-core bcc_core_rl_cleanup cron already covers this range,
            // but the companion row gives us belt-and-suspenders GC
            // (expired-read auto-delete via get_transient + WP core GC).
            self::writeTransientTimeoutCompanion($optionKey, $freshValue);
            return 1;
        }

        $newCount = (int) $wpdb->insert_id;

        // Defensive: if insert_id was not propagated for any reason,
        // fall back to a single SELECT rather than returning a misleading 0.
        if ($newCount <= 0) {
            $raw = self::getBucketCount($optionKey);
            // getBucketCount now returns null on DB error. Treat null or 0
            // as "unknown / failed" and propagate null so the caller
            // fails closed. A positive raw value is the recovered count.
            return ($raw !== null && $raw > 0) ? $raw : null;
        }

        return $newCount;
    }

    /**
     * Write the _transient_timeout_* companion row used by WP's native
     * transient GC. Silent no-op when $optionKey is not a _transient_*
     * row (the rate limiter's key format always is, but callers may pass
     * bare keys in future). Best-effort — failure is logged but never
     * propagated since the primary counter INSERT has already succeeded
     * and the bcc-core cleanup cron is the authoritative GC path.
     */
    private static function writeTransientTimeoutCompanion(string $optionKey, string $freshValue): void
    {
        global $wpdb;

        $prefix = '_transient_';
        if (strncmp($optionKey, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        // Parse expires from the "count|expires" freshValue. The counter
        // INSERT just committed this same value, so on failure to parse
        // we simply skip — the bcc-core range cleanup still reaps these.
        $parts     = explode('|', $freshValue, 2);
        $expiresAt = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($expiresAt <= 0) {
            return;
        }

        // Transient name is the key without the _transient_ prefix.
        // Companion row name is _transient_timeout_{name}.
        $transientName = substr($optionKey, strlen($prefix));
        $timeoutKey    = '_transient_timeout_' . $transientName;

        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %s, 'no')",
            $timeoutKey,
            (string) $expiresAt
        ));

        if ($result === false && class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::error('[bcc-trust] rate_limit_timeout_companion_failed', [
                'bucket_key'  => $optionKey,
                'timeout_key' => $timeoutKey,
                'db_error'    => $wpdb->last_error,
            ]);
        }
    }

    /**
     * Atomically decrement a bucket counter, clamped to zero.
     *
     * Used to undo an increment when a rate-limit check determines the
     * request is over the limit (increment-first pattern).
     *
     * @param string $optionKey Full option_name for the bucket.
     */
    public static function decrementBucket(string $optionKey): bool
    {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = CONCAT(
               GREATEST(CAST(SUBSTRING_INDEX(option_value, '|', 1) AS SIGNED) - 1, 0),
               '|',
               SUBSTRING_INDEX(option_value, '|', -1)
             )
             WHERE option_name = %s",
            $optionKey
        ));

        if ($result === false) {
            // Decrement failed — the denied request's increment stays,
            // consuming one slot of legitimate capacity until the bucket
            // expires. Log so sustained DB issues are visible in monitoring.
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-trust] rate_limit_decrement_failed', [
                    'bucket_key' => $optionKey,
                    'db_error'   => $wpdb->last_error,
                ]);
            }
            return false;
        }

        return true;
    }

    /**
     * Fetch the counter values for the current and previous bucket keys.
     *
     * @param string $currentKey  Option name for the current bucket.
     * @param string $previousKey Option name for the previous bucket.
     * @return array{current: int, previous: int} Parsed counts.
     */
    public static function getBucketCounts(string $currentKey, string $previousKey): array
    {
        global $wpdb;

        /** @var array<int, object{option_name: string, option_value: string}> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
            $currentKey,
            $previousKey
        ));

        $current  = 0;
        $previous = 0;

        foreach ($rows as $row) {
            $cnt = (int) explode('|', $row->option_value, 2)[0];
            if ($row->option_name === $currentKey) {
                $current = $cnt;
            } else {
                $previous = $cnt;
            }
        }

        return ['current' => $current, 'previous' => $previous];
    }

    /**
     * Read the raw counter value for a single bucket key.
     *
     * Returns null on DB error so the caller can distinguish a true
     * "row missing" (0) from a transient read failure that the caller
     * must treat as fail-closed. The previous variant collapsed both
     * cases to 0, which let attackers timing requests across a bucket
     * boundary during DB stress deliver up to 2× the configured limit
     * (audit CRIT-1).
     *
     * @param string $optionKey Full option_name.
     * @return int|null The count portion of the stored value, 0 if
     *                  missing, or null on DB error.
     */
    public static function getBucketCount(string $optionKey): ?int
    {
        global $wpdb;

        // Clear prior error state so we can unambiguously detect a fresh
        // failure from this read alone.
        $wpdb->last_error = '';

        /** @var string|null $value */
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $optionKey
        ));

        if (is_string($value)) {
            return (int) explode('|', $value, 2)[0];
        }

        // A non-empty last_error on a null return means the read itself
        // failed — caller must fail closed.  A null with empty last_error
        // is "row missing" (0).  PHPStan types wpdb->last_error as the
        // literal empty string from its stub, which would make !== ''
        // always-false; copy into a local string and inspect length to
        // defeat the stub narrowing without suppressing the check.
        $lastError = (string) $wpdb->last_error;
        if (strlen($lastError) > 0) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-trust] rate_limit_read_failed', [
                    'bucket_key' => $optionKey,
                    'db_error'   => $lastError,
                ]);
            }
            return null;
        }

        return 0;
    }

    /**
     * Read the raw option value (count|expires) for a bucket key.
     *
     * @param string $optionKey Full option_name.
     * @return string|null The stored value, or null if not found.
     */
    public static function getBucketRaw(string $optionKey): ?string
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $optionKey
        ));

        return is_string($value) ? $value : null;
    }
}
