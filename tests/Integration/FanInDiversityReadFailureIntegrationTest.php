<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-205: VoteRepository::countOtherPagesVotedByUsers() against the REAL
 * votes table.
 *
 * It was a raw get_var(), so a failed read answered 0, which in
 * FraudDetector::detectFanInCoordination() is "fewer than 3 other pages":
 * the rule fired and wrote a false observation. The read now throws, and
 * the detector catches that and skips (unit-tested in FanInIsObservationalTest).
 *
 * ⚠ Faults come from failQueriesMatching, never a renamed table.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(VoteRepository::class)]
final class FanInDiversityReadFailureIntegrationTest extends TestCase
{
    private const VOTER_A = 970601;
    private const VOTER_B = 970602;
    private const PAGE    = 970700;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
        parent::tearDown();
    }

    private function purge(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'DELETE FROM `' . TableRegistry::votes() . '` WHERE voter_user_id IN (%d, %d)',
            self::VOTER_A,
            self::VOTER_B
        ));
    }

    private function vote(int $voter, int $page, int $status = 1): void
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert(TableRegistry::votes(), [
            'voter_user_id' => $voter,
            'page_id'       => $page,
            'vote_type'     => 1,
            'weight'        => 1.0,
            'status'        => $status,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]), (string) $wpdb->last_error);
    }

    public function testAHealthyReadCountsDistinctOtherActivePages(): void
    {
        $this->vote(self::VOTER_A, self::PAGE);        // the excluded page
        $this->vote(self::VOTER_A, self::PAGE + 1);
        $this->vote(self::VOTER_B, self::PAGE + 1);    // same page, counted once
        $this->vote(self::VOTER_B, self::PAGE + 2);
        $this->vote(self::VOTER_B, self::PAGE + 3, 0); // inactive, not counted

        $n = (new VoteRepository())->countOtherPagesVotedByUsers([self::VOTER_A, self::VOTER_B], self::PAGE);

        self::assertSame(2, $n);
    }

    /** A genuine zero is still 0, not a failure. */
    public function testAHealthyEmptyResultIsZero(): void
    {
        $this->vote(self::VOTER_A, self::PAGE);

        self::assertSame(0, (new VoteRepository())->countOtherPagesVotedByUsers([self::VOTER_A], self::PAGE));
    }

    public function testAFailedReadThrowsInsteadOfAnsweringZero(): void
    {
        $this->vote(self::VOTER_A, self::PAGE + 1);
        $GLOBALS['wpdb']->failQueriesMatching = '/' . preg_quote('COUNT(DISTINCT page_id) AS n', '/') . '/';

        try {
            (new VoteRepository())->countOtherPagesVotedByUsers([self::VOTER_A], self::PAGE);
            self::fail('a failed read must throw, not answer 0 ("< 3 other pages")');
        } catch (RepositoryReadFailure) {
        }

        self::assertSame(1, $GLOBALS['wpdb']->injectedFailures, 'exactly this read must have failed');
    }
}
