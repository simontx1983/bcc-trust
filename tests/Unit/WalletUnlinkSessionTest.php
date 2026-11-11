<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\EmailVerificationController;
use BCC\Trust\Core\Support\JwtToken;
use BCC\Trust\Onchain\Controllers\WalletController;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The pre-hijack residual: after the rightful owner proves the email of an
 * account someone else created, that someone's WALLET is still linked. The
 * owner is prompted ("this account has a wallet linked — is it yours?") and
 * can remove it; removal must end the other party's sessions.
 *
 *   - POST /auth/verify-email reports linked_wallet_count (a count, never
 *     wallet detail), and a failed wallet read does not block verification.
 *   - DELETE /wallets/{id}, on a true removal of the caller's own wallet,
 *     returns a fresh token (always) and — with bcc_unlink_revokes_sessions
 *     ON — revokes every earlier one. OFF (the default) revokes nothing.
 *   - A foreign/absent id removes nothing, revokes nothing, returns no token.
 *   - After removal the wallet-login lookup no longer finds the account.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/wallet-unlink-stubs.php (fake
 * wallet repositories, BEFORE the autoloader can load the real ones), then
 * tests/Stubs/account-prehijack-stubs.php (in-memory WordPress).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WalletUnlinkSessionTest extends TestCase
{
    private const OWNER    = 30;
    private const STRANGER = 31;
    private const CHAIN    = 4;
    private const ADDRESS  = 'cosmos1attackerwalletfixture';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/wallet-unlink-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';

        $GLOBALS['__bcc_ph'] = [
            'users'      => [],
            'meta'       => [],
            'transients' => [],
            'mail'       => [],
            'actions'    => [],
            'options'    => [],
        ];
        $GLOBALS['__bcc_wu'] = [
            'wallets'      => [],
            'current_user' => self::OWNER,
            'read_fails'   => false,
            'side_effects' => [],
        ];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('no database in unit tests');
            }
        };

        // The owner: a real, verified email (so the last-wallet lockout guard
        // does not apply), and one wallet that someone else linked.
        $this->user(self::OWNER, 'owner@example.com');
        $this->wallet(501, self::OWNER, self::ADDRESS);
    }

    private function user(int $id, string $email): void
    {
        $GLOBALS['__bcc_ph']['users'][$id] = ['email' => $email, 'login' => 'u' . $id, 'display' => 'User ' . $id];
        $GLOBALS['__bcc_ph']['meta'][$id]  = ['bcc_handle' => 'handle' . $id];
    }

    private function wallet(int $id, int $userId, string $address): void
    {
        $GLOBALS['__bcc_wu']['wallets'][$id] = (object) [
            'id'             => $id,
            'user_id'        => $userId,
            'chain_id'       => self::CHAIN,
            'chain_slug'     => 'cosmos',
            'wallet_address' => $address,
            'verified_at'    => '2026-11-01 00:00:00',
        ];
    }

    /** @return array<string, mixed> */
    private static function unlink(int $id): array
    {
        $res = WalletController::rest_unlink_wallet(new \WP_REST_Request(['id' => $id]));
        self::assertSame(200, $res->get_status());
        $data = $res->get_data();
        self::assertIsArray($data);
        self::assertIsArray($data['data']);
        return $data['data'];
    }

    private static function decodeError(string $token): ?string
    {
        $r = JwtToken::decode($token);
        return $r['ok'] ? null : $r['error'];
    }

    // ── DELETE /wallets/{id} ────────────────────────────────────────────

    public function testWithTheSwitchOnUnlinkRevokesEveryEarlierSession(): void
    {
        $GLOBALS['__bcc_ph']['options'][WalletController::UNLINK_REVOKES_SESSIONS_OPTION] = '1';
        $walletLoginSession = JwtToken::encode(self::OWNER, 'handle' . self::OWNER);
        self::assertNull(self::decodeError($walletLoginSession), 'control: valid before the unlink');

        $data = self::unlink(501);

        self::assertTrue($data['removed']);
        self::assertSame(JwtToken::ERR_REVOKED, self::decodeError($walletLoginSession), 'the pre-unlink session is dead');
        self::assertIsString($data['token'] ?? null);
        self::assertNull(self::decodeError((string) $data['token']), "the caller's fresh token works");
        self::assertSame(JwtToken::TTL_SECONDS, $data['expires_in'] ?? null);
        self::assertSame('Bearer', $data['token_type'] ?? null);
    }

    public function testAfterUnlinkTheWalletLoginLookupNoLongerFindsTheAccount(): void
    {
        self::assertSame(self::OWNER, WalletRepository::findUserIdByAddress(self::CHAIN, self::ADDRESS), 'control');

        self::unlink(501);

        self::assertSame(0, WalletRepository::findUserIdByAddress(self::CHAIN, self::ADDRESS));
        self::assertContains('audit:wallet_unlinked', $GLOBALS['__bcc_wu']['side_effects'], 'existing unlink side effects still run');
        self::assertContains('bcc_wallet_disconnected', $GLOBALS['__bcc_ph']['actions']);
    }

    public function testWithTheSwitchOffByDefaultNothingIsRevokedButATokenIsReturned(): void
    {
        $session = JwtToken::encode(self::OWNER, 'handle' . self::OWNER);

        $data = self::unlink(501);

        self::assertTrue($data['removed']);
        self::assertNull(self::decodeError($session), 'default OFF: other sessions survive (frontend may not store the token yet)');
        self::assertNull(self::decodeError((string) ($data['token'] ?? '')), 'the token is returned regardless of the switch');
    }

    public function testAForeignWalletRemovesNothingRevokesNothingAndReturnsNoToken(): void
    {
        $GLOBALS['__bcc_ph']['options'][WalletController::UNLINK_REVOKES_SESSIONS_OPTION] = '1';
        $this->user(self::STRANGER, 'stranger@example.com');
        $this->wallet(502, self::STRANGER, 'cosmos1strangerwallet');
        $session = JwtToken::encode(self::OWNER, 'handle' . self::OWNER);

        $data = self::unlink(502);

        self::assertFalse($data['removed']);
        self::assertArrayNotHasKey('token', $data);
        self::assertNull(self::decodeError($session));
        self::assertSame(self::STRANGER, WalletRepository::findUserIdByAddress(self::CHAIN, 'cosmos1strangerwallet'));
    }

    // ── POST /auth/verify-email ─────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function verify(int $userId, string $email): array
    {
        $GLOBALS['__bcc_ph']['meta'][$userId][AuthSupport::META_EMAIL_VERIFIED] = '0';
        AuthSupport::beginEmailVerification($userId, $email);
        $html = (string) end($GLOBALS['__bcc_ph']['mail'])['html'];
        self::assertSame(1, preg_match('/>\s*(\d{6})\s*</', $html, $m));

        $res = (new EmailVerificationController())->verifyEmail(new \WP_REST_Request(['email' => $email, 'code' => $m[1]]));
        self::assertSame(200, $res->get_status(), 'verification completes');
        $data = $res->get_data();
        self::assertIsArray($data);
        return $data['data'];
    }

    public function testVerifyResponseCountsTheLinkedWallets(): void
    {
        $this->wallet(503, self::OWNER, 'cosmos1second');

        $data = self::verify(self::OWNER, 'owner@example.com');

        self::assertSame(2, $data['linked_wallet_count'] ?? null);
        self::assertArrayNotHasKey('wallets', $data, 'no wallet detail in an auth response');
        self::assertStringNotContainsString(self::ADDRESS, (string) json_encode($data));
    }

    public function testVerifyResponseIsZeroWhenNoWalletIsLinked(): void
    {
        $this->user(self::STRANGER, 'nowallet@example.com');

        $data = self::verify(self::STRANGER, 'nowallet@example.com');

        self::assertSame(0, $data['linked_wallet_count'] ?? null);
    }

    public function testAFailedWalletReadDoesNotBlockVerification(): void
    {
        $GLOBALS['__bcc_wu']['read_fails'] = true;

        $data = self::verify(self::OWNER, 'owner@example.com');

        self::assertSame(0, $data['linked_wallet_count'] ?? null);
        self::assertSame('1', $GLOBALS['__bcc_ph']['meta'][self::OWNER][AuthSupport::META_EMAIL_VERIFIED]);
    }
}
