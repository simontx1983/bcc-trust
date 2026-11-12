<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Observability\DegradationMetrics;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Services\CollectionPersistBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * #221 — a lost collection write has to reach the HEALTH surface, not just a
 * log file.
 *
 * #212 made the losses countable and both callers log them, but nothing
 * aggregates `bcc-logs/`, so `/system/health` and the Golden Paths check
 * stayed green while writes were being dropped. The recording lives inside
 * `persist()` rather than at each call site for the same reason the class
 * exists at all: the policy lives once, so a third caller cannot forget it.
 *
 * ⚠ Two of the three events in bcc-core's map are reachable. `write_failed`
 * and `batch_total_failure` are exercised here. `watermark_withheld` has NO
 * writer and is deliberately not faked — see testWatermarkWithheldHasNoWriterYet.
 */
#[CoversClass(CollectionPersistBatch::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CollectionPersistMetricsTest extends TestCase
{
    private const SUBSYSTEM = 'collection_persist';
    private const WALLET    = 77;
    private const TTL       = 14400;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/collection-persist-stubs.php';
        CollectionRepository::reset();
        DegradationMetrics::reset();
    }

    /** @return array<int, array<string, mixed>> */
    private function batch(int $n): array
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['chain_id' => 8, 'contract_address' => 'cosmos1row' . $i];
        }

        return $rows;
    }

    /**
     * THE CONTROL, and it comes first on purpose.
     *
     * Every "records nothing" assertion below reads an EMPTY array, which is
     * exactly what a broken spy also produces. This proves the spy records.
     */
    public function testTheSpyActuallyRecords(): void
    {
        DegradationMetrics::record(self::SUBSYSTEM, 'sentinel');

        self::assertSame(['sentinel'], DegradationMetrics::eventsFor(self::SUBSYSTEM));
        self::assertCount(1, DegradationMetrics::$recorded);
    }

    public function testACleanBatchRecordsNothing(): void
    {
        CollectionRepository::$scriptedStatuses = ['created', 'updated', 'updated'];

        $result = CollectionPersistBatch::persist($this->batch(3), self::WALLET, self::TTL);

        self::assertSame(0, $result['failed'], 'fixture sanity: this batch must not fail');
        self::assertCount(3, CollectionRepository::$calls, 'fixture sanity: the batch really ran');
        self::assertSame([], DegradationMetrics::$recorded, 'a healthy batch is not a degradation');
    }

    /**
     * Pins the reasoning behind the ABSENT `total > 0` guard in persist().
     *
     * #221 specifies `failed === total && total > 0`. The second half is not
     * re-tested in the source because `failed > 0` cannot hold on an empty
     * batch. If that ever stops being true, `0 === 0` would record a total
     * failure for a wallet that simply holds nothing, and this case fails.
     */
    public function testAnEmptyBatchRecordsNothing(): void
    {
        $result = CollectionPersistBatch::persist([], self::WALLET, self::TTL);

        self::assertSame(['total' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0], $result);
        self::assertSame([], DegradationMetrics::$recorded);
    }

    public function testAPartialFailureRecordsWriteFailedAndNotTotalFailure(): void
    {
        CollectionRepository::$scriptedStatuses = ['created', 'failed', 'updated'];

        CollectionPersistBatch::persist($this->batch(3), self::WALLET, self::TTL);

        self::assertSame(['write_failed'], DegradationMetrics::eventsFor(self::SUBSYSTEM));
        self::assertNotContains(
            'batch_total_failure',
            DegradationMetrics::eventsFor(self::SUBSYSTEM),
            'two rows landed, so the wallet DID make progress — calling that a total'
            . ' failure would send an operator at the connection instead of the data'
        );
    }

    public function testATotalFailureRecordsBothEvents(): void
    {
        CollectionRepository::$scriptedStatuses = ['failed', 'failed'];

        CollectionPersistBatch::persist($this->batch(2), self::WALLET, self::TTL);

        self::assertSame(
            ['write_failed', 'batch_total_failure'],
            DegradationMetrics::eventsFor(self::SUBSYSTEM),
            'a total failure is ALSO a write failure — the sharper event is additive,'
            . ' so a dashboard counting write_failed does not under-report'
        );
    }

    /** failed === total === 1 is still a total failure. */
    public function testASingleRowBatchThatFailsIsATotalFailure(): void
    {
        CollectionRepository::$scriptedStatuses = ['failed'];

        CollectionPersistBatch::persist($this->batch(1), self::WALLET, self::TTL);

        self::assertSame(
            ['write_failed', 'batch_total_failure'],
            DegradationMetrics::eventsFor(self::SUBSYSTEM)
        );
    }

    /**
     * PER BATCH, not per row. Two lost rows in one sweep are one incident; a
     * per-row counter would make a single bad sweep look like a storm.
     */
    public function testTwoLostRowsInOneBatchRecordWriteFailedExactlyOnce(): void
    {
        CollectionRepository::$scriptedStatuses = ['failed', 'created', 'failed'];

        $result = CollectionPersistBatch::persist($this->batch(3), self::WALLET, self::TTL);

        self::assertSame(2, $result['failed'], 'fixture sanity');
        self::assertSame(['write_failed'], DegradationMetrics::eventsFor(self::SUBSYSTEM));
    }

    /**
     * The malformed-row path increments `failed` WITHOUT touching the
     * repository, so it is a second route into the recording — one that a
     * narrower placement (inside the status branch) would have missed.
     */
    public function testAMalformedRowAloneIsRecordedAsATotalFailure(): void
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = ['not-an-array'];

        CollectionPersistBatch::persist($rows, self::WALLET, self::TTL);

        self::assertSame([], CollectionRepository::$calls, 'the repository is never reached');
        self::assertSame(
            ['write_failed', 'batch_total_failure'],
            DegradationMetrics::eventsFor(self::SUBSYSTEM)
        );
    }

    /** No stray subsystem is touched — a typo would surface here. */
    public function testNothingOutsideThisSubsystemIsRecorded(): void
    {
        CollectionRepository::$scriptedStatuses = ['failed'];

        CollectionPersistBatch::persist($this->batch(1), self::WALLET, self::TTL);

        self::assertNotSame([], DegradationMetrics::$recorded, 'fixture sanity: something was recorded');
        foreach (DegradationMetrics::$recorded as $row) {
            self::assertSame(self::SUBSYSTEM, $row['subsystem']);
        }
    }

    /**
     * RECONCILIATION against bcc-core's canonical map.
     *
     * `scripts/subsystem-count-guard.php` compares COUNTS only, and only
     * docs -> code. Nothing else checks that the strings this class emits are
     * the strings bcc-core declares, so a rename on either side would ship a
     * counter aggregating under a subsystem `DegradationMetrics` has never
     * heard of.
     *
     * Resolves the sibling the way bcc-trust CI lays it out: all three jobs
     * check this repo out at app/public/wp-content/plugins/bcc-trust and
     * bcc-core beside it (ci.yml :35, :251, :291), so dirname(__DIR__, 3) is
     * the plugins directory.
     *
     * ⚠⚠ IN CI A MISSING SIBLING IS A FAILURE, NOT A SKIP. `composer test`
     * is a plain `phpunit` with no --fail-on-skipped, so a skip here would be
     * invisible in a green run and this reconciliation would silently stop
     * running the day someone moved that checkout step. Same failure mode
     * ci.yml already calls out for imagick, where the remedy is a targeted
     * re-run with --fail-on-skipped (:93); gating on GITHUB_ACTIONS keeps the
     * remedy inside the test instead of needing a second workflow step.
     *
     * Locally a bare git worktree legitimately has no sibling, so skip there.
     */
    public function testTheEmittedNamesMatchBccCoresCanonicalMap(): void
    {
        $path = \BCC\Trust\Tests\Support\CoreSibling::require('bcc-core.php');

        $src = (string) file_get_contents($path);

        self::assertStringContainsString(
            "'" . self::SUBSYSTEM . "'",
            $src,
            'bcc-core no longer declares this subsystem; the counter would vanish from /system/health'
        );

        $found = preg_match(
            "/'" . self::SUBSYSTEM . "'\\s*=>\\s*\\[(.*?)\\]/s",
            $src,
            $block
        );
        self::assertSame(1, $found, 'could not locate the collection_persist event list in bcc-core.php');

        preg_match_all("/'([a-z0-9_]+)'/", $block[1], $matches);
        $declared = $matches[1];

        self::assertSame(
            ['write_failed', 'batch_total_failure', 'watermark_withheld'],
            $declared,
            'the canonical event list moved; update persist() and this case together'
        );

        foreach (['write_failed', 'batch_total_failure'] as $emitted) {
            self::assertContains($emitted, $declared, $emitted . ' is emitted but not declared');
        }
    }

    /**
     * ⚠⚠ `watermark_withheld` IS DECLARED AND HAS NO WRITER, recorded as a
     * fact rather than papered over.
     *
     * #221 specified it as "the gallery refresh declined to advance
     * markHoldingsRefreshed() after a failed batch". Measured on main: the
     * only withholding of that watermark is HoldingsService's `!$complete`
     * branch, which turns on an INCOMPLETE PROVIDER WALK and never sees a
     * persist batch. Recording it from here would mislabel a holdings-read
     * failure as a collection-write failure.
     *
     * So this asserts the ABSENCE, which is the honest state, and it will fail
     * the day someone wires the event — at which point delete it.
     */
    public function testWatermarkWithheldHasNoWriterYet(): void
    {
        CollectionRepository::$scriptedStatuses = ['failed', 'failed'];

        CollectionPersistBatch::persist($this->batch(2), self::WALLET, self::TTL);

        self::assertNotSame([], DegradationMetrics::$recorded, 'fixture sanity: the batch did fail');
        self::assertNotContains(
            'watermark_withheld',
            DegradationMetrics::eventsFor(self::SUBSYSTEM),
            'a persist batch cannot withhold a holdings watermark; if this now fails,'
            . ' the event found a real writer and this case should be deleted'
        );
    }
}
