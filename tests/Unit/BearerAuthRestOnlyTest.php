<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\BearerAuth;
use BCC\Trust\Core\Support\JwtToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Security review, 2026-11-19: BearerAuth signed a member in on EVERY
 * request type, not only REST. With the app's token a member was a
 * signed-in WordPress user on cms HTML (a 404 page printed a peepso_nonce
 * minted for them) and on admin-ajax and /peepsoajax/, where PeepSo then
 * answered for them (tags.get_taggable named any activity's author and
 * commenters; admin-ajax's peepso_embed_content fetched any URL).
 *
 * The bearer now counts on a REST request through the front controller
 * only, decided from the request itself (REST_REQUEST is not defined yet
 * when core first resolves the user). Pinned against the REAL
 * BearerAuth + JwtToken over account-prehijack-stubs.php and
 * rest-request-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BearerAuthRestOnlyTest extends TestCase
{
    private const USER = 90;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/envelope-meta-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        require_once __DIR__ . '/../Stubs/rest-request-stubs.php';
        $GLOBALS['__bcc_envelope_meta_throttle'] = ['allow' => true];
        $GLOBALS['__bcc_ph'] = [
            'users'      => [self::USER => ['email' => 'u90@example.com', 'login' => 'u90', 'display' => 'U90']],
            'meta'       => [self::USER => ['bcc_handle' => 'handle90']],
            'transients' => [],
            'mail'       => [],
            'actions'    => [],
            'options'    => [],
        ];
        $GLOBALS['__bcc_sig'] = ['ajax' => false, 'admin' => false, 'cron' => false];
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_SELF']);
    }

    // ── The rule: a pure function of the request ──────────────────────────

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function restRequests(): iterable
    {
        // [SCRIPT_NAME, REQUEST_URI, home path, REST prefix]
        yield "the app's REST call"           => ['/index.php', '/wp-json/bcc/v1/me/account', '', 'wp-json'];
        yield 'the bcc-trust namespace'         => ['/index.php', '/wp-json/bcc-trust/v1/vote', '', 'wp-json'];
        yield 'with a query string'             => ['/index.php', '/wp-json/bcc/v1/feed?cursor=abc&x=1', '', 'wp-json'];
        yield 'the REST index'                  => ['/index.php', '/wp-json', '', 'wp-json'];
        yield 'the REST index with a query string' => ['/index.php', '/wp-json?_envelope=1', '', 'wp-json'];
        yield 'the REST index, trailing slash'  => ['/index.php', '/wp-json/', '', 'wp-json'];
        yield 'a doubled leading slash (core trims it too)' => ['/index.php', '//wp-json/bcc/v1/me', '', 'wp-json'];
        yield 'a configured prefix'             => ['/index.php', '/api/bcc/v1/me', '', 'api'];
        yield 'a prefix configured with slashes' => ['/index.php', '/wp-json/bcc/v1/me', '', '/wp-json/'];
        yield 'a sub-directory install'         => ['/cms/index.php', '/cms/wp-json/bcc/v1/me', '/cms', 'wp-json'];
        yield 'a sub-directory home, any case (core strips it case-blind)' => ['/cms/index.php', '/CMS/wp-json/bcc/v1/me', '/cms/', 'wp-json'];
    }

    #[DataProvider('restRequests')]
    public function testARestRequestThroughTheFrontControllerCounts(string $script, string $uri, string $home, string $prefix): void
    {
        self::assertTrue(BearerAuth::isRestRequestFor($script, $uri, $home, $prefix, false));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function nonRestPaths(): iterable
    {
        // [REQUEST_URI, home path, REST prefix], all through /index.php
        yield 'a cms 404 page'                  => ['/no-such-page/', '', 'wp-json'];
        yield 'the front page'                  => ['/', '', 'wp-json'];
        yield 'PeepSo ajax'                     => ['/peepsoajax/tags.get_taggable', '', 'wp-json'];
        yield 'PeepSo ajax, deeper'             => ['/x/peepsoajax/tags.get_taggable', '', 'wp-json'];
        yield 'a feed'                          => ['/feed/', '', 'wp-json'];
        yield '?rest_route= is not honoured'    => ['/?rest_route=/bcc/v1/me/account', '', 'wp-json'];
        yield '?rest_route= on a page'          => ['/sample-page/?rest_route=/bcc/v1/me', '', 'wp-json'];
        yield 'PATH_INFO fails closed'          => ['/index.php/wp-json/bcc/v1/me', '', 'wp-json'];
        yield 'the prefix inside the path'      => ['/x/wp-json/bcc/v1/me', '', 'wp-json'];
        yield 'a longer first segment'          => ['/wp-jsonx/bcc/v1/me', '', 'wp-json'];
        yield 'a hyphenated look-alike'         => ['/wp-json-x/bcc/v1/me', '', 'wp-json'];
        yield 'a percent-encoded prefix fails closed' => ['/wp%2Djson/bcc/v1/me', '', 'wp-json'];
        yield 'an upper-case prefix (core routes it nowhere)' => ['/WP-JSON/bcc/v1/me', '', 'wp-json'];
        yield 'the default prefix when another is configured' => ['/wp-json/bcc/v1/me', '', 'api'];
        yield 'a sub-directory install, the prefix outside it' => ['/cms/x/wp-json/bcc/v1/me', '/cms', 'wp-json'];
        yield 'no request URI'                  => ['', '', 'wp-json'];
        yield 'no REST prefix'                  => ['/wp-json/bcc/v1/me', '', ''];
    }

    #[DataProvider('nonRestPaths')]
    public function testAnyOtherPathDoesNotCount(string $uri, string $home, string $prefix): void
    {
        self::assertFalse(BearerAuth::isRestRequestFor('/index.php', $uri, $home, $prefix, false));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool}> */
    public static function otherEntryPoints(): iterable
    {
        // [SCRIPT_NAME, REQUEST_URI, an admin/ajax/cron/XML-RPC request]
        yield 'admin-ajax.php carrying rest_route (the SSRF door)' => ['/wp-admin/admin-ajax.php', '/wp-admin/admin-ajax.php?rest_route=/bcc/v1/me&action=peepso_embed_content', true];
        yield 'admin-ajax.php, even with a REST path'  => ['/wp-admin/admin-ajax.php', '/wp-json/bcc/v1/me', true];
        yield 'admin-post.php carrying rest_route'     => ['/wp-admin/admin-post.php', '/wp-admin/admin-post.php?rest_route=/bcc/v1/me', true];
        yield 'wp-login.php carrying rest_route'       => ['/wp-login.php', '/wp-login.php?rest_route=/bcc/v1/me', false];
        yield 'wp-cron.php'                            => ['/wp-cron.php', '/wp-cron.php?doing_wp_cron=1', true];
        yield 'xmlrpc.php'                             => ['/xmlrpc.php', '/xmlrpc.php', true];
        yield 'the front controller, but flagged ajax/admin' => ['/index.php', '/wp-json/bcc/v1/me', true];
        yield 'no SCRIPT_NAME'                         => ['', '/wp-json/bcc/v1/me', false];
        yield 'a bare index.php'                       => ['index.php', '/wp-json/bcc/v1/me', false];
        yield 'a look-alike script'                    => ['/x-index.php', '/wp-json/bcc/v1/me', false];
    }

    #[DataProvider('otherEntryPoints')]
    public function testNoOtherEntryPointCounts(string $script, string $uri, bool $otherEntry): void
    {
        self::assertFalse(BearerAuth::isRestRequestFor($script, $uri, '', 'wp-json', $otherEntry));
    }

    // ── The reader: what the server and WordPress say ─────────────────────

    private static function request(string $script, string $uri): void
    {
        $_SERVER['SCRIPT_NAME'] = $script;
        $_SERVER['REQUEST_URI'] = $uri;
    }

    public function testTheReaderAcceptsTheAppsRestCall(): void
    {
        self::request('/index.php', '/wp-json/bcc/v1/me/account');

        self::assertTrue(BearerAuth::isRestRequest());
    }

    public function testTheReaderFallsBackToPhpSelf(): void
    {
        unset($_SERVER['SCRIPT_NAME']);
        $_SERVER['PHP_SELF']    = '/index.php';
        $_SERVER['REQUEST_URI'] = '/wp-json/bcc/v1/me/account';

        self::assertTrue(BearerAuth::isRestRequest());
    }

    public function testTheReaderUsesTheConfiguredPrefix(): void
    {
        $GLOBALS['__bcc_sig']['rest_prefix'] = 'api';
        self::request('/index.php', '/wp-json/bcc/v1/me/account');

        self::assertFalse(BearerAuth::isRestRequest(), 'not the hard-coded default');

        self::request('/index.php', '/api/bcc/v1/me/account');
        self::assertTrue(BearerAuth::isRestRequest());
    }

    /** @return iterable<string, array{0: string}> */
    public static function entryFlags(): iterable
    {
        yield 'is_admin()'      => ['admin'];
        yield 'wp_doing_ajax()' => ['ajax'];
        yield 'wp_doing_cron()' => ['cron'];
    }

    #[DataProvider('entryFlags')]
    public function testTheReaderRefusesAnAdminAjaxOrCronRequest(string $flag): void
    {
        $GLOBALS['__bcc_sig'][$flag] = true;
        self::request('/index.php', '/wp-json/bcc/v1/me/account');

        self::assertFalse(BearerAuth::isRestRequest());
    }

    public function testTheReaderRefusesAnXmlRpcRequest(): void
    {
        define('XMLRPC_REQUEST', true);
        self::request('/index.php', '/wp-json/bcc/v1/me/account');

        self::assertFalse(BearerAuth::isRestRequest());
    }

    // ── authenticateBearer(): the token counts on REST only ───────────────

    private static function withToken(string $token): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }

    public function testTheAppsRestCallIsSignedIn(): void
    {
        self::withToken(JwtToken::encode(self::USER, 'handle90'));
        self::request('/index.php', '/wp-json/bcc/v1/me/account');

        self::assertSame(self::USER, BearerAuth::authenticateBearer(false));
        self::assertSame(self::USER, BearerAuth::authenticatedUserId());
    }

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, bool>}> */
    public static function nonRestDoors(): iterable
    {
        yield 'a cms 404 page'           => ['/index.php', '/no-such-page/', []];
        yield 'PeepSo ajax (tags.get_taggable)' => ['/index.php', '/peepsoajax/tags.get_taggable', []];
        yield 'PeepSo ajax, deeper'      => ['/index.php', '/x/peepsoajax/tags.get_taggable', []];
        yield 'admin-ajax.php: peepso_embed_content via rest_route' => ['/wp-admin/admin-ajax.php', '/wp-admin/admin-ajax.php?rest_route=/bcc/v1/me&action=peepso_embed_content', ['ajax' => true, 'admin' => true]];
        yield 'admin-post.php'           => ['/wp-admin/admin-post.php', '/wp-admin/admin-post.php?rest_route=/bcc/v1/me', ['admin' => true]];
        yield 'wp-login.php'             => ['/wp-login.php', '/wp-login.php', []];
        yield 'a feed'                   => ['/index.php', '/feed/', []];
    }

    /** @param array<string, bool> $flags */
    #[DataProvider('nonRestDoors')]
    public function testTheSameTokenAnywhereElseIsIgnored(string $script, string $uri, array $flags): void
    {
        $GLOBALS['__bcc_sig'] = $flags + $GLOBALS['__bcc_sig'];
        self::withToken(JwtToken::encode(self::USER, 'handle90'));
        self::request($script, $uri);

        self::assertFalse(BearerAuth::authenticateBearer(false), 'signed out');
        self::assertSame(0, BearerAuth::authenticatedUserId());
        self::assertNull(BearerAuth::presentedTokenFailure(), 'ignored, not judged');
    }

    public function testADeadTokenOutsideRestIsNotJudgedEither(): void
    {
        self::withToken('not.a.token');
        self::request('/index.php', '/no-such-page/');

        self::assertFalse(BearerAuth::authenticateBearer(false));
        self::assertNull(BearerAuth::presentedTokenFailure());

        self::request('/index.php', '/wp-json/bcc/v1/me/account');
        self::assertFalse(BearerAuth::authenticateBearer(false));
        self::assertSame(BearerAuth::TOKEN_INVALID, BearerAuth::presentedTokenFailure(), 'on REST it is still judged');
    }

    /** @return iterable<string, array{0: string}> */
    public static function anyRequest(): iterable
    {
        yield 'a REST call' => ['/wp-json/bcc/v1/me/account'];
        yield 'a cms page'  => ['/no-such-page/'];
    }

    #[DataProvider('anyRequest')]
    public function testAnEarlierLoginStandsAnywhere(string $uri): void
    {
        self::withToken(JwtToken::encode(self::USER, 'handle90'));
        self::request('/index.php', $uri);

        self::assertSame(1, BearerAuth::authenticateBearer(1), 'a cookie login is not the bearer\'s to undo');
        self::assertSame(0, BearerAuth::authenticatedUserId());
    }

    // ── The side effect: those doors see a signed-out request ─────────────

    /**
     * Core's `determine_current_user` chain as WordPress runs it for a
     * request with no cookie and no application password: the cookie
     * handler (10) and the application-password handler (20) answer false,
     * then the bearer (30). wp_get_current_user() turns false into user 0.
     */
    private static function currentUser(): int
    {
        $user = false;
        $user = (static fn($u) => $u)($user);   // wp_validate_auth_cookie: no cookie
        $user = (static fn($u) => $u)($user);   // wp_validate_application_password: none
        $user = BearerAuth::authenticateBearer($user);

        return is_int($user) ? $user : 0;
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function signedOutDoors(): iterable
    {
        // A cms page mints its peepso_nonce for this user; PeepSo's ajax
        // handler answers "session expired" to user 0.
        yield 'a cms 404 page (its peepso_nonce is the signed-out one)' => ['/index.php', '/no-such-page/'];
        yield '/peepsoajax/tags.get_taggable (session expired)'         => ['/index.php', '/peepsoajax/tags.get_taggable'];
    }

    #[DataProvider('signedOutDoors')]
    public function testAMembersBearerLeavesThosePagesSignedOut(string $script, string $uri): void
    {
        self::withToken(JwtToken::encode(self::USER, 'handle90'));
        self::request($script, $uri);

        self::assertSame(0, self::currentUser());

        self::request('/index.php', '/wp-json/bcc/v1/me/account');
        self::assertSame(self::USER, self::currentUser(), 'the same token still signs the app in');
    }
}
