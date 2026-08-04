<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Rank\Repositories\FindingsRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Stubs/rank-unit-stubs.php';

/**
 * §15.5 once-only appeal gate — the REAL FindingsRepository against a
 * query-recording $wpdb fake (FakeReportWpdb precedent):
 *
 *   - the UPDATE is guarded on BOTH subject ownership AND
 *     appeal_status='' in SQL (race-safe: one winner, ever);
 *   - rows-affected 0 (replay, foreign finding, already adjudicated)
 *     reads as false — the endpoint's 409;
 *   - invalid ids short-circuit without touching the database.
 */
final class FindingAppealGateTest extends TestCase
{
    /** @var object The fake installed as global $wpdb. */
    private object $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class {
            public string $prefix = 'wp_';

            /** @var list<string> */
            public array $queries = [];

            /** @var list<array<int, mixed>> */
            public array $params = [];

            public int|false $rowsAffected = 1;

            public function prepare(string $sql, mixed ...$args): string
            {
                $this->params[] = $args;
                return $sql;
            }

            public function query(string $sql): int|false
            {
                $this->queries[] = $sql;
                return $this->rowsAffected;
            }
        };

        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testRequestAppealIsGuardedOnOwnershipAndVirginStatus(): void
    {
        $repository = new FindingsRepository();

        self::assertTrue($repository->requestAppeal(5, 7));

        self::assertCount(1, $this->wpdb->queries);
        $sql = $this->wpdb->queries[0];
        self::assertStringContainsString("SET appeal_status = 'requested'", $sql);
        self::assertStringContainsString('subject_user_id = %d', $sql, 'ownership is enforced in SQL, not just PHP');
        self::assertStringContainsString("appeal_status = ''", $sql, 'once-only: only a virgin appeal_status can flip');
        self::assertSame([5, 7], $this->wpdb->params[0]);
    }

    public function testReplayOrForeignFindingReadsAsFalse(): void
    {
        $repository = new FindingsRepository();

        // rows-affected 0: already requested / already adjudicated /
        // not the caller's finding — all collapse to the same false.
        $this->wpdb->rowsAffected = 0;

        self::assertFalse($repository->requestAppeal(5, 7));
        self::assertCount(1, $this->wpdb->queries, 'the guarded UPDATE ran exactly once');
    }

    public function testInvalidIdsShortCircuitWithoutQuerying(): void
    {
        $repository = new FindingsRepository();

        self::assertFalse($repository->requestAppeal(0, 7));
        self::assertFalse($repository->requestAppeal(5, 0));
        self::assertFalse($repository->requestAppeal(-1, -1));

        self::assertSame([], $this->wpdb->queries);
    }
}
