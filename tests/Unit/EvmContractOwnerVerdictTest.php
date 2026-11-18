<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\wallet\BlockchainQueryService as Q;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-14 PR C: BlockchainQueryService::evmContractOwnerVerdict.
 *
 * Only owner() naming the wallet is OWNER, and only a DIFFERENT non-zero
 * address is NOT_OWNER. Everything else is UNKNOWN, which never revokes and
 * never grants a claim. That includes the zero address (ownership
 * renounced: product decision 2026-11-13), a revert (no Ownable), a failed
 * call and an odd result.
 */
#[CoversClass(Q::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EvmContractOwnerVerdictTest extends TestCase
{
    private const WALLET   = '0x1111111111111111111111111111111111111111';
    private const OTHER    = '0x2222222222222222222222222222222222222222';
    private const CONTRACT = '0xAbCdEf0000000000000000000000000000000002';

    /** @var list<array{string, string}> */
    private static array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('\\BCC\\Core\\Log\\Logger', false)) {
            eval('namespace BCC\\Core\\Log; final class Logger { public static function info(string $m, array $c = []): void {} public static function warning(string $m, array $c = []): void {} public static function error(string $m, array $c = []): void {} }');
        }
        self::$calls = [];
        Q::$ethCallerFor = null;
    }

    protected function tearDown(): void
    {
        Q::$ethCallerFor = null;
        parent::tearDown();
    }

    private static function chain(string $type = 'evm'): object
    {
        return (object) ['id' => 8453, 'slug' => 'base', 'chain_type' => $type];
    }

    /** @param array{ok: bool, result: ?string, kind: string} $answer */
    private static function answer(array $answer): void
    {
        Q::$ethCallerFor = static fn (object $chain): \Closure => static function (string $to, string $data) use ($answer): array {
            self::$calls[] = [$to, $data];
            return $answer;
        };
    }

    private static function word(string $address): string
    {
        return '0x' . str_repeat('0', 24) . substr(strtolower($address), 2);
    }

    public function testOwnerNamingTheWalletIsOwnerWhateverTheCase(): void
    {
        self::answer(['ok' => true, 'result' => strtoupper(self::word(self::WALLET)), 'kind' => 'none']);

        self::assertSame(Q::OWNER_VERDICT_OWNER, Q::evmContractOwnerVerdict(self::chain(), strtoupper(self::WALLET), self::CONTRACT));
        self::assertSame([[strtolower(self::CONTRACT), '0x8da5cb5b']], self::$calls, 'one owner() call to the contract');
    }

    public function testADifferentNonZeroOwnerIsNotOwner(): void
    {
        self::answer(['ok' => true, 'result' => self::word(self::OTHER), 'kind' => 'none']);

        self::assertSame(Q::OWNER_VERDICT_NOT_OWNER, Q::evmContractOwnerVerdict(self::chain(), self::WALLET, self::CONTRACT));
    }

    public function testARenouncedZeroOwnerIsUnknownNeverNotOwner(): void
    {
        self::answer(['ok' => true, 'result' => '0x' . str_repeat('0', 64), 'kind' => 'none']);

        self::assertSame(Q::OWNER_VERDICT_UNKNOWN, Q::evmContractOwnerVerdict(self::chain(), self::WALLET, self::CONTRACT));
    }

    /** @return array<string, array{0: array{ok: bool, result: ?string, kind: string}}> */
    public static function noAnswers(): array
    {
        return [
            'revert (no Ownable)'   => [['ok' => false, 'result' => null, 'kind' => 'rpc_error']],
            'transport'             => [['ok' => false, 'result' => null, 'kind' => 'transport']],
            'no credential'         => [['ok' => false, 'result' => null, 'kind' => 'credentials_missing']],
            'empty 0x (no code)'    => [['ok' => true, 'result' => '0x', 'kind' => 'none']],
            'short word'            => [['ok' => true, 'result' => '0x' . str_repeat('1', 40), 'kind' => 'none']],
            'not an address word'   => [['ok' => true, 'result' => '0x' . str_repeat('f', 64), 'kind' => 'none']],
            'two words'             => [['ok' => true, 'result' => self::word(self::WALLET) . str_repeat('0', 64), 'kind' => 'none']],
            'ok without a result'   => [['ok' => true, 'result' => null, 'kind' => 'none']],
            'failure carrying a word' => [['ok' => false, 'result' => self::word(self::WALLET), 'kind' => 'rpc_error']],
        ];
    }

    /** @param array{ok: bool, result: ?string, kind: string} $answer */
    #[DataProvider('noAnswers')]
    public function testNoClearAnswerIsUnknown(array $answer): void
    {
        self::answer($answer);

        self::assertSame(Q::OWNER_VERDICT_UNKNOWN, Q::evmContractOwnerVerdict(self::chain(), self::WALLET, self::CONTRACT));
    }

    public function testAThrowIsUnknown(): void
    {
        Q::$ethCallerFor = static fn (object $chain): \Closure => static function (string $to, string $data): array {
            throw new \RuntimeException('boom');
        };

        self::assertSame(Q::OWNER_VERDICT_UNKNOWN, Q::evmContractOwnerVerdict(self::chain(), self::WALLET, self::CONTRACT));
    }

    public function testNoDriverForTheChainIsUnknown(): void
    {
        Q::$ethCallerFor = static fn (object $chain): ?\Closure => null;

        self::assertSame(Q::OWNER_VERDICT_UNKNOWN, Q::evmContractOwnerVerdict(self::chain(), self::WALLET, self::CONTRACT));
    }

    public function testNothingIsAskedForANonEvmChainOrAddress(): void
    {
        self::answer(['ok' => true, 'result' => self::word(self::WALLET), 'kind' => 'none']);

        self::assertSame(Q::OWNER_VERDICT_UNKNOWN, Q::evmContractOwnerVerdict(self::chain('solana'), self::WALLET, self::CONTRACT));
        self::assertSame(Q::OWNER_VERDICT_UNKNOWN, Q::evmContractOwnerVerdict(self::chain(), 'cosmos1abc', self::CONTRACT));
        self::assertSame(Q::OWNER_VERDICT_UNKNOWN, Q::evmContractOwnerVerdict(self::chain(), self::WALLET, 'not-a-contract'));
        self::assertSame([], self::$calls);
    }
}
