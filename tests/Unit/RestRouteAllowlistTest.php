<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Security\RestRouteAllowlist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Security review (2026-11-18): BearerAuth authenticates every REST request,
 * so a member's token reached core's routes (`PUT /wp/v2/users/me` changed
 * the password or email around every BCC step-up) and every other plugin's.
 * RestRouteAllowlist opens only BCC's namespaces to non-admins:
 *   - members: `/bcc/v1` and `/bcc-trust/v1` only; anything else is 403;
 *   - signed out: BCC's namespaces plus the exact EXEMPT_ROUTES (none
 *     today); anything else is 401, reads included (the allowlist's own
 *     review: `/wp/v2/media` listed every member's blog-cover upload with
 *     its author id);
 *   - admins: untouched.
 * Application passwords are for admins only.
 *
 * A refusal is a core `rest_forbidden` RESPONSE, never a bare WP_Error: a
 * bare one inside a `/batch/v1` call makes core's batch handler fatal (500).
 *
 * Runs the real filters over minimal WordPress doubles defined here and in
 * tests/Stubs/rest-batch-stubs.php (WP_Error, the response conversion and
 * the batch handler's per-request tail, mirroring WordPress 7.0.2).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RestRouteAllowlistTest extends TestCase
{
    private const ALL_METHODS = ['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** A stand-in exemption list: one QUIC.cloud-style signed callback. */
    private const SAMPLE_EXEMPTIONS = ['POST /litespeed/v1/notify_img'];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__cug'] = ['user' => 0, 'caps' => [], 'filters' => [], 'user_caps' => []];
        require_once __DIR__ . '/../Stubs/rest-batch-stubs.php';
        if (!class_exists('WP_REST_Request', false)) {
            eval('class WP_REST_Request { public function __construct(private string $method = "GET", private string $route = "") {} public function get_route(): string { return $this->route; } public function get_method(): string { return $this->method; } }');
        }
        if (!class_exists('WP_User', false)) {
            eval('class WP_User { public function __construct(public int $ID = 0) {} }');
        }
        if (!function_exists('current_user_can')) {
            eval('function current_user_can(string $cap): bool { return in_array($cap, $GLOBALS["__cug"]["caps"], true); }');
        }
        if (!function_exists('user_can')) {
            eval('function user_can($user, string $cap): bool { return in_array($cap, $GLOBALS["__cug"]["user_caps"][$user->ID] ?? [], true); }');
        }
        if (!function_exists('get_current_user_id')) {
            eval('function get_current_user_id(): int { return (int) $GLOBALS["__cug"]["user"]; }');
        }
        if (!function_exists('add_filter')) {
            eval('function add_filter(string $hook, $cb, int $priority = 10, int $args = 1): bool { $GLOBALS["__cug"]["filters"][] = [$hook, $cb, $priority, $args]; return true; }');
        }
    }

    /** @return mixed */
    private static function dispatch(string $route, string $method = 'GET', mixed $earlier = null)
    {
        return RestRouteAllowlist::filter($earlier, null, new \WP_REST_Request($method, $route));
    }

    private static function member(): void
    {
        $GLOBALS['__cug']['user'] = 42;
    }

    private static function admin(): void
    {
        $GLOBALS['__cug']['user'] = 1;
        $GLOBALS['__cug']['caps'] = ['manage_options'];
    }

    /** Core's rest_forbidden answer, as error_to_response() would render it. */
    private static function refusalBody(int $status): array
    {
        return ['code' => 'rest_forbidden', 'message' => 'Sorry, you are not allowed to do that.', 'data' => ['status' => $status]];
    }

    private static function assertRefused(mixed $res, int $status, string $what): void
    {
        self::assertInstanceOf(\WP_REST_Response::class, $res, $what . ': a response, never a bare WP_Error');
        self::assertSame($status, $res->get_status(), $what);
        self::assertSame(self::refusalBody($status), $res->get_data(), $what);
    }

    // ── BCC's namespaces: open to everyone ────────────────────────────────

    /** @return iterable<string, array{0: string}> */
    public static function bccRoutes(): iterable
    {
        yield 'bcc/v1 route'                 => ['/bcc/v1/posts'];
        yield 'bcc/v1 nested'                => ['/bcc/v1/me/account/password'];
        yield 'bcc-trust/v1 route'           => ['/bcc-trust/v1/vote'];
        yield 'bcc/v1 namespace index'       => ['/bcc/v1'];
        yield 'bcc-trust/v1 namespace index' => ['/bcc-trust/v1'];
        yield 'any letter case (core is case-blind)' => ['/BCC/V1/Posts'];
        yield 'bcc-trust, any letter case'   => ['/Bcc-Trust/V1/vote'];
        yield 'a users-looking BCC route'    => ['/bcc-trust/v1/users/me'];
        yield 'the deploy health probe'      => ['/bcc/v1/ranks'];
    }

    #[DataProvider('bccRoutes')]
    public function testAMemberReachesBccRoutesWithAnyMethod(string $route): void
    {
        self::member();
        $earlier = new \stdClass();

        foreach (self::ALL_METHODS as $method) {
            self::assertNull(self::dispatch($route, $method), "{$method} {$route}");
            self::assertSame($earlier, self::dispatch($route, $method, $earlier), 'whatever answered before stands');
        }
    }

    #[DataProvider('bccRoutes')]
    public function testASignedOutCallerReachesBccRoutesWithAnyMethod(string $route): void
    {
        $earlier = new \stdClass();

        foreach (self::ALL_METHODS as $method) {
            self::assertNull(self::dispatch($route, $method), "{$method} {$route}");
            self::assertSame($earlier, self::dispatch($route, $method, $earlier), 'whatever answered before stands');
        }
    }

    // ── Members: nothing outside BCC's namespaces ─────────────────────────

    /** @return iterable<string, array{0: string}> */
    public static function nonBccRoutes(): iterable
    {
        yield 'core users/me'               => ['/wp/v2/users/me'];
        yield 'core application-passwords'  => ['/wp/v2/users/me/application-passwords'];
        yield 'core posts'                  => ['/wp/v2/posts'];
        yield 'core media'                  => ['/wp/v2/media'];
        yield 'core settings'               => ['/wp/v2/settings'];
        yield 'the REST index'              => ['/'];
        yield 'batch'                       => ['/batch/v1'];
        yield 'oembed proxy'                => ['/oembed/1.0/proxy'];
        yield 'site health'                 => ['/wp-site-health/v1/tests/background-updates'];
        yield 'another plugin'              => ['/litespeed/v3/ping'];
        yield 'PeepSo'                      => ['/peepso/v1/post'];
        yield 'a look-alike namespace'      => ['/bcc/v1x/posts'];
        yield 'a look-alike bcc-trust'      => ['/bcc-trustx/v1/vote'];
        yield 'a look-alike version'        => ['/bcc/v2/posts'];
        yield 'a BCC name, not a namespace' => ['/bcc'];
        yield 'BCC under another prefix'    => ['/wp/v2/bcc/v1/posts'];
        // Neither of these reaches a BCC handler (core matches the route as
        // typed against anchored /bcc/v1/… patterns), so the allow side
        // does not normalise them into one.
        yield '//bcc/v1/posts'              => ['//bcc/v1/posts'];
        yield 'no leading slash'            => ['bcc/v1/posts'];
    }

    #[DataProvider('nonBccRoutes')]
    public function testAMemberIsRefusedEverythingElseWith403(string $route): void
    {
        self::member();

        foreach (self::ALL_METHODS as $method) {
            self::assertRefused(self::dispatch($route, $method), 403, "{$method} {$route}");
        }
    }

    public function testAMemberRefusalWinsOverAnEarlierAnswer(): void
    {
        self::member();

        self::assertRefused(self::dispatch('/wp/v2/users/me', 'PUT', new \stdClass()), 403, 'earlier answer');
    }

    // ── Signed out: nothing outside BCC's namespaces, reads included ──────

    /** @return iterable<string, array{0: string}> */
    public static function coreUsersRoutes(): iterable
    {
        yield 'the collection'                 => ['/wp/v2/users'];
        yield 'trailing slash'                 => ['/wp/v2/users/'];
        yield 'me'                             => ['/wp/v2/users/me'];
        yield 'a user by id'                   => ['/wp/v2/users/7'];
        yield 'me/application-passwords'       => ['/wp/v2/users/me/application-passwords'];
        yield 'an application password'        => ['/wp/v2/users/7/application-passwords/0b6f5f9e-1c1d-4c2e-9f00-000000000001'];
        yield 'introspect'                     => ['/wp/v2/users/me/application-passwords/introspect'];
        yield 'uppercase (core is case-blind)' => ['/WP/V2/Users/Me'];
        yield '?rest_route=wp/v2/users/me'     => ['wp/v2/users/me'];
        yield '?rest_route=//wp/v2//users/me'  => ['//wp/v2//users/me'];
    }

    #[DataProvider('coreUsersRoutes')]
    public function testASignedOutCallerIsRefusedTheUsersRoutesWith401EvenToRead(string $route): void
    {
        foreach (self::ALL_METHODS as $method) {
            self::assertRefused(self::dispatch($route, $method), 401, "{$method} {$route}");
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function readsOutsideBcc(): iterable
    {
        // The review's finding: every member's blog-cover upload, with its
        // author id.
        yield 'core media'                => ['/wp/v2/media'];
        yield 'a media item'              => ['/wp/v2/media/123'];
        yield 'media, any letter case'    => ['/WP/V2/Media'];
        yield 'core comments'             => ['/wp/v2/comments'];
        yield 'core search'               => ['/wp/v2/search'];
        yield 'the REST index'            => ['/'];
        yield 'core posts'                => ['/wp/v2/posts'];
        yield 'core types'                => ['/wp/v2/types'];
        yield 'oembed'                    => ['/oembed/1.0/embed'];
        yield 'batch'                     => ['/batch/v1'];
        yield 'near users: longer name'   => ['/wp/v2/usersx'];
        yield 'near users: shorter name'  => ['/wp/v2/user'];
        yield 'another namespace'         => ['/wp/v3/users'];
        yield 'PeepSo'                    => ['/peepso/v1/activity'];
        yield 'LiteSpeed'                 => ['/litespeed/v1/guest/sync'];
        yield 'Akismet'                   => ['/akismet/v1/alert'];
        yield 'a look-alike namespace'    => ['/bcc/v1x/posts'];
        yield '//bcc/v1/posts'            => ['//bcc/v1/posts'];
        yield 'no leading slash'          => ['bcc/v1/posts'];
    }

    #[DataProvider('readsOutsideBcc')]
    public function testASignedOutCallerMayNotReadOutsideBccWith401(string $route): void
    {
        foreach (['GET', 'HEAD', 'get', 'head'] as $method) {
            self::assertRefused(self::dispatch($route, $method), 401, "{$method} {$route}");
        }
    }

    public function testASignedOutRefusalWinsOverAnEarlierAnswer(): void
    {
        self::assertRefused(self::dispatch('/wp/v2/media', 'GET', new \stdClass()), 401, 'earlier answer');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function anonymousWrites(): iterable
    {
        foreach (['/wp/v2/comments', '/wp/v2/posts', '/batch/v1', '/litespeed/v3/ping', '/litespeed/v1/notify_img', '/akismet/v1/webhook', '/peepso/v1/post', '/bcc/v1x/posts'] as $route) {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'post'] as $method) {
                yield "{$method} {$route}" => [$route, $method];
            }
        }
    }

    #[DataProvider('anonymousWrites')]
    public function testASignedOutCallerMayNotWriteOutsideBccWith401(string $route, string $method): void
    {
        self::assertRefused(self::dispatch($route, $method), 401, "{$method} {$route}");
    }

    // ── Exemptions: exact method and route, signed out only ───────────────

    public function testNothingOutsideBccIsExemptToday(): void
    {
        self::assertSame(
            [],
            RestRouteAllowlist::EXEMPT_ROUTES,
            'nothing outside BCC is open to a signed-out caller; an entry needs a real caller and a test of its own'
        );
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function exemptSpellings(): iterable
    {
        yield 'as listed'                         => ['POST', '/litespeed/v1/notify_img'];
        yield 'method in lower case'              => ['post', '/litespeed/v1/notify_img'];
        yield 'route in any case (core is case-blind)' => ['POST', '/LiteSpeed/V1/Notify_Img'];
    }

    #[DataProvider('exemptSpellings')]
    public function testASignedOutCallerReachesAnExemptRoute(string $method, string $route): void
    {
        self::assertNull(RestRouteAllowlist::refusal($route, $method, false, self::SAMPLE_EXEMPTIONS), "{$method} {$route}");
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function nearlyExempt(): iterable
    {
        yield 'GET of a POST exemption'      => ['GET', '/litespeed/v1/notify_img'];
        yield 'HEAD of a POST exemption'     => ['HEAD', '/litespeed/v1/notify_img'];
        yield 'PUT of a POST exemption'      => ['PUT', '/litespeed/v1/notify_img'];
        yield 'OPTIONS of a POST exemption'  => ['OPTIONS', '/litespeed/v1/notify_img'];
        yield 'a route under it'             => ['POST', '/litespeed/v1/notify_img/x'];
        yield 'a longer name'                => ['POST', '/litespeed/v1/notify_imgx'];
        yield 'its namespace'                => ['POST', '/litespeed/v1'];
        yield 'a sibling'                    => ['POST', '/litespeed/v3/ping'];
        yield 'a trailing slash'             => ['POST', '/litespeed/v1/notify_img/'];
        yield 'a doubled leading slash'      => ['POST', '//litespeed/v1/notify_img'];
        yield 'no leading slash'             => ['POST', 'litespeed/v1/notify_img'];
        yield 'a doubled inner slash'        => ['POST', '/litespeed//v1/notify_img'];
    }

    #[DataProvider('nearlyExempt')]
    public function testOnlyTheExactMethodAndRouteAreExempt(string $method, string $route): void
    {
        self::assertRefused(RestRouteAllowlist::refusal($route, $method, false, self::SAMPLE_EXEMPTIONS), 401, "{$method} {$route}");
    }

    public function testAMemberGetsNoExemption(): void
    {
        self::assertRefused(
            RestRouteAllowlist::refusal('/litespeed/v1/notify_img', 'POST', true, self::SAMPLE_EXEMPTIONS),
            403,
            'a signed-in member, on an exempt route'
        );
    }

    public function testWithoutAnExemptionListEveryRouteIsRefused(): void
    {
        self::assertRefused(RestRouteAllowlist::refusal('/litespeed/v1/notify_img', 'POST', false), 401, 'the real (empty) list');
        self::assertRefused(RestRouteAllowlist::refusal('/litespeed/v1/notify_img', 'POST', false, []), 401, 'an empty list');
    }

    // ── Inside /batch/v1 ──────────────────────────────────────────────────

    /**
     * Core's /batch/v1 loop (class-wp-rest-server.php,
     * serve_batch_request_v1, 7.0.2), cut down to what touches this
     * filter's answer: rest_pre_dispatch; the handler when nothing
     * answered; rest_ensure_response(), which hands a WP_Error back as it
     * came; then the envelope, which reads get_status(). A bare WP_Error
     * there is a fatal, and the whole batch a 500.
     *
     * @param list<string> $routes
     */
    private static function asBatchWould(array $routes): \WP_REST_Response
    {
        $responses = [];
        foreach ($routes as $route) {
            $result = self::dispatch($route, 'POST') ?? new \WP_REST_Response(['handled' => $route], 200);
            $responses[] = ['body' => $result->get_data(), 'status' => $result->get_status()];
        }

        return new \WP_REST_Response(['responses' => $responses], 207);
    }

    /** @return iterable<string, array{0: int, 1: int}> */
    public static function batchCallers(): iterable
    {
        yield 'a member'   => [42, 403];
        yield 'signed out' => [0, 401];
    }

    #[DataProvider('batchCallers')]
    public function testARefusalInsideABatchKeepsItsOwnStatus(int $user, int $status): void
    {
        $GLOBALS['__cug']['user'] = $user;

        $batch = self::asBatchWould(['/wp/v2/posts', '/bcc/v1/posts']);

        self::assertSame(207, $batch->get_status());
        $responses = $batch->get_data()['responses'];
        self::assertSame([$status, 200], array_column($responses, 'status'), 'the refusal, then a BCC route');
        self::assertSame('rest_forbidden', $responses[0]['body']['code']);
    }

    // ── Admins: untouched ─────────────────────────────────────────────────

    /** @return iterable<string, array{0: string}> */
    public static function everyKindOfRoute(): iterable
    {
        yield from self::coreUsersRoutes();
        yield from self::nonBccRoutes();
        yield from self::bccRoutes();
        foreach (self::readsOutsideBcc() as $name => $args) {
            yield "read: {$name}" => $args;
        }
    }

    #[DataProvider('everyKindOfRoute')]
    public function testAnAdminPassesThroughUntouched(string $route): void
    {
        self::admin();
        $earlier = new \stdClass();

        foreach (self::ALL_METHODS as $method) {
            self::assertNull(self::dispatch($route, $method), "{$method} {$route}");
            self::assertSame($earlier, self::dispatch($route, $method, $earlier), 'whatever answered before stands');
        }
    }

    public function testANonRequestIsLeftAlone(): void
    {
        self::member();
        $earlier = new \stdClass();

        self::assertSame($earlier, RestRouteAllowlist::filter($earlier, null, 'not a request'));
    }

    // ── /batch/v1: a refused sub-request never fails the whole batch ──────

    /**
     * Runs a batch through the registered filters and the batch handler's
     * per-request tail. A sub-request nothing refuses gets $answer.
     *
     * @param list<array{0: string, 1: string}> $calls [method, route]
     * @return list<array{body: mixed, status: int, headers: array<string, mixed>}>
     */
    private static function batch(array $calls, ?\WP_REST_Response $answer = null): array
    {
        RestRouteAllowlist::register();
        $answer ??= new \WP_REST_Response(['ok' => true], 200);

        $res = bcc_test_serve_batch(
            array_map(static fn(array $c): \WP_REST_Request => new \WP_REST_Request($c[0], $c[1]), $calls),
            static fn(): \WP_REST_Response => $answer
        );

        self::assertSame(207, $res->get_status(), 'a batch answers 207, never 500');
        return $res->get_data()['responses'];
    }

    public function testAMembersBatchAnswers207WithEachRefusedSubRequestsOwn403(): void
    {
        self::member();

        $responses = self::batch([['PUT', '/wp/v2/users/me'], ['POST', '/peepso/v1/post'], ['POST', '/wp/v2/posts']]);

        self::assertCount(3, $responses);
        foreach ($responses as $i => $r) {
            self::assertSame(403, $r['status'], "sub-request {$i}");
            self::assertSame(self::refusalBody(403), $r['body'], "sub-request {$i}");
        }
    }

    public function testASignedOutBatchAnswers207WithEachRefusedSubRequestsOwn401(): void
    {
        $responses = self::batch([['POST', '/wp/v2/comments'], ['DELETE', '/wp/v2/users/7']]);

        self::assertSame([401, 401], array_column($responses, 'status'));
        self::assertSame(self::refusalBody(401), $responses[1]['body']);
    }

    public function testAnAdminsBatchIsDispatchedUntouched(): void
    {
        self::admin();

        $responses = self::batch([['PUT', '/wp/v2/users/me'], ['POST', '/wp/v2/posts']], new \WP_REST_Response(['id' => 7], 201));

        self::assertSame([201, 201], array_column($responses, 'status'));
        self::assertSame(['id' => 7], $responses[0]['body']);
    }

    public function testTheBatchDoubleFailsOnABareWpErrorAsCoreDoes(): void
    {
        // The control: a bare WP_Error from rest_pre_dispatch reaches
        // get_data() in the batch handler, which WP_Error does not have.
        // That fatal is the 500 this class avoids.
        $GLOBALS['__cug']['filters'][] = ['rest_pre_dispatch', static fn(): \WP_Error => new \WP_Error('rest_forbidden', 'no', ['status' => 403]), 1, 1];

        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/WP_Error::get_data\(\)/');
        bcc_test_serve_batch([new \WP_REST_Request('PUT', '/wp/v2/users/me')], static fn(): \WP_REST_Response => new \WP_REST_Response(null, 200));
    }

    // ── Application passwords: admins only ────────────────────────────────

    public function testApplicationPasswordsAreForAdminsOnly(): void
    {
        $GLOBALS['__cug']['user_caps'] = [1 => ['manage_options'], 42 => ['read']];

        self::assertTrue(RestRouteAllowlist::applicationPasswordsAvailable(true, new \WP_User(1)), 'admin');
        self::assertFalse(RestRouteAllowlist::applicationPasswordsAvailable(true, new \WP_User(42)), 'member');
        self::assertFalse(RestRouteAllowlist::applicationPasswordsAvailable(false, new \WP_User(1)), 'switched off elsewhere stays off');
        self::assertFalse(RestRouteAllowlist::applicationPasswordsAvailable(true, null), 'no user');
    }

    // ── Wiring ────────────────────────────────────────────────────────────

    public function testItHooksRestPreDispatchFirstAndTheApplicationPasswordsFilter(): void
    {
        RestRouteAllowlist::register();

        self::assertSame(
            [
                ['rest_pre_dispatch', [RestRouteAllowlist::class, 'filter'], 1, 3],
                ['wp_is_application_passwords_available_for_user', [RestRouteAllowlist::class, 'applicationPasswordsAvailable'], 10, 2],
            ],
            $GLOBALS['__cug']['filters']
        );
    }

    public function testItIsRegisteredAtBoot(): void
    {
        $boot = (string) file_get_contents(__DIR__ . '/../../bcc-trust.php');

        self::assertStringContainsString("\\BCC\\Trust\\Core\\Security\\RestRouteAllowlist::register();", $boot);
    }
}
