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
        require_once __DIR__ . '/../Stubs/rest-request-stubs.php';
        $GLOBALS['__bcc_envelope_meta_throttle'] = ['allow' => true];
        // The bearer counts on a REST request only (BearerAuthRestOnlyTest).
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/wp-json/bcc/v1/me';
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
        self::assertSame([[WordPressSignInGate::class, 'refuseWordPressAjax'], PHP_INT_MIN, 1], $hooks['init']);
        self::assertSame([[WordPressSignInGate::class, 'keepMembersOutOfAdmin'], 1, 1], $hooks['admin_init']);
        self::assertSame([[WordPressSignInGate::class, 'filterRest'], 1, 3], $hooks['rest_pre_dispatch']);
        self::assertSame([[WordPressSignInGate::class, 'allowPasswordReset'], PHP_INT_MAX, 2], $hooks['allow_password_reset']);
        self::assertSame([[WordPressSignInGate::class, 'sameLostPasswordAnswer'], PHP_INT_MAX, 2], $hooks['lostpassword_errors']);
        self::assertSame([[WordPressSignInGate::class, 'keepPathWordsOffGroups'], 10, 6], $hooks['wp_unique_post_slug']);
        self::assertSame([[WordPressSignInGate::class, 'noXmlRpc'], PHP_INT_MAX, 1], $hooks['xmlrpc_enabled']);
        self::assertSame([[WordPressSignInGate::class, 'noXmlRpcMethods'], PHP_INT_MAX, 1], $hooks['xmlrpc_methods']);
        self::assertSame([[WordPressSignInGate::class, 'noPingbackHeader'], PHP_INT_MAX, 1], $hooks['wp_headers']);
        self::assertSame([[WordPressSignInGate::class, 'closedToCoreComments'], PHP_INT_MAX, 1], $hooks['comments_open']);
        self::assertSame([[WordPressSignInGate::class, 'closedToCoreComments'], PHP_INT_MAX, 1], $hooks['pings_open']);
        self::assertSame([[WordPressSignInGate::class, 'noNativeSignUp'], PHP_INT_MAX, 1], $hooks['pre_option_users_can_register']);
        self::assertSame([[WordPressSignInGate::class, 'peepsoSignUpDisabled'], PHP_INT_MAX, 1], $hooks['peepso_filter_get_option_site_registration_disabled']);
        self::assertSame([[WordPressSignInGate::class, 'peepsoDataExportOff'], PHP_INT_MAX, 1], $hooks['peepso_filter_get_option_gdpr_enable']);
    }

    /**
     * Runs one filter's recorded callbacks, by priority, after core's value
     * and an earlier plugin's, as apply_filters() would.
     *
     * @param list<array{0: callable, 1: int}> $before
     * @return mixed
     */
    private static function filtered(string $hook, mixed $value, array $before)
    {
        $filters = $before;
        foreach ($GLOBALS['__bcc_sig']['hooks'] as [$h, $callback, $priority]) {
            if ($h === $hook) {
                $filters[] = [$callback, $priority];
            }
        }
        usort($filters, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
        foreach ($filters as [$callback]) {
            $value = $callback($value);
        }

        return $value;
    }

    /** XML-RPC stays off for everyone, whatever an earlier filter answered. */
    public function testXmlRpcIsOffWhateverCameBefore(): void
    {
        WordPressSignInGate::register();

        self::assertFalse(self::filtered('xmlrpc_enabled', true, [[static fn($on): bool => true, 99]]));
    }

    /** No XML-RPC method answers, even after a later filter adds one back. */
    public function testNoXmlRpcMethodAnswersWhateverCameBefore(): void
    {
        WordPressSignInGate::register();

        $core = ['core.methodOne' => 'this:methodOne', 'core.methodTwo' => 'this:methodTwo'];
        self::assertSame([], self::filtered('xmlrpc_methods', $core, [[static fn($m): array => $m + ['demo.sayHello' => 'this:sayHello'], 99]]));
    }

    /** @return iterable<string, array{0: string}> */
    public static function commentAndPingHooks(): iterable
    {
        yield 'comments' => ['comments_open'];
        yield 'pings'    => ['pings_open'];
    }

    /**
     * Core comments and pings are closed on every post: whatever core read
     * from the post (open), and even after a later plugin opened them again,
     * the answer core's comment handlers and trackbacks get is false.
     */
    #[DataProvider('commentAndPingHooks')]
    public function testCoreCommentsAndPingsAreClosedWhateverCameBefore(string $hook): void
    {
        WordPressSignInGate::register();

        self::assertFalse(self::filtered($hook, true, [[static fn($open): bool => true, 99]]));
        self::assertFalse(self::filtered($hook, '1', [[static fn($open): string => 'open', 10]]));
        self::assertFalse(self::filtered($hook, false, []), 'a closed post stays closed');
    }

    /** X-Pingback is dropped; every other header passes through unchanged. */
    public function testThePingbackHeaderIsDroppedAndNothingElse(): void
    {
        $headers = ['Content-Type' => 'text/html; charset=UTF-8', 'X-Pingback' => 'https://cms.example.test/xmlrpc.php', 'Link' => '<x>; rel=y'];

        self::assertSame(['Content-Type' => 'text/html; charset=UTF-8', 'Link' => '<x>; rel=y'], WordPressSignInGate::noPingbackHeader($headers));
        self::assertSame(['Content-Type' => 'text/html'], WordPressSignInGate::noPingbackHeader(['Content-Type' => 'text/html']));
        self::assertSame('not headers', WordPressSignInGate::noPingbackHeader('not headers'), 'anything else passes through');
    }

    /** WordPress's own sign-up reads '0', even after a filter turned it on. */
    public function testNativeSignUpIsOffWhateverCameBefore(): void
    {
        WordPressSignInGate::register();

        self::assertSame('0', self::filtered('pre_option_users_can_register', false, [[static fn($v): string => '1', 99]]));
    }

    /** PeepSo's own sign-up reads disabled, even after a filter enabled it. */
    public function testPeepSoSignUpIsOffWhateverCameBefore(): void
    {
        WordPressSignInGate::register();

        self::assertSame(1, self::filtered('peepso_filter_get_option_site_registration_disabled', 0, [[static fn($v): int => 0, 99]]));
    }

    /**
     * PeepSo's built-in data export reads off (F-581), even when the stored
     * setting is on and filters at every lower priority turn it back on.
     */
    public function testPeepSoDataExportIsOffWhateverCameBefore(): void
    {
        WordPressSignInGate::register();

        self::assertSame(0, self::filtered('peepso_filter_get_option_gdpr_enable', 1, []), 'the stored setting alone');
        self::assertSame(0, self::filtered('peepso_filter_get_option_gdpr_enable', '1', [
            [static fn($v): int => 1, 10],
            [static fn($v): string => '1', 99],
            [static fn($v): bool => true, PHP_INT_MAX - 1],
        ]), 'filters that turn it back on, at every lower priority');
    }

    /**
     * The gate registers at boot: bcc-trust.php calls register() at file
     * scope, outside every function, closure and class, so its filters are
     * in place when the plugin file loads, before `plugins_loaded` and
     * `init` (F-581: PeepSo reads its data-export setting during `init`).
     */
    public function testTheGateRegistersAtBootBeforeInit(): void
    {
        $tokens = token_get_all((string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php'));

        $stack   = [];   // one entry per open brace: 'scope' (function, closure, class) or 'block'
        $pending = false;
        $found   = [];
        $count   = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t    = $tokens[$i];
            $id   = is_array($t) ? $t[0] : null;
            $text = is_array($t) ? $t[1] : $t;

            if (in_array($id, [T_FUNCTION, T_FN, T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true)) {
                $pending = true;
            } elseif ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $stack[] = ($pending && $text === '{') ? 'scope' : 'block';
                $pending = false;
            } elseif ($text === '}') {
                array_pop($stack);
            } elseif ($text === ';') {
                $pending = false;   // an abstract method or `fn` arrow ends here
            }

            // PHP 8 reads the fully qualified class name as one token.
            if ($id === T_NAME_FULLY_QUALIFIED && $text === '\\BCC\\Trust\\Core\\Security\\WordPressSignInGate') {
                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if (($tokens[$j][1] ?? $tokens[$j]) === '::' && ($tokens[$j + 1][1] ?? null) === 'register') {
                    $found[] = !in_array('scope', $stack, true);
                }
            }
        }
        self::assertSame([true], $found, 'exactly one register() call, at file scope');
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

    /** The same decision as `init` (refuseWordPressAjax), asked again here. */
    public function testASignedInMemberIsRefusedAtAdminAjax(): void
    {
        $GLOBALS['__bcc_sig']['current'] = self::MEMBER;
        $GLOBALS['__bcc_sig']['ajax']    = true;
        $_REQUEST['action'] = 'some_member_action';

        self::assertSame('status', self::runAdminInit());
        self::assertBare403();
    }

    /** @return array<string, array{int, bool}> */
    public static function untouchedAdminVisits(): array
    {
        return [
            'admin, wp-admin'      => [self::ADMIN, false],
            'admin, admin-ajax'    => [self::ADMIN, true],
            'signed out, wp-admin' => [0, false],
        ];
    }

    /** F-496: the same signed-out admin-ajax decision as `init`, asked again here. */
    public function testASignedOutCallerIsRefusedAtAdminInitOnlyForADeniedAction(): void
    {
        $GLOBALS['__bcc_sig']['ajax'] = true;

        $_REQUEST['action'] = 'wordfence_ls_authenticate';
        self::assertNull(self::runAdminInit(), 'an action that is not denied passes');

        $_REQUEST['action'] = 'peepso_vip_user_icons';
        self::assertSame('status', self::runAdminInit());
        self::assertBare403();
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

    // ── 2. init: WordPress's and PeepSo's ajax doors ────────────────────

    private static function runInit(string $uri, ?string $action = null): ?string
    {
        $_SERVER['REQUEST_URI'] = $uri;
        if ($action === null) {
            unset($_REQUEST['action']);
        } else {
            $_REQUEST['action'] = $action;
        }

        ob_start();
        try {
            WordPressSignInGate::refuseWordPressAjax();
            return null;
        } catch (\SignInGateHalt $halt) {
            return $halt->getMessage();
        } finally {
            self::assertSame('', ob_get_clean(), 'a refusal prints nothing: no page, no nonce');
        }
    }

    /** A bare 403: status and no-cache headers, never a wp_die() page or a redirect. */
    private static function assertBare403(): void
    {
        self::assertSame(403, $GLOBALS['__bcc_sig']['status'] ?? null);
        self::assertTrue($GLOBALS['__bcc_sig']['nocache'] ?? false, 'no-cache headers');
        self::assertArrayNotHasKey('die', $GLOBALS['__bcc_sig']);
        self::assertArrayNotHasKey('redirect', $GLOBALS['__bcc_sig']);
    }

    /** @return array<string, array{string}> */
    public static function memberAjaxActions(): array
    {
        return [
            'some_member_action'                     => ['some_member_action'],
            "core's rest-nonce"                        => ['rest-nonce'],
            'heartbeat'                                => ['heartbeat'],
            "Action Scheduler's async runner"          => ['as_async_request_queue_runner'],
            "LiteSpeed's async task (with a cookie)"   => ['async_litespeed'],
            'no action at all'                         => [''],
        ];
    }

    #[DataProvider('memberAjaxActions')]
    public function testASignedInMemberGetsABare403AtAdminAjax(string $action): void
    {
        $GLOBALS['__bcc_sig']['current'] = self::MEMBER;
        $GLOBALS['__bcc_sig']['ajax']    = true;

        self::assertSame('status', self::runInit('/wp-admin/admin-ajax.php', $action));
        self::assertBare403();
    }

    public function testAnArrayActionIsRefusedToo(): void
    {
        $GLOBALS['__bcc_sig']['current'] = self::MEMBER;
        $GLOBALS['__bcc_sig']['ajax']    = true;
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
        $_REQUEST['action'] = ['some_member_action'];

        try {
            WordPressSignInGate::refuseWordPressAjax();
            self::fail('not refused');
        } catch (\SignInGateHalt) {
        }
        self::assertBare403();
    }

    public function testNoAdminAjaxActionIsOpenToMembers(): void
    {
        self::assertSame([], WordPressSignInGate::MEMBER_AJAX_ACTIONS);
        self::assertFalse(WordPressSignInGate::allowsMemberAjax('some_member_action'), 'the real (empty) list');
    }

    /** If the list ever gains an entry, only that exact action opens. */
    public function testAnAllowlistEntryOpensOnlyThatExactAction(): void
    {
        $sample = ['as_async_request_queue_runner'];

        self::assertTrue(WordPressSignInGate::allowsMemberAjax('as_async_request_queue_runner', $sample));
        foreach (['AS_ASYNC_REQUEST_QUEUE_RUNNER', 'as_async_request_queue_runner_x', 'as_async_request', ' as_async_request_queue_runner', 'some_member_action', ''] as $other) {
            self::assertFalse(WordPressSignInGate::allowsMemberAjax($other, $sample), var_export($other, true));
        }
    }

    public function testAnAdminKeepsAdminAjax(): void
    {
        $GLOBALS['__bcc_sig']['current'] = self::ADMIN;
        $GLOBALS['__bcc_sig']['ajax']    = true;

        self::assertNull(self::runInit('/wp-admin/admin-ajax.php', 'peepso_vip_user_icons'));
        self::assertArrayNotHasKey('status', $GLOBALS['__bcc_sig']);
    }

    // ── F-496: signed-out admin-ajax, a deny-list ───────────────────────

    public function testTheSignedOutDenyRulesArePinned(): void
    {
        self::assertSame('peepso', WordPressSignInGate::SIGNED_OUT_DENIED_PREFIX);
        self::assertSame([
            'log_js_errors',
        ], WordPressSignInGate::SIGNED_OUT_DENIED_ACTIONS);
    }

    /**
     * Signed-out actions that MUST stay open: admin sign-in (2FA, passkeys),
     * loopbacks, schedulers, mail and core's own. A refusal here locks
     * admins out or stops background work.
     *
     * @return array<string, array{string}>
     */
    public static function signedOutKeptActions(): array
    {
        return [
            'core heartbeat'              => ['heartbeat'],
            'core generate-password'      => ['generate-password'],
            'LiteSpeed loopback'          => ['async_litespeed'],
            'Action Scheduler runner'     => ['as_async_request_queue_runner'],
            'Wordfence 2FA sign-in'       => ['wordfence_ls_authenticate'],
            'Wordfence passkey begin'     => ['wordfence_ls_begin_passkey_login'],
            'Wordfence passkey finish'    => ['wordfence_ls_finish_passkey_login'],
            'Wordfence support register'  => ['wordfence_ls_register_support'],
            'Wordfence scan'              => ['wordfence_doScan'],
            'WP Mail SMTP pingback'       => ['wp_mail_smtp_gmail_reauth_pingback'],
            'WP Mail SMTP migrations'     => ['wp_mail_smtp_init_migrations'],
            'WP Mail SMTP installed'      => ['wp_mail_smtp_is_installed'],
            'no action at all'            => [''],
            'the prefix in another case'  => ['PeepSo_vip_user_icons'],
            'the prefix not at the start' => ['x_peepso'],
            'a denied name, longer'       => ['log_js_errors_x'],
            'a denied name, upper case'   => ['WP_BULK_IMAGE_ALT'],
        ];
    }

    #[DataProvider('signedOutKeptActions')]
    public function testASignedOutCallerKeepsAnyActionThatIsNotDenied(string $action): void
    {
        $GLOBALS['__bcc_sig']['ajax'] = true;

        self::assertNull(self::runInit('/wp-admin/admin-ajax.php', $action));
        self::assertArrayNotHasKey('status', $GLOBALS['__bcc_sig']);
        self::assertFalse(WordPressSignInGate::deniesSignedOutAjax($action));
    }

    /** @return array<string, array{string}> */
    public static function signedOutDeniedActions(): array
    {
        return [
            'PeepSo: vip_user_icons'      => ['peepso_vip_user_icons'],
            'PeepSo: blogposts'           => ['peepsoblogposts_user_posts'],
            'PeepSo: should_get_chats'    => ['peepso_should_get_chats'],
            'any other peepso* action'    => ['peepso_some_new_action'],
            'the bare prefix'             => ['peepso'],
            'debug JS logger'             => ['log_js_errors'],
        ];
    }

    #[DataProvider('signedOutDeniedActions')]
    public function testASignedOutCallerGetsABare403ForADeniedAction(string $action): void
    {
        $GLOBALS['__bcc_sig']['ajax'] = true;

        self::assertSame('status', self::runInit('/wp-admin/admin-ajax.php', $action));
        self::assertBare403();
        self::assertTrue(WordPressSignInGate::deniesSignedOutAjax($action));
    }

    /** An array action names no hook, so there is nothing to deny. */
    public function testASignedOutArrayActionPasses(): void
    {
        $GLOBALS['__bcc_sig']['ajax'] = true;
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
        $_REQUEST['action'] = ['peepso_vip_user_icons'];

        WordPressSignInGate::refuseWordPressAjax();
        self::assertArrayNotHasKey('status', $GLOBALS['__bcc_sig']);
    }

    /** Members keep the full block: an action open signed out is still refused to them. */
    public function testASignedInMemberIsRefusedAnActionOpenSignedOut(): void
    {
        $GLOBALS['__bcc_sig']['current'] = self::MEMBER;
        $GLOBALS['__bcc_sig']['ajax']    = true;

        self::assertSame('status', self::runInit('/wp-admin/admin-ajax.php', 'heartbeat'));
        self::assertBare403();
    }

    /** Signed out, but not admin-ajax: `init` leaves the request alone. */
    public function testASignedOutPageRequestIsUntouched(): void
    {
        self::assertNull(self::runInit('/some-page/', 'peepso_vip_user_icons'));
        self::assertArrayNotHasKey('status', $GLOBALS['__bcc_sig']);
    }

    // ── F-496: reserved path words ──────────────────────────────────────

    public function testTheReservedPathWordsArePinned(): void
    {
        self::assertSame(['peepsoajax', 'peepso'], WordPressSignInGate::RESERVED_PATH_WORDS);
    }

    /** @return array<string, array{string, string}> */
    public static function reservedGroupSlugs(): array
    {
        return [
            'peepsoajax'  => ['peepsoajax', 'peepsoajax-group'],
            'peepso'      => ['peepso', 'peepso-group'],
            'mixed case'  => ['PeepSoAjax', 'peepsoajax-group'],
        ];
    }

    #[DataProvider('reservedGroupSlugs')]
    public function testAGroupNeverGetsAReservedSlug(string $slug, string $expected): void
    {
        $GLOBALS['__bcc_sig']['unique_suffix'] = '-2';

        $out = WordPressSignInGate::keepPathWordsOffGroups($slug, 41, 'publish', 'peepso-group', 0, $slug);

        self::assertSame($expected . '-2', $out, 'the -group form, made unique by core');
        self::assertSame([[$expected, 41, 'publish', 'peepso-group', 0]], $GLOBALS['__bcc_sig']['unique_slug'] ?? null);
    }

    /** @return array<string, array{mixed, string}> */
    public static function untouchedSlugs(): array
    {
        return [
            'a normal group slug'                => ['builders', 'peepso-group'],
            'a reserved word inside a longer one' => ['peepsoajax-fans', 'peepso-group'],
            'a reserved word on a post'          => ['peepsoajax', 'post'],
            'a reserved word on a page'          => ['peepso', 'page'],
            'not a string'                       => [null, 'peepso-group'],
        ];
    }

    /** @param mixed $slug */
    #[DataProvider('untouchedSlugs')]
    public function testOtherSlugsAreUntouched($slug, string $postType): void
    {
        self::assertSame($slug, WordPressSignInGate::keepPathWordsOffGroups($slug, 41, 'publish', $postType, 0, $slug));
        self::assertArrayNotHasKey('unique_slug', $GLOBALS['__bcc_sig']);
    }

    /**
     * PeepSo::check_ajax_query() (peepso.php, 9.0.5.5), verbatim but for
     * the global: does PeepSo route this URI to its ajax handler?
     */
    private static function peepsoRoutes(string $requestUri): bool
    {
        $path = trim((string) parse_url($requestUri, PHP_URL_PATH), '/');
        $parts = explode('/', $path);
        $segment = count($parts) - 2;

        return $segment >= 0 && 'peepsoajax' === $parts[$segment];
    }

    /** @return array<string, array{string}> */
    public static function peepsoAjaxUris(): array
    {
        return [
            'the router'                 => ['/peepsoajax/a.b'],
            'below a prefix'             => ['/x/peepsoajax/a.b'],
            'after index.php'            => ['/index.php/peepsoajax/a.b'],
            'a trailing slash'           => ['/peepsoajax/a.b/'],
            'a query string'             => ['/peepsoajax/a.b?x=1'],
            'a module call'              => ['/peepsoajax/module.method?id=12'],
            'any case'                   => ['/PeepSoAjax/module.method'],
            'a doubled leading slash'    => ['//peepsoajax/a.b'],
            'a host-looking path'        => ['//cms.example.test/peepsoajax/a.b'],
            'absolute-form'              => ['https://cms.example.test/peepsoajax/a.b'],
            'deep below a prefix'        => ['/a/b/c/peepsoajax/a.b'],
            'the bare router'            => ['/peepsoajax'],
            'the bare router, slashed'   => ['/peepsoajax/'],
            'the bare router, any case'  => ['/PeepSoAjax'],
        ];
    }

    #[DataProvider('peepsoAjaxUris')]
    public function testPeepSoAjaxIsMatchedAsPeepSoRoutesIt(string $uri): void
    {
        self::assertTrue(WordPressSignInGate::isPeepSoAjax($uri));
    }

    /** @return array<string, array{string}> */
    public static function otherUris(): array
    {
        return [
            'the front page'       => ['/'],
            'a BCC REST call'      => ['/wp-json/bcc/v1/me'],
            'admin-ajax'           => ['/wp-admin/admin-ajax.php?action=some_member_action'],
            'a longer segment'     => ['/peepsoajaxx/a.b'],
            'a prefixed segment'   => ['/xpeepsoajax/a.b'],
            'split words'          => ['/peepso/ajax/a.b'],
            'PeepSo REST'          => ['/wp-json/peepso/v1/x'],
        ];
    }

    #[DataProvider('otherUris')]
    public function testOtherUrisAreNotPeepSoAjax(string $uri): void
    {
        self::assertFalse(WordPressSignInGate::isPeepSoAjax($uri));
    }

    /** Whatever PeepSo would route, the matcher catches (a superset). */
    public function testEveryUriPeepSoRoutesIsMatched(): void
    {
        $corpus = array_merge(
            array_column(self::peepsoAjaxUris(), 0),
            array_column(self::otherUris(), 0),
            ['/peepsoajax/a.b/c', '/x/y/peepsoajax/z', '/peepsoajax//a.b', '/peepsoajax/a.b#frag', '/%70eepsoajax/a.b', 'peepsoajax/a.b']
        );
        $routed = 0;
        foreach ($corpus as $uri) {
            if (self::peepsoRoutes($uri)) {
                $routed++;
                self::assertTrue(WordPressSignInGate::isPeepSoAjax($uri), $uri);
            }
        }
        self::assertGreaterThanOrEqual(10, $routed, 'the corpus exercises what PeepSo routes');
    }

    /** @return array<string, array{int}> */
    public static function nonAdmins(): array
    {
        return ['a member' => [self::MEMBER], 'signed out' => [0]];
    }

    #[DataProvider('nonAdmins')]
    public function testANonAdminGetsABare403AtPeepSoAjax(int $user): void
    {
        $GLOBALS['__bcc_sig']['current'] = $user;

        self::assertSame('status', self::runInit('/x/peepsoajax/module.method'));
        self::assertBare403();
    }

    /** @return array<string, array{string}> */
    public static function peepsoMemberDataExportActions(): array
    {
        return [
            'request the export'   => ['profile.request_account_data'],
            'download the export'  => ['profile.download_account_data'],
            'delete the archive'   => ['profile.delete_account_data_archive'],
        ];
    }

    /**
     * This refusal is also what keeps PeepSo's member data-export actions
     * closed; the export setting does not gate them (F-581, private tracker).
     *
     * So it must stay registered at `init`, PHP_INT_MIN (before PeepSo's
     * router), and must keep refusing these actions for a signed-in member.
     */
    #[DataProvider('peepsoMemberDataExportActions')]
    public function testTheRouterRefusalKeepsPeepSoMemberDataExportActionsClosed(string $method): void
    {
        WordPressSignInGate::register();
        $init = array_values(array_filter(
            $GLOBALS['__bcc_sig']['hooks'],
            static fn(array $h): bool => $h[0] === 'init' && $h[1] === [WordPressSignInGate::class, 'refuseWordPressAjax']
        ));
        self::assertCount(1, $init, 'registered once at init');
        self::assertSame(PHP_INT_MIN, $init[0][2], 'at PHP_INT_MIN, before PeepSo routes the call');

        $GLOBALS['__bcc_sig']['current'] = self::MEMBER;

        self::assertSame('status', self::runInit('/peepsoajax/' . $method));
        self::assertBare403();
    }

    public function testAnAdminKeepsPeepSoAjax(): void
    {
        $GLOBALS['__bcc_sig']['current'] = self::ADMIN;

        self::assertNull(self::runInit('/peepsoajax/module.method'));
        self::assertArrayNotHasKey('status', $GLOBALS['__bcc_sig']);
    }

    /** @return array<string, array{string}> */
    public static function memberElsewhere(): array
    {
        return [
            'a BCC REST call' => ['/wp-json/bcc/v1/me'],
            'a cms page'      => ['/no-such-page/'],
            'wp-admin'        => ['/wp-admin/'],
        ];
    }

    #[DataProvider('memberElsewhere')]
    public function testInitLeavesAMemberAloneEverywhereElse(string $uri): void
    {
        $GLOBALS['__bcc_sig']['current'] = self::MEMBER;

        self::assertNull(self::runInit($uri));
        self::assertArrayNotHasKey('status', $GLOBALS['__bcc_sig']);
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

    // ── 4b. wp-login.php's lost-password form ───────────────────────────

    /**
     * Core's retrieve_password() (user.php, 7.0.2), cut down to what
     * decides the answer: the account lookup and its `invalid_email`, the
     * `lostpassword_errors` filters add_filter() recorded (another plugin's
     * first, when a test adds one), `invalidcombo`, then
     * get_password_reset_key().
     *
     * @return \WP_Error|string the error the form shows, or 'mailed'
     */
    private static function lostPassword(string $userLogin)
    {
        $errors = new \WP_Error();
        $user   = false;
        if ($userLogin === '') {
            $errors->add('empty_username', 'Please enter a username or email address.');
        } elseif (str_contains($userLogin, '@')) {
            $user = get_user_by('email', $userLogin) ?: self::accountByLogin($userLogin);
            if (!$user) {
                $errors->add('invalid_email', 'There is no account with that username or email address.');
            }
        } else {
            $user = self::accountByLogin($userLogin);
        }

        foreach ($GLOBALS['__bcc_sig']['hooks'] as [$hook, $callback]) {
            if ($hook === 'lostpassword_errors') {
                $errors = $callback($errors, $user);
            }
        }
        if ($errors->has_errors()) {
            return $errors;
        }
        if (!$user) {
            return new \WP_Error('invalidcombo', 'There is no account with that username or email address.');
        }
        $key = get_password_reset_key($user);

        return $key instanceof \WP_Error ? $key : 'mailed';
    }

    private static function accountByLogin(string $login): ?\WP_User
    {
        foreach ($GLOBALS['__bcc_ph']['users'] as $id => $u) {
            if ($u['login'] === $login) {
                return self::account($id);
            }
        }
        return null;
    }

    /** @return array<string, array{string}> */
    public static function lostPasswordNames(): array
    {
        return [
            'a member, by login'  => ['alice'],
            'a member, by email'  => ['alice@example.com'],
            'an admin, by login'  => ['boss'],
            'an admin, by email'  => ['boss@example.com'],
            'an unknown login'    => ['nobody'],
            'an unknown email'    => ['nobody@example.com'],
        ];
    }

    /**
     * Core said "There is no account with that username or email address"
     * for an unknown account, while a known one got the reset refusal:
     * side by side they told which accounts exist. Every named account,
     * known or not, admin or member, now gets the same answer.
     */
    #[DataProvider('lostPasswordNames')]
    public function testTheLostPasswordFormGivesEveryAccountTheSameAnswer(string $name): void
    {
        WordPressSignInGate::register();

        self::assertResetRefused(self::lostPassword($name), $name);
        self::assertArrayNotHasKey('reset_keys', $GLOBALS['__bcc_sig']);
    }

    public function testAnEmptyLostPasswordFormStillSaysSo(): void
    {
        WordPressSignInGate::register();

        $answer = self::lostPassword('');

        self::assertInstanceOf(\WP_Error::class, $answer);
        self::assertSame(['empty_username'], $answer->get_error_codes());
    }

    /** Another plugin's error (a captcha) reads the same for a known and an unknown account. */
    #[DataProvider('lostPasswordNames')]
    public function testAnotherPluginsErrorStandsAndReadsTheSameForEveryAccount(string $name): void
    {
        add_filter('lostpassword_errors', static function (\WP_Error $errors): \WP_Error {
            $errors->add('captcha_failed', 'Please complete the captcha.');
            return $errors;
        }, 10, 2);
        WordPressSignInGate::register();

        $answer = self::lostPassword($name);

        self::assertInstanceOf(\WP_Error::class, $answer);
        self::assertSame(['captcha_failed'], $answer->get_error_codes(), $name);
    }

    public function testSomethingThatIsNotAnErrorListIsLeftAlone(): void
    {
        self::assertSame('odd', WordPressSignInGate::sameLostPasswordAnswer('odd', false));
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
