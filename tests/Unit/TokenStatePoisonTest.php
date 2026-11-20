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
 * Here get_user_meta() answers a STALE cache that holds no token state
 * (account-prehijack-stubs' store), while the database (TokenStateWpdb)
 * holds the truth. The REAL JwtToken, BearerAuth and TokenStateRepository
 * run on top, and every decision must follow the database. Swapping the
 * read back to get_user_meta() makes these tests fail.
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

    /**
     * A token's claims, read WITHOUT checking it (it may be revoked).
     *
     * @return array<string, mixed>
     */
    private function payloadOfAny(string $token): array
    {
        $parts = explode('.', $token);
        $json  = base64_decode(strtr($parts[1] ?? '', '-_', '+/'), true);
        $data  = is_string($json) ? json_decode($json, true) : null;
        self::assertIsArray($data);

        return $data;
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

    /** Several users at once: each is read from the database. */
    public function testEveryUserIsReadFromTheDatabase(): void
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

    // ── Revoking and minting read the database too ────────────────────────

    /** +1 on the poisoned cache's 0 would write 1 over 5: a LOWER version. */
    public function testRevokeCountsOnFromTheDatabaseNotThePoisonedCache(): void
    {
        $this->db->put(self::USER, 'bcc_token_version', 5);

        self::assertSame(6, JwtToken::revokeAllForUser(self::USER));
        self::assertSame('6', (string) $GLOBALS['__bcc_ph']['meta'][self::USER]['bcc_token_version']);
    }

    /** Unknown: still revoke, with a version no outstanding token can carry. */
    public function testRevokeWithAnUnknownVersionJumpsAboveEveryToken(): void
    {
        $this->db->put(self::USER, 'bcc_token_version', 5);
        $this->db->mode = 'error';
        $floor = (int) floor(microtime(true) * 1000);

        $next = JwtToken::revokeAllForUser(self::USER);

        self::assertGreaterThanOrEqual($floor, $next, 'above every counter and every earlier jump');
        self::assertSame((string) $next, (string) $GLOBALS['__bcc_ph']['meta'][self::USER]['bcc_token_version'], 'and written: the revocation happened');
    }

    /** Before, a sign-in during the poison minted tv 0, and the token failed at once. */
    public function testASignInDuringThePoisonMintsTheDatabaseVersion(): void
    {
        $this->db->put(self::USER, 'bcc_token_version', 5);

        $token = JwtToken::encodeNewSession(self::USER, 'h' . self::USER);

        self::assertSame(5, $this->payloadOf($token)['tv']);
    }

    /** A mint whose version can't be read still mints, and that token fails closed. */
    public function testAMintWithAnUnknownVersionFailsClosed(): void
    {
        $this->db->put(self::USER, 'bcc_token_version', 5);
        $this->db->mode = 'error';
        $token = JwtToken::encodeNewSession(self::USER, 'h' . self::USER);
        $this->db->mode = 'ok';

        self::assertSame(JwtToken::ERR_REVOKED, JwtToken::decode($token)['error'] ?? null, 'the cache said 0; the database says 5');
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

    // ── F-495: a duplicated row never hides a revocation or a sign-out ─────
    //
    // update_metadata() inserts a SECOND row when its look-up of the first
    // fails. The newer row holds the write; the first row is the stale one.

    public function testARevocationInADuplicatedVersionRowStands(): void
    {
        $this->db->put(self::USER, 'bcc_token_version', 5); // the original row
        $old = JwtToken::encodeNewSession(self::USER, 'h' . self::USER);
        $this->db->put(self::USER, 'bcc_token_version', 6); // the revoke, inserted beside it
        $new = JwtToken::encodeNewSession(self::USER, 'h' . self::USER);

        self::assertSame(5, $this->payloadOfAny($old)['tv']);
        self::assertSame(6, $this->payloadOf($new)['tv'], 'minted with the revoked-to version');
        self::assertSame(JwtToken::ERR_REVOKED, JwtToken::decode($old)['error'] ?? null);
        self::assertTrue(JwtToken::decode($new)['ok'], 'the token minted after the revocation works');
        self::assertSame(6, JwtToken::tokenVersion(self::USER));
    }

    public function testASignOutInADuplicatedDenylistRowStands(): void
    {
        $token = self::tokenWithTv(self::USER, 0);
        $jti   = (string) $this->payloadOf($token)['jti'];
        $this->db->put(self::USER, 'bcc_jwt_denylist', ['someone-else' => time() + 3600]);
        $this->db->put(self::USER, 'bcc_jwt_denylist', [self::hash($jti) => time() + 3600]);

        self::assertSame(JwtToken::ERR_LOGGED_OUT, JwtToken::decode($token)['error'] ?? null);
    }

    public function testARetirementInADuplicatedRetiredRowStands(): void
    {
        $token = self::tokenWithTv(self::USER, 0);
        $jti   = (string) $this->payloadOf($token)['jti'];
        $this->db->put(self::USER, 'bcc_jwt_retired', ['someone-else' => ['from' => 1, 'until' => time() + 3600]]);
        $this->db->put(self::USER, 'bcc_jwt_retired', [self::hash($jti) => ['from' => time() - 10, 'until' => time() + 3600]]);

        self::assertSame(JwtToken::ERR_SUPERSEDED, JwtToken::decode($token)['error'] ?? null);
    }

    /** The same token in two rows: signed out until the LATER of the two. */
    public function testADenylistEntryInTwoRowsLastsUntilTheLater(): void
    {
        $token = self::tokenWithTv(self::USER, 0);
        $jti   = (string) $this->payloadOf($token)['jti'];
        $this->db->put(self::USER, 'bcc_jwt_denylist', [self::hash($jti) => time() + 3600]);
        $this->db->put(self::USER, 'bcc_jwt_denylist', [self::hash($jti) => time() - 3600]);

        self::assertSame(JwtToken::ERR_LOGGED_OUT, JwtToken::decode($token)['error'] ?? null);
    }

    /** The same token in two rows: retired over the WIDEST window (earliest from, latest until). */
    public function testARetiredEntryInTwoRowsTakesTheWidestWindow(): void
    {
        $token = self::tokenWithTv(self::USER, 0);
        $jti   = (string) $this->payloadOf($token)['jti'];
        // The NEWER row alone would not retire it yet (its from is ahead).
        $this->db->put(self::USER, 'bcc_jwt_retired', [self::hash($jti) => ['from' => time() - 10, 'until' => time() + 60]]);
        $this->db->put(self::USER, 'bcc_jwt_retired', [self::hash($jti) => ['from' => time() + 600, 'until' => time() + 3600]]);

        self::assertSame(JwtToken::ERR_SUPERSEDED, JwtToken::decode($token)['error'] ?? null, 'the earlier from');

        // The NEWER row alone would have let it go (its until is past).
        $this->db->rows = [];
        $this->db->put(self::USER, 'bcc_jwt_retired', [self::hash($jti) => ['from' => time() - 600, 'until' => time() + 3600]]);
        $this->db->put(self::USER, 'bcc_jwt_retired', [self::hash($jti) => ['from' => time() - 10, 'until' => time() - 5]]);

        self::assertSame(JwtToken::ERR_SUPERSEDED, JwtToken::decode($token)['error'] ?? null, 'the later until');
    }

    // ── F-495: a write that did not land is never claimed ────────────────

    public function testARevokeWhoseWriteFailsAnswersNull(): void
    {
        $this->db->put(self::USER, 'bcc_token_version', 5);
        $GLOBALS['__bcc_ph']['fail_update']['bcc_token_version'] = true;

        self::assertNull(JwtToken::revokeAllForUser(self::USER), 'nothing was revoked, so no version is claimed');
        self::assertSame(['bcc_token_version'], $GLOBALS['__bcc_ph']['failed_updates'] ?? null);
    }

    public function testALogOutWhoseWriteFailsAnswersFalse(): void
    {
        $payload = $this->payloadOf(self::tokenWithTv(self::USER, 0));
        $GLOBALS['__bcc_ph']['fail_update']['bcc_jwt_denylist'] = true;

        self::assertFalse(JwtToken::logOut($payload));
    }

    public function testARetireWhoseWriteFailsAnswersFalse(): void
    {
        $payload = $this->payloadOf(self::tokenWithTv(self::USER, 0));
        $GLOBALS['__bcc_ph']['fail_update']['bcc_jwt_retired'] = true;

        self::assertFalse(JwtToken::retireAfterRefresh($payload));
    }

    /**
     * update_user_meta() answers false for an UNCHANGED value too. Retiring
     * a token that is already retired changes nothing, so nothing is written
     * and that is not a failure.
     */
    public function testRetiringAnAlreadyRetiredTokenWritesNothingAndSucceeds(): void
    {
        $payload = $this->payloadOf(self::tokenWithTv(self::USER, 0));
        $this->db->put(self::USER, 'bcc_jwt_retired', [self::hash((string) $payload['jti']) => ['from' => time() + 60, 'until' => time() + 3600]]);
        $GLOBALS['__bcc_ph']['fail_update']['bcc_jwt_retired'] = true;

        self::assertTrue(JwtToken::retireAfterRefresh($payload));
        self::assertArrayNotHasKey('failed_updates', $GLOBALS['__bcc_ph'], 'no write was attempted');
    }
}
