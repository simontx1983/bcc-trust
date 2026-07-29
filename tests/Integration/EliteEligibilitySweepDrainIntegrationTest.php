<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\ScoreRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Locks the drain property the §J.12 elite-eligibility backfill depends on,
 * against a real MySQL — because the defect in #130 lived precisely in the
 * interaction between the sweep's SQL predicate and the stamping write, which
 * no pure-PHP double would have reproduced.
 *
 * The bug: the backfill passed `now + 1 YEAR` as its staleness boundary so
 * that every unevaluated row matched on the first pass. But the predicate is
 *
 *     elite_eligible_at IS NULL OR elite_eligible_at < :staleBefore
 *
 * and a row stamped `now` still satisfies `now < now + 1 year`. The match set
 * therefore never shrank: the loop re-fetched the same pages until its
 * iteration cap, `$drained` never became true, and the migration returned
 * INCOMPLETE on every request forever — on ANY environment holding a single
 * page at or above the elite threshold. The runner's all-done signature was
 * consequently never written, so its one-option fast path never engaged.
 *
 * These tests assert the boundary semantics directly. If someone reintroduces
 * a future-dated boundary, testStampedRowLeavesTheSweepSet fails.
 */
#[Group('integration')]
#[CoversClass(ScoreRepository::class)]
final class EliteEligibilitySweepDrainIntegrationTest extends TestCase
{
    private const PAGE_ID  = 987654321;
    private const MIN_SCORE = 80;

    private function table(): string
    {
        return $GLOBALS['wpdb']->prefix . 'bcc_trust_page_scores';
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->query(
            'DELETE FROM `' . $this->table() . '` WHERE page_id = ' . self::PAGE_ID
        );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->query(
            'DELETE FROM `' . $this->table() . '` WHERE page_id = ' . self::PAGE_ID
        );
    }

    /** Insert one never-evaluated page sitting at the elite threshold. */
    private function seedEliteScoredPage(): void
    {
        $GLOBALS['wpdb']->query(
            'INSERT INTO `' . $this->table() . '`
                (page_id, category_id, page_owner_id, total_score,
                 elite_eligible, elite_eligible_at)
             VALUES (' . self::PAGE_ID . ', 0, 1, 100.00, 0, NULL)'
        );
    }

    /** @return list<int> */
    private function sweep(string $staleBefore): array
    {
        return (new ScoreRepository())->listPagesForEliteSweep(
            self::MIN_SCORE,
            $staleBefore,
            100,
            0
        );
    }

    public function testNeverEvaluatedRowIsPickedUpBySweep(): void
    {
        $this->seedEliteScoredPage();

        self::assertContains(
            self::PAGE_ID,
            $this->sweep(gmdate('Y-m-d H:i:s')),
            'a NULL elite_eligible_at must always match, whatever the boundary'
        );
    }

    /**
     * THE regression. Stamp the row the way recomputeFor() does, then re-run
     * the sweep with the SAME boundary the backfill would still be holding.
     * The row must be gone — that is the only thing that lets the loop drain.
     */
    public function testStampedRowLeavesTheSweepSet(): void
    {
        $this->seedEliteScoredPage();

        // The backfill computes its boundary ONCE, before the loop.
        $staleBefore = gmdate('Y-m-d H:i:s');

        self::assertContains(self::PAGE_ID, $this->sweep($staleBefore), 'precondition: row is in the set');

        // recomputeFor()'s persistence step, verbatim.
        $persisted = (new ScoreRepository())->setEliteEligibility(
            self::PAGE_ID,
            false,
            gmdate('Y-m-d H:i:s')
        );
        self::assertTrue($persisted, 'the stamp must report success');

        self::assertNotContains(
            self::PAGE_ID,
            $this->sweep($staleBefore),
            'a stamped row MUST leave the match set or the backfill never drains (#130)'
        );
    }

    /**
     * The boundary that caused #130, pinned as an explicit negative so the
     * reasoning cannot be lost: with a future-dated boundary the stamped row
     * stays in the set, which is exactly the infinite re-fetch.
     */
    public function testFutureDatedBoundaryWouldNotDrain(): void
    {
        $this->seedEliteScoredPage();

        (new ScoreRepository())->setEliteEligibility(self::PAGE_ID, false, gmdate('Y-m-d H:i:s'));

        $futureBoundary = gmdate('Y-m-d H:i:s', time() + YEAR_IN_SECONDS);

        self::assertContains(
            self::PAGE_ID,
            $this->sweep($futureBoundary),
            'documents the defect: a future boundary keeps stamped rows in the set forever'
        );
    }

    /**
     * Re-affirming the SAME verdict within the same second changes zero rows.
     * MySQL reports changed rows (WordPress does not set CLIENT_FOUND_ROWS),
     * and setEliteEligibility used to read that 0 as a persistence failure —
     * which made recomputeFor() return false, record a bogus
     * `schema_unavailable` degradation, and abort the backfill mid-drain.
     */
    public function testIdempotentReAffirmationReportsSuccessNotFailure(): void
    {
        $this->seedEliteScoredPage();

        $repo  = new ScoreRepository();
        $stamp = gmdate('Y-m-d H:i:s');

        self::assertTrue($repo->setEliteEligibility(self::PAGE_ID, false, $stamp), 'first write changes the row');
        self::assertTrue(
            $repo->setEliteEligibility(self::PAGE_ID, false, $stamp),
            'an identical re-write changes 0 rows and MUST still report success (#130)'
        );
    }

    /** A page with no rows at all is not a persistence failure either. */
    public function testMissingPageDoesNotReportPersistenceFailure(): void
    {
        self::assertTrue(
            (new ScoreRepository())->setEliteEligibility(self::PAGE_ID, false, gmdate('Y-m-d H:i:s')),
            'no matching row is "nothing to persist", not a schema/SQL failure'
        );
    }
}
