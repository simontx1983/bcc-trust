<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-550 prerequisite: the index run stores each Solana validator's vote
 * account (`vote_account`), the account a claim later reads the validator's
 * authorities from.
 *
 * Driven through ValidatorRepository::bulkUpsert() against a recording
 * $wpdb double, so these assert the statements actually sent:
 *   - a fetcher that sends `vote_account` (Solana) owns the column: a new
 *     row gets it, a stored NULL is backfilled, a changed value is written,
 *     and a null (an identity it can't trust) CLEARS a stored value;
 *   - a fetcher that doesn't send it (every other chain) never touches it;
 *   - only base58 ever reaches the SQL.
 */
#[CoversClass(ValidatorRepository::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ValidatorVoteAccountWriteTest extends TestCase
{
    private const VOTE_A = 'Vote111111111111111111111111111111111111111';
    private const VOTE_B = 'Stake11111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/validator-vote-account-stubs.php';
        $GLOBALS['wpdb'] = new \BccVoteAccountFakeWpdb();
    }

    private static function db(): \BccVoteAccountFakeWpdb
    {
        /** @var \BccVoteAccountFakeWpdb $db */
        $db = $GLOBALS['wpdb'];
        return $db;
    }

    /**
     * One validator as a fetcher returns it.
     *
     * @return array<string, mixed>
     */
    private static function incoming(string $addr, mixed $vote = 'absent', string $moniker = 'Alpha'): array
    {
        $row = [
            'operator_address'  => $addr,
            'chain_id'          => 41,
            'moniker'           => $moniker,
            'status'            => 'active',
            'commission_rate'   => 5.0,
            'total_stake'       => 100.0,
            'jailed_count'      => 0,
            'voting_power_rank' => 1,
        ];
        if ($vote !== 'absent') {
            $row['vote_account'] = $vote;
        }
        return $row;
    }

    /** The stored row as the bulk SELECT returns it, unchanged from incoming() unless a test says so. */
    private static function stored(string $addr, ?string $vote, string $moniker = 'Alpha'): object
    {
        return (object) [
            'id' => '7', 'operator_address' => $addr, 'moniker' => $moniker, 'status' => 'active',
            'commission_rate' => '5.00', 'total_stake' => '100.00000000', 'jailed_count' => '0',
            'voting_power_rank' => '1', 'enrichment_attempts' => '0',
            'fetched_at' => gmdate('Y-m-d H:i:s'), 'vote_account' => $vote,
        ];
    }

    /** @param list<array<string, mixed>> $validators @return array{total: int, new: int, updated: int, unchanged: int, refreshed: int} */
    private static function upsert(array $validators): array
    {
        return ValidatorRepository::bulkUpsert($validators, 3600);
    }

    public function testTheBulkReadSelectsTheStoredVoteAccount(): void
    {
        self::upsert([self::incoming('NodeA', self::VOTE_A)]);

        self::assertStringContainsString('fetched_at, vote_account', (string) preg_replace('/\s+/', ' ', self::db()->queries[0]));
    }

    public function testANewSolanaRowIsInsertedWithItsVoteAccount(): void
    {
        $stats = self::upsert([self::incoming('NodeA', self::VOTE_A)]);

        $writes = self::db()->writes();
        self::assertSame(1, $stats['new']);
        self::assertCount(1, $writes);
        self::assertStringContainsString('next_enrichment_at, vote_account)', $writes[0]);
        self::assertStringEndsWith(", '" . self::VOTE_A . "')", $writes[0]);
    }

    public function testANewRowFromAnotherChainIsInsertedWithNone(): void
    {
        self::upsert([self::incoming('cosmosvaloper1abc')]);

        self::assertStringEndsWith(', NULL)', self::db()->writes()[0]);
    }

    public function testAStoredNullIsBackfilledOnTheNextRun(): void
    {
        self::db()->existing = [self::stored('NodeA', null)];

        $stats = self::upsert([self::incoming('NodeA', self::VOTE_A)]);

        $writes = self::db()->writes();
        self::assertSame(1, $stats['updated'], 'the first run after deploy writes the column');
        self::assertCount(1, $writes);
        self::assertStringContainsString("vote_account = '" . self::VOTE_A . "'", $writes[0]);
    }

    public function testTheSameVoteAccountIsNoWrite(): void
    {
        self::db()->existing = [self::stored('NodeA', self::VOTE_A)];

        $stats = self::upsert([self::incoming('NodeA', self::VOTE_A)]);

        self::assertSame(1, $stats['unchanged']);
        self::assertSame([], self::db()->writes());
    }

    public function testAChangedVoteAccountIsWritten(): void
    {
        self::db()->existing = [self::stored('NodeA', self::VOTE_A)];

        self::upsert([self::incoming('NodeA', self::VOTE_B)]);

        self::assertStringContainsString("vote_account = '" . self::VOTE_B . "'", self::db()->writes()[0]);
    }

    /** Security S3: an identity the fetcher can't trust (shared, unreadable) clears a stored value. */
    public function testANullFromSolanaClearsAStoredVoteAccount(): void
    {
        self::db()->existing = [self::stored('NodeA', self::VOTE_A)];

        $stats = self::upsert([self::incoming('NodeA', null)]);

        self::assertSame(1, $stats['updated']);
        self::assertStringContainsString('vote_account = NULL', self::db()->writes()[0]);
    }

    public function testAnotherChainNeverTouchesTheColumn(): void
    {
        // Something else changed (the moniker), so the row is rewritten, but
        // the vote account is kept as it is.
        self::db()->existing = [self::stored('cosmosvaloper1abc', null, 'Old name')];

        $stats = self::upsert([self::incoming('cosmosvaloper1abc', 'absent', 'New name')]);

        self::assertSame(1, $stats['updated']);
        self::assertStringContainsString('vote_account = vote_account', self::db()->writes()[0]);
    }

    public function testAnotherChainsUnchangedRowStaysUnwritten(): void
    {
        self::db()->existing = [self::stored('cosmosvaloper1abc', null)];

        $stats = self::upsert([self::incoming('cosmosvaloper1abc')]);

        self::assertSame(1, $stats['unchanged'], 'no vote account sent: no spurious change');
        self::assertSame([], self::db()->writes());
    }

    /** @return array<string, array{0: mixed}> */
    public static function notBase58(): array
    {
        return [
            'a quote'          => ["Vote1111111111111111111111111111111111111'"],
            'a percent'        => ['Vote1111111111111111111111111111111111111%s'],
            'a zero'           => ['0ote111111111111111111111111111111111111111'],
            'too short'        => ['Vote111'],
            'too long'         => [str_repeat('1', 45)],
            'not a string'     => [42],
            'empty'            => [''],
        ];
    }

    /** Only the base58 alphabet reaches the SQL: anything else is stored as none. */
    #[DataProvider('notBase58')]
    public function testOnlyBase58ReachesTheSql(mixed $vote): void
    {
        self::upsert([self::incoming('NodeA', $vote)]);

        $insert = self::db()->writes()[0];
        self::assertStringEndsWith(', NULL)', $insert);
        if (is_string($vote) && $vote !== '') {
            self::assertStringNotContainsString($vote, $insert);
        }
    }
}
