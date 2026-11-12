<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Application\ScoreContributorService;
use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Services\TrustScoreService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-240: ScoreContributorService::applyBonus() must keep its bool contract.
 *
 * ScoreContributorInterface promises `false on failure`, and both callers
 * (BonusService::applyBonus, BonusRetryService::processAll) queue or count a
 * retry on `false` and catch nothing. But every failure inside the bonus
 * transaction ended as a THROW: TransactionManager::run() turns a closure's
 * `false` into "Transaction callback returned false". So a failed bonus write
 * threw past the retry queue instead of being queued.
 *
 * Fault: the bonus column UPDATE fails (applyBonusColumn returns false).
 */
#[Group('integration')]
#[CoversClass(ScoreContributorService::class)]
final class ScoreContributorWriteFailureIntegrationTest extends TestCase
{
    private const OWNER_ID = 9851;

    /** The bonus column write in ScoreRepository::applyBonusColumn(). */
    private const BONUS_WRITE = '/^UPDATE\s+\S*bcc_trust_page_scores\s+SET onchain_bonus\s*=/';

    private int $pageId;

    protected function setUp(): void
    {
        $this->pageId = MemberSelfPageService::ID_BASE + self::OWNER_ID;

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        foreach (['bcc_trust_page_scores', 'bcc_trust_page_scores_velocity'] as $t) {
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . $t . '`');
        }
        $GLOBALS['__bcc_test_object_cache'] = [];
        Logger::reset();

        $ok = $wpdb->insert($wpdb->prefix . 'bcc_trust_page_scores', [
            'page_id'       => $this->pageId,
            'category_id'   => 0,
            'page_owner_id' => self::OWNER_ID,
            'total_score'   => round(TrustScoreService::compute(0.0, 0.0, 0.0, 0.0, 0.0, 0.0), 2),
        ]);
        self::assertNotFalse($ok, 'score fixture insert failed: ' . $wpdb->last_error);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
    }

    private function onchainBonus(): float
    {
        $wpdb = $GLOBALS['wpdb'];
        return (float) $wpdb->get_var($wpdb->prepare(
            'SELECT onchain_bonus FROM `' . $wpdb->prefix . 'bcc_trust_page_scores` WHERE page_id = %d AND category_id = 0',
            $this->pageId
        ));
    }

    private function budgetCharged(): float
    {
        $wpdb = $GLOBALS['wpdb'];
        return (float) $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(score_delta), 0) FROM `' . TableRegistry::scoreVelocity() . '` WHERE page_id = %d',
            $this->pageId
        ));
    }

    public function testAFailedBonusWriteReturnsFalseInsteadOfThrowing(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = self::BONUS_WRITE;
        try {
            $ok = (new ScoreContributorService(new ScoreRepository()))->applyBonus($this->pageId, 'onchain', 1.5);
        } finally {
            $hits = $wpdb->injectedFailures;
            $wpdb->clearFaultInjection();
        }

        self::assertSame(1, $hits, 'precondition: the bonus write was the query failed');
        self::assertFalse($ok, 'a failed write must report false (the callers queue a retry on false)');
        self::assertEqualsWithDelta(0.0, $this->onchainBonus(), 0.0001);
        self::assertEqualsWithDelta(0.0, $this->budgetCharged(), 0.0001, 'the velocity charge rolls back with the write');

        $errors = array_values(array_filter(
            Logger::$lines,
            static fn (array $l): bool => $l['level'] === 'error' && $l['message'] === 'score_contributor_write_failed'
        ));
        self::assertCount(1, $errors, 'the failure is logged once, at ERROR');
        self::assertSame($this->pageId, $errors[0]['context']['page_id']);
    }

    public function testControlAWorkingWriteStillReturnsTrue(): void
    {
        if (!function_exists('do_action')) {
            eval('function do_action(string $hook, ...$args): void {}');
        }

        $ok = (new ScoreContributorService(new ScoreRepository()))->applyBonus($this->pageId, 'onchain', 1.5);

        self::assertTrue($ok);
        self::assertEqualsWithDelta(1.5, $this->onchainBonus(), 0.0001);
    }
}
