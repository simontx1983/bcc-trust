<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\SessionController;
use BCC\Trust\Core\Support\JwtToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * GET /auth/session — the server, not a POST body, says whose session a
 * Bearer token is (frontend F-08: the NextAuth `bcc-verified` provider used to
 * build sessions from caller-supplied user_id / handle).
 *
 * Pinned against the REAL SessionController + JwtToken + BearerAuth header
 * reader:
 *   - a valid token → { user_id, handle (CURRENT meta), in_good_standing,
 *     expires_at (= the token's exp) }, no-store + Vary: Authorization;
 *   - missing / non-Bearer / garbage / expired / revoked / deleted-user →
 *     ONE 401 bcc_unauthorized with the same body (no reason leaked);
 *   - a valid WP cookie session does NOT rescue a bad Bearer (the route
 *     never consults get_current_user_id());
 *   - no handle → 409; the throttle is per TOKEN and runs before decode.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/auth-session-stubs.php, then
 * tests/Stubs/account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthSessionEndpointTest extends TestCase
{
    private const USER = 70;

    protected function setUp(): void
    {
        parent::setUp();
        // A logged-in WP cookie session for a DIFFERENT, real user (71) — the
        // route must ignore it.
        require_once __DIR__ . '/../Stubs/auth-session-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users'      => [],
            'meta'       => [],
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
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        foreach ([self::USER, 71] as $id) {
            $GLOBALS['__bcc_ph']['users'][$id] = ['email' => "u{$id}@example.com", 'login' => 'u' . $id, 'display' => 'U' . $id];
            $GLOBALS['__bcc_ph']['meta'][$id]  = ['bcc_handle' => 'handle' . $id];
        }
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: \WP_REST_Response} */
    private static function call(?string $authorization): array
    {
        if ($authorization !== null) {
            $_SERVER['HTTP_AUTHORIZATION'] = $authorization;
        }
        $res  = (new SessionController())->session(new \WP_REST_Request([]));
        $data = $res->get_data();
        self::assertIsArray($data);
        return [$res->get_status(), $data, $res];
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

    public function testAValidTokenReturnsTheServersViewOfTheSession(): void
    {
        $token   = JwtToken::encode(self::USER, 'stale-handle-in-token');
        $payload = JwtToken::decode($token);
        self::assertTrue($payload['ok']);

        [$status, $body, $res] = self::call('Bearer ' . $token);

        self::assertSame(200, $status);
        self::assertSame(
            [
                'user_id'          => self::USER,
                'handle'           => 'handle' . self::USER,     // CURRENT meta, not the token claim
                'in_good_standing' => true,                       // resolveInGoodStanding fails open
                'expires_at'       => (int) $payload['payload']['exp'],
            ],
            $body['data']
        );
        self::assertSame('no-store', self::headers($res)['Cache-Control'] ?? null);
        self::assertSame('Authorization', self::headers($res)['Vary'] ?? null);
    }

    public function testATokenWithinTheSkewWindowIsStillValid(): void
    {
        $exp = time() - 30; // past exp, inside the 60 s skew decode() allows
        [$status, $body] = self::call('Bearer ' . JwtToken::encode(self::USER, 'h', ['exp' => $exp]));

        self::assertSame(200, $status);
        self::assertSame($exp, $body['data']['expires_at']);
    }

    /** @return array<string, array{0: ?string}> */
    public static function rejected(): array
    {
        return [
            'no Authorization header' => [null],
            'non-Bearer scheme'       => ['Basic dXNlcjpwYXNz'],
            'empty Bearer'            => ['Bearer '],
            'garbage token'           => ['Bearer not.a.jwt'],
        ];
    }

    #[DataProvider('rejected')]
    public function testMissingOrMalformedCredentialsAreOneUniform401(?string $authorization): void
    {
        [$status, $body] = self::call($authorization);

        self::assertSame(401, $status);
        self::assertSame(['code' => 'bcc_unauthorized', 'message' => 'Not signed in.', 'status' => 401], $body['error']);
    }

    public function testAnExpiredTokenIs401(): void
    {
        [$status, $body] = self::call('Bearer ' . JwtToken::encode(self::USER, 'h', ['exp' => time() - 120]));

        self::assertSame(401, $status);
        self::assertSame('Not signed in.', $body['error']['message'], 'same body: no reason leaked');
    }

    public function testARevokedTokenIs401(): void
    {
        $token = JwtToken::encode(self::USER, 'h');
        JwtToken::revokeAllForUser(self::USER);

        [$status] = self::call('Bearer ' . $token);

        self::assertSame(401, $status);
    }

    public function testATokenForADeletedUserIs401(): void
    {
        $token = JwtToken::encode(self::USER, 'h');
        unset($GLOBALS['__bcc_ph']['users'][self::USER]);

        [$status] = self::call('Bearer ' . $token);

        self::assertSame(401, $status);
    }

    public function testAValidCookieSessionDoesNotRescueABadBearer(): void
    {
        // get_current_user_id() says user 71 is signed in by cookie.
        self::assertSame(71, get_current_user_id());

        [$status, $body] = self::call('Bearer forged.token.value');

        self::assertSame(401, $status, 'the route validates the PRESENTED token, never the cookie user');
        self::assertArrayNotHasKey('data', $body);
    }

    public function testAUserWithoutAHandleIs409(): void
    {
        $token = JwtToken::encode(self::USER, 'h');
        $GLOBALS['__bcc_ph']['meta'][self::USER]['bcc_handle'] = '';

        [$status, $body] = self::call('Bearer ' . $token);

        self::assertSame(409, $status);
        self::assertSame('bcc_invalid_state', $body['error']['code']);
    }

    public function testTheThrottleIsPerTokenAndRunsBeforeDecode(): void
    {
        $GLOBALS['__bcc_ph']['throttle_deny'] = true;

        [$status, $body] = self::call('Bearer not.a.jwt');

        self::assertSame(429, $status, 'a denied bucket answers before any decode (even for a garbage token)');
        self::assertSame('bcc_rate_limited', $body['error']['code']);
        self::assertSame(
            ['auth_session:' . substr(hash('sha256', 'not.a.jwt'), 0, 32)],
            $GLOBALS['__bcc_ph']['throttle_keys'],
            'keyed on a hash of the token — not the IP (every call comes from the BFF), not the raw token'
        );
    }

    public function testTheRouteIsRegisteredAsAGet(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../app/Domain/Core/REST/Auth/SessionController.php');
        self::assertMatchesRegularExpression(
            "#'/auth/session',\s*\[\s*'methods'\s*=>\s*WP_REST_Server::READABLE,\s*'callback'\s*=>\s*\[\\\$instance, 'session'\]#",
            $src
        );
        self::assertStringNotContainsString('get_current_user_id', self::sessionBody($src));
    }

    private static function sessionBody(string $src): string
    {
        $start = strpos($src, 'public function session(');
        $end   = strpos($src, 'private static function sessionUnauthorized(');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        // Strip comments: the docblock above names get_current_user_id on purpose.
        $out = '';
        foreach (token_get_all('<?php ' . substr($src, $start, $end - $start)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }
}
