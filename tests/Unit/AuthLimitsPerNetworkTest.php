<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\EmailVerificationController;
use BCC\Trust\Core\REST\Auth\PasswordAuthController;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-290 — anonymous auth limits that a shared network could exhaust.
 *
 * Each anonymous auth route had ONE small per-network bucket (IPv4 /24,
 * IPv6 /48): an office, campus or carrier NAT used it up between unrelated
 * members (3 password resets an hour for everyone behind it). Pinned,
 * running the REAL forgotPassword / resendVerification over a counting
 * Throttle double:
 *   - the per-network budgets are office scale (user-approved numbers);
 *   - the mail-sending routes add a per-ADDRESS budget of 3 an hour, so
 *     one inbox still can't be bombed from anywhere;
 *   - the address key is normalized (case-insensitive) and hit before the
 *     account lookup, so it behaves the same whether or not an account
 *     exists — no enumeration oracle.
 *
 * Subprocess-only; setUp() loads the stubs before anything autoloads.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthLimitsPerNetworkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users'             => [],
            'meta'              => [],
            'transients'        => [],
            'mail'              => [],
            'actions'           => [],
            'options'           => [],
            'throttle_counting' => true,
        ];
    }

    /** @return array{string, string} [route label, method] */
    public static function mailRoutes(): array
    {
        return [
            'forgot-password'     => ['forgot', AuthSupport::FORGOT_PASSWORD_RATE_LIMIT, AuthSupport::FORGOT_PASSWORD_EMAIL_RATE_LIMIT],
            'resend-verification' => ['resend', AuthSupport::RESEND_VERIFICATION_RATE_LIMIT, AuthSupport::RESEND_VERIFICATION_EMAIL_RATE_LIMIT],
        ];
    }

    private static function hit(string $route, string $email): int
    {
        $req = new \WP_REST_Request(['email' => $email]);
        return $route === 'forgot'
            ? (new PasswordAuthController())->forgotPassword($req)->get_status()
            : (new EmailVerificationController())->resendVerification($req)->get_status();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mailRoutes')]
    public function testOneAddressIsCappedButOthersOnTheNetworkCarryOn(string $route, int $perNetwork, int $perAddress): void
    {
        for ($i = 0; $i < $perAddress; $i++) {
            self::assertSame(200, self::hit($route, 'target@example.com'));
        }
        self::assertSame(429, self::hit($route, 'target@example.com'), 'the inbox is protected');
        self::assertSame(429, self::hit($route, 'TARGET@Example.com'), 'normalized: case does not open a new budget');

        self::assertSame(200, self::hit($route, 'colleague@example.com'), 'a colleague behind the same NAT is not locked out');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mailRoutes')]
    public function testTheNetworkBudgetIsOfficeScale(string $route, int $perNetwork, int $perAddress): void
    {
        self::assertGreaterThanOrEqual(20, $perNetwork);
        for ($i = 0; $i < $perNetwork; $i++) {
            self::assertSame(200, self::hit($route, 'member' . $i . '@example.com'), 'request ' . ($i + 1));
        }
        self::assertSame(429, self::hit($route, 'one-more@example.com'), 'the network ceiling still holds');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mailRoutes')]
    public function testTheAddressBudgetIsHitWhetherOrNotAnAccountExists(string $route, int $perNetwork, int $perAddress): void
    {
        // No account has this address; the cap applies all the same, so the
        // 4th request reveals nothing about existence.
        for ($i = 0; $i < $perAddress; $i++) {
            self::hit($route, 'nobody@example.com');
        }
        self::assertSame(429, self::hit($route, 'nobody@example.com'));
        self::assertContains(
            AuthSupport::emailThrottleKey($route === 'forgot' ? 'forgot_password' : 'resend_verification', 'Nobody@Example.com'),
            $GLOBALS['__bcc_ph']['throttle_keys']
        );
    }

    private static function from(string $ip): void
    {
        $GLOBALS['__bcc_ph']['client_ip'] = $ip;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mailRoutes')]
    public function testAStrangerElsewhereSpendsOnlyTheirOwnNetworksShare(string $route, int $perNetwork, int $perAddress): void
    {
        self::from('203.0.113.9');
        for ($i = 0; $i < $perAddress; $i++) {
            self::hit($route, 'victim@example.com');
        }
        self::assertSame(429, self::hit($route, 'victim@example.com'), 'the stranger is capped');

        self::from('198.51.100.20');
        self::assertSame(200, self::hit($route, 'victim@example.com'), 'the owner on another network still gets mail');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mailRoutes')]
    public function testTheGlobalCapStillStopsMailBombingAcrossNetworks(string $route, int $perNetwork, int $perAddress): void
    {
        $global = $route === 'forgot'
            ? AuthSupport::FORGOT_PASSWORD_EMAIL_GLOBAL_RATE_LIMIT
            : AuthSupport::RESEND_VERIFICATION_EMAIL_GLOBAL_RATE_LIMIT;
        self::assertSame(10, $global);
        for ($i = 0; $i < $global; $i++) {
            self::from('192.0.' . $i . '.1'); // a fresh network each time
            self::assertSame(200, self::hit($route, 'target@example.com'));
        }
        self::from('100.64.0.1');
        self::assertSame(429, self::hit($route, 'target@example.com'), 'the address is capped across networks');
    }

    public function testTheOwnersTrustedNetworkSkipsTheGlobalCap(): void
    {
        // An account whose owner completed a sign-in from home.
        $GLOBALS['__bcc_ph']['users'][7] = ['email' => 'owner@example.com', 'login' => 'u7', 'display' => 'Owner'];
        self::from('198.51.100.20');
        AuthSupport::recordTrustedLoginNetwork(7);

        // A stranger exhausts the global cap from many networks.
        for ($i = 0; $i < AuthSupport::FORGOT_PASSWORD_EMAIL_GLOBAL_RATE_LIMIT; $i++) {
            self::from('192.0.' . $i . '.1');
            AuthSupport::allowMailToAddress('forgot_password', 'owner@example.com', AuthSupport::FORGOT_PASSWORD_EMAIL_RATE_LIMIT, AuthSupport::FORGOT_PASSWORD_EMAIL_GLOBAL_RATE_LIMIT);
        }

        self::from('100.64.0.1');
        self::assertFalse(
            AuthSupport::allowMailToAddress('forgot_password', 'owner@example.com', AuthSupport::FORGOT_PASSWORD_EMAIL_RATE_LIMIT, AuthSupport::FORGOT_PASSWORD_EMAIL_GLOBAL_RATE_LIMIT),
            'an untrusted network is capped'
        );
        self::from('198.51.100.77'); // the owner's home /24
        self::assertTrue(
            AuthSupport::allowMailToAddress('forgot_password', 'owner@example.com', AuthSupport::FORGOT_PASSWORD_EMAIL_RATE_LIMIT, AuthSupport::FORGOT_PASSWORD_EMAIL_GLOBAL_RATE_LIMIT),
            'the owner at home still gets their reset mail'
        );
    }

    public function testTheOtherApprovedNumbers(): void
    {
        self::assertSame(20, AuthSupport::LOGIN_RATE_LIMIT, 'login per network, per minute');
        self::assertSame(30, AuthSupport::VERIFY_EMAIL_RATE_LIMIT, 'verify-email per network, per hour');
        self::assertSame(30, AuthSupport::RESET_PASSWORD_RATE_LIMIT, 'reset-password per network, per hour');
    }
}
