<?php

/**
 * Collaborators for {@see \BCC\Trust\Onchain\Support\AlchemyCredential} and the
 * EVM consumers that resolve through it.
 *
 * ── ⚠⚠⚠ WHY AN HTTP SPY AND NOT A RETURN-VALUE CHECK ─────────────────────
 * One guarantee under test is NEGATIVE: with no Alchemy credential configured, a
 * blocked request must make NO PROVIDER CALL. A test that only checked the return
 * value would pass on an implementation that fired the request first and
 * discarded the response — which is worse than the bug it replaced, because it
 * spends quota and hands a credentialed URL to a provider's logs.
 *
 * So `wp_remote_get`/`wp_remote_post` are shimmed in
 * `BCC\Trust\Onchain\Support`, the namespace {@see \BCC\Trust\Onchain\Support\ApiRetry}
 * calls them from UNQUALIFIED — PHP resolves those to the namespaced shim before
 * the global function. Every attempt is COUNTED and its URL recorded, and the
 * tests assert the count is zero.
 *
 * ── ⚠⚠ WHAT THIS DELIBERATELY DOES NOT FAKE ──────────────────────────────
 * The transport is NOT reachable in the unit suite and this file does not try to
 * make it so. `ApiRetry` consults the circuit breaker, which needs the object
 * cache, transients, `is_wp_error()` and `BCC\Core\DB\AdvisoryLock` from the
 * sibling bcc-core plugin. Faking that chain would rebuild the integration
 * bootstrap inside a unit test, and each fake would be somewhere the test could
 * pass while lying.
 *
 * The consequence is worth stating plainly: if a regression DID let a blocked
 * request through, the test fails with a class-not-found ERROR rather than a spy
 * count of 1. Both are failures and the mutation is still killed — but the
 * message will name `AdvisoryLock`, not the guard that was removed. The positive
 * case (a public node IS selected with no credential) is therefore asserted
 * against the resolver instead, where no transport is involved.
 *
 * ⚠ Recording the URL is still deliberate: it lets a test prove a call went to
 * the resolved endpoint without the test itself ever printing one.
 *
 * @package BCC\Trust\Tests
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support {

    /**
     * Records every outbound HTTP attempt made through {@see ApiRetry}.
     *
     * `$active` gates the shim so a test file that loads this stub but wants the
     * real behaviour is unaffected — the same discipline
     * tests/Stubs/nft-capability-stubs.php uses.
     */
    final class AlchemyHttpSpy
    {
        public static bool $active = false;

        /** @var list<array{method: string, url: string}> */
        public static array $calls = [];

        /** @var array<string, mixed>|\WP_Error|null Response every call returns. */
        public static $response = null;

        public static function reset(): void
        {
            self::$active   = true;
            self::$calls    = [];
            self::$response = ['response' => ['code' => 200], 'body' => '{"result":"0x1"}'];
        }

        /** @return list<string> */
        public static function urls(): array
        {
            return array_map(static fn(array $c): string => $c['url'], self::$calls);
        }

        public static function count(): int
        {
            return count(self::$calls);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_get')) {
        /**
         * @param array<string, mixed> $args
         * @return array<string, mixed>|\WP_Error
         */
        function wp_remote_get(string $url, array $args = [])
        {
            if (!AlchemyHttpSpy::$active) {
                return \wp_remote_get($url, $args);
            }
            AlchemyHttpSpy::$calls[] = ['method' => 'GET', 'url' => $url];

            return AlchemyHttpSpy::$response;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_post')) {
        /**
         * @param array<string, mixed> $args
         * @return array<string, mixed>|\WP_Error
         */
        function wp_remote_post(string $url, array $args = [])
        {
            if (!AlchemyHttpSpy::$active) {
                return \wp_remote_post($url, $args);
            }
            AlchemyHttpSpy::$calls[] = ['method' => 'POST', 'url' => $url];

            return AlchemyHttpSpy::$response;
        }
    }
}

namespace {
    require_once __DIR__ . '/nft-capability-stubs.php';
}
