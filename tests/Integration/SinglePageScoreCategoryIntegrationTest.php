<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\VoteService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-84 (user decision): a page has ONE trust score; a vote counts once,
 * however many PeepSo categories the page carries.
 *
 *  - Votes are written once, at category_id = 0. VoteService used to write
 *    one votes row PER category, so every page-level read counted a vote
 *    once per category (the authoritative recalc doubled on a two-category
 *    page). Pinned via the resolver the cast path uses.
 *  - The three remaining scores joins in VoteRepository that resolve a page
 *    owner (getVotedPagesWithOwners, countMutualVotePages,
 *    countReciprocalVotes) read only the canonical category_id = 0 row; the
 *    scores PK is (page_id, category_id), so a page with category rows used
 *    to multiply them.
 *
 * Real MySQL for the joins. Category rows are put on BOTH pages of a
 * reciprocal pair so both owner joins (s1, s2) are exercised.
 */
#[Group('integration')]
#[CoversClass(VoteRepository::class)]
#[CoversClass(VoteService::class)]
final class SinglePageScoreCategoryIntegrationTest extends TestCase
{
    private const A      = 995001;
    private const B      = 995002;
    private const PAGE_A = 995501;
    private const PAGE_B = 995502;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_votes`');
        $wpdb->query('DELETE FROM `' . TableRegistry::scores() . '` WHERE page_id IN (' . self::PAGE_A . ',' . self::PAGE_B . ')');

        foreach ([[self::PAGE_A, self::A], [self::PAGE_B, self::B]] as [$page, $owner]) {
            foreach ([0, 5, 9] as $category) {
                self::assertNotFalse($wpdb->insert(TableRegistry::scores(), [
                    'page_id'       => $page,
                    'category_id'   => $category,
                    'page_owner_id' => $owner,
                ]), $wpdb->last_error);
            }
        }
        $this->vote(self::A, self::PAGE_B);
        $this->vote(self::B, self::PAGE_A);
    }

    private function vote(int $voter, int $pageId): void
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_votes', [
            'voter_user_id' => $voter,
            'page_id'       => $pageId,
            'category_id'   => 0,
            'vote_type'     => 1,
            'weight'        => 1.0,
            'vested_weight' => 1.0,
            'status'        => 1,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
    }

    public function testAVoteIsWrittenUnderTheSingleCanonicalRowWhateverTheCategories(): void
    {
        $svc = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        $m   = new \ReflectionMethod(VoteService::class, 'resolveVoteCategoryIds');
        $m->setAccessible(true);

        self::assertSame([0], $m->invoke($svc, self::PAGE_A), 'one row per (voter, page), at category 0');
    }

    public function testVotedPagesWithOwnersListsEachPageOnce(): void
    {
        $rows = (new VoteRepository())->getVotedPagesWithOwners(self::A);

        self::assertCount(1, $rows, 'not once per category row');
        self::assertSame(self::B, (int) $rows[0]->page_owner_id);
    }

    public function testReciprocalVotesAreCountedOnce(): void
    {
        $repo = new VoteRepository();

        self::assertSame(1, $repo->countReciprocalVotes(self::A), 'one reciprocal vote, not 9 (3 × 3 category rows)');
        self::assertSame(1, $repo->countMutualVotePages(self::A));
    }
}
