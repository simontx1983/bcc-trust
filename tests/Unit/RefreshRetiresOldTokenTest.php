<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\SessionController;
use BCC\Trust\Core\Support\JwtToken;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-282 (backend half) — POST /auth/refresh retires the token it replaces.
 *
 * Before, a refresh minted a new token and left the old one fully live
 * until exp + the refresh grace: a copy taken before the refresh (or one a
 * sign-out never reached) stayed usable for days. Pinned against the REAL
 * SessionController::refresh + JwtToken:
 *   - the old token keeps working for REFRESH_OVERLAP_SECONDS (other tabs
 *     and in-flight requests of the same session switch over; the frontend
 *     signs the member out when a refresh is refused), then fails decode()
 *     AND decodeForRefresh() with ERR_SUPERSEDED;
 *   - the new token is unaffected;
 *   - the FIRST retirement wins: refreshing the same token again during the
 *     overlap never pushes its cutoff later (no keep-alive loop);
 *   - a logout during the overlap still kills it at once;
 *   - the entry lives as long as the token could be used; expired entries
 *     are pruned; written under the per-user lock;
 *   - a refused refresh retires nothing.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/auth-session-stubs.php, then
 * tests/Stubs/account-prehijack-stubs.php, then the suspension stub below.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RefreshRetiresOldTokenTest extends TestCase
{
    private const USER = 81;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/auth-session-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        require_once __DIR__ . '/../Stubs/refresh-suspension-stub.php';
        $GLOBALS['__bcc_ph'] = [
            'users'      => [self::USER => ['email' => 'u81@example.com', 'login' => 'u81', 'display' => 'U81']],
            'meta'       => [self::USER => ['bcc_handle' => 'handle81']],
            'transients' => [],
            'mail'       => [],
            'actions'    => [],
            'options'    => [],
        ];
        $GLOBALS['__bcc_suspended'] = false;
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('no database in unit tests');
            }
        };
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function refresh(string $token): array
    {
        $req = new \WP_REST_Request([]);
        $req->set_header('Authorization', 'Bearer ' . $token);
        $res  = (new SessionController())->refresh($req);
        $data = $res->get_data();
        self::assertIsArray($data);
        return [$res->get_status(), $data['data'] ?? $data];
    }

    /** @return array<string, array{from: int, until: int}> */
    private static function retired(): array
    {
        $raw = $GLOBALS['__bcc_ph']['meta'][self::USER][JwtToken::RETIRED_META_KEY] ?? [];
        return is_array($raw) ? $raw : [];
    }

    private static function hashOf(string $token): string
    {
        return substr(hash('sha256', (string) JwtToken::decode($token)['payload']['jti']), 0, 40);
    }

    /** Move the overlap into the past, as if REFRESH_OVERLAP_SECONDS had elapsed. */
    private static function endOverlap(string $hash): void
    {
        $GLOBALS['__bcc_ph']['meta'][self::USER][JwtToken::RETIRED_META_KEY][$hash]['from'] = time() - 1;
    }

    public function testTheOldTokenWorksThroughTheOverlapThenNeverAgain(): void
    {
        $old  = JwtToken::encode(self::USER, 'handle81');
        $hash = self::hashOf($old);

        [$status, $data] = self::refresh($old);
        self::assertSame(200, $status);
        $new = (string) $data['token'];

        self::assertTrue(JwtToken::decode($old)['ok'], 'still usable during the overlap');
        $entry = self::retired()[$hash];
        self::assertEqualsWithDelta(time() + JwtToken::REFRESH_OVERLAP_SECONDS, $entry['from'], 2);

        self::endOverlap($hash);

        self::assertSame(['ok' => false, 'error' => JwtToken::ERR_SUPERSEDED], JwtToken::decode($old));
        self::assertSame(['ok' => false, 'error' => JwtToken::ERR_SUPERSEDED], JwtToken::decodeForRefresh($old), 'cannot be refreshed back to life');
        [$status] = self::refresh($old);
        self::assertSame(401, $status);

        self::assertTrue(JwtToken::decode($new)['ok'], 'the replacement is unaffected');
    }

    public function testRefreshingTheSameTokenAgainNeverExtendsItsLife(): void
    {
        $old  = JwtToken::encode(self::USER, 'handle81');
        $hash = self::hashOf($old);

        self::refresh($old);
        $GLOBALS['__bcc_ph']['meta'][self::USER][JwtToken::RETIRED_META_KEY][$hash]['from'] = time() + 5;

        [$status] = self::refresh($old);
        self::assertSame(200, $status, 'a second tab refreshing during the overlap still gets a token');
        self::assertSame(time() + 5, self::retired()[$hash]['from'], 'the first cutoff stands');
    }

    public function testTheEntryLastsAsLongAsTheTokenCouldBeUsed(): void
    {
        $old = JwtToken::encode(self::USER, 'handle81');
        $exp = (int) JwtToken::decode($old)['payload']['exp'];

        self::refresh($old);

        self::assertGreaterThanOrEqual(
            $exp + JwtToken::SKEW_SECONDS + JwtToken::REFRESH_GRACE_SECONDS - 2,
            self::retired()[self::hashOf($old)]['until']
        );
    }

    public function testALogoutDuringTheOverlapStillEndsItAtOnce(): void
    {
        $old = JwtToken::encode(self::USER, 'handle81');
        self::refresh($old);

        JwtToken::logOut((array) JwtToken::decodeForRefresh($old)['payload']);

        self::assertSame(JwtToken::ERR_LOGGED_OUT, JwtToken::decode($old)['error'] ?? null);
    }

    public function testExpiredEntriesArePrunedAndTheWriteIsLocked(): void
    {
        $GLOBALS['__bcc_ph']['meta'][self::USER][JwtToken::RETIRED_META_KEY] = [
            'stale' => ['from' => time() - 100, 'until' => time() - 10],
            'live'  => ['from' => time() - 100, 'until' => time() + 600],
        ];

        self::refresh(JwtToken::encode(self::USER, 'handle81'));

        self::assertArrayNotHasKey('stale', self::retired());
        self::assertArrayHasKey('live', self::retired());
        self::assertCount(2, self::retired());
        self::assertSame(['acquire:bcc_jwt_deny_81', 'release:bcc_jwt_deny_81'], $GLOBALS['__bcc_ph']['locks'] ?? []);
    }

    public function testARefusedRefreshRetiresNothing(): void
    {
        $GLOBALS['__bcc_suspended'] = true;
        $old = JwtToken::encode(self::USER, 'handle81');

        [$status] = self::refresh($old);

        self::assertSame(403, $status);
        self::assertSame([], self::retired());
        self::assertTrue(JwtToken::decode($old)['ok']);
    }

    public function testAMalformedEntryIsIgnored(): void
    {
        $old = JwtToken::encode(self::USER, 'handle81');
        $GLOBALS['__bcc_ph']['meta'][self::USER][JwtToken::RETIRED_META_KEY] = [self::hashOf($old) => 'garbage'];

        self::assertTrue(JwtToken::decode($old)['ok']);
    }
}
