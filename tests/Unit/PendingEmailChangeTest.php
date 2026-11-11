<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\OAuthController;
use BCC\Trust\Core\REST\MyAccountEndpoint;
use BCC\Trust\Core\Services\AuthMailer;
use BCC\Trust\Core\Services\PendingEmailService;
use BCC\Trust\Core\Support\JwtToken;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Email change is verify-before-promote (pre-hijack via PATCH /me/account/email).
 *
 * The hole: an attacker verifies their OWN address ('1'), then PATCHes the
 * account email to victim@gmail.com with only their own password. The change
 * applied at once and the '1' stayed, so the victim's later Google sign-in
 * linked into the attacker's account (revocation does not help — the attacker
 * knows the password).
 *
 * Pinned, running the REAL MyAccountEndpoint, OAuthController::oauthLogin and
 * PendingEmailService:
 *   - PATCH holds the new address PENDING: user_email and the flag unchanged,
 *     OTP mailed to the NEW address;
 *   - oauthLogin never matches a PENDING address (the attack ends with no link);
 *   - POST /me/account/email/verify applies it (verified '1'), revokes every
 *     earlier token, returns a fresh one, and sends the both-address canary;
 *   - expiry / abandonment leaves the old address; a wrong code keeps the
 *     pending entry; a new request supersedes the old; a conflict aborts;
 *   - recovery-email and email-change codes cannot complete each other.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/pending-email-stubs.php, then
 * tests/Stubs/account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PendingEmailChangeTest extends TestCase
{
    private const USER     = 40;
    private const OTHER    = 41;
    private const PASSWORD = 'correct horse battery staple';
    private const OLD      = 'me@example.com';
    private const NEW      = 'victim@gmail.com';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/pending-email-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';

        $GLOBALS['__bcc_ph'] = [
            'users'        => [],
            'meta'         => [],
            'transients'   => [],
            'mail'         => [],
            'actions'      => [],
            'options'      => [],
            'audit'        => [],
            'canary'       => [],
            'current_user' => self::USER,
            'passwords'    => [self::USER => self::PASSWORD],
        ];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('no database in unit tests');
            }
        };

        // The caller's account: their OWN address, proven ('1').
        $this->user(self::USER, self::OLD, '1');
    }

    private function user(int $id, string $email, ?string $flag): void
    {
        $GLOBALS['__bcc_ph']['users'][$id] = ['email' => $email, 'login' => 'u' . $id, 'display' => 'User ' . $id];
        $GLOBALS['__bcc_ph']['meta'][$id]  = ['bcc_handle' => 'handle' . $id];
        if ($flag !== null) {
            $GLOBALS['__bcc_ph']['meta'][$id][AuthSupport::META_EMAIL_VERIFIED] = $flag;
        }
    }

    private static function email(int $id): string
    {
        return $GLOBALS['__bcc_ph']['users'][$id]['email'];
    }

    private static function flag(int $id): string
    {
        return (string) ($GLOBALS['__bcc_ph']['meta'][$id][AuthSupport::META_EMAIL_VERIFIED] ?? '');
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function patch(string $email, string $password = self::PASSWORD): array
    {
        $res  = (new MyAccountEndpoint())->patchEmail(new \WP_REST_Request(['email' => $email, 'current_password' => $password]));
        $data = $res->get_data();
        self::assertIsArray($data);
        return [$res->get_status(), $data['data'] ?? $data];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function verify(string $code): array
    {
        $res  = (new MyAccountEndpoint())->verifyEmailChange(new \WP_REST_Request(['code' => $code]));
        $data = $res->get_data();
        self::assertIsArray($data);
        return [$res->get_status(), $data['data'] ?? $data];
    }

    private static function lastCodeTo(string $to): string
    {
        $mails = array_values(array_filter($GLOBALS['__bcc_ph']['mail'], static fn(array $m): bool => $m['to'] === $to));
        self::assertNotEmpty($mails, 'a code was mailed to ' . $to);
        $html = end($mails)['html'];
        self::assertSame(1, preg_match('/>\s*(\d{6})\s*</', $html, $m));
        return $m[1];
    }

    /** @return array<string, mixed> */
    private static function googleSignIn(string $email): array
    {
        // A Google email the provider VERIFIED — so only the pending gate
        // stands between it and the account (the case must not pass
        // vacuously on a missing verified bit).
        $params = ['provider' => 'google', 'provider_id' => 'google-sub-' . md5($email), 'email' => $email, 'display_name' => 'G', 'email_verified' => true];
        $req    = new \WP_REST_Request($params);
        $ts     = (string) time();
        $nonce  = bin2hex(random_bytes(8));
        $req->set_header('x-bcc-oauth-timestamp', $ts);
        $req->set_header('x-bcc-oauth-nonce', $nonce);
        $req->set_header('x-bcc-oauth-signature', hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $req->get_body(), (string) BCC_OAUTH_BRIDGE_SECRET));
        $res = (new OAuthController())->oauthLogin($req);
        self::assertSame(200, $res->get_status());
        $data = $res->get_data();
        self::assertIsArray($data);
        return $data['data'];
    }

    private static function decodeError(string $token): ?string
    {
        $r = JwtToken::decode($token);
        return $r['ok'] ? null : $r['error'];
    }

    // ── request: nothing applies before the code ────────────────────────

    public function testPatchHoldsTheNewAddressPendingAndMailsTheNewInbox(): void
    {
        [$status, $data] = self::patch(self::NEW);

        self::assertSame(200, $status);
        self::assertTrue($data['pending']);
        self::assertSame('v*****@gmail.com', $data['email_masked']);
        self::assertSame(PendingEmailService::PENDING_TTL, $data['expires_in']);
        self::assertArrayNotHasKey('email', $data, 'the response must not claim the change happened');

        self::assertSame(self::OLD, self::email(self::USER), 'user_email is NOT replaced before the OTP');
        self::assertSame('1', self::flag(self::USER), 'the old, proven address keeps its state');
        self::lastCodeTo(self::NEW);
        self::assertSame([], $GLOBALS['__bcc_ph']['canary'], 'canary goes out on confirmation');
        self::assertContains('account_email_change_requested', $GLOBALS['__bcc_ph']['audit']);
        self::assertNotContains('account_email_changed', $GLOBALS['__bcc_ph']['audit']);
    }

    public function testTheH1AttackEndsWithNoLinkAndNoToken(): void
    {
        // Attacker: own verified account, re-points it at the victim's address.
        self::patch(self::NEW);

        // Victim's first Google sign-in with that address.
        $data = self::googleSignIn(self::NEW);

        self::assertArrayNotHasKey('token', $data, 'oauthLogin never matches a PENDING address');
        self::assertSame('handle_required', $data['status'] ?? null);
        self::assertSame('', (string) ($GLOBALS['__bcc_ph']['meta'][self::USER][AuthSupport::META_OAUTH_PREFIX . 'google'] ?? ''));

        // Control: the account's CURRENT, proven address still links — so the
        // miss above is the pending gate, not a sign-in that can't link at all.
        self::assertIsString(self::googleSignIn(self::OLD)['token'] ?? null);
    }

    public function testWrongPasswordStagesNothing(): void
    {
        [$status] = self::patch(self::NEW, 'wrong');

        self::assertSame(422, $status);
        self::assertSame([], $GLOBALS['__bcc_ph']['mail']);
        self::assertNull(PendingEmailService::pendingEmail(self::USER, PendingEmailService::PURPOSE_CHANGE));
    }

    public function testTheSameAddressIsANoOp(): void
    {
        [$status, $data] = self::patch(strtoupper(self::OLD));

        self::assertSame(200, $status);
        self::assertFalse($data['pending']);
        self::assertSame([], $GLOBALS['__bcc_ph']['mail']);
    }

    // ── confirm ─────────────────────────────────────────────────────────

    public function testTheCodeAppliesTheChangeRevokesEarlierTokensAndReturnsAFreshOne(): void
    {
        $before = JwtToken::encode(self::USER, 'handle' . self::USER);
        self::patch('new@example.com');

        [$status, $data] = self::verify(self::lastCodeTo('new@example.com'));

        self::assertSame(200, $status);
        self::assertSame('new@example.com', self::email(self::USER));
        self::assertSame('1', self::flag(self::USER), 'the new address is proven');
        self::assertSame('new@example.com', $data['email']);
        self::assertSame(JwtToken::ERR_REVOKED, self::decodeError($before), 'a pre-change token is dead');
        self::assertNull(self::decodeError((string) $data['token']), "the caller's fresh token works");
        self::assertSame('Bearer', $data['token_type']);
        self::assertSame(['emailChanged:' . self::OLD . '>new@example.com'], $GLOBALS['__bcc_ph']['canary']);
        self::assertContains('account_email_changed', $GLOBALS['__bcc_ph']['audit']);
        self::assertNull(PendingEmailService::pendingEmail(self::USER, PendingEmailService::PURPOSE_CHANGE));
    }

    public function testConfirmingMarksTheNewAddressVerifiedEvenFromUnverified(): void
    {
        // An account whose current address was never proven ('0').
        $GLOBALS['__bcc_ph']['meta'][self::USER][AuthSupport::META_EMAIL_VERIFIED] = '0';
        self::patch('new@example.com');
        self::assertSame('0', self::flag(self::USER), 'still unproven while pending');

        [$status] = self::verify(self::lastCodeTo('new@example.com'));

        self::assertSame(200, $status);
        self::assertSame('1', self::flag(self::USER), 'the OTP proved the new address');
    }

    public function testExpiryLeavesTheOldAddress(): void
    {
        self::patch(self::NEW);
        $code = self::lastCodeTo(self::NEW);
        unset($GLOBALS['__bcc_ph']['transients'][PendingEmailService::PENDING_PREFIX . self::USER]); // TTL elapsed

        [$status, $body] = self::verify($code);

        self::assertSame(400, $status);
        self::assertSame('bcc_invalid_request', $body['error']['code'] ?? null, 'nothing pending: "start again"');
        self::assertSame(self::OLD, self::email(self::USER));
        self::assertSame('1', self::flag(self::USER));
    }

    public function testAWrongCodeKeepsThePendingEntryForARetry(): void
    {
        self::patch('new@example.com');
        $code  = self::lastCodeTo('new@example.com');
        $wrong = $code === '000000' ? '111111' : '000000';

        [$status, $body] = self::verify($wrong);
        self::assertSame(422, $status);
        self::assertSame('bcc_invalid_otp', $body['error']['code'] ?? null, 'a wrong code has its own code: "stay on the code form"');
        self::assertSame(self::OLD, self::email(self::USER));

        [$status] = self::verify($code);
        self::assertSame(200, $status);
        self::assertSame('new@example.com', self::email(self::USER));
    }

    public function testANewRequestSupersedesTheOldOne(): void
    {
        self::patch('first@example.com');
        $first = self::lastCodeTo('first@example.com');
        self::patch('second@example.com');
        $second = self::lastCodeTo('second@example.com');

        if ($first !== $second) {
            [$status] = self::verify($first);
            self::assertSame(422, $status, "the superseded request's code no longer works");
        }
        [$status] = self::verify($second);

        self::assertSame(200, $status);
        self::assertSame('second@example.com', self::email(self::USER));
    }

    public function testAnAddressTakenMeanwhileAbortsTheChange(): void
    {
        self::patch('new@example.com');
        $code = self::lastCodeTo('new@example.com');
        $this->user(self::OTHER, 'new@example.com', '1');   // someone else got it first

        [$status] = self::verify($code);

        self::assertSame(409, $status);
        self::assertSame(self::OLD, self::email(self::USER));
    }

    // ── shared service: purposes cannot cross ───────────────────────────

    public function testRecoveryAndChangeCodesCannotCompleteEachOther(): void
    {
        $otp = PendingEmailService::stage(self::USER, 'r@example.com', PendingEmailService::PURPOSE_RECOVERY);

        [$status] = self::verify($otp);
        self::assertSame(400, $status, 'an email-change confirm ignores a recovery pending entry');
        self::assertSame(self::OLD, self::email(self::USER));

        $r = PendingEmailService::confirm(self::USER, $otp, PendingEmailService::PURPOSE_RECOVERY);
        self::assertTrue($r['ok']);
        self::assertSame('r@example.com', self::email(self::USER));
    }

    public function testAPreExistingRecoveryEntryWithoutPurposeStillConfirms(): void
    {
        // Shape written by RecoveryEmailEndpoint before this change (in flight at deploy).
        $GLOBALS['__bcc_ph']['transients'][PendingEmailService::PENDING_PREFIX . self::USER] = [
            'email'    => 'legacy@example.com',
            'otp_hash' => hash_hmac('sha256', '424242', 'test-salt-prehijack'),
        ];

        self::assertFalse(PendingEmailService::confirm(self::USER, '424242', PendingEmailService::PURPOSE_CHANGE)['ok']);
        $r = PendingEmailService::confirm(self::USER, '424242', PendingEmailService::PURPOSE_RECOVERY);

        self::assertTrue($r['ok']);
        self::assertSame('legacy@example.com', self::email(self::USER));
    }

    // ── mail copy ───────────────────────────────────────────────────────

    public function testTheChangeCodeMailTellsANonOwnerToIgnoreIt(): void
    {
        AuthMailer::sendEmailChangeOtp('new@example.com', '123456');
        $mail = end($GLOBALS['__bcc_ph']['mail']);

        self::assertSame('[BCC] Confirm your new email address', $mail['subject']);
        self::assertStringContainsString('If you didn&rsquo;t ask to change your BCC email to this address, ignore this email.', $mail['html']);
        self::assertStringContainsString('the account keeps its current address', $mail['html']);
        self::assertStringContainsString('>123456<', $mail['html']);
    }

    public function testTheRecoveryCodeMailIsUnchanged(): void
    {
        AuthMailer::sendRecoveryEmailOtp('r@example.com', '654321');
        $mail = end($GLOBALS['__bcc_ph']['mail']);

        self::assertSame('[BCC] Confirm your recovery email', $mail['subject']);
        self::assertStringContainsString('<title>Confirm your recovery email &mdash; BCC</title>', $mail['html']);
        self::assertStringContainsString("Enter this code in the app to set this address as your account-recovery\n                    email. It lets you regain access if you ever lose your wallet.", str_replace("\r\n", "\n", $mail['html']));
        self::assertStringContainsString('If you didn&rsquo;t request a recovery email on your BCC account,', $mail['html']);
        self::assertStringContainsString('>654321<', $mail['html']);
    }
}
