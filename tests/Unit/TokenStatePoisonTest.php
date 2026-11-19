<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\BearerAuth;
use BCC\Trust\Core\Support\JwtToken;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-462 (HIGH): a token's revocation state comes from the DATABASE, not the
 * meta cache.
 *
 * WordPress's update_meta_cache() caches an EMPTY array for a user when its
 * meta SELECT fails, and batch priming can do that to many users at once.
 * Read through get_user_meta(), tv was then 0 and both lists empty. So a
 * revoked, signed-out or refresh-retired token was ACCEPTED. With a
 * persistent cache that lasted until the user's meta was next written.
 *
 * Here get_user_meta() answers that POISONED cache (account-prehijack-stubs'
 * store holds no token state). The database (TokenStateWpdb) holds the
 * truth. The REAL JwtToken, BearerAuth and TokenStateRepository run on top.
 * Swapping the read back to get_user_meta() makes these tests fail.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TokenStatePoisonTest extends TestCase
{
    private const USER  = 90;
    private const USERS = [90, 91, 92];

    private \TokenStateWpdb $db;

    protected function setUp(): void
    {
        parent::setUp();
        // The real repository first, so the stand-in steps aside.
        require_once __DIR__ . '/../Stubs/token-state-wpdb-stubs.php';
        require_once __DIR__ . '/../Stubs/envelope-meta-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        require_once __DIR__ . '/../Stubs/wp-signin-gate-stubs.php';
        require_once __DIR__ . '/../Stubs/rest-request-stubs.php';
        $GLOBALS['__bcc_envelope_meta_throttle'] = ['allow' => true];
        $GLOBALS['__bcc_ph'] = [
            'users'      => [],
            'meta'       => [],
            'transients' => [],
            'mail'       => [],
            'actions'    => [],
            'options'    => [],
        ];
        foreach (self::USERS as $id) {
            $GLOBALS['__bcc_ph']['users'][$id] = ['email' => "u{$id}@example.com", 'login' => "u{$id}", 'display' => "U{$id}"];
            // The POISONED cache: the user's meta is cached, but empty of token state.
            $GLOBALS['__bcc_ph']['meta'][$id] = ['bcc_handle' => "h{$id}"];
        }
        $GLOBALS['__bcc_sig'] = ['admins' => [], 'current' => 0, 'ajax' => false, 'hooks' => []];
        $this->db = new \TokenStateWpdb();
        $GLOBALS['wpdb'] = $this->db;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/wp-json/bcc/v1/me/account';
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /** A token minted while the cache said tv = $tv. */
    private static function tokenWithTv(int $userId, int $tv): string
    {
        $GLOBALS['__bcc_ph']['meta'][$userId]['bcc_token_version'] = $tv;
        $token = JwtToken::encodeNewSession($userId, "h{$userId}");
        unset($GLOBALS['__bcc_ph']['meta'][$userId]['bcc_token_version']); // and the cache is poisoned again

        return $token;
    }

    private static function hash(string $jti): string
    {
        return substr(hash('sha256', $jti), 0, 40);
    }

    /** @return array<string, mixed> */
    private function payloadOf(string $token): array
    {
        $decoded = JwtToken::decode($token);
        self::assertTrue($decoded['ok'], 'the token is good before the state says otherwise');

        return $decoded['payload'];
    }

    // ── The poisoned cache no longer accepts dead tokens ──────────────────

    public function testARevokedTokenIsRefusedThoughTheCacheSaysNeverRevoked(): void
    {
        $old = self::tokenWithTv(self::USER, 0);
        $this->db->put(self::USER, 'bcc_token_version', 5); // signed out everywhere since

        $decoded = JwtToken::decode($old);

        self::assertFalse($decoded['ok']);
        self::assertSame(JwtToken::ERR_REVOKED, $decoded['error']);
    }

    /** The side effect: before, a legitimate tv >= 1 token was REFUSED while the cache said 0. */
    public function testALegitimateTokenIsAcceptedDuringThePoison(): void
    {
        $this->db->put(self::USER, 'bcc_token_version', 5);
        $good = self::tokenWithTv(self::USER, 5);

        self::assertTrue(JwtToken::decode($good)['ok']);
        self::assertTrue(JwtToken::decodeForRefresh($good)['ok']);
    }

    public function testASignedOutTokenIsRefused(): void
    {
        $token = self::tokenWithTv(self::USER, 0);
        $jti   = (string) $this->payloadOf($token)['jti'];
        $this->db->put(self::USER, 'bcc_jwt_denylist', [self::hash($jti) => time() + 3600]);

        self::assertSame(JwtToken::ERR_LOGGED_OUT, JwtToken::decode($token)['error'] ?? null);
    }

    public function testARetiredTokenIsRefusedAfterItsOverlap(): void
    {
        $token = self::tokenWithTv(self::USER, 0);
        $jti   = (string) $this->payloadOf($token)['jti'];
        $this->db->put(self::USER, 'bcc_jwt_retired', [self::hash($jti) => ['from' => time() - 10, 'until' => time() + 3600]]);

        self::assertSame(JwtToken::ERR_SUPERSEDED, JwtToken::decode($token)['error'] ?? null);
    }

    /** Batch priming poisons many users at once; each is still read from the database. */
    public function testABatchPrimedPoisonRestoresNobodysDeadTokens(): void
    {
        $dead = [];
        $live = [];
        foreach (self::USERS as $i => $id) {
            $dead[$id] = self::tokenWithTv($id, 0);
            $this->db->put($id, 'bcc_token_version', $i + 2);
            $live[$id] = self::tokenWithTv($id, $i + 2);
        }

        foreach (self::USERS as $id) {
            self::assertSame(JwtToken::ERR_REVOKED, JwtToken::decode($dead[$id])['error'] ?? null, "user {$id}'s revoked token");
            self::assertTrue(JwtToken::decode($live[$id])['ok'], "user {$id}'s current token");
        }
    }

    // ── A failed read refuses the token: never "empty" ────────────────────

    /** @return iterable<string, array{0: string}> */
    public static function failedReads(): iterable
    {
        yield 'a driver error'                         => ['error'];
        yield 'query() false before flush, stale rows' => ['false_early'];
        yield 'an error beside rows'                   => ['error_rows'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failedReads')]
    public function testAFailedReadRefusesTheToken(string $mode): void
    {
        $token = self::tokenWithTv(self::USER, 0);
        $this->db->last_result = [(object) ['meta_key' => 'bcc_token_version', 'meta_value' => '0']];
        $this->db->mode = $mode;

        foreach ([JwtToken::decode($token), JwtToken::decodeForRefresh($token)] as $decoded) {
            self::assertFalse($decoded['ok']);
            self::assertSame(JwtToken::ERR_STATE_UNAVAILABLE, $decoded['error']);
        }
    }

    public function testBearerAuthDoesNotSignInAndAnswers503(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::tokenWithTv(self::USER, 0);
        $this->db->mode = 'error';

        self::assertFalse(BearerAuth::authenticateBearer(false), 'not authenticated');
        self::assertSame(0, BearerAuth::authenticatedUserId());
        self::assertSame(BearerAuth::TOKEN_UNAVAILABLE, BearerAuth::presentedTokenFailure());

        $refusal = BearerAuth::refuseUnknownTokenState(null);
        self::assertInstanceOf(\WP_Error::class, $refusal);
        self::assertSame('bcc_unavailable', $refusal->get_error_code());
        self::assertTrue(BearerAuth::noCacheWhenTokenStateUnknown(false), 'private, no-store');
    }

    public function testTheRefusalIsHookedFirstWithNoCacheHeaders(): void
    {
        BearerAuth::register();

        self::assertContains(['rest_authentication_errors', [BearerAuth::class, 'refuseUnknownTokenState'], 1, 1], $GLOBALS['__bcc_sig']['hooks']);
        self::assertContains(['rest_send_nocache_headers', [BearerAuth::class, 'noCacheWhenTokenStateUnknown'], 10, 1], $GLOBALS['__bcc_sig']['hooks']);
    }

    /** Only the unknown state is refused; an earlier error stands. */
    public function testTheRefusalLeavesEverythingElseAlone(): void
    {
        // A good token: no failure recorded.
        $this->db->put(self::USER, 'bcc_token_version', 0);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::tokenWithTv(self::USER, 0);
        self::assertSame(self::USER, BearerAuth::authenticateBearer(false));
        self::assertNull(BearerAuth::refuseUnknownTokenState(null));
        self::assertTrue(BearerAuth::refuseUnknownTokenState(true));
        self::assertFalse(BearerAuth::noCacheWhenTokenStateUnknown(false));

        // The unknown state, after an earlier filter's own error: that error stands.
        $this->db->mode = 'error';
        BearerAuth::authenticateBearer(false);
        $earlier = new \WP_Error('rest_forbidden', 'no');
        self::assertSame($earlier, BearerAuth::refuseUnknownTokenState($earlier));
    }

    // ── Never write over a list that could not be read ────────────────────

    public function testLogOutWritesNothingWhenTheDenylistCannotBeRead(): void
    {
        $token   = self::tokenWithTv(self::USER, 0);
        $payload = $this->payloadOf($token);
        $this->db->put(self::USER, 'bcc_jwt_denylist', ['someone-else' => time() + 3600]);
        $this->db->mode = 'error';

        self::assertFalse(JwtToken::logOut($payload));
        self::assertArrayNotHasKey('bcc_jwt_denylist', $GLOBALS['__bcc_ph']['meta'][self::USER], 'no write: it would drop the other signed-out tokens');
    }

    public function testRetireAfterRefreshWritesNothingWhenTheListCannotBeRead(): void
    {
        $token   = self::tokenWithTv(self::USER, 0);
        $payload = $this->payloadOf($token);
        $this->db->mode = 'error';

        self::assertFalse(JwtToken::retireAfterRefresh($payload));
        self::assertArrayNotHasKey('bcc_jwt_retired', $GLOBALS['__bcc_ph']['meta'][self::USER]);
    }

    public function testLogOutKeepsTheOtherEntriesWhenItCanRead(): void
    {
        $token   = self::tokenWithTv(self::USER, 0);
        $payload = $this->payloadOf($token);
        $this->db->put(self::USER, 'bcc_jwt_denylist', ['someone-else' => time() + 3600]);

        self::assertTrue(JwtToken::logOut($payload));
        $written = $GLOBALS['__bcc_ph']['meta'][self::USER]['bcc_jwt_denylist'] ?? [];
        self::assertArrayHasKey('someone-else', $written, 'built on the DATABASE list, not the poisoned cache');
        self::assertArrayHasKey(self::hash((string) $payload['jti']), $written);
    }
}
