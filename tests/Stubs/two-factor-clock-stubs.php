<?php

declare(strict_types=1);

/**
 * F-292 TwoFactorResendTest: a CLOCK for the 2FA challenge lifecycle.
 *
 * Load BEFORE account-prehijack-stubs.php, in a separate process. The
 * shared stubs ignore transient TTLs and count Throttle hits forever; the
 * resend fix is entirely about time (a challenge expiring, a re-arm, an
 * absolute lifetime, a wrong-code counter that must not fade), so this file
 * pre-empts exactly those pieces and leaves everything else to the shared
 * file:
 *
 *  - `time()` in BCC\Trust\Core\REST\Auth reads the test clock;
 *  - transients EXPIRE at set-time + TTL on that clock (same backing array
 *    as the shared stubs, so their assertions still read it);
 *  - Throttle reproduces RateLimiter's sliding window, including the
 *    linear decay of the previous bucket, which is what would let a
 *    too-short wrong-code window refill.
 */

namespace {
    final class TwoFaClock
    {
        public static int $now = 6000000;
        /** @var array<string, int> transient key => expires at */
        public static array $expires = [];
        /** @var array<string, int> "bucket key|bucket index" => count */
        public static array $buckets = [];

        public static function reset(): void
        {
            self::$now     = 6000000;
            self::$expires = [];
            self::$buckets = [];
        }

        public static function advance(int $seconds): void
        {
            self::$now += $seconds;
        }
    }

    if (!function_exists('get_transient')) {
        /** @return mixed */
        function get_transient(string $key)
        {
            $exp = TwoFaClock::$expires[$key] ?? 0;
            if ($exp > 0 && TwoFaClock::$now >= $exp) {
                unset($GLOBALS['__bcc_ph']['transients'][$key], TwoFaClock::$expires[$key]);
                return false;
            }
            return $GLOBALS['__bcc_ph']['transients'][$key] ?? false;
        }
    }

    if (!function_exists('set_transient')) {
        /** @param mixed $value */
        function set_transient(string $key, $value, int $ttl = 0): bool
        {
            $GLOBALS['__bcc_ph']['transients'][$key] = $value;
            TwoFaClock::$expires[$key] = $ttl > 0 ? TwoFaClock::$now + $ttl : 0;
            return true;
        }
    }

    if (!function_exists('delete_transient')) {
        function delete_transient(string $key): bool
        {
            unset($GLOBALS['__bcc_ph']['transients'][$key], TwoFaClock::$expires[$key]);
            return true;
        }
    }
}

namespace BCC\Trust\Core\REST\Auth {
    if (!function_exists(__NAMESPACE__ . '\\time')) {
        function time(): int
        {
            return \TwoFaClock::$now;
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(__NAMESPACE__ . '\\Throttle', false)) {
        /**
         * RateLimiter::slidingWindowCheck on the test clock: increment the
         * current bucket, then allow while
         * cur*100 + prev*weightPct <= limit*100.
         */
        final class Throttle
        {
            public static function allow(string $action, int $limit = 10, int $window = 60, ?string $key = null): bool
            {
                $bucketKey = $key ?? $action;
                $window    = max(1, $window);
                $now       = \TwoFaClock::$now;
                $bucket    = intdiv($now, $window);

                $cur = (\TwoFaClock::$buckets[$bucketKey . '|' . $window . '|' . $bucket] ?? 0) + 1;
                \TwoFaClock::$buckets[$bucketKey . '|' . $window . '|' . $bucket] = $cur;
                $prev = \TwoFaClock::$buckets[$bucketKey . '|' . $window . '|' . ($bucket - 1)] ?? 0;

                $elapsed   = $now - ($bucket * $window);
                $weightPct = max(0, min(100, 100 - intdiv($elapsed * 100, $window)));

                return ($cur * 100) + ($prev * $weightPct) <= $limit * 100;
            }

            /** @return null */
            public static function lastDenial()
            {
                return null;
            }
        }
    }
}
