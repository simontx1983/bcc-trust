<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\WalletAuthController;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Wallet-login MEDIUM: one anonymous client could make every wallet sign-in
 * 429. Junk signatures spent the per-wallet bucket and the 300/min route
 * ceiling, both counted BEFORE the signature check.
 *
 * Now, against the REAL WalletAuthController::walletLogin over the counting
 * Throttle double (which honours countDenied and release() as bcc-core does):
 *   - a signature that verifies against none of the address's challenges
 *     gives back both slots — the buckets count verified sign-ins only;
 *   - a refused call is not recorded;
 *   - what bounds junk instead is a cap per live challenge: at most
 *     WALLET_LOGIN_CHALLENGE_MAX_FAILURES checks, counted BEFORE verifying
 *     (so concurrent requests can't race past it), and the MAX-th bad
 *     signature deletes the challenge so the owner can get a fresh one;
 *   - up to MAX-1 bad signatures then the right one still signs in;
 *   - a fresh challenge starts a fresh count.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/wallet-signup-stubs.php (its DB
 * stub serves the wallet lookup after a verified signature),
 * tests/Stubs/wallet-login-throttle-stubs.php, then
 * tests/Stubs/account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WalletLoginChallengeCapTest extends TestCase
{
    private const ADDR = '0xabcdef0000000000000000000000000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/wallet-signup-stubs.php';
        require_once __DIR__ . '/../Stubs/wallet-login-throttle-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users' => [], 'meta' => [], 'transients' => [], 'mail' => [], 'actions' => [], 'options' => [],
            'throttle_counting' => true,
        ];
        $GLOBALS['__bcc_wl'] = [
            'challenges' => [self::ADDR => [['chain_id' => 7, 'message' => 'msg-1']]],
            'valid'      => ['evm|msg-1|good-sig'],
            'consumed'   => [],
            'verified'   => [],
        ];
        // A verified signature goes on to the wallet lookup: no account is
        // linked, so it ends 404 bcc_wallet_not_linked.
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }
            /** @return null */
            public function get_var(string $sql)
            {
                return null;
            }
            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('unexpected $wpdb->' . $name);
            }
        };
    }

    private static function login(string $sig): int
    {
        $req = new \WP_REST_Request(['wallet_address' => self::ADDR, 'signature' => $sig]);
        return (new WalletAuthController())->walletLogin($req)->get_status();
    }

    private static function hits(string $bucket): int
    {
        return (int) ($GLOBALS['__bcc_ph']['throttle_hits'][$bucket] ?? 0);
    }

    private static function walletKey(): string
    {
        return 'wallet_login:' . substr(hash('sha256', strtolower(self::ADDR)), 0, 32);
    }

    /** The per-challenge bucket identity: address, chain, nonce, message. */
    private static function challengeKey(string $prefix, int $chainId, string $message): string
    {
        return $prefix . ':' . substr(hash('sha256', implode("\n", [strtolower(self::ADDR), (string) $chainId, '', $message])), 0, 32);
    }

    /** @return list<int> chain ids still live for ADDR */
    private static function live(): array
    {
        return array_values(array_map(static fn (array $c): int => (int) $c['chain_id'], $GLOBALS['__bcc_wl']['challenges'][self::ADDR] ?? []));
    }

    public function testBadSignaturesSpendNeitherTheWalletsBucketNorTheCeiling(): void
    {
        for ($i = 1; $i < AuthSupport::WALLET_LOGIN_CHALLENGE_MAX_FAILURES; $i++) {
            self::assertSame(401, self::login('junk' . $i));
        }

        self::assertSame(0, self::hits(self::walletKey()), "the wallet's 5/min is untouched");
        self::assertSame(0, self::hits('wallet_login_global'), 'so is the route ceiling');
        self::assertSame([7], self::live(), 'and the challenge is still live');
    }

    public function testTheMaxthBadSignatureDeletesTheChallenge(): void
    {
        $max = AuthSupport::WALLET_LOGIN_CHALLENGE_MAX_FAILURES;
        for ($i = 1; $i < $max; $i++) {
            self::assertSame(401, self::login('junk' . $i));
            self::assertSame([], $GLOBALS['__bcc_wl']['consumed'], "bad signature #$i deletes nothing");
        }

        self::assertSame(401, self::login('junk' . $max));
        self::assertSame([self::ADDR . '#7'], $GLOBALS['__bcc_wl']['consumed'], 'the MAX-th deletes it, by chain id');
        self::assertSame([], self::live());

        self::assertSame(400, self::login('good-sig'), 'nothing left to sign in against: request a new nonce');
        self::assertCount($max, $GLOBALS['__bcc_wl']['verified'], 'never more than MAX verifies on one challenge');
    }

    public function testUpToMaxMinusOneBadSignaturesThenTheRightOneStillSignsIn(): void
    {
        for ($i = 1; $i < AuthSupport::WALLET_LOGIN_CHALLENGE_MAX_FAILURES; $i++) {
            self::login('junk' . $i);
        }

        self::assertSame(404, self::login('good-sig'), 'verified and consumed, then "no account linked"');
        self::assertSame(1, self::hits(self::walletKey()), 'a verified sign-in counts');
        self::assertSame(1, self::hits('wallet_login_global'));
    }

    public function testAChallengeOverTheAttemptCapIsDeletedWithoutVerifying(): void
    {
        // Concurrent requests already used every check this challenge has.
        $GLOBALS['__bcc_ph']['throttle_hits'][self::challengeKey('wallet_login_attempt', 7, 'msg-1')] = AuthSupport::WALLET_LOGIN_CHALLENGE_MAX_FAILURES;

        self::assertSame(400, self::login('good-sig'));

        self::assertSame([], $GLOBALS['__bcc_wl']['verified'], 'no verify at all');
        self::assertSame([self::ADDR . '#7'], $GLOBALS['__bcc_wl']['consumed'], 'the challenge is deleted');
        self::assertSame(0, self::hits(self::walletKey()), 'and the slots are given back');
        self::assertSame(0, self::hits('wallet_login_global'));
    }

    public function testEveryLiveChallengeOfTheAddressIsCapped(): void
    {
        // EVM: one live challenge per chain; a bad signature is checked against each.
        $GLOBALS['__bcc_wl']['challenges'][self::ADDR][] = ['chain_id' => 8, 'message' => 'msg-base'];

        for ($i = 1; $i <= AuthSupport::WALLET_LOGIN_CHALLENGE_MAX_FAILURES; $i++) {
            self::login('junk' . $i);
        }

        self::assertSame([self::ADDR . '#7', self::ADDR . '#8'], $GLOBALS['__bcc_wl']['consumed']);
        self::assertSame([], self::live());
    }

    public function testAFreshChallengeStartsAFreshCount(): void
    {
        for ($i = 1; $i <= AuthSupport::WALLET_LOGIN_CHALLENGE_MAX_FAILURES; $i++) {
            self::login('junk' . $i);
        }
        self::assertSame([], self::live());

        // The owner's next /auth/wallet-nonce issues a new challenge.
        $GLOBALS['__bcc_wl']['challenges'][self::ADDR] = [['chain_id' => 7, 'message' => 'msg-2']];
        $GLOBALS['__bcc_wl']['valid'][] = 'evm|msg-2|good-sig-2';

        self::assertSame(401, self::login('junk-again'), 'one bad signature on the new challenge');
        self::assertSame([7], self::live(), 'does not inherit the old count');
        self::assertSame(404, self::login('good-sig-2'), 'and the owner signs in');
    }

    public function testVerifiedSignInsStillCountPerWallet(): void
    {
        $limit = AuthSupport::WALLET_LOGIN_RATE_LIMIT;
        for ($i = 1; $i <= $limit; $i++) {
            $GLOBALS['__bcc_wl']['challenges'][self::ADDR] = [['chain_id' => 7, 'message' => 'msg-v' . $i]];
            $GLOBALS['__bcc_wl']['valid'][] = 'evm|msg-v' . $i . '|sig-v' . $i;
            self::assertSame(404, self::login('sig-v' . $i), "verified sign-in #$i");
        }

        $GLOBALS['__bcc_wl']['challenges'][self::ADDR] = [['chain_id' => 7, 'message' => 'msg-over']];
        self::assertSame(429, self::login('anything'), 'the 6th within the minute is limited');
    }

    public function testARefusedCallIsNotRecorded(): void
    {
        $GLOBALS['__bcc_ph']['throttle_hits'][self::walletKey()] = AuthSupport::WALLET_LOGIN_RATE_LIMIT;

        for ($i = 0; $i < 10; $i++) {
            self::assertSame(429, self::login('good-sig'));
        }

        self::assertSame(AuthSupport::WALLET_LOGIN_RATE_LIMIT, self::hits(self::walletKey()), 'retrying while refused never extends the block');
        self::assertSame(0, self::hits('wallet_login_global'), 'and gives back the ceiling slot it charged');
        self::assertSame([], $GLOBALS['__bcc_wl']['verified']);
    }

    public function testACeilingRefusalIsNotRecorded(): void
    {
        $GLOBALS['__bcc_ph']['throttle_hits']['wallet_login_global'] = AuthSupport::WALLET_LOGIN_GLOBAL_LIMIT;

        self::assertSame(429, self::login('good-sig'));

        self::assertSame(AuthSupport::WALLET_LOGIN_GLOBAL_LIMIT, self::hits('wallet_login_global'));
        self::assertSame(['auth_throttle:wallet_login_ceiling'], $GLOBALS['__bcc_ph']['metrics'] ?? [], 'still counted as a ceiling trip');
    }
}
