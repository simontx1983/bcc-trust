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
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $GLOBALS['current_user']);
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
