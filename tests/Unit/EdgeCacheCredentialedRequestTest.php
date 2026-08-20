<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\BearerAuth;
use BCC\Trust\Infrastructure\EdgeCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the credentialed-request edge-cache exclusion.
 *
 * ## The gap this closes
 *
 * The exclusion used to be scoped purely by ROUTE — the two BCC
 * namespaces. But {@see BearerAuth} hooks `determine_current_user`
 * GLOBALLY at priority 30, so a JWT authenticates on any route,
 * including PeepSo's. Verified on production before the fix:
 *
 *   /wp-json/peepso/v1/post   200   X-LiteSpeed-Cache-Control: public,max-age=60
 *   /wp-json/wp/v2/posts      200   X-LiteSpeed-Cache-Control: no-cache
 *
 * WP core sends its own nocache headers, so `/wp/v2/*` was never at
 * risk. PeepSo's routes were.
 *
 * The trap: LSCWP decides "is this visitor logged in?" from COOKIES.
 * This frontend authenticates with `Authorization: Bearer` and sets no
 * cookie, so an authenticated request looks anonymous to the cache —
 * its personalized response is stored and replayed to the next caller.
 * Cookie sessions were never exposed, which is exactly why this stayed
 * invisible.
 *
 * The two tests that matter here are the PeepSo pair: same route,
 * opposite verdicts, decided solely by the credential.
 */
#[CoversClass(EdgeCache::class)]
#[CoversMethod(BearerAuth::class, 'hasCredential')]
final class EdgeCacheCredentialedRequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    // ── The regression ───────────────────────────────────────────────

    public function testCredentialedRequestToAForeignRouteIsExcluded(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer header.payload.signature';

        self::assertTrue(
            EdgeCache::shouldExclude('/peepso/v1/post'),
            'a Bearer-authenticated PeepSo response must never be edge-cached'
        );
    }

    public function testAnonymousRequestToTheSameRouteStillCaches(): void
    {
        // The other half of the pin. If this ever returns true the
        // anonymous edge tier is gone and the fix has over-reached.
        self::assertFalse(
            EdgeCache::shouldExclude('/peepso/v1/post'),
            'anonymous traffic must keep its edge cache'
        );
    }

    // ── Credential detection ─────────────────────────────────────────

    /** @return iterable<string, array{array<string, string>, bool}> */
    public static function credentials(): iterable
    {
        yield 'bearer token' => [
            ['HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi'],
            true,
        ];
        // Application Passwords authenticate too, and are equally
        // invisible to a cookie-based logged-in check.
        yield 'basic auth' => [
            ['HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz'],
            true,
        ];
        // BearerAuth falls back to this when suEXEC moves the header;
        // the cache check must follow it to the same place, or the two
        // disagree and the gap reopens.
        yield 'redirect-prefixed header' => [
            ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi'],
            true,
        ];
        // A rejected credential still produces a user-specific response
        // (a 401 is not a cacheable public answer).
        yield 'malformed credential still counts' => [
            ['HTTP_AUTHORIZATION' => 'garbage'],
            true,
        ];
        yield 'no header' => [[], false];
        yield 'empty header' => [['HTTP_AUTHORIZATION' => ''], false];
        // The HTTP/2 quirk BearerAuth documents: HTTP_AUTHORIZATION set
        // but empty while the real value sits in the redirect variant.
        yield 'empty primary, real fallback' => [
            ['HTTP_AUTHORIZATION' => '', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer real.token.here'],
            true,
        ];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('credentials')]
    public function testCredentialDetection(array $server, bool $expected): void
    {
        foreach ($server as $k => $v) {
            $_SERVER[$k] = $v;
        }

        self::assertSame($expected, BearerAuth::hasCredential());
        self::assertSame($expected, EdgeCache::isCredentialed());
    }

    // ── Route scoping still works independently ──────────────────────

    public function testBccRoutesStayExcludedWithoutCredentials(): void
    {
        self::assertTrue(EdgeCache::shouldExclude('/bcc/v1/feed/hot'));
        self::assertTrue(EdgeCache::shouldExclude('/bcc-trust/v1/ranks'));
    }

    public function testCredentialDoesNotWidenTheRoutePredicate(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc.def.ghi';

        // appliesTo() is a pure ROUTE predicate and must stay that way —
        // the credential is handled by the separate arm of shouldExclude().
        self::assertFalse(
            EdgeCache::appliesTo('/peepso/v1/post'),
            'appliesTo() must remain a pure route predicate'
        );
    }
}
