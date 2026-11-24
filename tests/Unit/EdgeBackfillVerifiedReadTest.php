<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\EdgeRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The trust-graph backfill may not invent edges (F-472).
 *
 * ## Why this is a write-path test, not a read-path one
 *
 * `backfillFromSources()` aggregates vote weights per (voter, page owner)
 * and UPSERTS one edge row per result. Every row the source read returns
 * becomes a durable trust-graph edge: source, target, weight, vote count.
 * Trust rank is computed over those edges.
 *
 * So the usual read-failure question — "does an empty answer look like
 * 'no rows'?" — is the smaller half here. The larger half is that rows
 * which are not this query's own get WRITTEN. A read gate recovers the
 * moment the database does. This does not: the wrong edges stay.
 *
 * ## What the old form could not see
 *
 * The read was `$wpdb->get_results($wpdb->prepare(...)) ?: []`.
 * `get_results()` discards `query()`'s return value, and `wpdb::query()`
 * returns false ABOVE its own `flush()` on a failed reconnect or an
 * emptied `query` filter — so `last_error` is EMPTY and `last_result`
 * still holds the PREVIOUS statement's rows. `get_results()` hands those
 * back. The trailing `?: []` then collapsed a failed read and "no votes
 * yet" into the same answer, so the failure could not even be
 * distinguished from an empty source table.
 *
 * It now reads through `runRead(..., failClosed: true)`, the same guard
 * `recalculateVoteEdge()` already uses: clear `last_error`, call
 * `query()` explicitly, check its return AND the error, throw.
 *
 * ## Why these assertions are on `$sent`, not on the return value
 *
 * A test that only asserted "0 edges returned" would pass against code
 * that wrote the stale rows and then returned a count of zero. The
 * assertions below are on the statements the double was actually asked
 * to run, so a write that happened cannot be hidden by what the method
 * chose to return.
 *
 * @see \BCC\Trust\Tests\Integration\TrustEdgeVoteIntegrityIntegrationTest
 *      for the same repository against real MySQL.
 */
#[Group('unit')]
#[CoversClass(EdgeRepository::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EdgeBackfillVerifiedReadTest extends TestCase
{
    private \BccEdgeBackfillFakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../Stubs/edge-backfill-stubs.php';

        $GLOBALS['__bcc_edge_log'] = [];
        $this->wpdb                = new \BccEdgeBackfillFakeWpdb();
        $GLOBALS['wpdb']           = $this->wpdb;
    }

    /**
     * A source read that bails pre-flush writes NOTHING.
     *
     * The rows left in `last_result` are the table-existence probes'
     * — `hiddenReviewExclusion()` runs those first, so they are a real
     * previous query. They are shaped like backfill rows on purpose:
     * under the old form they would have been upserted as edges between
     * the users they name.
     */
    public function testABailingSourceReadUpsertsNothing(): void
    {
        $this->wpdb->resultRows = self::staleRows();
        $this->wpdb->bailOnBackfillRead = true;

        try {
            $this->repository()->backfillFromSources();
            self::fail('a source read that could not be trusted was treated as usable');
        } catch (RepositoryReadFailure) {
            // expected
        }

        // ANTI-VACUITY: without rows sitting in last_result the old form
        // would also have written nothing, and this test would pass
        // against the defect it exists to pin.
        self::assertNotSame(
            [],
            $this->wpdb->last_result,
            'premise: another statement left rows behind for the bail to pick up'
        );

        // The premise that makes this a BAIL and not an ordinary failure:
        // no driver error was recorded, so no last_error check could see it.
        self::assertSame('', $this->wpdb->last_error, 'premise: the bail sets no error');

        self::assertSame([], $this->wpdb->edgeUpserts(), 'an edge was written from untrusted rows');
        self::assertSame([], $this->wpdb->deletes, 'an edge was deleted from untrusted rows');

        // And it said WHY, naming the absence of a driver error — the
        // detail that separates a bail from a reported failure.
        self::assertStringContainsString('wpdb early return', self::loggedReason());
    }

    /**
     * The control that proves the test above is about the BAIL and not
     * about the method being unable to write at all: a healthy read
     * upserts one row per aggregated pair, with the values it read.
     */
    public function testAHealthySourceReadUpsertsEveryAggregatedPair(): void
    {
        $this->wpdb->resultRows = self::staleRows();

        $count = $this->repository()->backfillFromSources();

        self::assertSame(2, $count);

        $upserts = $this->wpdb->edgeUpserts();
        self::assertCount(2, $upserts, 'one upsert per aggregated (voter, owner) pair');
        self::assertStringContainsString('( 7, 9, 4.5, 3,', $upserts[0]);
        self::assertStringContainsString('( 11, 9, 2, 1,', $upserts[1]);
        self::assertSame([], $this->wpdb->deletes);
    }

    /**
     * A genuinely EMPTY source table is still an ordinary answer: no
     * throw, no write, zero edges. This is what keeps the fail-closed
     * read from turning a quiet site into an error.
     */
    public function testAnEmptySourceTableIsNotAFailure(): void
    {
        $count = $this->repository()->backfillFromSources();

        self::assertSame(0, $count);
        self::assertSame([], $this->wpdb->edgeUpserts());
        self::assertSame([], $GLOBALS['__bcc_edge_log'], 'an empty source table logged a read failure');
    }

    private function repository(): EdgeRepository
    {
        // The REAL VoteRepository: `hiddenReviewExclusion()` is part of the
        // statement under test, and a stub would let its SQL drift.
        return new EdgeRepository(new VoteRepository());
    }

    /**
     * Rows shaped like the backfill's own result — source, target, summed
     * weight, vote count.
     *
     * @return list<object>
     */
    private static function staleRows(): array
    {
        return [
            (object) [
                'source_user_id' => '7',
                'target_user_id' => '9',
                'total_weight'   => '4.5',
                'vote_count'     => '3',
            ],
            (object) [
                'source_user_id' => '11',
                'target_user_id' => '9',
                'total_weight'   => '2.0',
                'vote_count'     => '1',
            ],
        ];
    }

    private static function loggedReason(): string
    {
        $entries = $GLOBALS['__bcc_edge_log'] ?? [];
        self::assertNotSame([], $entries, 'the read failure was never logged');

        $last = $entries[count($entries) - 1];

        return (string) ($last[1]['db_error'] ?? '');
    }
}
