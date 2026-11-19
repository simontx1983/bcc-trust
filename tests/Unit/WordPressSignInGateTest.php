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
 *   - `authenticate`: a member account is refused, including the cookie
 *     re-login, with the one answer every failed sign-in gets too (a wrong
 *     password, an unknown name or email, admin or member alike), so the
 *     form tests neither passwords nor which logins are admins; an admin's
 *     right password, an empty form and other plugins' answers (Wordfence's
 *     2FA prompt) are untouched;
 *   - `admin_init`: a signed-in member is redirected from wp-admin to the
 *     app (with wp_redirect: wp_safe_redirect would swap the frontend for
 *     admin_url() at admin_init and loop) and refused (403) at
 *     admin-ajax.php; admins and signed-out visitors are untouched;
 *   - `allow_password_reset`: no reset key for anyone, admins included,
 *     except through appResetKey() (the app's forgot-password flow; its
 *     end-to-end test is PasswordResetAtTheAppTest);
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

    private const SIGN_IN_AT_APP = 'That sign-in didn\'t work. Members sign in at <a href="https://app.example.test/login">the app</a>.';

    private const RESET_AT_APP = 'Reset your password at <a href="https://app.example.test/forgot-password">the app</a>.';

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

    public function testItHooksAllFourDoors(): void
    {
        WordPressSignInGate::register();

        $hooks = [];
        foreach ($GLOBALS['__bcc_sig']['hooks'] as [$hook, $callback, $priority, $args]) {
            $hooks[$hook] = [$callback, $priority, $args];
        }
        self::assertSame([[WordPressSignInGate::class, 'refuseMemberSignIn'], PHP_INT_MAX, 3], $hooks['authenticate']);
        self::assertSame([[WordPressSignInGate::class, 'keepMembersOutOfAdmin'], 1, 1], $hooks['admin_init']);
        self::assertSame([[WordPressSignInGate::class, 'filterRest'], 1, 3], $hooks['rest_pre_dispatch']);
        self::assertSame([[WordPressSignInGate::class, 'allowPasswordReset'], PHP_INT_MAX, 2], $hooks['allow_password_reset']);
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

    public function testAnAdminWithTheRightPasswordSignsInAsBefore(): void
    {
        $admin = self::account(self::ADMIN);
        self::assertSame($admin, WordPressSignInGate::refuseMemberSignIn($admin, 'boss', 'right'));
    }

    /** @return array<string, array{string, string}> */
    public static function failedSignIns(): array
    {
        return [
            'an admin, wrong password'          => ['incorrect_password', 'boss'],
            'an admin by email, wrong password' => ['incorrect_password', 'boss@example.com'],
            'a member, wrong password'          => ['incorrect_password', 'alice'],
            'an unknown login'                  => ['invalid_username', 'nobody'],
            'an unknown email'                  => ['invalid_email', 'nobody@example.com'],
        ];
    }

    /**
     * No role oracle: core named the cause ("the password you entered for
     * the username boss is incorrect"), and beside a member's refusal that
     * told an admin login from a member one. Every failed sign-in now reads
     * like a member's.
     */
    #[DataProvider('failedSignIns')]
    public function testEveryFailedSignInGetsTheSameAnswerWhateverTheRole(string $code, string $name): void
    {
        $failed = new \WP_Error($code, "Core's own message, naming {$name}.");

        self::assertRefused(WordPressSignInGate::refuseMemberSignIn($failed, $name, 'wrong'));
    }

    /** @return array<string, array{?string, string}> an earlier WP_Error's code (null: no answer yet), the name typed */
    public static function answersLeftAlone(): array
    {
        return [
            'nothing yet'                      => [null, ''],
            'an empty form'                    => [null, '   '],
            "an admin's empty password"        => ['empty_password', 'boss'],
            "a member's empty password"        => ['empty_password', 'alice'],
            'an empty username'                => ['empty_username', ''],
            "Wordfence's 2FA prompt, an admin" => ['wfls_twofactor_required', 'boss'],
            "Wordfence's 2FA prompt, a member" => ['wfls_twofactor_required', 'alice'],
            'a lockout'                        => ['wfls_lockout', 'alice'],
        ];
    }

    /**
     * Not a sign-in, and the same for every role, so passed through: an
     * empty field reads the same for an admin and a member, and an admin
     * with the right password still gets Wordfence's 2FA prompt.
     */
    #[DataProvider('answersLeftAlone')]
    public function testEveryOtherAnswerIsUntouched(?string $code, string $name): void
    {
        $answer = $code === null ? null : new \WP_Error($code, 'Its own message.');

        self::assertSame($answer, WordPressSignInGate::refuseMemberSignIn($answer, $name, ''));
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

    /**
     * At admin_init the frontend's host is not yet an allowed redirect host
     * (FrontendRedirect adds it on rest_api_init), and here it is not the
     * home host either (cms. vs app., as on staging and local). So
     * wp_safe_redirect would land on admin_url(), back in this hook,
     * forever.
     */
    public function testASignedInMemberIsSentFromWpAdminToTheApp(): void
    {
        $GLOBALS['__bcc_sig']['current'] = self::MEMBER;
        $GLOBALS['__bcc_sig']['allowed_redirect_hosts'] = [];

        self::assertSame('redirect', self::runAdminInit());
        self::assertSame(['https://app.example.test/', 302], $GLOBALS['__bcc_sig']['redirect']);
    }

    /** The double above catches the loop only if it checks hosts like core. */
    public function testTheRedirectDoubleChecksHostsLikeCore(): void
    {
        $where = static function (string $location): string {
            try {
                wp_safe_redirect($location);
            } catch (\SignInGateHalt) {
            }
            return $GLOBALS['__bcc_sig']['redirect'][0];
        };

        self::assertSame('https://cms.example.test/wp-admin/', $where('https://app.example.test/'), 'off-site: admin_url()');
        self::assertSame('https://cms.example.test/x', $where('https://cms.example.test/x'), 'the home host stands');
        $GLOBALS['__bcc_sig']['allowed_redirect_hosts'] = ['app.example.test'];
        self::assertSame('https://app.example.test/', $where('https://app.example.test/'), 'an allowed host stands');
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
            $result = WordPressSignInGate::filterRest(null, new \WP_REST_Server(), new \WP_REST_Request($route, 'POST'));
            $result ??= new \WP_REST_Response(['handled' => $route], 200);
            $responses[] = ['body' => $result->get_data(), 'status' => $result->get_status()];
        }

        return new \WP_REST_Response(['responses' => $responses], 207);
    }

    public function testARefusalInsideABatchKeepsItsOwnStatus(): void
    {
        $GLOBALS['__bcc_sig']['current'] = self::MEMBER;

        $batch = self::asBatchWould(['/bcc/v1/feed', '/wp/v2/posts']);

        self::assertSame(207, $batch->get_status());
        $data = $batch->get_data();
        self::assertIsArray($data);
        self::assertSame([401, 200], array_column($data['responses'], 'status'), 'the refusal, then a route this gate leaves alone');
        self::assertSame('bcc_unauthorized', $data['responses'][0]['body']['error']['code']);
    }

    // ── 4. allow_password_reset ─────────────────────────────────────────

    /** @param mixed $answer */
    private static function assertResetRefused($answer, string $what): void
    {
        self::assertInstanceOf(\WP_Error::class, $answer, $what);
        self::assertSame(WordPressSignInGate::RESET_ERROR_CODE, $answer->get_error_code(), $what);
        self::assertSame(self::RESET_AT_APP, $answer->get_error_message(), $what);
    }

    /** @return array<string, array{int}> */
    public static function everyRole(): array
    {
        return ['a member' => [self::MEMBER], 'an admin' => [self::ADMIN]];
    }

    /**
     * wp-login.php's lost-password form, PeepSo's recover-password page, a
     * wp-admin "send password reset": all mint the key with
     * get_password_reset_key(). For every role: a form that emailed admins
     * and turned members away would tell which logins are admins.
     */
    #[DataProvider('everyRole')]
    public function testNoOneElseGetsAResetKey(int $id): void
    {
        WordPressSignInGate::register();

        self::assertResetRefused(get_password_reset_key(self::account($id)), 'a bare get_password_reset_key()');
        self::assertResetRefused(WordPressSignInGate::allowPasswordReset(true, $id), 'the filter itself');
        self::assertArrayNotHasKey('reset_keys', $GLOBALS['__bcc_sig']);
    }

    #[DataProvider('everyRole')]
    public function testTheAppsFlowGetsAResetKeyForEveryRole(int $id): void
    {
        WordPressSignInGate::register();

        self::assertSame('reset-key-for-' . $id, WordPressSignInGate::appResetKey(self::account($id)));
        self::assertSame([$id], $GLOBALS['__bcc_sig']['reset_keys']);
    }

    public function testTheAppsFlowLeavesNothingOpenBehindIt(): void
    {
        WordPressSignInGate::register();
        $member = self::account(self::MEMBER);

        WordPressSignInGate::appResetKey($member);
        self::assertResetRefused(get_password_reset_key($member), 'after the app minted its key');

        $GLOBALS['__bcc_sig']['reset_throws'] = true;
        try {
            WordPressSignInGate::appResetKey($member);
            self::fail('the double throws');
        } catch (\RuntimeException) {
        }
        $GLOBALS['__bcc_sig']['reset_throws'] = false;
        self::assertResetRefused(get_password_reset_key($member), 'after a throw inside the app flow');
    }

    /** PeepSo hooks its own allow_password_reset checks; inside the app's flow they still decide. */
    public function testInsideTheAppsFlowAnEarlierRefusalStands(): void
    {
        add_filter('allow_password_reset', static fn (): bool => false, 20, 2);
        WordPressSignInGate::register();

        $key = WordPressSignInGate::appResetKey(self::account(self::MEMBER));

        self::assertInstanceOf(\WP_Error::class, $key);
        self::assertSame('no_password_reset', $key->get_error_code());
        self::assertArrayNotHasKey('reset_keys', $GLOBALS['__bcc_sig']);
    }
}
