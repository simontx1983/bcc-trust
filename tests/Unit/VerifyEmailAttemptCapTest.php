<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\EmailVerificationController;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-48 — the email-verification code has a per-account attempt cap.
 *
 * A correct code at POST /auth/verify-email signs the caller in, and the only
 * fence was a per-IP bucket, so guesses spread across addresses were not
 * bounded per account. Pinned against the REAL EmailVerificationController +
 * AuthSupport (counting Throttle stub):
 *   - wrong codes 1..4 leave the code usable — the right one still signs in;
 *   - the 5th wrong code burns it: the right code then fails too, and the
 *     answer is the same generic one (no new enumeration signal);
 *   - the counter is per (user, current code): a resend starts fresh, and
 *     another account's failures never count;
 *   - the bucket key is explicit (user + code identity, never the code);
 *   - no pending code or no account → nothing is counted;
 *   - the emailed link (token path) is untouched by a burn.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class VerifyEmailAttemptCapTest extends TestCase
{
    private const USER  = 61;
    private const EMAIL = 'pending61@example.com';
    private const CODE  = '424242';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users'             => [
                self::USER => ['email' => self::EMAIL, 'login' => 'u61', 'display' => 'U61'],
                62         => ['email' => 'other62@example.com', 'login' => 'u62', 'display' => 'U62'],
            ],
            'meta'              => [
                self::USER => ['bcc_handle' => 'handle61', AuthSupport::META_EMAIL_VERIFIED => '0'],
                62         => ['bcc_handle' => 'handle62', AuthSupport::META_EMAIL_VERIFIED => '0'],
            ],
            'transients'        => [],
            'mail'              => [],
            'actions'           => [],
            'throttle_counting' => true,
        ];
        // The success path resolves in_good_standing through the trust
        // repository, which fails OPEN on any Throwable.
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('no database in unit tests');
            }
        };
        AuthSupport::storeOtpForUser(self::USER, self::CODE);
    }

    private static function verify(string $code, string $email = self::EMAIL): int
    {
        return (new EmailVerificationController())->verifyEmail(
            new \WP_REST_Request(['email' => $email, 'code' => $code])
        )->get_status();
    }

    /** @return list<string> */
    private static function failKeys(): array
    {
        return array_values(array_filter(
            $GLOBALS['__bcc_ph']['throttle_keys'] ?? [],
            static fn(string $k): bool => str_starts_with($k, 'verify_email_fail:')
        ));
    }

    public function testFourWrongCodesLeaveTheRightOneWorking(): void
    {
        for ($i = 1; $i <= AuthSupport::VERIFY_EMAIL_MAX_FAILURES - 1; $i++) {
            self::assertSame(400, self::verify('000000'), "wrong code #$i");
        }

        self::assertSame(200, self::verify(self::CODE), 'still usable after MAX-1 wrong codes');
        self::assertSame('1', $GLOBALS['__bcc_ph']['meta'][self::USER][AuthSupport::META_EMAIL_VERIFIED]);
    }

    public function testTheFifthWrongCodeBurnsTheCode(): void
    {
        for ($i = 1; $i <= AuthSupport::VERIFY_EMAIL_MAX_FAILURES; $i++) {
            self::verify('000000');
        }

        self::assertNull(AuthSupport::emailOtpIdentity(self::USER), 'burned');
        self::assertSame(400, self::verify(self::CODE), 'the right code no longer works');
        self::assertSame('0', $GLOBALS['__bcc_ph']['meta'][self::USER][AuthSupport::META_EMAIL_VERIFIED]);
    }

    public function testABurnAnswersExactlyLikeAnyWrongCode(): void
    {
        $bodies = [];
        for ($i = 1; $i <= AuthSupport::VERIFY_EMAIL_MAX_FAILURES; $i++) {
            $res      = (new EmailVerificationController())->verifyEmail(
                new \WP_REST_Request(['email' => self::EMAIL, 'code' => '000000'])
            );
            $bodies[] = [$res->get_status(), $res->get_data()];
        }
        $unknown = (new EmailVerificationController())->verifyEmail(
            new \WP_REST_Request(['email' => 'nobody@example.com', 'code' => '000000'])
        );

        self::assertCount(1, array_unique(array_map('serialize', $bodies)), 'the burning attempt looks like the others');
        self::assertSame($bodies[0], [$unknown->get_status(), $unknown->get_data()], 'and like an unknown address');
    }

    public function testAResendStartsAFreshCount(): void
    {
        for ($i = 1; $i <= AuthSupport::VERIFY_EMAIL_MAX_FAILURES - 1; $i++) {
            self::verify('000000');
        }
        AuthSupport::storeOtpForUser(self::USER, '515151');         // a resend

        for ($i = 1; $i <= AuthSupport::VERIFY_EMAIL_MAX_FAILURES - 1; $i++) {
            self::verify('000000');
        }

        self::assertSame(200, self::verify('515151'), 'the new code has its own budget');
    }

    public function testAnotherAccountsFailuresNeverCount(): void
    {
        AuthSupport::storeOtpForUser(62, '626262');
        for ($i = 1; $i <= AuthSupport::VERIFY_EMAIL_MAX_FAILURES; $i++) {
            self::verify('000000', 'other62@example.com');
        }

        self::assertNotNull(AuthSupport::emailOtpIdentity(self::USER));
        self::assertSame(200, self::verify(self::CODE));
    }

    /**
     * L2: every attempt is counted BEFORE the compare — even the RIGHT code
     * is refused, uncompared, once MAX attempts have been counted (the state
     * concurrent guesses leave behind), and the code is burned.
     */
    public function testAttemptsBeyondTheCapAreRefusedBeforeAnyCompare(): void
    {
        $GLOBALS['__bcc_ph']['throttle_hits']['verify_email_attempt:' . self::USER . ':' . AuthSupport::emailOtpIdentity(self::USER)] = AuthSupport::VERIFY_EMAIL_MAX_FAILURES;

        self::assertSame(400, self::verify(self::CODE));
        self::assertNull(AuthSupport::emailOtpIdentity(self::USER), 'burned');
        self::assertSame('0', $GLOBALS['__bcc_ph']['meta'][self::USER][AuthSupport::META_EMAIL_VERIFIED], 'not verified');
    }

    public function testEveryAttemptIsCountedIncludingTheRightOne(): void
    {
        $key = 'verify_email_attempt:' . self::USER . ':' . AuthSupport::emailOtpIdentity(self::USER);

        self::verify(self::CODE);

        self::assertSame(1, $GLOBALS['__bcc_ph']['throttle_hits'][$key] ?? 0);
    }

    public function testTheBucketKeyIsExplicitAndNeverTheCode(): void
    {
        self::verify('000000');

        $keys = self::failKeys();
        self::assertSame(['verify_email_fail:' . self::USER . ':' . AuthSupport::emailOtpIdentity(self::USER)], $keys);
        self::assertStringNotContainsString(self::CODE, $keys[0]);
        $storedHmac = (string) $GLOBALS['__bcc_ph']['transients']['bcc_otp_' . self::USER];
        self::assertStringNotContainsString(substr($storedHmac, 0, 16), $keys[0], 'not even a prefix of the code-derived HMAC');
    }

    public function testNoPendingCodeOrNoAccountCountsNothing(): void
    {
        AuthSupport::burnEmailOtp(self::USER);
        self::verify('000000');
        self::verify('000000', 'nobody@example.com');

        self::assertSame([], self::failKeys());
    }

    public function testABurnLeavesTheEmailedLinkWorking(): void
    {
        $GLOBALS['__bcc_ph']['transients']['bcc_vt_linktoken61'] = (string) self::USER;
        for ($i = 1; $i <= AuthSupport::VERIFY_EMAIL_MAX_FAILURES; $i++) {
            self::verify('000000');
        }

        $res = (new EmailVerificationController())->verifyEmail(new \WP_REST_Request(['token' => 'linktoken61']));

        self::assertSame(200, $res->get_status());
    }
}
