<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Security\IpResolver;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-125 — IpResolver honours operator-declared trusted proxies via bcc-core.
 *
 * BCC_TRUSTED_PROXY_IPS / bcc_trusted_proxy_ips were documented but never
 * read here, so behind a non-Cloudflare proxy every client resolved to the
 * proxy's IP. IpResolver now delegates to bcc-core's Throttle::clientIp()
 * when — and only when — Throttle::trustedProxies() is non-empty. Pinned
 * against the REAL IpResolver with a per-test bcc-core Throttle double:
 *   - NO proxy declared (production): the result is exactly the pre-F-125
 *     one — REMOTE_ADDR, forwarded headers ignored, CF only behind its
 *     opt-in — and core's resolver is never called;
 *   - bcc-core absent, or a bcc-core that predates the API: the same
 *     unchanged fallback;
 *   - a proxy declared: core's answer, sanitised; 'invalid_remote' → 0.0.0.0.
 *
 * Subprocess-only: each test declares its own BCC\Core\Security\Throttle.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IpResolverTrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['REMOTE_ADDR']           = '192.0.2.50';
        $_SERVER['HTTP_X_FORWARDED_FOR']  = '6.6.6.6, 203.0.113.9';
        $_SERVER['HTTP_X_REAL_IP']        = '7.7.7.7';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '8.8.4.4';
        $GLOBALS['__bcc_core_calls'] = [];
        if (!function_exists('apply_filters')) {
            eval('function apply_filters(string $hook, $value = null) { return $value; }');
        }
    }

    /** @param list<string> $trusted */
    private static function core(array $trusted, string $clientIp): void
    {
        $GLOBALS['__bcc_core_trusted'] = $trusted;
        $GLOBALS['__bcc_core_client']  = $clientIp;
        eval('namespace BCC\\Core\\Security; final class Throttle {
            public static function trustedProxies(): array { $GLOBALS["__bcc_core_calls"][] = "trustedProxies"; return $GLOBALS["__bcc_core_trusted"]; }
            public static function clientIp(): string { $GLOBALS["__bcc_core_calls"][] = "clientIp"; return $GLOBALS["__bcc_core_client"]; }
        }');
    }

    public function testNoProxyDeclaredIsExactlyTheOldResult(): void
    {
        self::core([], '203.0.113.9');

        self::assertSame('192.0.2.50', IpResolver::getClientIp(), 'production: REMOTE_ADDR, headers ignored');
        self::assertNotContains('clientIp', $GLOBALS['__bcc_core_calls'], "core's resolver is never consulted");
    }

    public function testWithoutBccCoreTheFallbackIsUnchanged(): void
    {
        self::assertFalse(class_exists('BCC\\Core\\Security\\Throttle', false));
        self::assertSame('192.0.2.50', IpResolver::getClientIp());
    }

    public function testABccCoreWithoutTheApiFallsBack(): void
    {
        eval('namespace BCC\\Core\\Security; final class Throttle {
            public static function allow(string $a, int $l = 10, int $w = 60, ?string $k = null): bool { return true; }
        }');

        self::assertSame('192.0.2.50', IpResolver::getClientIp());
    }

    public function testADeclaredProxyResolvesThroughCore(): void
    {
        self::core(['192.0.2.50'], '203.0.113.9');

        self::assertSame('203.0.113.9', IpResolver::getClientIp());
        self::assertSame(['trustedProxies', 'clientIp'], $GLOBALS['__bcc_core_calls']);
    }

    public function testCoresAnswerIsSanitised(): void
    {
        self::core(['192.0.2.50'], '2001:DB8:0:0::1');

        self::assertSame('2001:db8::1', IpResolver::getClientIp(), 'canonical form, as before');
    }

    public function testAnInvalidRemoteMapsToTheSafeFallback(): void
    {
        self::core(['10.0.0.1'], 'invalid_remote');

        self::assertSame('0.0.0.0', IpResolver::getClientIp());
    }

    public function testCloudflareStillNeedsItsOptInWhenNoProxyIsDeclared(): void
    {
        self::core([], '203.0.113.9');
        $_SERVER['REMOTE_ADDR'] = '104.16.0.10';     // a Cloudflare address, no opt-in

        self::assertSame('104.16.0.10', IpResolver::getClientIp());
    }
}
