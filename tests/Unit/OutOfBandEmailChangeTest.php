<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\EmailVerificationController;
use BCC\Trust\Core\REST\Auth\OAuthController;
use BCC\Trust\Core\REST\MyAccountEndpoint;
use BCC\Trust\Core\Services\AccountRecoveryService;
use BCC\Trust\Core\Services\PendingEmailService;
use BCC\Trust\Core\Services\UserLifecycleService;
use BCC\Trust\Core\Services\UserSyncService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * H1 — an email change made OUTSIDE PendingEmailService revokes the
 * account's email verification.
 *
 * The hole: UserLifecycleService::onProfileUpdate only diffed the password
 * hash. A member whose own address was proven ('1') could change user_email
 * to someone else's address through core REST (PUT /wp/v2/users/me),
 * wp-admin (profile.php, user-edit.php) or any plugin calling
 * wp_update_user(), and the '1' stayed. oauthLogin() links a Google sign-in
 * into an existing account by email whenever that flag is '1', so the
 * address's real owner signing in with Google landed in the member's account.
 *
 * Every one of those paths reaches BCC the same way: wp_update_user() fires
 * `profile_update`($id, $old_user_data). Neither unit harness loads
 * WordPress, so the stub wp_update_user() fires that hook, with the
 * pre-write snapshot like core, into the REAL onProfileUpdate. Pinned
 * against it, the real oauthLogin, verifyEmail, MyAccountEndpoint and
 * PendingEmailService:
 *   - an outside change sets the flag to '0', and a Google sign-in by the
 *     new address then links nothing and mints nothing;
 *   - re-saving the same address (in any case), or another field, keeps
 *     '1' (and Google still links, so the harness is not vacuous);
 *   - a code or link mailed BEFORE the change dies with it; a link mailed
 *     after it works;
 *   - a wallet sign-up's typed address is dropped by the change, so the
 *     REAL resend (email and handle forms) mails it nothing, and a stale
 *     one can neither be verified into the new user_email nor applied;
 *   - every code and link is bound to the address it was mailed to: one
 *     mailed elsewhere, or with no recorded address, never verifies, even
 *     after a user_email write that fires no hook at all;
 *   - PendingEmailService's own writes (the email change, a wallet
 *     sign-up's address) stay verified and revoke nothing;
 *   - the audit entry names no address.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/out-of-band-email-stubs.php,
 * pending-email-stubs.php, then account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OutOfBandEmailChangeTest extends TestCase
{
    private const USER     = 40;
    private const PASSWORD = 'correct horse battery staple';
    private const OWN      = 'me@example.com';   // the member's own inbox
    private const VICTIM   = 'victim@gmail.com'; // someone else's
    private const NEW      = 'new@example.com';  // a new inbox the member owns

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/out-of-band-email-stubs.php';
        require_once __DIR__ . '/../Stubs/pending-email-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';

        $GLOBALS['__bcc_ph'] = [
            'users'             => [],
            'meta'              => [],
            'transients'        => [],
            'mail'              => [],
            'actions'           => [],
            'options'           => [],
            'audit'             => [],
            'canary'            => [],
            'core_email_notice' => [],
            'log_audit'         => [],
            'current_user'      => self::USER,
            'passwords'         => [self::USER => self::PASSWORD],
        ];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('no database in unit tests');
            }
        };

        // The REAL hook, wired as register() wires it: profile_update → onProfileUpdate.
        $lifecycle = self::lifecycle();
        $GLOBALS['__bcc_ph']['on_profile_update'] = static function (int $id, mixed $old) use ($lifecycle): void {
            $lifecycle->onProfileUpdate($id, $old);
        };
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** UserLifecycleService with a no-op sync service (no database here). */
    private static function lifecycle(): UserLifecycleService
    {
        $svc  = (new ReflectionClass(UserLifecycleService::class))->newInstanceWithoutConstructor();
        $sync = new class extends UserSyncService {
            public function __construct()
            {
            }

            public function sync(?int $userId = null): int
            {
                return 0;
            }
        };
        (new ReflectionClass(UserLifecycleService::class))->getProperty('syncService')->setValue($svc, $sync);
        return $svc;
    }

    private static function account(int $id, string $email, string $flag): void
    {
        $GLOBALS['__bcc_ph']['users'][$id] = ['email' => $email, 'login' => 'u' . $id, 'display' => 'User ' . $id];
        $GLOBALS['__bcc_ph']['meta'][$id]  = ['bcc_handle' => 'handle' . $id, AuthSupport::META_EMAIL_VERIFIED => $flag];
    }

    /** A wallet sign-up as it leaves the account: placeholder in user_email, the typed address pending, '0'. */
    private static function walletSignup(int $id, string $typed): void
    {
        self::account($id, 'wallet-' . str_repeat('0', 16) . '@' . AccountRecoveryService::PLACEHOLDER_EMAIL_DOMAIN, '0');
        $GLOBALS['__bcc_ph']['meta'][$id][PendingEmailService::META_PENDING_SIGNUP_EMAIL] = $typed;
    }

    /**
     * What core REST (PUT /wp/v2/users/me), wp-admin's edit_user() and any
     * plugin do to change an email: wp_update_user().
     */
    private static function changeOutsideBcc(int $id, string $email): void
    {
        self::assertSame($id, wp_update_user(['ID' => $id, 'user_email' => $email]));
        self::assertSame($email, self::email($id), 'control: the write landed');
    }

    private static function email(int $id): string
    {
        return $GLOBALS['__bcc_ph']['users'][$id]['email'];
    }

    private static function flag(int $id): string
    {
        return (string) ($GLOBALS['__bcc_ph']['meta'][$id][AuthSupport::META_EMAIL_VERIFIED] ?? '');
    }

    /** @return list<array<string, mixed>> the context of every revocation audit entry */
    private static function revocations(): array
    {
        $out = [];
        foreach ($GLOBALS['__bcc_ph']['log_audit'] as [$message, $context]) {
            if ($message === 'email_verification_revoked') {
                $out[] = $context;
            }
        }
        return $out;
    }

    private static function lastCodeTo(string $to): string
    {
        $mails = array_values(array_filter($GLOBALS['__bcc_ph']['mail'], static fn(array $m): bool => $m['to'] === $to));
        self::assertNotEmpty($mails, 'a code was mailed to ' . $to);
        self::assertSame(1, preg_match('/>\s*(\d{6})\s*</', end($mails)['html'], $m));
        return $m[1];
    }

    /**
     * Mail a verification code + link to $to for $id, the shared sequence
     * every sign-up and resend uses.
     *
     * @return array{code: string, token: string}
     */
    private static function mailVerification(int $id, string $to): array
    {
        $before = array_keys($GLOBALS['__bcc_ph']['transients']);
        AuthSupport::beginEmailVerification($id, $to);
        $tokens = array_values(array_filter(
            array_diff(array_keys($GLOBALS['__bcc_ph']['transients']), $before),
            static fn($k): bool => str_starts_with((string) $k, 'bcc_vt_')
        ));
        self::assertCount(1, $tokens, 'one new link');
        return ['code' => self::lastCodeTo($to), 'token' => substr((string) $tokens[0], strlen('bcc_vt_'))];
    }

    /** @param array<string, string> $params */
    private static function verify(array $params): \WP_REST_Response
    {
        return (new EmailVerificationController())->verifyEmail(new \WP_REST_Request($params));
    }

    /**
     * The REAL POST /auth/resend-verification.
     *
     * @param array<string, string> $params
     */
    private static function resend(array $params): void
    {
        $res = (new EmailVerificationController())->resendVerification(new \WP_REST_Request($params));
        self::assertSame(200, $res->get_status(), 'resend always answers ok');
    }

    private static function mailsTo(string $to): int
    {
        return count(array_filter($GLOBALS['__bcc_ph']['mail'], static fn(array $m): bool => $m['to'] === $to));
    }

    /** The newest verify-link token in the most recent mail to $to. */
    private static function lastTokenTo(string $to): string
    {
        $mails = array_values(array_filter($GLOBALS['__bcc_ph']['mail'], static fn(array $m): bool => $m['to'] === $to));
        self::assertNotEmpty($mails, 'a link was mailed to ' . $to);
        self::assertSame(1, preg_match('/token=([0-9a-f]{64})/', end($mails)['html'], $m));
        return $m[1];
    }

    /** A user_email write that fires no profile_update (a direct database write). */
    private static function writeEmailWithoutAnyHook(int $id, string $email): void
    {
        $GLOBALS['__bcc_ph']['users'][$id]['email'] = $email;
    }

    private static function errorCode(\WP_REST_Response $res): ?string
    {
        $data = $res->get_data();
        return is_array($data) ? ($data['error']['code'] ?? null) : null;
    }

    /** @return array<string, mixed> */
    private static function googleSignIn(string $email): array
    {
        // A Google email the provider VERIFIED, so the account-side gate is
        // the only thing between it and the account.
        $params = ['provider' => 'google', 'provider_id' => 'google-sub-' . md5($email), 'email' => $email, 'display_name' => 'G', 'email_verified' => true];
        $req    = new \WP_REST_Request($params);
        $ts     = (string) time();
        $nonce  = bin2hex(random_bytes(8));
        $req->set_header('x-bcc-oauth-timestamp', $ts);
        $req->set_header('x-bcc-oauth-nonce', $nonce);
        $req->set_header('x-bcc-oauth-signature', hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $req->get_body(), (string) BCC_OAUTH_BRIDGE_SECRET));
        $res = (new OAuthController())->oauthLogin($req);
        self::assertSame(200, $res->get_status(), 'the signed bridge request must pass the gate');
        $data = $res->get_data();
        self::assertIsArray($data);
        self::assertIsArray($data['data']);
        return $data['data'];
    }

    private static function googleLink(int $id): string
    {
        return (string) ($GLOBALS['__bcc_ph']['meta'][$id][AuthSupport::META_OAUTH_PREFIX . 'google'] ?? '');
    }

    // ── an outside change revokes ───────────────────────────────────────

    public function testAnOutsideEmailChangeRevokesTheVerification(): void
    {
        self::account(self::USER, self::OWN, '1');

        self::changeOutsideBcc(self::USER, self::VICTIM);

        self::assertSame('0', self::flag(self::USER), 'the new address is unproven');
        self::assertSame(
            [['user_id' => self::USER, 'via' => 'profile_update']],
            self::revocations(),
            'audited once, and the entry names no address'
        );
    }

    public function testAGoogleSignInByTheNewAddressThenLinksNothing(): void
    {
        self::account(self::USER, self::OWN, '1');
        self::changeOutsideBcc(self::USER, self::VICTIM);

        // The address's real owner signs in with Google.
        $data = self::googleSignIn(self::VICTIM);

        self::assertArrayNotHasKey('token', $data, 'NO session for the account');
        self::assertArrayNotHasKey('user_id', $data);
        self::assertSame('handle_required', $data['status'] ?? null, 'falls through to the new-account path');
        self::assertSame('', self::googleLink(self::USER), 'NO Google link written onto the account');
        self::assertNotContains('bcc_user_login', $GLOBALS['__bcc_ph']['actions']);
    }

    public function testReSavingTheSameAddressOrAnotherFieldKeepsTheVerification(): void
    {
        self::account(self::USER, self::OWN, '1');

        wp_update_user(['ID' => self::USER, 'user_email' => ' Me@Example.COM ']); // same inbox, other case
        wp_update_user(['ID' => self::USER, 'user_email' => self::OWN]); // profile.php re-save
        wp_update_user(['ID' => self::USER, 'display_name' => 'Renamed']);

        self::assertSame('1', self::flag(self::USER));
        self::assertSame([], self::revocations());
        // Control: the harness CAN link, so the test above is not vacuous.
        self::assertSame(self::USER, self::googleSignIn(self::OWN)['user_id'] ?? null);
    }

    // ── what was mailed before the change dies with it ──────────────────

    public function testACodeMailedBeforeTheChangeCannotVerifyTheNewAddress(): void
    {
        // A password sign-up still pending, its code mailed to the member's own inbox.
        self::account(self::USER, self::OWN, '0');
        $mailed = self::mailVerification(self::USER, self::OWN);

        self::changeOutsideBcc(self::USER, self::VICTIM);

        $res = self::verify(['email' => self::VICTIM, 'code' => $mailed['code']]);
        self::assertSame(400, $res->get_status());
        self::assertSame('bcc_invalid_otp', self::errorCode($res));
        self::assertSame('0', self::flag(self::USER), 'the victim address is NOT marked verified');
        self::assertArrayNotHasKey('bcc_otp_' . self::USER, $GLOBALS['__bcc_ph']['transients'], 'the code was burned');
    }

    public function testALinkMailedBeforeTheChangeCannotVerifyTheNewAddress(): void
    {
        // A wallet sign-up typed an inbox the member owns; the link goes there.
        self::walletSignup(self::USER, self::OWN);
        $mailed = self::mailVerification(self::USER, self::OWN);

        // Then user_email is pointed at the victim, outside BCC.
        self::changeOutsideBcc(self::USER, self::VICTIM);

        $res = self::verify(['token' => $mailed['token']]);
        self::assertSame(400, $res->get_status());
        self::assertSame('bcc_invalid_verify_token', self::errorCode($res));
        self::assertSame('0', self::flag(self::USER), 'the victim address is NOT marked verified');
        self::assertSame(self::VICTIM, self::email(self::USER));
        self::assertArrayNotHasKey('token', self::googleSignIn(self::VICTIM), 'and Google still links nothing');
    }

    public function testALinkMailedAfterTheChangeStillVerifiesIt(): void
    {
        self::account(self::USER, self::OWN, '1');
        self::changeOutsideBcc(self::USER, self::VICTIM);

        // Resend mails the address now on the account; its owner opens the link.
        $mailed = self::mailVerification(self::USER, self::VICTIM);
        self::assertStringStartsWith(
            self::USER . ':1:',
            (string) ($GLOBALS['__bcc_ph']['transients']['bcc_vt_' . $mailed['token']] ?? ''),
            'stored under the new link generation'
        );

        $res = self::verify(['token' => $mailed['token']]);

        self::assertSame(200, $res->get_status());
        self::assertSame('1', self::flag(self::USER), 'the inbox owner proved it');
    }

    // ── a wallet sign-up's typed address (review MEDIUM) ────────────────

    /**
     * The chain: a wallet sign-up types an inbox the attacker owns (OWN),
     * user_email is pointed at the victim outside BCC, then the REAL resend
     * is asked, by that address and by handle. The typed address went with
     * the change, so nothing new reaches it, and what reached it before
     * verifies nothing.
     */
    public function testAfterAnOutsideChangeAResendMailsTheTypedAddressNothing(): void
    {
        self::walletSignup(self::USER, self::OWN);
        $mailed = self::mailVerification(self::USER, self::OWN); // the sign-up mail
        self::changeOutsideBcc(self::USER, self::VICTIM);

        self::assertArrayNotHasKey(PendingEmailService::META_PENDING_SIGNUP_EMAIL, $GLOBALS['__bcc_ph']['meta'][self::USER], 'the typed address is dropped');
        $toTyped = self::mailsTo(self::OWN);
        self::resend(['email' => self::OWN]);
        self::resend(['identifier' => '@handle' . self::USER]);
        self::assertSame($toTyped, self::mailsTo(self::OWN), 'NOTHING new is mailed to the typed address');
        self::assertSame(1, self::mailsTo(self::VICTIM), 'control: the handle form ran, and mailed the address on file');

        foreach ([
            ['token' => $mailed['token']],
            ['email' => self::OWN, 'code' => $mailed['code']],
            ['email' => self::VICTIM, 'code' => $mailed['code']],
        ] as $attempt) {
            self::assertSame(400, self::verify($attempt)->get_status(), (string) json_encode($attempt));
        }
        self::assertSame('0', self::flag(self::USER), 'the victim address is NOT marked verified');
        self::assertArrayNotHasKey('token', self::googleSignIn(self::VICTIM), 'and Google links nothing');
    }

    /**
     * The same chain from a state this fix does not leave behind: the typed
     * address is still pending although user_email is now the victim's (a
     * change made before the fix shipped). The REAL resend mails the typed
     * inbox, by that address and by handle, and neither its link nor its
     * code verifies: each proves the typed inbox, not the address now on
     * the account.
     */
    public function testAStalePendingAddressCannotBeVerifiedIntoTheNewEmail(): void
    {
        self::walletSignup(self::USER, self::OWN);
        self::changeOutsideBcc(self::USER, self::VICTIM);
        $GLOBALS['__bcc_ph']['meta'][self::USER][PendingEmailService::META_PENDING_SIGNUP_EMAIL] = self::OWN;

        self::resend(['identifier' => '@handle' . self::USER]);
        $link = self::lastTokenTo(self::OWN);
        self::resend(['email' => self::OWN]);
        $code = self::lastCodeTo(self::OWN);
        self::assertSame(2, self::mailsTo(self::OWN), 'control: both forms mailed the typed inbox');

        $byLink = self::verify(['token' => $link]);
        self::assertSame(400, $byLink->get_status());
        self::assertSame('bcc_invalid_verify_token', self::errorCode($byLink));
        $byCode = self::verify(['email' => self::OWN, 'code' => $code]);
        self::assertSame(400, $byCode->get_status());
        self::assertSame('bcc_invalid_otp', self::errorCode($byCode));

        self::assertSame('0', self::flag(self::USER), 'the victim address is NOT marked verified');
        self::assertSame(self::VICTIM, self::email(self::USER));
        self::assertArrayNotHasKey('token', self::googleSignIn(self::VICTIM), 'and Google links nothing');
    }

    /**
     * A stale pending address is never applied over a real user_email: the
     * verification is refused (409 bcc_invalid_state), not finalised, even
     * when the code itself was mailed to user_email. The pending one is
     * dropped, so the next resend verifies user_email normally.
     */
    public function testAStalePendingAddressIsRefusedNotApplied(): void
    {
        self::account(self::USER, self::VICTIM, '0');
        $GLOBALS['__bcc_ph']['meta'][self::USER][PendingEmailService::META_PENDING_SIGNUP_EMAIL] = self::OWN;

        self::resend(['email' => self::VICTIM]);
        $res = self::verify(['email' => self::VICTIM, 'code' => self::lastCodeTo(self::VICTIM)]);

        self::assertSame(409, $res->get_status());
        self::assertSame('bcc_invalid_state', self::errorCode($res));
        self::assertSame('0', self::flag(self::USER), 'not finalised');
        self::assertSame(self::VICTIM, self::email(self::USER), 'the pending address was not applied');
        self::assertArrayNotHasKey(PendingEmailService::META_PENDING_SIGNUP_EMAIL, $GLOBALS['__bcc_ph']['meta'][self::USER], 'and it is dropped');

        self::resend(['email' => self::VICTIM]);
        self::assertSame(200, self::verify(['token' => self::lastTokenTo(self::VICTIM)])->get_status(), 'recoverable: a fresh mail verifies');
        self::assertSame('1', self::flag(self::USER));
    }

    // ── every code and link is bound to the address it was mailed to ────

    public function testACodeMailedToAnotherAddressNeverVerifiesTheCurrentOne(): void
    {
        self::account(self::USER, self::OWN, '0');
        $mailed = self::mailVerification(self::USER, self::OWN);
        self::writeEmailWithoutAnyHook(self::USER, self::VICTIM); // no revocation runs

        $res = self::verify(['email' => self::VICTIM, 'code' => $mailed['code']]);

        self::assertSame(400, $res->get_status());
        self::assertSame('bcc_invalid_otp', self::errorCode($res));
        self::assertSame('0', self::flag(self::USER), 'the victim address is NOT marked verified');
        self::assertArrayNotHasKey('bcc_otp_' . self::USER, $GLOBALS['__bcc_ph']['transients'], 'the right code is consumed');
    }

    public function testALinkMailedToAnotherAddressNeverVerifiesTheCurrentOne(): void
    {
        self::account(self::USER, self::OWN, '0');
        $mailed = self::mailVerification(self::USER, self::OWN);
        self::writeEmailWithoutAnyHook(self::USER, self::VICTIM);

        $res = self::verify(['token' => $mailed['token']]);

        self::assertSame(400, $res->get_status());
        self::assertSame('bcc_invalid_verify_token', self::errorCode($res));
        self::assertSame('0', self::flag(self::USER), 'the victim address is NOT marked verified');
        self::assertArrayNotHasKey('bcc_vt_' . $mailed['token'], $GLOBALS['__bcc_ph']['transients'], 'the link is consumed');
    }

    /** Codes and links stored before the binding existed carry no address: refused. */
    public function testACodeOrLinkWithNoRecordedAddressIsRefused(): void
    {
        self::account(self::USER, self::OWN, '0');
        $mailed = self::mailVerification(self::USER, self::OWN);
        // Strip the address, leaving each value in its pre-binding shape.
        $otpKey = 'bcc_otp_' . self::USER;
        $vtKey  = 'bcc_vt_' . $mailed['token'];
        $GLOBALS['__bcc_ph']['transients'][$otpKey] = explode(':', (string) $GLOBALS['__bcc_ph']['transients'][$otpKey])[0];
        $GLOBALS['__bcc_ph']['transients'][$vtKey]  = explode(':', (string) $GLOBALS['__bcc_ph']['transients'][$vtKey])[0];

        self::assertSame(400, self::verify(['email' => self::OWN, 'code' => $mailed['code']])->get_status(), 'unbound code');
        self::assertSame(400, self::verify(['token' => $mailed['token']])->get_status(), 'unbound link');
        self::assertSame('0', self::flag(self::USER));
    }

    /** The binding is to the inbox: the address's case and stray spaces do not matter. */
    public function testTheBindingIgnoresCaseAndSpaces(): void
    {
        self::account(self::USER, self::OWN, '0');
        AuthSupport::beginEmailVerification(self::USER, ' Me@Example.COM ');
        $tokens = array_values(array_filter(
            array_keys($GLOBALS['__bcc_ph']['transients']),
            static fn($k): bool => str_starts_with((string) $k, 'bcc_vt_')
        ));
        self::assertCount(1, $tokens);

        self::assertSame(200, self::verify(['token' => substr((string) $tokens[0], strlen('bcc_vt_'))])->get_status());
        self::assertSame('1', self::flag(self::USER));
    }

    // ── PendingEmailService's own writes stay verified ──────────────────

    public function testABccEmailChangeStaysVerifiedAndRevokesNothing(): void
    {
        self::account(self::USER, self::OWN, '1');

        // Observe the marker from inside the hook.
        $inner = $GLOBALS['__bcc_ph']['on_profile_update'];
        $GLOBALS['__bcc_ph']['on_profile_update'] = static function (int $id, mixed $old) use ($inner): void {
            $GLOBALS['__bcc_ph']['marker'][] = [
                PendingEmailService::isOwnWrite($id, ' New@Example.com'),
                PendingEmailService::isOwnWrite($id, self::VICTIM),
                PendingEmailService::isOwnWrite($id + 1, self::NEW),
            ];
            $inner($id, $old);
        };

        $patch = (new MyAccountEndpoint())->patchEmail(new \WP_REST_Request(['email' => self::NEW, 'current_password' => self::PASSWORD]));
        self::assertSame(200, $patch->get_status());
        $res = (new MyAccountEndpoint())->verifyEmailChange(new \WP_REST_Request(['code' => self::lastCodeTo(self::NEW)]));

        self::assertSame(200, $res->get_status());
        self::assertSame(self::NEW, self::email(self::USER));
        self::assertSame('1', self::flag(self::USER), 'the proven address stays verified');
        self::assertSame([], self::revocations(), 'nothing revoked');
        self::assertArrayNotHasKey(AuthSupport::META_VERIFY_LINK_GEN, $GLOBALS['__bcc_ph']['meta'][self::USER]);
        self::assertSame(
            [[true, false, false]],
            $GLOBALS['__bcc_ph']['marker'] ?? null,
            'marked as its own write: that user, that address (case/space-insensitive), nobody else'
        );
        self::assertFalse(PendingEmailService::isOwnWrite(self::USER, self::NEW), 'the mark ends with the write');
        self::assertSame(self::USER, self::googleSignIn(self::NEW)['user_id'] ?? null, 'a proven address links');
    }

    public function testAWalletSignupAddressAppliedByItsCodeStaysVerified(): void
    {
        self::walletSignup(self::USER, self::OWN);
        $mailed = self::mailVerification(self::USER, self::OWN);

        $res = self::verify(['email' => self::OWN, 'code' => $mailed['code']]);

        self::assertSame(200, $res->get_status());
        self::assertSame(self::OWN, self::email(self::USER), 'the pending address was applied');
        self::assertSame('1', self::flag(self::USER));
        self::assertSame([], self::revocations());
    }

    public function testAWalletSignupAddressAppliedByItsLinkStaysVerified(): void
    {
        self::walletSignup(self::USER, self::OWN);
        $mailed = self::mailVerification(self::USER, self::OWN);

        $res = self::verify(['token' => $mailed['token']]);

        self::assertSame(200, $res->get_status());
        self::assertSame(self::OWN, self::email(self::USER));
        self::assertSame('1', self::flag(self::USER));
        self::assertSame([], self::revocations());
    }
}
