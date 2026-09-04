<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\DiscoveryScanProgress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The Cosmos Hub canary, rebuilt on real MySQL as a regression fixture.
 *
 * ── WHY THIS SHAPE AND NOT A UNIT TEST ──────────────────────────────────
 * The defect was a COUNT: 732 rows sitting at their creation-time default
 * that a surface treated as settled. A doubled repository would have
 * counted whatever the double was told to count, and the actual predicate
 * — `classification NOT IN (…) AND retry_count < … AND (classified_at IS
 * NULL OR classifier_version < …)` — is SQL. So the rows are real, written
 * through the real repository, and counted by the real query.
 */
#[CoversClass(DiscoveryScanProgress::class)]
#[Group('integration')]
final class DiscoveryScanProgressIntegrationTest extends TestCase
{
    /** A chain id no fixture or shipped registry row uses. */
    private const CHAIN = 90801;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . CosmwasmCodeFamilyRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
        $wpdb->query('DELETE FROM `' . ChainCheckpointRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    /**
     * Seed families exactly as enumeration does: `recordDiscovered()` is the
     * real writer, so the rows carry the real creation-time defaults rather
     * than values this test invented.
     */
    private function seedEnumerated(int $count): void
    {
        $families = [];
        for ($codeId = 1; $codeId <= $count; $codeId++) {
            $families[] = ['code_id' => $codeId, 'checksum' => sprintf('%064x', $codeId)];
        }
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN, $families);
    }

    /**
     * Mark the code listing drained, exactly as the worker does.
     *
     * ⚠ `ensureExists()` first: `recordCwCodeProgress()` is an UPDATE, so on
     * a chain with no checkpoint row it matches nothing and silently marks
     * nothing. A fixture that skipped this would have asserted against a
     * checkpoint that was never written — and the first draft of this test
     * did exactly that, which is why the assertion failed rather than the
     * production code.
     */
    private function markEnumerationComplete(): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::recordCwCodeProgress(self::CHAIN, null, 737, true);
    }

    /**
     * Settle one family, through the real writer and the real verdict shape.
     *
     * ⚠ `classified_at` and `classifier_version` are set BY the repository,
     * not by this test — which is the point: the pending predicate keys on
     * exactly those two columns, so a test that wrote them itself would be
     * asserting against its own fixture rather than the production writer.
     */
    private function classify(int $codeId, string $classification): void
    {
        CosmwasmCodeFamilyRepository::recordClassification(
            self::CHAIN,
            $codeId,
            [
                'classification'     => $classification,
                'reason'             => 'sampled:1',
                'probes_ok'          => 'contract_info',
                'probes_failed'      => '',
                'last_error'         => '',
                'classifier_version' => CosmwasmClassifier::VERSION,
            ],
            'cosmos1testcontractaddressforprogressfixture000000',
            0
        );
    }

    // ── (1) THE CANARY, EXACTLY ─────────────────────────────────────────

    /**
     * 737 families, enumeration complete, 5 classified, 732 still queued,
     * zero confirmed — and the scan is NOT complete.
     *
     * ⚠ This is the state the 2026-09-04 staging run actually left behind,
     * beside a run row that said `succeeded` / `partial = 0` /
     * `pass_completed` with 0 collections emitted. Every one of those run
     * values is true and none of them may make `scan_complete` true.
     */
    public function testTheCosmosHubCanaryReportsAnIncompleteScan(): void
    {
        $this->seedEnumerated(737);
        $this->markEnumerationComplete();

        // The five the canary actually settled: 2 terminal negatives, 2
        // unreachable and 1 inconclusive-after-a-real-attempt.
        $this->classify(1, CosmwasmClassifier::NOT_CW721);
        $this->classify(2, CosmwasmClassifier::NOT_CW721);
        $this->classify(3, CosmwasmClassifier::UNREACHABLE);
        $this->classify(4, CosmwasmClassifier::UNREACHABLE);
        $this->classify(5, CosmwasmClassifier::INCONCLUSIVE);

        $p = DiscoveryScanProgress::forChain(self::CHAIN);

        self::assertTrue($p['ok']);
        self::assertSame(DiscoveryScanProgress::YES, $p['enumeration_complete']);
        self::assertSame(737, $p['total_families']);
        self::assertSame(0, $p['collection_families'], 'no confirmed CW-721');

        // ⚠ THE ASSERTION THE WHOLE PR EXISTS FOR.
        self::assertSame(
            DiscoveryScanProgress::NO,
            $p['scan_complete'],
            'enumeration complete + unexamined families is NOT a complete scan'
        );
        self::assertSame(DiscoveryScanProgress::YES, $p['more_work_available']);

        // ⚠ 732 — AND THIS IS THE CANARY'S OWN FIGURE, not a coincidence.
        //
        // All FIVE leave the queue, not just the two terminal negatives.
        // The predicate's last term is `classified_at IS NULL OR
        // classifier_version < %d`: a family examined at the CURRENT
        // classifier version is settled for now whatever the verdict was.
        // `temporarily_unreachable` and `inconclusive` are requeueable, but
        // they come back on a classifier VERSION BUMP — not on the next
        // pass.
        //
        // So "remaining" means "families this pass would pick up", which is
        // the only number worth showing an operator, and 737 - 5 = 732 is
        // exactly what the staging canary left behind.
        self::assertSame(732, $p['remaining_families']);
        self::assertSame(5, $p['classified_families']);
    }

    /** (9) A default row — untouched by any attempt — counts as remaining. */
    public function testDefaultUnvisitedRowsCountAsRemaining(): void
    {
        $this->seedEnumerated(10);
        $this->markEnumerationComplete();

        $wpdb = $GLOBALS['wpdb'];
        $row  = $wpdb->get_row('SELECT classification, classification_reason, classified_at FROM `'
            . CosmwasmCodeFamilyRepository::table() . '` WHERE chain_id = ' . self::CHAIN . ' AND code_id = 1');

        // The creation-time default really is `inconclusive` with nothing
        // else set — the exact shape that was mistaken for a verdict.
        self::assertSame(CosmwasmClassifier::INCONCLUSIVE, $row->classification);
        self::assertNull($row->classification_reason);
        self::assertNull($row->classified_at);

        $p = DiscoveryScanProgress::forChain(self::CHAIN);
        self::assertSame(10, $p['remaining_families']);
        self::assertSame(DiscoveryScanProgress::NO, $p['scan_complete']);
    }

    /**
     * ⚠ A row at the CURRENT classifier version that was never classified.
     *
     * This is the case the `classified_at IS NULL` term exists for, and the
     * ONLY case that can tell it apart from the `classifier_version < %d`
     * term beside it. Enumeration writes `classifier_version = 0`, so every
     * ordinary unvisited row is already caught by the version term — and a
     * mutation control that deleted `classified_at IS NULL` SURVIVED for
     * exactly that reason.
     *
     * The row is built with SQL rather than a repository call because no
     * writer produces this combination on purpose; it is the shape a future
     * writer could produce by accident, and the guard must hold if it does.
     */
    public function testARowAtTheCurrentVersionButNeverClassifiedStillCounts(): void
    {
        $this->seedEnumerated(3);
        $this->markEnumerationComplete();

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . CosmwasmCodeFamilyRepository::table() . '`
                SET classifier_version = %d, classified_at = NULL
              WHERE chain_id = %d',
            CosmwasmClassifier::VERSION,
            self::CHAIN
        ));

        // The version term can no longer include them: only `classified_at
        // IS NULL` can.
        $p = DiscoveryScanProgress::forChain(self::CHAIN);

        self::assertSame(3, $p['remaining_families'], 'never-classified rows remain queued');
        self::assertSame(0, $p['classified_families']);
        self::assertSame(DiscoveryScanProgress::NO, $p['scan_complete']);
    }

    /** (10) A genuinely attempted terminal negative leaves the queue. */
    public function testATerminalNegativeDoesNotRemainQueued(): void
    {
        $this->seedEnumerated(3);
        $this->markEnumerationComplete();
        $this->classify(1, CosmwasmClassifier::NOT_CW721);

        $p = DiscoveryScanProgress::forChain(self::CHAIN);
        self::assertSame(2, $p['remaining_families']);
        self::assertSame(1, $p['classified_families']);
    }

    // ── (4) the genuine complete-zero ───────────────────────────────────

    /**
     * All families terminal, none confirmed → a REAL "no collections"
     * answer, and the only state in which that sentence may be printed.
     */
    public function testAllTerminalWithNoConfirmedIsAGenuineCompleteZero(): void
    {
        $this->seedEnumerated(4);
        $this->markEnumerationComplete();
        for ($i = 1; $i <= 4; $i++) {
            $this->classify($i, CosmwasmClassifier::NOT_CW721);
        }

        $p = DiscoveryScanProgress::forChain(self::CHAIN);

        self::assertSame(DiscoveryScanProgress::YES, $p['scan_complete']);
        self::assertSame(0, $p['remaining_families']);
        self::assertSame(4, $p['classified_families']);
        self::assertSame(0, $p['collection_families']);
        self::assertSame(DiscoveryScanProgress::NO, $p['more_work_available']);

        $sentence = DiscoveryScanProgress::summarySentence($p);
        self::assertStringContainsString('Scan complete', $sentence);
        self::assertStringContainsString('No supported NFT collections were confirmed', $sentence);
    }

    /** (5) Confirmed collections + remaining work is still incomplete. */
    public function testConfirmedCollectionsPlusRemainingIsStillIncomplete(): void
    {
        $this->seedEnumerated(6);
        $this->markEnumerationComplete();
        $this->classify(1, CosmwasmClassifier::CONFIRMED);

        $p = DiscoveryScanProgress::forChain(self::CHAIN);

        self::assertSame(1, $p['collection_families']);
        self::assertSame(5, $p['remaining_families']);
        self::assertSame(DiscoveryScanProgress::NO, $p['scan_complete']);
        self::assertStringNotContainsString('Scan complete', DiscoveryScanProgress::summarySentence($p));
    }

    // ── (6) enumeration incomplete ──────────────────────────────────────

    /**
     * Every family terminal but the code listing NOT drained: still
     * incomplete, because unenumerated code ids may hold anything.
     */
    public function testIncompleteEnumerationIsNeverACompleteScan(): void
    {
        $this->seedEnumerated(3);
        for ($i = 1; $i <= 3; $i++) {
            $this->classify($i, CosmwasmClassifier::NOT_CW721);
        }
        // No markEnumerationComplete() — the walk is still open.

        $p = DiscoveryScanProgress::forChain(self::CHAIN);

        self::assertSame(DiscoveryScanProgress::NO, $p['enumeration_complete']);
        self::assertSame(0, $p['remaining_families'], 'the queue really is empty');
        self::assertSame(
            DiscoveryScanProgress::NO,
            $p['scan_complete'],
            'an empty queue is not enough while enumeration is open'
        );
    }

    /** A chain nobody has ever walked: not started, definitely not complete. */
    public function testAnUnwalkedChainIsIncompleteRatherThanUnknown(): void
    {
        $p = DiscoveryScanProgress::forChain(self::CHAIN);

        self::assertTrue($p['ok']);
        self::assertSame(DiscoveryScanProgress::NO, $p['enumeration_complete']);
        self::assertSame(0, $p['total_families']);
        self::assertSame(DiscoveryScanProgress::NO, $p['scan_complete']);
    }
}
