<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Log\Logger;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A FAILED read, against a REAL MySQL, in all three repositories.
 *
 * ── WHY THIS CANNOT BE A UNIT TEST ──────────────────────────────────────
 * The whole subject is what `$wpdb` does when the server rejects a query,
 * and the unit suite's `$wpdb` double returns queued fixtures no matter
 * what it is handed — a failed query is literally unrepresentable there.
 * So the failures here are REAL: the table (or the column) is renamed out
 * from under the query, MySQL answers "doesn't exist", and the repository
 * is asked what it does about that.
 *
 * ── FOUR STATES, NOT TWO ────────────────────────────────────────────────
 * Every read is pinned in each state it can be in:
 *
 *   populated   rows come back
 *   empty       the query ran and matched nothing — a legitimate answer
 *   failed      the query did not run — NOT an answer
 *   malformed   the row came back with something unusable in it
 *
 * The middle two are the pair that used to be indistinguishable, and the
 * distinction is the entire point of the guards.
 *
 * ── AND TWO POLICIES ────────────────────────────────────────────────────
 * Operator-facing reads THROW ({@see RepositoryReadFailure}); worker
 * reads log and return empty so a cron tick degrades instead of dying.
 * Both are asserted, because "guard everything harder" applied to the
 * worker would be its own outage.
 */
#[Group('integration')]
#[CoversClass(ChainCheckpointRepository::class)]
#[CoversClass(CosmwasmCodeFamilyRepository::class)]
#[CoversClass(CosmwasmContractRepository::class)]
final class CosmwasmReadFailureIntegrationTest extends TestCase
{
    private const CHAIN_ID = 8;
    private const CHAIN_B  = 9;

    private const CONTRACT_A = 'cosmos1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CONTRACT_B = 'cosmos1bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @var list<string> tables renamed away by the current test */
    private array $hidden = [];

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('TRUNCATE TABLE `' . CosmwasmCodeFamilyRepository::table() . '`');
        $wpdb->query('TRUNCATE TABLE `' . CosmwasmContractRepository::table() . '`');
        $wpdb->query('TRUNCATE TABLE `' . ChainCheckpointRepository::table() . '`');
        Logger::reset();
        $this->hidden = [];
    }

    protected function tearDown(): void
    {
        // Never leave a renamed table behind for the next test.
        foreach ($this->hidden as $table) {
            $GLOBALS['wpdb']->query("RENAME TABLE `{$table}__hidden` TO `{$table}`");
        }
        $this->hidden = [];
    }

    // ── FAIL-CLOSED: the operator-facing reads ──────────────────────────

    public function testAllChainFamilyCountsPopulatedEmptyAndFailed(): void
    {
        // populated
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [['code_id' => 1, 'checksum' => 'aa']]);
        $counts = CosmwasmCodeFamilyRepository::countsByChainAndClassification();
        self::assertSame(1, $counts[self::CHAIN_ID][CosmwasmClassifier::INCONCLUSIVE]);

        // empty — a real answer
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `' . CosmwasmCodeFamilyRepository::table() . '`');
        self::assertSame([], CosmwasmCodeFamilyRepository::countsByChainAndClassification());
        self::assertSame('', (string) $GLOBALS['wpdb']->last_error, 'an empty table is not an error');

        // failed — NOT an answer
        $this->hideTable(CosmwasmCodeFamilyRepository::table());
        $this->expectException(RepositoryReadFailure::class);
        CosmwasmCodeFamilyRepository::countsByChainAndClassification();
    }

    public function testPendingCountsByChainFailsClosed(): void
    {
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [['code_id' => 2, 'checksum' => 'bb']]);
        self::assertSame(
            [self::CHAIN_ID => 1],
            CosmwasmCodeFamilyRepository::pendingCountsByChain(CosmwasmClassifier::VERSION)
        );

        $GLOBALS['wpdb']->query('TRUNCATE TABLE `' . CosmwasmCodeFamilyRepository::table() . '`');
        self::assertSame([], CosmwasmCodeFamilyRepository::pendingCountsByChain(CosmwasmClassifier::VERSION));

        $this->hideTable(CosmwasmCodeFamilyRepository::table());
        $this->expectException(RepositoryReadFailure::class);
        CosmwasmCodeFamilyRepository::pendingCountsByChain(CosmwasmClassifier::VERSION);
    }

    /**
     * The grouped unresolved-error aggregate, against real MySQL.
     *
     * Four states, and the middle two carry the whole argument: an EMPTY
     * result is the panel saying "nothing is wrong", a FAILED read is the
     * panel unable to say anything. Collapsing them is how a broken
     * database renders green.
     *
     * The `<> ''` half of the predicate is exercised deliberately, because
     * MySQL treats '' and NULL differently and only one of them is
     * excluded by `IS NOT NULL`.
     */
    public function testErroredCountsByChainPopulatedEmptyAndFailed(): void
    {
        $table = CosmwasmCodeFamilyRepository::table();

        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [
            ['code_id' => 80, 'checksum' => 'aa'],
            ['code_id' => 81, 'checksum' => 'bb'],
            ['code_id' => 82, 'checksum' => 'cc'],
        ]);
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_B, [['code_id' => 5, 'checksum' => 'dd']]);

        // Two errored on chain A, one on chain B.
        CosmwasmCodeFamilyRepository::recordAttemptFailure(self::CHAIN_ID, 80, 'Circuit breaker open for chain 8', 1);
        CosmwasmCodeFamilyRepository::recordAttemptFailure(self::CHAIN_ID, 81, 'rpc error: code = Unavailable', 1);
        CosmwasmCodeFamilyRepository::recordAttemptFailure(self::CHAIN_B, 5, 'node unreachable', 1);

        // …and one row whose error is the EMPTY STRING, not NULL. It must
        // not count: `IS NOT NULL` alone would let it through.
        $GLOBALS['wpdb']->query(
            "UPDATE `{$table}` SET last_error = '' WHERE chain_id = " . self::CHAIN_ID . " AND code_id = 82"
        );

        self::assertSame(
            [self::CHAIN_ID => 2, self::CHAIN_B => 1],
            CosmwasmCodeFamilyRepository::erroredCountsByChain(),
            'grouped per chain; the empty-string row is not an error'
        );

        // empty — a legitimate answer, not a failure
        $GLOBALS['wpdb']->query("TRUNCATE TABLE `{$table}`");
        self::assertSame([], CosmwasmCodeFamilyRepository::erroredCountsByChain());
        self::assertSame('', (string) $GLOBALS['wpdb']->last_error, 'an empty table is not an error');

        // failed — NOT an answer. This must never become "zero errors".
        $this->hideTable($table);
        $this->expectException(RepositoryReadFailure::class);
        CosmwasmCodeFamilyRepository::erroredCountsByChain();
    }

    /** A settled `not_cw721` with no error text is NOT an unresolved error. */
    public function testASettledNegativeIsNotCountedAsAnError(): void
    {
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [['code_id' => 90, 'checksum' => 'ee']]);
        CosmwasmCodeFamilyRepository::recordAttemptFailure(self::CHAIN_ID, 90, 'transient node blip', 1);
        self::assertSame([self::CHAIN_ID => 1], CosmwasmCodeFamilyRepository::erroredCountsByChain());

        // The retry succeeds and settles the family as a genuine non-NFT.
        CosmwasmCodeFamilyRepository::recordClassification(
            self::CHAIN_ID,
            90,
            [
                'classification'     => CosmwasmClassifier::NOT_CW721,
                'reason'             => 'no_cw721_queries',
                'probes_ok'          => '',
                'probes_failed'      => 'num_tokens,contract_info',
                'last_error'         => '',
                'classifier_version' => CosmwasmClassifier::VERSION,
            ],
            null,
            1
        );

        self::assertSame(
            [],
            CosmwasmCodeFamilyRepository::erroredCountsByChain(),
            'an ordinary DeFi contract must not keep the panel yellow forever'
        );
    }

    public function testFamilyBatchLookupFailsClosed(): void
    {
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [['code_id' => 434, 'checksum' => 'cc']]);
        self::assertCount(1, CosmwasmCodeFamilyRepository::findManyForChains([self::CHAIN_ID], [434]));

        // empty: the family genuinely is not there
        self::assertSame([], CosmwasmCodeFamilyRepository::findManyForChains([self::CHAIN_ID], [999]));

        $this->hideTable(CosmwasmCodeFamilyRepository::table());
        $this->expectException(RepositoryReadFailure::class);
        CosmwasmCodeFamilyRepository::findManyForChains([self::CHAIN_ID], [434]);
    }

    public function testContractInventoryFailsClosed(): void
    {
        CosmwasmContractRepository::recordDiscovered(self::CHAIN_ID, 434, [
            ['contract_address' => self::CONTRACT_A, 'denied' => false],
            ['contract_address' => self::CONTRACT_B, 'denied' => true],
        ]);

        $inventory = CosmwasmContractRepository::inventoryByChain();
        self::assertSame(2, $inventory[self::CHAIN_ID]['total']);
        self::assertSame(1, $inventory[self::CHAIN_ID]['denied']);
        self::assertSame(0, $inventory[self::CHAIN_ID]['inspected'], 'listed is not inspected');

        $GLOBALS['wpdb']->query('TRUNCATE TABLE `' . CosmwasmContractRepository::table() . '`');
        self::assertSame([], CosmwasmContractRepository::inventoryByChain());

        $this->hideTable(CosmwasmContractRepository::table());
        $this->expectException(RepositoryReadFailure::class);
        CosmwasmContractRepository::inventoryByChain();
    }

    public function testContractBatchLookupFailsClosed(): void
    {
        CosmwasmContractRepository::recordDiscovered(self::CHAIN_ID, 434, [
            ['contract_address' => self::CONTRACT_A, 'denied' => false],
        ]);
        self::assertCount(
            1,
            CosmwasmContractRepository::findManyForChains([self::CHAIN_ID], [self::CONTRACT_A])
        );
        self::assertSame(
            [],
            CosmwasmContractRepository::findManyForChains([self::CHAIN_ID], [self::CONTRACT_B]),
            'a contract the scanner has not inventoried is a real, empty answer'
        );

        $this->hideTable(CosmwasmContractRepository::table());
        $this->expectException(RepositoryReadFailure::class);
        CosmwasmContractRepository::findManyForChains([self::CHAIN_ID], [self::CONTRACT_A]);
    }

    public function testCheckpointListingFailsClosedOnlyForTheOperatorVariant(): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        self::assertCount(1, ChainCheckpointRepository::getAll());
        self::assertCount(1, ChainCheckpointRepository::getAllOrFail());

        $this->hideTable(ChainCheckpointRepository::table());

        // The worker-facing variant degrades…
        self::assertSame([], ChainCheckpointRepository::getAll(), 'the worker must not fatal on a failed read');
        self::assertTrue($this->loggedReadFailure('getAll'), '…but it must still say so in the log');

        // …and the operator-facing one refuses.
        $this->expectException(RepositoryReadFailure::class);
        ChainCheckpointRepository::getAllOrFail();
    }

    public function testDeniedFlagSeparatesNoRowFromUnreadable(): void
    {
        CosmwasmContractRepository::recordDiscovered(self::CHAIN_ID, 434, [
            ['contract_address' => self::CONTRACT_A, 'denied' => true],
        ]);

        self::assertTrue(CosmwasmContractRepository::deniedFlag(self::CHAIN_ID, self::CONTRACT_A));

        CosmwasmContractRepository::setDenied(self::CHAIN_ID, self::CONTRACT_A, false);
        self::assertFalse(CosmwasmContractRepository::deniedFlag(self::CHAIN_ID, self::CONTRACT_A));

        // No row at all — a real answer, and NOT the same as a failure.
        self::assertNull(CosmwasmContractRepository::deniedFlag(self::CHAIN_ID, self::CONTRACT_B));

        $this->hideTable(CosmwasmContractRepository::table());
        $this->expectException(RepositoryReadFailure::class);
        CosmwasmContractRepository::deniedFlag(self::CHAIN_ID, self::CONTRACT_A);
    }

    /**
     * The exception has to carry enough to act on: which read, and what
     * the server said.
     */
    public function testTheFailureNamesTheReadAndTheDatabaseError(): void
    {
        $this->hideTable(CosmwasmContractRepository::table());

        try {
            CosmwasmContractRepository::inventoryByChain();
            self::fail('a missing table must not be reported as an empty inventory');
        } catch (RepositoryReadFailure $e) {
            self::assertSame('inventoryByChain', $e->repositoryMethod());
            self::assertStringContainsString("doesn't exist", $e->dbError());
            self::assertStringContainsString('NOT "no rows"', $e->getMessage());
        }
    }

    // ── FAIL-SAFE: the worker reads ─────────────────────────────────────

    /**
     * The worker's queue reads must keep degrading rather than throwing —
     * an exception inside a cron tick is a worse outcome than a logged
     * empty queue — but every one of them must leave the ERROR line that
     * tells an operator the empty queue was not real.
     *
     * @return list<array{0: string, 1: callable(): mixed}>
     */
    public static function workerReads(): array
    {
        return [
            'code family: pending classification' => ['findPendingClassification', static fn() => CosmwasmCodeFamilyRepository::findPendingClassification(self::CHAIN_ID, 25, CosmwasmClassifier::VERSION)],
            'code family: enumerable'             => ['findEnumerable', static fn() => CosmwasmCodeFamilyRepository::findEnumerable(self::CHAIN_ID, 25, true)],
            'code family: metadata due'           => ['findDueForMetadataCheck', static fn() => CosmwasmCodeFamilyRepository::findDueForMetadataCheck(self::CHAIN_ID, gmdate('Y-m-d H:i:s'), 25)],
            'contract: known map'                 => ['knownMap', static fn() => CosmwasmContractRepository::knownMap(self::CHAIN_ID, [self::CONTRACT_A])],
            'contract: pending classification'    => ['findPendingClassification', static fn() => CosmwasmContractRepository::findPendingClassification(self::CHAIN_ID, 25, CosmwasmClassifier::VERSION)],
            'contract: emit queue'                => ['findEmittable', static fn() => CosmwasmContractRepository::findEmittable(self::CHAIN_ID, 25)],
        ];
    }

    /**
     * @param callable(): mixed $read
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('workerReads')]
    public function testWorkerReadsDegradeLoudlyRatherThanThrowing(string $method, callable $read): void
    {
        $this->hideTable(CosmwasmCodeFamilyRepository::table());
        $this->hideTable(CosmwasmContractRepository::table());

        $result = $read();

        self::assertSame([], $result, 'the worker gets an empty result and lives');
        self::assertTrue(
            $this->loggedReadFailure($method),
            $method . ' returned empty without recording that the query never ran'
        );
    }

    public function testCheckpointGetDegradesLoudly(): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        self::assertNotNull(ChainCheckpointRepository::get(self::CHAIN_ID));
        self::assertNull(ChainCheckpointRepository::get(self::CHAIN_B), 'no such chain is a real answer');
        self::assertFalse($this->loggedReadFailure('get'), 'and it must not be logged as a failure');

        $this->hideTable(ChainCheckpointRepository::table());
        self::assertNull(ChainCheckpointRepository::get(self::CHAIN_ID));
        self::assertTrue($this->loggedReadFailure('get'));
    }

    // ── reads INSIDE a write path ───────────────────────────────────────

    /**
     * `recordSuccess()` reads the progression history and writes it back.
     * If that read fails, `[]` decodes out of it and the UPDATE would
     * replace a real history with a one-entry array — erasing the
     * evidence the dashboard uses to detect stagnation and lag drift,
     * silently, while reporting success.
     *
     * So: the tick's real progress still lands, and the history column is
     * left ALONE rather than rewritten from a read that did not happen.
     */
    public function testAFailedHistoryReadNeitherStopsNorCorruptsTheCheckpointWrite(): void
    {
        $table = ChainCheckpointRepository::table();
        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        ChainCheckpointRepository::recordSuccess(self::CHAIN_ID, 100, 110);
        ChainCheckpointRepository::recordSuccess(self::CHAIN_ID, 200, 210);

        $before = (string) $GLOBALS['wpdb']->get_var(
            "SELECT block_progression_history FROM `{$table}` WHERE chain_id = " . self::CHAIN_ID
        );
        self::assertCount(2, ChainCheckpointRepository::decodeProgressionHistory($before));

        // Make ONLY the history read fail: the column is gone, the rest of
        // the row is untouched.
        $GLOBALS['wpdb']->query(
            "ALTER TABLE `{$table}` CHANGE `block_progression_history` `block_progression_history__hidden` LONGTEXT NULL"
        );
        Logger::reset();

        ChainCheckpointRepository::recordSuccess(self::CHAIN_ID, 300, 310);

        self::assertTrue($this->loggedReadFailure('recordSuccess'), 'a failed read inside a write must be logged');

        $GLOBALS['wpdb']->query(
            "ALTER TABLE `{$table}` CHANGE `block_progression_history__hidden` `block_progression_history` LONGTEXT NULL"
        );

        $row = ChainCheckpointRepository::get(self::CHAIN_ID);
        self::assertNotNull($row);
        self::assertSame(300, (int) $row->last_processed_block, 'real progress still had to land');
        self::assertSame(ChainCheckpointRepository::STATE_HEALTHY, (string) $row->state);
        self::assertSame(
            $before,
            (string) $row->block_progression_history,
            'the history must be left alone, not rewritten from a read that never ran'
        );
    }

    /**
     * `addCuUsage()` reads the row under `FOR UPDATE`. A failed read there
     * used to be indistinguishable from "this chain has no checkpoint
     * row": both returned 0, wrote nothing, and said nothing — which
     * under-counts real provider spend against the daily budget breaker.
     *
     * Now the two are different: the missing row is silent, the failed
     * read rolls back AND logs.
     */
    public function testAFailedLockedReadRollsBackAndIsDistinguishableFromAMissingRow(): void
    {
        $table = ChainCheckpointRepository::table();
        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);

        self::assertSame(120, ChainCheckpointRepository::addCuUsage(self::CHAIN_ID, 120));
        self::assertSame(240, ChainCheckpointRepository::addCuUsage(self::CHAIN_ID, 120));

        // A chain with no row: 0, quietly. That is a real answer.
        Logger::reset();
        self::assertSame(0, ChainCheckpointRepository::addCuUsage(self::CHAIN_B, 120));
        self::assertFalse($this->loggedReadFailure('addCuUsage'));

        // Now break ONLY the locked read.
        $GLOBALS['wpdb']->query(
            "ALTER TABLE `{$table}` CHANGE `cu_used_today` `cu_used_today__hidden` BIGINT UNSIGNED NOT NULL DEFAULT 0"
        );
        Logger::reset();

        self::assertSame(
            0,
            ChainCheckpointRepository::addCuUsage(self::CHAIN_ID, 120),
            'the worker still gets an answer instead of a fatal inside cron'
        );
        self::assertTrue($this->loggedReadFailure('addCuUsage'), 'but the lost CU is now on the record');
        self::assertTrue(
            $this->logged('addCuUsage rollback'),
            'and the transaction was rolled back rather than half-applied'
        );

        $GLOBALS['wpdb']->query(
            "ALTER TABLE `{$table}` CHANGE `cu_used_today__hidden` `cu_used_today` BIGINT UNSIGNED NOT NULL DEFAULT 0"
        );

        $row = ChainCheckpointRepository::get(self::CHAIN_ID);
        self::assertNotNull($row);
        self::assertSame(240, (int) $row->cu_used_today, 'a rolled-back tick must not have moved the counter');
    }

    // ── malformed output ────────────────────────────────────────────────

    /**
     * The fourth state. A row that comes back with garbage in the JSON
     * column is neither empty nor failed, and must not be either: it
     * decodes to `[]` on purpose (the column is bounded-best-effort, not
     * contract) and nothing throws.
     */
    public function testMalformedStoredJsonIsNeitherAnErrorNorAnEmptyRead(): void
    {
        $table = ChainCheckpointRepository::table();
        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        $GLOBALS['wpdb']->query(
            "UPDATE `{$table}` SET block_progression_history = 'not json at all' WHERE chain_id = " . self::CHAIN_ID
        );
        Logger::reset();

        ChainCheckpointRepository::recordSuccess(self::CHAIN_ID, 400, 410);

        self::assertFalse($this->loggedReadFailure('recordSuccess'), 'unusable content is not a failed query');

        $row = ChainCheckpointRepository::get(self::CHAIN_ID);
        self::assertNotNull($row);
        $history = ChainCheckpointRepository::decodeProgressionHistory(
            is_string($row->block_progression_history) ? $row->block_progression_history : null
        );
        self::assertCount(1, $history, 'the garbage is replaced by a fresh, valid entry');
        self::assertSame(400, $history[0]['block']);
    }

    // ── the deny wiring, end to end, on real SQL ────────────────────────

    /**
     * The indexed queue path actually skips a synchronised deny.
     *
     * `findEmittable()` filters on `denied = 0` and is served by
     * `idx_emit (chain_id, classification, denied, collection_row_written)`
     * — so the hidden contract is not "filtered out in PHP", it is not
     * looked at. Both halves are asserted: the index exists over those
     * columns, and the row is gone from the queue.
     */
    public function testSynchronisedDenyRemovesTheContractFromTheIndexedEmitQueue(): void
    {
        $table = CosmwasmContractRepository::table();

        CosmwasmContractRepository::recordDiscovered(self::CHAIN_ID, 434, [
            ['contract_address' => self::CONTRACT_A, 'denied' => false],
        ]);
        CosmwasmContractRepository::recordClassification(
            self::CHAIN_ID,
            self::CONTRACT_A,
            [
                'classification'     => CosmwasmClassifier::CONFIRMED,
                'reason'             => 'integration',
                'probes_ok'          => '',
                'probes_failed'      => '',
                'last_error'         => '',
                'classifier_version' => CosmwasmClassifier::VERSION,
            ],
            0
        );
        self::assertCount(1, CosmwasmContractRepository::findEmittable(self::CHAIN_ID, 25));

        // What the hide button's sync does, on the one affected contract.
        CosmwasmContractRepository::setDenied(self::CHAIN_ID, self::CONTRACT_A, true);

        self::assertTrue(CosmwasmContractRepository::deniedFlag(self::CHAIN_ID, self::CONTRACT_A));
        self::assertSame(
            [],
            CosmwasmContractRepository::findEmittable(self::CHAIN_ID, 25),
            'the emit queue must stop returning it, not re-reject it every sweep'
        );
        self::assertSame(1, CosmwasmContractRepository::countDenied(self::CHAIN_ID));

        // …and that predicate is index-backed rather than a table scan.
        $index = [];
        /** @var list<object{Key_name: string, Column_name: string, Seq_in_index: string}> $rows */
        $rows = $GLOBALS['wpdb']->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_emit'");
        foreach ($rows as $row) {
            $index[(int) $row->Seq_in_index] = (string) $row->Column_name;
        }
        ksort($index);
        self::assertSame(
            ['chain_id', 'classification', 'denied', 'collection_row_written'],
            array_values($index),
            'idx_emit is what makes the deny predicate cheap; the queue query is written against it'
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** Rename a table out from under the next query, restored in tearDown. */
    private function hideTable(string $table): void
    {
        $GLOBALS['wpdb']->query("RENAME TABLE `{$table}` TO `{$table}__hidden`");
        self::assertSame('', (string) $GLOBALS['wpdb']->last_error, 'could not stage the failure');
        $this->hidden[] = $table;
    }

    private function loggedReadFailure(string $method): bool
    {
        foreach (Logger::$lines as $line) {
            if ($line['level'] !== 'error' || !str_contains($line['message'], 'read failed')) {
                continue;
            }
            if (($line['context']['method'] ?? null) === $method) {
                return true;
            }
        }

        return false;
    }

    private function logged(string $needle): bool
    {
        foreach (Logger::$lines as $line) {
            if (str_contains($line['message'], $needle)) {
                return true;
            }
        }

        return false;
    }
}
