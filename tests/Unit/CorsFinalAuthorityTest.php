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

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../Stubs/cors-stubs.php';

        if (!defined('BCC_FRONTEND_ORIGIN')) {
            define(
                'BCC_FRONTEND_ORIGIN',
                self::PROD
                . ',' . self::STAGING
                . ',vercel:bcc-frontend:' . self::TEAM
                . ',http://localhost:3000'
            );
        }

        HeaderRecorder::reset();
        Hooks::reset();

        unset($_SERVER['HTTP_ORIGIN']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/wp-json/bcc/v1/users';
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

    public function testRetiredRegexEntryIsNeverHonoured(): void
    {
        // The retired form, verbatim from the shipped wp-config example.
        // It must neither match nor be mis-read as an exact origin.
        $entry = 'regex:^https://bcc-frontend-[a-z0-9]+-' . self::TEAM . '\\.vercel\\.app$';

        foreach ([$entry, 'https://bcc-frontend-abc123def-' . self::TEAM . '.vercel.app'] as $origin) {
            $_SERVER['HTTP_ORIGIN'] = $origin;
            $this->serveRest('/bcc/v1/users');
            // The preview origin is still allowed — by the `vercel:` rule
            // that setUp() configures, not by the regex entry.
            if (str_starts_with($origin, 'regex:')) {
                self::assertSame([], HeaderRecorder::values('Access-Control-Allow-Origin'));
            }
        }

        self::assertStringContainsString(
            'vercel:bcc-frontend:' . self::TEAM,
            (string) BCC_FRONTEND_ORIGIN,
            'preview coverage must come from the structural rule, not a regex'
        );
    }
}
