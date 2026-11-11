<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\TwoFactorController;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-124 — the 2FA OTP had no per-account attempt cap: only a per-IP throttle,
 * wrong codes burned nothing, and the OTP was shared by every live challenge
 * of the user (so each extra login bought more guesses at the same code).
 *
 * Pinned against the REAL TwoFactorController::twoFaVerify / twoFaResend and
 * AuthSupport's 2FA helpers:
 *   - the OTP is bound to its challenge;
 *   - at most TWO_FA_MAX_FAILURES (5) wrong codes per (user, challenge): 1–4
 *     answer bcc_invalid_2fa_code, the 5th burns that challenge's OTP + the
 *     challenge and answers bcc_invalid_2fa_token ("sign in again");
 *   - a new login supersedes every earlier challenge (one live challenge per
 *     user), so N logins cannot multiply guesses, and a stale challenge can
 *     never burn a fresh one;
 *   - a resend re-binds a new code to the same challenge without resetting
 *     its counter;
 *   - the counter bucket is an explicit (user, challenge) key, not an IP.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TwoFactorAttemptCapTest extends TestCase
{
    private const USER  = 60;
    private const RIGHT = '424242';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users'             => [self::USER => ['email' => 'u60@example.com', 'login' => 'u60', 'display' => 'U60']],
            'meta'              => [self::USER => ['bcc_handle' => 'handle60']],
            'transients'        => [],
            'mail'              => [],
            'actions'           => [],
            'options'           => [],
            'throttle_counting' => true,
        ];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('no database in unit tests');
            }
        };
    }

    /** Simulate /auth/login's 2FA gate for USER with a known code. */
    private static function login(string $code = self::RIGHT): string
    {
        $token = bin2hex(random_bytes(32));
        AuthSupport::store2faChallenge($token, self::USER);
        AuthSupport::store2faOtp($token, $code);
        return $token;
    }

    /** @return array{0: int, 1: ?string, 2: array<string, mixed>} */
    private static function verify(string $challenge, string $code): array
    {
        $res  = (new TwoFactorController())->twoFaVerify(new \WP_REST_Request(['challenge_token' => $challenge, 'code' => $code]));
        $data = $res->get_data();
        self::assertIsArray($data);
        return [$res->get_status(), $data['error']['code'] ?? null, $data];
    }

    private static function otpKey(string $challenge): string
    {
        return 'bcc_2fa_otp_' . AuthSupport::twoFaChallengeKey($challenge);
    }

    public function testFourWrongCodesThenTheRightOneStillSignsIn(): void
    {
        $c = self::login();
        for ($i = 1; $i <= 4; $i++) {
            [$status, $code] = self::verify($c, '000000');
            self::assertSame([401, 'bcc_invalid_2fa_code'], [$status, $code], "wrong code #$i");
        }

        [$status, , $data] = self::verify($c, self::RIGHT);

        self::assertSame(200, $status);
        self::assertIsString($data['data']['token'] ?? null);
    }

    public function testTheFifthWrongCodeBurnsTheOtpAndTheChallenge(): void
    {
        $c = self::login();
        for ($i = 1; $i <= 4; $i++) {
            self::verify($c, '000000');
        }

        [$status, $code, $data] = self::verify($c, '000000');

        self::assertSame([401, 'bcc_invalid_2fa_token'], [$status, $code]);
        self::assertSame('Too many incorrect codes. Please sign in again.', $data['error']['message']);
        self::assertArrayNotHasKey(self::otpKey($c), $GLOBALS['__bcc_ph']['transients'], 'OTP burned');
        self::assertArrayNotHasKey('bcc_2fa_ct_' . $c, $GLOBALS['__bcc_ph']['transients'], 'challenge burned');

        // Even the right code no longer works — the user must sign in again.
        [$status, $code] = self::verify($c, self::RIGHT);
        self::assertSame([401, 'bcc_invalid_2fa_token'], [$status, $code]);
    }

    public function testANewLoginSupersedesTheEarlierChallenge(): void
    {
        $old = self::login('111111');
        $new = self::login('222222');

        self::assertNull(AuthSupport::peek2faChallenge($old), 'only the current challenge is live');
        self::assertArrayNotHasKey(self::otpKey($old), $GLOBALS['__bcc_ph']['transients'], "the old challenge's OTP is dropped");

        [$status, $code] = self::verify($old, '111111');
        self::assertSame([401, 'bcc_invalid_2fa_token'], [$status, $code], 'even its own right code is dead');

        [$status] = self::verify($new, '222222');
        self::assertSame(200, $status);
    }

    public function testAStaleChallengeCannotBurnAFreshOne(): void
    {
        $old = self::login('111111');
        $new = self::login(self::RIGHT);

        for ($i = 1; $i <= 6; $i++) {
            self::verify($old, '000000');   // hammer the superseded challenge
        }

        self::assertArrayHasKey(self::otpKey($new), $GLOBALS['__bcc_ph']['transients'], "the fresh challenge's OTP is untouched");
        [$status] = self::verify($new, self::RIGHT);
        self::assertSame(200, $status);
    }

    public function testTheCounterIsPerChallenge(): void
    {
        $a = self::login('111111');
        for ($i = 1; $i <= 4; $i++) {
            self::verify($a, '000000');
        }
        $b = self::login(self::RIGHT);
        for ($i = 1; $i <= 4; $i++) {
            [$status, $code] = self::verify($b, '000000');
            self::assertSame('bcc_invalid_2fa_code', $code, "B's wrong code #$i counts only toward B");
        }

        [$status] = self::verify($b, self::RIGHT);
        self::assertSame(200, $status);
    }

    public function testAResendDoesNotResetTheCounter(): void
    {
        $c = self::login();
        for ($i = 1; $i <= 3; $i++) {
            self::verify($c, '000000');
        }

        (new TwoFactorController())->twoFaResend(new \WP_REST_Request(['challenge_token' => $c]));
        self::assertNotEmpty($GLOBALS['__bcc_ph']['mail'], 'a new code was mailed');
        self::assertArrayHasKey(self::otpKey($c), $GLOBALS['__bcc_ph']['transients'], 're-bound to the same challenge');

        self::verify($c, '000000');                       // 4th
        [$status, $code] = self::verify($c, '000000');    // 5th → burn
        self::assertSame([401, 'bcc_invalid_2fa_token'], [$status, $code]);
    }

    public function testTheCounterBucketIsAnExplicitUserAndChallengeKey(): void
    {
        $c = self::login();

        self::verify($c, '000000');

        self::assertContains(
            '2fa_fail:' . self::USER . ':' . substr(hash('sha256', $c), 0, 32),
            $GLOBALS['__bcc_ph']['throttle_keys'],
            'keyed on (user, challenge) — never split by client IP'
        );
    }
}
