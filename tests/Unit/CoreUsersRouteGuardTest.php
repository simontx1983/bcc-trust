<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Security\CoreUsersRouteGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Security review (2026-11-18): core's /wp/v2/users routes let a member's
 * bearer token change the account's password or email around every BCC
 * step-up, and mint application passwords. CoreUsersRouteGuard refuses
 * them, and everything under them, to anyone without manage_options: 401
 * signed out, 403 signed in, all methods, however the route is spelled
 * (core matches routes case-insensitively, and ?rest_route= takes the path
 * as typed). Admins pass through untouched.
 *
 * Runs the real filter over minimal WordPress doubles defined here.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CoreUsersRouteGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__cug'] = ['user' => 0, 'caps' => [], 'filters' => []];
        if (!class_exists('WP_REST_Request', false)) {
            eval('class WP_REST_Request { public function __construct(private string $method = "GET", private string $route = "") {} public function get_route(): string { return $this->route; } public function get_method(): string { return $this->method; } }');
        }
        if (!class_exists('WP_Error', false)) {
            eval('class WP_Error { public function __construct(public string $code = "", public string $message = "", public mixed $data = null) {} public function get_error_code(): string { return $this->code; } public function get_error_data(): mixed { return $this->data; } }');
        }
        if (!function_exists('current_user_can')) {
            eval('function current_user_can(string $cap): bool { return in_array($cap, $GLOBALS["__cug"]["caps"], true); }');
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
        return CoreUsersRouteGuard::filter($earlier, null, new \WP_REST_Request($method, $route));
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

    /** @return iterable<string, array{0: string}> */
    public static function guardedRoutes(): iterable
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

    #[DataProvider('guardedRoutes')]
    public function testAMemberIsRefusedWith403(string $route): void
    {
        self::member();

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $res = self::dispatch($route, $method);
            self::assertInstanceOf(\WP_Error::class, $res, "{$method} {$route}");
            self::assertSame('rest_forbidden', $res->get_error_code());
            self::assertSame(['status' => 403], $res->get_error_data());
        }
    }

    #[DataProvider('guardedRoutes')]
    public function testASignedOutCallerIsRefusedWith401(string $route): void
    {
        $res = self::dispatch($route);

        self::assertInstanceOf(\WP_Error::class, $res);
        self::assertSame(['status' => 401], $res->get_error_data());
    }

    #[DataProvider('guardedRoutes')]
    public function testAnAdminPassesThroughUntouched(string $route): void
    {
        self::admin();

        self::assertNull(self::dispatch($route, 'PUT'));
        $earlier = new \stdClass();
        self::assertSame($earlier, self::dispatch($route, 'PUT', $earlier), 'whatever answered before stands');
    }

    /** @return iterable<string, array{0: string}> */
    public static function otherRoutes(): iterable
    {
        yield 'a longer name'      => ['/wp/v2/usersx'];
        yield 'a shorter name'     => ['/wp/v2/user'];
        yield 'core posts'         => ['/wp/v2/posts'];
        yield 'BCC me'             => ['/bcc-trust/v1/me/account/password'];
        yield 'BCC users'          => ['/bcc-trust/v1/users/me'];
        yield 'another namespace'  => ['/wp/v3/users'];
        yield 'the index'          => ['/'];
    }

    #[DataProvider('otherRoutes')]
    public function testOtherRoutesAreNotTouched(string $route): void
    {
        self::member();

        self::assertNull(self::dispatch($route, 'PUT'));
    }

    public function testARefusalWinsOverAnEarlierAnswer(): void
    {
        self::member();

        // Something at an earlier priority answered: no non-admin request
        // may reach these handlers, so the refusal still wins.
        $res = self::dispatch('/wp/v2/users/me', 'PUT', new \stdClass());

        self::assertInstanceOf(\WP_Error::class, $res);
    }

    public function testItHooksRestPreDispatchFirst(): void
    {
        CoreUsersRouteGuard::register();

        self::assertSame(
            [['rest_pre_dispatch', [CoreUsersRouteGuard::class, 'filter'], 1, 3]],
            $GLOBALS['__cug']['filters']
        );
    }

    public function testItIsRegisteredAtBoot(): void
    {
        $boot = (string) file_get_contents(__DIR__ . '/../../bcc-trust.php');

        self::assertStringContainsString("\\BCC\\Trust\\Core\\Security\\CoreUsersRouteGuard::register();", $boot);
    }
}
