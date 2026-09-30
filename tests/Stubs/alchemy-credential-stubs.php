<?php

/**
 * Collaborators for {@see \BCC\Trust\Onchain\Support\AlchemyCredential} and the
 * EVM consumers that resolve through it.
 *
 * ── ⚠⚠⚠ THE TRANSPORT SENTINEL, AND WHY IT IS AT THIS EXACT SEAM ─────────
 * One guarantee under test is NEGATIVE: with no Alchemy credential configured, a
 * blocked request must make ZERO transport attempts. A test that only checked the
 * return value would pass on an implementation that fired the request first and
 * discarded the response — which is worse than the bug it replaced, because it
 * spends quota and hands a credentialed URL to a provider's logs.
 *
 * The seam matters. {@see \BCC\Trust\Onchain\Support\ApiRetry::post()} does this,
 * in this order:
 *
 *   1. SafeHttpClient::prepareArgs()      <-- OUTER SENTINEL, first statement
 *   2. self::request(...)
 *        2a. OnchainCircuitBreaker::isOpen()   needs cache + transients +
 *                                              BCC\Core\DB\AdvisoryLock
 *        2b. wp_remote_post()             <-- INNER SENTINEL
 *
 * An earlier version of this file shimmed only step 2b. That was NOT a usable
 * regression control: a request that wrongly reached the transport died at step
 * 2a with `Class "BCC\Core\DB\AdvisoryLock" not found` — a failure, yes, but one
 * whose message names an unrelated class and which would equally be produced by a
 * dozen unrelated breakages. A generic class-not-found is not a tripwire.
 *
 * `prepareArgs()` is the FIRST thing on the path and needs nothing, so it is the
 * deterministic sentinel. Step 2b is kept as a second, deeper one: together they
 * distinguish "never entered the transport layer" from "entered it but was
 * stopped by the breaker".
 *
 * ── FAIL-IF-CALLED ──────────────────────────────────────────────────────
 * {@see TransportSentinel::$failIfCalled} makes either sentinel THROW
 * {@see TransportAttempted} the moment it is touched. A blocked-path test arms it,
 * so a regression fails with a message that names the actual defect instead of
 * being inferred from a count. Tests that legitimately expect a call disarm it and
 * assert on the recorded attempts.
 *
 * ⚠ URLs are recorded but the tests never print them — recording exists so a test
 * can prove WHICH endpoint was contacted without emitting one.
 *
 * @package BCC\Trust\Tests
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Support {

    /**
     * Thrown the instant an armed sentinel is touched.
     *
     * A dedicated type, so a test can assert THIS and not merely "something threw"
     * — the whole point of replacing the class-not-found tripwire.
     */
    final class TransportAttempted extends \RuntimeException
    {
    }
}

namespace BCC\Trust\Onchain\Support {

    use BCC\Trust\Onchain\Tests\Support\TransportAttempted;

    /**
     * Records — and optionally refuses — every outbound attempt.
     */
    final class TransportSentinel
    {
        public static bool $active = false;

        /** Arm to throw {@see TransportAttempted} on any attempt. */
        public static bool $failIfCalled = false;

        /** @var list<array{seam: string, method: string, url: string}> */
        public static array $attempts = [];

        /** @var array<string, mixed>|\WP_Error|null Response the inner sentinel returns. */
        public static $response = null;

        public static function reset(bool $failIfCalled = false): void
        {
            self::$active       = true;
            self::$failIfCalled = $failIfCalled;
            self::$attempts     = [];
            self::$response     = ['response' => ['code' => 200], 'body' => '{"result":"0x1"}'];
        }

        /** @throws TransportAttempted when armed. */
        public static function record(string $seam, string $method, string $url): void
        {
            self::$attempts[] = ['seam' => $seam, 'method' => $method, 'url' => $url];

            if (self::$failIfCalled) {
                // ⚠ The message names the seam and NOT the url — an exception
                // message is an output, and outputs do not carry endpoints.
                throw new TransportAttempted(
                    'transport attempted at seam "' . $seam . '" (' . $method . ') — a request that '
                    . 'should have been refused before the transport reached it'
                );
            }
        }

        public static function count(): int
        {
            return count(self::$attempts);
        }

        /** @return list<string> */
        public static function urls(): array
        {
            return array_map(static fn(array $a): string => $a['url'], self::$attempts);
        }

        /** @return list<string> */
        public static function seams(): array
        {
            return array_values(array_unique(array_map(static fn(array $a): string => $a['seam'], self::$attempts)));
        }

        /** Was the sentinel left completely untouched? */
        public static function untouched(): bool
        {
            return self::$attempts === [];
        }
    }

    // ── INNER SENTINEL: the WP HTTP functions ApiRetry calls UNQUALIFIED ─────
    //
    // PHP resolves an unqualified `wp_remote_post()` inside this namespace to
    // `BCC\Trust\Onchain\Support\wp_remote_post` before the global function, which
    // is what makes these effective without touching production code.

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_get')) {
        /**
         * @param array<string, mixed> $args
         * @return array<string, mixed>|\WP_Error
         */
        function wp_remote_get(string $url, array $args = [])
        {
            if (!TransportSentinel::$active) {
                return \wp_remote_get($url, $args);
            }
            TransportSentinel::record('wp_remote_*', 'GET', $url);

            return TransportSentinel::$response;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_post')) {
        /**
         * @param array<string, mixed> $args
         * @return array<string, mixed>|\WP_Error
         */
        function wp_remote_post(string $url, array $args = [])
        {
            if (!TransportSentinel::$active) {
                return \wp_remote_post($url, $args);
            }
            TransportSentinel::record('wp_remote_*', 'POST', $url);

            return TransportSentinel::$response;
        }
    }
}

namespace BCC\Core\Http {

    use BCC\Trust\Onchain\Support\TransportSentinel;

    /**
     * ── OUTER SENTINEL ───────────────────────────────────────────────────
     * `prepareArgs()` is the FIRST statement in `ApiRetry::post()`/`get()`, ahead
     * of the circuit breaker and everything it drags in, so it fires on any
     * request that enters the transport layer at all.
     *
     * Pass-through on success: SSRF hardening is bcc-core's to test, and a fake
     * that rejected URLs would make "no provider call was made" pass for the wrong
     * reason.
     *
     * ⚠ Guarded with `class_exists(…, false)`. Another stub file may have declared
     * this class already; an unguarded declaration is a fatal error naming THIS
     * file while the real cause is load order.
     */
    if (!class_exists(SafeHttpClient::class, false)) {
        final class SafeHttpClient
        {
            /**
             * @param  array<string, mixed> $args
             * @return array<string, mixed>|\WP_Error
             */
            public static function prepareArgs(string $url, array $args)
            {
                if (TransportSentinel::$active) {
                    TransportSentinel::record('SafeHttpClient::prepareArgs', 'PREPARE', $url);
                }

                return $args;
            }
        }
    }
}

namespace {
    require_once __DIR__ . '/nft-capability-stubs.php';
}
