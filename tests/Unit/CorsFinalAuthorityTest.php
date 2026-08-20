<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\CorsHandler;
use BCC\Trust\Core\Tests\Support\HeaderRecorder;
use BCC\Trust\Core\Tests\Support\Hooks;
use BCC\Trust\Core\Tests\Support\PreflightTerminated;
use BCC\Trust\Core\Tests\Support\WpCore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * CorsHandler must be the FINAL CORS authority for the two BCC REST
 * namespaces.
 *
 * ## The bug this pins
 *
 * WP core's `rest_send_cors_headers()` reflects ANY `Origin` back as
 * `Access-Control-Allow-Origin` with `Access-Control-Allow-Credentials:
 * true`, no allowlist. It is registered on `rest_pre_serve_request` at
 * priority 10 from `rest_api_init` — i.e. AFTER plugins load. A plugin
 * callback registered at load time on the same priority therefore runs
 * FIRST and is clobbered. CorsHandler used to be exactly that, while its
 * docblock claimed the opposite.
 *
 * Every ordering assertion below therefore registers the two callbacks in
 * PRODUCTION ORDER — CorsHandler first (plugin load), core second
 * (rest_api_init) — through a hook registry that reproduces WordPress's
 * "ascending priority, then registration order" dispatch. A stub that
 * ignored ordering would pass against the broken code.
 *
 * ## What is asserted
 *
 *   - approved origins get exactly ONE Access-Control-Allow-Origin
 *   - NO BCC response ever carries Access-Control-Allow-Credentials
 *   - denied / malformed / missing / `null` origins get no ACAO at all
 *   - core's reflected headers cannot survive on a BCC route
 *   - pre-existing duplicate CORS headers cannot survive
 *   - the OPTIONS preflight path and the GET response path agree exactly
 *   - `Authorization` stays on Access-Control-Allow-Headers
 *   - the exposed-header set is exactly `X-Request-Id` (D4), core's
 *     `X-WP-Total, X-WP-TotalPages, Link` exposure is stripped, and the
 *     pagination totals are still SENT while being unreadable cross-origin
 *   - `/wp/v2/*` is left exactly as core produced it
 *   - Vercel hash previews and `git-*` branch previews are allowed
 *   - uppercase origins are canonicalised, never echoed verbatim
 *   - Vercel lookalike hosts are denied
 *
 * ## Isolation
 *
 * Own subprocess. setUp() pulls in tests/Stubs/cors-stubs.php, which fakes
 * `header()` / `header_remove()` / `headers_list()` / `status_header()` /
 * `add_action()` / `add_filter()` / `wp_parse_url()` /
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
    private const TEAM    = 'phillip-simon-s-projects';

    /**
     * The anchored `regex:` pattern used by most of the regex fixtures.
     * Deliberately the SHAPE the retired implementation shipped, so the
     * uppercase and structural fixtures below are testing the exact
     * configuration that used to be exploitable.
     *
     * Note `[a-z0-9-]+` rather than the original `[a-z0-9]+`: the original
     * could not span a hyphen, which is why no `git-*` branch preview ever
     * matched it (defect 2).
     */
    private const REGEX_PREVIEW = 'regex:^https://bcc-frontend-[a-z0-9-]+-'
        . self::TEAM . '\\.vercel\\.app$';

    /** The allowlist every pre-existing test in this file was written against. */
    private const BASE_CONFIG = self::PROD
        . ',' . self::STAGING
        . ',vercel:bcc-frontend:' . self::TEAM
        . ',http://localhost:3000';

    /**
     * Per-test `BCC_FRONTEND_ORIGIN`.
     *
     * `#[RunTestsInSeparateProcesses]` gives every test its own process, but
     * `setUp()` still runs before the test body, and a constant can only be
     * defined once — so a test cannot choose its own allowlist from inside
     * itself. Keying off the test name in setUp() is the seam. The
     * alternative, adding a settable override to CorsHandler, would put a
     * test-only mutation hook on the class whose entire job is to be the
     * final authority; that is a worse trade.
     *
     * Anything not listed here gets BASE_CONFIG, which is byte-identical to
     * what this file used before the `regex:` form was reinstated. That is
     * what keeps the pre-existing tests honest rather than merely green.
     *
     * @return array<string, string>
     */
    private static function configs(): array
    {
        return [
            // A catch-all, to pin what the totality canary does with it.
            'testRegexCatchAllIsRefusedAsAConfigurationError'
                => self::PROD . ',regex:.*',
            'testRegexCatchAllVariantsAreAllRefused'
                => self::PROD . ',regex:.+,regex:[\s\S]*,regex:(.*)',

            // Unanchored / partially anchored patterns.
            'testUnanchoredRegexMatchesNothingBecauseEveryPatternIsWrapped'
                => self::PROD . ',regex:bcc-frontend',
            'testAnchorPresenceIsNotAnchorGuarantee'
                => self::PROD . ',regex:^https://bluecollarcrypto\\.io|evil',
            'testAlternationIsGroupedSoEveryBranchIsAnchored'
                => 'regex:https://a\\.example|https://b\\.example',

            // Malformed / hostile patterns.
            'testNonCompilingPatternIsDeniedAndTheRestOfTheAllowlistStillWorks'
                => self::PROD . ',regex:^https://[a-z,' . self::STAGING,
            'testOverlongPatternIsRefused'
                => self::PROD . ',regex:^https://' . str_repeat('a', 200) . '$',
            'testPatternContainingTheDelimiterIsRefused'
                => self::PROD . ',regex:^https://bcc#frontend\\.example$',
            'testLeadingDoubleSlashPatternIsRefused'
                => self::PROD . ',regex://evil.com',
            // A genuinely catastrophic rule, paired below with a subject that
            // is a STRUCTURALLY VALID origin — see the test for why that
            // pairing is the whole point.
            'testCatastrophicPatternFailsClosedWithoutHanging'
                => self::PROD . ',regex:https://(a|a)+\\.io',

            // The comma trap.
            'testCountedQuantifierIsTornApartByTheAllowlistSplitterAndFailsClosed'
                => self::PROD . ',regex:^https://bcc-frontend-[a-z0-9]{6,32}-'
                    . self::TEAM . '\\.vercel\\.app$',

            // Coexistence of all three entry forms.
            'testAllThreeEntryFormsCoexist'
                => self::PROD
                    . ',vercel:bcc-frontend:' . self::TEAM
                    . ',' . self::REGEX_PREVIEW,
        ];
    }

    /** The allowlist a `regex:`-form test runs against, for its own assertions. */
    private static function configForCurrent(string $test): string
    {
        return self::configs()[$test] ?? self::REGEX_ONLY_CONFIG;
    }

    /**
     * Default for the regex fixtures that share one allowlist: the anchored
     * preview pattern, plus an exact origin so "the rest of the allowlist
     * still works" is always assertable.
     */
    private const REGEX_ONLY_CONFIG = self::PROD . ',' . self::REGEX_PREVIEW;

    /**
     * Tests that opt in to REGEX_ONLY_CONFIG rather than BASE_CONFIG.
     * Listed explicitly so a new test cannot silently inherit a regex
     * allowlist it did not ask for.
     *
     * @return list<string>
     */
    private static function regexFixtureTests(): array
    {
        return [
            'testAnchoredRegexAllowsThePreviewHostAndEchoesTheCanonicalOrigin',
            'testUppercaseOriginUnderARegexRuleIsEchoedLowercasedNeverRaw',
            'testRegexRuleCannotBypassStructuralOriginValidation',
            'testRegexRuleIsMatchedCaseSensitively',
            'testTrailingWhitespaceBytesAreTrimmedAndNeverReflected',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../Stubs/cors-stubs.php';

        if (!defined('BCC_FRONTEND_ORIGIN')) {
            $test = $this->name();
            if (isset(self::configs()[$test])) {
                $config = self::configs()[$test];
            } elseif (in_array($test, self::regexFixtureTests(), true)) {
                $config = self::REGEX_ONLY_CONFIG;
            } else {
                $config = self::BASE_CONFIG;
            }
            define('BCC_FRONTEND_ORIGIN', $config);
        }

        HeaderRecorder::reset();
        Hooks::reset();

        unset($_SERVER['HTTP_ORIGIN']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/wp-json/bcc/v1/users';
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

    // ─────────────────────────────────────────────────────────────────
    // Harness
    // ─────────────────────────────────────────────────────────────────

    /**
     * Replay a real REST response cycle: plugin load registers
     * CorsHandler, rest_api_init registers core's emitter at 10,
     * WP_REST_Server sends its unconditional preamble, then the
     * `rest_pre_serve_request` chain runs.
     */
    private function serveRest(string $route, string $method = 'GET'): void
    {
        HeaderRecorder::reset();
        Hooks::reset();

        CorsHandler::register();                                              // plugin load
        Hooks::add('rest_pre_serve_request', [WpCore::class, 'sendCorsHeaders'], 10, 1); // rest_api_init

        WpCore::serveRequestPreamble();

        Hooks::apply(
            'rest_pre_serve_request',
            false,
            new \WP_REST_Response($route),
            new \WP_REST_Request($route, $method)
        );
    }

    /** @return int HTTP status the preflight terminated with, or 0 if it fell through to WP */
    private function servePreflight(string $uri = '/wp-json/bcc/v1/users'): int
    {
        HeaderRecorder::reset();
        Hooks::reset();

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

    /**
     * `Access-Control-Expose-Headers` split into its comma-separated tokens.
     * An empty list means the header is absent entirely.
     *
     * @return list<string>
     */
    private static function exposedTokens(): array
    {
        $value = HeaderRecorder::value('Access-Control-Expose-Headers');
        if ($value === null) {
            return [];
        }

        $tokens = [];
        foreach (explode(',', $value) as $token) {
            $token = trim($token);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /** @return array<string, list<string>> the CORS-relevant slice of the header table */
    private function corsHeaders(): array
    {
        $names = [
            'Access-Control-Allow-Origin',
            'Access-Control-Allow-Credentials',
            'Access-Control-Allow-Methods',
            'Access-Control-Allow-Headers',
            'Access-Control-Expose-Headers',
            'Access-Control-Max-Age',
            'Vary',
        ];
        $out = [];
        foreach ($names as $name) {
            $out[$name] = HeaderRecorder::values($name);
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────
    // Fixture tables
    // ─────────────────────────────────────────────────────────────────

    /**
     * label => [request Origin, canonical ACAO that must come back].
     *
     * @return array<string, array{string, string}>
     */
    private static function mustAllow(): array
    {
        return [
            'production exact'                => [self::PROD, self::PROD],
            'staging exact'                   => [self::STAGING, self::STAGING],
            'production uppercase scheme+host' => ['HTTPS://BLUECOLLARCRYPTO.IO', self::PROD],
            'production mixed case host'      => ['https://BlueCollarCrypto.io', self::PROD],
            'production with bare root path'  => ['https://bluecollarcrypto.io/', self::PROD],
            'vercel hash preview'             => [
                'https://bcc-frontend-abc123def-' . self::TEAM . '.vercel.app',
                'https://bcc-frontend-abc123def-' . self::TEAM . '.vercel.app',
            ],
            'vercel git-main branch preview'  => [
                'https://bcc-frontend-git-main-' . self::TEAM . '.vercel.app',
                'https://bcc-frontend-git-main-' . self::TEAM . '.vercel.app',
            ],
            'vercel git multi-segment branch' => [
                'https://bcc-frontend-git-fix-cors-repair-' . self::TEAM . '.vercel.app',
                'https://bcc-frontend-git-fix-cors-repair-' . self::TEAM . '.vercel.app',
            ],
            'vercel preview uppercase'        => [
                'HTTPS://BCC-FRONTEND-GIT-MAIN-PHILLIP-SIMON-S-PROJECTS.VERCEL.APP',
                'https://bcc-frontend-git-main-' . self::TEAM . '.vercel.app',
            ],
            'configured loopback dev origin'  => ['http://localhost:3000', 'http://localhost:3000'],
        ];
    }

    /** @return array<string, string> label => Origin header value that must be refused */
    private static function mustDeny(): array
    {
        $preview = 'bcc-frontend-abc123def-' . self::TEAM . '.vercel.app';

        return [
            'empty origin'                    => '',
            'literal null origin'             => 'null',
            'unrelated https site'            => 'https://evil.com',
            'production over plain http'      => 'http://bluecollarcrypto.io',
            'production domain as suffix'     => 'https://bluecollarcrypto.io.evil.com',
            'attacker subdomain of prod'      => 'https://evil.bluecollarcrypto.io',
            'attacker subdomain of preview'   => 'https://evil.' . $preview,
            'preview as domain suffix'        => 'https://' . $preview . '.evil.com',
            'preview with trailing dot'       => 'https://' . $preview . '.',
            'team slug lookalike'             => 'https://bcc-frontend-abc123def-phillip-simon-s-project.vercel.app',
            'vercel tld lookalike'            => 'https://bcc-frontend-abc123def-' . self::TEAM . '.vercel.dev',
            'project slug lookalike'          => 'https://bcc-frontendx-abc123def-' . self::TEAM . '.vercel.app',
            'bare attacker project alias'     => 'https://bcc-frontend-attackerxyz.vercel.app',
            'preview with empty middle token' => 'https://bcc-frontend--' . self::TEAM . '.vercel.app',
            'preview with empty branch slug'  => 'https://bcc-frontend-git--' . self::TEAM . '.vercel.app',
            'preview hash below length floor' => 'https://bcc-frontend-abc-' . self::TEAM . '.vercel.app',
            'embedded credentials'            => 'https://user:pass@bluecollarcrypto.io',
            'userinfo confusion'              => 'https://bluecollarcrypto.io@evil.com',
            'unexpected port'                 => 'https://bluecollarcrypto.io:8443',
            'path beyond root'                => 'https://bluecollarcrypto.io/admin',
            'query string'                    => 'https://bluecollarcrypto.io?x=1',
            'fragment'                        => 'https://bluecollarcrypto.io#frag',
            'percent-encoded host separator'  => 'https://bluecollarcrypto%2eio',
            'percent-encoded null byte'       => 'https://bluecollarcrypto.io%00.evil.com',
            'crlf header injection'           => "https://bluecollarcrypto.io\r\nX-Injected: 1",
            'embedded space'                  => 'https://bluecollar crypto.io',
            'production with trailing dot'    => 'https://bluecollarcrypto.io.',
            'punycode lookalike'              => 'https://xn--bluecollarcrypto-1x9d.io',
            'scheme-relative'                 => '//bluecollarcrypto.io',
            'bare host, no scheme'            => 'bluecollarcrypto.io',
            'scheme only'                     => 'https://',
            'loopback on wrong port'          => 'http://localhost:3001',
            'unconfigured loopback host'      => 'http://127.0.0.1:3000',
            'uppercase suffix attack'         => 'HTTPS://BCC-FRONTEND-ABC123DEF-PHILLIP-SIMON-S-PROJECTS.VERCEL.APP.EVIL.COM',
            'over-long origin'                => 'https://' . str_repeat('a', 250) . '.io',
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Must-allow
    // ─────────────────────────────────────────────────────────────────

    public function testApprovedOriginsGetExactlyOneCanonicalAcao(): void
    {
        foreach (self::mustAllow() as $label => [$origin, $expected]) {
            $_SERVER['HTTP_ORIGIN'] = $origin;
            $this->serveRest('/bcc/v1/users');

            $acao = HeaderRecorder::values('Access-Control-Allow-Origin');

            self::assertCount(1, $acao, "{$label}: expected exactly one ACAO, got " . count($acao));
            self::assertSame($expected, $acao[0], "{$label}: ACAO must be the canonical origin");

            if ($origin !== $expected) {
                self::assertNotSame(
                    $origin,
                    $acao[0],
                    "{$label}: the raw request Origin must never be echoed verbatim"
                );
            }
        }
    }

    public function testApprovedResponsesNeverCarryAllowCredentials(): void
    {
        foreach (self::mustAllow() as $label => [$origin, $_expected]) {
            $_SERVER['HTTP_ORIGIN'] = $origin;
            $this->serveRest('/bcc/v1/users');

            self::assertSame(
                [],
                HeaderRecorder::values('Access-Control-Allow-Credentials'),
                "{$label}: BCC responses must never be credentialed"
            );
        }
    }

    public function testApprovedResponsesAdvertiseAuthorizationAndVaryOnOrigin(): void
    {
        $_SERVER['HTTP_ORIGIN'] = self::PROD;
        $this->serveRest('/bcc/v1/users');

        $allowHeaders = HeaderRecorder::value('Access-Control-Allow-Headers');
        self::assertIsString($allowHeaders);
        self::assertStringContainsString(
            'Authorization',
            $allowHeaders,
            'the entire headless chain is Bearer-JWT; dropping Authorization logs everyone out'
        );

        $methods = HeaderRecorder::value('Access-Control-Allow-Methods');
        self::assertIsString($methods);
        foreach (['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'] as $method) {
            self::assertStringContainsString($method, $methods);
        }

        self::assertSame('600', HeaderRecorder::value('Access-Control-Max-Age'));
        self::assertSame('X-Request-Id', HeaderRecorder::value('Access-Control-Expose-Headers'));

        $vary = HeaderRecorder::values('Vary');
        self::assertCount(1, $vary, 'Vary must be a single collapsed header, not core-appended duplicates');
        self::assertStringContainsString('Origin', $vary[0]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Must-deny
    // ─────────────────────────────────────────────────────────────────

    public function testDeniedOriginsGetNoCorsHeadersAtAll(): void
    {
        foreach (self::mustDeny() as $label => $origin) {
            if ($origin === '') {
                unset($_SERVER['HTTP_ORIGIN']);
            } else {
                $_SERVER['HTTP_ORIGIN'] = $origin;
            }

            $this->serveRest('/bcc/v1/users');

            self::assertSame(
                [],
                HeaderRecorder::values('Access-Control-Allow-Origin'),
                "{$label}: must not receive an Access-Control-Allow-Origin"
            );
            self::assertSame(
                [],
                HeaderRecorder::values('Access-Control-Allow-Credentials'),
                "{$label}: must not receive Access-Control-Allow-Credentials"
            );
            self::assertSame(
                [],
                HeaderRecorder::values('Access-Control-Max-Age'),
                "{$label}: must not receive Access-Control-Max-Age"
            );
            self::assertSame(
                [],
                HeaderRecorder::values('Access-Control-Allow-Methods'),
                "{$label}: must not receive Access-Control-Allow-Methods"
            );
            self::assertSame(
                [],
                HeaderRecorder::values('Access-Control-Allow-Headers'),
                "{$label}: must not receive Access-Control-Allow-Headers"
            );
            self::assertSame(
                [],
                HeaderRecorder::values('Access-Control-Expose-Headers'),
                "{$label}: must not receive Access-Control-Expose-Headers"
            );
        }
    }

    public function testDeniedResponsesStillVaryOnOrigin(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.com';
        $this->serveRest('/bcc/v1/users');

        $vary = HeaderRecorder::values('Vary');
        self::assertCount(1, $vary);
        self::assertStringContainsString(
            'Origin',
            $vary[0],
            'a shared cache must not replay an approved response to a denied origin'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // D4 — the exposed-header surface is minimal and deliberate
    // ─────────────────────────────────────────────────────────────────
    //
    // `Access-Control-Expose-Headers` is the ONLY thing that decides which
    // response headers cross-origin JS may read. Everything absent from it
    // is invisible to the browser client no matter what the server sends.
    //
    // The audited answer (2026-08) is that exactly one header has a real
    // browser consumer:
    //
    //   - `X-Request-Id` — 2 consumers, both `response.headers.get(...)` in
    //     bcc-frontend/src/lib/api/client.ts (:122 and :495). Emitted by
    //     app/Domain/Core/REST/Envelope.php:69.
    //   - `X-WP-Total` / `X-WP-TotalPages` — 0 consumers in bcc-frontend/src.
    //     Emitted on exactly two routes, DisputeController.php:308-309
    //     (`/disputes/votes/{page_id}`) and :339-340 (`/disputes/mine`).
    //     bcc-frontend/src/lib/api/disputes-endpoints.ts:71 records the
    //     deliberate V1 decision to fetch only the first page, so nothing
    //     reads the totals.
    //   - `Link` — 0 consumers. WP core exposes it site-wide for its own
    //     paginated collections; no BCC route emits it.
    //
    // Zero consumers therefore means zero exposure. That is a real
    // consequence, not a formality: today those pagination totals are
    // UNREADABLE cross-origin. The response still carries them (see
    // testPaginationTotalsAreStillSentJustNotReadable) — the browser
    // simply refuses to hand them to JS.
    //
    // ⚠ IF DISPUTE PAGINATION EVER BECOMES CLIENT-DRIVEN — i.e. the
    // frontend starts asking for `page=2` and needs to know how many pages
    // there are — `X-WP-Total` and `X-WP-TotalPages` MUST be added back to
    // CorsHandler::EXPOSED_HEADERS as a deliberate act, and these tests
    // updated in the same change. The symptom of forgetting is not an
    // error: `response.headers.get("X-WP-Total")` simply returns `null`
    // cross-origin while working fine in any same-origin or server-side
    // test. Do not debug that from the client. It is this list.
    //
    // The alternative — putting the totals back "just in case" — was
    // rejected: an exposed header is a permanent commitment to a
    // cross-origin reader, and the response-body envelope is the right
    // place to carry pagination when a real pagination story arrives.

    public function testApprovedResponsesExposeExactlyXRequestIdAndNothingElse(): void
    {
        foreach (self::mustAllow() as $label => [$origin, $_expected]) {
            $_SERVER['HTTP_ORIGIN'] = $origin;
            $this->serveRest('/bcc/v1/users');

            self::assertCount(
                1,
                HeaderRecorder::values('Access-Control-Expose-Headers'),
                "{$label}: Access-Control-Expose-Headers must be a single header line"
            );
            self::assertSame(
                ['X-Request-Id'],
                self::exposedTokens(),
                "{$label}: the exposed set must be exactly X-Request-Id — see the D4 note above "
                . 'before adding anything to it'
            );
        }
    }

    /**
     * The two routes that actually emit pagination headers. Naming them
     * explicitly means this test starts failing the day someone adds the
     * totals to the exposed set without also updating the D4 note.
     */
    public function testPaginationHeadersAreNotExposedOnTheRoutesThatEmitThem(): void
    {
        $_SERVER['HTTP_ORIGIN'] = self::PROD;

        foreach (['/bcc/v1/disputes/votes/123', '/bcc/v1/disputes/mine'] as $route) {
            $this->serveRest($route);

            $exposed = array_map('strtolower', self::exposedTokens());

            foreach (['x-wp-total', 'x-wp-totalpages', 'link'] as $unreadable) {
                self::assertNotContains(
                    $unreadable,
                    $exposed,
                    "{$route}: {$unreadable} has zero browser consumers and must stay unexposed"
                );
            }

            self::assertSame(['X-Request-Id'], self::exposedTokens(), "{$route}: exposed set drifted");
        }
    }

    /**
     * Exposure is a READ permission, not a send switch. The server still
     * emits `X-WP-Total` on those routes for same-origin callers, curl,
     * SSR and server-to-server consumers — the browser is the only party
     * that enforces the expose list. This test exists so a future reader
     * does not "fix" the unreadability by deleting the emitters.
     */
    public function testPaginationTotalsAreStillSentJustNotReadable(): void
    {
        $_SERVER['HTTP_ORIGIN'] = self::PROD;

        HeaderRecorder::reset();
        Hooks::reset();

        CorsHandler::register();
        Hooks::add('rest_pre_serve_request', [WpCore::class, 'sendCorsHeaders'], 10, 1);

        WpCore::serveRequestPreamble();
        // What DisputeController::votes() does on the response object.
        HeaderRecorder::send('X-WP-Total: 7');
        HeaderRecorder::send('X-WP-TotalPages: 1');

        Hooks::apply(
            'rest_pre_serve_request',
            false,
            new \WP_REST_Response('/bcc/v1/disputes/votes/123'),
            new \WP_REST_Request('/bcc/v1/disputes/votes/123', 'GET')
        );

        self::assertSame(['7'], HeaderRecorder::values('X-WP-Total'), 'the totals are still sent…');
        self::assertSame(['1'], HeaderRecorder::values('X-WP-TotalPages'));
        self::assertSame(['X-Request-Id'], self::exposedTokens(), '…they are simply not readable cross-origin');
    }

    /**
     * Core's own exposure list must not survive. `WP_REST_Server::serve_request()`
     * emits `Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages, Link`
     * unconditionally, before dispatch and regardless of Origin — so without
     * the strip, BCC responses would inherit an exposure set nobody chose.
     *
     * The spy at `FINAL_AUTHORITY_PRIORITY - 1` proves core's value really was
     * on the response first; without it a passing assertion would be
     * indistinguishable from core never having run.
     */
    public function testCoreExposeHeaderListIsStrippedNotInherited(): void
    {
        $_SERVER['HTTP_ORIGIN'] = self::PROD;

        HeaderRecorder::reset();
        Hooks::reset();

        $beforeFinalAuthority = null;

        CorsHandler::register();
        Hooks::add('rest_pre_serve_request', [WpCore::class, 'sendCorsHeaders'], 10, 1);
        Hooks::add(
            'rest_pre_serve_request',
            static function (bool $served) use (&$beforeFinalAuthority): bool {
                $beforeFinalAuthority = HeaderRecorder::values('Access-Control-Expose-Headers');
                return $served;
            },
            CorsHandler::FINAL_AUTHORITY_PRIORITY - 1,
            1
        );

        WpCore::serveRequestPreamble();
        Hooks::apply(
            'rest_pre_serve_request',
            false,
            new \WP_REST_Response('/bcc/v1/users'),
            new \WP_REST_Request('/bcc/v1/users', 'GET')
        );

        self::assertSame(
            ['X-WP-Total, X-WP-TotalPages, Link'],
            $beforeFinalAuthority,
            "core's unconditional exposure must be present before we run, else this test proves nothing"
        );
        self::assertSame(
            ['X-Request-Id'],
            self::exposedTokens(),
            "core's X-WP-Total / X-WP-TotalPages / Link exposure must be stripped, not merged"
        );
    }

    /** Denied origins get no exposure at all — not even the empty header. */
    public function testDeniedOriginsExposeNothing(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.com';

        foreach (['/bcc/v1/users', '/bcc/v1/disputes/mine'] as $route) {
            $this->serveRest($route);

            self::assertSame(
                [],
                HeaderRecorder::values('Access-Control-Expose-Headers'),
                "{$route}: a denied response must carry no Access-Control-* header at all"
            );
        }
    }

    /** The preflight path must advertise the same set as the response path. */
    public function testPreflightExposesTheSameMinimalSet(): void
    {
        $_SERVER['HTTP_ORIGIN'] = self::PROD;

        self::assertSame(204, $this->servePreflight('/wp-json/bcc/v1/disputes/mine'));
        self::assertSame(
            ['X-Request-Id'],
            self::exposedTokens(),
            'OPTIONS and GET must advertise the same readable-header set'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // Ordering / survivability
    // ─────────────────────────────────────────────────────────────────

    public function testCallbackIsRegisteredLastOnRestPreServeRequest(): void
    {
        self::assertSame(
            PHP_INT_MAX,
            CorsHandler::FINAL_AUTHORITY_PRIORITY,
            'anything below PHP_INT_MAX is a race with the next plugin that picks a bigger number'
        );

        CorsHandler::register();

        self::assertSame(
            [PHP_INT_MAX],
            Hooks::priorities('rest_pre_serve_request'),
            'the final authority must be the only BCC callback on this hook, at PHP_INT_MAX'
        );
        self::assertSame([1], Hooks::priorities('init'), 'preflight short-circuit runs at init:1');
    }

    /**
     * The load-bearing proof: a spy registered one priority BELOW the final
     * authority snapshots the header table at the moment core has finished.
     * If the snapshot is empty the test is worthless (core never ran, or ran
     * after us) and it says so; if the snapshot holds core's reflection but
     * the final table does not, the strip is proven rather than assumed.
     */
    public function testCoreRunsFirstAndItsReflectedHeadersAreStripped(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.com';

        HeaderRecorder::reset();
        Hooks::reset();

        /** @var array<string, list<string>> $afterCore */
        $afterCore = [];

        CorsHandler::register();                                                         // plugin load
        Hooks::add('rest_pre_serve_request', [WpCore::class, 'sendCorsHeaders'], 10, 1); // rest_api_init
        Hooks::add(
            'rest_pre_serve_request',
            static function (bool $served) use (&$afterCore): bool {
                foreach (['Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods'] as $name) {
                    $afterCore[$name] = HeaderRecorder::values($name);
                }
                return $served;
            },
            CorsHandler::FINAL_AUTHORITY_PRIORITY - 1,
            1
        );

        WpCore::serveRequestPreamble();
        Hooks::apply(
            'rest_pre_serve_request',
            false,
            new \WP_REST_Response('/bcc/v1/users'),
            new \WP_REST_Request('/bcc/v1/users', 'GET')
        );

        // 1. Core really did emit its unconditional reflection, and it was
        //    still on the response immediately before our callback.
        self::assertSame(
            ['https://evil.com'],
            $afterCore['Access-Control-Allow-Origin'],
            'core must run BEFORE the final authority, otherwise this test proves nothing'
        );
        self::assertSame(['true'], $afterCore['Access-Control-Allow-Credentials']);
        self::assertSame(['OPTIONS, GET, POST, PUT, PATCH, DELETE'], $afterCore['Access-Control-Allow-Methods']);

        // 2. And none of it survived.
        self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Origin'));
        self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Credentials'));
        self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Methods'));
        self::assertSame([], HeaderRecorder::values('Access-Control-Expose-Headers'));
        self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Headers'));
    }

    public function testDuplicateCorsHeadersCannotSurvive(): void
    {
        $_SERVER['HTTP_ORIGIN'] = self::PROD;

        HeaderRecorder::reset();
        Hooks::reset();

        CorsHandler::register();
        Hooks::add('rest_pre_serve_request', [WpCore::class, 'sendCorsHeaders'], 10, 1);

        // Something earlier in the stack appended (not replaced) its own
        // CORS headers — a reverse proxy rule, a second plugin, a stale
        // .htaccess Header add.
        HeaderRecorder::send('Access-Control-Allow-Origin: https://evil.com', false);
        HeaderRecorder::send('Access-Control-Allow-Origin: *', false);
        HeaderRecorder::send('Access-Control-Allow-Credentials: true', false);
        HeaderRecorder::send('Access-Control-Max-Age: 86400', false);
        WpCore::serveRequestPreamble();

        Hooks::apply(
            'rest_pre_serve_request',
            false,
            new \WP_REST_Response('/bcc/v1/users'),
            new \WP_REST_Request('/bcc/v1/users', 'GET')
        );

        self::assertSame([self::PROD], HeaderRecorder::values('Access-Control-Allow-Origin'));
        self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Credentials'));
        self::assertSame(['600'], HeaderRecorder::values('Access-Control-Max-Age'));
    }

    public function testNamespaceRootIsCoveredNotJustSubRoutes(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.com';

        foreach (['/bcc/v1', '/bcc-trust/v1', '/bcc/v1/users', '/bcc-trust/v1/read-model/health'] as $route) {
            $this->serveRest($route);
            self::assertSame(
                [],
                HeaderRecorder::values('Access-Control-Allow-Origin'),
                "{$route}: core's reflection survived"
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Non-BCC namespaces are untouched
    // ─────────────────────────────────────────────────────────────────

    public function testWpV2NamespaceIsLeftExactlyAsCoreProducedIt(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.com';

        foreach (['/wp/v2/posts', '/wp/v2/users', '/peepso/v1/profile', '/bcc/v10/thing'] as $route) {
            $this->serveRest($route);

            self::assertSame(
                ['https://evil.com'],
                HeaderRecorder::values('Access-Control-Allow-Origin'),
                "{$route}: core CORS must NOT be removed site-wide (owner ruling)"
            );
            self::assertSame(
                ['true'],
                HeaderRecorder::values('Access-Control-Allow-Credentials'),
                "{$route}: core's credentialed behaviour must be behaviourally identical"
            );
            self::assertSame(
                ['OPTIONS, GET, POST, PUT, PATCH, DELETE'],
                HeaderRecorder::values('Access-Control-Allow-Methods'),
                "{$route}: core's method list must be untouched"
            );
            self::assertSame(
                ['Authorization, X-WP-Nonce, Content-Disposition, Content-MD5, Content-Type'],
                HeaderRecorder::values('Access-Control-Allow-Headers'),
                "{$route}: core's allow-headers must be untouched"
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // OPTIONS ≡ GET
    // ─────────────────────────────────────────────────────────────────

    public function testPreflightAndResponsePathsAgreeForApprovedOrigins(): void
    {
        $_SERVER['HTTP_ORIGIN'] = self::PROD;

        $status = $this->servePreflight('/wp-json/bcc/v1/users');
        self::assertSame(204, $status);
        $preflight = $this->corsHeaders();
        self::assertSame(
            ['no-store, no-cache, must-revalidate'],
            HeaderRecorder::values('Cache-Control'),
            'preflights must never be stored by an edge cache'
        );

        $this->serveRest('/bcc/v1/users');
        $response = $this->corsHeaders();

        self::assertSame($response, $preflight, 'OPTIONS and GET must produce the same CORS decision');
        self::assertSame([self::PROD], $preflight['Access-Control-Allow-Origin']);
        self::assertSame([], $preflight['Access-Control-Allow-Credentials']);
    }

    public function testPreflightAndResponsePathsAgreeForDeniedOrigins(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.com';

        $status = $this->servePreflight('/wp-json/bcc/v1/users');
        self::assertSame(204, $status, 'a denied preflight terminates here rather than falling through to core');
        $preflight = $this->corsHeaders();

        $this->serveRest('/bcc/v1/users');
        $response = $this->corsHeaders();

        self::assertSame([], $preflight['Access-Control-Allow-Origin']);
        self::assertSame([], $preflight['Access-Control-Allow-Credentials']);
        self::assertSame($response, $preflight);
    }

    public function testPreflightCoversEveryBccAddressingForm(): void
    {
        $_SERVER['HTTP_ORIGIN'] = self::PROD;

        $uris = [
            '/wp-json/bcc/v1/users',
            '/wp-json/bcc/v1',
            '/wp-json/bcc-trust/v1/read-model/health',
            '/wp-json/bcc/v1/cards/search?limit=5',
            '/?rest_route=/bcc/v1/users',
            '/subsite/wp-json/bcc/v1/users',
        ];

        foreach ($uris as $uri) {
            self::assertSame(204, $this->servePreflight($uri), "{$uri}: preflight should be handled");
            self::assertSame(
                [self::PROD],
                HeaderRecorder::values('Access-Control-Allow-Origin'),
                "{$uri}: approved preflight lost its ACAO"
            );
        }
    }

    public function testPreflightIgnoresNonBccAndOriginlessRequests(): void
    {
        $_SERVER['HTTP_ORIGIN'] = self::PROD;
        self::assertSame(0, $this->servePreflight('/wp-json/wp/v2/posts'), 'non-BCC preflight must fall through');
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
    // Allowlist configuration
    // ─────────────────────────────────────────────────────────────────

    /**
     * A `regex:`-prefixed string is a CONFIG entry, never a legal `Origin`
     * header. Sending one as an Origin must be refused like any other
     * malformed value — the prefix must not be stripped, and the remainder
     * must not be re-read as an exact origin.
     *
     * (Previously `testRetiredRegexEntryIsNeverHonoured`. The entry FORM is
     * supported again; this property is unchanged and still worth pinning,
     * so the assertions are kept verbatim and only the name and commentary
     * were corrected.)
     */
    public function testRegexPrefixedStringIsNotItselfAValidOrigin(): void
    {
        $entry = 'regex:^https://bcc-frontend-[a-z0-9]+-' . self::TEAM . '\\.vercel\\.app$';

        foreach ([$entry, 'https://bcc-frontend-abc123def-' . self::TEAM . '.vercel.app'] as $origin) {
            $_SERVER['HTTP_ORIGIN'] = $origin;
            $this->serveRest('/bcc/v1/users');
            // The preview origin is still allowed — by the `vercel:` rule
            // that setUp() configures for this test, not by any regex entry.
            if (str_starts_with($origin, 'regex:')) {
                self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Origin'));
            }
        }

        self::assertStringContainsString(
            'vercel:bcc-frontend:' . self::TEAM,
            (string) BCC_FRONTEND_ORIGIN,
            'preview coverage here must come from the structural rule, not a regex'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // The `regex:` entry form
    // ─────────────────────────────────────────────────────────────────
    //
    // Reinstated 2026-08 by owner ruling after the hardening pass withdrew
    // it. Reinstated is not restored: the original had two live defects and
    // the tests below exist to keep both dead.
    //
    //   Defect 1 (FALSE-ALLOW). It matched `/i` against the RAW Origin bytes
    //   and echoed those bytes back, so `HTTPS://BCC-FRONTEND-….VERCEL.APP`
    //   was allowed and reflected verbatim. Pinned by
    //   testUppercaseOriginUnderARegexRuleIsEchoedLowercasedNeverRaw and
    //   testRegexRuleCannotBypassStructuralOriginValidation.
    //
    //   Defect 2 (FALSE-DENY). Its `[a-z0-9]+` body could not span a hyphen,
    //   so no `git-*` branch preview matched. A config-authoring problem, not
    //   a code one; the `vercel:` form now covers previews structurally and
    //   is the recommended answer. testAllThreeEntryFormsCoexist shows the
    //   two side by side.
    //
    // The pattern is ALWAYS wrapped in `^(?:…)$` rather than checked for
    // anchors — see testAnchorPresenceIsNotAnchorGuarantee for the concrete
    // false-allow that the check-instead-of-wrap design would have let
    // through.

    /** Call the private pattern validator directly. Null = entry discarded. */
    private static function compileRule(string $pattern): ?string
    {
        $method = new \ReflectionMethod(CorsHandler::class, 'compileRegexRule');

        /** @var string|null $compiled */
        $compiled = $method->invoke(null, $pattern);

        return $compiled;
    }

    /**
     * Call the private structural validator directly. Null = the origin never
     * reaches the allowlist at all. Used to prove a denial came from the
     * matcher rather than from canonicalisation, which is the difference
     * between a real assertion and a vacuous one.
     *
     * @return array{scheme: string, host: string, port: int|null, canonical: string}|null
     */
    private static function canonicalize(string $origin): ?array
    {
        $method = new \ReflectionMethod(CorsHandler::class, 'canonicalizeOrigin');

        /** @var array{scheme: string, host: string, port: int|null, canonical: string}|null $parts */
        $parts = $method->invoke(null, $origin);

        return $parts;
    }

    public function testAnchoredRegexAllowsThePreviewHostAndEchoesTheCanonicalOrigin(): void
    {
        $host = 'https://bcc-frontend-abc123def-' . self::TEAM . '.vercel.app';

        self::assertSame($host, $this->acao($host), 'the anchored regex rule must allow the preview host');

        // Defect 2 stays dead: the hyphen-spanning body matches branch aliases.
        $branch = 'https://bcc-frontend-git-fix-cors-repair-' . self::TEAM . '.vercel.app';
        self::assertSame($branch, $this->acao($branch));

        // …and the exact entry in the same config is unaffected.
        self::assertSame(self::PROD, $this->acao(self::PROD));
    }

    /**
     * DEFECT 1, pinned. The old implementation matched `/i` against the raw
     * header and echoed the raw header; this asserts the origin is allowed
     * but that what comes back is the LOWERCASED canonical form and is not
     * byte-equal to what the caller sent.
     */
    public function testUppercaseOriginUnderARegexRuleIsEchoedLowercasedNeverRaw(): void
    {
        $raw       = 'HTTPS://BCC-FRONTEND-ABC123DEF-PHILLIP-SIMON-S-PROJECTS.VERCEL.APP';
        $canonical = 'https://bcc-frontend-abc123def-' . self::TEAM . '.vercel.app';

        $acao = $this->acao($raw);

        self::assertSame($canonical, $acao, 'the canonical, lowercased origin must be echoed');
        self::assertNotSame($raw, $acao, 'the raw request bytes must never be reflected');
        self::assertSame(
            strtolower($raw),
            $acao,
            'canonicalisation is a pure lowercase here — if these diverge the fixture is wrong'
        );
    }

    /**
     * The pattern is matched case-SENSITIVELY. An uppercase origin is allowed
     * only because canonicalizeOrigin() lowercased it first, not because the
     * matcher is tolerant. Proven by a rule containing an uppercase literal,
     * which can therefore never match anything.
     */
    public function testRegexRuleIsMatchedCaseSensitively(): void
    {
        self::assertNotNull(
            self::compileRule('^https://bcc-frontend-[a-z0-9-]+-' . self::TEAM . '\\.vercel\\.app$'),
            'control: the lowercase rule compiles'
        );

        // An uppercase rule compiles fine but matches no canonical origin,
        // because every canonical origin is lowercase and there is no /i.
        $upper = self::compileRule('^HTTPS://BCC-FRONTEND-ABC\\.VERCEL\\.APP$');
        self::assertNotNull($upper);
        self::assertStringNotContainsString(
            'i',
            (string) substr((string) $upper, (int) strrpos((string) $upper, '#') + 1),
            'the trailing modifier list must not contain `i`'
        );
        self::assertSame(
            0,
            preg_match((string) $upper, 'https://bcc-frontend-abc.vercel.app'),
            'a case-sensitive rule must not match the lowercased canonical origin'
        );
    }

    /**
     * A `regex:` entry is a filter applied AFTER canonicalizeOrigin(), never
     * a way around it. Every origin below would satisfy the configured
     * pattern's visible intent, and every one must still be refused.
     *
     * The two mechanisms are called out per row because they are different:
     * most rows never reach the matcher at all (canonicalizeOrigin returns
     * null), while `port` reaches it and is refused by the anchoring, since
     * the canonical form carries `:8443` and the pattern ends at `.app$`.
     */
    public function testRegexRuleCannotBypassStructuralOriginValidation(): void
    {
        $host = 'bcc-frontend-abc123def-' . self::TEAM . '.vercel.app';

        $fixtures = [
            'unexpected port'          => 'https://' . $host . ':8443',
            'path beyond root'         => 'https://' . $host . '/admin',
            'query string'             => 'https://' . $host . '?x=1',
            'fragment'                 => 'https://' . $host . '#f',
            'embedded credentials'     => 'https://user:pass@' . $host,
            'userinfo confusion'       => 'https://' . $host . '@evil.com',
            'plain http'               => 'http://' . $host,
            'trailing dot'             => 'https://' . $host . '.',
            'literal null'             => 'null',
            'crlf injection'           => "https://{$host}\r\nX-Injected: 1",
            'embedded space'           => 'https://bcc-frontend abc-' . self::TEAM . '.vercel.app',
            'percent-encoded dot'      => 'https://bcc-frontend-abc123def-' . self::TEAM . '%2evercel.app',
            'embedded nul byte'        => 'https://bcc-frontend-abc' . "\0" . '-' . self::TEAM . '.vercel.app',
            'suffix attack'            => 'https://' . $host . '.evil.com',
            'subdomain attack'         => 'https://evil.' . $host,
        ];

        foreach ($fixtures as $label => $origin) {
            self::assertNull(
                $this->acao($origin),
                "{$label}: a regex rule must not smuggle an origin past canonicalizeOrigin()"
            );
        }

        // Control — without this the loop could pass because the rule matches
        // nothing at all, which would make every row above vacuous.
        self::assertSame(
            'https://' . $host,
            $this->acao('https://' . $host),
            'the same rule must still allow the well-formed origin'
        );
    }

    /**
     * A TRAILING nul (or space, tab, CR, LF, vertical tab) is stripped by the
     * `trim()` in requestOrigin() before validation, so such an origin is
     * ALLOWED — but what gets echoed is the trimmed canonical form, with the
     * junk byte gone. That is the property that matters: no caller-supplied
     * byte is reflected.
     *
     * Documented rather than "fixed" because it is uniform across all three
     * entry forms and is a normalisation, not a bypass. An EMBEDDED nul
     * survives trim() and is refused by the charset check — see the fixture
     * table above. Pinned both ways so neither half drifts.
     */
    public function testTrailingWhitespaceBytesAreTrimmedAndNeverReflected(): void
    {
        $host = 'https://bcc-frontend-abc123def-' . self::TEAM . '.vercel.app';

        foreach (["\0", ' ', "\t", "\r\n", "\x0b"] as $suffix) {
            $acao = $this->acao($host . $suffix);

            self::assertSame($host, $acao, 'the trimmed canonical origin is what comes back');
            self::assertNotSame($host . $suffix, $acao, 'the raw bytes must never be reflected');
            self::assertSame(
                1,
                preg_match('/^[\x21-\x7e]+$/', (string) $acao),
                'the echoed header must be printable ASCII only — no header smuggling'
            );
        }
    }

    /**
     * ANCHORING DECISION: every pattern is wrapped in `^(?:…)$`, always. A
     * substring rule therefore matches nothing rather than matching
     * everything containing it.
     */
    public function testUnanchoredRegexMatchesNothingBecauseEveryPatternIsWrapped(): void
    {
        // Config: `regex:bcc-frontend` — a bare substring.
        foreach ([
            'https://bcc-frontend-abc123def-' . self::TEAM . '.vercel.app',
            'https://bcc-frontend.evil.com',
            'https://evil.com/bcc-frontend',
            'https://bcc-frontend.io',
        ] as $origin) {
            self::assertNull($this->acao($origin), "unanchored rule must not match {$origin}");
        }

        // The rule is not rejected outright — it compiles, it simply cannot
        // match a whole origin. Distinguishing these two is the point.
        self::assertNotNull(self::compileRule('bcc-frontend'), 'the entry is wrapped, not refused');
        self::assertSame(self::PROD, $this->acao(self::PROD), 'the rest of the allowlist is unaffected');
    }

    /**
     * WHY WRAPPING BEATS ANCHOR-CHECKING, concretely.
     *
     * Config here is `regex:^https://bluecollarcrypto\.io|evil`. It CONTAINS
     * a `^`, so an implementation that merely verified the presence of
     * anchors would accept it — and then `https://evil.com` matches, because
     * alternation binds looser than the anchor and the second branch is a
     * bare substring. That is a live false-allow produced by a rule that
     * looks anchored.
     *
     * Wrapping makes the branch `^(?:…|evil)$`, which only matches the
     * literal string `evil` — not an origin, so nothing.
     */
    public function testAnchorPresenceIsNotAnchorGuarantee(): void
    {
        $sneaky = '^https://bluecollarcrypto\\.io|evil';

        // 1. The premise: an anchor-presence check WOULD have accepted this.
        self::assertStringContainsString('^', $sneaky);

        // 2. And it really is exploitable when used unwrapped. If this ever
        //    stops being true the test below proves nothing.
        self::assertSame(
            1,
            preg_match('#' . $sneaky . '#', 'https://evil.com'),
            'premise failed: the unwrapped pattern no longer false-allows'
        );

        // 3. As configured, it does not.
        self::assertNull($this->acao('https://evil.com'), 'the substring branch must not match');
        self::assertNull($this->acao('https://evil.example'));

        // 4. The legitimate branch still works.
        self::assertSame(self::PROD, $this->acao(self::PROD));
    }

    /**
     * The wrapper is `^(?:…)$`, not `^…$`. Without the non-capturing group an
     * alternation would anchor only its first and last branch.
     */
    public function testAlternationIsGroupedSoEveryBranchIsAnchored(): void
    {
        // Config: `regex:https://a\.example|https://b\.example` (no anchors).
        self::assertSame('https://a.example', $this->acao('https://a.example'));
        self::assertSame('https://b.example', $this->acao('https://b.example'));

        // Neither branch leaks as a substring.
        self::assertNull($this->acao('https://zzzb.example'));
        self::assertNull($this->acao('https://a.example.evil.com'));
    }

    /**
     * A pattern PCRE cannot compile is discarded, and — the load-bearing half
     * — discarding it does not disturb any other entry.
     *
     * Config: `https://…io , regex:^https://[a-z , https://staging…io`. The
     * middle entry has an unterminated character class. Note it got that way
     * by the comma splitter, which is the same trap the counted-quantifier
     * test covers.
     */
    public function testNonCompilingPatternIsDeniedAndTheRestOfTheAllowlistStillWorks(): void
    {
        self::assertNull(self::compileRule('^https://[a-z'), 'an uncompilable pattern must be discarded');

        // It must not somehow become permissive.
        foreach (['https://evil.com', 'https://a.example', 'https://bcc-frontend.io'] as $origin) {
            self::assertNull($this->acao($origin), "{$origin}: a broken rule must never widen the allowlist");
        }

        // Entries on BOTH sides of the broken one still resolve.
        self::assertSame(self::PROD, $this->acao(self::PROD), 'the entry before the broken rule still works');
        self::assertSame(self::STAGING, $this->acao(self::STAGING), 'the entry after it still works');
    }

    /**
     * `regex:.*` — the degenerate catch-all. DECISION: REJECTED, not treated
     * as acceptable operator rope.
     *
     * A total catch-all in a CORS allowlist is never a deliberate policy; it
     * is what gets pasted in while debugging and then shipped. The negative
     * canary (an RFC 2606 `.invalid` host no honest rule can want) catches
     * exactly that shape.
     *
     * ⚠ What is still rope, deliberately: BREADTH short of totality.
     * `^https://.*\.vercel\.app$` does not match the canary, is accepted, and
     * hands CORS to every Vercel deployment on the internet. No local check
     * can separate a broad-but-intentional rule from a broad-but-careless
     * one, so that remains a config-review responsibility. This test asserts
     * both halves so the boundary is explicit rather than implied.
     */
    public function testRegexCatchAllIsRefusedAsAConfigurationError(): void
    {
        self::assertNull(self::compileRule('.*'), 'a total catch-all must be refused');

        foreach (['https://evil.com', 'https://anything.example', 'https://bcc-frontend.io'] as $origin) {
            self::assertNull($this->acao($origin), "{$origin}: `regex:.*` must not allow it");
        }

        // The rest of the allowlist is untouched by the refusal.
        self::assertSame(self::PROD, $this->acao(self::PROD));

        // The documented limit of the guard: broad-but-not-total is ACCEPTED.
        self::assertNotNull(
            self::compileRule('^https://.*\\.vercel\\.app$'),
            'breadth short of totality is deliberately still permitted — see the docblock'
        );
    }

    /** The other spellings of "everything" are caught too. */
    public function testRegexCatchAllVariantsAreAllRefused(): void
    {
        foreach (['.*', '.+', '[\\s\\S]*', '(.*)', '.{0,}', '[^#]*'] as $pattern) {
            self::assertNull(self::compileRule($pattern), "`{$pattern}` must be refused as a catch-all");
        }

        foreach (['https://evil.com', 'https://anything.example'] as $origin) {
            self::assertNull($this->acao($origin), "{$origin}: no catch-all variant may allow it");
        }

        self::assertSame(self::PROD, $this->acao(self::PROD));
    }

    /**
     * ⚠ THE COMMA TRAP. The allowlist splits on `,` before any entry is
     * parsed, so a counted quantifier is torn in half. This is a real
     * operator footgun, and the point of the test is that it fails CLOSED:
     * the front half will not compile, the back half is not an origin, and
     * neither becomes permissive.
     */
    public function testCountedQuantifierIsTornApartByTheAllowlistSplitterAndFailsClosed(): void
    {
        // Prove the premise — the configured value really did get split.
        $entries = explode(',', (string) BCC_FRONTEND_ORIGIN);
        self::assertCount(3, $entries, 'premise: `{6,32}` splits the entry in two');
        self::assertStringEndsWith('[a-z0-9]{6', $entries[1]);
        self::assertSame('32}-' . self::TEAM . '\\.vercel\\.app$', $entries[2]);

        // The preview host the operator was trying to allow is NOT allowed…
        self::assertNull(
            $this->acao('https://bcc-frontend-abc123def-' . self::TEAM . '.vercel.app'),
            'the torn rule must not match — this is a silent config failure, by design fail-closed'
        );

        // …and nothing else became allowed either.
        foreach (['https://evil.com', 'https://32.vercel.app'] as $origin) {
            self::assertNull($this->acao($origin), "{$origin}: neither half may widen the allowlist");
        }

        self::assertSame(self::PROD, $this->acao(self::PROD), 'the exact entry is unaffected');
    }

    /** Over the length cap → refused, before PCRE ever sees it. */
    public function testOverlongPatternIsRefused(): void
    {
        $atCap   = '^https://' . str_repeat('a', 190) . '$';
        $overCap = '^https://' . str_repeat('a', 200) . '$';

        self::assertLessThanOrEqual(200, strlen($atCap));
        self::assertGreaterThan(200, strlen($overCap));

        self::assertNotNull(self::compileRule($atCap), 'a pattern within the cap compiles');
        self::assertNull(self::compileRule($overCap), 'a pattern over the cap is refused');
        self::assertNull(self::compileRule(''), 'an empty pattern is refused');

        self::assertSame(self::PROD, $this->acao(self::PROD), 'the rest of the allowlist still works');
    }

    /** The delimiter cannot appear in the body, so `#` is refused. */
    public function testPatternContainingTheDelimiterIsRefused(): void
    {
        self::assertNull(self::compileRule('^https://bcc#frontend\\.example$'));
        self::assertNull(self::compileRule('#'));
        self::assertNull(
            self::compileRule("^https://a\x01b\\.example$"),
            'a control character in the pattern is refused too'
        );

        self::assertSame(self::PROD, $this->acao(self::PROD));
    }

    /**
     * A pattern beginning with `//` is refused — not because CORS needs it
     * (no canonical origin starts with `//`, so it could never match) but
     * because `regex://evil.com` parses as a URL whose host is `evil.com`,
     * and FrontendRedirect::allowedHosts() would then trust that host as an
     * OAuth `return_to` target. Refusing the shape here is free.
     */
    public function testLeadingDoubleSlashPatternIsRefused(): void
    {
        self::assertNull(self::compileRule('//evil.com'));
        self::assertNull(self::compileRule('//evil.com$'));

        // Prove WHY: this is what the neighbouring parser would have seen.
        $parsed = parse_url('regex://evil.com');
        self::assertIsArray($parsed);
        self::assertSame(
            'evil.com',
            $parsed['host'] ?? null,
            'premise: a leading // makes the entry parse as a URL with a host'
        );

        // …and that only a LEADING // does it, so ordinary rules are safe.
        self::assertArrayNotHasKey('host', (array) parse_url('regex:^https://x\\.example$'));
        self::assertNotNull(self::compileRule('^https://x\\.example$'));

        self::assertSame(self::PROD, $this->acao(self::PROD));
    }

    /**
     * A catastrophic-backtracking rule must fail CLOSED rather than hang or,
     * worse, be read as a match.
     *
     * ⚠ Getting this test to test anything is fiddly, and the first version
     * of it was vacuous — worth knowing about before editing it. The obvious
     * subject, `https://<200 a's>.io`, never reaches the matcher at all:
     * `isValidHostname()` rejects a DNS label longer than 63 bytes, so
     * `canonicalizeOrigin()` returns null and the rule is never consulted.
     * The test passed, caught nothing, and a mutation that made PCRE errors
     * fail OPEN survived it.
     *
     * So the subject below is built from three 63-byte labels: long enough to
     * make `(a|a)+` explode, short enough to be a legal hostname. Step 1
     * asserts that structural validity directly, because that is the
     * assumption the whole test rests on.
     */
    public function testCatastrophicPatternFailsClosedWithoutHanging(): void
    {
        $label   = str_repeat('a', 63);
        $host    = "{$label}.{$label}.{$label}.io";
        $subject = 'https://' . $host;

        // 1. The subject really is a well-formed origin, so a denial below can
        //    only come from the matcher — not from canonicalizeOrigin().
        self::assertLessThanOrEqual(253, strlen($host));
        self::assertLessThanOrEqual(255, strlen($subject));
        self::assertNotNull(
            self::canonicalize($subject),
            'premise: the subject must survive structural validation, or this test is vacuous'
        );

        // 2. The configured rule really is catastrophic against it — PCRE
        //    gives up rather than answering.
        self::assertFalse(
            @preg_match('#^(?:https://(a|a)+\\.io)$#D', $subject),
            'premise: the rule must actually blow the backtrack limit'
        );
        self::assertSame(PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());

        // 3. And that is read as a denial, quickly.
        $started = microtime(true);
        self::assertNull($this->acao($subject), 'a blown backtrack limit must deny, never allow');
        $elapsedMs = (microtime(true) - $started) * 1000;

        self::assertLessThan(
            2000.0,
            $elapsedMs,
            'the match must be bounded by pcre.backtrack_limit, not run to completion'
        );

        self::assertSame(self::PROD, $this->acao(self::PROD), 'the rest of the allowlist still works');
    }

    /**
     * All three entry forms in one config, each doing its own job and none
     * interfering with the others.
     */
    public function testAllThreeEntryFormsCoexist(): void
    {
        $config = (string) BCC_FRONTEND_ORIGIN;
        self::assertStringContainsString(self::PROD, $config);
        self::assertStringContainsString('vercel:bcc-frontend:' . self::TEAM, $config);
        self::assertStringContainsString('regex:', $config);

        // 1. exact
        self::assertSame(self::PROD, $this->acao(self::PROD));

        // 2. vercel: — the hash form, matched structurally.
        $hash = 'https://bcc-frontend-abc123def-' . self::TEAM . '.vercel.app';
        self::assertSame($hash, $this->acao($hash));

        // 3. vercel: — the git-* branch form the retired regex could never
        //    reach (defect 2). This is why the structural form is preferred.
        $branch = 'https://bcc-frontend-git-main-' . self::TEAM . '.vercel.app';
        self::assertSame($branch, $this->acao($branch));

        // 4. regex: — same team, but a host shape the vercel: rule rejects,
        //    proving the regex entry is actually load-bearing here and the
        //    assertion is not being satisfied by the vercel: rule.
        $viaRegex = 'https://bcc-frontend-a-b-c-' . self::TEAM . '.vercel.app';
        self::assertSame($viaRegex, $this->acao($viaRegex));

        // Everything else is still denied.
        foreach ([
            'https://evil.com',
            'https://bcc-frontend-abc123def-other-team.vercel.app',
            'https://bcc-frontend-abc123def-' . self::TEAM . '.vercel.app.evil.com',
            'https://evil.bcc-frontend-abc123def-' . self::TEAM . '.vercel.app',
        ] as $origin) {
            self::assertNull($this->acao($origin), "{$origin}: must stay denied");
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Global REST ownership
    //
    // The gate used to delegate to EdgeCache::appliesTo(), i.e. a CACHE
    // policy decided a SECURITY boundary. It answered "BCC namespaces
    // only", so /wp-json/ and /wp/v2/* were left on core's reflected
    // headers — reproduced on production 2026-08-19, where
    // evil.example.com was echoed back from /wp-json/wp/v2/types.
    //
    // These are the regression guards for that: if ownsRoute() is ever
    // re-narrowed, or re-coupled to the cache predicate, the core-route
    // rows fail.
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
        yield 'REST index'    => ['/wp-json/'];
        yield 'core wp/v2'    => ['/wp-json/wp/v2/types'];
        yield 'bcc/v1'        => ['/wp-json/bcc/v1/example'];
        yield 'bcc-trust/v1'  => ['/wp-json/bcc-trust/v1/example'];
        yield 'rest_route'    => ['/?rest_route=/wp/v2/types'];
    }

    #[DataProvider('ownedRoutes')]
    public function testCorsIsOwnedOnEveryRestRouteViaTheResponsePath(string $route): void
    {
        self::assertSame(
            self::PROD,
            $this->acao(self::PROD, $route),
            "the handler must own {$route}, not defer to core"
        );
        self::assertSame(
            [],
            HeaderRecorder::values('Access-Control-Allow-Credentials'),
            'credentials must never be emitted'
        );
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
        self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Credentials'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Origin policy, on a CORE route
    //
    // /wp/v2/types is the representative route precisely because it is
    // the one no BCC code used to guard.
    // ─────────────────────────────────────────────────────────────────

    /** @return iterable<string, array{string, ?string}> */
    public static function originPolicy(): iterable
    {
        yield 'configured origin allowed' => [self::PROD, self::PROD];
        yield 'hostile origin denied'     => ['https://evil.example.com', null];
        yield 'wrong environment denied'  => [self::STAGING, null];
        yield 'literal null denied'       => ['null', null];
        yield 'malformed denied'          => ['https://bluecollarcrypto.io/path?q=1#f', null];
        yield 'absent origin gets none'   => ['', null];
    }

    #[DataProvider('originPolicy')]
    public function testOriginPolicyOnACoreRouteResponsePath(string $origin, ?string $expected): void
    {
        self::assertSame($expected, $this->acao($origin, '/wp/v2/types'));
        self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Credentials'));
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
        self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Credentials'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Ordered sequences — no header state may survive between requests.
    // ─────────────────────────────────────────────────────────────────

    public function testAllowedThenEvilThenAllowedLeaksNothing(): void
    {
        self::assertSame(self::PROD, $this->acao(self::PROD, '/wp/v2/types'), 'call 1');
        self::assertNull($this->acao('https://evil.example.com', '/wp/v2/types'), 'call 2 must not inherit call 1');
        self::assertSame(self::PROD, $this->acao(self::PROD, '/wp/v2/types'), 'call 3');
        self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Credentials'));
    }

    public function testEvilThenAllowedThenEvilLeaksNothing(): void
    {
        self::assertNull($this->acao('https://evil.example.com', '/wp/v2/types'), 'call 1');
        self::assertSame(self::PROD, $this->acao(self::PROD, '/wp/v2/types'), 'call 2');
        self::assertNull($this->acao('https://evil.example.com', '/wp/v2/types'), 'call 3 must not inherit call 2');
        self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Credentials'));
    }
}
