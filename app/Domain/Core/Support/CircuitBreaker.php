<?php
/**
 * Circuit breaker for flaky external dependencies.
 *
 * Tracks consecutive failures per named endpoint. Once the failure count
 * crosses a threshold inside a short counting window, the breaker OPENS and
 * all subsequent calls short-circuit (return false from allow()) for a
 * cooldown period — sparing the dependency from a retry stampede and
 * sparing callers from a timeout stampede.
 *
 * Use:
 *
 *     if (!CircuitBreaker::allow('cosmos:lcd.cosmos.network')) {
 *         return $cachedFailureResult;
 *     }
 *     try {
 *         $response = $client->get(...);
 *         CircuitBreaker::recordSuccess('cosmos:lcd.cosmos.network');
 *     } catch (\Throwable $e) {
 *         CircuitBreaker::recordFailure('cosmos:lcd.cosmos.network', $e);
 *         return $failureSentinel;
 *     }
 *
 * Storage is WordPress transients (object-cache backed when Redis is up,
 * DB otherwise). Values survive a worker crash; the cooldown TTL bounds
 * how long the breaker can stay open on a truly dead dependency.
 *
 * @package BCC\Trust\Core\Support
 */

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class CircuitBreaker
{
    /** @var int Consecutive failures that trip the breaker. */
    private const FAIL_THRESHOLD = 3;

    /** @var int Seconds of silence that resets the fail counter. */
    private const COUNT_WINDOW = 300;

    /** @var int Seconds the breaker stays OPEN after tripping. */
    private const OPEN_TTL = 600;

    /** @var string wp_options option prefix for the open-state marker. */
    private const OPEN_PREFIX = 'bcc_trust_cb_open_';

    /** @var string wp_options option prefix for the fail counter. */
    private const FAIL_PREFIX = 'bcc_trust_cb_fail_';

    /**
     * Return false when the named circuit is OPEN (skip the call).
     *
     * Callers MUST check this before attempting the protected work and
     * have a sensible fallback when it returns false. Returning true
     * does NOT guarantee the work will succeed — it only means the
     * breaker is not currently suppressing attempts.
     */
    public static function allow(string $key): bool
    {
        $openUntil = (int) get_transient(self::OPEN_PREFIX . self::safeKey($key));
        if ($openUntil === 0) {
            return true;
        }
        if ($openUntil <= time()) {
            delete_transient(self::OPEN_PREFIX . self::safeKey($key));
            return true;
        }
        return false;
    }

    /**
     * Record a successful call. Clears any pending fail count.
     */
    public static function recordSuccess(string $key): void
    {
        delete_transient(self::FAIL_PREFIX . self::safeKey($key));
        delete_transient(self::OPEN_PREFIX . self::safeKey($key));
    }

    /**
     * Record a failure. Trips the breaker if the threshold is crossed.
     */
    public static function recordFailure(string $key, ?\Throwable $e = null): void
    {
        $safe  = self::safeKey($key);
        $count = (int) get_transient(self::FAIL_PREFIX . $safe) + 1;
        set_transient(self::FAIL_PREFIX . $safe, $count, self::COUNT_WINDOW);

        if ($count >= self::FAIL_THRESHOLD) {
            $openUntil = time() + self::OPEN_TTL;
            set_transient(self::OPEN_PREFIX . $safe, $openUntil, self::OPEN_TTL);

            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning(
                    "[bcc-trust] CircuitBreaker OPEN for '{$key}' for " . self::OPEN_TTL . 's',
                    [
                        'key'         => $key,
                        'fail_count'  => $count,
                        'open_until'  => gmdate('c', $openUntil),
                        'last_error'  => $e ? $e->getMessage() : null,
                    ]
                );
            }
        }
    }

    /**
     * Returns a snapshot of breaker state for health dashboards. Empty
     * when the breaker is closed.
     *
     * @return array{open: bool, open_until?: string, fail_count?: int}
     */
    public static function status(string $key): array
    {
        $safe      = self::safeKey($key);
        $openUntil = (int) get_transient(self::OPEN_PREFIX . $safe);
        $failCount = (int) get_transient(self::FAIL_PREFIX . $safe);

        if ($openUntil > time()) {
            return [
                'open'       => true,
                'open_until' => gmdate('c', $openUntil),
                'fail_count' => $failCount,
            ];
        }
        return ['open' => false];
    }

    /**
     * Transient key names are truncated to 172 chars on persistent backends;
     * hash long keys so pathological inputs don't silently collide.
     */
    private static function safeKey(string $key): string
    {
        if (strlen($key) > 80) {
            return substr(md5($key), 0, 32);
        }
        return preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $key) ?? md5($key);
    }
}
