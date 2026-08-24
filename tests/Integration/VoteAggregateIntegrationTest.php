<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\VoteRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `VoteRepository::getVoteAggregatesForPage()` must actually run its query.
 *
 * ## The regression this pins
 *
 * The aggregate SQL carried a documentation comment reading `Floor (%f) = …`
 * INSIDE the string handed to `$wpdb->prepare()`. `prepare()` counts
 * placeholders textually and cannot tell documentation from a value position,
 * so it counted 14 against the 13 arguments actually supplied, reported
 * `_doing_it_wrong`, and — because the query uses unnumbered placeholders —
 * returned an empty string. `get_row('')` then ran nothing.
 *
 * That failure was invisible. The `if (!$result)` guard below the query exists
 * for "this page has no votes yet" and cheerfully absorbed "the query never
 * executed", handing back a zero row. Every trust-score recalculation therefore
 * scored every page as though it had no votes, while the only outward sign was
 * a PHP notice in the log.
 *
 * So a test that only asserted "returns an object with these keys" would still
 * pass against the bug. These assertions are deliberately shaped to fail:
 * non-zero counts, exact aggregate values, and an explicit check that
 * `prepare()` reported no fault.
 */
#[Group('integration')]
#[CoversClass(VoteRepository::class)]
final class VoteAggregateIntegrationTest extends TestCase
{
    private const PAGE_ID  = 900001;
    private const OTHER_ID = 900002;

    private function votesTable(): string
    {
        return $GLOBALS['wpdb']->prefix . 'bcc_trust_votes';
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `' . $this->votesTable() . '`');
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `wp_users`');
        $GLOBALS['wpdb']->resetDoingItWrong();
    }

    /**
     * Insert a vote directly. Deliberately bypasses the service layer: this
     * test is about the read path, and a fixture that depended on the write
     * path would fail for reasons unrelated to the aggregate.
     *
     * A matching wp_users row is created because the aggregate LEFT JOINs it
     * for the voter-maturity term; `$registeredDaysAgo` controls whether the
     * voter counts toward mature_unique_voters.
     */
    private function insertVote(
        int $voterId,
        int $pageId,
        int $voteType,
        float $weight,
        int $registeredDaysAgo = 365
    ): void {
        $GLOBALS['wpdb']->query(sprintf(
            "INSERT IGNORE INTO `wp_users` (ID, user_login, user_registered)
             VALUES (%d, 'voter%d', DATE_SUB(NOW(), INTERVAL %d DAY))",
            $voterId,
            $voterId,
            $registeredDaysAgo
        ));

        $ok = $GLOBALS['wpdb']->insert($this->votesTable(), [
            'voter_user_id' => $voterId,
            'page_id'       => $pageId,
            'vote_type'     => $voteType,
            'weight'        => $weight,
            'status'        => 1,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);

        self::assertNotFalse($ok, 'fixture insert failed: ' . $GLOBALS['wpdb']->last_error);
    }

    public function testAggregateQueryActuallyExecutesAndCountsRealVotes(): void
    {
        $this->insertVote(11, self::PAGE_ID, 1, 1.0);
        $this->insertVote(12, self::PAGE_ID, 1, 1.0);
        $this->insertVote(13, self::PAGE_ID, -1, 1.0);
        // A vote on a different page must not leak into this page's totals.
        $this->insertVote(14, self::OTHER_ID, 1, 1.0);

        $agg = (new VoteRepository())->getVoteAggregatesForPage(self::PAGE_ID);

        self::assertSame(
            [],
            $GLOBALS['wpdb']->doingItWrong,
            'wpdb::prepare() reported a placeholder/argument fault — the aggregate query did not run'
        );

        self::assertSame(3, (int) $agg->vote_count, 'three votes exist on this page');
        self::assertSame(3, (int) $agg->unique_voters, 'three distinct voters');
        self::assertGreaterThan(0.0, (float) $agg->positive_score, 'two upvotes must produce a positive score');
        self::assertGreaterThan(0.0, (float) $agg->negative_score, 'one downvote must produce a negative score');
        self::assertNotNull($agg->last_vote_at, 'last_vote_at is populated when votes exist');
    }

    /**
     * The zero row is a legitimate answer for a page with no votes. It must
     * stay reachable — otherwise a future fix could "pass" the test above by
     * removing the guard rather than by fixing the query.
     */
    public function testPageWithNoVotesStillReturnsZeroRowWithoutFault(): void
    {
        $agg = (new VoteRepository())->getVoteAggregatesForPage(self::PAGE_ID);

        self::assertSame([], $GLOBALS['wpdb']->doingItWrong, 'no prepare() fault on the empty path either');
        self::assertSame(0, (int) $agg->vote_count);
        self::assertSame(0, (int) $agg->unique_voters);
        self::assertNull($agg->last_vote_at);
    }

    /**
     * Distinguishes "query ran and found nothing" from "query never ran".
     *
     * Both produce vote_count 0, so the count alone cannot tell them apart.
     * A page carrying votes must not report zero — that is precisely the
     * production symptom.
     */
    public function testZeroAggregateIsNotReturnedWhenVotesExist(): void
    {
        $this->insertVote(21, self::PAGE_ID, 1, 2.5);

        $agg = (new VoteRepository())->getVoteAggregatesForPage(self::PAGE_ID);

        self::assertNotSame(
            0,
            (int) $agg->vote_count,
            'a page with a stored vote reported zero — the aggregate query silently did not execute'
        );
    }
}
