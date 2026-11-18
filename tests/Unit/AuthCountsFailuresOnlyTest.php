<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\EmailVerificationController;
use BCC\Trust\Core\REST\Auth\PasswordAuthController;
use BCC\Trust\Core\Services\HandleService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-290 follow-up: on the anonymous auth routes a refused call is no
 * longer recorded. Every other caller keeps pessimistic counting.
 *
 * Pinned, running the REAL login / forgot-password / resend / verify /
 * reset handlers over the counting Throttle double (which honours
 * countDenied and release() as bcc-core #124 does):
 *   - retrying while blocked never extends the block;
 *   - a correct password STAYS counted (the 2FA step after it has no
 *     limit of its own, so a release would mean unlimited sign-ins);
 *   - a call a LATER login bucket refuses gives back what it charged;
 *   - forgot / resend: the per-network bucket doesn't record refusals and
 *     gives its slot back when an address cap refuses; the per-address mail
 *     caps still count every request (the mail-bomb guard);
 *   - verify-email and reset-password opt in; signup does not.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthCountsFailuresOnlyTest extends TestCase
{
    private const ALICE = 10;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/handle-rename-identity-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users'             => [self::ALICE => ['email' => 'alice@example.test', 'login' => 'u_alice', 'display' => 'Alice']],
            // Unverified, so a correct password stops at 403 — past the throttle.
            'meta'              => [self::ALICE => [HandleService::META_HANDLE => 'alice', AuthSupport::META_EMAIL_VERIFIED => '0']],
            'passwords'         => [self::ALICE => 'right'],
            'transients'        => [],
            'mail'              => [],
            'actions'           => [],
            'current_user'      => 0,
            'throttle_counting' => true,
            'client_ip'         => '198.51.100.20',
        ];
    }

    private static function login(string $identifier, string $password): int
    {
        return (new PasswordAuthController())->login(
            new \WP_REST_Request(['identifier' => $identifier, 'password' => $password])
        )->get_status();
    }

    private static function netKey(string $typed): string
    {
        return 'bcc_throttle_login_account_net_' . hash('sha256', 'id:' . hash('sha256', $typed) . '|' . AuthSupport::loginNetwork());
    }

    private static function hits(string $bucket): int
    {
        return (int) ($GLOBALS['__bcc_ph']['throttle_hits'][$bucket] ?? 0);
    }

    public function testRetryingWhileBlockedNeverExtendsTheBlock(): void
    {
        $limit = AuthSupport::LOGIN_ACCOUNT_RATE_LIMIT;
        for ($i = 0; $i < $limit; $i++) {
            // The per-IP `login` bucket (20/min) isn't what this case exercises.
            unset($GLOBALS['__bcc_ph']['throttle_hits']['login']);
            self::assertSame(401, self::login('alice', 'wrong' . $i));
        }
        for ($i = 0; $i < 15; $i++) {
            unset($GLOBALS['__bcc_ph']['throttle_hits']['login']);
            self::assertSame(429, self::login('alice', 'still-wrong'));
        }

        self::assertSame($limit, self::hits(self::netKey('alice')), 'the 15 refused retries were not recorded');
    }

    public function testACorrectPasswordStaysCounted(): void
    {
        for ($i = 0; $i < 3; $i++) {
            self::login('alice', 'wrong' . $i);
        }

        self::assertSame(403, self::login('alice', 'right'), 'past the throttle, stopped by the unverified email');

        self::assertSame(4, self::hits('login'), 'the per-IP bucket keeps the success');
        self::assertSame(4, self::hits(self::netKey('alice')), 'so does the per-network one');
        $ceiling = 'bcc_throttle_login_account_' . hash('sha256', 'id:' . hash('sha256', 'alice'));
        self::assertSame(4, self::hits($ceiling), 'and the per-identifier ceiling');
        self::assertSame([], $GLOBALS['__bcc_ph']['throttle_releases'] ?? [], 'nothing was given back');
    }

    public function testACallALaterBucketRefusesGivesBackWhatItCharged(): void
    {
        // The per-identifier ceiling is already spent.
        $ceiling = 'bcc_throttle_login_account_' . hash('sha256', 'id:' . hash('sha256', 'alice'));
        $GLOBALS['__bcc_ph']['throttle_hits'][$ceiling] = AuthSupport::LOGIN_ACCOUNT_GLOBAL_RATE_LIMIT;

        self::assertSame(429, self::login('alice', 'wrong'));

        self::assertSame(0, self::hits('login'), 'the per-IP slot was given back');
        self::assertSame(0, self::hits(self::netKey('alice')), 'and the per-network one');
        self::assertSame(AuthSupport::LOGIN_ACCOUNT_GLOBAL_RATE_LIMIT, self::hits($ceiling), 'the refusing bucket recorded nothing');
    }

    public function testTheAuthRoutesOptInAndSignupDoesNot(): void
    {
        self::login('alice', 'wrong');
        (new PasswordAuthController())->forgotPassword(new \WP_REST_Request(['email' => 'nobody@example.com']));
        (new EmailVerificationController())->resendVerification(new \WP_REST_Request(['email' => 'nobody@example.com']));
        (new EmailVerificationController())->verifyEmail(new \WP_REST_Request(['email' => 'nobody@example.com', 'code' => '123456']));
        (new PasswordAuthController())->resetPassword(new \WP_REST_Request(['key' => 'k', 'login' => 'u', 'password' => 'a long new password']));
        (new PasswordAuthController())->signup(new \WP_REST_Request(['email' => 'x@example.com', 'password' => 'a long password', 'handle' => 'xx']));

        $flags = $GLOBALS['__bcc_ph']['throttle_count_denied'];
        foreach (['login', self::netKey('alice'), 'forgot_password', 'resend_verification', 'verify_email', 'reset_password_attempt'] as $bucket) {
            self::assertFalse($flags[$bucket], $bucket . ' does not record refused calls');
        }
        self::assertTrue($flags['signup'], 'everything else stays pessimistic');
    }

    public function testAnAddressCapRefusalGivesBackTheNetworkSlotButStillCountsTheAddress(): void
    {
        $perAddress = AuthSupport::FORGOT_PASSWORD_EMAIL_RATE_LIMIT;
        for ($i = 0; $i < $perAddress; $i++) {
            (new PasswordAuthController())->forgotPassword(new \WP_REST_Request(['email' => 'target@example.com']));
        }
        self::assertSame($perAddress, self::hits('forgot_password'));

        $status = (new PasswordAuthController())->forgotPassword(new \WP_REST_Request(['email' => 'target@example.com']))->get_status();

        self::assertSame(429, $status);
        self::assertSame($perAddress, self::hits('forgot_password'), 'the refused request gave its network slot back');
        $addressKey = AuthSupport::emailThrottleKey('forgot_password', 'target@example.com') . '_net_' . hash('sha256', AuthSupport::loginNetwork());
        self::assertSame($perAddress + 1, self::hits($addressKey), 'the address cap counts every request (mail-bomb guard)');
    }

    /** @param array<string, string> $params */
    private static function resend(array $params): int
    {
        return (new EmailVerificationController())->resendVerification(new \WP_REST_Request($params))->get_status();
    }

    public function testAHandleCapRefusalGivesBackTheNetworkSlotButStillCountsTheHandle(): void
    {
        $perHandle = AuthSupport::RESEND_VERIFICATION_EMAIL_RATE_LIMIT;
        for ($i = 0; $i < $perHandle; $i++) {
            self::assertSame(200, self::resend(['identifier' => 'nobody-here']));
        }
        self::assertSame($perHandle, self::hits('resend_verification'));

        self::assertSame(429, self::resend(['identifier' => 'nobody-here']));

        self::assertSame($perHandle, self::hits('resend_verification'), 'the refused request gave its network slot back');
        $handleKey = AuthSupport::emailThrottleKey('resend_verification', 'handle:nobody-here') . '_net_' . hash('sha256', AuthSupport::loginNetwork());
        self::assertSame($perHandle + 1, self::hits($handleKey), 'the handle cap counts every request');
    }

    public function testTheSilentInboxCapOnTheHandleFormKeepsTheNetworkSlot(): void
    {
        // Spend alice's inbox cap by email, then ask by handle: nothing is
        // sent, the answer is the same ok, and the network slot stays
        // charged. A give-back only here (the handle exists) would let the
        // network count tell existing handles from unknown ones.
        $perAddress = AuthSupport::RESEND_VERIFICATION_EMAIL_RATE_LIMIT;
        for ($i = 0; $i < $perAddress; $i++) {
            self::resend(['email' => 'alice@example.test']);
        }
        $mails   = count($GLOBALS['__bcc_ph']['mail']);
        $network = self::hits('resend_verification');

        self::assertSame(200, self::resend(['identifier' => 'alice']));

        self::assertSame($mails, count($GLOBALS['__bcc_ph']['mail']), 'over the inbox cap, nothing is sent');
        self::assertSame($network + 1, self::hits('resend_verification'), 'the network slot is not given back');
        self::assertSame([], $GLOBALS['__bcc_ph']['throttle_releases'] ?? [], 'nothing was released');
    }
}
