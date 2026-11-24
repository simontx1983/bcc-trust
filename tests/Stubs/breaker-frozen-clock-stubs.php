<?php

declare(strict_types=1);

/**
 * F-577: a frozen clock for the REAL {@see \BCC\Trust\Onchain\Support\OnchainCircuitBreaker},
 * used by BreakerReaderAgreementTest's cooldown-boundary cases.
 *
 * Every breaker reader calls `time()` unqualified from
 * `BCC\Trust\Onchain\Support`, so PHP resolves the namespaced `time()` below
 * first. A boundary case seeds `opened_at = now - offset` and then reads. On
 * the wall clock a second can tick between the two, and "one second before"
 * becomes "exactly on". On this clock the seed and every read share one
 * instant, so each offset is exact.
 *
 * ⚠ INERT UNLESS A TEST SETS `BccBreakerClock::$now`. While it is null this
 * delegates to the real `\time()`, so the other cases in the file see real
 * time. A `function_exists`-guarded definition is process-global, which is
 * why only BreakerReaderAgreementTest loads this file and why it runs each
 * test in a separate process. `apiretry-real-stubs.php` shadows `time()` in
 * the same namespace for ApiRetry. The two files are never loaded together,
 * and the test asserts this clock is the one the breaker reads.
 */

namespace {
    final class BccBreakerClock
    {
        /** The instant every breaker read sees; null = the real clock. */
        public static ?int $now = null;
    }
}

namespace BCC\Trust\Onchain\Support {
    if (!function_exists(__NAMESPACE__ . '\\time')) {
        function time(): int
        {
            return \BccBreakerClock::$now ?? \time();
        }
    }
}
