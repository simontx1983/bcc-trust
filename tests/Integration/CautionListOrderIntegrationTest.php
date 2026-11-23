<?php
/**
 * F-454: the caution/risky list's excluding reader against a real database.
 *
 * The unit suite (CautionListFailClosedTest) pins the last-good logic with a
 * $wpdb double that returns rows in the order it is given. What only real
 * SQL can show is pinned here:
 *   - the ORDER BY: risky before caution, then the lowest score, then the
 *     member id, so the list is the same on every read and the members past
 *     the ceiling would be the least severe;
 *   - the WHERE: member self-pages only (not entity pages), category 0 only,
 *     caution and risky only;
 *   - a read the harness breaks is served from the last-good list, and with
 *     none it throws.
 *
 * Sentinel owner ids (99070x); other suites' rows may share the table, so
 * the assertions look at these ids only.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Services\MemberSelfPageService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReputationRepository::class)]
final class CautionListOrderIntegrationTest extends TestCase
{
    private const RISKY_30      = 990701;
    private const RISKY_10      = 990702;
    private const CAUTION_40_HI = 990704;
    private const CAUTION_40_LO = 990703;
    private const CAUTION_5     = 990705;
    private const NEUTRAL       = 990706;
    private const ENTITY_PAGE   = 990707;
    private const OTHER_CAT     = 990708;

    /** The caution read, and nothing else the repository runs. */
    private const CAUTION_READ = "/reputation_tier IN \\('caution', 'risky'\\)/";

    protected function setUp(): void
    {
        parent::setUp();
        self::purge();
        self::seed();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        self::purge();
        parent::tearDown();
    }

    public function testTheListIsReadMostSevereFirst(): void
    {
        $ids = (new ReputationRepository())->getCautionAndRiskyUserIdsForExclusion();

        self::assertSame(
            [self::RISKY_10, self::RISKY_30, self::CAUTION_5, self::CAUTION_40_LO, self::CAUTION_40_HI],
            self::sentinels($ids),
            'risky first, then the lowest score, then the id; never a neutral member, an entity page or another category'
        );
    }

    /** The inclusion-set worker's reader reads in the same order. */
    public function testTheWorkersReaderUsesTheSameOrder(): void
    {
        $ids = (new ReputationRepository())->getCautionAndRiskyUserIds(1000);

        self::assertSame(
            [self::RISKY_10, self::RISKY_30, self::CAUTION_5, self::CAUTION_40_LO, self::CAUTION_40_HI],
            self::sentinels($ids)
        );
    }

    public function testAFailedReadServesTheLastGoodList(): void
    {
        $repo = new ReputationRepository();
        $good = $repo->getCautionAndRiskyUserIdsForExclusion();
        self::assertNotSame([], self::sentinels($good));

        $GLOBALS['wpdb']->failQueriesMatching = self::CAUTION_READ;

        self::assertSame($good, $repo->getCautionAndRiskyUserIdsForExclusion());
        self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'the fault really hit the read');
    }

    public function testAFailedReadWithNoLastGoodListThrows(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = self::CAUTION_READ;

        $this->expectException(RepositoryException::class);
        (new ReputationRepository())->getCautionAndRiskyUserIdsForExclusion();
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function sentinels(array $ids): array
    {
        return array_values(array_filter($ids, static fn (int $id): bool => $id >= 990701 && $id <= 990708));
    }

    private static function seed(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bcc_trust_page_scores';
        $base  = MemberSelfPageService::ID_BASE;
        $rows  = [
            [$base + self::RISKY_30, 0, self::RISKY_30, 30.0, 'risky'],
            [$base + self::RISKY_10, 0, self::RISKY_10, 10.0, 'risky'],
            [$base + self::CAUTION_40_HI, 0, self::CAUTION_40_HI, 40.0, 'caution'],
            [$base + self::CAUTION_40_LO, 0, self::CAUTION_40_LO, 40.0, 'caution'],
            [$base + self::CAUTION_5, 0, self::CAUTION_5, 5.0, 'caution'],
            [$base + self::NEUTRAL, 0, self::NEUTRAL, 1.0, 'neutral'],
            // An entity page (below ID_BASE) is not a member.
            [990707, 0, self::ENTITY_PAGE, 0.0, 'caution'],
            // A category row is not the member's own tier.
            [$base + self::OTHER_CAT, 2, self::OTHER_CAT, 0.0, 'caution'],
        ];
        foreach ($rows as [$pageId, $categoryId, $ownerId, $score, $tier]) {
            self::assertNotFalse($wpdb->insert($table, [
                'page_id'         => $pageId,
                'category_id'     => $categoryId,
                'page_owner_id'   => $ownerId,
                'total_score'     => $score,
                'reputation_tier' => $tier,
            ]), 'score fixture: ' . $wpdb->last_error);
        }
    }

    private static function purge(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}bcc_trust_page_scores WHERE page_owner_id BETWEEN 990701 AND 990708");
        delete_option(ReputationRepository::CAUTION_LAST_GOOD_OPTION);
    }
}
