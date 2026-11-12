<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\BehavioralAnalyzer;
use BCC\Trust\Core\Security\FraudDetector;
use BCC\Trust\Core\Security\TrustGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A FAILED read inside fraud analysis must never be WRITTEN as a fact.
 *
 * Two places did exactly that, both because a failed read looks like
 * "no data" (real wpdb: get_results() → [], get_row()/get_var() → null):
 *
 *  - TrustGraph::calculateTrustRank(): a failed user_info read reached the
 *    "no stored rank yet" branch and wrote the 0.5 default over the
 *    member's REAL trust_rank (and cached it for an hour).
 *  - BehavioralAnalyzer::analyzeUserBehavior(): every sub-analysis reads an
 *    empty result as "too little data, not suspicious", so a failed read
 *    scored 0 — which was then PERSISTED over the stored behavior_score
 *    and cached (1h, stale copy 2h).
 *
 * Both now throw before any write or cache, into the callers' retry.
 *
 * ⚠ Faults are injected with the harness's failQueriesMatching, NOT by
 * renaming a table: a renamed table is re-created EMPTY by the next schema
 * pass, after which the read succeeds and the test silently stops testing.
 * Every failure case also asserts the fault actually fired.
 */
#[Group('integration')]
#[CoversClass(TrustGraph::class)]
#[CoversClass(BehavioralAnalyzer::class)]
final class FraudFailedReadNoCorruptWriteIntegrationTest extends TestCase
{
    private const UID          = 970401;
    private const STORED_RANK  = 0.15;
    private const STORED_SCORE = 55;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        $GLOBALS['__bcc_test_object_cache'] = [];

        $wpdb->query($wpdb->prepare('DELETE FROM `' . TableRegistry::userInfo() . '` WHERE user_id = %d', self::UID));
        $wpdb->query($wpdb->prepare('DELETE FROM `' . TableRegistry::votes() . '` WHERE voter_user_id = %d', self::UID));
        $wpdb->query('DELETE FROM `' . TableRegistry::scores() . '` WHERE page_id BETWEEN 970410 AND 970419');

        self::assertNotFalse($wpdb->insert(TableRegistry::userInfo(), [
            'user_id'        => self::UID,
            'user_login'     => 'fixture' . self::UID,
            'user_email'     => 'fixture' . self::UID . '@example.test',
            'display_name'   => 'Fixture ' . self::UID,
            'trust_rank'     => self::STORED_RANK,
            'behavior_score' => self::STORED_SCORE,
        ]), (string) $wpdb->last_error);

        // Five voted pages with score rows, so analyzeSocialPattern() gets
        // past its "< 5 pages" early return and reaches its last two reads.
        for ($i = 0; $i < 5; $i++) {
            $pageId = 970410 + $i;
            self::assertNotFalse($wpdb->insert(TableRegistry::scores(), [
                'page_id' => $pageId, 'category_id' => 0, 'page_owner_id' => 970490 + $i, 'total_score' => 50,
            ]), (string) $wpdb->last_error);
            self::assertNotFalse($wpdb->insert(TableRegistry::votes(), [
                'voter_user_id' => self::UID, 'page_id' => $pageId, 'category_id' => 0, 'vote_type' => 1,
                'weight' => 1.0, 'vested_weight' => 1.0, 'status' => 1, 'created_at' => gmdate('Y-m-d H:i:s'),
            ]), (string) $wpdb->last_error);
        }
    }

    protected function tearDown(): void
    {
        // A failed test must not leave a fault armed for the next one.
        $GLOBALS['wpdb']->clearFaultInjection();
        $GLOBALS['__bcc_test_object_cache'] = [];
    }

    private function failOnly(string $sqlFragment): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/' . preg_quote($sqlFragment, '/') . '/';
    }

    /** @return array{trust_rank: float, behavior_score: int} read straight from the table */
    private function storedRow(): array
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT trust_rank, behavior_score FROM `' . TableRegistry::userInfo() . '` WHERE user_id = %d',
            self::UID
        ));
        self::assertIsObject($row);

        return ['trust_rank' => (float) $row->trust_rank, 'behavior_score' => (int) $row->behavior_score];
    }

    private function userInfoSelect(): string
    {
        return 'FROM ' . TableRegistry::userInfo() . ' WHERE user_id';
    }

    // ── trust rank ──────────────────────────────────────────────────────

    public function testAFailedUserInfoReadDoesNotOverwriteTheTrustRank(): void
    {
        $graph = Plugin::instance()->trustGraph();
        $this->failOnly($this->userInfoSelect());

        try {
            $graph->calculateTrustRank(self::UID);
            self::fail('a failed user_info read must throw, not reach the "no stored rank" default');
        } catch (RepositoryException) {
        }

        self::assertGreaterThanOrEqual(1, $GLOBALS['wpdb']->injectedFailures, 'the fault must actually fire');
        self::assertEqualsWithDelta(self::STORED_RANK, $this->storedRow()['trust_rank'], 0.0001, 'the real rank was overwritten');
        self::assertFalse(wp_cache_get('trust_rank_' . self::UID, TrustGraph::CACHE_GROUP), 'nothing may be cached');

        // Recovered: the real stored rank is served.
        self::assertEqualsWithDelta(self::STORED_RANK, $graph->calculateTrustRank(self::UID), 0.0001);
    }

    // ── behaviour score ─────────────────────────────────────────────────

    /** @return array<string, array{string}> one fragment per read analyzeUserBehavior() makes */
    public static function behaviourReads(): array
    {
        return [
            'recent votes'        => ['SELECT vote_type, created_at, page_id, weight'],
            'activity breakdown'  => ['DAYOFWEEK(created_at) as day_of_week'],
            'recent actions'      => ['SELECT created_at, action'],
            'voted pages'         => ['SELECT DISTINCT v.page_id, s.page_owner_id'],
            'reciprocal votes'    => ['SELECT COUNT(*) AS n'],
            'voted page scores'   => ['SELECT total_score FROM'],
            'weekly activity'     => ['WEEK(created_at) as week'],
        ];
    }

    #[DataProvider('behaviourReads')]
    public function testAFailedBehaviourReadWritesAndCachesNothing(string $fragment): void
    {
        $analyzer = new BehavioralAnalyzer();
        $this->failOnly($fragment);

        try {
            $analyzer->analyzeUserBehavior(self::UID);
            self::fail('a failed read must throw, not score as "not suspicious"');
        } catch (\RuntimeException) {
        }

        self::assertSame(1, $GLOBALS['wpdb']->injectedFailures, 'exactly this read must have failed');
        self::assertSame(self::STORED_SCORE, $this->storedRow()['behavior_score'], 'the stored behaviour score was overwritten');
        self::assertFalse(wp_cache_get('behavior_' . self::UID, BehavioralAnalyzer::CACHE_GROUP), 'nothing may be cached');
        self::assertFalse(wp_cache_get('behavior_stale_' . self::UID, BehavioralAnalyzer::CACHE_GROUP), 'no stale copy either');
    }

    public function testTheThrottledFallbackDoesNotReadAFailedUserInfoReadAsZero(): void
    {
        // Another caller holds the throttle and there is no stale copy, so
        // the fallback reads the stored score (F-128).
        wp_cache_add('behavior_lock_' . self::UID, 1, BehavioralAnalyzer::CACHE_GROUP, 60);
        $this->failOnly($this->userInfoSelect());

        $this->expectException(RepositoryException::class);
        (new BehavioralAnalyzer())->analyzeUserBehavior(self::UID);
    }

    public function testWithNoFaultTheAnalysisStillPersistsAndCaches(): void
    {
        // Control: the fixture reaches a real, successful analysis.
        $result = (new BehavioralAnalyzer())->analyzeUserBehavior(self::UID);

        self::assertSame($result['behavior_score'], $this->storedRow()['behavior_score']);
        self::assertNotFalse(wp_cache_get('behavior_' . self::UID, BehavioralAnalyzer::CACHE_GROUP));
    }

    public function testAFailedBehaviourReadAbortsTheWholeFraudAnalysisUncached(): void
    {
        $this->failOnly('DAYOFWEEK(created_at) as day_of_week');

        try {
            FraudDetector::analyzeFraud(self::UID);
            self::fail('analyzeFraud must not complete on a failed behaviour read');
        } catch (\RuntimeException) {
        }

        self::assertFalse(wp_cache_get('fraud_analysis_' . self::UID, FraudDetector::CACHE_GROUP), 'the analysis must not be cached for the retry to reuse');
        self::assertSame(self::STORED_SCORE, $this->storedRow()['behavior_score']);
    }
}
