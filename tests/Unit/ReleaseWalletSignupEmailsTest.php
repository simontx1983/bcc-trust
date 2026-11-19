<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\Services\AccountRecoveryService;
use BCC\Trust\Core\Services\PendingEmailService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-294 one-shot: unverified addresses wallet sign-ups typed move out of
 * user_email into the pending sign-up address
 * (includes/database/release-wallet-signup-unverified-emails.php).
 *
 * Pinned against the real migration function:
 *   - a wallet sign-up: typed address → pending, user_email → the salt-keyed
 *     per-user placeholder, written straight to wp_users, cache cleaned;
 *   - an OAuth account or one with no wallet is left alone (skipped);
 *   - an already-pending address is never overwritten (a retry after a
 *     failed write keeps the first one);
 *   - an unreadable wallet list, a failed SELECT, a failed meta or user
 *     write, an empty salt → INCOMPLETE, and nothing half-written that a
 *     retry cannot finish;
 *   - more than one run's worth → MORE, a re-run after COMPLETE is a no-op;
 *   - the SELECT is narrowed to unverified ('0'), non-placeholder, not a
 *     password account; the log carries counts, never an address.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/release-wallet-signup-email-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReleaseWalletSignupEmailsTest extends TestCase
{
    private const SALT = 'unit-salt-release';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/release-wallet-signup-email-stubs.php';
        require_once __DIR__ . '/../../includes/database/backfill-wallet-placeholder-emails.php';
        require_once __DIR__ . '/../../includes/database/release-wallet-signup-unverified-emails.php';
        $GLOBALS['__bcc_rel'] = [
            'salt' => self::SALT, 'candidates' => [], 'meta' => [], 'wallets' => [],
            'prepared' => [], 'updates' => [], 'cache_cleaned' => [], 'logs' => [], 'email_exists' => [],
        ];
        $GLOBALS['wpdb']->last_error = '';
    }

    private static function walletSignup(int $id, string $email): void
    {
        $GLOBALS['__bcc_rel']['candidates'][$id] = $email;
        $GLOBALS['__bcc_rel']['meta'][$id]       = [AuthSupport::META_EMAIL_VERIFIED => '0', 'bcc_handle' => 'h' . $id];
        $GLOBALS['__bcc_rel']['wallets'][$id]    = [['id' => $id * 10, 'wallet_address' => '0xabc']];
    }

    private static function placeholderFor(int $id): string
    {
        return bcc_trust_wallet_placeholder_email_for_user($id, AccountRecoveryService::PLACEHOLDER_EMAIL_DOMAIN, self::SALT);
    }

    private static function pending(int $id): ?string
    {
        $v = $GLOBALS['__bcc_rel']['meta'][$id][PendingEmailService::META_PENDING_SIGNUP_EMAIL] ?? null;
        return $v === null ? null : (string) $v;
    }

    /** @return array<string, mixed> */
    private static function lastLog(): array
    {
        $logs = $GLOBALS['__bcc_rel']['logs'];
        self::assertNotEmpty($logs);
        return end($logs)[1];
    }

    public function testAWalletSignupMovesTheTypedAddressToPending(): void
    {
        self::walletSignup(41, 'typed@example.com');

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_release_wallet_signup_unverified_emails());

        self::assertSame('typed@example.com', self::pending(41));
        self::assertSame([['id' => 41, 'email' => self::placeholderFor(41)]], $GLOBALS['__bcc_rel']['updates']);
        self::assertTrue(AccountRecoveryService::isPlaceholderEmail(self::placeholderFor(41)));
        self::assertSame([41], $GLOBALS['__bcc_rel']['cache_cleaned']);
        self::assertSame(['moved' => 1, 'skipped' => 0], self::lastLog());
    }

    public function testOauthAndWalletlessAccountsAreLeftAlone(): void
    {
        self::walletSignup(41, 'oauth@example.com');
        $GLOBALS['__bcc_rel']['meta'][41][AuthSupport::META_OAUTH_PREFIX . 'google'] = 'sub-1';
        self::walletSignup(42, 'password@example.com');
        $GLOBALS['__bcc_rel']['wallets'][42] = []; // an unconfirmed password sign-up: F-285's
        self::walletSignup(43, 'wallet@example.com');

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_release_wallet_signup_unverified_emails());

        self::assertSame([['id' => 43, 'email' => self::placeholderFor(43)]], $GLOBALS['__bcc_rel']['updates']);
        self::assertNull(self::pending(41));
        self::assertNull(self::pending(42));
        self::assertSame(['moved' => 1, 'skipped' => 2], self::lastLog());
    }

    public function testATakenPlaceholderIsSkipped(): void
    {
        self::walletSignup(41, 'typed@example.com');
        $GLOBALS['__bcc_rel']['email_exists'][strtolower(self::placeholderFor(41))] = 99;

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_release_wallet_signup_unverified_emails());

        self::assertSame([], $GLOBALS['__bcc_rel']['updates']);
        self::assertNull(self::pending(41));
    }

    public function testItsOwnPlaceholderIsNotACollision(): void
    {
        self::walletSignup(41, 'typed@example.com');
        $GLOBALS['__bcc_rel']['email_exists'][strtolower(self::placeholderFor(41))] = 41;

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_release_wallet_signup_unverified_emails());

        self::assertCount(1, $GLOBALS['__bcc_rel']['updates']);
    }

    public function testAnUnreadableWalletListStopsTheRunUntouched(): void
    {
        self::walletSignup(41, 'typed@example.com');
        $GLOBALS['__bcc_rel']['wallet_read_fails'] = true;

        self::assertSame(BCC_TRUST_MIGRATION_INCOMPLETE, bcc_trust_release_wallet_signup_unverified_emails());

        self::assertSame([], $GLOBALS['__bcc_rel']['updates']);
        self::assertNull(self::pending(41));
    }

    public function testAFailedUserWriteIsRetriedWithTheFirstPendingKept(): void
    {
        self::walletSignup(41, 'typed@example.com');
        $GLOBALS['__bcc_rel']['update_return'] = false;

        self::assertSame(BCC_TRUST_MIGRATION_INCOMPLETE, bcc_trust_release_wallet_signup_unverified_emails());
        self::assertSame('typed@example.com', self::pending(41));
        self::assertSame([], $GLOBALS['__bcc_rel']['cache_cleaned']);

        // The retry finds the same row; the pending address is not rewritten
        // (even if user_email were somehow different now).
        $GLOBALS['__bcc_rel']['update_return'] = 1;
        $GLOBALS['__bcc_rel']['candidates'][41] = 'changed@example.com';
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_release_wallet_signup_unverified_emails());
        self::assertSame('typed@example.com', self::pending(41));
        self::assertSame([['id' => 41, 'email' => self::placeholderFor(41)]], $GLOBALS['__bcc_rel']['updates']);
    }

    public function testAFailedMetaWriteLeavesUserEmailAlone(): void
    {
        self::walletSignup(41, 'typed@example.com');
        $GLOBALS['__bcc_rel']['meta_write_fails'] = true;

        self::assertSame(BCC_TRUST_MIGRATION_INCOMPLETE, bcc_trust_release_wallet_signup_unverified_emails());

        self::assertSame([], $GLOBALS['__bcc_rel']['updates'], 'the address is never dropped without being held');
    }

    public function testAFailedSelectIsIncomplete(): void
    {
        self::walletSignup(41, 'typed@example.com');
        $GLOBALS['__bcc_rel']['select_fails'] = true;

        self::assertSame(BCC_TRUST_MIGRATION_INCOMPLETE, bcc_trust_release_wallet_signup_unverified_emails());
        self::assertSame([], $GLOBALS['__bcc_rel']['updates']);
    }

    public function testAnEmptySaltIsIncompleteAndQueriesNothing(): void
    {
        self::walletSignup(41, 'typed@example.com');
        $GLOBALS['__bcc_rel']['salt'] = '';

        self::assertSame(BCC_TRUST_MIGRATION_INCOMPLETE, bcc_trust_release_wallet_signup_unverified_emails());
        self::assertSame([], $GLOBALS['__bcc_rel']['prepared']);
        self::assertSame([], $GLOBALS['__bcc_rel']['updates']);
    }

    public function testMoreThanOneRunsWorthIsMoreThenCompletes(): void
    {
        for ($id = 1; $id <= 2001; $id++) {
            self::walletSignup($id, 'u' . $id . '@example.com');
        }

        self::assertSame(BCC_TRUST_MIGRATION_MORE, bcc_trust_release_wallet_signup_unverified_emails());
        self::assertCount(2000, $GLOBALS['__bcc_rel']['updates']);

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_release_wallet_signup_unverified_emails());
        self::assertCount(2001, $GLOBALS['__bcc_rel']['updates']);
        self::assertSame('u2001@example.com', self::pending(2001));

        // A re-run after COMPLETE finds nothing and writes nothing.
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_release_wallet_signup_unverified_emails());
        self::assertCount(2001, $GLOBALS['__bcc_rel']['updates']);
    }

    public function testTheSelectIsNarrowedAndTheLogNamesNoAddress(): void
    {
        self::walletSignup(41, 'typed@example.com');

        bcc_trust_release_wallet_signup_unverified_emails();

        $args = $GLOBALS['__bcc_rel']['prepared'][0];
        self::assertSame(0, $args[0], 'keyset cursor starts at 0');
        self::assertSame('%@noreply.bcc.local', $args[1], 'placeholders are excluded');
        self::assertSame(AuthSupport::META_EMAIL_VERIFIED, $args[2]);
        self::assertSame(AccountRecoveryService::META_HAS_PASSWORD, $args[3]);
        self::assertSame(100, $args[4]);
        self::assertSame(41, $GLOBALS['__bcc_rel']['prepared'][1][0], 'the cursor advances past the last row');

        foreach ($GLOBALS['__bcc_rel']['logs'] as [$message, $context]) {
            self::assertStringNotContainsString('@', $message . json_encode($context));
        }
    }

    public function testItIsRegisteredWithTheRunnerAndLoaded(): void
    {
        $runner = (string) file_get_contents(__DIR__ . '/../../includes/database/migration-runner.php');
        self::assertMatchesRegularExpression(
            "/'id'\s*=>\s*'release_wallet_signup_unverified_emails_v1',\s*'done_option'\s*=>\s*'[a-z_]+',\s*'callback'\s*=>\s*'bcc_trust_release_wallet_signup_unverified_emails'/",
            $runner
        );
        self::assertStringContainsString(
            "require_once BCC_TRUST_PATH . 'includes/database/release-wallet-signup-unverified-emails.php';",
            (string) file_get_contents(__DIR__ . '/../../bcc-trust.php')
        );
    }
}
