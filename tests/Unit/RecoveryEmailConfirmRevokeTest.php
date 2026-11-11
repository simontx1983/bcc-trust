<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\RecoveryEmailEndpoint;
use BCC\Trust\Core\Services\PendingEmailService;
use BCC\Trust\Core\Support\JwtToken;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-118 (the remaining path): confirming a recovery email changes the
 * account's email credential, so every earlier Bearer token must die and the
 * caller gets a fresh one — the same contract as the email-change confirm
 * (MyAccountEndpoint::verifyEmailChange) and the password change.
 *
 * Runs the REAL RecoveryEmailEndpoint::verifyRecoveryEmail + PendingEmailService
 * + JwtToken.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/pending-email-stubs.php, then
 * tests/Stubs/account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RecoveryEmailConfirmRevokeTest extends TestCase
{
    private const USER        = 50;
    private const PLACEHOLDER = 'wallet-0123456789abcdef@noreply.bcc.local';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/pending-email-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';

        $GLOBALS['__bcc_ph'] = [
            'users'        => [self::USER => ['email' => self::PLACEHOLDER, 'login' => 'u50', 'display' => 'U50']],
            'meta'         => [self::USER => ['bcc_handle' => 'handle50', AuthSupport::META_EMAIL_VERIFIED => '0']],
            'transients'   => [],
            'mail'         => [],
            'actions'      => [],
            'options'      => [],
            'audit'        => [],
            'canary'       => [],
            'current_user' => self::USER,
        ];
    }

    private static function decodeError(string $token): ?string
    {
        $r = JwtToken::decode($token);
        return $r['ok'] ? null : $r['error'];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function confirm(string $code): array
    {
        $res  = (new RecoveryEmailEndpoint())->verifyRecoveryEmail(new \WP_REST_Request(['code' => $code]));
        $data = $res->get_data();
        self::assertIsArray($data);
        return [$res->get_status(), $data['data'] ?? $data];
    }

    public function testConfirmRevokesEveryEarlierTokenAndReturnsAFreshOne(): void
    {
        $before = JwtToken::encode(self::USER, 'handle50');
        self::assertNull(self::decodeError($before), 'control: valid before the confirm');
        $otp = PendingEmailService::stage(self::USER, 'me@example.com', PendingEmailService::PURPOSE_RECOVERY);

        [$status, $data] = self::confirm($otp);

        self::assertSame(200, $status);
        self::assertSame('me@example.com', $data['email']);
        self::assertSame(JwtToken::ERR_REVOKED, self::decodeError($before), 'a pre-confirm session is dead');
        self::assertIsString($data['token'] ?? null);
        self::assertNull(self::decodeError((string) $data['token']), "the caller's fresh token works");
        self::assertSame(JwtToken::TTL_SECONDS, $data['expires_in'] ?? null);
        self::assertSame('Bearer', $data['token_type'] ?? null);
        // Existing behaviour unchanged: promoted + verified, canary, audit.
        self::assertSame('me@example.com', $GLOBALS['__bcc_ph']['users'][self::USER]['email']);
        self::assertSame('1', $GLOBALS['__bcc_ph']['meta'][self::USER][AuthSupport::META_EMAIL_VERIFIED]);
        self::assertSame(['recoveryEmailConfirmed:me@example.com'], $GLOBALS['__bcc_ph']['canary']);
        self::assertContains('recovery_email_confirmed', $GLOBALS['__bcc_ph']['audit']);
    }

    public function testAWrongCodeRevokesNothingAndReturnsNoToken(): void
    {
        $session = JwtToken::encode(self::USER, 'handle50');
        $otp     = PendingEmailService::stage(self::USER, 'me@example.com', PendingEmailService::PURPOSE_RECOVERY);

        [$status, $body] = self::confirm($otp === '000000' ? '111111' : '000000');

        self::assertSame(422, $status);
        self::assertArrayNotHasKey('token', $body);
        self::assertNull(self::decodeError($session), 'a failed confirm must not sign anyone out');
        self::assertSame(self::PLACEHOLDER, $GLOBALS['__bcc_ph']['users'][self::USER]['email']);
    }

    public function testNothingPendingRevokesNothing(): void
    {
        $session = JwtToken::encode(self::USER, 'handle50');

        [$status] = self::confirm('123456');

        self::assertSame(400, $status);
        self::assertNull(self::decodeError($session));
    }
}
