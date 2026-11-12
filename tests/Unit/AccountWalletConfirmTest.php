<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Core\Crypto\WalletChallenge;
use BCC\Core\Wallet\WalletIdentityService;
use BCC\Trust\Core\REST\AccountCredentialCheck;
use BCC\Trust\Core\REST\Auth\WalletAuthController;
use BCC\Trust\Core\REST\MyAccountEndpoint;
use BCC\Trust\Core\REST\RecoveryEmailEndpoint;
use BCC\Trust\Core\Services\AccountRecoveryService;
use BCC\Trust\Core\Services\PendingEmailService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-253 (user decision 2026-11-12): a member confirms an email change, a
 * password change or an account delete with their current password OR a
 * signature from one of their own verified wallets. Wallet-only members
 * never had a password, so before this they could do none of the three.
 *
 * Pinned, running the REAL MyAccountEndpoint, RecoveryEmailEndpoint,
 * WalletAuthController::nonce and AccountCredentialCheck over bcc-core's
 * wallet seam (tests/Stubs/wallet-confirm-stubs.php; the purpose-binding of
 * the real consume is pinned in core's WalletChallengePurposeTest):
 *   - each change accepts a signature over a challenge issued for THAT
 *     change, and asks core to consume it with that purpose;
 *   - a challenge for another change (or plain wallet verify) is refused;
 *   - a foreign or unverified wallet is refused before the challenge is
 *     spent; a bad signature is refused after;
 *   - cheap input errors are answered before the challenge is spent;
 *   - the password path is unchanged; neither credential is a clear 422;
 *   - the audit row records which credential confirmed;
 *   - GET /auth/nonce issues per purpose, defaults to verify, refuses an
 *     unknown one;
 *   - recovery-email refuses once the account has a real email (#16);
 *   - has_password: '1' / '0' / placeholder email / unknown.
 *
 * Subprocess-only; setUp() loads the stubs before anything autoloads.
 */
#[CoversClass(AccountCredentialCheck::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AccountWalletConfirmTest extends TestCase
{
    private const USER     = 70;
    private const OTHER    = 71;
    private const PASSWORD = 'correct horse battery staple';
    private const WALLET   = '0x00000000000000000000000000000000000000aa';
    private const FOREIGN  = '0x00000000000000000000000000000000000000bb';
    private const UNVERIFIED = '0x00000000000000000000000000000000000000cc';
    private const PLACEHOLDER = 'wallet-abc@noreply.bcc.local';

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->name() === 'testAnOlderCoreRefusesTheWalletPathButThePasswordPathStillWorks') {
            // bcc-core from before F-253: WalletChallenge has no purposes.
            require_once __DIR__ . '/../Stubs/wallet-challenge-pre-f253-stub.php';
        }
        require_once __DIR__ . '/../Stubs/pending-email-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        require_once __DIR__ . '/../Stubs/wallet-confirm-stubs.php';

        $GLOBALS['__bcc_ph'] = [
            'users'        => [],
            'meta'         => [],
            'transients'   => [],
            'mail'         => [],
            'actions'      => [],
            'options'      => [],
            'audit'        => [],
            'audit_meta'   => [],
            'canary'       => [],
            'current_user' => self::USER,
            // The wallet-only member's random password: they never saw it.
            'passwords'    => [self::USER => 'random-never-shown'],
        ];
        $GLOBALS['__bcc_wc'] = [
            'challenges' => [],
            'consumed'   => [],
            'issued'     => [],
            'deleted'    => [],
            'links'      => [
                ['id' => 1, 'user_id' => self::USER, 'chain_id' => 1, 'address' => self::WALLET, 'verified' => true],
                ['id' => 2, 'user_id' => self::OTHER, 'chain_id' => 1, 'address' => self::FOREIGN, 'verified' => true],
                ['id' => 3, 'user_id' => self::USER, 'chain_id' => 1, 'address' => self::UNVERIFIED, 'verified' => false],
            ],
        ];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /** Push-subscription cleanup on a password change. */
            public function delete(string $table, array $where, array $format = []): int
            {
                return 0;
            }

            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('unexpected $wpdb->' . $name . ' in unit test');
            }
        };

        $this->user(self::USER, 'me@example.com');
        $this->user(self::OTHER, 'other@example.com');
    }

    private function user(int $id, string $email): void
    {
        $GLOBALS['__bcc_ph']['users'][$id] = ['email' => $email, 'login' => 'u' . $id, 'display' => 'User ' . $id];
        $GLOBALS['__bcc_ph']['meta'][$id]  = ['bcc_handle' => 'handle' . $id, '_bcc_email_verified' => '1'];
    }

    /** Issue a challenge as GET /auth/nonce would; returns the matching signature. */
    private static function sign(string $purpose, string $address = self::WALLET): string
    {
        $c = WalletIdentityService::generateChallenge(self::USER, 'ethereum', 1, $address, $purpose);
        return 'sig-of:' . $c['message'];
    }

    /** @return array<string, mixed> */
    private static function walletProof(string $signature, string $address = self::WALLET): array
    {
        return ['wallet_address' => $address, 'chain_slug' => 'ethereum', 'signature' => $signature];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function call(callable $handler, array $params): array
    {
        $res  = $handler(new \WP_REST_Request($params));
        $data = $res->get_data();
        self::assertIsArray($data);
        return [$res->get_status(), $data['data'] ?? ($data['error'] ?? $data)];
    }

    private static function challengeIsLive(string $address = self::WALLET): bool
    {
        return isset($GLOBALS['__bcc_wc']['challenges'][self::USER . ':' . strtolower($address)]);
    }

    /** @return list<string> */
    private static function consumedPurposes(): array
    {
        return array_column($GLOBALS['__bcc_wc']['consumed'], 'purpose');
    }

    // ── Email change ────────────────────────────────────────────────────

    public function testAWalletSignatureConfirmsAnEmailChange(): void
    {
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_EMAIL_CHANGE);

        [$status, $data] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'new@example.com'] + self::walletProof($sig));

        self::assertSame(200, $status);
        self::assertTrue($data['pending']);
        self::assertSame([WalletChallenge::PURPOSE_CONFIRM_EMAIL_CHANGE], self::consumedPurposes());
        self::assertSame(['confirmed_by' => 'wallet'], $GLOBALS['__bcc_ph']['audit_meta']['account_email_change_requested']);
        self::assertSame('me@example.com', $GLOBALS['__bcc_ph']['users'][self::USER]['email'], 'still verify-before-promote');
    }

    public function testThePasswordPathIsUnchangedAndAudited(): void
    {
        $GLOBALS['__bcc_ph']['passwords'][self::USER] = self::PASSWORD;

        [$status] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'new@example.com', 'current_password' => self::PASSWORD]);
        self::assertSame(200, $status);
        self::assertSame(['confirmed_by' => 'password'], $GLOBALS['__bcc_ph']['audit_meta']['account_email_change_requested']);
        self::assertSame([], self::consumedPurposes(), 'no wallet involved');

        [$status, $err] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'new2@example.com', 'current_password' => 'wrong']);
        self::assertSame(422, $status);
        self::assertSame('Current password is incorrect.', $err['message']);
    }

    public function testNeitherCredentialIsAClear422(): void
    {
        [$status, $err] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'new@example.com']);

        self::assertSame(422, $status);
        self::assertSame('bcc_invalid_request', $err['code']);
        self::assertStringContainsString('current password or a signature', $err['message']);
    }

    public function testAChallengeForAnotherChangeIsRefused(): void
    {
        foreach ([WalletChallenge::PURPOSE_CONFIRM_ACCOUNT_DELETE, WalletChallenge::PURPOSE_VERIFY] as $other) {
            $sig = self::sign($other);
            [$status] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'new@example.com'] + self::walletProof($sig));
            self::assertSame(400, $status, $other . ' must not approve an email change');
        }
        self::assertSame([], $GLOBALS['__bcc_ph']['audit']);
        self::assertSame([], $GLOBALS['__bcc_ph']['mail'], 'no code mailed');
    }

    public function testAForeignOrUnverifiedWalletIsRefusedWithoutSpendingTheChallenge(): void
    {
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_EMAIL_CHANGE, self::FOREIGN);
        [$status, $err] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'new@example.com'] + self::walletProof($sig, self::FOREIGN));
        self::assertSame(400, $status);
        self::assertSame('That wallet is not linked to your account.', $err['message']);

        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_EMAIL_CHANGE, self::UNVERIFIED);
        [$status, $err] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'new@example.com'] + self::walletProof($sig, self::UNVERIFIED));
        self::assertSame(400, $status);
        self::assertSame('That wallet is not verified.', $err['message']);

        self::assertSame([], $GLOBALS['__bcc_wc']['consumed'], 'refused before the point of no return');
    }

    public function testABadSignatureIsRefusedAndSpendsTheChallenge(): void
    {
        self::sign(WalletChallenge::PURPOSE_CONFIRM_EMAIL_CHANGE);

        [$status, $err] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'new@example.com'] + self::walletProof('sig-of:something-else'));

        self::assertSame(401, $status);
        self::assertSame('bcc_signature_invalid', $err['code']);
        self::assertFalse(self::challengeIsLive(), 'single-use');
        self::assertSame([], $GLOBALS['__bcc_ph']['audit']);
    }

    public function testAnInvalidEmailIsAnsweredBeforeTheChallengeIsSpent(): void
    {
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_EMAIL_CHANGE);

        [$status] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'not-an-email'] + self::walletProof($sig));

        self::assertSame(422, $status);
        self::assertTrue(self::challengeIsLive(), 'a typo keeps the signed challenge');
    }

    // ── Password change ─────────────────────────────────────────────────

    public function testAWalletOnlyMemberSetsAPasswordWithTheirWallet(): void
    {
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_PASSWORD_CHANGE);

        [$status, $data] = self::call([new MyAccountEndpoint(), 'patchPassword'], ['password' => 'a brand new passphrase'] + self::walletProof($sig));

        self::assertSame(200, $status);
        self::assertNotEmpty($data['token']);
        self::assertSame('a brand new passphrase', $GLOBALS['__bcc_ph']['passwords'][self::USER]);
        self::assertSame([WalletChallenge::PURPOSE_CONFIRM_PASSWORD_CHANGE], self::consumedPurposes());
        self::assertSame(['confirmed_by' => 'wallet'], $GLOBALS['__bcc_ph']['audit_meta']['account_password_changed']);
    }

    public function testATooShortPasswordIsAnsweredBeforeTheChallengeIsSpent(): void
    {
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_PASSWORD_CHANGE);

        [$status] = self::call([new MyAccountEndpoint(), 'patchPassword'], ['password' => 'short'] + self::walletProof($sig));

        self::assertSame(422, $status);
        self::assertTrue(self::challengeIsLive());
        self::assertSame('random-never-shown', $GLOBALS['__bcc_ph']['passwords'][self::USER]);
    }

    public function testAnEmailChangeChallengeCannotChangeThePassword(): void
    {
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_EMAIL_CHANGE);

        [$status] = self::call([new MyAccountEndpoint(), 'patchPassword'], ['password' => 'a brand new passphrase'] + self::walletProof($sig));

        self::assertSame(400, $status);
        self::assertSame('random-never-shown', $GLOBALS['__bcc_ph']['passwords'][self::USER]);
    }

    // ── Delete ──────────────────────────────────────────────────────────

    public function testAWalletSignatureConfirmsAccountDeletion(): void
    {
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_ACCOUNT_DELETE);

        [$status, $data] = self::call([new MyAccountEndpoint(), 'deleteAccount'], ['confirm' => 'DELETE'] + self::walletProof($sig));

        self::assertSame(200, $status);
        self::assertTrue($data['deleted']);
        self::assertSame([self::USER], $GLOBALS['__bcc_wc']['deleted']);
        self::assertSame([WalletChallenge::PURPOSE_CONFIRM_ACCOUNT_DELETE], self::consumedPurposes());
        self::assertSame(['confirmed_by' => 'wallet'], $GLOBALS['__bcc_ph']['audit_meta']['account_deleted']);
    }

    public function testAMissingConfirmWordKeepsTheChallengeAndDeletesNothing(): void
    {
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_ACCOUNT_DELETE);

        [$status] = self::call([new MyAccountEndpoint(), 'deleteAccount'], self::walletProof($sig));

        self::assertSame(422, $status);
        self::assertTrue(self::challengeIsLive());
        self::assertSame([], $GLOBALS['__bcc_wc']['deleted']);
    }

    public function testOnlyADeleteChallengeCanDelete(): void
    {
        foreach ([WalletChallenge::PURPOSE_CONFIRM_PASSWORD_CHANGE, WalletChallenge::PURPOSE_VERIFY] as $other) {
            $sig = self::sign($other);
            [$status] = self::call([new MyAccountEndpoint(), 'deleteAccount'], ['confirm' => 'DELETE'] + self::walletProof($sig));
            self::assertSame(400, $status, $other);
        }
        self::assertSame([], $GLOBALS['__bcc_wc']['deleted']);
    }

    public function testAnOlderCoreRefusesTheWalletPathButThePasswordPathStillWorks(): void
    {
        $sig = self::sign(AccountCredentialCheck::PURPOSE_ACCOUNT_DELETE);
        [$status, $err] = self::call([new MyAccountEndpoint(), 'deleteAccount'], ['confirm' => 'DELETE'] + self::walletProof($sig));
        self::assertSame(503, $status, 'an older core would accept a plain verify signature: refuse');
        self::assertSame('bcc_unavailable', $err['code']);
        self::assertSame([], $GLOBALS['__bcc_wc']['deleted']);
        self::assertSame([], $GLOBALS['__bcc_wc']['consumed']);

        [$status] = self::call([new WalletAuthController(), 'nonce'], ['chain_slug' => 'ethereum', 'wallet_address' => self::WALLET, 'purpose' => 'confirm_account_delete']);
        self::assertSame(503, $status, 'no confirmation challenge with the wrong words');

        $GLOBALS['__bcc_ph']['passwords'][self::USER] = self::PASSWORD;
        [$status] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'new@example.com', 'current_password' => self::PASSWORD]);
        self::assertSame(200, $status, 'the password path never depends on core');
    }

    // ── GET /auth/nonce ─────────────────────────────────────────────────

    public function testTheNonceIsIssuedForTheAskedPurposeAndDefaultsToVerify(): void
    {
        $nonce = [new WalletAuthController(), 'nonce'];

        [$status, $data] = self::call($nonce, ['chain_slug' => 'ethereum', 'wallet_address' => self::WALLET, 'purpose' => 'confirm_account_delete']);
        self::assertSame(200, $status);
        self::assertSame('confirm_account_delete', $data['purpose']);

        [$status, $data] = self::call($nonce, ['chain_slug' => 'ethereum', 'wallet_address' => self::WALLET]);
        self::assertSame(200, $status);
        self::assertSame('verify', $data['purpose']);

        self::assertSame(['confirm_account_delete', 'verify'], array_column($GLOBALS['__bcc_wc']['issued'], 'purpose'));

        foreach (['sign_in', 'delete'] as $bad) {
            [$status, $err] = self::call($nonce, ['chain_slug' => 'ethereum', 'wallet_address' => self::WALLET, 'purpose' => $bad]);
            self::assertSame(422, $status, $bad);
            self::assertSame('Unknown purpose.', $err['message']);
        }
        self::assertCount(2, $GLOBALS['__bcc_wc']['issued'], 'nothing issued for a refused purpose');
    }

    // ── Recovery email (#16) ────────────────────────────────────────────

    public function testRecoveryEmailRefusesOnceTheAccountHasARealEmail(): void
    {
        $sig = self::sign(WalletChallenge::PURPOSE_VERIFY);

        [$status, $err] = self::call([new RecoveryEmailEndpoint(), 'requestRecoveryEmail'], ['email' => 'new@example.com'] + self::walletProof($sig));

        self::assertSame(409, $status);
        self::assertStringContainsString('already has an email address', $err['message']);
        self::assertTrue(self::challengeIsLive(), 'refused before the challenge is spent');
        self::assertSame([], $GLOBALS['__bcc_ph']['mail']);
    }

    public function testRecoveryEmailStillAttachesAFirstEmailToAPlaceholderAccount(): void
    {
        $GLOBALS['__bcc_ph']['users'][self::USER]['email'] = self::PLACEHOLDER;
        $sig = self::sign(WalletChallenge::PURPOSE_VERIFY);

        [$status, $data] = self::call([new RecoveryEmailEndpoint(), 'requestRecoveryEmail'], ['email' => 'first@example.com'] + self::walletProof($sig));

        self::assertSame(200, $status);
        self::assertTrue($data['ok']);
        self::assertSame([WalletChallenge::PURPOSE_VERIFY], self::consumedPurposes(), 'the ordinary verify challenge, as before');
    }

    public function testARecoveryStagedOnThePlaceholderCannotOverwriteARealEmailSetSince(): void
    {
        $GLOBALS['__bcc_ph']['users'][self::USER]['email'] = self::PLACEHOLDER;
        $otp = PendingEmailService::stage(self::USER, 'first@example.com', PendingEmailService::PURPOSE_RECOVERY);
        $GLOBALS['__bcc_ph']['users'][self::USER]['email'] = 'real@example.com';

        [$status] = self::call([new RecoveryEmailEndpoint(), 'verifyRecoveryEmail'], ['code' => $otp]);

        self::assertSame(409, $status);
        self::assertSame('real@example.com', $GLOBALS['__bcc_ph']['users'][self::USER]['email']);
    }

    // ── has_password ────────────────────────────────────────────────────

    public function testHasPasswordReadsTheFlagThenThePlaceholderThenUnknown(): void
    {
        $meta = AccountRecoveryService::META_HAS_PASSWORD;

        $GLOBALS['__bcc_ph']['meta'][self::USER][$meta] = '1';
        self::assertTrue(AccountRecoveryService::hasPassword(self::USER));

        $GLOBALS['__bcc_ph']['meta'][self::USER][$meta] = '0';
        self::assertFalse(AccountRecoveryService::hasPassword(self::USER));

        unset($GLOBALS['__bcc_ph']['meta'][self::USER][$meta]);
        self::assertNull(AccountRecoveryService::hasPassword(self::USER), 'an older account with a real email: unknown');

        $GLOBALS['__bcc_ph']['users'][self::USER]['email'] = self::PLACEHOLDER;
        self::assertFalse(AccountRecoveryService::hasPassword(self::USER), 'an older wallet signup no reset could ever reach');

        AccountRecoveryService::markHasPassword(self::USER);
        self::assertTrue(AccountRecoveryService::hasPassword(self::USER));
    }
}
