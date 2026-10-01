<?php

declare(strict_types=1);

/**
 * The four WP functions the endpoint switch needs that the integration
 * bootstrap does not supply — and one transport stub that exists to make
 * outbound traffic IMPOSSIBLE rather than merely unlikely.
 *
 * ── WHY THIS IS HERE AND NOT IN THE SHARED BOOTSTRAP ────────────────────
 * Same reasoning as `http-response-accessor-stubs.php`: 600-odd unrelated
 * integration tests keep the surface they have today, and only the file that
 * needs more asks for it.
 *
 * ⚠⚠⚠ THE TRANSPORT STUB IS A SAFETY CONTROL, NOT A CONVENIENCE.
 * `CosmosEndpointVerifier` proves a destination by calling
 * `BCC\Core\Http\SafeHttpClient::get()`, which — after its SSRF checks —
 * hands off to `wp_remote_get()`. In the integration harness that function is
 * otherwise UNDEFINED, so a verification attempt is a fatal error rather than
 * a request: outbound traffic is impossible today by accident.
 *
 * Defining it here keeps that guarantee while making the failure DETERMINISTIC
 * instead of fatal. It is the only implementation of `wp_remote_get()` in the
 * process, it never opens a socket, and it always returns a `WP_Error`. So a
 * test may drive the real `execute()` through its real verification step and
 * still reach no provider — and an endpoint switch can never complete against
 * a live host from a test run, which is the property that actually matters.
 *
 * ⚠ ONE EXTERNAL DEPENDENCY REMAINS, AND IT IS NOT HTTP.
 * `SafeHttpClient::prepareArgs()` resolves the host with `gethostbyname()` /
 * `dns_get_record()` before it pins an IP, so reaching this stub costs one DNS
 * lookup. Nothing asserts on whether that lookup succeeds: a resolver failure
 * returns `WP_Error` from `prepareArgs()` and a resolver success returns
 * `WP_Error` from here, and `verify()` maps both to the same
 * `target_unreachable`. The tests therefore read the REASON, never this spy's
 * call count, so they are identical with or without a working resolver.
 *
 * @package BCC\Trust\Tests\Integration
 */

if (!class_exists('BccEndpointTransportSpy', false)) {
    /**
     * Records every transport attempt, for diagnosis rather than assertion.
     *
     * ⚠ DO NOT ASSERT ON `$calls`. Whether the HTTP layer is reached at all
     * depends on whether DNS resolved, which is not this suite's business. The
     * reason returned by `execute()` is the DNS-independent signal; see the
     * note above.
     */
    final class BccEndpointTransportSpy
    {
        /** @var list<string> */
        public static array $calls = [];

        public static function reset(): void
        {
            self::$calls = [];
        }
    }
}

if (!function_exists('wp_remote_get')) {
    /**
     * ⚠ NEVER OPENS A SOCKET. See the file docblock.
     *
     * @param array<string, mixed> $args
     * @return \WP_Error
     */
    function wp_remote_get(string $url, array $args = [])
    {
        \BccEndpointTransportSpy::$calls[] = $url;

        return new \WP_Error(
            'bcc_integration_no_transport',
            'Integration tests make no outbound requests.'
        );
    }
}

if (!function_exists('add_filter')) {
    /**
     * A no-op, reached only as a side effect of the transport path.
     *
     * `SafeHttpClient::prepareArgs()` registers a one-shot filter that disables
     * the Streams transport, because its CURLOPT_RESOLVE pin is cURL-only. That
     * registration is bookkeeping for a request this harness never sends, so
     * there is nothing to honour — but leaving the function undefined turns the
     * verification step into a fatal error instead of a refusal.
     */
    function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): bool
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
