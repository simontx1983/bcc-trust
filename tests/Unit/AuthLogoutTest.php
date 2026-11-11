<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\SessionController;
use BCC\Trust\Core\Support\JwtToken;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-120 — POST /auth/logout signs out ONE bearer token server-side.
 *
 * Before, "sign out" only cleared the browser's NextAuth cookie; a copied
 * bearer stayed valid until expiry. Pinned against the REAL SessionController
 * + JwtToken + BearerAuth header reader:
 *   - after logout the token fails decode() AND decodeForRefresh() with
 *     ERR_LOGGED_OUT, and GET /auth/session answers 401;
 *   - the denylist entry lives through exp + skew + the refresh grace;
 *   - other tokens of the same user keep working;
 *   - always 200 { ok: true }, idempotent — missing / invalid token included —
 *     and an invalid token stores nothing;
 *   - a token inside its refresh-grace window is signed out too;
 *   - per-token throttle with an explicit key; no-store + Vary.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/auth-session-stubs.php, then
 * tests/Stubs/account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthLogoutTest extends TestCase
{
    private const USER = 80;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/auth-session-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users'      => [self::USER => ['email' => 'u80@example.com', 'login' => 'u80', 'display' => 'U80']],
            'meta'       => [self::USER => ['bcc_handle' => 'handle80']],
            'transients' => [],
            'mail'       => [],
            'actions'    => [],
            'options'    => [],
        ];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('no database in unit tests');
            }
        };
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: \WP_REST_Response} */
    private static function logout(?string $authorization): array
    {
        if ($authorization === null) {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        } else {
            $_SERVER['HTTP_AUTHORIZATION'] = $authorization;
        }
        $res  = (new SessionController())->logout(new \WP_REST_Request([]));
        $data = $res->get_data();
        self::assertIsArray($data);
        return [$res->get_status(), $data, $res];
    }

    private static function sessionStatus(string $token): int
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        return (new SessionController())->session(new \WP_REST_Request([]))->get_status();
    }

    /** @return array<string, string> */
    private static function headers(\WP_REST_Response $r): array
    {
        $p = new \ReflectionProperty($r, 'headers');
        $p->setAccessible(true);
        /** @var array<string, string> $h */
        $h = $p->getValue($r);
        return $h;
    }

    public function testLogoutSignsOutThatTokenEverywhereItIsChecked(): void
    {
        $token = JwtToken::encode(self::USER, 'handle80');
        self::assertTrue(JwtToken::decode($token)['ok'], 'control: valid before logout');
        self::assertSame(200, self::sessionStatus($token), 'control: /auth/session accepts it');

        [$status, $body, $res] = self::logout('Bearer ' . $token);

        self::assertSame(200, $status);
        self::assertSame(['ok' => true], $body['data']);
        self::assertSame('no-store', self::headers($res)['Cache-Control'] ?? null);
        self::assertSame('Authorization', self::headers($res)['Vary'] ?? null);

        self::assertSame(['ok' => false, 'error' => JwtToken::ERR_LOGGED_OUT], JwtToken::decode($token));
        self::assertSame(['ok' => false, 'error' => JwtToken::ERR_LOGGED_OUT], JwtToken::decodeForRefresh($token), 'cannot be refreshed back to life');
        self::assertSame(401, self::sessionStatus($token));
    }

    public function testOtherTokensOfTheSameUserKeepWorking(): void
    {
        $signedOut = JwtToken::encode(self::USER, 'handle80');
        $otherDevice = JwtToken::encode(self::USER, 'handle80');

        self::logout('Bearer ' . $signedOut);

        self::assertTrue(JwtToken::decode($otherDevice)['ok'], 'single-session logout, not logout-everywhere');
    }

    public function testTheDenylistOutlivesTheRefreshGraceWindow(): void
    {
        $token = JwtToken::encode(self::USER, 'handle80');
        $exp   = (int) JwtToken::decode($token)['payload']['exp'];

        self::logout('Bearer ' . $token);

        $deny = array_values(self::denylist());
        self::assertCount(1, $deny);
        self::assertGreaterThanOrEqual(
            $exp + JwtToken::SKEW_SECONDS + JwtToken::REFRESH_GRACE_SECONDS - 2,
            $deny[0],
            'a refreshable token must stay denied until it could no longer be refreshed'
        );
    }

    /** @return array<string, int> */
    private static function denylist(): array
    {
        $raw = $GLOBALS['__bcc_ph']['meta'][self::USER][JwtToken::DENYLIST_META_KEY] ?? [];
        return is_array($raw) ? $raw : [];
    }

    public function testAnExpiredDenylistEntryNoLongerDenies(): void
    {
        $token = JwtToken::encode(self::USER, 'handle80', ['jti' => 'reused']);
        $GLOBALS['__bcc_ph']['meta'][self::USER][JwtToken::DENYLIST_META_KEY] = [
            substr(hash('sha256', 'reused'), 0, 40) => time() - 1,
        ];

        self::assertTrue(JwtToken::decode($token)['ok'], 'an entry past its until is inert even before pruning');
    }

    public function testExpiredEntriesArePrunedOnWrite(): void
    {
        $GLOBALS['__bcc_ph']['meta'][self::USER][JwtToken::DENYLIST_META_KEY] = ['stale' => time() - 10, 'live' => time() + 600];

        JwtToken::logOut(['jti' => 'new', 'user_id' => self::USER, 'exp' => time() + 60]);

        $list = self::denylist();
        self::assertArrayNotHasKey('stale', $list);
        self::assertArrayHasKey('live', $list);
        self::assertCount(2, $list);
    }

    public function testTheWriteIsSerialisedPerUserAndReadsFresh(): void
    {
        self::logout('Bearer ' . JwtToken::encode(self::USER, 'handle80'));

        self::assertSame(['acquire:bcc_jwt_deny_80', 'release:bcc_jwt_deny_80'], $GLOBALS['__bcc_ph']['locks'] ?? []);
        self::assertSame(['user_meta:80'], $GLOBALS['__bcc_ph']['cache_deletes'] ?? [], 're-read under the lock');
    }

    public function testAnUnavailableLockStillSignsTheTokenOut(): void
    {
        $GLOBALS['__bcc_ph']['lock_deny'] = true;
        $token = JwtToken::encode(self::USER, 'handle80');

        [$status] = self::logout('Bearer ' . $token);

        self::assertSame(200, $status);
        self::assertSame(JwtToken::ERR_LOGGED_OUT, JwtToken::decode($token)['error'] ?? null);
        self::assertSame(['acquire:bcc_jwt_deny_80'], $GLOBALS['__bcc_ph']['locks'], 'never releases a lock it did not take');
    }

    public function testTheListIsCappedKeepingTheLatestExpiring(): void
    {
        for ($i = 0; $i < JwtToken::DENYLIST_MAX_ENTRIES + 5; $i++) {
            JwtToken::logOut(['jti' => 'j' . $i, 'user_id' => self::USER, 'exp' => time() + $i]);
        }

        $list = self::denylist();
        self::assertCount(JwtToken::DENYLIST_MAX_ENTRIES, $list);
        self::assertSame(
            ['ok' => false, 'error' => JwtToken::ERR_LOGGED_OUT],
            JwtToken::decode(JwtToken::encode(self::USER, 'handle80', ['jti' => 'j' . (JwtToken::DENYLIST_MAX_ENTRIES + 4)])),
            'the newest (latest-expiring) entry survives the cap'
        );
    }

    public function testATokenInsideItsRefreshGraceIsSignedOutToo(): void
    {
        $token = JwtToken::encode(self::USER, 'handle80', ['exp' => time() - 3600]); // expired, still refreshable
        self::assertTrue(JwtToken::decodeForRefresh($token)['ok'], 'control: refreshable before logout');

        self::logout('Bearer ' . $token);

        self::assertSame(JwtToken::ERR_LOGGED_OUT, JwtToken::decodeForRefresh($token)['error'] ?? null);
    }

    public function testMissingOrInvalidTokensAre200AndStoreNothing(): void
    {
        foreach ([null, 'Basic abc', 'Bearer not.a.jwt'] as $auth) {
            [$status, $body] = self::logout($auth);
            self::assertSame(200, $status, 'logout never fails visibly');
            self::assertSame(['ok' => true], $body['data']);
        }

        self::assertSame([], self::denylist());
    }

    public function testLogoutIsIdempotent(): void
    {
        $token = 'Bearer ' . JwtToken::encode(self::USER, 'handle80');

        self::assertSame(200, self::logout($token)[0]);
        self::assertSame(200, self::logout($token)[0], 'a second logout of the same token is still 200');
    }

    public function testTheThrottleIsPerTokenWithAnExplicitKey(): void
    {
        $GLOBALS['__bcc_ph']['throttle_deny'] = true;
        $raw = JwtToken::encode(self::USER, 'handle80');

        [$status, $body, $res] = self::logout('Bearer ' . $raw);

        self::assertSame(429, $status);
        self::assertSame('bcc_rate_limited', $body['error']['code']);
        self::assertSame('no-store', self::headers($res)['Cache-Control'] ?? null);
        self::assertSame(['auth_logout:' . substr(hash('sha256', $raw), 0, 32)], $GLOBALS['__bcc_ph']['throttle_keys']);
        self::assertTrue(JwtToken::decode($raw)['ok'], 'a throttled logout signs nothing out');
    }
}
