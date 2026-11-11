<?php

declare(strict_types=1);

/**
 * F-181 — a controllable clock for CollectionRepository, test-only.
 *
 * `CollectionRepository::upsert()` stamps `fetched_at` (current_time) and
 * `expires_at` (time() + TTL). Both calls are UNQUALIFIED inside the
 * `BCC\Trust\Onchain\Repositories` namespace, so functions declared here
 * resolve first — the same seam tests/Stubs/{apiretry-real,cron-schedule,
 * discovery-retirement}-stubs.php use for `time()`.
 *
 * With `$GLOBALS['__bcc_collection_clock']` unset both fall straight through
 * to the real clock, so loading this file changes nothing for any other test
 * in the process. Set it to a Unix timestamp to freeze the clock.
 */

namespace BCC\Trust\Onchain\Repositories;

if (!function_exists(__NAMESPACE__ . '\\time')) {
    function time(): int
    {
        $frozen = $GLOBALS['__bcc_collection_clock'] ?? null;
        return is_int($frozen) ? $frozen : \time();
    }
}

if (!function_exists(__NAMESPACE__ . '\\current_time')) {
    /** @return int|string */
    function current_time(string $type, int|bool $gmt = 0)
    {
        $frozen = $GLOBALS['__bcc_collection_clock'] ?? null;
        if (!is_int($frozen)) {
            return \current_time($type, $gmt);
        }
        if ($type === 'timestamp' || $type === 'U') {
            return $frozen;
        }
        return $gmt ? gmdate('Y-m-d H:i:s', $frozen) : date('Y-m-d H:i:s', $frozen);
    }
}
