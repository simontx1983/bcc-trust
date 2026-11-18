<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\EmailVerificationController;
use BCC\Trust\Core\REST\Auth\WalletAuthController;
use BCC\Trust\Core\Services\AccountRecoveryService;
use BCC\Trust\Core\Services\PendingEmailService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-294 — a wallet sign-up's typed address no longer squats user_email.
 *
 * It used to go straight into user_email, unverified. user_email is UNIQUE,
 * so whoever typed an address first reserved it against its real owner, and
 * F-285's reclaim never touches an account with a wallet. Now user_email
 * keeps the wallet placeholder and the typed address is held PENDING
 * (PendingEmailService::META_PENDING_SIGNUP_EMAIL) until the inbox is
 * proven. Pinned against the REAL walletSignup, verifyEmail,
 * resendVerification and PendingEmailService:
 *   - sign-up writes the placeholder, holds the address pending and mails
 *     the code to it; a pending address is not a "taken" address;
 *   - the code (by that address) or the link applies it, marks it verified,
 *     and core's "Email Changed" mail (to the dead placeholder) stays off;
 *   - taken meanwhile → 409 and the pending address is dropped; a failed
 *     write keeps it for a retry;
 *   - two sign-ups holding it → the address resolves to nobody (the link
 *     still works);
 *   - resend by that address or by handle mails the pending address;
 *   - confirming a recovery email clears it.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/pending-email-stubs.php,
 * wallet-signup-stubs.php, wallet-login-throttle-stubs.php, then
 * account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WalletSignupPendingEmailTest extends TestCase
{
    private const ADDR  = '0xabcdef0000000000000000000000000000000001';
    private const TYPED = 'me@example.com';
    private const NEW   = 500; // the wallet-signup-stubs insert id
    private const OTHER = 3;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/pending-email-stubs.php';
        require_once __DIR__ . '/../Stubs/wallet-signup-stubs.php';
        require_once __DIR__ . '/../Stubs/wallet-login-throttle-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users' => [], 'meta' => [], 'transients' => [], 'mail' => [], 'actions' => [], 'options' => [],
            'audit' => [], 'canary' => [], 'core_email_notice' => [],
        ];
        $GLOBALS['__bcc_wl'] = [
            'challenges'  => [self::ADDR => [['chain_id' => 8, 'message' => 'msg-base']]],
            'valid'       => ['evm|msg-base|good-sig'],
            'consumed'    => [],
            'verified'    => [],
            'insert_ok'   => true,
            'link_result' => 900,
        ];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }

            /** @return null no wallet link, no other owner */
            public function get_var(string $sql)
            {
                return null;
            }

            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('unexpected $wpdb->' . $name);
            }
        };
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @param array<string, string> $extra */
    private static function signup(array $extra = ['email' => self::TYPED]): \WP_REST_Response
    {
        $req = new \WP_REST_Request(['wallet_address' => self::ADDR, 'signature' => 'good-sig', 'handle' => 'newhandle'] + $extra);
        return (new WalletAuthController())->walletSignup($req);
    }

    /** A wallet sign-up like the real one leaves it: placeholder in user_email, address pending. */
    private static function pendingAccount(int $id, string $pending): void
    {
        $GLOBALS['__bcc_ph']['users'][$id] = [
            'email' => 'wallet-' . str_repeat((string) ($id % 10), 16) . '@' . AccountRecoveryService::PLACEHOLDER_EMAIL_DOMAIN,
            'login' => 'u' . $id, 'display' => 'User ' . $id,
        ];
        $GLOBALS['__bcc_ph']['meta'][$id] = [
            'bcc_handle'                                    => 'handle' . $id,
            AuthSupport::META_EMAIL_VERIFIED                => '0',
            PendingEmailService::META_PENDING_SIGNUP_EMAIL => $pending,
        ];
    }

    private static function email(int $id): string
    {
        return $GLOBALS['__bcc_ph']['users'][$id]['email'];
    }

    private static function pending(int $id): ?string
    {
        $v = $GLOBALS['__bcc_ph']['meta'][$id][PendingEmailService::META_PENDING_SIGNUP_EMAIL] ?? null;
        return $v === null ? null : (string) $v;
    }

    private static function flag(int $id): string
    {
        return (string) ($GLOBALS['__bcc_ph']['meta'][$id][AuthSupport::META_EMAIL_VERIFIED] ?? '');
    }

    /** @return list<array{to: string, subject: string, html: string}> */
    private static function mailTo(string $to): array
    {
        return array_values(array_filter($GLOBALS['__bcc_ph']['mail'], static fn(array $m): bool => $m['to'] === $to));
    }

    private static function lastCodeTo(string $to): string
    {
        $mails = self::mailTo($to);
        self::assertNotEmpty($mails, 'a code was mailed to ' . $to);
        self::assertSame(1, preg_match('/>\s*(\d{6})\s*</', end($mails)['html'], $m));
        return $m[1];
    }

    /** The verify-link token stored for $userId (the one the mail links to). */
    private static function tokenFor(int $userId): string
    {
        foreach ($GLOBALS['__bcc_ph']['transients'] as $key => $value) {
            if (str_starts_with((string) $key, 'bcc_vt_') && (string) $value === (string) $userId) {
                return substr((string) $key, strlen('bcc_vt_'));
            }
        }
        self::fail('no verify token stored for user ' . $userId);
    }

    /** @param array<string, string> $params */
    private static function verify(array $params): \WP_REST_Response
    {
        return (new EmailVerificationController())->verifyEmail(new \WP_REST_Request($params));
    }

    /** @param array<string, string> $params */
    private static function resend(array $params): int
    {
        return (new EmailVerificationController())->resendVerification(new \WP_REST_Request($params))->get_status();
    }

    private static function errorCode(\WP_REST_Response $res): ?string
    {
        $data = $res->get_data();
        return is_array($data) ? ($data['error']['code'] ?? null) : null;
    }

    // ── sign-up ─────────────────────────────────────────────────────────

    public function testSignupKeepsThePlaceholderAndHoldsTheTypedAddressPending(): void
    {
        self::assertSame(201, self::signup()->get_status());

        $inserted = $GLOBALS['__bcc_wl']['inserted_email'][0] ?? '';
        self::assertTrue(AccountRecoveryService::isPlaceholderEmail($inserted), 'user_email is the wallet placeholder');
        self::assertSame($inserted, self::email(self::NEW));
        self::assertSame(self::TYPED, self::pending(self::NEW), 'the typed address is held pending');
        self::assertSame('0', self::flag(self::NEW));
        self::assertCount(1, self::mailTo(self::TYPED), 'the code and link go to the typed address');
        self::assertCount(1, $GLOBALS['__bcc_ph']['mail']);
    }

    public function testSignupWithoutAnAddressHoldsNothingPendingAndMailsNothing(): void
    {
        self::assertSame(201, self::signup([])->get_status());

        self::assertTrue(AccountRecoveryService::isPlaceholderEmail(self::email(self::NEW)));
        self::assertNull(self::pending(self::NEW));
        self::assertSame([], $GLOBALS['__bcc_ph']['mail']);
    }

    public function testAnAddressAnotherSignupHoldsPendingIsNotTaken(): void
    {
        self::pendingAccount(self::OTHER, self::TYPED);

        self::assertSame(201, self::signup()->get_status(), 'pending is not a reservation');
        self::assertSame(self::TYPED, self::pending(self::NEW));
    }

    // ── verify ──────────────────────────────────────────────────────────

    public function testTheCodeByThatAddressAppliesIt(): void
    {
        self::signup();

        $res = self::verify(['email' => self::TYPED, 'code' => self::lastCodeTo(self::TYPED)]);

        self::assertSame(200, $res->get_status());
        self::assertSame(self::TYPED, self::email(self::NEW), 'now the account address');
        self::assertNull(self::pending(self::NEW));
        self::assertSame('1', self::flag(self::NEW));
        self::assertSame([false], $GLOBALS['__bcc_ph']['core_email_notice'], "core's mail to the placeholder is off");
    }

    public function testTheLinkAppliesIt(): void
    {
        self::signup();

        $res = self::verify(['token' => self::tokenFor(self::NEW)]);

        self::assertSame(200, $res->get_status());
        self::assertSame(self::TYPED, self::email(self::NEW));
        self::assertNull(self::pending(self::NEW));
        self::assertSame('1', self::flag(self::NEW));
    }

    public function testAnAddressTakenMeanwhileIs409AndDropsThePending(): void
    {
        self::signup();
        $placeholder = self::email(self::NEW);
        $token       = self::tokenFor(self::NEW);
        $code        = self::lastCodeTo(self::TYPED);
        // Its real owner signs up with a password before the link is used.
        $GLOBALS['__bcc_ph']['users'][self::OTHER] = ['email' => self::TYPED, 'login' => 'u3', 'display' => 'Three'];

        $byCode = self::verify(['email' => self::TYPED, 'code' => $code]);
        self::assertSame(400, $byCode->get_status(), 'the address is the owner\'s now: the squatter\'s code does not match it');
        self::assertSame('bcc_invalid_otp', self::errorCode($byCode));

        $res = self::verify(['token' => $token]);

        self::assertSame(409, $res->get_status());
        self::assertSame('bcc_conflict', self::errorCode($res));
        self::assertSame('An account with that email already exists.', $res->get_data()['error']['message'] ?? null);
        self::assertSame($placeholder, self::email(self::NEW), 'nothing applied');
        self::assertNull(self::pending(self::NEW), 'the pending address is dropped');
        self::assertSame('0', self::flag(self::NEW), 'not verified');
    }

    public function testAFailedWriteKeepsThePendingForARetry(): void
    {
        self::signup();
        $placeholder = self::email(self::NEW);
        $GLOBALS['__bcc_ph']['update_fails'] = true;

        $res = self::verify(['token' => self::tokenFor(self::NEW)]);

        self::assertSame(500, $res->get_status());
        self::assertSame('bcc_internal_error', self::errorCode($res));
        self::assertSame($placeholder, self::email(self::NEW));
        self::assertSame(self::TYPED, self::pending(self::NEW), 'kept for a retry');
        self::assertSame('0', self::flag(self::NEW));
    }

    public function testAnAddressTwoSignupsHoldPendingResolvesToNobody(): void
    {
        self::pendingAccount(self::OTHER, self::TYPED);
        self::signup();
        $mailsBefore = count($GLOBALS['__bcc_ph']['mail']);

        $byCode = self::verify(['email' => self::TYPED, 'code' => self::lastCodeTo(self::TYPED)]);
        self::assertSame(400, $byCode->get_status(), 'ambiguous = nobody');
        self::assertSame('bcc_invalid_otp', self::errorCode($byCode));

        self::assertSame(200, self::resend(['email' => self::TYPED]));
        self::assertCount($mailsBefore, $GLOBALS['__bcc_ph']['mail'], 'resend to an ambiguous address mails no one');

        // The link names its account, so it still works.
        self::assertSame(200, self::verify(['token' => self::tokenFor(self::NEW)])->get_status());
        self::assertSame(self::TYPED, self::email(self::NEW));
    }

    public function testARealAddressIsNeverReplacedByAStalePending(): void
    {
        $GLOBALS['__bcc_ph']['users'][self::OTHER] = ['email' => 'real@example.com', 'login' => 'u3', 'display' => 'Three'];
        $GLOBALS['__bcc_ph']['meta'][self::OTHER]  = [PendingEmailService::META_PENDING_SIGNUP_EMAIL => self::TYPED];

        self::assertSame(PendingEmailService::SIGNUP_EMAIL_NONE, PendingEmailService::applyPendingSignupEmail(self::OTHER));
        self::assertSame('real@example.com', self::email(self::OTHER));
        self::assertNull(self::pending(self::OTHER), 'the stale pending address is cleared');
    }

    // ── resend ──────────────────────────────────────────────────────────

    public function testResendByThatAddressMailsIt(): void
    {
        self::signup();

        self::assertSame(200, self::resend(['email' => self::TYPED]));

        self::assertCount(2, self::mailTo(self::TYPED));
        self::assertCount(2, $GLOBALS['__bcc_ph']['mail']);
    }

    public function testResendByHandleMailsThePendingAddressNotThePlaceholder(): void
    {
        self::signup();

        self::assertSame(200, self::resend(['identifier' => '@newhandle']));

        self::assertCount(2, self::mailTo(self::TYPED));
        self::assertCount(2, $GLOBALS['__bcc_ph']['mail'], 'nothing to the placeholder');
    }

    // ── recovery email ──────────────────────────────────────────────────

    public function testConfirmingARecoveryEmailClearsThePending(): void
    {
        self::pendingAccount(self::OTHER, self::TYPED);
        $otp = PendingEmailService::stage(self::OTHER, 'recovery@example.com', PendingEmailService::PURPOSE_RECOVERY);

        $out = PendingEmailService::confirm(self::OTHER, $otp, PendingEmailService::PURPOSE_RECOVERY);

        self::assertTrue($out['ok']);
        self::assertSame('recovery@example.com', self::email(self::OTHER));
        self::assertNull(self::pending(self::OTHER));
    }
}
