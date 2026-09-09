<?php

declare(strict_types=1);

/**
 * Doubles for running the REAL transport stack end to end:
 * {@see \BCC\Trust\Onchain\Fetchers\CosmosFetcher::listContractsForCodeId()}
 * → {@see \BCC\Trust\Onchain\Support\ApiRetry} →
 * {@see \BCC\Trust\Onchain\Support\OnchainCircuitBreaker}.
 *
 * ── WHY EVERY ONE OF THOSE THREE IS REAL ────────────────────────────────
 * PR 7.7 claims that `CosmwasmEnumerationFailure::isProviderFault()` is a
 * SUPERSET of the rule `ApiRetry` uses to charge the breaker — i.e. that no
 * breaker-charging enumeration failure can go unrecorded. That claim is
 * about the agreement between two independently-written rules, so a test
 * that fakes either one proves nothing: it would compare the predicate to
 * a second copy of itself.
 *
 * PR 7.6 learned this the expensive way twice — a source-text test could
 * not see a behavioural bypass, and a breaker fix that unified three of
 * four readers left the fourth computing its own arithmetic. So here the
 * ONLY fake is the wire.
 *
 * ⚠ THE WIRE IS THE FAKE, AND NOTHING ELSE IS. `wp_remote_get` is scripted
 * from {@see BccWire}; retry counts, backoff decisions, the 429 branch, the
 * 4xx branch, the application-error hook and every `recordFailure()` call
 * are production code executing for real.
 *
 * ── RELATIONSHIP TO THE TWO EXISTING "REAL" STUB SETS ───────────────────
 * This EXTENDS rather than duplicates them, and requires one of them:
 *
 *   - `breaker-real-stubs.php` — REQUIRED below. Supplies the option /
 *     transient / object-cache surface, the advisory lock, the logger and
 *     the atomic counter repository, so the real breaker keeps its faithful
 *     two-store split. Nothing here re-declares any of that.
 *   - `apiretry-real-stubs.php` — real ApiRetry with a RECORDING breaker,
 *     driven by handing `ApiRetry::request()` a closure. Right for asserting
 *     what the retry loop was told; it deliberately has no HTTP surface, so
 *     it cannot drive a production FETCHER method.
 *
 * What is genuinely new here, and the only reason this file exists, is the
 * `wp_remote_get` + `SafeHttpClient` surface that lets the REAL
 * `CosmosFetcher::listContractsForCodeId()` run end to end. PR 7.7's gap was
 * found in production precisely because no test had ever driven that method
 * over a real transport.
 *
 * @package BCC\Trust\Onchain\Tests\Stubs
 */

namespace {

// ⚠ THE REQUIRE LIVES INSIDE THE BRACED GLOBAL NAMESPACE, not above it.
// A file that uses braced namespace blocks may not contain ANY code outside
// them — a bare `require_once` at the top is a fatal parse error, not a
// warning, and the same trap has now bitten this repo three times.
//
// Brings in the option / transient / object-cache surface, the advisory
// lock, the logger and the atomic counter repository — all already faithful
// there, including the deliberate split between the no-expiry counter and
// the TTL'd open-state that produced the chain-18 drift.
require_once __DIR__ . '/breaker-real-stubs.php';

/** The scripted wire. One entry consumed per HTTP attempt, in order. */
final class BccWire
{
    /**
     * Responses to hand back, oldest first. Each is either
     * `['code' => int, 'body' => string, 'headers' => array<string,string>]`
     * or a WP_Error.
     *
     * @var list<array{code: int, body: string, headers?: array<string, string>}|\WP_Error>
     */
    public static array $queue = [];

    /**
     * Used when the queue is empty, so a scenario states its steady state
     * once instead of padding the queue to the retry count.
     *
     * @var array{code: int, body: string, headers?: array<string, string>}|\WP_Error|null
     */
    public static $always = null;

    /**
     * Every URL requested, in order. Anti-vacuity: proves the wire ran.
     *
     * @var list<string>
     */
    public static array $urls = [];

    /**
     * Every backoff the real retry loop asked for, in seconds and in order.
     *
     * ⚠ KEPT SEPARATE FROM `$urls`. Folding them together would let a
     * scenario that only ever slept satisfy an assertion meant to prove a
     * request was actually made.
     *
     * @var list<int>
     */
    public static array $sleeps = [];

    public static function reset(): void
    {
        self::$queue  = [];
        self::$always = null;
        self::$urls   = [];
        self::$sleeps = [];
    }

    /** @return array{code: int, body: string, headers?: array<string, string>}|\WP_Error */
    public static function next(string $url)
    {
        self::$urls[] = $url;

        if (self::$queue !== []) {
            return array_shift(self::$queue);
        }
        if (self::$always !== null) {
            return self::$always;
        }

        return new \WP_Error('http_request_failed', 'no scripted response');
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string, list<string>> */
        private array $errors = [];

        public function __construct(string $code = '', string $message = '')
        {
            if ($code !== '') {
                $this->errors[$code][] = $message;
            }
        }

        public function get_error_message(): string
        {
            foreach ($this->errors as $messages) {
                return (string) ($messages[0] ?? '');
            }

            return '';
        }

        public function get_error_code(): string
        {
            foreach ($this->errors as $code => $ignored) {
                return (string) $code;
            }

            return '';
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof \WP_Error;
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = [])
    {
        return \BccWire::next($url);
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        return is_array($response) ? (int) ($response['code'] ?? 0) : '';
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }
}

if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header($response, string $header)
    {
        if (!is_array($response)) {
            return '';
        }

        return (string) ($response['headers'][strtolower($header)] ?? '');
    }
}
}

namespace BCC\Core\Http {
    /**
     * SSRF hardening, faked at its production FQN.
     *
     * Deliberately a pass-through: this suite is about the RETRY and BREAKER
     * rules, and a URL rejected here would never reach them. The SSRF
     * behaviour itself is covered by its own suite.
     */
    final class SafeHttpClient
    {
        /**
         * @param  array<string, mixed> $args
         * @return array<string, mixed>|\WP_Error
         */
        public static function prepareArgs(string $url, array $args = [])
        {
            return $args;
        }
    }
}

namespace BCC\Trust\Onchain\Support {
    /**
     * ⚠ NAMESPACED `sleep()` WINS OVER THE GLOBAL ONE for unqualified calls
     * inside this namespace, which is how the real retry loop's backoff is
     * neutralised WITHOUT touching the loop. `ApiRetry` sleeps up to three
     * seconds per retry and retries three times, so a dozen scenarios would
     * otherwise spend well over a minute asleep — and a suite slow enough to
     * be skipped is a suite that stops finding things.
     *
     * Every sleep is recorded, so a test can still assert that backoff
     * HAPPENED without paying for it.
     */
    function sleep(int $seconds): int
    {
        \BccWire::$sleeps[] = $seconds;

        return 0;
    }
}
