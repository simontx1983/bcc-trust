<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\LoginStepUp;
use BCC\Trust\Core\REST\Auth\PasswordAuthController;
use BCC\Trust\Core\Services\HandleService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Captcha step-up on POST /auth/login. App sign-ins bypass Wordfence; the
 * step-up asks for a Cloudflare Turnstile token only when something looks
 * wrong. Pinned against the REAL login handler, the real LoginStepUp and
 * TurnstileVerifier, over the counting Throttle double and a stubbed
 * SafeHttpClient (no real HTTP):
 *   - OFF without both keys: no captcha, no extra writes;
 *   - a normal sign-in never sees it;
 *   - the 11th wrong password for one identifier in 15 min turns it on for
 *     that identifier (the 10th does not); a site-wide failure spike turns
 *     it on for everyone, and every failure over that threshold is counted
 *     (so a spike crosses the alerter's threshold);
 *   - while on: no token → 429 bcc_captcha_required (with the site key),
 *     checked before the password, and Turnstile is never called;
 *     a valid token passes; an invalid one is refused and charges nothing;
 *     an unknown error code fails CLOSED;
 *   - a Turnstile outage or a bad secret fails OPEN to the throttles, logged
 *     and counted;
 *   - a network the account recently signed in from never sees it, and its
 *     failures don't count toward the identifier;
 *   - an identifier with no account behaves exactly like one with
 *     (existence-blind).
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/handle-rename-identity-stubs.php,
 * tests/Stubs/login-captcha-stubs.php, then tests/Stubs/account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LoginCaptchaStepUpTest extends TestCase
{
    private const ALICE = 10;
    private const IP    = '198.51.100.20';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/handle-rename-identity-stubs.php';
        require_once __DIR__ . '/../Stubs/login-captcha-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users'             => [self::ALICE => ['email' => 'alice@example.test', 'login' => 'u_alice', 'display' => 'Alice']],
            // Unverified, so a correct password stops at 403 — past the captcha.
            'meta'              => [self::ALICE => [HandleService::META_HANDLE => 'alice', AuthSupport::META_EMAIL_VERIFIED => '0']],
            'passwords'         => [self::ALICE => 'right'],
            'transients'        => [],
            'mail'              => [],
            'actions'           => [],
            'options'           => ['bcc_turnstile_site_key' => 'site-key-test', 'bcc_turnstile_secret_key' => 'secret-test'],
            'current_user'      => 0,
            'throttle_counting' => true,
            'client_ip'         => self::IP,
        ];
        $_SERVER['REMOTE_ADDR'] = self::IP;
        $GLOBALS['__bcc_cap'] = ['posts' => []];
    }

    /** @return array{0: int, 1: array<string, mixed>} status and decoded body */
    private static function login(string $identifier, string $password, ?string $token = null): array
    {
        // The per-network `login` bucket (20/min) isn't what these cases exercise.
        unset($GLOBALS['__bcc_ph']['throttle_hits']['login']);
        $params = ['identifier' => $identifier, 'password' => $password];
        if ($token !== null) {
            $params['captcha_token'] = $token;
        }
        $response = (new PasswordAuthController())->login(new \WP_REST_Request($params));
        $data     = $response->get_data();
        return [$response->get_status(), is_array($data) ? $data : []];
    }

    private static function failTimes(string $identifier, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            self::assertSame(401, self::login($identifier, 'wrong' . $i)[0], "failure #$i is an ordinary 401");
        }
    }

    private static function reply(bool $success, array $codes = [], int $status = 200): void
    {
        $GLOBALS['__bcc_cap']['reply'] = [
            'response' => ['code' => $status],
            'body'     => (string) json_encode(['success' => $success, 'error-codes' => $codes]),
        ];
    }

    private static function code(array $body): string
    {
        return (string) ($body['error']['code'] ?? '');
    }

    /** @return list<string> */
    private static function metrics(): array
    {
        return $GLOBALS['__bcc_ph']['metrics'] ?? [];
    }

    private static function netKey(string $typed): string
    {
        return 'bcc_throttle_login_account_net_' . hash('sha256', 'id:' . hash('sha256', $typed) . '|' . AuthSupport::loginNetwork());
    }

    public function testOffWithoutBothKeys(): void
    {
        unset($GLOBALS['__bcc_ph']['options']['bcc_turnstile_secret_key']);
        self::failTimes('alice', 15);

        self::assertSame(401, self::login('alice', 'still-wrong')[0], 'never a captcha');
        self::assertSame([], $GLOBALS['__bcc_cap']['posts']);
        self::assertSame([], preg_grep('/^bcc_login_stepup_/', array_keys($GLOBALS['__bcc_ph']['transients'])), 'no step-up state written');
        self::assertSame([], preg_grep('/^bcc_login_fail_/', array_keys($GLOBALS['__bcc_ph']['throttle_hits'] ?? [])), 'no failure counters either');
    }

    public function testANormalSignInNeverSeesACaptcha(): void
    {
        self::failTimes('alice', 2);

        self::assertSame(403, self::login('alice', 'right')[0], 'past the password, stopped by the unverified email');
        self::assertSame([], $GLOBALS['__bcc_cap']['posts']);
    }

    public function testTheEleventhFailureForOneIdentifierTurnsItOn(): void
    {
        self::failTimes('alice', LoginStepUp::IDENTIFIER_FAILURES);
        self::assertSame(401, self::login('alice', 'wrong-11')[0], 'the 11th is still asked for no captcha — and records the trip');

        [$status, $body] = self::login('alice', 'right');

        self::assertSame(429, $status, 'checked BEFORE the password: even the right one needs the captcha');
        self::assertSame('bcc_captcha_required', self::code($body));
        self::assertSame(['provider' => 'turnstile', 'site_key' => 'site-key-test'], $body['error']['data']['captcha'] ?? null);
        self::assertSame([], $GLOBALS['__bcc_cap']['posts'], 'no token, so Turnstile is never called');
        self::assertSame([], self::metrics(), 'one identifier is not a site-wide spike');
    }

    public function testTheStepUpIsPerIdentifier(): void
    {
        self::failTimes('alice', LoginStepUp::IDENTIFIER_FAILURES + 1);

        self::assertSame(401, self::login('bob', 'whatever')[0], "another identifier isn't asked");
    }

    public function testAValidTokenPassesWithTheCallersIp(): void
    {
        self::failTimes('alice', LoginStepUp::IDENTIFIER_FAILURES + 1);
        self::reply(true);

        self::assertSame(403, self::login('alice', 'right', 'tok-good')[0], 'past the captcha and the password');

        self::assertCount(1, $GLOBALS['__bcc_cap']['posts']);
        $post = $GLOBALS['__bcc_cap']['posts'][0];
        self::assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $post['url']);
        self::assertSame(['secret' => 'secret-test', 'response' => 'tok-good', 'remoteip' => self::IP], $post['args']['body']);
    }

    public function testAnInvalidTokenIsRefusedAndChargesNothing(): void
    {
        self::failTimes('alice', LoginStepUp::IDENTIFIER_FAILURES + 1);
        $ceiling = 'bcc_throttle_login_account_' . hash('sha256', 'id:' . hash('sha256', 'alice'));
        $hits    = static fn (string $k): int => (int) ($GLOBALS['__bcc_ph']['throttle_hits'][$k] ?? 0);
        $before  = [$hits(self::netKey('alice')), $hits($ceiling)];
        self::reply(false, ['invalid-input-response']);

        [$status, $body] = self::login('alice', 'right', 'tok-bad');

        self::assertSame(429, $status);
        self::assertSame('bcc_captcha_required', self::code($body));
        self::assertSame($before, [$hits(self::netKey('alice')), $hits($ceiling)], 'the refusal gave back the per-network and ceiling slots');
    }

    public function testAnUnknownErrorCodeFailsClosed(): void
    {
        self::failTimes('alice', LoginStepUp::IDENTIFIER_FAILURES + 1);
        self::reply(false, ['something-new']);

        self::assertSame(429, self::login('alice', 'right', 'tok-odd')[0], 'never a bypass from a code we do not know');
    }

    public function testAnOversizedTokenIsRefusedWithoutCallingTurnstile(): void
    {
        self::failTimes('alice', LoginStepUp::IDENTIFIER_FAILURES + 1);
        self::reply(true);

        self::assertSame(429, self::login('alice', 'right', str_repeat('x', 2049))[0]);
        self::assertSame([], $GLOBALS['__bcc_cap']['posts']);
    }

    public function testATurnstileOutageFailsOpenToTheThrottles(): void
    {
        self::failTimes('alice', LoginStepUp::IDENTIFIER_FAILURES + 1);
        $GLOBALS['__bcc_cap']['reply'] = new \WP_Error('http_request_failed', 'timed out');

        self::assertSame(401, self::login('alice', 'wrong-again', 'tok')[0], 'the password is checked as before');
        self::assertSame(403, self::login('alice', 'right', 'tok')[0], 'and the owner still gets in');
        self::assertSame(['auth_throttle:turnstile_unavailable', 'auth_throttle:turnstile_unavailable'], self::metrics());
    }

    public function testANon200FailsOpen(): void
    {
        self::failTimes('alice', LoginStepUp::IDENTIFIER_FAILURES + 1);
        self::reply(true, [], 502);

        self::assertSame(403, self::login('alice', 'right', 'tok')[0]);
        self::assertSame(['auth_throttle:turnstile_unavailable'], self::metrics());
    }

    public function testABadSecretFailsOpen(): void
    {
        self::failTimes('alice', LoginStepUp::IDENTIFIER_FAILURES + 1);
        self::reply(false, ['invalid-input-secret']);

        self::assertSame(403, self::login('alice', 'right', 'tok')[0], 'our misconfiguration never locks members out');
        self::assertSame(['auth_throttle:turnstile_unavailable'], self::metrics());
    }

    public function testATrustedNetworkNeverSeesItAndIsNotCounted(): void
    {
        $GLOBALS['__bcc_ph']['meta'][self::ALICE][AuthSupport::META_TRUSTED_LOGIN_NETWORKS] = [hash('sha256', AuthSupport::loginNetwork()) => time()];

        self::failTimes('alice', LoginStepUp::IDENTIFIER_FAILURES + 5);

        self::assertSame(403, self::login('alice', 'right')[0], 'the owner at home is never asked');
        self::assertSame([], preg_grep('/^bcc_login_stepup_id_/', array_keys($GLOBALS['__bcc_ph']['transients'])), "home failures don't trip the identifier");

        // Even with the identifier tripped from elsewhere, home stays exempt.
        $typedKey = 'id:' . hash('sha256', 'alice');
        $GLOBALS['__bcc_ph']['transients']['bcc_login_stepup_id_' . substr(hash('sha256', $typedKey), 0, 32)] = 1;
        self::assertSame(403, self::login('alice', 'right')[0], 'nor when the identifier is tripped from elsewhere');

        $GLOBALS['__bcc_ph']['transients']['bcc_login_stepup_site'] = 1;
        self::assertSame(403, self::login('alice', 'right')[0], 'nor during a site-wide spike');
        self::assertSame([], $GLOBALS['__bcc_cap']['posts']);
    }

    public function testASiteWideSpikeTurnsItOnForEveryoneAndCountsEachFailure(): void
    {
        $GLOBALS['__bcc_ph']['throttle_hits']['bcc_login_fail_site'] = LoginStepUp::SITE_FAILURES;

        self::assertSame(401, self::login('bob', 'wrong')[0], 'the failure that crosses the threshold');
        self::assertSame(['auth_throttle:login_failure_spike'], self::metrics());

        self::assertSame(429, self::login('carol', 'anything')[0], 'an identifier with no failures of its own is asked too');
        self::assertSame(429, self::login('alice', 'right')[0]);

        self::reply(true);
        self::assertSame(401, self::login('dave', 'wrong', 'tok')[0], 'another failure while it is on');
        self::assertSame(
            ['auth_throttle:login_failure_spike', 'auth_throttle:login_failure_spike'],
            self::metrics(),
            'every failure over the threshold counts, so a real wave reaches the alert threshold'
        );
    }

    public function testAnUnknownIdentifierIsTreatedExactlyLikeAnAccount(): void
    {
        self::failTimes('nobody@example.test', LoginStepUp::IDENTIFIER_FAILURES + 1);

        [$status, $body] = self::login('nobody@example.test', 'x');

        self::assertSame(429, $status, 'the step-up says nothing about whether the account exists');
        self::assertSame('bcc_captcha_required', self::code($body));
    }
}
