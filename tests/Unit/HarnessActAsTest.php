<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Security\WordPressSignInGate;
use BCC\Trust\Core\Support\BearerAuth;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-441: the in-process harnesses (scripts/tests/announcement-*.php) become
 * a member the way the app does, by bearer token
 * (scripts/tests/lib/act-as.php).
 *
 * Since H2, WordPressSignInGate refuses a member on a bcc route whose
 * identity did not come from the bearer token. `wp_set_current_user()` is
 * exactly that, and it broke every harness that dispatched a bcc route as
 * a member. This pins that the helper's member passes the REAL gate's
 * decision, through the real BearerAuth and JwtToken, and that the old way
 * does not.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HarnessActAsTest extends TestCase
{
    private const MEMBER = 90;
    private const OTHER  = 91;
    private const ROUTE  = '/bcc/v1/ranks';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/envelope-meta-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        require_once __DIR__ . '/../Stubs/wp-signin-gate-stubs.php';
        require_once __DIR__ . '/../Stubs/harness-act-as-stubs.php';
        require_once __DIR__ . '/../Stubs/rest-request-stubs.php';
        require_once __DIR__ . '/../../scripts/tests/lib/act-as.php';
        $GLOBALS['__bcc_envelope_meta_throttle'] = ['allow' => true];
        $GLOBALS['__bcc_ph'] = [
            'users'      => [
                self::MEMBER => ['email' => 'alice@example.com', 'login' => 'alice', 'display' => 'Alice'],
                self::OTHER  => ['email' => 'bob@example.com', 'login' => 'bob', 'display' => 'Bob'],
            ],
            'meta'       => [self::MEMBER => ['bcc_handle' => 'alice'], self::OTHER => ['bcc_handle' => 'bob']],
            'transients' => [],
            'mail'       => [],
            'actions'    => [],
            'options'    => [],
        ];
        $GLOBALS['__bcc_sig'] = ['admins' => [], 'current' => 0, 'ajax' => false, 'hooks' => []];
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $GLOBALS['current_user'], $GLOBALS['__bcc_harness_request']);
        // What `wp eval-file` and PHPUnit look like: not the front
        // controller, no REST path. The bearer counts on REST only.
        $_SERVER['SCRIPT_NAME'] = 'vendor/bin/phpunit';
        $_SERVER['REQUEST_URI'] = '';
        BearerAuth::register();
    }

    /** What door 3 decides for the request as it now stands: null = let through. */
    private static function door3(): ?\WP_REST_Response
    {
        return WordPressSignInGate::decideRest(self::ROUTE, get_current_user_id(), BearerAuth::authenticatedUserId());
    }

    public function testAMemberActedAsByBearerPassesTheSignInGate(): void
    {
        bcc_harness_act_as(self::MEMBER);

        self::assertSame(self::MEMBER, get_current_user_id());
        self::assertSame(self::MEMBER, BearerAuth::authenticatedUserId());
        self::assertStringStartsWith('Bearer ', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
        self::assertNull(self::door3(), 'a bearer-authenticated member reaches the route');
    }

    /** The breakage the helper exists for: the old way is refused by H2's door 3. */
    public function testAMemberSetWithoutABearerIsRefusedByTheSignInGate(): void
    {
        $GLOBALS['__bcc_sig']['current'] = self::MEMBER; // what wp_set_current_user() leaves

        $refusal = self::door3();

        self::assertInstanceOf(\WP_REST_Response::class, $refusal);
        self::assertSame(401, $refusal->get_status());
    }

    public function testSwitchingMembersAndSigningOut(): void
    {
        bcc_harness_act_as(self::MEMBER);
        bcc_harness_act_as(self::OTHER);

        self::assertSame(self::OTHER, get_current_user_id());
        self::assertSame(self::OTHER, BearerAuth::authenticatedUserId());
        self::assertNull(self::door3());

        bcc_harness_act_as(0);

        self::assertSame(0, get_current_user_id());
        self::assertSame(0, BearerAuth::authenticatedUserId());
        self::assertArrayNotHasKey('HTTP_AUTHORIZATION', $_SERVER, 'signed out presents no token');
    }

    /**
     * The helper mints credentials, so outside the PHP command line it refuses
     * before doing anything (review agent, LOW).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function webSapis(): iterable
    {
        yield 'PHP-FPM'           => ['fpm-fcgi'];
        yield 'Apache mod_php'    => ['apache2handler'];
        yield 'CGI / LiteSpeed'   => ['cgi-fcgi'];
        yield 'LiteSpeed LSAPI'   => ['litespeed'];
        yield 'the built-in server' => ['cli-server'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('webSapis')]
    public function testOutsideTheCommandLineItRefuses(string $sapi): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bcc_harness_act_as is for CLI harnesses only');

        bcc_harness_require_cli($sapi);
    }

    public function testOnTheCommandLineItRuns(): void
    {
        bcc_harness_require_cli('cli');

        self::assertSame('cli', PHP_SAPI, 'PHPUnit runs on the command line, so the helper is usable here');
    }

    /**
     * The guard is the helper's FIRST statement, with the real SAPI, so a
     * web request stops before a token is minted or a header set. (PHP_SAPI
     * is fixed per process, so this is the only way to pin the call.)
     */
    public function testTheGuardIsTheHelpersFirstStatement(): void
    {
        $fn   = new \ReflectionFunction('bcc_harness_act_as');
        $src  = file((string) $fn->getFileName());
        self::assertIsArray($src);
        $body = implode('', array_slice($src, $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1));
        $afterBrace = trim(substr($body, (int) strpos($body, '{') + 1));

        self::assertStringStartsWith('bcc_harness_require_cli(PHP_SAPI);', $afterBrace);
    }

    /** The REST-only rule (BearerAuth) is why the helper presents a REST request. */
    public function testItPresentsTheAppsRestRequestAndRestoresTheRealOne(): void
    {
        bcc_harness_act_as(self::MEMBER);

        self::assertSame('/index.php', $_SERVER['SCRIPT_NAME']);
        self::assertSame('/wp-json/', $_SERVER['REQUEST_URI']);
        self::assertTrue(BearerAuth::isRestRequest());

        bcc_harness_act_as(self::OTHER);
        bcc_harness_act_as(0);

        self::assertSame('vendor/bin/phpunit', $_SERVER['SCRIPT_NAME'], 'the real values, not the first switch\'s');
        self::assertSame('', $_SERVER['REQUEST_URI']);
        self::assertFalse(BearerAuth::isRestRequest());
    }

    /** Each sign-in / sign-out round restores what was there when that round began. */
    public function testEachRoundRestoresItsOwnRequest(): void
    {
        bcc_harness_act_as(self::MEMBER);
        bcc_harness_act_as(0);

        $_SERVER['REQUEST_URI'] = '/somewhere-else';
        bcc_harness_act_as(self::OTHER);
        bcc_harness_act_as(0);

        self::assertSame('/somewhere-else', $_SERVER['REQUEST_URI'], 'not the first round\'s value');
    }

    public function testItUsesTheConfiguredRestPrefix(): void
    {
        $GLOBALS['__bcc_sig']['rest_prefix'] = 'api';

        bcc_harness_act_as(self::MEMBER);

        self::assertSame('/api/', $_SERVER['REQUEST_URI']);
        self::assertSame(self::MEMBER, BearerAuth::authenticatedUserId());
    }

    /**
     * The control, part 1: the helper's own steps minus presenting the REST
     * request. On the CLI's request shape the same minted token is ignored,
     * because BearerAuth counts a bearer on REST only.
     */
    public function testWithoutTheRestShapeTheSameTokenIsIgnored(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . \BCC\Trust\Core\Support\JwtToken::encodeNewSession(self::MEMBER, 'alice');
        $GLOBALS['current_user'] = null;

        self::assertSame(0, (int) wp_get_current_user()->ID, 'a CLI request is not REST, so the bearer is ignored');
        self::assertSame(0, BearerAuth::authenticatedUserId());
    }

    /**
     * The control, part 2: when the request cannot be REST at all (here an
     * admin request, which BearerAuth never treats as REST), the helper's
     * REST shape is not enough, the token is ignored, and the helper throws
     * instead of running the harness as nobody. BearerAuth stays strict.
     */
    public function testWhenTheRequestCannotBeRestTheHelperThrows(): void
    {
        $GLOBALS['__bcc_sig']['admin'] = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bcc_harness_act_as(' . self::MEMBER . '): the request resolved user 0 (bearer user 0)');

        bcc_harness_act_as(self::MEMBER);
    }

    /** A token the app would refuse stops the harness instead of running it as nobody. */
    public function testARefusedTokenFailsLoudly(): void
    {
        // The account is deleted after its token is minted and before the
        // request resolves, so BearerAuth refuses the token (jwt_user_missing).
        add_filter('determine_current_user', static function ($userId) {
            unset($GLOBALS['__bcc_ph']['users'][self::MEMBER]);
            return $userId;
        }, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bcc_harness_act_as(' . self::MEMBER . '): the request resolved user 0 (bearer user 0)');

        bcc_harness_act_as(self::MEMBER);
    }
}
