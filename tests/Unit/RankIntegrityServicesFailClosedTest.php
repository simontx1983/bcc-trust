<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Rank\Repositories\ClusterFindingsRepository;
use BCC\Trust\Rank\Repositories\FindingsRepository;
use BCC\Trust\Rank\Repositories\RankEventsRepository;
use BCC\Trust\Rank\Services\IndependenceResolver;
use BCC\Trust\Rank\Services\RankEvidenceIngestor;
use BCC\Trust\Rank\Services\RankStateService;
use BCC\Trust\Rank\Support\RankScoringConfig;
use BCC\Trust\Tests\Stubs\RankUnitWpFakes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Stubs/rank-unit-stubs.php';

/**
 * F-464 / F-465: the services in front of the now fail-closed rank reads.
 *
 *   - The evidence ingestor: a failed confirmed-cluster read ledgers
 *     nothing. It throws; the subscriber wiring records
 *     `evidence_ingest_failed`.
 *   - The own-view progression findings block: display only, so a failed
 *     read shows an empty list instead of failing the whole progression.
 */
final class RankIntegrityServicesFailClosedTest extends TestCase
{
    protected function setUp(): void
    {
        RankUnitWpFakes::reset();
        $memo = new \ReflectionProperty(IndependenceResolver::class, 'memberToRepresentative');
        $memo->setValue(null, null);
    }

    /** @return array{0: RankEvidenceIngestor, 1: object} */
    private function ingestor(bool $clustersFail, array $confirmed = []): array
    {
        $events = new class extends RankEventsRepository {
            /** @var list<array{0: string, 1: int, 2: string, 3: float}> */
            public array $appended = [];

            public function __construct()
            {
            }

            public function append(
                string $eventUid,
                int $subjectUserId,
                string $sourceType,
                int $sourceId,
                string $category,
                int $relationshipUserId,
                float $rawValue,
                float $cappedValue
            ): bool {
                unset($sourceType, $sourceId, $relationshipUserId, $rawValue);
                $this->appended[] = [$eventUid, $subjectUserId, $category, $cappedValue];
                return true;
            }
        };

        $clusters = new class ($clustersFail, $confirmed) extends ClusterFindingsRepository {
            /** @param list<object> $rows */
            public function __construct(private readonly bool $fails, private readonly array $rows)
            {
            }

            public function listActiveConfirmed(): array
            {
                if ($this->fails) {
                    throw new RepositoryReadFailure(self::class, __FUNCTION__, 'simulated');
                }
                return $this->rows;
            }
        };

        return [new RankEvidenceIngestor($events, new IndependenceResolver($clusters), RankScoringConfig::fromDefaultFile()), $events];
    }

    public function testAFailedClusterReadLedgersNothing(): void
    {
        [$ingestor, $events] = $this->ingestor(true);

        try {
            $ingestor->ingest(41, 'post', 900);
            self::fail('the event was ingested without the cluster findings');
        } catch (RepositoryReadFailure) {
        }

        self::assertSame([], $events->appended, 'not ledgered at all');
    }

    /** Control: with the findings readable, a non-representative member is zero-credited. */
    public function testAReadableClusterZeroCreditsANonRepresentative(): void
    {
        // TransactionManager::run() talks to $wpdb directly; a minimal one.
        $GLOBALS['wpdb'] = new class {
            public string $last_error = '';

            public function get_var(string $sql): string
            {
                unset($sql);
                return '0';
            }

            /** @return int */
            public function query(string $sql)
            {
                unset($sql);
                return 1;
            }
        };

        $finding = (object) ['id' => '1', 'level' => 'confirmed', 'member_user_ids' => '[41,42]', 'representative_user_id' => '42', 'selection_method' => 'auto_oldest_account', 'effective_at' => '2026-11-01 00:00:00'];
        [$ingestor, $events] = $this->ingestor(false, [$finding]);

        $ingestor->ingest(41, 'post', 900);

        self::assertNotSame([], $events->appended);
        foreach ($events->appended as [, , , $capped]) {
            self::assertSame(0.0, $capped);
        }
    }

    public function testTheProgressionFindingsBlockShowsAnEmptyListOnAFailedRead(): void
    {
        $findings = new class extends FindingsRepository {
            public function __construct()
            {
            }

            public function getActiveForUser(int $userId): array
            {
                throw new RepositoryReadFailure(self::class, __FUNCTION__, 'simulated');
            }

            public function getRecentlyReversedForUser(int $userId, string $since): array
            {
                unset($userId, $since);
                return [];
            }
        };

        $service = (new \ReflectionClass(RankStateService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(RankStateService::class, 'findings'))->setValue($service, $findings);
        $block = new \ReflectionMethod(RankStateService::class, 'findingsBlock');

        self::assertSame([], $block->invoke($service, 7), 'display only: an empty list, not a fatal');
    }
}
