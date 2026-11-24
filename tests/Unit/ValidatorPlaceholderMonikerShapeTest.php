<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Core\Repositories\PageDiscoveryRepository;
use BCC\Trust\Onchain\Fetchers\PolkadotFetcher;
use BCC\Trust\Onchain\Fetchers\SolanaFetcher;
use BCC\Trust\Onchain\Fetchers\ThorchainFetcher;
use BCC\Trust\Onchain\Support\ApiRetry;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * F-560: the discovery lists sort a validator whose moniker is only a
 * placeholder after the named ones. PageDiscoveryRepository's SQL predicate
 * (UNNAMED_MONIKER_SQL) recognises EXACTLY these placeholder shapes, written
 * by our own fetchers when a chain gives no name:
 *   - `{first 6}...{last 4}`, 13 characters (Solana, Polkadot);
 *   - `{first 8}...{last 4}`, 15 characters (THORChain).
 *
 * This pins those shapes to what the fetchers actually write. If a fetcher
 * starts writing another shape, this fails, and the predicate must learn it
 * too, or that chain's unnamed validators would sort as named.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ValidatorPlaceholderMonikerShapeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-indexer-stubs.php';
        ApiRetry::reset();
        \BCC\Core\Log\Logger::reset();
    }

    /** The shape the SQL predicate recognises: the address's first 6, `...`, its last 4, at 13 or 15 characters. */
    private static function assertPredicateShape(string $moniker, string $address, int $length): void
    {
        self::assertSame($length, strlen($moniker), $moniker);
        self::assertContains(strlen($moniker), [13, 15], 'the predicate knows 13 and 15 only');
        self::assertSame(substr($address, 0, 6), substr($moniker, 0, 6), 'LEFT(moniker, 6) = LEFT(address, 6)');
        self::assertSame('...' . substr($address, -4), substr($moniker, -7), "RIGHT(moniker, 7) = CONCAT('...', RIGHT(address, 4))");
    }

    /**
     * The other half of the pin: the predicate recognises exactly the shapes
     * asserted above (and an empty moniker, and the bare address). If either
     * side changes alone, one of these fails.
     */
    public function testThePredicateKnowsExactlyTheseShapes(): void
    {
        $sql = (string) preg_replace('/\s+/', ' ', (string) (new \ReflectionClassConstant(PageDiscoveryRepository::class, 'UNNAMED_MONIKER_SQL'))->getValue());

        self::assertStringContainsString('CHAR_LENGTH(v_named.moniker) IN (13, 15)', $sql);
        self::assertStringContainsString('LEFT(v_named.moniker, 6) = LEFT(v_named.operator_address, 6)', $sql);
        self::assertStringContainsString("RIGHT(v_named.moniker, 7) = CONCAT('...', RIGHT(v_named.operator_address, 4))", $sql);
        self::assertStringContainsString('v_named.moniker = v_named.operator_address', $sql);
        self::assertStringContainsString("v_named.moniker IS NULL OR TRIM(v_named.moniker) = ''", $sql);
    }

    public function testSolanaWritesTheSixDotsFourShape(): void
    {
        $node = 'Vote111111111111111111111111111111111111111';
        ApiRetry::$queue[] = ['code' => 200, 'body' => (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
            'current'    => [['nodePubkey' => $node, 'votePubkey' => 'Stake11111111111111111111111111111111111111', 'activatedStake' => 5, 'commission' => 5]],
            'delinquent' => [],
        ]])];

        $rows = (new SolanaFetcher((object) [
            'id' => 41, 'slug' => 'solana', 'name' => 'Solana', 'chain_type' => 'solana', 'rpc_url' => 'https://solana-rpc.example',
        ]))->fetch_all_validators();

        self::assertCount(1, $rows);
        self::assertSame($node, $rows[0]['operator_address']);
        self::assertPredicateShape((string) $rows[0]['moniker'], $node, 13);
    }

    public function testThorchainWritesTheEightDotsFourShape(): void
    {
        $node = 'thor1qpgxwq6ga88u3h5hsdyzlrnhpj5yxc6szq3rmf';
        ApiRetry::$queue[] = ['code' => 200, 'body' => (string) json_encode([[
            'node_address'          => $node,
            'status'                => 'Active',
            'total_bond'            => '100000000',
            'node_operator_address' => 'thor1op',
            'bond_providers'        => ['providers' => [['bond_address' => 'thor1op', 'bond' => '100000000']]],
        ]])];

        $rows = (new ThorchainFetcher((object) [
            'id' => 31, 'slug' => 'thorchain', 'name' => 'THORChain', 'chain_type' => 'thorchain', 'rest_url' => 'https://thornode.example',
        ]))->fetch_all_validators();

        self::assertCount(1, $rows);
        self::assertSame($node, $rows[0]['operator_address']);
        self::assertPredicateShape((string) $rows[0]['moniker'], $node, 15);
    }

    public function testPolkadotWritesTheSixDotsFourShapeWithNoDisplayName(): void
    {
        $stash = '15oF4uVJwmo4TdGW7VfQxNLavjCXviqxT9S1MgbjMNHr6Sp5';
        $fetcher = new PolkadotFetcher((object) [
            'id' => 19, 'slug' => 'polkadot', 'name' => 'Polkadot', 'chain_type' => 'polkadot', 'decimals' => 10,
            'rest_url' => 'https://polkadot.api.subscan.io',
        ]);
        $map = new ReflectionMethod(PolkadotFetcher::class, 'mapValidator');
        $map->setAccessible(true);

        /** @var array<string, mixed> $row */
        $row = $map->invoke($fetcher, [
            'stash_account_display' => ['address' => $stash, 'display' => ''],
            'bonded_total'          => '10000000000',
            'bonded_owner'          => '10000000000',
            'validator_prefs_value' => '50000000',
            'count_nominators'      => 7,
            'validator_status'      => 'validator',
            'is_active'             => true,
        ], 0);

        self::assertSame($stash, $row['operator_address']);
        self::assertPredicateShape((string) $row['moniker'], $stash, 13);
    }
}
