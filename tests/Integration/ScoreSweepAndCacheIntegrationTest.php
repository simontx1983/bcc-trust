<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\ScoreRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-86: the hourly drift sweep must round-robin, not re-pick the same pages.
 * F-87: the attestation-bonus and endorsement-count writers must invalidate
 *       the page-score cache themselves.
 */
#[Group('integration')]
#[CoversClass(ScoreRepository::class)]
final class ScoreSweepAndCacheIntegrationTest extends TestCase
{
    private const FIRST_PAGE = 970601;
    private const LAST_PAGE  = 970609;

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $GLOBALS['__bcc_test_object_cache'] = [];
        $this->deleteFixtures();
    }

    protected function tearDown(): void
    {
        $this->deleteFixtures();
        $GLOBALS['__bcc_test_object_cache'] = [];
    }

    private function deleteFixtures(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'DELETE FROM `' . TableRegistry::scores() . '` WHERE page_id BETWEEN %d AND %d',
            self::FIRST_PAGE,
            self::LAST_PAGE
        ));
    }

    private function seedRow(int $pageId, int $categoryId, string $lastCalculatedAt, int $recalcRequired = 0): void
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert(TableRegistry::scores(), [
            'page_id'            => $pageId,
            'category_id'        => $categoryId,
            'page_owner_id'      => 970690,
            'total_score'        => 50,
            'last_calculated_at'   => $lastCalculatedAt,
            'recalculate_required' => $recalcRequired,
        ]), (string) $wpdb->last_error);
    }

    // ── F-86 ────────────────────────────────────────────────────────────

    // Each test's dates are far older than anything another suite writes,
    // so its rows are the oldest in the shared table and the LIMIT is ours
    // alone. Each fixture is built so that every wrong ordering gives a
    // DIFFERENT answer (an unordered query can come back in index order by
    // luck, so "the answer happens to be sorted" is not enough).

    public function testTheSweepTakesTheOldestPagesFirst(): void
    {
        // The two oldest pages carry recalculate_required = 1, so an
        // idx_recalculate (recalculate_required, last_calculated_at) walk
        // reaches them LAST, and the lowest key is the newest row.
        $this->seedRow(970601, 0, '2001-01-01 05:00:00');
        $this->seedRow(970602, 0, '2001-01-01 01:00:00', 1);
        $this->seedRow(970603, 0, '2001-01-01 02:00:00', 1);
        $this->seedRow(970604, 0, '2001-01-01 04:00:00');

        self::assertSame([970602, 970603], (new ScoreRepository())->getStalePageIds(2));
    }

    public function testAMultiCategoryPageTakesOneSlot(): void
    {
        // Page 970605's two category rows are the two oldest rows.
        $this->seedRow(970605, 0, '2001-01-01 01:00:00');
        $this->seedRow(970605, 7, '2001-01-01 02:00:00');
        $this->seedRow(970606, 0, '2001-01-01 03:00:00');
        $this->seedRow(970607, 0, '2001-01-01 04:00:00');

        self::assertSame([970605, 970606, 970607], (new ScoreRepository())->getStalePageIds(3));
    }

    public function testAPageIsRankedByItsOldestCategoryRow(): void
    {
        // 970608 has one very old row and one newer than 970609's only row.
        $this->seedRow(970608, 0, '2001-01-01 01:00:00');
        $this->seedRow(970608, 7, '2001-01-01 09:00:00');
        $this->seedRow(970609, 0, '2001-01-01 05:00:00');

        self::assertSame([970608], (new ScoreRepository())->getStalePageIds(1));
    }

    public function testAFreshlyCalculatedPageIsNotStale(): void
    {
        $this->seedRow(970605, 0, '2001-01-01 00:00:00');
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . TableRegistry::scores() . '` SET last_calculated_at = NOW() WHERE page_id = %d',
            970605
        ));

        self::assertNotContains(970605, (new ScoreRepository())->getStalePageIds(1000));
    }

    // ── F-87 ────────────────────────────────────────────────────────────

    public function testTheAttestationBonusWriterInvalidatesTheCachedScore(): void
    {
        $this->seedRow(970606, 0, gmdate('Y-m-d H:i:s'));
        $repo = new ScoreRepository();

        $cached = $repo->getByPageId(970606, 0);
        self::assertNotNull($cached);
        self::assertEqualsWithDelta(0.0, $cached->getAttestationBonus(), 0.001, 'precondition');

        $repo->applyAttestationBonus(970606, 3.5);

        $fresh = $repo->getByPageId(970606, 0);
        self::assertNotNull($fresh);
        self::assertEqualsWithDelta(3.5, $fresh->getAttestationBonus(), 0.001, 'served the stale cached score');
    }

    public function testTheEndorsementCountWriterInvalidatesTheCachedScore(): void
    {
        $this->seedRow(970607, 0, gmdate('Y-m-d H:i:s'));
        $repo = new ScoreRepository();

        $cached = $repo->getByPageId(970607, 0);
        self::assertNotNull($cached);
        self::assertSame(0, $cached->getEndorsementCount(), 'precondition');

        $repo->updateEndorsementCount(970607, 4);

        $fresh = $repo->getByPageId(970607, 0);
        self::assertNotNull($fresh);
        self::assertSame(4, $fresh->getEndorsementCount(), 'served the stale cached score');
    }
}
