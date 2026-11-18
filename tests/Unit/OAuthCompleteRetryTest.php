<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\OAuthController;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-294 (account flows): a second /auth/oauth-complete with the same
 * provider_token used to answer "That handle is already taken." (two submits
 * racing) or "Session expired." (a retry after the first had succeeded and
 * consumed the token). A retry is now the same sign-up:
 *   - submits for one token are serialised by an advisory lock; a submit
 *     that cannot get it within the wait answers a retryable 503 and
 *     creates nothing;
 *   - after a successful create, a retry with that token signs in as that
 *     account (fresh session, the same 201 shape) for OAUTH_RETRY_SECONDS.
 *
 * Against the REAL controller, over handle-rename-identity-stubs.php +
 * account-prehijack-stubs.php (whose AdvisoryLock stub records acquire /
 * release and can be told to refuse).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OAuthCompleteRetryTest extends TestCase
{
    private const TOKEN = 'provider-token-1';

    protected function setUp(): void
    {
        parent::setUp();
        if (!\function_exists('wp_generate_password')) {
            eval('function wp_generate_password(int $length = 12, bool $special = true, bool $extra = false): string { return str_repeat("p", $length); }');
        }
        require_once __DIR__ . '/../Stubs/handle-rename-identity-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users' => [], 'meta' => [], 'passwords' => [], 'transients' => [],
            'mail' => [], 'actions' => [], 'audit' => [], 'locks' => [],
        ];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('no database in unit tests');
            }
        };
        AuthSupport::storeOauthProviderToken(self::TOKEN, [
            'provider'       => 'google',
            'provider_id'    => 'google-sub-1',
            'email'          => 'newcomer@example.test',
            'email_verified' => true,
            'display_name'   => 'Newcomer',
        ]);
    }

    /** @return array{status: int, data: array<string, mixed>, code: string} */
    private static function complete(string $handle = 'newcomer', string $token = self::TOKEN): array
    {
        $resp = (new OAuthController())->oauthComplete(new \WP_REST_Request(['provider_token' => $token, 'handle' => $handle]));
        $body = $resp->get_data();
        return [
            'status' => $resp->get_status(),
            'data'   => is_array($body) ? (array) ($body['data'] ?? []) : [],
            'code'   => is_array($body) ? (string) ($body['error']['code'] ?? '') : '',
        ];
    }

    public function testARetryAfterSuccessSignsInAsTheSameAccount(): void
    {
        $first = self::complete();
        self::assertSame(201, $first['status']);
        $users = count($GLOBALS['__bcc_ph']['users']);
        $mails = count($GLOBALS['__bcc_ph']['mail']);

        $retry = self::complete();

        self::assertSame(201, $retry['status'], 'was "Session expired."');
        self::assertSame($first['data']['user_id'], $retry['data']['user_id']);
        self::assertSame('newcomer', $retry['data']['handle']);
        self::assertNotEmpty($retry['data']['token'], 'a fresh session');
        self::assertSame(array_keys($first['data']), array_keys($retry['data']), 'the same response shape');
        self::assertCount($users, $GLOBALS['__bcc_ph']['users'], 'no second account');
        self::assertCount($mails, $GLOBALS['__bcc_ph']['mail'], 'no second mail');
    }

    public function testARetryWithADifferentHandleIsStillTheSameAccount(): void
    {
        $first = self::complete('newcomer');

        $retry = self::complete('someone-else');

        self::assertSame(201, $retry['status']);
        self::assertSame($first['data']['user_id'], $retry['data']['user_id']);
        self::assertSame('newcomer', $retry['data']['handle'], 'the account the token already created');
    }

    public function testASubmitThatCannotTakeTheLockCreatesNothing(): void
    {
        // The other submit for this token holds the lock (the concurrent case).
        $GLOBALS['__bcc_ph']['lock_deny'] = true;

        $res = self::complete();

        self::assertSame(503, $res['status']);
        self::assertSame('bcc_unavailable', $res['code']);
        self::assertSame([], $GLOBALS['__bcc_ph']['users'], 'nothing created');
        self::assertNotNull(AuthSupport::peekOauthProviderToken(self::TOKEN), 'the token is intact for the retry');
    }

    public function testTheLockIsPerTokenAndAlwaysReleased(): void
    {
        self::complete();

        $locks = $GLOBALS['__bcc_ph']['locks'];
        self::assertCount(2, $locks);
        self::assertStringStartsWith('acquire:bcc_oauth_complete_', $locks[0]);
        self::assertSame(str_replace('acquire:', 'release:', $locks[0]), $locks[1]);
        self::assertStringNotContainsString(self::TOKEN, $locks[0], 'keyed on a hash, never the token');
    }

    public function testOnceTheRetryWindowHasPassedTheTokenIsSpent(): void
    {
        self::complete();
        // The completion marker expired.
        foreach (array_keys($GLOBALS['__bcc_ph']['transients']) as $key) {
            if (str_starts_with((string) $key, 'bcc_oauth_done_')) {
                unset($GLOBALS['__bcc_ph']['transients'][$key]);
            }
        }

        $res = self::complete();

        self::assertSame(400, $res['status']);
        self::assertSame('bcc_invalid_oauth_token', $res['code']);
    }

    public function testAnotherTokenIsNotTheSameSignup(): void
    {
        self::complete();
        AuthSupport::storeOauthProviderToken('provider-token-2', [
            'provider' => 'google', 'provider_id' => 'google-sub-2', 'email' => 'second@example.test',
            'email_verified' => true, 'display_name' => 'Second',
        ]);

        $other = self::complete('second', 'provider-token-2');

        self::assertSame(201, $other['status']);
        self::assertSame('second', $other['data']['handle']);
        self::assertCount(2, $GLOBALS['__bcc_ph']['users']);
    }
}
