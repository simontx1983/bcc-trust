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
 * F-253 (user decisions 2026-11-12): a member confirms an email change, a
 * password change or an account delete with their current password. A
 * member with NO password (a wallet/OAuth signup that never set one) can't,
 * so they confirm with a signature from one of their own verified wallets —
 * an ESTABLISHED one: verified 48h+ ago and before the current sign-in, so
 * a stolen session can't link its own wallet and take the account.
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
 *   - an account with a password (or possibly one) must type it;
 *   - the proving wallet is verified 48h+ ago and before the sign-in
 *     (auth_time, kept across refresh); a relinked wallet starts over;
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
                ['id' => 1, 'user_id' => self::USER, 'chain_id' => 1, 'address' => self::WALLET, 'verified_at' => time() - 3 * 86400],
                ['id' => 2, 'user_id' => self::OTHER, 'chain_id' => 1, 'address' => self::FOREIGN, 'verified_at' => time() - 3 * 86400],
                ['id' => 3, 'user_id' => self::USER, 'chain_id' => 1, 'address' => self::UNVERIFIED, 'verified_at' => null],
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
        // The member under test signed up with a wallet: no password.
        $GLOBALS['__bcc_ph']['meta'][self::USER][AccountRecoveryService::META_HAS_PASSWORD] = '0';
        // ... and signed in just now (the wallet predates this sign-in).
        self::signIn();
    }

    /** Present a bearer token for the member, as the frontend does. */
    private static function signIn(array $claims = []): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . \BCC\Trust\Core\Support\JwtToken::encode(self::USER, 'handle' . self::USER, $claims);
    }

    private static function verifiedAgo(int $linkId, int $seconds): void
    {
        foreach ($GLOBALS['__bcc_wc']['links'] as $i => $l) {
            if ($l['id'] === $linkId) {
                $GLOBALS['__bcc_wc']['links'][$i]['verified_at'] = time() - $seconds;
            }
        }
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
        $GLOBALS['__bcc_ph']['meta'][self::USER][AccountRecoveryService::META_HAS_PASSWORD] = '1';
        $GLOBALS['__bcc_ph']['passwords'][self::USER] = self::PASSWORD;

        [$status] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'new@example.com', 'current_password' => self::PASSWORD]);
        self::assertSame(200, $status);
        self::assertSame(['confirmed_by' => 'password'], $GLOBALS['__bcc_ph']['audit_meta']['account_email_change_requested']);
        self::assertSame([], self::consumedPurposes(), 'no wallet involved');

        [$status, $err] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'new2@example.com', 'current_password' => 'wrong']);
        self::assertSame(422, $status);
        self::assertSame('Current password is incorrect.', $err['message']);
    }

    public function testNeitherCredentialIsAClear422NamingTheOneThisAccountUses(): void
    {
        [$status, $err] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'new@example.com']);
        self::assertSame(422, $status);
        self::assertSame('bcc_invalid_request', $err['code']);
        self::assertSame('Confirm with a signature from one of your verified wallets.', $err['message']);

        $GLOBALS['__bcc_ph']['meta'][self::USER][AccountRecoveryService::META_HAS_PASSWORD] = '1';
        [$status, $err] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'new@example.com']);
        self::assertSame(422, $status);
        self::assertSame('Confirm with your current password.', $err['message']);
    }

    // ── Who may use the wallet path (session-theft guard) ───────────────

    public function testAnAccountWithAPasswordMustTypeIt(): void
    {
        // A stolen session links the thief's own wallet; it must not then
        // stand in for the password.
        $GLOBALS['__bcc_ph']['meta'][self::USER][AccountRecoveryService::META_HAS_PASSWORD] = '1';
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_ACCOUNT_DELETE);

        [$status, $err] = self::call([new MyAccountEndpoint(), 'deleteAccount'], ['confirm' => 'DELETE'] + self::walletProof($sig));

        self::assertSame(403, $status);
        self::assertSame('bcc_forbidden', $err['code']);
        self::assertSame('Your account has a password. Confirm with your current password.', $err['message']);
        self::assertSame([], $GLOBALS['__bcc_wc']['deleted']);
        self::assertTrue(self::challengeIsLive(), 'refused before anything is spent');
    }

    public function testAnAccountThatMightHaveAPasswordIsTreatedAsHavingOne(): void
    {
        // has_password null: an older account with a real email.
        unset($GLOBALS['__bcc_ph']['meta'][self::USER][AccountRecoveryService::META_HAS_PASSWORD]);
        self::assertNull(AccountRecoveryService::hasPassword(self::USER));
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_PASSWORD_CHANGE);

        [$status] = self::call([new MyAccountEndpoint(), 'patchPassword'], ['password' => 'a brand new passphrase'] + self::walletProof($sig));

        self::assertSame(403, $status);
        self::assertSame('random-never-shown', $GLOBALS['__bcc_ph']['passwords'][self::USER]);
    }

    public function testAWalletVerifiedAnHourAgoCannotConfirmYet(): void
    {
        self::verifiedAgo(1, 3600);
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_ACCOUNT_DELETE);

        $res = (new MyAccountEndpoint())->deleteAccount(new \WP_REST_Request(['confirm' => 'DELETE'] + self::walletProof($sig)));
        $err = $res->get_data()['error'];

        self::assertSame(403, $res->get_status());
        self::assertSame('bcc_forbidden', $err['code']);
        self::assertStringContainsString('verified too recently', $err['message']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', time() - 3600 + 48 * 3600), $err['data']['usable_at']);
        self::assertSame([], $GLOBALS['__bcc_wc']['deleted']);
        self::assertTrue(self::challengeIsLive(), 'checked before the challenge is spent');
    }

    public function testAWalletVerified49HoursAgoBeforeThisSignInConfirms(): void
    {
        self::verifiedAgo(1, 49 * 3600);
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_ACCOUNT_DELETE);

        [$status] = self::call([new MyAccountEndpoint(), 'deleteAccount'], ['confirm' => 'DELETE'] + self::walletProof($sig));

        self::assertSame(200, $status);
        self::assertSame([self::USER], $GLOBALS['__bcc_wc']['deleted']);
    }

    public function testAWalletVerifiedAfterThisSignInCannotConfirmEvenWhenOld(): void
    {
        // The thief's session began 60h ago; they linked their wallet 49h
        // ago. Old enough, but newer than the sign-in it is used from.
        self::verifiedAgo(1, 49 * 3600);
        self::signIn(['auth_time' => time() - 60 * 3600]);
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_PASSWORD_CHANGE);

        [$status, $err] = self::call([new MyAccountEndpoint(), 'patchPassword'], ['password' => 'a brand new passphrase'] + self::walletProof($sig));

        self::assertSame(403, $status);
        self::assertSame('This wallet was verified after you signed in. Sign out, sign back in, and try again.', $err['message']);
        self::assertSame('random-never-shown', $GLOBALS['__bcc_ph']['passwords'][self::USER]);
    }

    public function testATokenWithoutAuthTimeFallsBackToItsIssuedAt(): void
    {
        self::verifiedAgo(1, 49 * 3600);
        self::signIn(['auth_time' => null, 'iat' => time() - 50 * 3600, 'exp' => time() + 3600]);
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_ACCOUNT_DELETE);

        [$status] = self::call([new MyAccountEndpoint(), 'deleteAccount'], ['confirm' => 'DELETE'] + self::walletProof($sig));

        self::assertSame(403, $status, 'issued 50h ago, the wallet came after');
    }

    public function testUnlinkingAndRelinkingAWalletRestartsItsClock(): void
    {
        // Relinking creates a new row with a fresh verified_at: the old
        // row's age is not inherited.
        $GLOBALS['__bcc_wc']['links'] = array_values(array_filter($GLOBALS['__bcc_wc']['links'], static fn (array $l): bool => $l['id'] !== 1));
        $GLOBALS['__bcc_wc']['links'][] = ['id' => 9, 'user_id' => self::USER, 'chain_id' => 1, 'address' => self::WALLET, 'verified_at' => time() - 60];
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_EMAIL_CHANGE);

        [$status, $err] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'new@example.com'] + self::walletProof($sig));

        self::assertSame(403, $status);
        self::assertStringContainsString('verified too recently', $err['message']);
    }

    public function testNoBearerSessionMeansNoWalletConfirmation(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_EMAIL_CHANGE);

        [$status] = self::call([new MyAccountEndpoint(), 'patchEmail'], ['email' => 'new@example.com'] + self::walletProof($sig));

        self::assertSame(401, $status);
        self::assertTrue(self::challengeIsLive());
    }

    /**
     * Every token mint in the plugin, and whether it is a real sign-in
     * (auth_time = now) or a continuation of the caller's session (keeps
     * auth_time). A wallet unlink or a credential change is NOT a sign-in:
     * re-minting there with a fresh auth_time would let a wallet linked
     * mid-session count as linked before the sign-in.
     *
     * @return array<string, array{string, string, int}>
     */
    public static function mintSites(): array
    {
        return [
            'password sign-in'     => ['app/Domain/Core/REST/Auth/PasswordAuthController.php', 'encodeNewSession', 1],
            'oauth sign-in/up'     => ['app/Domain/Core/REST/Auth/OAuthController.php', 'encodeNewSession', 2],
            'wallet sign-in/up'    => ['app/Domain/Core/REST/Auth/WalletAuthController.php', 'encodeNewSession', 2],
            '2fa completion'       => ['app/Domain/Core/REST/Auth/TwoFactorController.php', 'encodeNewSession', 1],
            'email-verify sign-in' => ['app/Domain/Core/REST/Auth/AuthSupport.php', 'encodeNewSession', 1],
            'refresh'              => ['app/Domain/Core/REST/Auth/SessionController.php', 'encodeContinuation', 1],
            'email + password'     => ['app/Domain/Core/REST/MyAccountEndpoint.php', 'encodeContinuation', 2],
            'wallet unlink'        => ['app/Domain/Onchain/Controllers/WalletController.php', 'encodeContinuation', 1],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mintSites')]
    public function testEachMintSiteSaysWhetherItIsASignIn(string $rel, string $helper, int $count): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
        self::assertSame($count, substr_count($src, 'JwtToken::' . $helper . '('), $rel);
        self::assertStringNotContainsString('JwtToken::encode(', $src, 'no mint without a session decision');
    }

    public function testNoOtherCodeMintsTokensDirectly(): void
    {
        $root = dirname(__DIR__, 2);
        $hits = [];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php' && !str_ends_with($f->getPathname(), 'JwtToken.php')
                && str_contains((string) file_get_contents($f->getPathname()), 'JwtToken::encode(')
            ) {
                $hits[] = $f->getPathname();
            }
        }
        self::assertSame([], $hits);
    }

    public function testASignInStartsNowAndAContinuationKeepsTheStart(): void
    {
        $jwt = \BCC\Trust\Core\Support\JwtToken::class;

        $fresh = $jwt::decode($jwt::encodeNewSession(self::USER, 'h'))['payload'];
        self::assertSame(time(), $fresh['auth_time']);

        $cont = $jwt::decode($jwt::encodeContinuation(self::USER, 'h', 1234))['payload'];
        self::assertSame(1234, $jwt::sessionStartedAt($cont));
        self::assertGreaterThan(1234, $cont['iat'], 'iat is the mint time; only auth_time is kept');

        self::signIn(['auth_time' => 4321]);
        self::assertSame(4321, $jwt::presentedSessionStartedAt(self::USER));
        self::assertSame(0, $jwt::presentedSessionStartedAt(self::OTHER), 'a token for someone else counts for nothing');
        unset($_SERVER['HTTP_AUTHORIZATION']);
        self::assertSame(0, $jwt::presentedSessionStartedAt(self::USER), 'unknown → 0: no wallet predates it (fail closed)');
    }

    public function testAnInSessionReMintKeepsTheSessionStart(): void
    {
        // The re-mint after a credential change (here: a wallet-only member
        // setting a password with their wallet) continues the SAME session.
        self::verifiedAgo(1, 5 * 86400);
        self::signIn(['auth_time' => time() - 4 * 86400]);
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_PASSWORD_CHANGE);

        [$status, $data] = self::call([new MyAccountEndpoint(), 'patchPassword'], ['password' => 'a brand new passphrase'] + self::walletProof($sig));

        self::assertSame(200, $status);
        $payload = \BCC\Trust\Core\Support\JwtToken::decode($data['token'])['payload'];
        self::assertSame(time() - 4 * 86400, $payload['auth_time']);
    }

    public function testAWalletLinkedMidSessionStillCannotConfirmAfterAReMint(): void
    {
        // The attack: a stolen session (signed in 60h ago) links wallet W
        // 49h ago, then gets a re-minted token (unlink, refresh, ...). The
        // re-mint keeps auth_time, so W is still "after the sign-in".
        self::verifiedAgo(1, 49 * 3600);
        $start = time() - 60 * 3600;
        $reMinted = \BCC\Trust\Core\Support\JwtToken::encodeContinuation(self::USER, 'handle' . self::USER, $start);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $reMinted;
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_ACCOUNT_DELETE);

        [$status] = self::call([new MyAccountEndpoint(), 'deleteAccount'], ['confirm' => 'DELETE'] + self::walletProof($sig));

        self::assertSame(403, $status);
        self::assertSame([], $GLOBALS['__bcc_wc']['deleted']);
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

    public function testAChallengeForOneChainIsNotSpentOnAnother(): void
    {
        // The same EVM address, linked and verified on both chains; the
        // challenge (and the message the member signed) names ethereum.
        $GLOBALS['__bcc_wc']['links'][] = ['id' => 4, 'user_id' => self::USER, 'chain_id' => 2, 'address' => self::WALLET, 'verified_at' => time() - 3 * 86400];
        $sig = self::sign(WalletChallenge::PURPOSE_CONFIRM_EMAIL_CHANGE);

        [$status, $err] = self::call(
            [new MyAccountEndpoint(), 'patchEmail'],
            ['email' => 'new@example.com', 'wallet_address' => self::WALLET, 'chain_slug' => 'base', 'signature' => $sig]
        );

        self::assertSame(400, $status);
        self::assertSame('Challenge was issued for another chain. Please request a new nonce.', $err['message']);
        self::assertSame([], $GLOBALS['__bcc_ph']['audit']);
        self::assertSame([], $GLOBALS['__bcc_ph']['mail']);
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

    public function testRecoveryEmailNeedsAnEstablishedWalletToo(): void
    {
        $GLOBALS['__bcc_ph']['users'][self::USER]['email'] = self::PLACEHOLDER;
        self::verifiedAgo(1, 3600);
        $sig = self::sign(WalletChallenge::PURPOSE_VERIFY);

        [$status, $err] = self::call([new RecoveryEmailEndpoint(), 'requestRecoveryEmail'], ['email' => 'first@example.com'] + self::walletProof($sig));

        self::assertSame(403, $status);
        self::assertStringContainsString('verified too recently', $err['message']);
        self::assertSame([], $GLOBALS['__bcc_ph']['mail']);

        self::verifiedAgo(1, 49 * 3600);
        self::signIn(['auth_time' => time() - 60 * 3600]);
        [$status] = self::call([new RecoveryEmailEndpoint(), 'requestRecoveryEmail'], ['email' => 'first@example.com'] + self::walletProof($sig));
        self::assertSame(403, $status, 'and verified before this sign-in');
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
