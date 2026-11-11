<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\OAuthController;
use BCC\Trust\Core\REST\Auth\WalletAuthController;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-123 + F-131 — /auth/wallet-login and /auth/oauth are called SERVER-SIDE by
 * the frontend, so every request arrives from one egress IP. Their per-IP
 * throttles were therefore ONE shared bucket: anyone could lock out every
 * wallet login (5/min) or every Google/X sign-in (10/min).
 *
 * Now (REAL controllers, counting Throttle stub):
 *   - per IDENTITY buckets: wallet = chain + lower-cased address (5/min),
 *     oauth = provider + provider_id (10/min), each with an EXPLICIT key
 *     (never split or merged by IP), plus a 300/min route-wide ceiling;
 *   - exhausting one identity's bucket does not touch another's;
 *   - wallet-login counts only requests that carry a server-issued nonce for
 *     that address (a bare junk request does not count — though nonces for
 *     any address can be minted via /auth/wallet-nonce); /auth/oauth counts
 *     only requests that pass the signed bridge gate.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/wallet-login-throttle-stubs.php,
 * then tests/Stubs/account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PerIdentityThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/wallet-login-throttle-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users'             => [],
            'meta'              => [],
            'transients'        => [],
            'mail'              => [],
            'actions'           => [],
            'options'           => [],
            'throttle_counting' => true,
        ];
        $GLOBALS['__bcc_wl'] = ['nonces' => []];
    }

    // ── /auth/oauth (F-131) ─────────────────────────────────────────────

    private static function oauth(string $providerId, bool $signed = true): int
    {
        $params = ['provider' => 'google', 'provider_id' => $providerId, 'email' => '', 'display_name' => 'G'];
        $req    = new \WP_REST_Request($params);
        if ($signed) {
            $ts    = (string) time();
            $nonce = bin2hex(random_bytes(8));
            $req->set_header('x-bcc-oauth-timestamp', $ts);
            $req->set_header('x-bcc-oauth-nonce', $nonce);
            $req->set_header('x-bcc-oauth-signature', hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $req->get_body(), (string) BCC_OAUTH_BRIDGE_SECRET));
        }
        return (new OAuthController())->oauthLogin($req)->get_status();
    }

    private static function oauthKey(string $providerId): string
    {
        return 'oauth_login:' . substr(hash('sha256', "google\n" . $providerId), 0, 32);
    }

    public function testOauthIsThrottledPerProviderIdentityNotShared(): void
    {
        for ($i = 1; $i <= AuthSupport::OAUTH_LOGIN_RATE_LIMIT; $i++) {
            self::assertSame(200, self::oauth('sub-a'), "sign-in #$i for identity A");
        }

        self::assertSame(429, self::oauth('sub-a'), "A's 11th within the minute is limited");
        self::assertSame(200, self::oauth('sub-b'), "B is NOT locked out by A's bucket");
        self::assertContains(self::oauthKey('sub-a'), $GLOBALS['__bcc_ph']['throttle_keys']);
    }

    public function testUnsignedOauthCallsNeverTouchABucket(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            self::assertSame(401, self::oauth('sub-a', signed: false));
        }

        self::assertSame([], $GLOBALS['__bcc_ph']['throttle_keys'] ?? [], 'the bridge gate runs first');
        self::assertSame(200, self::oauth('sub-a'), 'the real identity is unaffected');
    }

    public function testTheOauthRouteCeilingAppliesAcrossIdentities(): void
    {
        $GLOBALS['__bcc_ph']['throttle_hits']['oauth_login_global'] = AuthSupport::OAUTH_LOGIN_GLOBAL_LIMIT;

        self::assertSame(429, self::oauth('sub-fresh'));
        self::assertSame(['auth_throttle:oauth_login_ceiling'], $GLOBALS['__bcc_ph']['metrics'] ?? [], 'a tripped ceiling is counted, never silent');
    }

    public function testAPerIdentityLimitDoesNotCountAsACeilingTrip(): void
    {
        for ($i = 1; $i <= AuthSupport::OAUTH_LOGIN_RATE_LIMIT + 1; $i++) {
            self::oauth('sub-a');
        }

        self::assertSame([], $GLOBALS['__bcc_ph']['metrics'] ?? []);
    }

    // ── /auth/wallet-login (F-123) ──────────────────────────────────────

    private static function walletLogin(string $address, bool $withNonce = true): int
    {
        if ($withNonce) {
            $GLOBALS['__bcc_wl']['nonces'][$address] = ($GLOBALS['__bcc_wl']['nonces'][$address] ?? 0) + 1;
        }
        $req = new \WP_REST_Request(['wallet_address' => $address, 'signature' => 'sig']);
        return (new WalletAuthController())->walletLogin($req)->get_status();
    }

    private static function walletKey(string $address): string
    {
        return 'wallet_login:' . substr(hash('sha256', "4\n" . strtolower($address)), 0, 32);
    }

    public function testWalletLoginIsThrottledPerWalletNotShared(): void
    {
        for ($i = 1; $i <= AuthSupport::WALLET_LOGIN_RATE_LIMIT; $i++) {
            self::assertSame(401, self::walletLogin('cosmos1walleta'), "attempt #$i reaches the verify (bad sig → 401)");
        }

        self::assertSame(429, self::walletLogin('cosmos1walleta'), "wallet A's 6th within the minute is limited");
        self::assertSame(401, self::walletLogin('cosmos1walletb'), 'wallet B is NOT locked out by A');
        self::assertContains(self::walletKey('cosmos1walleta'), $GLOBALS['__bcc_ph']['throttle_keys']);
    }

    public function testJunkWithoutANonceCannotDrainAWalletsBucket(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            self::assertSame(400, self::walletLogin('cosmos1victim', withNonce: false));
        }

        self::assertNotContains(self::walletKey('cosmos1victim'), $GLOBALS['__bcc_ph']['throttle_keys'] ?? []);
        self::assertSame(401, self::walletLogin('cosmos1victim'), 'the owner still reaches the verify');
    }

    public function testCaseVariantsOfAnAddressShareABucket(): void
    {
        for ($i = 1; $i <= AuthSupport::WALLET_LOGIN_RATE_LIMIT; $i++) {
            self::walletLogin('cosmos1CaseWallet');
        }

        self::assertSame(429, self::walletLogin('COSMOS1CASEWALLET'), 'fail-closed: no extra budget from case variants');
    }

    public function testTheWalletRouteCeilingAppliesAcrossWallets(): void
    {
        $GLOBALS['__bcc_ph']['throttle_hits']['wallet_login_global'] = AuthSupport::WALLET_LOGIN_GLOBAL_LIMIT;

        self::assertSame(429, self::walletLogin('cosmos1freshwallet'));
        self::assertSame(['auth_throttle:wallet_login_ceiling'], $GLOBALS['__bcc_ph']['metrics'] ?? []);
    }
}
