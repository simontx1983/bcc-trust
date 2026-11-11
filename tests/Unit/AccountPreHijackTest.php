<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\EmailVerificationController;
use BCC\Trust\Core\REST\Auth\OAuthController;
use BCC\Trust\Core\Support\JwtToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Account pre-hijack via an unverified email (wallet signup → Google link).
 *
 * The attack: sign up with a wallet, typing the victim's address as the
 * account email. Wallet signup used to mark that address verified ('1'), and
 * oauthLogin() links a Google sign-in into an existing account by email
 * whenever the flag is not '0'. So the victim's first Google sign-in landed
 * inside the attacker's account — attacker keeps wallet access.
 *
 * Pinned here, running the REAL oauthLogin (signed bridge gate included),
 * resendVerification, verifyEmail and the AuthSupport/AuthMailer helpers:
 *   - the attack sequence ends with NO link and NO JWT for that account;
 *   - once the inbox owner verifies (OTP from the mail), Google DOES link;
 *   - link-by-email requires exactly '1': '0', '' and junk fail closed;
 *   - resend serves '0' and legacy '' accounts, never '1' or placeholders;
 *   - beginEmailVerification is the one OTP+token+mail sequence, and every
 *     caller uses it (password signup, X signup, wallet signup, resend);
 *   - the mail tells a non-owner to ignore it.
 *
 * ## Isolation
 * Subprocess-only; setUp() pulls in tests/Stubs/account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AccountPreHijackTest extends TestCase
{
    private const VICTIM   = 'victim@gmail.com';
    private const ATTACKER = 10;
    private const OWNER    = 20;
    private const ROOT     = __DIR__ . '/../../app/Domain/Core/REST/Auth/';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users'      => [],
            'meta'       => [],
            'transients' => [],
            'mail'       => [],
            'actions'    => [],
        ];
        // A successful sign-in resolves in_good_standing through the trust
        // repository, which fails OPEN on any Throwable (AuthSupport::
        // resolveInGoodStanding). A $wpdb double that throws on every query
        // exercises exactly that branch without a database.
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('no database in unit tests');
            }
        };
    }

    private static function addUser(int $id, string $email, ?string $flag): void
    {
        $GLOBALS['__bcc_ph']['users'][$id] = ['email' => $email, 'login' => 'u' . $id, 'display' => 'User ' . $id];
        $GLOBALS['__bcc_ph']['meta'][$id]  = ['bcc_handle' => 'handle' . $id];
        if ($flag !== null) {
            $GLOBALS['__bcc_ph']['meta'][$id][AuthSupport::META_EMAIL_VERIFIED] = $flag;
        }
    }

    /** @return array<string, mixed> */
    private static function googleSignIn(
        string $email,
        string $providerId = 'google-sub-victim',
        mixed $emailVerified = true,
        string $provider = 'google'
    ): array {
        // Default: a Google email the provider VERIFIED (the bridge says so
        // explicitly) — so the account-side gate is what each case exercises.
        $params = ['provider' => $provider, 'provider_id' => $providerId, 'email' => $email, 'display_name' => 'Victim'];
        if ($emailVerified !== null) {
            $params['email_verified'] = $emailVerified;
        }
        $req    = new \WP_REST_Request($params);
        $ts     = (string) time();
        $nonce  = bin2hex(random_bytes(8));
        $req->set_header('x-bcc-oauth-timestamp', $ts);
        $req->set_header('x-bcc-oauth-nonce', $nonce);
        $req->set_header(
            'x-bcc-oauth-signature',
            hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $req->get_body(), (string) BCC_OAUTH_BRIDGE_SECRET)
        );

        $res = (new OAuthController())->oauthLogin($req);
        self::assertSame(200, $res->get_status(), 'the signed bridge request must pass the gate');
        $data = $res->get_data();
        self::assertIsArray($data);
        self::assertIsArray($data['data']);
        return $data['data'];
    }

    private static function oauthLink(int $userId): string
    {
        return (string) ($GLOBALS['__bcc_ph']['meta'][$userId][AuthSupport::META_OAUTH_PREFIX . 'google'] ?? '');
    }

    private static function flag(int $userId): string
    {
        return (string) ($GLOBALS['__bcc_ph']['meta'][$userId][AuthSupport::META_EMAIL_VERIFIED] ?? '');
    }

    /** @return list<array{to: string, subject: string, html: string}> */
    private static function mail(): array
    {
        return $GLOBALS['__bcc_ph']['mail'];
    }

    private static function otpFrom(string $html): string
    {
        self::assertSame(1, preg_match('/>\s*(\d{6})\s*</', $html, $m), 'the mail carries a 6-digit code');
        return $m[1];
    }

    private static function src(string $file): string
    {
        $s = file_get_contents(self::ROOT . $file);
        self::assertIsString($s);
        // Drop comments so prose mentioning a call cannot satisfy an assertion.
        $out = '';
        foreach (token_get_all($s) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }

    // ── the attack ──────────────────────────────────────────────────────

    public function testWalletSignupWithAVictimAddressIsNeverLinkedByGoogleSignIn(): void
    {
        // Attacker's wallet signup, victim's address typed as the account email.
        self::addUser(self::ATTACKER, self::VICTIM, null);
        AuthSupport::startWalletSignupEmailVerification(self::ATTACKER, self::VICTIM);

        self::assertSame('0', self::flag(self::ATTACKER), 'a wallet signature does not verify an inbox');

        // Victim's first Google sign-in with that address.
        $data = self::googleSignIn(self::VICTIM);

        self::assertArrayNotHasKey('token', $data, 'NO JWT for the attacker account');
        self::assertArrayNotHasKey('user_id', $data);
        self::assertSame('handle_required', $data['status'] ?? null, 'falls through to the new-account path');
        self::assertSame('', self::oauthLink(self::ATTACKER), 'NO Google link written onto the attacker account');
        self::assertNotContains('bcc_user_login', $GLOBALS['__bcc_ph']['actions']);
    }

    public function testOnceTheInboxOwnerVerifiesGoogleSignInLinks(): void
    {
        self::addUser(self::OWNER, self::VICTIM, null);
        AuthSupport::startWalletSignupEmailVerification(self::OWNER, self::VICTIM);
        self::assertCount(1, self::mail());

        // The inbox owner enters the code from the mail.
        $code = self::otpFrom(self::mail()[0]['html']);
        $res  = (new EmailVerificationController())->verifyEmail(
            new \WP_REST_Request(['email' => self::VICTIM, 'code' => $code])
        );
        self::assertSame(200, $res->get_status());
        self::assertSame('1', self::flag(self::OWNER));

        $data = self::googleSignIn(self::VICTIM);

        self::assertSame(self::OWNER, $data['user_id'] ?? null);
        self::assertIsString($data['token'] ?? null);
        self::assertSame('google-sub-victim', self::oauthLink(self::OWNER));
    }

    // ── revocation: sessions that predate the event must not survive ────

    private static function decodeError(string $token): ?string
    {
        $r = JwtToken::decode($token);
        return $r['ok'] ? null : $r['error'];
    }

    public function testFirstLinkByEmailRevokesEveryEarlierToken(): void
    {
        self::addUser(self::OWNER, self::VICTIM, '1');
        $before = JwtToken::encode(self::OWNER, 'handle' . self::OWNER);
        self::assertNull(self::decodeError($before), 'control: valid before the link');

        $data = self::googleSignIn(self::VICTIM);

        self::assertSame(JwtToken::ERR_REVOKED, self::decodeError($before), 'a pre-link token is dead');
        self::assertIsString($data['token'] ?? null);
        self::assertNull(self::decodeError((string) $data['token']), "the caller's fresh token works");
    }

    public function testProviderIdSignInDoesNotRevoke(): void
    {
        self::addUser(self::OWNER, self::VICTIM, '1');
        self::googleSignIn(self::VICTIM);                    // first sign-in links (and revokes)
        $session = JwtToken::encode(self::OWNER, 'handle' . self::OWNER);

        self::googleSignIn(self::VICTIM);                    // matched by provider id, no new link

        self::assertNull(self::decodeError($session), 'a routine sign-in must not log the user out elsewhere');
    }

    public function testEmailVerificationRevokesEveryEarlierToken(): void
    {
        // Pre-hijack: the account creator holds a session from signup.
        self::addUser(self::ATTACKER, self::VICTIM, null);
        AuthSupport::startWalletSignupEmailVerification(self::ATTACKER, self::VICTIM);
        $creatorSession = JwtToken::encode(self::ATTACKER, 'handle' . self::ATTACKER);
        self::assertNull(self::decodeError($creatorSession), 'control: valid before verification');

        // The inbox owner verifies.
        $res = (new EmailVerificationController())->verifyEmail(
            new \WP_REST_Request(['email' => self::VICTIM, 'code' => self::otpFrom(self::mail()[0]['html'])])
        );
        $data = $res->get_data();
        self::assertIsArray($data);

        self::assertSame(JwtToken::ERR_REVOKED, self::decodeError($creatorSession), 'the pre-verification session is dead');
        self::assertNull(self::decodeError((string) ($data['data']['token'] ?? '')), "the verifier's fresh token works");
    }

    /** @return array<string, array{0: ?string, 1: bool}> */
    public static function flagCases(): array
    {
        return [
            "'1' verified links"         => ['1', true],
            "'0' pending does not"       => ['0', false],
            "'' legacy does not"         => ['', false],
            'missing meta does not'      => [null, false],
            "junk 'yes' does not"        => ['yes', false],
            "' 1' (not exact) does not"  => [' 1', false],
        ];
    }

    #[DataProvider('flagCases')]
    public function testLinkByEmailRequiresExactlyVerified(?string $flag, bool $links): void
    {
        self::addUser(self::OWNER, self::VICTIM, $flag);

        $data = self::googleSignIn(self::VICTIM);

        self::assertSame($links, isset($data['token']));
        self::assertSame($links ? 'google-sub-victim' : '', self::oauthLink(self::OWNER));
        self::assertSame($links, AuthSupport::mayLinkOauthByEmail((string) $flag));
    }

    // ── the PROVIDER must have verified the email, explicitly ───────────

    /** @return array<string, array{0: mixed, 1: string, 2: bool}> */
    public static function providerClaimCases(): array
    {
        return [
            'google + email_verified true links'   => [true, 'google', true],
            'google, no email_verified field'      => [null, 'google', false],
            'google, email_verified false'         => [false, 'google', false],
            'google, email_verified "true" string' => ['true', 'google', false],
            'google, email_verified 1'             => [1, 'google', false],
            'twitter claiming true'                => [true, 'twitter', false],
        ];
    }

    #[DataProvider('providerClaimCases')]
    public function testLinkByEmailRequiresAnExplicitProviderVerification(mixed $claim, string $provider, bool $links): void
    {
        // A verified account — so only the provider-side claim decides.
        self::addUser(self::OWNER, self::VICTIM, '1');

        $data = self::googleSignIn(self::VICTIM, 'sub-claim', $claim, $provider);

        self::assertSame($links, isset($data['token']));
        self::assertSame(
            $links ? 'sub-claim' : '',
            (string) ($GLOBALS['__bcc_ph']['meta'][self::OWNER][AuthSupport::META_OAUTH_PREFIX . $provider] ?? '')
        );
    }

    /** @return array<string, mixed> */
    private static function providerToken(string $token): array
    {
        $raw = $GLOBALS['__bcc_ph']['transients']['bcc_oauth_pt_' . $token] ?? null;
        self::assertIsString($raw);
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    public function testTheProviderTokenCarriesTheVerifiedBitForOauthComplete(): void
    {
        // No existing account → handle_required + a provider token.
        $verified   = self::googleSignIn('new-verified@gmail.com', 'sub-a', true);
        $unverified = self::googleSignIn('new-unverified@gmail.com', 'sub-b', null);

        self::assertTrue(self::providerToken((string) $verified['provider_token'])['email_verified']);
        self::assertFalse(self::providerToken((string) $unverified['provider_token'])['email_verified']);
    }

    public function testOauthCompleteTrustsOnlyTheStoredVerifiedBit(): void
    {
        // oauth-complete needs the full WordPress user stack to run; pin the
        // decision it makes instead: '1' only from the token's explicit bit.
        $src = self::src('OAuthController.php');
        self::assertStringContainsString("\$emailProviderVerified = (\$data['email_verified'] ?? false) === true", $src);
        self::assertStringNotContainsString("\$emailProviderVerified = \$email !== '' && is_email(\$email);", $src);
        self::assertStringContainsString("AuthSupport::META_EMAIL_VERIFIED, \$emailProviderVerified ? '1' : '0'", $src);
    }

    // ── wallet signup email state ───────────────────────────────────────

    public function testWalletPlaceholderAddressIsUnverifiedAndNeverMailed(): void
    {
        $placeholder = 'wallet-0123456789abcdef@noreply.bcc.local';
        self::addUser(self::ATTACKER, $placeholder, null);

        AuthSupport::startWalletSignupEmailVerification(self::ATTACKER, $placeholder);

        self::assertSame('0', self::flag(self::ATTACKER));
        self::assertSame([], self::mail(), 'no mail to a placeholder alias');
    }

    // ── resendVerification ──────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: ?string, 2: bool}> */
    public static function resendCases(): array
    {
        return [
            "pending '0' is resent"           => ['a@example.com', '0', true],
            "legacy '' is resent (D1)"        => ['a@example.com', null, true],
            "verified '1' is skipped"         => ['a@example.com', '1', false],
            'placeholder is skipped'          => ['wallet-00ff00ff00ff00ff@noreply.bcc.local', '0', false],
        ];
    }

    #[DataProvider('resendCases')]
    public function testResendServesOnlyUnprovenRealAddresses(string $email, ?string $flag, bool $mailed): void
    {
        self::addUser(self::OWNER, $email, $flag);

        $res = (new EmailVerificationController())->resendVerification(new \WP_REST_Request(['email' => $email]));

        self::assertSame(200, $res->get_status(), 'always ok (anti-enumeration)');
        self::assertCount($mailed ? 1 : 0, self::mail());
        if ($mailed) {
            self::assertSame($email, self::mail()[0]['to']);
        }
    }

    public function testResendForAnUnknownAddressSendsNothing(): void
    {
        $res = (new EmailVerificationController())->resendVerification(new \WP_REST_Request(['email' => 'nobody@example.com']));

        self::assertSame(200, $res->get_status());
        self::assertSame([], self::mail());
    }

    // ── the shared sequence ─────────────────────────────────────────────

    public function testBeginEmailVerificationStoresOtpAndTokenAndMailsTheLink(): void
    {
        self::addUser(self::OWNER, 'owner@example.com', '0');

        AuthSupport::beginEmailVerification(self::OWNER, 'owner@example.com');

        self::assertCount(1, self::mail());
        $mail = self::mail()[0];
        self::assertSame('owner@example.com', $mail['to']);
        self::assertSame('[BCC] Verify your email address', $mail['subject']);

        // OTP: the mailed code is the one stored (as its HMAC).
        self::assertSame(
            hash_hmac('sha256', self::otpFrom($mail['html']), 'test-salt-prehijack'),
            $GLOBALS['__bcc_ph']['transients']['bcc_otp_' . self::OWNER] ?? null
        );

        // Token: exactly one stored, mapped to the user, and it is the one in the link.
        $tokens = array_values(array_filter(
            array_keys($GLOBALS['__bcc_ph']['transients']),
            static fn(string $k): bool => str_starts_with($k, 'bcc_vt_')
        ));
        self::assertCount(1, $tokens);
        self::assertSame((string) self::OWNER, $GLOBALS['__bcc_ph']['transients'][$tokens[0]]);
        $token = substr($tokens[0], strlen('bcc_vt_'));
        self::assertStringContainsString(
            htmlspecialchars(
                'https://app.example.test/verify-email?email=' . rawurlencode('owner@example.com') . '&token=' . $token,
                ENT_QUOTES,
                'UTF-8'
            ),
            $mail['html']
        );

        // It does not decide verification state — callers do.
        self::assertSame('0', self::flag(self::OWNER));
    }

    public function testVerificationMailTellsANonOwnerToIgnoreIt(): void
    {
        self::addUser(self::OWNER, 'owner@example.com', '0');

        AuthSupport::beginEmailVerification(self::OWNER, 'owner@example.com');

        $html = self::mail()[0]['html'];
        self::assertStringContainsString('If you didn&rsquo;t create this account, ignore this email.', $html);
        self::assertStringNotContainsString('your address will not be used', $html);
    }

    public function testEveryCallerUsesTheSharedSequence(): void
    {
        // Wallet signup: never marks the address verified, and starts verification.
        $wallet = self::src('WalletAuthController.php');
        self::assertDoesNotMatchRegularExpression("/META_EMAIL_VERIFIED,\s*'1'/", $wallet);
        self::assertStringContainsString('AuthSupport::startWalletSignupEmailVerification($userIdInt, $email)', $wallet);

        // Password signup, X signup, resend: one sequence, no inline copies left.
        foreach (['PasswordAuthController.php', 'OAuthController.php', 'EmailVerificationController.php'] as $file) {
            $src = self::src($file);
            self::assertStringContainsString('AuthSupport::beginEmailVerification(', $src, $file);
            self::assertStringNotContainsString('storeVerifyToken(', $src, $file . ' still has an inline copy');
            self::assertStringNotContainsString('sendVerificationEmail(', $src, $file . ' still has an inline copy');
        }

        // oauthLogin's email fallback goes through the fail-closed predicate.
        self::assertStringContainsString('AuthSupport::mayLinkOauthByEmail($emailVerified)', self::src('OAuthController.php'));
    }
}
