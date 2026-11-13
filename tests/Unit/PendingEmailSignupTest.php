<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\PasswordAuthController;
use BCC\Trust\Core\Services\AccountRecoveryService;
use BCC\Trust\Core\Services\HandleService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-285: an unconfirmed email sign-up held its handle and email forever.
 * F-287: a password reset proved the inbox but sign-in still said "verify
 * your email".
 *
 * Both turn on one predicate (PasswordAuthController::isPendingEmailSignup):
 * a PURE email-signup account still waiting on confirmation — has_password
 * '1', _bcc_email_verified '0', no OAuth link, no wallet. It can never have
 * signed in.
 *   - F-285 (user-approved): after 7 days such an account stops holding its
 *     handle or email; the next sign-up that wants either hard-deletes it.
 *   - F-287 (approved): a successful reset confirms such an account. NOT a
 *     wallet / OAuth account, whose email may be someone else's (the
 *     pre-hijack case).
 *
 * Against the REAL signup() and resetPassword(), over the in-memory world
 * of handle-rename-identity-stubs.php + account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PendingEmailSignupTest extends TestCase
{
    private const STALE = 10;   // abandoned 8 days ago
    private const DAY   = 86400;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/handle-rename-identity-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users'        => [],
            'meta'         => [],
            'passwords'    => [],
            'transients'   => [],
            'mail'         => [],
            'actions'      => [],
            'audit'        => [],
            'deleted'      => [],
            'wallets'      => [],
            'reset_keys'   => [],
            'current_user' => 0,
        ];
        // The wpdb double the auth helpers fail open against.
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('no database in unit tests');
            }
        };
    }

    /** An email sign-up that never confirmed, registered $ageSeconds ago. */
    private static function pending(int $id, string $handle, string $email, int $ageSeconds): void
    {
        $GLOBALS['__bcc_ph']['users'][$id] = [
            'email'      => $email,
            'login'      => AuthSupport::deriveLogin($handle),
            'display'    => $handle,
            'registered' => time() - $ageSeconds,   // epoch, as account-prehijack-stubs reads it
        ];
        $GLOBALS['__bcc_ph']['meta'][$id] = [
            HandleService::META_HANDLE                => $handle,
            AuthSupport::META_EMAIL_VERIFIED          => '0',
            AccountRecoveryService::META_HAS_PASSWORD => '1',
        ];
    }

    /** @return array{status: int, message: string} */
    private static function signup(string $handle, string $email): array
    {
        $req  = new \WP_REST_Request(['handle' => $handle, 'email' => $email, 'password' => 'correct horse battery']);
        $resp = (new PasswordAuthController())->signup($req);
        $data = $resp->get_data();
        return [
            'status'  => $resp->get_status(),
            'message' => is_array($data) ? (string) ($data['error']['message'] ?? '') : '',
        ];
    }

    private static function reset(int $userId): int
    {
        $GLOBALS['__bcc_ph']['reset_keys'][$userId] = 'k-' . $userId;
        $req = new \WP_REST_Request([
            'key'      => 'k-' . $userId,
            'login'    => $GLOBALS['__bcc_ph']['users'][$userId]['login'],
            'password' => 'a brand new password',
        ]);
        return (new PasswordAuthController())->resetPassword($req)->get_status();
    }

    private static function verified(int $userId): string
    {
        return (string) ($GLOBALS['__bcc_ph']['meta'][$userId][AuthSupport::META_EMAIL_VERIFIED] ?? '');
    }

    // ── F-285 ────────────────────────────────────────────────────────────

    public function testAStaleUnconfirmedSignupFreesItsHandle(): void
    {
        self::pending(self::STALE, 'alice', 'typo@example.test', 8 * self::DAY);

        self::assertSame(201, self::signup('alice', 'alice@example.test')['status']);

        self::assertSame([self::STALE], $GLOBALS['__bcc_ph']['deleted']);
        self::assertSame('abandoned_signup_reclaimed', $GLOBALS['__bcc_ph']['audit'][0]['action'] ?? null);
        self::assertSame([], $GLOBALS['__bcc_ph']['audit'][0]['context'] ?? null, 'audited by user id only');
    }

    public function testAStaleUnconfirmedSignupFreesItsEmail(): void
    {
        // Someone mistyped the real owner's address 8 days ago.
        self::pending(self::STALE, 'someone', 'realowner@example.test', 8 * self::DAY);

        self::assertSame(201, self::signup('realowner', 'realowner@example.test')['status']);
        self::assertSame([self::STALE], $GLOBALS['__bcc_ph']['deleted']);
    }

    public function testInsideSevenDaysItStillHoldsThemWithTheNewCopy(): void
    {
        self::pending(self::STALE, 'alice', 'typo@example.test', 6 * self::DAY);

        $byHandle = self::signup('alice', 'alice@example.test');
        $byEmail  = self::signup('alice2', 'typo@example.test');

        self::assertSame(409, $byHandle['status']);
        self::assertStringContainsString('unconfirmed sign-ups free up after 7 days', $byHandle['message']);
        self::assertSame(409, $byEmail['status']);
        self::assertStringStartsWith('An account with that email already exists.', $byEmail['message']);
        self::assertStringContainsString('unconfirmed sign-ups free up after 7 days', $byEmail['message']);
        self::assertSame([], $GLOBALS['__bcc_ph']['deleted']);
    }

    /** @return iterable<string, array{string}> */
    public static function liveAccounts(): iterable
    {
        foreach (['confirmed', 'legacy no marker', 'no password (wallet or OAuth signup)', 'Google linked', 'wallet linked', 'wallet read fails'] as $case) {
            yield $case => [$case];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('liveAccounts')]
    public function testAnAccountWithAnyOtherWayInIsNeverReclaimed(string $case): void
    {
        self::pending(self::STALE, 'alice', 'alice-old@example.test', 30 * self::DAY);
        $meta = &$GLOBALS['__bcc_ph']['meta'][self::STALE];
        match ($case) {
            'confirmed'                            => $meta[AuthSupport::META_EMAIL_VERIFIED] = '1',
            'legacy no marker'                     => $meta[AuthSupport::META_EMAIL_VERIFIED] = '',
            'no password (wallet or OAuth signup)' => $meta[AccountRecoveryService::META_HAS_PASSWORD] = '0',
            'Google linked'                        => $meta[AuthSupport::META_OAUTH_PREFIX . 'google'] = 'sub-1',
            'wallet linked'                        => $GLOBALS['__bcc_ph']['wallets'][self::STALE] = [(object) ['id' => 1]],
            'wallet read fails'                    => $GLOBALS['__bcc_ph']['wallet_read_fails'] = true,
        };
        unset($meta);

        self::assertSame(409, self::signup('alice', 'alice@example.test')['status']);
        self::assertSame([], $GLOBALS['__bcc_ph']['deleted']);
    }

    // ── F-287 ────────────────────────────────────────────────────────────

    public function testAResetConfirmsAPendingEmailSignup(): void
    {
        self::pending(self::STALE, 'alice', 'alice@example.test', 2 * self::DAY);
        $GLOBALS['__bcc_ph']['transients']['bcc_otp_' . self::STALE] = 'pending-code-hash';

        self::assertSame(200, self::reset(self::STALE));

        self::assertSame('1', self::verified(self::STALE));
        self::assertArrayNotHasKey('bcc_otp_' . self::STALE, $GLOBALS['__bcc_ph']['transients'], 'the pending code is burned');
        self::assertContains('bcc_email_verified', $GLOBALS['__bcc_ph']['actions']);
    }

    public function testAResetDoesNotConfirmAWalletAccountsUnprovenEmail(): void
    {
        // The pre-hijack shape: a wallet account carrying an email someone
        // typed. The inbox owner can reset the password, but the account
        // must not become Google-linkable by that email.
        self::pending(self::STALE, 'alice', 'victim@example.test', 2 * self::DAY);
        $GLOBALS['__bcc_ph']['wallets'][self::STALE] = [(object) ['id' => 1]];

        self::assertSame(200, self::reset(self::STALE));

        self::assertSame('0', self::verified(self::STALE));
    }

    public function testAResetLeavesAnAlreadyConfirmedAccountAlone(): void
    {
        self::pending(self::STALE, 'alice', 'alice@example.test', 2 * self::DAY);
        $GLOBALS['__bcc_ph']['meta'][self::STALE][AuthSupport::META_EMAIL_VERIFIED] = '1';

        self::assertSame(200, self::reset(self::STALE));

        self::assertNotContains('bcc_email_verified', $GLOBALS['__bcc_ph']['actions']);
    }
}
