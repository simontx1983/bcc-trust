<?php

declare(strict_types=1);

/**
 * Doubles for measuring BREAKER COUNTER DELTAS through a real worker.
 *
 * ── WHY A THIRD "REAL" STUB SET, AND WHY IT IS MOSTLY COMPOSITION ───────
 * PR B's central claim is arithmetic: ONE logical provider request costs the
 * chain-wide breaker AT MOST ONE failure, even when the transport retried it
 * four times AND the caller then formed its own verdict about the same failed
 * result. Proving that needs three things real at once:
 *
 *   1. the real {@see \BCC\Trust\Onchain\Support\ApiRetry} retry loop,
 *   2. the real {@see \BCC\Trust\Onchain\Support\OnchainCircuitBreaker} and
 *      its real atomic counter — the number an operator would read, not a
 *      mock's call list,
 *   3. the real caller, so the domain verdict under test is the one that
 *      ships rather than a copy of its rule written in the test.
 *
 * No existing stub set has all three. `nft-indexer-stubs.php` drives the real
 * worker but fakes both the transport and the breaker, so it can only observe
 * that `recordFailure()` was called — the exact measurement a per-attempt
 * regression would still satisfy.
 *
 * ── HOW COMPOSITION WORKS HERE ──────────────────────────────────────────
 * Every class in those files is declared behind `class_exists(…, false)`. So
 * loading the REAL `ApiRetry` and `OnchainCircuitBreaker` FIRST makes
 * `nft-indexer-stubs.php` skip its fakes for exactly those two and still
 * supply the repositories, the factory and the observability surface the
 * worker needs. Nothing is duplicated, and the skip is asserted below rather
 * than assumed — a silently-faked breaker would turn every delta in this
 * suite into a number about nothing.
 *
 * ⚠ THE WIRE IS THE ONLY FAKE. Retry counts, backoff decisions, the 429 and
 * 4xx branches, the single settlement point and every counter write are
 * production code executing for real.
 *
 * @package BCC\Trust\Onchain\Tests\Stubs
 */

namespace {
    // Transport surface + BccWire + WP_Error + SafeHttpClient, and (via its
    // own require) the option/transient/cache/lock/counter surface the real
    // breaker needs.
    require_once __DIR__ . '/enumeration-real-transport-stubs.php';

    // ⚠ FORCE THE REAL CLASSES TO BE DECLARED BEFORE THE FAKES GET A CHANCE.
    // These two autoload the production code; the guards in the file required
    // below then decline to redefine them.
    if (!class_exists(\BCC\Trust\Onchain\Support\ApiRetry::class)) {
        throw new \RuntimeException('the real ApiRetry must be autoloadable for this harness');
    }
    if (!class_exists(\BCC\Trust\Onchain\Support\OnchainCircuitBreaker::class)) {
        throw new \RuntimeException('the real OnchainCircuitBreaker must be autoloadable for this harness');
    }

    if (!function_exists('wp_remote_post')) {
        /**
         * The EVM paths post JSON-RPC, so the scripted wire has to answer POST
         * as well as GET. Same queue, same recording, so a scenario scripts one
         * list regardless of verb.
         *
         * @param array<string, mixed> $args
         * @return array{code: int, body: string, headers?: array<string, string>}|\WP_Error
         */
        function wp_remote_post(string $url, array $args = [])
        {
            return \BccWire::next($url);
        }
    }

    // Repositories, FetcherFactory and observability — everything the worker
    // touches that is neither transport nor breaker.
    require_once __DIR__ . '/nft-indexer-stubs.php';

    /**
     * Assert the composition actually happened.
     *
     * If either of these ever became a fake, the counter assertions in
     * BreakerChargePerLogicalRequestTest would silently become assertions
     * about a recording double — green, and worthless.
     */
    final class BccRealStackGuard
    {
        /** @return array{api_retry: string, breaker: string} */
        public static function provenance(): array
        {
            return [
                'api_retry' => (string) (new \ReflectionClass(\BCC\Trust\Onchain\Support\ApiRetry::class))->getFileName(),
                'breaker'   => (string) (new \ReflectionClass(\BCC\Trust\Onchain\Support\OnchainCircuitBreaker::class))->getFileName(),
            ];
        }
    }
}
