<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\NearFetcher;
use BCC\Trust\Onchain\Fetchers\SolanaFetcher;
use BCC\Trust\Onchain\Fetchers\ThorchainFetcher;
use BCC\Trust\Onchain\Support\ApiRetry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-66: a malformed provider answer to a validator-index fetch is EMPTY, never
 * a thrown Error.
 *
 * Thorchain, Solana and NEAR each read their validator list item by item and
 * sorted it with bccomp() on the raw provider amount. A non-array item hit a
 * typed mapper (TypeError), a non-numeric amount hit bccomp() (ValueError), and
 * Solana's delinquent-marking loop wrote into a string item. Each is an Error,
 * not an Exception, so it escaped ChainRefreshService's per-chain catch and
 * aborted the index run for every chain after it.
 *
 * Now: an unreadable list returns [] (the run's partial signal), and an
 * unreadable amount sorts as 0 through StakeAmount::sortable(). That the run
 * also survives an Error that does escape a fetcher is pinned separately in
 * IndexRunPartialSkipsDestructiveStepsTest.
 *
 * Separate processes: SolanaFetcher caches getVoteAccounts per process.
 */
#[CoversClass(ThorchainFetcher::class)]
#[CoversClass(SolanaFetcher::class)]
#[CoversClass(NearFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ValidatorIndexShapeGuardsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-indexer-stubs.php';
        ApiRetry::reset();
        \BCC\Core\Log\Logger::reset();
    }

    /** @param mixed $payload */
    private static function queueBody(mixed $payload): void
    {
        ApiRetry::$queue[] = ['code' => 200, 'body' => (string) json_encode($payload)];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private static function ranks(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['operator_address']] = (int) $r['voting_power_rank'];
        }
        return $out;
    }

    // ── THORChain ───────────────────────────────────────────────────────

    private static function thor(): ThorchainFetcher
    {
        return new ThorchainFetcher((object) [
            'id' => 31, 'slug' => 'thorchain', 'name' => 'THORChain', 'chain_type' => 'thorchain',
            'rest_url' => 'https://thornode.example',
        ]);
    }

    /** @return array<string, mixed> */
    private static function node(string $addr, mixed $bond, string $status = 'Active'): array
    {
        return [
            'node_address'          => $addr,
            'status'                => $status,
            'total_bond'            => $bond,
            'node_operator_address' => 'thor1op' . $addr,
            'bond_providers'        => ['providers' => [['bond_address' => 'thor1op' . $addr, 'bond' => '100000000']]],
        ];
    }

    public function testThorchainAWellFormedListIsRankedByBond(): void
    {
        self::queueBody([self::node('thor1aaaa', '300000000'), self::node('thor1bbbb', '900000000')]);

        $rows = self::thor()->fetch_all_validators();

        self::assertSame(['thor1bbbb' => 1, 'thor1aaaa' => 2], self::ranks($rows));
        self::assertSame(1.0, $rows[0]['self_stake']);
    }

    public function testThorchainANonNumericBondSortsAsZeroInsteadOfThrowing(): void
    {
        self::queueBody([self::node('thor1aaaa', '1e9'), self::node('thor1bbbb', '5'), self::node('thor1cccc', ['x'])]);

        $ranks = self::ranks(self::thor()->fetch_all_validators());

        self::assertSame(1, $ranks['thor1bbbb']);
        self::assertCount(3, $ranks);
    }

    public function testThorchainMalformedBondProvidersDoNotThrow(): void
    {
        $n = self::node('thor1aaaa', '5');
        $n['bond_providers'] = ['providers' => 'none'];
        $m = self::node('thor1bbbb', '6');
        $m['bond_providers'] = ['providers' => ['garbage', 7]];
        $o = self::node('thor1cccc', '7');
        $o['status'] = ['Active'];
        self::queueBody([$n, $m, $o]);

        $rows = array_column(self::thor()->fetch_all_validators(), null, 'operator_address');

        self::assertCount(3, $rows);
        self::assertNull($rows['thor1aaaa']['self_stake'], 'providers not a list');
        self::assertNull($rows['thor1bbbb']['self_stake'], 'provider entries not arrays');
        self::assertSame('inactive', $rows['thor1cccc']['status'], 'a non-string status maps to inactive');
    }

    /** @return array<string, array{0: mixed}> */
    public static function unreadableThorBodies(): array
    {
        return [
            'a map, not a list'       => [['thor1aaaa' => self::node('thor1aaaa', '5')]],
            'an item is a string'     => [[self::node('thor1aaaa', '5'), 'garbage']],
            'an item has no address'  => [[self::node('thor1aaaa', '5'), ['total_bond' => '5']]],
            'an item has empty address' => [[['node_address' => '', 'total_bond' => '5']]],
        ];
    }

    #[DataProvider('unreadableThorBodies')]
    public function testThorchainAnUnreadableListIsEmpty(mixed $payload): void
    {
        self::queueBody($payload);

        self::assertSame([], self::thor()->fetch_all_validators());
    }

    // ── Solana ──────────────────────────────────────────────────────────

    private static function sol(): SolanaFetcher
    {
        return new SolanaFetcher((object) [
            'id' => 41, 'slug' => 'solana', 'name' => 'Solana', 'chain_type' => 'solana',
            'rpc_url' => 'https://solana-rpc.example',
        ]);
    }

    /** @return array<string, mixed> */
    private static function vote(string $node, mixed $stake): array
    {
        return ['nodePubkey' => $node, 'votePubkey' => 'vote' . $node, 'activatedStake' => $stake, 'commission' => 5, 'epochCredits' => [[1, 2, 3]]];
    }

    /** @param mixed $current @param mixed $delinquent */
    private static function queueVotes(mixed $current, mixed $delinquent): void
    {
        self::queueBody(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['current' => $current, 'delinquent' => $delinquent]]);
    }

    public function testSolanaAWellFormedSetIsRankedByStakeAndMarksDelinquents(): void
    {
        self::queueVotes([self::vote('NodeAAAAAAAAAAAAAAAAAA', 3000000000), self::vote('NodeBBBBBBBBBBBBBBBBBB', 9000000000)], [self::vote('NodeCCCCCCCCCCCCCCCCCC', 1000000000)]);

        $rows = self::sol()->fetch_all_validators();
        $byAddr = array_column($rows, null, 'operator_address');

        self::assertSame(['NodeBBBBBBBBBBBBBBBBBB' => 1, 'NodeAAAAAAAAAAAAAAAAAA' => 2, 'NodeCCCCCCCCCCCCCCCCCC' => 3], self::ranks($rows));
        self::assertSame('inactive', $byAddr['NodeCCCCCCCCCCCCCCCCCC']['status']);
    }

    public function testSolanaANonNumericStakeSortsAsZeroInsteadOfThrowing(): void
    {
        self::queueVotes([self::vote('NodeAAAAAAAAAAAAAAAAAA', '1e9'), self::vote('NodeBBBBBBBBBBBBBBBBBB', 5)], []);

        $ranks = self::ranks(self::sol()->fetch_all_validators());

        self::assertSame(1, $ranks['NodeBBBBBBBBBBBBBBBBBB']);
        self::assertCount(2, $ranks);
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    public static function unreadableVoteSets(): array
    {
        return [
            'delinquent item is a string' => [[self::vote('NodeAAAAAAAAAAAAAAAAAA', 5)], ['garbage']],
            'delinquent is a string'      => [[self::vote('NodeAAAAAAAAAAAAAAAAAA', 5)], 'none'],
            'current is a string'         => ['none', []],
            'current is a map'            => [['x' => self::vote('NodeAAAAAAAAAAAAAAAAAA', 5)], []],
            'current item has no node'    => [[['votePubkey' => 'v', 'activatedStake' => 5]], []],
            'current item node is empty'  => [[['nodePubkey' => '', 'activatedStake' => 5]], []],
        ];
    }

    #[DataProvider('unreadableVoteSets')]
    public function testSolanaAnUnreadableVoteSetIsEmpty(mixed $current, mixed $delinquent): void
    {
        self::queueVotes($current, $delinquent);

        self::assertSame([], self::sol()->fetch_all_validators());
    }

    public function testSolanaAnUnreadableSetIsCachedForTheProcessLikeAnyFailure(): void
    {
        self::queueVotes('none', []);
        self::queueVotes([self::vote('NodeAAAAAAAAAAAAAAAAAA', 5)], []);

        $f = self::sol();
        self::assertSame([], $f->fetch_all_validators());
        self::assertSame([], $f->fetch_all_validators());
        self::assertCount(1, ApiRetry::$calls);
    }

    // ── NEAR ────────────────────────────────────────────────────────────

    private static function near(): NearFetcher
    {
        return new NearFetcher((object) [
            'id' => 51, 'slug' => 'near', 'name' => 'NEAR', 'chain_type' => 'near',
            'rpc_url' => 'https://near-rpc.example',
        ]);
    }

    /** @param mixed $list */
    private static function queueNear(mixed $list): void
    {
        self::queueBody(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['current_validators' => $list]]);
    }

    /** @return array<string, mixed> */
    private static function pool(string $id, mixed $stake): array
    {
        return ['account_id' => $id, 'stake' => $stake, 'num_produced_blocks' => 9, 'num_expected_blocks' => 10];
    }

    public function testNearAWellFormedListIsRankedByStake(): void
    {
        self::queueNear([self::pool('a.poolv1.near', '100'), self::pool('b.poolv1.near', '900')]);

        self::assertSame(['b.poolv1.near' => 1, 'a.poolv1.near' => 2], self::ranks(self::near()->fetch_all_validators()));
    }

    public function testNearANonNumericStakeSortsAsZeroInsteadOfThrowing(): void
    {
        self::queueNear([self::pool('a.poolv1.near', '1e30'), self::pool('b.poolv1.near', '9'), self::pool('c.poolv1.near', ['x'])]);

        $ranks = self::ranks(self::near()->fetch_all_validators());

        self::assertSame(1, $ranks['b.poolv1.near']);
        self::assertCount(3, $ranks);
    }

    /** @return array<string, array{0: mixed}> */
    public static function unreadableNearLists(): array
    {
        return [
            'a string'             => ['none'],
            'a map'                => [['x' => self::pool('a.poolv1.near', '1')]],
            'an item is a string'  => [[self::pool('a.poolv1.near', '1'), 'garbage']],
            'an item has no id'    => [[['stake' => '1']]],
            'an item has empty id' => [[['account_id' => '', 'stake' => '1']]],
        ];
    }

    #[DataProvider('unreadableNearLists')]
    public function testNearAnUnreadableListIsEmpty(mixed $list): void
    {
        self::queueNear($list);

        self::assertSame([], self::near()->fetch_all_validators());
    }
}
