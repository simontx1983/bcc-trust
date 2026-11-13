<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\PasswordAuthController;
use BCC\Trust\Core\Services\HandleService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-281 — a stranger can't lock a member out of password sign-in.
 *
 * The per-account budget was one bucket per typed identifier, account-wide,
 * counted before the password check (denials too): anyone who knew a public
 * handle could keep its owner locked out with ~1.4 requests a minute.
 * Pinned, running the REAL PasswordAuthController::login over a counting
 * Throttle double:
 *   - a stranger on another network exhausts only that network's budget;
 *     the owner, from theirs, still reaches the password check;
 *   - from one network the budget still caps a guesser at 20 / 15 min;
 *   - a handle and an email never share a budget, so a 429 can't link a
 *     guessed email to a public handle;
 *   - networks the owner signed in from lately skip the account ceiling;
 *   - a ceiling per typed identifier caps a guess spread over many
 *     networks — and can't link a handle to an email either;
 *   - an unknown identifier is throttled the same way and answers 401.
 *
 * Subprocess-only; setUp() loads the stubs before anything autoloads.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LoginLockoutPerNetworkTest extends TestCase
{
    private const ALICE = 10;
    private const HOME     = '198.51.100.20';
    private const STRANGER = '203.0.113.99';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/handle-rename-identity-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users'             => [self::ALICE => ['email' => 'alice@example.test', 'login' => 'u_alice', 'display' => 'Alice']],
            // Unverified, so a correct password stops at 403 — past the throttle.
            'meta'              => [self::ALICE => [HandleService::META_HANDLE => 'alice', AuthSupport::META_EMAIL_VERIFIED => '0']],
            'passwords'         => [self::ALICE => 'right'],
            'transients'        => [],
            'mail'              => [],
            'actions'           => [],
            'current_user'      => 0,
            'throttle_counting' => true,
        ];
    }

    private static function attempt(string $identifier, string $password, string $ip): int
    {
        $GLOBALS['__bcc_ph']['client_ip'] = $ip;
        // The per-IP `login` bucket (5/min) is not what these cases exercise.
        unset($GLOBALS['__bcc_ph']['throttle_hits']['login']);
        $req = new \WP_REST_Request(['identifier' => $identifier, 'password' => $password]);
        return (new PasswordAuthController())->login($req)->get_status();
    }

    public function testAStrangerElsewhereCannotLockTheOwnerOut(): void
    {
        // Past the account-wide ceiling: denied attempts must not spend it.
        for ($i = 0; $i < 250; $i++) {
            self::attempt('@alice', 'guess' . $i, self::STRANGER);
        }
        self::assertSame(429, self::attempt('@alice', 'guess', self::STRANGER), 'the stranger is capped');

        self::assertSame(403, self::attempt('alice', 'right', self::HOME), 'the owner reaches the password check');
    }

    public function testOneNetworkIsStillCappedAtTwenty(): void
    {
        for ($i = 0; $i < AuthSupport::LOGIN_ACCOUNT_RATE_LIMIT; $i++) {
            self::assertSame(401, self::attempt('alice', 'guess' . $i, self::STRANGER));
        }
        self::assertSame(429, self::attempt('alice', 'guess', self::STRANGER));
        self::assertSame(429, self::attempt('alice', 'guess', '203.0.113.200'), 'the same /24');
    }

    public function testAHandleAndAnEmailNeverShareABudgetSoA429LinksNothing(): void
    {
        // The probe: exhaust the public handle's budget, then try a guessed
        // email. If they shared a bucket, a 429 would reveal that the email
        // is that account's. The guessed (right) email answers exactly like
        // an email that belongs to nobody.
        for ($i = 0; $i < AuthSupport::LOGIN_ACCOUNT_RATE_LIMIT; $i++) {
            self::attempt('@alice', 'guess' . $i, self::STRANGER);
        }
        self::assertSame(429, self::attempt('alice', 'guess', self::STRANGER), '@alice and alice are the same typed text');

        self::assertSame(401, self::attempt('alice@example.test', 'guess', self::STRANGER));
        self::assertSame(401, self::attempt('nobody@example.test', 'guess', self::STRANGER));
    }

    public function testAGuessSpreadOverManyNetworksHitsTheAccountCeiling(): void
    {
        $per = AuthSupport::LOGIN_ACCOUNT_RATE_LIMIT;
        $networks = intdiv(AuthSupport::LOGIN_ACCOUNT_GLOBAL_RATE_LIMIT, $per);
        for ($n = 0; $n < $networks; $n++) {
            for ($i = 0; $i < $per; $i++) {
                self::attempt('alice', 'guess', '192.0.' . $n . '.1');
            }
        }
        self::assertSame(429, self::attempt('alice', 'right', self::HOME), 'account-wide ceiling reached');
    }

    private static function hitTheCeilingFromElsewhere(): void
    {
        $per = AuthSupport::LOGIN_ACCOUNT_RATE_LIMIT;
        for ($n = 0; $n < intdiv(AuthSupport::LOGIN_ACCOUNT_GLOBAL_RATE_LIMIT, $per); $n++) {
            for ($i = 0; $i < $per; $i++) {
                self::attempt('alice', 'guess', '192.0.' . $n . '.1');
            }
        }
    }

    public function testTheCeilingCannotLinkAHandleToAnEmailEither(): void
    {
        // The same probe, one level up: exhaust the ceiling through the
        // public handle across many networks, then try a guessed email from
        // a fresh, untrusted network. It answers like an unknown email.
        self::hitTheCeilingFromElsewhere();
        self::assertSame(429, self::attempt('alice', 'guess', '100.64.1.1'), 'the handle is at its ceiling');

        self::assertSame(401, self::attempt('alice@example.test', 'guess', '100.64.2.1'));
        self::assertSame(401, self::attempt('nobody@example.test', 'guess', '100.64.3.1'));
    }

    public function testANetworkTheOwnerSignedInFromSkipsTheCeiling(): void
    {
        // The owner completed a sign-in from home earlier.
        $GLOBALS['__bcc_ph']['client_ip'] = self::HOME;
        AuthSupport::recordTrustedLoginNetwork(self::ALICE);

        self::hitTheCeilingFromElsewhere();

        self::assertSame(403, self::attempt('alice', 'right', '198.51.100.77'), 'home /24: past the throttle');
        self::assertSame(429, self::attempt('alice', 'right', '100.64.9.9'), 'a network never signed in from hits the ceiling');
    }

    public function testTrustedNetworksAreBoundedAndExpire(): void
    {
        for ($i = 0; $i < AuthSupport::TRUSTED_LOGIN_NETWORKS_MAX + 3; $i++) {
            $GLOBALS['__bcc_ph']['client_ip'] = '10.' . $i . '.0.1';
            AuthSupport::recordTrustedLoginNetwork(self::ALICE);
        }
        $list = (array) get_user_meta(self::ALICE, AuthSupport::META_TRUSTED_LOGIN_NETWORKS, true);
        self::assertCount(AuthSupport::TRUSTED_LOGIN_NETWORKS_MAX, $list);

        $hash = hash('sha256', '203.0.113.0');
        $GLOBALS['__bcc_ph']['meta'][self::ALICE][AuthSupport::META_TRUSTED_LOGIN_NETWORKS] = [$hash => time() - AuthSupport::TRUSTED_LOGIN_NETWORKS_TTL - 1];
        self::assertFalse(AuthSupport::isTrustedLoginNetwork(self::ALICE, '203.0.113.0'), 'older than 30 days');
    }

    public function testOnlyACompletedSignInRecordsTheNetwork(): void
    {
        // A right password stops at the second factor here (unverified
        // account → 403): nothing is recorded by the password step itself.
        self::assertSame(403, self::attempt('alice', 'right', self::HOME));
        self::assertEmpty(get_user_meta(self::ALICE, AuthSupport::META_TRUSTED_LOGIN_NETWORKS, true));

        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/REST/Auth/TwoFactorController.php');
        self::assertSame(1, substr_count($src, 'AuthSupport::recordTrustedLoginNetwork($userId)'), 'recorded where the second factor completes');
    }

    public function testAnUnknownIdentifierIsThrottledAndAnswers401(): void
    {
        self::assertSame(401, self::attempt('nobody', 'guess', self::STRANGER));
        $keys = $GLOBALS['__bcc_ph']['throttle_keys'];
        self::assertCount(1, array_filter($keys, static fn (string $k): bool => str_starts_with($k, 'bcc_throttle_login_account_net_')));
        self::assertCount(1, array_filter($keys, static fn (string $k): bool => str_starts_with($k, 'bcc_throttle_login_account_') && !str_contains($k, '_net_')));
        for ($i = 0; $i < AuthSupport::LOGIN_ACCOUNT_RATE_LIMIT; $i++) {
            self::attempt('nobody', 'guess', self::STRANGER);
        }
        self::assertSame(429, self::attempt('nobody', 'guess', self::STRANGER));
    }
}
