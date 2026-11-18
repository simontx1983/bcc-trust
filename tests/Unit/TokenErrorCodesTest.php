<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Envelope;
use BCC\Trust\Core\Support\BearerAuth;
use BCC\Trust\Core\Support\JwtToken;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-288 — the contract's `bcc_token_expired` / `bcc_token_invalid` (§1.4.3)
 * were never sent: a bearer token that failed made the request anonymous,
 * so a protected route said `bcc_unauthorized` and the client couldn't
 * tell "refresh" from "sign out".
 *
 * Pinned against the REAL BearerAuth::authenticateBearer + JwtToken +
 * Envelope::wrap:
 *   - a protected route's 401 `bcc_unauthorized` becomes
 *     `bcc_token_expired` for a plain expiry and `bcc_token_invalid` for a
 *     malformed / revoked / signed-out / superseded token or a deleted user;
 *   - no token presented, or a token that worked: `bcc_unauthorized` stays;
 *   - status and shape are unchanged; any other status or code, and routes
 *     outside bcc/v1, are untouched — a public route serving the anonymous
 *     view (200) is not a 401 and stays as it is.
 *
 * Subprocess-only; the envelope stubs load first (route-style REST
 * classes), then the account stubs (JWT, users, meta).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TokenErrorCodesTest extends TestCase
{
    private const USER = 90;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/envelope-meta-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_envelope_meta_throttle'] = ['allow' => true];
        $GLOBALS['__bcc_ph'] = [
            'users'      => [self::USER => ['email' => 'u90@example.com', 'login' => 'u90', 'display' => 'U90']],
            'meta'       => [self::USER => ['bcc_handle' => 'handle90']],
            'transients' => [],
            'mail'       => [],
            'actions'    => [],
            'options'    => [],
        ];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /** Authenticate as WordPress would, then wrap a 401 from a protected route. */
    private static function protectedRouteWith(?string $token, int $status = 401, string $code = 'bcc_unauthorized', string $route = '/bcc/v1/me/account'): array
    {
        if ($token === null) {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        } else {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        BearerAuth::authenticateBearer(false);

        $out = Envelope::wrap(
            new \WP_REST_Response(['error' => ['code' => $code, 'message' => 'Sign in required.', 'status' => $status], '_meta' => ['version' => 1]], $status),
            new \WP_REST_Server(),
            new \WP_REST_Request($route, 'GET')
        );
        $data = $out->get_data();
        self::assertIsArray($data);
        return [$out->get_status(), $data['error']['code'] ?? null, $data['error']['message'] ?? null];
    }

    private static function expiredToken(): string
    {
        return JwtToken::encode(self::USER, 'handle90', ['iat' => time() - 8 * 86400, 'exp' => time() - 3600]);
    }

    public function testAnExpiredTokenSaysRefresh(): void
    {
        [$status, $code, $message] = self::protectedRouteWith(self::expiredToken());

        self::assertSame(401, $status);
        self::assertSame('bcc_token_expired', $code);
        self::assertSame('Your session has expired. Refresh it and try again.', $message);
        self::assertSame(BearerAuth::TOKEN_EXPIRED, BearerAuth::presentedTokenFailure());
    }

    /** @return array<string, array{string}> */
    public static function deadTokens(): array
    {
        return [
            'malformed'     => ['malformed'],
            'bad signature' => ['bad_signature'],
            'revoked'       => ['revoked'],
            'signed out'    => ['logged_out'],
            'deleted user'  => ['deleted_user'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deadTokens')]
    public function testADeadTokenSaysSignOut(string $kind): void
    {
        $token = JwtToken::encode(self::USER, 'handle90');
        switch ($kind) {
            case 'malformed':
                $token = 'not-a-jwt';
                break;
            case 'bad_signature':
                $token = substr($token, 0, -4) . 'AAAA';
                break;
            case 'revoked':
                JwtToken::revokeAllForUser(self::USER);
                break;
            case 'logged_out':
                JwtToken::logOut((array) JwtToken::decode($token)['payload']);
                break;
            case 'deleted_user':
                unset($GLOBALS['__bcc_ph']['users'][self::USER]);
                break;
        }

        [$status, $code, $message] = self::protectedRouteWith($token);

        self::assertSame(401, $status);
        self::assertSame('bcc_token_invalid', $code);
        self::assertSame('Your session is no longer valid. Please sign in again.', $message);
    }

    public function testNoTokenOrAWorkingTokenKeepsUnauthorized(): void
    {
        self::assertSame([401, 'bcc_unauthorized', 'Sign in required.'], self::protectedRouteWith(null));

        // A working token on a route that still answers 401 (e.g. a
        // credential check failed): not a token problem.
        self::assertSame([401, 'bcc_unauthorized', 'Sign in required.'], self::protectedRouteWith(JwtToken::encode(self::USER, 'handle90')));
        self::assertNull(BearerAuth::presentedTokenFailure());
    }

    public function testAWorkingTokenClearsAnEarlierFailure(): void
    {
        self::protectedRouteWith(self::expiredToken());
        self::assertSame([401, 'bcc_unauthorized', 'Sign in required.'], self::protectedRouteWith(JwtToken::encode(self::USER, 'handle90')));
    }

    public function testOtherResponsesAreUntouched(): void
    {
        $bad = self::expiredToken();
        self::assertSame([403, 'bcc_forbidden', 'Sign in required.'], self::protectedRouteWith($bad, 403, 'bcc_forbidden'));
        self::assertSame([401, 'bcc_invalid_credentials', 'Sign in required.'], self::protectedRouteWith($bad, 401, 'bcc_invalid_credentials'));
        self::assertSame([401, 'bcc_unauthorized', 'Sign in required.'], self::protectedRouteWith($bad, 401, 'bcc_unauthorized', '/wp/v2/users/me'), 'outside bcc/v1');
    }

    public function testTheRefreshRoutesOwn401IsNotRelabelled(): void
    {
        // A token past the refresh grace: refresh refuses it. "Refresh and
        // retry" would be a loop, so it stays bcc_unauthorized — whatever
        // case the route arrives in.
        foreach (['/bcc/v1/auth/refresh', '/BCC/v1/Auth/Refresh', '/bcc/v1/auth/refresh/'] as $route) {
            self::assertSame(
                [401, 'bcc_unauthorized', 'Sign in required.'],
                self::protectedRouteWith(self::expiredToken(), 401, 'bcc_unauthorized', $route),
                $route
            );
        }
        self::assertSame('bcc_token_expired', self::protectedRouteWith(self::expiredToken(), 401, 'bcc_unauthorized', '/bcc/v1/auth/session')[1], 'other auth routes still say why');
    }

    public function testAPublicRouteStillServesTheAnonymousView(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::expiredToken();
        self::assertFalse(BearerAuth::authenticateBearer(false), 'the request is anonymous');

        $out = Envelope::wrap(
            new \WP_REST_Response(['data' => ['items' => []], '_meta' => ['version' => 1]], 200),
            new \WP_REST_Server(),
            new \WP_REST_Request('/bcc/v1/feed/hot', 'GET')
        );
        self::assertSame(200, $out->get_status());
        self::assertSame(['items' => []], $out->get_data()['data']);
    }
}
