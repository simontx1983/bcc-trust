<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Security\WordPressSignInGate;
use BCC\Trust\Core\Support\BearerAuth;
use BCC\Trust\Core\Support\JwtToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Security review 2026-11-18, H2: wp-login.php signed members in with the
 * password alone, and the resulting WordPress cookie (plus a `wp_rest`
 * nonce) then authenticated every bcc route, skipping BCC's email-code 2FA,
 * its sign-in throttles and the captcha step-up.
 *
 * Pinned against the REAL WordPressSignInGate, and for the REST door the
 * REAL BearerAuth::authenticateBearer + JwtToken:
 *   - `authenticate`: a member account is refused with one answer whether
 *     the password was right or wrong (by login or by email), including the
 *     cookie re-login; admins, unknown names and an empty form are untouched;
 *   - `admin_init`: a signed-in member is redirected from wp-admin to the
 *     app and refused (403) at admin-ajax.php; admins and signed-out
 *     visitors are untouched;
 *   - `rest_pre_dispatch`: on every bcc namespace, however spelled, a member
 *     must be the user the bearer token authenticated; a cookie session (even
 *     with a valid bearer alongside) is 401 `bcc_unauthorized`; admins and
 *     anonymous callers pass; other namespaces are left to their own guards.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WordPressSignInGateTest extends TestCase
{
    private const MEMBER = 90;
    private const ADMIN  = 1;

    private const SIGN_IN_AT_APP = 'Members sign in at <a href="https://app.example.test/login">the app</a>.';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/envelope-meta-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        require_once __DIR__ . '/../Stubs/wp-signin-gate-stubs.php';
        $GLOBALS['__bcc_envelope_meta_throttle'] = ['allow' => true];
        $GLOBALS['__bcc_ph'] = [
            'users'      => [
                self::MEMBER => ['email' => 'alice@example.com', 'login' => 'alice', 'display' => 'Alice'],
                self::ADMIN  => ['email' => 'boss@example.com', 'login' => 'boss', 'display' => 'Boss'],
            ],
            'meta'       => [self::MEMBER => ['bcc_handle' => 'alice'], self::ADMIN => ['bcc_handle' => 'boss']],
            'transients' => [],
            'mail'       => [],
            'actions'    => [],
            'options'    => [],
        ];
        $GLOBALS['__bcc_sig'] = ['admins' => [self::ADMIN], 'current' => 0, 'ajax' => false, 'hooks' => []];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testItHooksAllThreeDoors(): void
    {
        WordPressSignInGate::register();

        $hooks = [];
        foreach ($GLOBALS['__bcc_sig']['hooks'] as [$hook, $callback, $priority, $args]) {
            $hooks[$hook] = [$callback, $priority, $args];
        }
        self::assertSame([[WordPressSignInGate::class, 'refuseMemberSignIn'], PHP_INT_MAX, 3], $hooks['authenticate']);
        self::assertSame([[WordPressSignInGate::class, 'keepMembersOutOfAdmin'], 1, 1], $hooks['admin_init']);
        self::assertSame([[WordPressSignInGate::class, 'filterRest'], 1, 3], $hooks['rest_pre_dispatch']);
    }

    // ── 1. authenticate ─────────────────────────────────────────────────

    private static function account(int $id): \WP_User
    {
        $user = get_user_by('id', $id);
        self::assertInstanceOf(\WP_User::class, $user);
        return $user;
    }

    /** @param mixed $answer */
    private static function assertRefused($answer): void
    {
        self::assertInstanceOf(\WP_Error::class, $answer);
        self::assertSame(WordPressSignInGate::ERROR_CODE, $answer->get_error_code());
        self::assertSame(self::SIGN_IN_AT_APP, $answer->get_error_message());
    }

    public function testAMemberWithTheRightPasswordIsRefused(): void
    {
        self::assertRefused(WordPressSignInGate::refuseMemberSignIn(self::account(self::MEMBER), 'alice', 'right'));
    }

    /** @return array<string, array{string}> */
    public static function memberNames(): array
    {
        return [
            'by login' => ['alice'],
            'by email' => ['alice@example.com'],
        ];
    }

    /** No password oracle: a wrong password gets the very same answer. */
    #[DataProvider('memberNames')]
    public function testAMemberWithAWrongPasswordGetsTheSameAnswer(string $name): void
    {
        $wrong = new \WP_Error('incorrect_password', 'The password you entered is incorrect.');

        self::assertRefused(WordPressSignInGate::refuseMemberSignIn($wrong, $name, 'wrong'));
    }

    /** wp_authenticate_cookie (priority 30) signs in from a cookie with an empty form. */
    public function testTheCookieReLoginOfAMemberIsRefused(): void
    {
        self::assertRefused(WordPressSignInGate::refuseMemberSignIn(self::account(self::MEMBER), '', ''));
    }

    public function testAnAdminSignsInAsBefore(): void
    {
        $admin = self::account(self::ADMIN);
        self::assertSame($admin, WordPressSignInGate::refuseMemberSignIn($admin, 'boss', 'right'));

        $wrong = new \WP_Error('incorrect_password', 'The password you entered is incorrect.');
        self::assertSame($wrong, WordPressSignInGate::refuseMemberSignIn($wrong, 'boss', 'wrong'));
        self::assertSame($wrong, WordPressSignInGate::refuseMemberSignIn($wrong, 'boss@example.com', 'wrong'));
    }

    public function testAnUnknownNameOrAnEmptyFormIsUntouched(): void
    {
        $unknown = new \WP_Error('invalid_username', 'Unknown username.');
        self::assertSame($unknown, WordPressSignInGate::refuseMemberSignIn($unknown, 'nobody', 'pw'));
        self::assertSame($unknown, WordPressSignInGate::refuseMemberSignIn($unknown, 'nobody@example.com', 'pw'));
        self::assertNull(WordPressSignInGate::refuseMemberSignIn(null, '', ''));
        self::assertNull(WordPressSignInGate::refuseMemberSignIn(null, '   ', ''));
    }

    // ── 2. admin_init ───────────────────────────────────────────────────

    /** @return string|null what stopped the request ('redirect', 'wp_die'), or null */
    private static function runAdminInit(): ?string
    {
        try {
            WordPressSignInGate::keepMembersOutOfAdmin();
            return null;
        } catch (\SignInGateHalt $halt) {
            return $halt->getMessage();
        }
    }

    public function testASignedInMemberIsSentFromWpAdminToTheApp(): void
    {
        $GLOBALS['__bcc_sig']['current'] = self::MEMBER;

        self::assertSame('redirect', self::runAdminInit());
        self::assertSame(['https://app.example.test/', 302], $GLOBALS['__bcc_sig']['redirect']);
    }

    public function testASignedInMemberIsRefusedAtAdminAjax(): void
    {
        $GLOBALS['__bcc_sig']['current'] = self::MEMBER;
        $GLOBALS['__bcc_sig']['ajax']    = true;

        self::assertSame('wp_die', self::runAdminInit());
        self::assertSame(['-1', 403], $GLOBALS['__bcc_sig']['die']);
        self::assertArrayNotHasKey('redirect', $GLOBALS['__bcc_sig']);
    }

    /** @return array<string, array{int, bool}> */
    public static function untouchedAdminVisits(): array
    {
        return [
            'admin, wp-admin'      => [self::ADMIN, false],
            'admin, admin-ajax'    => [self::ADMIN, true],
            'signed out, wp-admin' => [0, false],
            'signed out, ajax'     => [0, true],
        ];
    }

    #[DataProvider('untouchedAdminVisits')]
    public function testAdminsAndSignedOutVisitorsAreUntouched(int $user, bool $ajax): void
    {
        $GLOBALS['__bcc_sig']['current'] = $user;
        $GLOBALS['__bcc_sig']['ajax']    = $ajax;

        self::assertNull(self::runAdminInit());
        self::assertArrayNotHasKey('redirect', $GLOBALS['__bcc_sig']);
        self::assertArrayNotHasKey('die', $GLOBALS['__bcc_sig']);
    }

    // ── 3. rest_pre_dispatch ────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function bccRoutes(): array
    {
        return [
            'bcc/v1'           => ['/bcc/v1/me/account'],
            'bcc-trust/v1'     => ['/bcc-trust/v1/votes'],
            'namespace index'  => ['/bcc/v1'],
            'capitals'         => ['/BCC/v1/me/account'],
            'doubled slash'    => ['//bcc/v1/me/account'],
            'mixed'            => ['//BCC-Trust//v1/votes'],
            'no leading slash' => ['bcc/v1/me/account'],
        ];
    }

    /** @param mixed $response */
    private static function assertUnauthorized($response): void
    {
        self::assertInstanceOf(\WP_REST_Response::class, $response);
        self::assertSame(401, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame(
            ['code' => 'bcc_unauthorized', 'message' => 'Sign in at the app.', 'status' => 401],
            $data['error']
        );
    }

    #[DataProvider('bccRoutes')]
    public function testAMemberTheBearerDidNotSignInIsRefused(string $route): void
    {
        self::assertUnauthorized(WordPressSignInGate::decideRest($route, self::MEMBER, 0));
    }

    #[DataProvider('bccRoutes')]
    public function testAMemberTheBearerSignedInPasses(string $route): void
    {
        self::assertNull(WordPressSignInGate::decideRest($route, self::MEMBER, self::MEMBER));
    }

    public function testABearerForSomeoneElseDoesNotCoverTheSessionUser(): void
    {
        self::assertUnauthorized(WordPressSignInGate::decideRest('/bcc/v1/me/account', self::MEMBER, 55));
    }

    #[DataProvider('bccRoutes')]
    public function testAnAdminAndAnAnonymousCallerPass(string $route): void
    {
        self::assertNull(WordPressSignInGate::decideRest($route, self::ADMIN, 0));
        self::assertNull(WordPressSignInGate::decideRest($route, 0, 0));
    }

    /**
     * Not this gate's namespaces (CoreUsersRouteGuard and the REST allowlist
     * own those). `/batch/v1` itself passes: each request inside a batch goes
     * through rest_pre_dispatch with its own route.
     */
    public function testOtherNamespacesAreLeftToTheirOwnGuards(): void
    {
        foreach (['/wp/v2/posts', '/peepso/v1/activity', '/batch/v1', '/bccx/v1/y', '/oembed/1.0/embed', '/'] as $route) {
            self::assertNull(WordPressSignInGate::decideRest($route, self::MEMBER, 0), $route);
        }
    }

    /** Authenticate as WordPress would: cookie / app-password filters first, then BearerAuth. */
    private static function asWordPressWould(?string $token, int|false $earlierLogin = false): void
    {
        if ($token === null) {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        } else {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $GLOBALS['__bcc_sig']['current'] = (int) BearerAuth::authenticateBearer($earlierLogin);
    }

    /** @return mixed */
    private static function dispatch(string $route, string $method = 'GET', mixed $earlier = null)
    {
        return WordPressSignInGate::filterRest($earlier, new \WP_REST_Server(), new \WP_REST_Request($route, $method));
    }

    public function testAMemberSignedInWithTheirBearerReachesTheRoute(): void
    {
        self::asWordPressWould(JwtToken::encode(self::MEMBER, 'alice'));

        self::assertSame(self::MEMBER, BearerAuth::authenticatedUserId());
        self::assertNull(self::dispatch('/bcc/v1/me/account', 'POST'));
    }

    /** The cookie decided first, so BearerAuth deferred: the bearer alongside it does not help. */
    public function testAMemberCookieSessionIsRefusedEvenWithAValidBearer(): void
    {
        self::asWordPressWould(JwtToken::encode(self::MEMBER, 'alice'), self::MEMBER);

        self::assertSame(self::MEMBER, (int) $GLOBALS['__bcc_sig']['current']);
        self::assertSame(0, BearerAuth::authenticatedUserId());
        self::assertUnauthorized(self::dispatch('/bcc/v1/me/account', 'POST'));
    }

    public function testAnAdminCookieSessionStillWorks(): void
    {
        self::asWordPressWould(null, self::ADMIN);

        self::assertNull(self::dispatch('/bcc/v1/onchain/chains', 'POST'));
    }

    public function testEachAuthenticationStandsAlone(): void
    {
        $token = JwtToken::encode(self::MEMBER, 'alice');

        self::asWordPressWould($token);
        self::assertSame(self::MEMBER, BearerAuth::authenticatedUserId());
        self::asWordPressWould('not-a-jwt');
        self::assertSame(0, BearerAuth::authenticatedUserId());

        self::asWordPressWould($token);
        self::asWordPressWould($token, self::MEMBER);
        self::assertSame(0, BearerAuth::authenticatedUserId());

        self::asWordPressWould($token);
        self::asWordPressWould(null);
        self::assertSame(0, BearerAuth::authenticatedUserId());
    }

    public function testARefusalWinsOverAnEarlierAnswer(): void
    {
        $earlier = new \WP_REST_Response(['data' => 'cached'], 200);

        $GLOBALS['__bcc_sig']['current'] = self::MEMBER;
        self::assertUnauthorized(self::dispatch('/bcc/v1/feed', 'GET', $earlier));

        $GLOBALS['__bcc_sig']['current'] = self::ADMIN;
        self::assertSame($earlier, self::dispatch('/bcc/v1/feed', 'GET', $earlier));
    }

    public function testSomethingThatIsNotARequestIsIgnored(): void
    {
        $GLOBALS['__bcc_sig']['current'] = self::MEMBER;

        self::assertSame('earlier', WordPressSignInGate::filterRest('earlier', null, 'not a request'));
    }
}
