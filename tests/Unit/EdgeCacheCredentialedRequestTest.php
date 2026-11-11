<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

require_once __DIR__ . '/../Stubs/edgecache-session-stubs.php';

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
        \BccEdgeCacheSessionState::reset();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        // Global session state is process-wide: leaving it armed would make
        // every later suite look authenticated.
        \BccEdgeCacheSessionState::reset();
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

    // ── The cookie/session arm ───────────────────────────────────────
    //
    // isCredentialed() is a disjunction: the Authorization header OR a
    // WordPress session. Every test above drives the header. Until now the
    // session arm had NO coverage at all — `function_exists()` is false in a
    // unit run, so the branch was unreachable and a regression there could
    // not turn anything red.
    //
    // A cookie-authenticated visitor was never the reported exposure (LSCWP
    // already excludes cookie sessions of its own accord), which is why the
    // arm reads as belt-and-braces. Belt-and-braces that no test exercises is
    // just an untested claim.

    /**
     * The load-bearing one: an authenticated WordPress session is excluded
     * on a route the route-predicate does NOT cover, with no Authorization
     * header anywhere.
     */
    public function testWordPressSessionAloneExcludesAForeignRoute(): void
    {
        // Neutralise the other arm and prove it is off, so a pass cannot be
        // the header arm answering for the session arm.
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        self::assertFalse(BearerAuth::hasCredential(), 'precondition: no header credential');
        self::assertFalse(EdgeCache::appliesTo('/peepso/v1/post'), 'precondition: route is not self-excluding');
        self::assertFalse(EdgeCache::isCredentialed(), 'precondition: logged out to begin with');

        \BccEdgeCacheSessionState::$loggedIn = true;

        self::assertTrue(
            EdgeCache::isCredentialed(),
            'an authenticated WordPress session must count as a credential'
        );
        self::assertTrue(
            EdgeCache::shouldExclude('/peepso/v1/post'),
            'a logged-in visitor must never have a foreign-route response publicly cached'
        );
    }

    /**
     * The arm must be genuinely reachable — that the guard is satisfied is
     * the whole precondition for the test above meaning anything.
     */
    public function testTheSessionGuardIsSatisfiedInThisEnvironment(): void
    {
        self::assertTrue(
            function_exists('is_user_logged_in'),
            'without a GLOBAL is_user_logged_in() the session arm short-circuits and is untestable'
        );
    }

    /**
     * Logged out, no header: not credentialed. Previously this was true only
     * because the guard failed; now the guard passes and the predicate itself
     * is doing the work, so it is worth pinning separately.
     */
    public function testLoggedOutWithNoHeaderIsNotCredentialed(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        \BccEdgeCacheSessionState::$loggedIn = false;

        self::assertFalse(EdgeCache::isCredentialed());
        self::assertFalse(
            EdgeCache::shouldExclude('/peepso/v1/post'),
            'anonymous traffic must keep its public cacheability'
        );
    }

    /**
     * Either arm alone is sufficient — the disjunction is not an accidental
     * conjunction. Also covers both arms together.
     */
    public function testEitherArmAloneIsSufficient(): void
    {
        // Session only.
        \BccEdgeCacheSessionState::$loggedIn = true;
        self::assertTrue(EdgeCache::isCredentialed(), 'session alone');

        // Header only.
        \BccEdgeCacheSessionState::$loggedIn = false;
        $_SERVER['HTTP_AUTHORIZATION']       = 'Bearer header.payload.signature';
        self::assertTrue(EdgeCache::isCredentialed(), 'header alone');

        // Both.
        \BccEdgeCacheSessionState::$loggedIn = true;
        self::assertTrue(EdgeCache::isCredentialed(), 'both arms');
    }

    /**
     * Session state must not leak into the route predicate, mirroring the
     * assertion already made for the header arm.
     */
    public function testSessionDoesNotWidenTheRoutePredicate(): void
    {
        \BccEdgeCacheSessionState::$loggedIn = true;

        self::assertFalse(
            EdgeCache::appliesTo('/peepso/v1/post'),
            'appliesTo() must remain a pure route predicate'
        );
    }
}
