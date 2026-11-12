<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Security\IpResolver;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-54 — IpResolver trusts CF-Connecting-IP only behind the Cloudflare OPT-IN.
 *
 * It used to trust the header whenever REMOTE_ADDR was in a Cloudflare range.
 * A site that merely egresses through Cloudflare (shared hosts, Workers/WARP)
 * would then take a client-chosen IP from anyone on that egress — for vote
 * IPs, fingerprints, audit rows and anonymous rate limits. Now the same
 * switch as bcc-core's Throttle is required. Pinned against the REAL
 * IpResolver::getClientIp():
 *   - no opt-in → REMOTE_ADDR, even from a Cloudflare address;
 *   - opt-in by constant or by filter + Cloudflare REMOTE_ADDR → the header;
 *   - opt-in but REMOTE_ADDR outside Cloudflare → REMOTE_ADDR;
 *   - X-Forwarded-For / X-Real-IP are never read.
 *
 * Subprocess-only: BCC_BEHIND_CLOUDFLARE is a constant, defined per test.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IpResolverCloudflareOptInTest extends TestCase
{
    private const CF_EDGE = '104.16.0.10';     // inside 104.16.0.0/13
    private const CLIENT  = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
        $GLOBALS['__bcc_cf_filter'] = false;
        if (!function_exists('apply_filters')) {
            eval('function apply_filters(string $hook, $value = null) { return $hook === "bcc_behind_cloudflare" ? $GLOBALS["__bcc_cf_filter"] : $value; }');
        }
    }

    private static function resolve(string $remoteAddr, ?string $cfHeader = self::CLIENT): string
    {
        $_SERVER['REMOTE_ADDR'] = $remoteAddr;
        if ($cfHeader !== null) {
            $_SERVER['HTTP_CF_CONNECTING_IP'] = $cfHeader;
        }
        return IpResolver::getClientIp();
    }

    public function testWithoutTheOptInTheHeaderIsIgnoredEvenFromCloudflare(): void
    {
        self::assertFalse(IpResolver::behindCloudflare());
        self::assertSame(self::CF_EDGE, self::resolve(self::CF_EDGE));
    }

    public function testTheConstantOptInTrustsTheHeaderFromCloudflare(): void
    {
        define('BCC_BEHIND_CLOUDFLARE', true);

        self::assertSame(self::CLIENT, self::resolve(self::CF_EDGE));
    }

    public function testTheFilterOptInTrustsTheHeaderFromCloudflare(): void
    {
        $GLOBALS['__bcc_cf_filter'] = true;

        self::assertSame(self::CLIENT, self::resolve(self::CF_EDGE));
    }

    public function testAFalsyConstantIsNotAnOptIn(): void
    {
        define('BCC_BEHIND_CLOUDFLARE', false);

        self::assertSame(self::CF_EDGE, self::resolve(self::CF_EDGE));
    }

    public function testOptedInButNotFromCloudflareIgnoresTheHeader(): void
    {
        define('BCC_BEHIND_CLOUDFLARE', true);

        self::assertSame('192.0.2.50', self::resolve('192.0.2.50'));
    }

    public function testForwardedHeadersAreNeverRead(): void
    {
        define('BCC_BEHIND_CLOUDFLARE', true);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6';
        $_SERVER['HTTP_X_REAL_IP']       = '7.7.7.7';

        self::assertSame('192.0.2.50', self::resolve('192.0.2.50', null));
    }
}
