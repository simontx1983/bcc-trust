<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\TwoFactorController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-292: POST /auth/2fa/resend.
 *
 *  1. An ENDED challenge (expired, superseded, burned) answered ok=true and
 *     sent nothing. It now answers bcc_invalid_2fa_token 401, like verify.
 *  2. A LATE resend mailed a 5-minute code into a challenge whose 10-minute
 *     timer was never extended, so that code failed with "session expired".
 *     A resend now re-arms the challenge for one code lifetime, up to an
 *     absolute 30-minute age.
 *  3. Re-arming must not buy guesses: the wrong-code counter lasts the
 *     challenge's whole possible life, so the total stays 5 however many
 *     resends happen.
 *
 * Runs on a clock (two-factor-clock-stubs.php): transients expire and the
 * limiter's sliding window decays, exactly the behaviours this fix is about.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(TwoFactorController::class)]
#[CoversClass(AuthSupport::class)]
final class TwoFactorResendTest extends TestCase
{
    private const USER  = 61;
    private const RIGHT = '424242';
    private const WRONG = '000000';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/two-factor-clock-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        \TwoFaClock::reset();
        $GLOBALS['__bcc_ph'] = [
            'users'      => [self::USER => ['email' => 'u61@example.com', 'login' => 'u61', 'display' => 'U61']],
            'meta'       => [self::USER => ['bcc_handle' => 'handle61']],
            'transients' => [],
            'mail'       => [],
            'actions'    => [],
            'options'    => [],
        ];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('no database in unit tests');
            }
        };
    }

    private static function login(string $code = self::RIGHT): string
    {
        $token = bin2hex(random_bytes(32));
        AuthSupport::store2faChallenge($token, self::USER);
        AuthSupport::store2faOtp($token, $code);
        return $token;
    }

    /** @return array{int, ?string} */
    private static function resend(string $challenge): array
    {
        $res  = (new TwoFactorController())->twoFaResend(new \WP_REST_Request(['challenge_token' => $challenge]));
        $data = $res->get_data();
        self::assertIsArray($data);
        return [$res->get_status(), $data['error']['code'] ?? null];
    }

    /** @return array{int, ?string, string} */
    private static function verify(string $challenge, string $code): array
    {
        $res  = (new TwoFactorController())->twoFaVerify(new \WP_REST_Request(['challenge_token' => $challenge, 'code' => $code]));
        $data = $res->get_data();
        self::assertIsArray($data);
        return [$res->get_status(), $data['error']['code'] ?? null, (string) ($data['error']['message'] ?? '')];
    }

    /** The 6-digit code in the most recent mail. */
    private static function lastMailedCode(): string
    {
        $mail = end($GLOBALS['__bcc_ph']['mail']);
        self::assertIsArray($mail, 'a code was mailed');
        self::assertSame(1, preg_match('/\b(\d{6})\b/', (string) $mail['html'], $m), 'the mail carries a 6-digit code');
        return $m[1];
    }

    // ── 1. an ended challenge is an error, not ok ───────────────────────

    public function testAResendOnAnExpiredChallengeIs401AndSendsNothing(): void
    {
        $c = self::login();
        \TwoFaClock::advance(AuthSupport::TWO_FA_CHALLENGE_TTL);

        $this->assertSame([401, 'bcc_invalid_2fa_token'], self::resend($c));
        $this->assertSame([], $GLOBALS['__bcc_ph']['mail']);
    }

    public function testAResendOnASupersededChallengeIs401(): void
    {
        $old = self::login();
        self::login();

        $this->assertSame([401, 'bcc_invalid_2fa_token'], self::resend($old));
        $this->assertSame([], $GLOBALS['__bcc_ph']['mail']);
    }

    public function testAResendOnABurnedChallengeIs401(): void
    {
        $c = self::login();
        for ($i = 1; $i <= AuthSupport::TWO_FA_MAX_FAILURES; $i++) {
            self::verify($c, self::WRONG);
        }

        $this->assertSame([401, 'bcc_invalid_2fa_token'], self::resend($c));
    }

    public function testAResendOnALiveChallengeSendsACodeThatVerifies(): void
    {
        $c = self::login('111111');

        $this->assertSame([200, null], self::resend($c));
        [$status] = self::verify($c, self::lastMailedCode());
        $this->assertSame(200, $status);
    }

    // ── 2. a late resend's code works ───────────────────────────────────

    public function testALateResendsCodeVerifiesAfterTheOriginalTimerWouldHaveEnded(): void
    {
        $c = self::login('111111');
        \TwoFaClock::advance(AuthSupport::TWO_FA_CHALLENGE_TTL - 10);   // 9:50 in

        $this->assertSame([200, null], self::resend($c));
        $code = self::lastMailedCode();
        \TwoFaClock::advance(120);                                       // 11:50 — past the original 10 min

        [$status, $err] = self::verify($c, $code);
        $this->assertSame(200, $status, 'the resent code must be usable, got ' . (string) $err);
    }

    public function testAResendAfterThirtyMinutesIs401(): void
    {
        $c = self::login();
        // Keep the challenge alive with resends every 4 minutes up to 31 min.
        for ($t = 240; $t < AuthSupport::TWO_FA_CHALLENGE_MAX_AGE; $t += 240) {
            \TwoFaClock::advance(240);
            $this->assertSame([200, null], self::resend($c), "resend at {$t}s");
        }
        \TwoFaClock::advance((AuthSupport::TWO_FA_CHALLENGE_MAX_AGE + 60) - \intdiv(AuthSupport::TWO_FA_CHALLENGE_MAX_AGE, 240) * 240);

        $mailsBefore = count($GLOBALS['__bcc_ph']['mail']);
        $this->assertSame([401, 'bcc_invalid_2fa_token'], self::resend($c), 'past the absolute lifetime');
        $this->assertCount($mailsBefore, $GLOBALS['__bcc_ph']['mail'], 'nothing sent');
    }

    // ── 3. resends never buy extra guesses ──────────────────────────────

    /**
     * 4 wrong codes early, then resends keep the challenge alive for ~18
     * minutes. With the counter's window equal to the old 10-minute TTL,
     * those 4 would have faded by now and the 5th wrong code would be
     * "incorrect" (a refilled guess). It must burn instead.
     */
    public function testFiveWrongCodesAcrossTwoResendsBurnTheChallenge(): void
    {
        $c = self::login();
        \TwoFaClock::advance(60);
        for ($i = 1; $i <= AuthSupport::TWO_FA_MAX_FAILURES - 1; $i++) {
            [, $err] = self::verify($c, self::WRONG);
            $this->assertSame('bcc_invalid_2fa_code', $err, "wrong code #$i");
        }

        \TwoFaClock::advance(440);                                       // 8:20
        $this->assertSame([200, null], self::resend($c));
        \TwoFaClock::advance(240);                                       // 12:20
        $this->assertSame([200, null], self::resend($c));
        \TwoFaClock::advance(240);                                       // 16:20

        [$status, $err, $msg] = self::verify($c, self::WRONG);
        $this->assertSame([401, 'bcc_invalid_2fa_token'], [$status, $err], 'the 5th wrong code burns, resends or not');
        $this->assertSame('Too many incorrect codes. Please sign in again.', $msg);
        $this->assertSame([401, 'bcc_invalid_2fa_token'], self::resend($c), 'and the burned challenge cannot be resent');
    }

    public function testTheRateLimitStillRunsFirst(): void
    {
        $c = self::login();
        for ($i = 1; $i <= AuthSupport::TWO_FA_RESEND_RATE_LIMIT; $i++) {
            self::resend($c);
        }

        [$status, $err] = self::resend('not-a-real-token');
        $this->assertSame([429, 'bcc_rate_limited'], [$status, $err]);
    }
}
