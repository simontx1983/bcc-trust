<?php

declare(strict_types=1);

/**
 * The WP surface the endpoint switch needs that the integration bootstrap does
 * not supply, plus a SCRIPTABLE transport and a DNS seam.
 *
 * ── WHY THIS IS HERE AND NOT IN THE SHARED BOOTSTRAP ────────────────────
 * Same reasoning as `http-response-accessor-stubs.php`: 600-odd unrelated
 * integration tests keep the surface they have today, and only the file that
 * needs more asks for it.
 *
 * ── THE TRANSPORT IS DEFAULT-DENY, AND THAT IS THE SAFETY CONTROL ───────
 * `CosmosEndpointVerifier` proves a destination by calling
 * `BCC\Core\Http\SafeHttpClient::get()`, which hands off to `wp_remote_get()`.
 * In the integration harness that function is otherwise UNDEFINED, so a
 * verification attempt is a fatal error rather than a request.
 *
 * `wp_remote_get()` here NEVER OPENS A SOCKET. It returns a `WP_Error` unless a
 * test has explicitly scripted a response, so:
 *   - no test can reach a provider, scripted or not;
 *   - a test that forgets to script gets a deterministic refusal, not a request;
 *   - and "outbound traffic is impossible" stops depending on a function simply
 *     being missing, which is a guarantee by accident.
 *
 * ── AND THE DNS SEAM ────────────────────────────────────────────────────
 * `SafeHttpClient::prepareArgs()` resolves the host with `gethostbyname()` /
 * `dns_get_record()` before pinning an IP, so a scripted 200 would still have
 * cost a real lookup. It consults its own per-request resolver cache FIRST, so
 * {@see BccEndpointTransportSpy::pinHost()} seeds that cache and the resolver
 * branch is never taken. No production code is modified to make this possible:
 * the cache is a real mechanism that exists because a burst of calls to one
 * host must not hit the OS resolver every time.
 *
 * ⚠ The pin is OBSERVABLE, which is what makes "DNS was not used" an assertion
 * rather than a claim: `prepareArgs()` records `host:port:ip` in
 * `SafeHttpClient::$pinnedResolves`, so a test can read back the sentinel IP it
 * seeded and know the address came from the cache.
 *
 * @package BCC\Trust\Tests\Integration
 */

namespace {

// ⚠ INSIDE the namespace block: a `require` before the first `namespace`
// declaration is a fatal parse error, not a style problem.
require_once __DIR__ . '/http-response-accessor-stubs.php';

if (!class_exists('BccEndpointTransportSpy', false)) {
    /**
     * The scripted transport, and the DNS seam.
     *
     * Response shape is the compact `['code' => int, 'body' => string]` that
     * `http-response-accessor-stubs.php` reads, so the pair is self-consistent.
     */
    final class BccEndpointTransportSpy
    {
        /**
         * Every transport attempt, in order.
         *
         * @var list<array{url: string, args: array<string, mixed>}>
         */
        public static array $calls = [];

        /**
         * Responses to hand out, one per call, in order. An empty queue means
         * `WP_Error` — see the file docblock.
         *
         * @var list<array{code: int, body: string}>
         */
        public static array $scripted = [];

        /** Called on the FIRST transport attempt only, inside the lock. */
        public static ?\Closure $onFirstCall = null;

        private const SAFE_HTTP = 'BCC\Core\Http\SafeHttpClient';

        public static function reset(): void
        {
            self::$calls       = [];
            self::$scripted    = [];
            self::$onFirstCall = null;
            self::clearPins();
        }

        /** Script one successful node_info response for a Cosmos network. */
        public static function scriptNodeInfo(string $network, int $code = 200): void
        {
            self::$scripted[] = [
                'code' => $code,
                'body' => (string) json_encode(['default_node_info' => ['network' => $network]]),
            ];
        }

        /** Script a raw body, for the unreadable and mismatched cases. */
        public static function scriptRaw(int $code, string $body): void
        {
            self::$scripted[] = ['code' => $code, 'body' => $body];
        }

        /**
         * Seed the resolver cache so `prepareArgs()` pins without a lookup.
         *
         * ⚠ `$ip` is never contacted. 203.0.113.0/24 is TEST-NET-3, reserved
         * for documentation, which is why it is the sentinel: if anything ever
         * did try to open a socket, it would not reach a real host.
         */
        public static function pinHost(string $host, string $ip = '203.0.113.10'): void
        {
            $cache = self::statics('dnsCache');
            $value = $cache->getValue();
            $value[$host] = ['ip' => $ip, 'expires' => time() + 3600];
            $cache->setValue(null, $value);
        }

        /**
         * What `prepareArgs()` actually pinned, as `host:port:ip`.
         *
         * @return array<string, string>
         */
        public static function pinnedResolves(): array
        {
            /** @var array<string, string> $value */
            $value = self::statics('pinnedResolves')->getValue();

            return $value;
        }

        public static function clearPins(): void
        {
            self::statics('dnsCache')->setValue(null, []);
            self::statics('pinnedResolves')->setValue(null, []);
        }

        /**
         * ⚠ THIS REACHES INTO ANOTHER REPOSITORY'S PRIVATE STATICS.
         *
         * `SafeHttpClient` lives in bcc-core, which CI checks out at its DEFAULT
         * BRANCH with no pinned ref, so a rename there lands here without a
         * bcc-trust change. Both names were verified against `bcc-core@main`
         * (`$dnsCache`, `$pinnedResolves`), and the failure is made legible on
         * purpose: a bare `ReflectionException` would read as a broken test
         * rather than as the coupling it actually is.
         */
        private static function statics(string $name): \ReflectionProperty
        {
            try {
                $p = new \ReflectionProperty(self::SAFE_HTTP, $name);
            } catch (\ReflectionException $e) {
                throw new \RuntimeException(
                    'The endpoint-switch integration tests seed and read '
                    . self::SAFE_HTTP . '::$' . $name
                    . ' to stub DNS without a lookup. bcc-core no longer has that'
                    . ' property, so the seam needs rebuilding against the current'
                    . ' SafeHttpClient. Original: ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            $p->setAccessible(true);

            return $p;
        }
    }
}

if (!function_exists('wp_remote_get')) {
    /**
     * ⚠ NEVER OPENS A SOCKET. Default-deny; see the file docblock.
     *
     * @param array<string, mixed> $args
     * @return array{code: int, body: string}|\WP_Error
     */
    function wp_remote_get(string $url, array $args = [])
    {
        $first = \BccEndpointTransportSpy::$calls === [];
        \BccEndpointTransportSpy::$calls[] = ['url' => $url, 'args' => $args];

        // The interleave point for a genuinely overlapping submission: this
        // runs INSIDE the switch's advisory lock.
        if ($first && \BccEndpointTransportSpy::$onFirstCall instanceof \Closure) {
            (\BccEndpointTransportSpy::$onFirstCall)();
        }

        if (\BccEndpointTransportSpy::$scripted !== []) {
            return array_shift(\BccEndpointTransportSpy::$scripted);
        }

        return new \WP_Error(
            'bcc_integration_no_transport',
            'Integration tests make no outbound requests, and none was scripted.'
        );
    }
}

if (!function_exists('add_filter')) {
    /**
     * A no-op, reached only as a side effect of the transport path.
     *
     * `prepareArgs()` registers a one-shot filter disabling the Streams
     * transport, because its CURLOPT_RESOLVE pin is cURL-only. That is
     * bookkeeping for a request this harness never sends, so there is nothing
     * to honour — but leaving it undefined turns verification into a fatal.
     */
    function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): bool
    {
        return true;
    }
}

if (!function_exists('add_action')) {
    /** Likewise: `injectCurlResolve()` hooks `http_api_curl` on a real send. */
    function add_action(string $hook, $callback, int $priority = 10, int $args = 1): bool
    {
        return true;
    }
}

if (!function_exists('wp_salt')) {
    /**
     * Stable within a process, which is all the review fingerprint needs: it is
     * compared against another fingerprint taken in the same request.
     */
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'bcc-integration-salt-' . $scheme;
    }
}

if (!function_exists('wp_generate_password')) {
    /**
     * ⚠ GENUINELY RANDOM, DELIBERATELY.
     *
     * A counter would make "a replacement review differs from the one it
     * replaced" true by construction, which is the very property the switch
     * depends on. Randomness keeps that assertion about the code.
     */
    function wp_generate_password(int $length = 12, bool $special = true, bool $extra = false): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max      = strlen($alphabet) - 1;
        $out      = '';

        for ($i = 0; $i < max(1, $length); $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}

}

namespace BCC\Trust\Core\Security {

/**
 * The actor on an audit row.
 *
 * Namespace-scoped so it binds ONLY for `AuditLogger`, which calls it
 * unqualified — the same technique, and the same backing global, as
 * `AuditLoggerMetaWriteIntegrationTest`. Declared here as well so this file
 * does not depend on which test PHPUnit happened to load first.
 */
if (!function_exists(__NAMESPACE__ . '\\get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['bcc_itest_current_user'] ?? 0);
    }
}

}
