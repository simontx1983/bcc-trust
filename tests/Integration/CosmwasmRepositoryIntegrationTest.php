<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The CosmWasm discovery repositories against a REAL MySQL.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────
 * A P0 shipped past 1,048 green unit tests:
 * `findPendingClassification()` built its ORDER BY as
 *
 *     $priorityOrder = '0';                       // no BCC_CW721_CODE_IDS
 *     ... ORDER BY {$priorityOrder}, next_attempt_at IS NOT NULL, code_id ASC
 *
 * In MySQL a bare integer in ORDER BY is a 1-BASED COLUMN ORDINAL, so
 * `ORDER BY 0` is "Unknown column '0' in 'order clause'" — an error, not a
 * no-op constant. `get_results()` returned null, the method returned `[]`,
 * and the worker read that as "nothing pending". Classification never ran,
 * in the DEFAULT configuration, silently, while reporting success.
 *
 * The unit suite could not have caught it: its `$wpdb` double returns
 * queued fixtures regardless of the query text, so a malformed clause is
 * literally unobservable there. THAT is the gap this file closes — every
 * dynamically-built query in both repositories is executed against a real
 * server here, and every call asserts `$wpdb->last_error` stayed empty.
 *
 * When you add a query with an interpolated fragment — an ORDER BY term, a
 * conditional WHERE clause, an IN() list — add it here too. A green unit
 * test is not evidence that the SQL parses.
 */
#[Group('integration')]
#[CoversClass(CosmwasmCodeFamilyRepository::class)]
#[CoversClass(CosmwasmContractRepository::class)]
final class CosmwasmRepositoryIntegrationTest extends TestCase
{
    private const CHAIN_ID = 8;
    private const CHAIN_B  = 9;

    private const CONTRACT_A = 'cosmos1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CONTRACT_B = 'cosmos1bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const CONTRACT_C = 'cosmos1cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('TRUNCATE TABLE `' . CosmwasmCodeFamilyRepository::table() . '`');
        $wpdb->query('TRUNCATE TABLE `' . CosmwasmContractRepository::table() . '`');
        $wpdb->query('TRUNCATE TABLE `' . ChainCheckpointRepository::table() . '`');
    }

    /**
     * The generic net: no repository call may leave a SQL error behind.
     * An empty result set is a legitimate answer; an empty result set WITH
     * an error is the failure mode this whole file is about.
     */
    private function assertNoDbError(string $what): void
    {
        self::assertSame(
            '',
            (string) $GLOBALS['wpdb']->last_error,
            $what . ' left a SQL error behind — an empty result here is NOT "no rows"'
        );
    }

    // ── THE REGRESSION ──────────────────────────────────────────────────

    public function testFindPendingClassificationWorksWithNoPriorityHint(): void
    {
        // THE DEFAULT CONFIGURATION — no BCC_CW721_CODE_IDS. This is the
        // exact call that silently returned [] and killed the pipeline.
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [
            ['code_id' => 1, 'checksum' => 'aa'],
            ['code_id' => 2, 'checksum' => 'bb'],
            ['code_id' => 3, 'checksum' => null],
            ['code_id' => 4, 'checksum' => 'dd'],
            ['code_id' => 5, 'checksum' => 'ee'],
        ]);
        $this->assertNoDbError('recordDiscovered');

        $rows = CosmwasmCodeFamilyRepository::findPendingClassification(
            self::CHAIN_ID,
            25,
            CosmwasmClassifier::VERSION
            // NO fourth argument — the default, the broken case.
        );
        $this->assertNoDbError('findPendingClassification (no hint)');

        self::assertCount(5, $rows, 'every freshly-discovered family is pending classification');
        self::assertSame(
            [1, 2, 3, 4, 5],
            array_map(static fn(object $r): int => (int) $r->code_id, $rows)
        );
    }

    public function testOrderByZeroIsAnErrorNotANoOpConstant(): void
    {
        // Executable documentation for WHY the ORDER BY is built
        // conditionally. If this ever starts passing as a no-op, the
        // conditional build could be "simplified" back into the bug.
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [['code_id' => 1, 'checksum' => 'aa']]);

        $wpdb  = $GLOBALS['wpdb'];
        $table = CosmwasmCodeFamilyRepository::table();

        $broken = $wpdb->get_results("SELECT code_id FROM `{$table}` ORDER BY 0, code_id ASC");
        self::assertSame([], $broken, 'ORDER BY 0 returns nothing…');
        self::assertStringContainsString(
            "Unknown column '0'",
            (string) $wpdb->last_error,
            '…because MySQL reads a bare integer in ORDER BY as a 1-based column ordinal'
        );

        $ok = $wpdb->get_results("SELECT code_id FROM `{$table}` ORDER BY code_id ASC");
        self::assertCount(1, $ok);
        $this->assertNoDbError('ORDER BY without the ordinal');
    }

    public function testPriorityHintReordersWithoutRestricting(): void
    {
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [
            ['code_id' => 100, 'checksum' => 'aa'],
            ['code_id' => 434, 'checksum' => 'bb'],
            ['code_id' => 900, 'checksum' => 'cc'],
        ]);

        $rows = CosmwasmCodeFamilyRepository::findPendingClassification(
            self::CHAIN_ID,
            25,
            CosmwasmClassifier::VERSION,
            [434]
        );
        $this->assertNoDbError('findPendingClassification (with hint)');

        $ids = array_map(static fn(object $r): int => (int) $r->code_id, $rows);
        self::assertSame(434, $ids[0], 'the hint reorders');
        self::assertCount(3, $ids, 'and restricts nothing');
    }

    // ── every other dynamically-built query, against real SQL ───────────

    public function testFindEnumerableBothClauseVariants(): void
    {
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [
            ['code_id' => 10, 'checksum' => 'aa'],
            ['code_id' => 11, 'checksum' => 'bb'],
        ]);
        $this->classify(10, CosmwasmClassifier::CONFIRMED);
        $this->classify(11, CosmwasmClassifier::CONFIRMED);
        CosmwasmCodeFamilyRepository::closeEnumeration(self::CHAIN_ID, 11);
        $this->assertNoDbError('closeEnumeration');

        // `$completeClause` present.
        $incomplete = CosmwasmCodeFamilyRepository::findEnumerable(self::CHAIN_ID, 25, true);
        $this->assertNoDbError('findEnumerable(onlyIncomplete: true)');
        self::assertSame([10], array_map(static fn(object $r): int => (int) $r->code_id, $incomplete));

        // `$completeClause` empty — the interpolation-of-nothing case.
        $all = CosmwasmCodeFamilyRepository::findEnumerable(self::CHAIN_ID, 25, false);
        $this->assertNoDbError('findEnumerable(onlyIncomplete: false)');
        self::assertCount(2, $all);
    }

    public function testChecksumTwinLookup(): void
    {
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [
            ['code_id' => 20, 'checksum' => 'DEADBEEF'],
            ['code_id' => 21, 'checksum' => 'deadbeef'],
        ]);
        $this->classify(20, CosmwasmClassifier::NOT_CW721);

        $twin = CosmwasmCodeFamilyRepository::findClassifiedTwinByChecksum(
            self::CHAIN_ID,
            'deadbeef',
            21,
            CosmwasmClassifier::VERSION
        );
        $this->assertNoDbError('findClassifiedTwinByChecksum');

        self::assertNotNull($twin);
        self::assertSame(20, (int) $twin->code_id);
        // The checksum was lowercased on write, so case cannot split a twin.
        self::assertSame('deadbeef', (string) $twin->checksum);
    }

    public function testMissingChecksumLandsAsSqlNullNotEmptyString(): void
    {
        // `prepare()` coerces a null %s to ''. An empty-string checksum
        // would masquerade as a real hash to the twin lookup, so the write
        // path emits a NULL literal instead.
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [['code_id' => 30, 'checksum' => null]]);

        $row = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 30);
        $this->assertNoDbError('find');
        self::assertNotNull($row);
        self::assertNull($row->checksum);

        $table = CosmwasmCodeFamilyRepository::table();
        self::assertSame(
            '1',
            (string) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM `{$table}` WHERE checksum IS NULL"),
        );
    }

    public function testRecordDiscoveredIsIdempotentAndPreservesVerdicts(): void
    {
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [['code_id' => 40, 'checksum' => 'aa']]);
        $this->classify(40, CosmwasmClassifier::NOT_CW721);

        // A re-walk of the listing must not resurrect a settled family.
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [['code_id' => 40, 'checksum' => 'aa']]);
        $this->assertNoDbError('recordDiscovered (duplicate)');

        $row = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 40);
        self::assertNotNull($row);
        self::assertSame(CosmwasmClassifier::NOT_CW721, (string) $row->classification);
        self::assertSame(1, CosmwasmCodeFamilyRepository::countForChain(self::CHAIN_ID));
    }

    public function testEnumerationProgressBothCursorBranches(): void
    {
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [['code_id' => 50, 'checksum' => 'aa']]);

        // Non-null cursor branch.
        CosmwasmCodeFamilyRepository::recordEnumerationProgress(self::CHAIN_ID, 50, 'CURSOR==', 100, false);
        $this->assertNoDbError('recordEnumerationProgress (cursor)');
        $row = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 50);
        self::assertNotNull($row);
        self::assertSame('CURSOR==', (string) $row->contracts_cursor);
        self::assertSame(100, (int) $row->contracts_enumerated);

        // GREATEST() keeps the high-water mark monotonic under a lower write.
        CosmwasmCodeFamilyRepository::recordEnumerationProgress(self::CHAIN_ID, 50, 'C2==', 40, false);
        $row = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 50);
        self::assertNotNull($row);
        self::assertSame(100, (int) $row->contracts_enumerated, 'position never goes backwards');

        // NULL cursor branch (separate statement — prepare() would coerce
        // a null %s to '').
        CosmwasmCodeFamilyRepository::recordEnumerationProgress(self::CHAIN_ID, 50, null, 150, true);
        $this->assertNoDbError('recordEnumerationProgress (null cursor)');
        $row = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 50);
        self::assertNotNull($row);
        self::assertNull($row->contracts_cursor, 'a null cursor must be SQL NULL, not an empty string');
        self::assertSame(1, (int) $row->enumeration_complete);
    }

    public function testRequeueForClassifierVersionTouchesOnlyAffectedRows(): void
    {
        $old = CosmwasmClassifier::VERSION - 1;

        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [
            ['code_id' => 60, 'checksum' => 'aa'],
            ['code_id' => 61, 'checksum' => 'bb'],
            ['code_id' => 62, 'checksum' => 'cc'],
        ]);
        $this->classify(60, CosmwasmClassifier::NOT_CW721, $old);
        $this->classify(61, CosmwasmClassifier::INCONCLUSIVE, $old);
        $this->classify(62, CosmwasmClassifier::CONFIRMED, $old);

        $count = CosmwasmCodeFamilyRepository::requeueForClassifierVersion(
            self::CHAIN_ID,
            CosmwasmClassifier::VERSION,
            100
        );
        $this->assertNoDbError('requeueForClassifierVersion');

        self::assertSame(1, $count, 'only the inconclusive row');

        $settled = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 60);
        self::assertNotNull($settled);
        self::assertNotNull($settled->classified_at, 'a settled negative is never requeued');
    }

    public function testCodeFamilyAggregates(): void
    {
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [
            ['code_id' => 70, 'checksum' => 'aa'],
            ['code_id' => 71, 'checksum' => 'bb'],
        ]);
        $this->classify(70, CosmwasmClassifier::CONFIRMED);

        $counts = CosmwasmCodeFamilyRepository::countsByClassification(self::CHAIN_ID);
        $this->assertNoDbError('countsByClassification');
        self::assertSame(1, $counts[CosmwasmClassifier::CONFIRMED]);
        self::assertSame(1, $counts[CosmwasmClassifier::INCONCLUSIVE]);
        self::assertSame(0, $counts[CosmwasmClassifier::NOT_CW721], 'absent classes report 0, not missing');

        self::assertSame(2, CosmwasmCodeFamilyRepository::countForChain(self::CHAIN_ID));
        $this->assertNoDbError('countForChain');

        self::assertSame(
            1,
            CosmwasmCodeFamilyRepository::countPendingClassification(self::CHAIN_ID, CosmwasmClassifier::VERSION)
        );
        $this->assertNoDbError('countPendingClassification');
    }

    public function testFindDueForMetadataCheck(): void
    {
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN_ID, [['code_id' => 80, 'checksum' => 'aa']]);
        $this->classify(80, CosmwasmClassifier::CONFIRMED);

        $due = CosmwasmCodeFamilyRepository::findDueForMetadataCheck(self::CHAIN_ID, gmdate('Y-m-d H:i:s'), 25);
        $this->assertNoDbError('findDueForMetadataCheck');
        self::assertCount(1, $due);

        CosmwasmCodeFamilyRepository::touchMetadataChecked(self::CHAIN_ID, 80);
        $this->assertNoDbError('touchMetadataChecked');

        $after = CosmwasmCodeFamilyRepository::findDueForMetadataCheck(
            self::CHAIN_ID,
            gmdate('Y-m-d H:i:s', time() - 3600),
            25
        );
        self::assertSame([], $after, 'a just-checked family is not due again');
    }

    // ── contract repository ─────────────────────────────────────────────

    public function testContractKnownMapAndPendingQueries(): void
    {
        CosmwasmContractRepository::recordDiscovered(self::CHAIN_ID, 434, [
            ['contract_address' => self::CONTRACT_A, 'denied' => false],
            ['contract_address' => self::CONTRACT_B, 'denied' => true],
        ]);
        $this->assertNoDbError('CosmwasmContractRepository::recordDiscovered');

        // IN() list built from a bounded, non-empty slice.
        $known = CosmwasmContractRepository::knownMap(
            self::CHAIN_ID,
            [self::CONTRACT_A, self::CONTRACT_C, strtoupper(self::CONTRACT_B)]
        );
        $this->assertNoDbError('knownMap');
        self::assertArrayHasKey(strtolower(self::CONTRACT_A), $known);
        self::assertArrayHasKey(strtolower(self::CONTRACT_B), $known, 'case-insensitive');
        self::assertArrayNotHasKey(strtolower(self::CONTRACT_C), $known);

        // `$codeClause` EMPTY — the interpolation-of-nothing case.
        $pending = CosmwasmContractRepository::findPendingClassification(
            self::CHAIN_ID,
            25,
            CosmwasmClassifier::VERSION
        );
        $this->assertNoDbError('findPendingClassification (no code filter)');
        self::assertCount(1, $pending, 'the denied row never enters the work queue');
        self::assertSame(strtolower(self::CONTRACT_A), (string) $pending[0]->contract_address);

        // `$codeClause` PRESENT.
        $filtered = CosmwasmContractRepository::findPendingClassification(
            self::CHAIN_ID,
            25,
            CosmwasmClassifier::VERSION,
            [434]
        );
        $this->assertNoDbError('findPendingClassification (code filter)');
        self::assertCount(1, $filtered);

        $other = CosmwasmContractRepository::findPendingClassification(
            self::CHAIN_ID,
            25,
            CosmwasmClassifier::VERSION,
            [999]
        );
        self::assertSame([], $other);
    }

    public function testContractEmitQueueRespectsDenyAndWrittenFlags(): void
    {
        CosmwasmContractRepository::recordDiscovered(self::CHAIN_ID, 434, [
            ['contract_address' => self::CONTRACT_A, 'denied' => false],
            ['contract_address' => self::CONTRACT_B, 'denied' => true],
        ]);
        CosmwasmContractRepository::recordClassification(
            self::CHAIN_ID,
            self::CONTRACT_A,
            $this->verdict(CosmwasmClassifier::CONFIRMED),
            0
        );
        CosmwasmContractRepository::recordClassification(
            self::CHAIN_ID,
            self::CONTRACT_B,
            $this->verdict(CosmwasmClassifier::CONFIRMED),
            0
        );
        $this->assertNoDbError('recordClassification');

        $emittable = CosmwasmContractRepository::findEmittable(self::CHAIN_ID, 25);
        $this->assertNoDbError('findEmittable');
        self::assertCount(1, $emittable, 'a denied contract is never emittable');

        CosmwasmContractRepository::markCollectionRowWritten(self::CHAIN_ID, self::CONTRACT_A);
        $this->assertNoDbError('markCollectionRowWritten');
        self::assertSame([], CosmwasmContractRepository::findEmittable(self::CHAIN_ID, 25));

        // Unhide puts it back in the queue.
        CosmwasmContractRepository::setDenied(self::CHAIN_ID, self::CONTRACT_B, false);
        $this->assertNoDbError('setDenied');
        self::assertCount(1, CosmwasmContractRepository::findEmittable(self::CHAIN_ID, 25));
    }

    public function testContractRecordDiscoveredPreservesVerdictsAndRefreshesDeny(): void
    {
        CosmwasmContractRepository::recordDiscovered(self::CHAIN_ID, 434, [
            ['contract_address' => self::CONTRACT_A, 'denied' => false],
        ]);
        CosmwasmContractRepository::recordClassification(
            self::CHAIN_ID,
            self::CONTRACT_A,
            $this->verdict(CosmwasmClassifier::NOT_CW721),
            2
        );

        // A re-walk with a deny rule now in force.
        CosmwasmContractRepository::recordDiscovered(self::CHAIN_ID, 434, [
            ['contract_address' => self::CONTRACT_A, 'denied' => true],
        ]);
        $this->assertNoDbError('recordDiscovered (duplicate)');

        $row = CosmwasmContractRepository::find(self::CHAIN_ID, self::CONTRACT_A);
        self::assertNotNull($row);
        self::assertSame(CosmwasmClassifier::NOT_CW721, (string) $row->classification, 'verdict survives');
        self::assertSame(1, (int) $row->denied, 'deny flag refreshes');
        self::assertSame(1, CosmwasmContractRepository::countForChain(self::CHAIN_ID));
    }

    public function testContractMigrationRepoints(): void
    {
        CosmwasmContractRepository::recordDiscovered(self::CHAIN_ID, 434, [
            ['contract_address' => self::CONTRACT_A, 'denied' => false],
        ]);

        CosmwasmContractRepository::recordMigration(self::CHAIN_ID, self::CONTRACT_A, 999);
        $this->assertNoDbError('recordMigration');

        $row = CosmwasmContractRepository::find(self::CHAIN_ID, self::CONTRACT_A);
        self::assertNotNull($row);
        self::assertSame(999, (int) $row->code_id);
        self::assertNotNull($row->migrated_at);
        self::assertSame(1, CosmwasmContractRepository::countForChain(self::CHAIN_ID), 'one row, not two');
    }

    public function testContractAggregates(): void
    {
        CosmwasmContractRepository::recordDiscovered(self::CHAIN_ID, 434, [
            ['contract_address' => self::CONTRACT_A, 'denied' => false],
            ['contract_address' => self::CONTRACT_B, 'denied' => true],
        ]);

        $counts = CosmwasmContractRepository::countsByClassification(self::CHAIN_ID);
        $this->assertNoDbError('countsByClassification');
        self::assertSame(2, $counts[CosmwasmClassifier::INCONCLUSIVE]);

        self::assertSame(1, CosmwasmContractRepository::countDenied(self::CHAIN_ID));
        $this->assertNoDbError('countDenied');
        self::assertSame(2, CosmwasmContractRepository::countForChain(self::CHAIN_ID));
    }

    public function testContractRetryBackoffIsPersistedAndHonoured(): void
    {
        CosmwasmContractRepository::recordDiscovered(self::CHAIN_ID, 434, [
            ['contract_address' => self::CONTRACT_A, 'denied' => false],
        ]);

        CosmwasmContractRepository::recordAttemptFailure(self::CHAIN_ID, self::CONTRACT_A, 'node down', 1);
        $this->assertNoDbError('recordAttemptFailure');

        // next_attempt_at is in the future, so the row is not due yet.
        self::assertSame(
            [],
            CosmwasmContractRepository::findPendingClassification(self::CHAIN_ID, 25, CosmwasmClassifier::VERSION)
        );
        $this->assertNoDbError('findPendingClassification (backoff)');
    }

    // ── checkpoint cw_* extension ───────────────────────────────────────

    public function testCheckpointCwColumnsRoundTrip(): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        $this->assertNoDbError('ensureExists');

        $row = ChainCheckpointRepository::get(self::CHAIN_ID);
        self::assertNotNull($row);
        self::assertSame(ChainCheckpointRepository::CW_STATE_IDLE, (string) $row->cw_discovery_state);

        ChainCheckpointRepository::recordCwCodeProgress(self::CHAIN_ID, 'CURSOR==', 713, false);
        $this->assertNoDbError('recordCwCodeProgress');
        $row = ChainCheckpointRepository::get(self::CHAIN_ID);
        self::assertNotNull($row);
        self::assertSame('CURSOR==', (string) $row->cw_code_cursor);
        self::assertSame(713, (int) $row->cw_max_code_id);
        self::assertSame(ChainCheckpointRepository::CW_STATE_BACKFILLING, (string) $row->cw_discovery_state);

        ChainCheckpointRepository::recordCwCodeProgress(self::CHAIN_ID, null, 713, true);
        $row = ChainCheckpointRepository::get(self::CHAIN_ID);
        self::assertNotNull($row);
        self::assertNull($row->cw_code_cursor, 'a completed walk clears the cursor to SQL NULL');
        self::assertSame(ChainCheckpointRepository::CW_STATE_BACKFILLED, (string) $row->cw_discovery_state);
        self::assertNotNull($row->cw_backfill_completed_at);
    }

    public function testWatermarkAdvanceIsMonotonicAndClearsTheError(): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        ChainCheckpointRepository::setCwDiscoveryState(
            self::CHAIN_ID,
            ChainCheckpointRepository::CW_STATE_BACKFILLING,
            'something went wrong'
        );

        ChainCheckpointRepository::advanceCwCodeWatermark(self::CHAIN_ID, 713);
        $this->assertNoDbError('advanceCwCodeWatermark');
        $row = ChainCheckpointRepository::get(self::CHAIN_ID);
        self::assertNotNull($row);
        self::assertSame(713, (int) $row->cw_max_code_id);
        self::assertNull($row->cw_last_error);

        // GREATEST(): a lower value can never lower the watermark.
        ChainCheckpointRepository::advanceCwCodeWatermark(self::CHAIN_ID, 400);
        $row = ChainCheckpointRepository::get(self::CHAIN_ID);
        self::assertNotNull($row);
        self::assertSame(713, (int) $row->cw_max_code_id);
    }

    public function testBackfillRestartClearsTheCursorAndRecordsTheReason(): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        ChainCheckpointRepository::recordCwCodeProgress(self::CHAIN_ID, 'STALE==', 400, false);

        ChainCheckpointRepository::requestCwBackfillRestart(self::CHAIN_ID, 'stale pagination cursor rejected');
        $this->assertNoDbError('requestCwBackfillRestart');

        $row = ChainCheckpointRepository::get(self::CHAIN_ID);
        self::assertNotNull($row);
        self::assertNull($row->cw_code_cursor);
        self::assertSame(ChainCheckpointRepository::CW_STATE_BACKFILLING, (string) $row->cw_discovery_state);
        self::assertStringContainsString('stale pagination cursor', (string) $row->cw_last_error);
    }

    public function testNextCwDiscoveryChainSkipsUnsupportedAndPaused(): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        ChainCheckpointRepository::ensureExists(self::CHAIN_B);
        ChainCheckpointRepository::setCwDiscoveryState(
            self::CHAIN_ID,
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED
        );

        $next = ChainCheckpointRepository::nextCwDiscoveryChain([self::CHAIN_ID, self::CHAIN_B]);
        $this->assertNoDbError('nextCwDiscoveryChain');
        self::assertSame(self::CHAIN_B, $next);

        ChainCheckpointRepository::setCwDiscoveryState(self::CHAIN_B, ChainCheckpointRepository::CW_STATE_PAUSED);
        self::assertNull(ChainCheckpointRepository::nextCwDiscoveryChain([self::CHAIN_ID, self::CHAIN_B]));
        $this->assertNoDbError('nextCwDiscoveryChain (all excluded)');
    }

    public function testNextCwDiscoveryChainPrefersTheLeastRecentlyWorked(): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        ChainCheckpointRepository::ensureExists(self::CHAIN_B);
        // CHAIN_ID has run; CHAIN_B never has (NULL sorts first).
        ChainCheckpointRepository::touchCwDiscovery(self::CHAIN_ID);
        $this->assertNoDbError('touchCwDiscovery');

        self::assertSame(
            self::CHAIN_B,
            ChainCheckpointRepository::nextCwDiscoveryChain([self::CHAIN_ID, self::CHAIN_B]),
            'a chain that has never run wins — that is what stops starvation'
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return array{classification: string, reason: string, probes_ok: string, probes_failed: string, last_error: string, classifier_version: int} */
    private function verdict(string $classification, ?int $version = null): array
    {
        return [
            'classification'     => $classification,
            'reason'             => 'integration',
            'probes_ok'          => '',
            'probes_failed'      => '',
            'last_error'         => '',
            'classifier_version' => $version ?? CosmwasmClassifier::VERSION,
        ];
    }

    private function classify(int $codeId, string $classification, ?int $version = null): void
    {
        CosmwasmCodeFamilyRepository::recordClassification(
            self::CHAIN_ID,
            $codeId,
            $this->verdict($classification, $version),
            null,
            0
        );
    }
}
