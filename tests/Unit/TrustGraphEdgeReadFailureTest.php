<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\EdgeRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Security\TrustGraph;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-574: a trust graph computed from an edge read that cannot be verified
 * is never written. The full walk and the neighbour walk FAIL CLOSED (the
 * read throws, nothing is computed or persisted); the subgraph fetch is
 * fail-safe (an empty subgraph is the caller's "skip").
 *
 * TrustGraph runs on real code here, over a scripted EdgeRepository and a
 * UserInfoRepository that records every rank write.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TrustGraphEdgeReadFailureTest extends TestCase
{
    /** @var object scripted edge reads + call log */
    private object $edges;

    /** @var object rank-write log */
    private object $userInfo;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('apply_filters')) {
            // Two edges per page, so a walk has a second page to fail on.
            eval('function apply_filters($hook, $value, ...$args) { return $hook === "bcc_trust_graph_edge_chunk" ? 2 : $value; }');
        }
        if (!function_exists('wp_cache_set')) {
            eval('function wp_cache_set(...$args) { return true; }');
        }

        $this->edges = new class extends EdgeRepository {
            /** @var list<list<object>|\Throwable> one entry per getAllEdges() call */
            public array $pages = [];
            /** @var list<int>|\Throwable */
            public $neighbors = [];
            /** @var list<object> */
            public array $subgraph = [];
            /** @var list<string> */
            public array $calls = [];

            public function __construct()
            {
            }

            public function getAllEdges(int $afterId = 0, int $limit = 1000): array
            {
                $this->calls[] = 'getAllEdges';
                $page = array_shift($this->pages) ?? [];
                if ($page instanceof \Throwable) {
                    throw $page;
                }
                return $page;
            }

            public function getNeighborIds(array $userIds): array
            {
                $this->calls[] = 'getNeighborIds';
                if ($this->neighbors instanceof \Throwable) {
                    throw $this->neighbors;
                }
                return $this->neighbors;
            }

            public function getEdgesForUsers(array $userIds, int $limit = self::MAX_SUBGRAPH_EDGES): array
            {
                $this->calls[] = 'getEdgesForUsers';
                return $this->subgraph;
            }

            public function backfillFromSources(): int
            {
                $this->calls[] = 'backfillFromSources';
                return 0;
            }
        };

        $this->userInfo = new class extends UserInfoRepository {
            /** @var list<string> */
            public array $writes = [];

            public function __construct()
            {
            }

            public function getBulkByUserIds(array $userIds): array
            {
                return [];
            }

            public function bulkUpdateTrustRanks(array $ranks): bool
            {
                $this->writes[] = 'bulkUpdateTrustRanks';
                return true;
            }

            public function updateTrustRank(int $userId, float $rank): void
            {
                $this->writes[] = 'updateTrustRank:' . $userId;
            }
        };
    }

    private function graph(): TrustGraph
    {
        $class = new ReflectionClass(TrustGraph::class);
        /** @var TrustGraph $graph */
        $graph = $class->newInstanceWithoutConstructor();
        foreach ([
            'userInfoRepo'            => $this->userInfo,
            'edgeRepo'                => $this->edges,
            'voteWeightMultiplier'    => 1.0,
            'endorseWeightMultiplier' => 1.0,
            'githubAgeBoost'          => 0.0,
            'githubFollowersBoost'    => 0.0,
        ] as $name => $value) {
            $class->getProperty($name)->setValue($graph, $value);
        }
        return $graph;
    }

    private static function edge(int $id, int $from, int $to): object
    {
        return (object) ['id' => (string) $id, 'source_user_id' => (string) $from, 'target_user_id' => (string) $to, 'weight' => '1', 'edge_type' => 'vote'];
    }

    private static function failure(): RepositoryReadFailure
    {
        return new RepositoryReadFailure(EdgeRepository::class, 'getAllEdges', 'simulated');
    }

    // ── the full walk ─────────────────────────────────────────────────

    /** A page that fails after a full first page: nothing is computed on the truncated graph, nothing written. */
    public function testAFailedPageMidWalkWritesNoRanks(): void
    {
        $this->edges->pages = [[self::edge(1, 1, 2), self::edge(2, 2, 3)], self::failure()];

        try {
            $this->graph()->batchCalculateAllRanks();
            self::fail('the read failure must reach the caller');
        } catch (RepositoryReadFailure) {
        }

        self::assertSame([], $this->userInfo->writes, 'no rank may be written from a truncated graph');
        self::assertSame(['getAllEdges', 'getAllEdges'], $this->edges->calls);
    }

    /** A failed FIRST page is not an empty table: no inline backfill write, no ranks. */
    public function testAFailedFirstPageIsNotAnEmptyTable(): void
    {
        $this->edges->pages = [self::failure()];

        try {
            $this->graph()->batchCalculateAllRanks();
            self::fail('the read failure must reach the caller');
        } catch (RepositoryReadFailure) {
        }

        self::assertNotContains('backfillFromSources', $this->edges->calls);
        self::assertSame([], $this->userInfo->writes);
    }

    /** ⭐ Control: a walk that reads cleanly writes ranks, so the "no write" assertions mean something. */
    public function testACleanWalkWritesRanks(): void
    {
        $this->edges->pages = [[self::edge(1, 1, 2), self::edge(2, 2, 3)], [self::edge(3, 3, 1)]];

        $this->graph()->batchCalculateAllRanks();

        self::assertSame(['bulkUpdateTrustRanks'], $this->userInfo->writes);
    }

    // ── the incremental pass ──────────────────────────────────────────

    public function testANeighbourReadFailureWritesNoRank(): void
    {
        $this->edges->neighbors = new RepositoryReadFailure(EdgeRepository::class, 'getNeighborIds', 'simulated');

        try {
            $this->graph()->incrementalUpdate([1]);
            self::fail('the read failure must reach the caller');
        } catch (RepositoryReadFailure) {
        }

        self::assertNotContains('getEdgesForUsers', $this->edges->calls, 'no subgraph is fetched for an unknown neighbourhood');
        self::assertSame([], $this->userInfo->writes);
    }

    /** A hub cut off at the walk's bound still reads as "more than 500", so it is still deferred. */
    public function testAHubCutOffAtTheBoundIsStillDeferred(): void
    {
        self::assertGreaterThan(500, EdgeRepository::MAX_NEIGHBOR_IDS, 'the bound must stay above the 500-user cap');
        $this->edges->neighbors = range(1000, 1000 + EdgeRepository::MAX_NEIGHBOR_IDS - 1);

        self::assertSame([], $this->graph()->incrementalUpdate([1]));
        self::assertNotContains('getEdgesForUsers', $this->edges->calls);
        self::assertSame([], $this->userInfo->writes);
    }

    /** Fail-safe subgraph: an empty (or unverifiable, so empty) fetch is a skip that writes nothing. */
    public function testAnEmptySubgraphWritesNothing(): void
    {
        $this->edges->neighbors = [2, 3];
        $this->edges->subgraph  = [];

        self::assertSame([], $this->graph()->incrementalUpdate([1]));
        self::assertSame([], $this->userInfo->writes);
    }

    /** ⭐ Control: a clean incremental pass writes the neighbourhood's ranks. */
    public function testACleanIncrementalPassWritesRanks(): void
    {
        $this->edges->neighbors = [2, 3];
        $this->edges->subgraph  = [self::edge(1, 1, 2), self::edge(2, 2, 3), self::edge(3, 3, 1)];

        self::assertNotSame([], $this->graph()->incrementalUpdate([1]));
        self::assertNotSame([], $this->userInfo->writes);
    }
}
