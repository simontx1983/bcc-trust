<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\WalletAuthController;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-25 — a junk wallet sign-in can no longer burn the owner's challenge.
 *
 * /auth/wallet-nonce issues a challenge for any address, and wallet-login /
 * wallet-signup used to CONSUME it before checking the signature, so anyone
 * could burn the challenge a wallet owner was about to sign. Pinned against
 * the REAL WalletAuthController::walletLogin / walletSignup:
 *   - a bad signature consumes nothing (401) — the owner's challenge stays;
 *   - a good signature consumes exactly the challenge it verified against,
 *     by chain id — never "the first one" (EVM addresses have one live
 *     challenge per chain);
 *   - no live challenge → 400; replay → 400; a lost consume race → 400;
 *   - each candidate is verified against its OWN stored message with its own
 *     chain's verifier; a candidate whose chain is gone is skipped.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/wallet-signup-stubs.php,
 * tests/Stubs/wallet-login-throttle-stubs.php, then
 * tests/Stubs/account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WalletSignInVerifyBeforeConsumeTest extends TestCase
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
        ];
        $GLOBALS['__bcc_wl'] = [
            'challenges' => [self::ADDR => [
                ['chain_id' => 7, 'message' => 'msg-eth'],
                ['chain_id' => 8, 'message' => 'msg-base'],
            ]],
            'valid'      => ['evm|msg-base|good-sig'],
            'consumed'   => [],
            'verified'   => [],
        ];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }

            /** @return null — no wallet link, no other owner */
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

    private static function login(string $sig, string $address = self::ADDR): int
    {
        $req = new \WP_REST_Request(['wallet_address' => $address, 'signature' => $sig]);
        return (new WalletAuthController())->walletLogin($req)->get_status();
    }

    private static function signup(string $sig): int
    {
        $req = new \WP_REST_Request(['wallet_address' => self::ADDR, 'signature' => $sig, 'handle' => 'newhandle']);
        return (new WalletAuthController())->walletSignup($req)->get_status();
    }

    /** @return list<int> chain ids still live for ADDR */
    private static function live(): array
    {
        return array_values(array_map(static fn(array $c): int => (int) $c['chain_id'], $GLOBALS['__bcc_wl']['challenges'][self::ADDR]));
    }

    public function testABadLoginSignatureBurnsNothing(): void
    {
        self::assertSame(401, self::login('junk'));

        self::assertSame([], $GLOBALS['__bcc_wl']['consumed'], 'nothing consumed');
        self::assertSame([7, 8], self::live(), "the owner's challenges are intact");
        self::assertSame(['evm|msg-eth|junk', 'evm|msg-base|junk'], $GLOBALS['__bcc_wl']['verified'], 'each candidate against its own stored message');
    }

    public function testAGoodLoginConsumesExactlyTheChallengeItSigned(): void
    {
        self::assertSame(404, self::login('good-sig'), 'verified, consumed, then "no account linked"');

        self::assertSame([self::ADDR . '#8'], $GLOBALS['__bcc_wl']['consumed'], 'by chain id — not the first live one (chain 7)');
        self::assertSame([7], self::live(), 'the other chain is untouched');
    }

    public function testAReplayedLoginSignatureIsRejected(): void
    {
        self::login('good-sig');

        self::assertSame(401, self::login('good-sig'), 'its challenge is gone; the remaining one does not verify');
    }

    public function testALostConsumeRaceIs400(): void
    {
        $GLOBALS['__bcc_wl']['consume_fails'] = true;

        self::assertSame(400, self::login('good-sig'));
    }

    public function testNoLiveChallengeIs400AndVerifiesNothing(): void
    {
        self::assertSame(400, self::login('good-sig', '0x0000000000000000000000000000000000000009'));
        self::assertSame([], $GLOBALS['__bcc_wl']['verified']);
    }

    public function testACandidateWhoseChainIsGoneIsSkipped(): void
    {
        array_unshift($GLOBALS['__bcc_wl']['challenges'][self::ADDR], ['chain_id' => 99, 'message' => 'msg-gone']);

        self::assertSame(404, self::login('good-sig'));
        self::assertSame(
            [],
            array_values(array_filter($GLOBALS['__bcc_wl']['verified'], static fn(string $c): bool => str_contains($c, 'msg-gone'))),
            'never verified at all'
        );
    }

    public function testABadSignupSignatureBurnsNothing(): void
    {
        self::assertSame(401, self::signup('junk'));

        self::assertSame([], $GLOBALS['__bcc_wl']['consumed']);
        self::assertSame([7, 8], self::live());
        self::assertSame([], $GLOBALS['__bcc_wl']['inserted'] ?? [], 'no account is created');
    }

    public function testAGoodSignupConsumesExactlyTheChallengeItSigned(): void
    {
        self::assertSame(500, self::signup('good-sig'), 'verified, consumed, then the stub user insert fails');

        self::assertSame([self::ADDR . '#8'], $GLOBALS['__bcc_wl']['consumed']);
        self::assertSame([7], self::live());
        self::assertSame(['u_newhandle'], $GLOBALS['__bcc_wl']['inserted'] ?? []);
    }

    public function testASignupThatLosesTheConsumeRaceCreatesNoAccount(): void
    {
        $GLOBALS['__bcc_wl']['consume_fails'] = true;

        self::assertSame(400, self::signup('good-sig'));
        self::assertSame([], $GLOBALS['__bcc_wl']['inserted'] ?? []);
    }
}
