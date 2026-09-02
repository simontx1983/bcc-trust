<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;
use PHPUnit\Framework\TestCase;

/**
 * The REPOSITORY half of "a database that did not answer is not an empty
 * queue".
 *
 * ── WHY THE UNIT TEST IS NOT ENOUGH, AND HOW WE KNOW ────────────────────
 * ProvisioningReadFailureTest drives the SERVICE, and the service talks to
 * a `CollectionRepository` fake. It proves the service reacts correctly to
 * an unavailable result — but the real repository's code, the part that has
 * to notice the failure in the first place, never runs. The mutation
 * controls said so out loud: planting `available => true` on a failed read
 * in the real repository left that unit test perfectly green. A SURVIVOR is
 * the test admitting it does not cover the line.
 *
 * So this covers it where it can only be covered: against a real server,
 * with the query actually broken.
 *
 * ── THE FAILURE CLASS ───────────────────────────────────────────────────
 * `wpdb::get_results()` returns `last_result` — an empty array — for a
 * FAILED query exactly as it does for a genuine no-rows result, and returns
 * null only for an empty query string. `get_row()` is null for both "no such
 * row" and "the query failed". In both cases `last_error` is the ONLY thing
 * that separates a fact from an outage, which is why reading it is not
 * defensive noise but the entire discrimination.
 */
final class ProvisioningReadFailureIntegrationTest extends TestCase
{
    private const ADDRESS = '0xfeedfacefeedfacefeedfacefeedfacefeedface';

    private int $collectionId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb']->clearFaultInjection();
        \BCC\Core\Log\Logger::reset();

        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$table}` (contract_address, chain_id, is_verified, fetched_at, expires_at,
                                     provisioning_state, provisioning_requested_at, provisioning_requested_by)
             VALUES (%s, %d, 1, NOW(), NOW(), %s, NOW(), %d)",
            self::ADDRESS,
            1,
            ProvisioningState::REQUESTED,
            7
        ));

        $this->collectionId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->collectionId, 'the fixture must exist for any of this to mean anything');
    }

    protected function tearDown(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        $wpdb->query('DELETE FROM `' . CollectionRepository::table() . '`');

        parent::tearDown();
    }

    /** @return list<string> */
    private function errorMessages(): array
    {
        return array_map(
            static fn (array $l): string => (string) $l['message'],
            array_values(array_filter(
                \BCC\Core\Log\Logger::$lines,
                static fn (array $l): bool => $l['level'] === 'error'
            ))
        );
    }

    // ── The queue read ──────────────────────────────────────────────────

    /**
     * The control case FIRST: a queue with work in it reads as available and
     * non-empty. Without this, "available === false" below could be true for
     * any reason at all — a broken fixture, a wrong table — and the test
     * would still look like it was proving something.
     */
    public function testAHealthyQueueReadIsAvailableAndReturnsTheRow(): void
    {
        $queue = CollectionRepository::listRequested(0, 50);

        self::assertTrue($queue['available']);
        self::assertCount(1, $queue['rows']);
        self::assertSame($this->collectionId, (int) $queue['rows'][0]->id);
    }

    public function testAFailedQueueReadReportsUnavailableRatherThanEmpty(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = '/ORDER BY c\.id ASC/i';

        $queue = CollectionRepository::listRequested(0, 50);

        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
        self::assertFalse(
            $queue['available'],
            'a SELECT that never executed must not be reported as a drained queue'
        );
        self::assertSame([], $queue['rows']);
    }

    public function testTheFailedQueueReadIsLoggedAsUnavailableNotEmpty(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/ORDER BY c\.id ASC/i';

        CollectionRepository::listRequested(0, 50);

        self::assertStringContainsString(
            'UNAVAILABLE, not empty',
            implode("\n", $this->errorMessages())
        );
    }

    /**
     * A genuinely empty queue is a SUCCESSFUL read. The whole value of the
     * flag is that it separates the two, so a test that only ever saw the
     * failure side would not prove separation.
     */
    public function testAGenuinelyEmptyQueueIsAvailable(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET provisioning_state = %s, provisioning_requested_at = NULL,
                                   provisioning_requested_by = NULL",
            ProvisioningState::NONE
        ));

        $queue = CollectionRepository::listRequested(0, 50);

        self::assertTrue($queue['available'], 'no rows is an answer, not an outage');
        self::assertSame([], $queue['rows']);
        self::assertSame([], $this->errorMessages(), 'and it is not an error');
    }

    // ── The single-row read ─────────────────────────────────────────────

    public function testAHealthyRowReadIsAvailableAndReturnsTheRow(): void
    {
        $read = CollectionRepository::readProvisioningRow($this->collectionId);

        self::assertTrue($read['available']);
        self::assertNotNull($read['row']);
        self::assertSame(ProvisioningState::REQUESTED, (string) $read['row']->provisioning_state);
    }

    public function testAFailedRowReadReportsUnavailableRatherThanNotFound(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = '/SELECT id, is_verified, canonical_identifier/i';

        $read = CollectionRepository::readProvisioningRow($this->collectionId);

        self::assertGreaterThan(0, $wpdb->injectedFailures);
        self::assertFalse($read['available'], 'the row exists; the query is what failed');
        self::assertNull($read['row']);
        self::assertStringContainsString(
            'UNAVAILABLE, not absent',
            implode("\n", $this->errorMessages())
        );
    }

    /**
     * A collection that genuinely is not there is available-and-null. This
     * is the assertion the whole change turns on: the same null, two
     * completely different meanings, and only `available` tells them apart.
     */
    public function testAMissingCollectionIsAvailableAndNull(): void
    {
        $read = CollectionRepository::readProvisioningRow($this->collectionId + 999_000);

        self::assertTrue($read['available']);
        self::assertNull($read['row']);
        self::assertSame([], $this->errorMessages());
    }

    /**
     * A non-positive id is answered without touching the database at all.
     * It is a substantive answer — there is no such collection and asking
     * again will not change that — so calling it unavailable would invent an
     * outage and invite an infinite retry.
     */
    public function testANonPositiveIdIsASubstantiveAnswerNotAFault(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->resetQueryCount();

        $read = CollectionRepository::readProvisioningRow(0);

        self::assertTrue($read['available']);
        self::assertNull($read['row']);
        self::assertSame(0, $wpdb->queryCount, 'no query should have been issued at all');
    }
}
