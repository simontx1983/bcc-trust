<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Security\FraudDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-102: rapid-vote detection must count real votes.
 *
 * FraudDetector::detectRapidVoting() counted audit-log rows with action
 * 'vote_up' / 'vote_down' — actions nothing writes — so it was ALWAYS 0:
 * the VoteService rapid-voting gate never fired and the +30 `rapid_voting`
 * fraud signal never scored. It now counts the votes table itself.
 *
 * Real MySQL. Fixtures are written in the same site-local frame as
 * production (`current_time('mysql')`), and the boundary cases are exact:
 * LIMIT is "more than", so LIMIT votes pass and LIMIT + 1 trips.
 */
#[Group('integration')]
#[CoversClass(FraudDetector::class)]
#[CoversClass(VoteRepository::class)]
final class RapidVotingDetectionIntegrationTest extends TestCase
{
    private const VOTER = 9901;
    private const OTHER = 9902;

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `' . $GLOBALS['wpdb']->prefix . 'bcc_trust_votes`');
    }

    /** Cast $n votes by $voter, $minutesAgo minutes ago, on distinct pages. */
    private function cast(int $voter, int $n, int $minutesAgo, int $status = 1, int $pageBase = 991000): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $at   = gmdate('Y-m-d H:i:s', (int) current_time('timestamp') - $minutesAgo * 60);
        for ($i = 0; $i < $n; $i++) {
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_votes', [
                'voter_user_id' => $voter,
                'page_id'       => $pageBase + $i,
                'vote_type'     => 1,
                'weight'        => 1.0,
                'vested_weight' => 1.0,
                'status'        => $status,
                'created_at'    => $at,
            ]), $wpdb->last_error);
        }
    }

    public function testMoreThanTheLimitWithinTheWindowIsRapid(): void
    {
        $this->cast(self::VOTER, BCC_TRUST_RAPID_VOTE_LIMIT + 1, 0);

        self::assertTrue(FraudDetector::detectRapidVoting(self::VOTER));
    }

    public function testExactlyTheLimitIsNotRapid(): void
    {
        $this->cast(self::VOTER, BCC_TRUST_RAPID_VOTE_LIMIT, 0);

        self::assertFalse(FraudDetector::detectRapidVoting(self::VOTER));
    }

    public function testVotesOutsideTheWindowDoNotCount(): void
    {
        $this->cast(self::VOTER, BCC_TRUST_RAPID_VOTE_LIMIT + 5, BCC_TRUST_RAPID_VOTE_WINDOW + 1);

        self::assertFalse(FraudDetector::detectRapidVoting(self::VOTER));
    }

    public function testARetractedBurstStillCounts(): void
    {
        // Cast-and-retract is still casting: the detector measures the rate.
        $this->cast(self::VOTER, BCC_TRUST_RAPID_VOTE_LIMIT, 0, 1);
        $this->cast(self::VOTER, 1, 0, 0, 995000);

        self::assertTrue(FraudDetector::detectRapidVoting(self::VOTER));
    }

    public function testOtherVotersDoNotCount(): void
    {
        $this->cast(self::OTHER, BCC_TRUST_RAPID_VOTE_LIMIT + 5, 0);
        $this->cast(self::VOTER, 1, 0, 1, 996000);

        self::assertFalse(FraudDetector::detectRapidVoting(self::VOTER));
    }

    public function testTheLivingHeaderCountIsStillActiveOnly(): void
    {
        // countByVoterSince's default semantics are unchanged for its other callers.
        $this->cast(self::VOTER, 2, 0, 1);
        $this->cast(self::VOTER, 3, 0, 0, 997000);
        $since = gmdate('Y-m-d H:i:s', (int) current_time('timestamp') - 3600);

        $repo = new VoteRepository();
        self::assertSame(2, $repo->countByVoterSince(self::VOTER, $since));
        self::assertSame(5, $repo->countByVoterSince(self::VOTER, $since, false));
    }
}
