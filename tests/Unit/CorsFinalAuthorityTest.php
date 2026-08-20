<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\CorsHandler;
use BCC\Trust\Core\Tests\Support\HeaderRecorder;
use BCC\Trust\Core\Tests\Support\Hooks;
use BCC\Trust\Core\Tests\Support\PreflightTerminated;
use BCC\Trust\Core\Tests\Support\WpCore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * CorsHandler must be the FINAL CORS authority for EVERY WordPress REST
 * route.
 *
 * ## The bug this pins
 *
 * WP core's `rest_send_cors_headers()` reflects ANY `Origin` back as
 * `Access-Control-Allow-Origin` with `Access-Control-Allow-Credentials:
 * true`, no allowlist, on every REST route. It is registered on
 * `rest_pre_serve_request` at priority 10 from `rest_api_init` — i.e.
 * AFTER plugins load. A plugin callback registered at load time on the
 * same priority therefore runs FIRST and is clobbered. CorsHandler used
 * to be exactly that, and it only guarded `/bcc/v1/*` and
 * `/bcc-trust/v1/*` in the first place.
 *
 * Reproduced on production 2026-08-19: `https://evil.example.com` was
 * echoed back from `/wp-json/wp/v2/types`.
 *
 * ## What is asserted
 *
 *   - core's emitter is removed, at the exact hook/callback/priority triple
 *   - ownership covers the REST index, `wp/v2`, both BCC namespaces, and
 *     the `?rest_route=` addressing form — on BOTH the response and the
 *     preflight path
 *   - allowlisted origins are echoed; hostile, wrong-environment, literal
 *     `null`, malformed and absent origins get nothing
 *   - `Access-Control-Allow-Credentials` is never emitted, anywhere
 *   - no header state survives between requests, in either order
 *   - `Authorization` and the Sentry tracing headers stay allowed
 *   - `Vary` is merged, not clobbered
 *   - a third party emitting CORS at an earlier priority is overridden
 *   - non-REST and Origin-less OPTIONS are left to WordPress
 *   - the allowlist is parsed by FrontendOrigin, not re-parsed here
 *
 * ## Isolation
 *
 * Own subprocess. setUp() pulls in tests/Stubs/cors-stubs.php, which fakes
 * `header()` / `header_remove()` / `headers_list()` / `status_header()` /
 * `add_action()` / `add_filter()` / `remove_filter()` / `wp_parse_url()` /
 * `rest_get_url_prefix()` at their fully-qualified names inside
 * `BCC\Trust\Core\Support`, plus a faithful header table, a WP-ordered hook
 * registry and a reproduction of core's emitter.
 */
#[CoversClass(CorsHandler::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CorsFinalAuthorityTest extends TestCase
{
    private const PROD    = 'https://bluecollarcrypto.io';
    private const STAGING = 'https://staging.bluecollarcrypto.io';
    private const EVIL    = 'https://evil.example.com';

    /**
     * The allowlist almost every test runs against. Exact entries only —
     * `STAGING` is deliberately ABSENT so "wrong environment" is a real
     * denial rather than a coincidence.
     */
    private const BASE_CONFIG = self::PROD . ',http://localhost:3000';

    /**
     * Per-test `BCC_FRONTEND_ORIGIN`.
     *
     * `#[RunTestsInSeparateProcesses]` gives every test its own process, but
     * `setUp()` still runs before the test body and a constant can only be
     * defined once — so a test cannot choose its own allowlist from inside
     * itself. Keying off the test name in setUp() is the seam.
     *
     * @return array<string, string>
     */
    private static function configs(): array
    {
        return [
            'testRegexAllowlistEntryIsResolvedThroughFrontendOrigin'
                => self::PROD . ',regex:^https://bcc-frontend-[a-z0-9-]+\.vercel\.app$',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../Stubs/cors-stubs.php';

        if (!defined('BCC_FRONTEND_ORIGIN')) {
            define('BCC_FRONTEND_ORIGIN', self::configs()[$this->name()] ?? self::BASE_CONFIG);
        }

        HeaderRecorder::reset();
        Hooks::reset();
        CorsHandler::resetRegistrationForTests();

        unset($_SERVER['HTTP_ORIGIN']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/wp-json/bcc/v1/users';
    }

    // ─────────────────────────────────────────────────────────────────
    // Harness
    // ─────────────────────────────────────────────────────────────────

    /**
     * Replay a real REST request lifecycle in PRODUCTION ORDER:
     *
     *   1. plugin load  — CorsHandler::register()
     *   2. rest_api_init@10 — core registers its emitter
     *   3. rest_api_init@11 — CorsHandler removes it
     *   4. WP_REST_Server::serve_request() preamble
     *   5. the rest_pre_serve_request chain
     *
     * Steps 2 and 3 run through the same ordered registry as production, so
     * a removal at the wrong priority fails here exactly as it would live.
     *
     * `$thirdParty` is an extra `rest_pre_serve_request` callback at core's
     * priority, for the "someone else emits CORS" case.
     */
    private function serveRest(string $route, ?callable $thirdParty = null): void
    {
        HeaderRecorder::reset();
        Hooks::reset();
        CorsHandler::resetRegistrationForTests();

        CorsHandler::register();
        Hooks::add('rest_api_init', [WpCore::class, 'registerCorsFilter'], 10, 1);
        Hooks::doAction('rest_api_init');

        if ($thirdParty !== null) {
            Hooks::add('rest_pre_serve_request', $thirdParty, 10, 1);
        }

        WpCore::serveRequestPreamble();

        Hooks::apply(
            'rest_pre_serve_request',
            false,
            new \WP_REST_Response($route),
            new \WP_REST_Request($route, 'GET')
        );
    }

    /** The single ACAO on the response, or null when the origin was denied. */
    private function acao(string $origin, string $route = '/bcc/v1/users'): ?string
    {
        if ($origin === '') {
            unset($_SERVER['HTTP_ORIGIN']);
        } else {
            $_SERVER['HTTP_ORIGIN'] = $origin;
        }

        $this->serveRest($route);

        $values = HeaderRecorder::values('Access-Control-Allow-Origin');
        self::assertLessThanOrEqual(1, count($values), 'more than one ACAO was emitted');

        return $values === [] ? null : $values[0];
    }

    /** @return int HTTP status the preflight terminated with, or 0 if it fell through to WP */
    private function servePreflight(string $uri = '/wp-json/bcc/v1/users'): int
    {
        HeaderRecorder::reset();
        Hooks::reset();
        CorsHandler::resetRegistrationForTests();

        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $_SERVER['REQUEST_URI']    = $uri;

        CorsHandler::register();

        try {
            CorsHandler::handlePreflight();
        } catch (PreflightTerminated $terminated) {
            return $terminated->status;
        }

        return 0;
    }

    private static function assertNoCredentials(string $context = ''): void
    {
        self::assertSame(
            [],
            HeaderRecorder::values('Access-Control-Allow-Credentials'),
            'Access-Control-Allow-Credentials must never be emitted' . ($context === '' ? '' : ": {$context}")
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // 1. Core is taken out of the CORS business
    // ─────────────────────────────────────────────────────────────────

    public function testCoreCorsEmitterIsRemovedFromTheHook(): void
    {
        CorsHandler::register();
        Hooks::add('rest_api_init', [WpCore::class, 'registerCorsFilter'], 10, 1);
        Hooks::doAction('rest_api_init');

        self::assertNotContains(
            CorsHandler::CORE_CORS_PRIORITY,
            Hooks::priorities('rest_pre_serve_request'),
            "core's emitter is still registered at priority 10"
        );
    }

    public function testRemovalReportsWhetherItActuallyRemovedAnything(): void
    {
        WpCore::registerCorsFilter();

        self::assertTrue(
            CorsHandler::removeCoreCorsHeaders(),
            'removal must report success when the callback was present'
        );
        self::assertFalse(
            CorsHandler::removeCoreCorsHeaders(),
            'a second removal has nothing to remove and must say so'
        );
    }

    /**
     * The priorities are not decorative. Core adds the filter from
     * `rest_api_init@10`, so a removal at 10 or below runs first, removes
     * nothing, and core re-adds moments later — a silent no-op that leaves
     * the hole wide open while every "is it removed?" assertion still
     * passes if written against the wrong ordering.
     */
    public function testRemovalRunsAfterCoreRegistersAndTargetsCoresExactPriority(): void
    {
        self::assertGreaterThan(
            10,
            CorsHandler::CORE_CORS_REMOVAL_PRIORITY,
            'removal must run after rest_api_default_filters() at rest_api_init@10'
        );
        self::assertSame(
            10,
            CorsHandler::CORE_CORS_PRIORITY,
            'remove_filter matches on priority; core registers at add_filter default 10'
        );

        // And prove the no-op: at the wrong priority nothing is removed.
        WpCore::registerCorsFilter();
        self::assertFalse(
            Hooks::remove('rest_pre_serve_request', 'rest_send_cors_headers', 9),
            'a removal at the wrong priority must not match'
        );
    }

    public function testHandlerIsRegisteredLastOnRestPreServeRequest(): void
    {
        CorsHandler::register();

        self::assertSame(
            [CorsHandler::FINAL_AUTHORITY_PRIORITY],
            Hooks::priorities('rest_pre_serve_request'),
            'the response callback must be last on the hook'
        );
    }

    public function testRegisterIsIdempotent(): void
    {
        CorsHandler::register();
        CorsHandler::register();

        self::assertCount(
            1,
            Hooks::callbacksFor('rest_pre_serve_request'),
            'double registration would emit duplicate CORS headers'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. Global REST ownership
    //
    // The gate used to be `isBccRoute()`, a substring test for the two BCC
    // namespaces — so `/wp-json/` and `/wp/v2/*` were left on core's
    // reflected headers. An earlier draft of the fix delegated instead to
    // EdgeCache::appliesTo(), i.e. let a CACHE policy decide a SECURITY
    // boundary. These are the regression guards for both: if ownership is
    // ever re-narrowed, or re-coupled to the cache predicate, the
    // core-route rows fail.
    // ─────────────────────────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function ownedRoutes(): iterable
    {
        yield 'REST index'   => ['/'];
        yield 'core wp/v2'   => ['/wp/v2/types'];
        yield 'bcc/v1'       => ['/bcc/v1/example'];
        yield 'bcc-trust/v1' => ['/bcc-trust/v1/example'];
    }

    /** @return iterable<string, array{string}> */
    public static function ownedUris(): iterable
    {
        yield 'REST index'   => ['/wp-json/'];
        yield 'core wp/v2'   => ['/wp-json/wp/v2/types'];
        yield 'bcc/v1'       => ['/wp-json/bcc/v1/example'];
        yield 'bcc-trust/v1' => ['/wp-json/bcc-trust/v1/example'];
        yield 'rest_route'   => ['/?rest_route=/wp/v2/types'];
    }

    #[DataProvider('ownedRoutes')]
    public function testCorsIsOwnedOnEveryRestRouteViaTheResponsePath(string $route): void
    {
        self::assertSame(
            self::PROD,
            $this->acao(self::PROD, $route),
            "the handler must own {$route}, not defer to core"
        );
        self::assertNoCredentials($route);
    }

    #[DataProvider('ownedUris')]
    public function testCorsIsOwnedOnEveryRestRouteViaThePreflightPath(string $uri): void
    {
        $_SERVER['HTTP_ORIGIN'] = self::PROD;

        $status = $this->servePreflight($uri);

        self::assertSame(204, $status, "preflight must terminate for {$uri}");

        $values = HeaderRecorder::values('Access-Control-Allow-Origin');
        self::assertCount(1, $values, 'exactly one ACAO expected');
        self::assertSame(self::PROD, $values[0]);
        self::assertNoCredentials($uri);
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. Origin policy, on a CORE route
    //
    // `/wp/v2/types` is the representative route precisely because it is
    // the one no BCC code used to guard.
    // ─────────────────────────────────────────────────────────────────

    /** @return iterable<string, array{string, ?string}> */
    public static function originPolicy(): iterable
    {
        yield 'configured origin allowed' => [self::PROD, self::PROD];
        yield 'hostile origin denied'     => [self::EVIL, null];
        yield 'wrong environment denied'  => [self::STAGING, null];
        yield 'literal null denied'       => ['null', null];
        yield 'malformed denied'          => ['https://bluecollarcrypto.io/path?q=1#f', null];
        yield 'absent origin gets none'   => ['', null];
    }

    #[DataProvider('originPolicy')]
    public function testOriginPolicyOnACoreRouteResponsePath(string $origin, ?string $expected): void
    {
        self::assertSame($expected, $this->acao($origin, '/wp/v2/types'));
        self::assertNoCredentials($origin);
    }

    #[DataProvider('originPolicy')]
    public function testOriginPolicyOnACoreRoutePreflightPath(string $origin, ?string $expected): void
    {
        if ($origin === '') {
            unset($_SERVER['HTTP_ORIGIN']);
        } else {
            $_SERVER['HTTP_ORIGIN'] = $origin;
        }

        $this->servePreflight('/wp-json/wp/v2/types');

        $values = HeaderRecorder::values('Access-Control-Allow-Origin');
        self::assertLessThanOrEqual(1, count($values), 'more than one ACAO was emitted');
        self::assertSame($expected, $values === [] ? null : $values[0]);
        self::assertNoCredentials($origin);
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. Ordered sequences — no header state may survive between requests
    // ─────────────────────────────────────────────────────────────────

    public function testAllowedThenEvilThenAllowedLeaksNothing(): void
    {
        self::assertSame(self::PROD, $this->acao(self::PROD, '/wp/v2/types'), 'call 1');
        self::assertNull($this->acao(self::EVIL, '/wp/v2/types'), 'call 2 must not inherit call 1');
        self::assertSame(self::PROD, $this->acao(self::PROD, '/wp/v2/types'), 'call 3');
        self::assertNoCredentials();
    }

    public function testEvilThenAllowedThenEvilLeaksNothing(): void
    {
        self::assertNull($this->acao(self::EVIL, '/wp/v2/types'), 'call 1');
        self::assertSame(self::PROD, $this->acao(self::PROD, '/wp/v2/types'), 'call 2');
        self::assertNull($this->acao(self::EVIL, '/wp/v2/types'), 'call 3 must not inherit call 2');
        self::assertNoCredentials();
    }

    // ─────────────────────────────────────────────────────────────────
    // 5. Preserved behaviour
    // ─────────────────────────────────────────────────────────────────

    public function testApprovedResponseAdvertisesAuthorizationAndTracingHeaders(): void
    {
        $this->acao(self::PROD);

        $allowed = HeaderRecorder::value('Access-Control-Allow-Headers') ?? '';
        foreach (['Authorization', 'Content-Type', 'X-WP-Nonce', 'Sentry-Trace', 'Baggage'] as $header) {
            self::assertStringContainsString($header, $allowed, "{$header} must stay allowed");
        }

        self::assertSame(
            'X-Request-Id',
            HeaderRecorder::value('Access-Control-Expose-Headers'),
            "core's X-WP-Total/Link exposure must be replaced, not inherited"
        );
    }

    public function testVaryOriginIsMergedNotClobbered(): void
    {
        $this->serveRest('/bcc/v1/users', static function (bool $served): bool {
            HeaderRecorder::send('Vary: Accept-Encoding');
            return $served;
        });

        self::assertSame(['Origin, Accept-Encoding'], HeaderRecorder::values('Vary'));
    }

    public function testDeniedResponsesStillVaryOnOrigin(): void
    {
        $this->acao(self::EVIL);

        self::assertSame(['Origin'], HeaderRecorder::values('Vary'), 'a denial must still be Origin-keyed');
        self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Origin'));
    }

    /**
     * Core is gone, but a third-party plugin on an earlier priority is not.
     * Running last and stripping every managed header is what makes that
     * survivable — including the duplicate-ACAO case browsers reject
     * outright.
     */
    public function testAnotherPluginsCorsHeadersCannotSurvive(): void
    {
        $_SERVER['HTTP_ORIGIN'] = self::EVIL;

        $this->serveRest('/bcc/v1/users', static function (bool $served): bool {
            HeaderRecorder::send('Access-Control-Allow-Origin: ' . self::EVIL);
            HeaderRecorder::send('Access-Control-Allow-Origin: *', false);
            HeaderRecorder::send('Access-Control-Allow-Credentials: true');
            return $served;
        });

        self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Origin'));
        self::assertNoCredentials();
    }

    public function testNonRestAndOriginlessOptionsAreLeftToWordPress(): void
    {
        $_SERVER['HTTP_ORIGIN'] = self::PROD;
        self::assertSame(
            0,
            $this->servePreflight('/some-page/'),
            'an ordinary page OPTIONS resolves to no REST route and must fall through'
        );
        self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Origin'));

        unset($_SERVER['HTTP_ORIGIN']);
        self::assertSame(
            0,
            $this->servePreflight('/wp-json/bcc/v1/users'),
            'an Origin-less OPTIONS is not a CORS preflight; leave WPs Allow: handling alone'
        );
        self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Origin'));
    }

    // ─────────────────────────────────────────────────────────────────
    // 6. One parser
    //
    // The allowlist grammar belongs to FrontendOrigin. This is the
    // behavioural proof that CorsHandler defers to it rather than carrying
    // a second copy of the rules: a `regex:` entry only resolves if the
    // prefix was understood, and CorsHandler contains no code that
    // understands it.
    // ─────────────────────────────────────────────────────────────────

    public function testRegexAllowlistEntryIsResolvedThroughFrontendOrigin(): void
    {
        $preview = 'https://bcc-frontend-abc123-git-main.vercel.app';

        self::assertSame($preview, $this->acao($preview), 'regex entry must resolve');
        self::assertNull($this->acao('https://bcc-frontend-abc123.vercel.app.evil.com'), 'anchored, so no suffix');
        self::assertSame(self::PROD, $this->acao(self::PROD), 'exact entries still work alongside');
        self::assertNoCredentials();
    }
}
