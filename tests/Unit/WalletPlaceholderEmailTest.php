<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\Services\AccountRecoveryService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Wallet-signup placeholder email: the value must NOT be a guessable
 * function of the wallet address (docs/wallet-privacy-policy.md).
 *
 * The pre-2026-07-23 form was `substr(md5(strtolower($address)), 0, 16)`.
 * Gravatar publishes `md5(user_email)` on every avatar URL, so an
 * attacker with a candidate address could recompute that local part,
 * hash it, and confirm which member owns the wallet — a member↔wallet
 * oracle. The fix keys the token on `wp_salt()`, which the attacker does
 * not know, while keeping it deterministic per wallet for signup-retry
 * idempotency.
 *
 * These tests pin: right shape, NOT the old md5 value, salt-dependence
 * (the whole point), determinism, case-folding, recovery-domain
 * recognition, and the fail-closed random branch. They also cover the
 * backfill token helper, which must derive from user_id — not the
 * address at all.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WalletPlaceholderEmailTest extends TestCase
{
    // Synthetic, unmistakably-not-a-real-wallet fixture (review item #4).
    private const ADDR   = '0xEXAMPLEonlyNOTArealWALLETfixtureonlyZZ01';
    private const DOMAIN = 'noreply.bcc.local';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/wallet-email-stubs.php';
        $GLOBALS['__bcc_wp_email_salt'] = 'salt-A';
        require_once __DIR__ . '/../../includes/database/backfill-wallet-placeholder-emails.php';
    }

    private const SHAPE = '/^wallet-[0-9a-f]{16}@noreply\.bcc\.local$/';

    // ── signup token ──────────────────────────────────────────────────

    public function testShapeIsAWalletPlaceholderOnTheRecoveryDomain(): void
    {
        $email = AuthSupport::placeholderEmailForWallet(self::ADDR);

        self::assertMatchesRegularExpression(self::SHAPE, $email);
        self::assertTrue(AccountRecoveryService::isPlaceholderEmail($email));
    }

    /**
     * The load-bearing assertion: the token is NOT the old md5-of-address
     * value, so the Gravatar oracle no longer resolves.
     */
    public function testTokenIsNotTheOldMd5OfAddress(): void
    {
        $email  = AuthSupport::placeholderEmailForWallet(self::ADDR);
        $oldMd5 = substr(md5(strtolower(self::ADDR)), 0, 16);

        self::assertStringNotContainsString($oldMd5, $email);
    }

    /**
     * Keyed on the salt: change the salt, the token changes. An attacker
     * cannot compute it from the address alone.
     */
    public function testTokenDependsOnTheSiteSalt(): void
    {
        $GLOBALS['__bcc_wp_email_salt'] = 'salt-A';
        $a = AuthSupport::placeholderEmailForWallet(self::ADDR);

        $GLOBALS['__bcc_wp_email_salt'] = 'salt-B';
        $b = AuthSupport::placeholderEmailForWallet(self::ADDR);

        self::assertNotSame($a, $b);
    }

    /** Deterministic per (address, salt) — the signup-retry idempotency net. */
    public function testDeterministicForSameAddressAndSalt(): void
    {
        $a = AuthSupport::placeholderEmailForWallet(self::ADDR);
        $b = AuthSupport::placeholderEmailForWallet(self::ADDR);

        self::assertSame($a, $b);
    }

    /** Case-folded on the address, matching the old strtolower() behaviour. */
    public function testCaseInsensitiveOnTheAddress(): void
    {
        $lower = AuthSupport::placeholderEmailForWallet(strtolower(self::ADDR));
        $upper = AuthSupport::placeholderEmailForWallet(strtoupper(self::ADDR));

        self::assertSame($lower, $upper);
    }

    public function testDifferentAddressesYieldDifferentTokens(): void
    {
        $a = AuthSupport::placeholderEmailForWallet(self::ADDR);
        $b = AuthSupport::placeholderEmailForWallet('0x0000000000000000000000000000000000000001');

        self::assertNotSame($a, $b);
    }

    /**
     * Fail closed: with no salt we must THROW, never mint an identity.
     * The controller turns this into an internal error before any user
     * row or nonce is created (see WalletAuthController::restWalletSignup).
     * A random token was rejected — it would give the account a
     * nondeterministic identity and mask a real misconfiguration; a bare
     * hash of the address would reopen the oracle.
     */
    public function testEmptySaltThrowsInsteadOfMintingAnIdentity(): void
    {
        $GLOBALS['__bcc_wp_email_salt'] = '';

        $this->expectException(\RuntimeException::class);
        AuthSupport::placeholderEmailForWallet(self::ADDR);
    }

    /**
     * The generated email must fit wp_users.user_email (VARCHAR(100)) and
     * be a syntactically valid address — WP's wp_insert_user sanitises and
     * requires a valid email. The token is 16 hex + fixed affixes = 41
     * chars, well inside the column.
     */
    public function testGeneratedEmailFitsColumnAndValidates(): void
    {
        $email = AuthSupport::placeholderEmailForWallet(self::ADDR);

        self::assertLessThanOrEqual(100, strlen($email), 'must fit user_email VARCHAR(100)');
        self::assertNotFalse(
            filter_var($email, FILTER_VALIDATE_EMAIL),
            'placeholder must be a syntactically valid email'
        );
    }

    // ── backfill token helper ─────────────────────────────────────────

    public function testBackfillTokenDerivesFromUserIdNotAddress(): void
    {
        $e1 = bcc_trust_wallet_placeholder_email_for_user(1, self::DOMAIN, 'salt-A');
        $e2 = bcc_trust_wallet_placeholder_email_for_user(2, self::DOMAIN, 'salt-A');

        self::assertMatchesRegularExpression(self::SHAPE, $e1);
        self::assertNotSame($e1, $e2, 'distinct users → distinct tokens (uniqueness)');

        // Same user + same salt → stable (idempotent re-run of the migration).
        self::assertSame($e1, bcc_trust_wallet_placeholder_email_for_user(1, self::DOMAIN, 'salt-A'));

        // Keyed on the salt.
        self::assertNotSame($e1, bcc_trust_wallet_placeholder_email_for_user(1, self::DOMAIN, 'salt-B'));

        // Not the address-derived value.
        self::assertStringNotContainsString(substr(md5(strtolower(self::ADDR)), 0, 16), $e1);
    }

    public function testBackfillTokenEmptySaltThrows(): void
    {
        // The migration guards the salt before the loop and aborts the
        // whole run (staying retryable) on empty. The helper throws as a
        // belt-and-suspenders backstop rather than fall back to a random
        // (non-idempotent) or empty-keyed (guessable) token.
        $this->expectException(\RuntimeException::class);
        bcc_trust_wallet_placeholder_email_for_user(1, self::DOMAIN, '');
    }

    public function testBackfillEmailFitsColumnAndValidates(): void
    {
        $email = bcc_trust_wallet_placeholder_email_for_user(1, self::DOMAIN, 'salt-A');

        self::assertLessThanOrEqual(100, strlen($email));
        self::assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL));
    }
}
