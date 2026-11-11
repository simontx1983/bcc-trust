<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\VoteRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-108 (ring-scan half): the vote-ring detector must see every mutual-vote
 * pair exactly once.
 *
 * VoteRepository::getMutualVotePairs() is walked page by page by the
 * TrustGraph cron. It paged with LIMIT/OFFSET over a GROUP BY with NO ORDER
 * BY, so pairs were skipped or seen twice between pages. It now orders by
 * (user_a, user_b) and pages with a keyset cursor on that pair; and its
 * scores joins read the canonical category_id = 0 row, so a page carrying
 * category rows no longer multiplies mutual_count / total_weight.
 *
 * Real MySQL.
 */
#[Group('integration')]
#[CoversClass(VoteRepository::class)]
final class MutualVotePairsPagingIntegrationTest extends TestCase
{
    private const BASE      = 994000;
    private const PAGE_BASE = 994500;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_votes`');
        $wpdb->query('DELETE FROM `' . TableRegistry::scores() . '` WHERE page_id BETWEEN ' . self::PAGE_BASE . ' AND ' . (self::PAGE_BASE + 99));
    }

    private function page(int $pageId, int $owner, int $category = 0): void
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert(TableRegistry::scores(), [
            'page_id'       => $pageId,
            'category_id'   => $category,
            'page_owner_id' => $owner,
        ]), $wpdb->last_error);
    }

    private function vote(int $voter, int $pageId): void
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_votes', [
            'voter_user_id' => $voter,
            'page_id'       => $pageId,
            'vote_type'     => 1,
            'weight'        => 1.5,
            'vested_weight' => 1.5,
            'status'        => 1,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
    }

    /** Seven reciprocal pairs: each member votes the other's page. */
    private function seedRing(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $a = self::BASE + 2 * $i;
            $b = $a + 1;
            $this->page(self::PAGE_BASE + 2 * $i, $a);
            $this->page(self::PAGE_BASE + 2 * $i + 1, $b);
            $this->vote($a, self::PAGE_BASE + 2 * $i + 1);
            $this->vote($b, self::PAGE_BASE + 2 * $i);
        }
    }

    public function testAKeysetWalkSeesEveryPairExactlyOnceInOrder(): void
    {
        $this->seedRing();
        $repo = new VoteRepository();

        $seen    = [];
        [$a, $b] = [0, 0];
        for ($guard = 0; $guard < 20; $guard++) {
            $page = $repo->getMutualVotePairs(1, 3, $a, $b);
            foreach ($page as $row) {
                $seen[] = (int) $row->user_a . '>' . (int) $row->user_b;
            }
            if (count($page) < 3) {
                break;
            }
            $last    = end($page);
            [$a, $b] = [(int) $last->user_a, (int) $last->user_b];
        }

        $expected = [];
        for ($i = 0; $i < 7; $i++) {
            $x          = self::BASE + 2 * $i;
            $expected[] = $x . '>' . ($x + 1);
            $expected[] = ($x + 1) . '>' . $x;
        }
        sort($expected);
        $sorted = $seen;
        sort($sorted);

        self::assertSame($expected, $sorted, 'every ordered pair, none twice');
        self::assertSame($sorted, $seen, 'rows arrive ordered by (user_a, user_b)');
    }

    public function testACategoryRowDoesNotMultiplyTheMutualCount(): void
    {
        $a = self::BASE + 60;
        $b = self::BASE + 61;
        $this->page(self::PAGE_BASE + 60, $a);
        $this->page(self::PAGE_BASE + 61, $b);
        $this->page(self::PAGE_BASE + 60, $a, 5); // both pages carry a category row,
        $this->page(self::PAGE_BASE + 61, $b, 5); // so BOTH scores joins (s1, s2) are exercised
        $this->vote($a, self::PAGE_BASE + 61);
        $this->vote($b, self::PAGE_BASE + 60);

        $rows = (new VoteRepository())->getMutualVotePairs(1, 50);
        $ab   = array_values(array_filter($rows, static fn (object $r): bool => (int) $r->user_a === $a && (int) $r->user_b === $b));

        self::assertCount(1, $ab);
        self::assertSame(1, (int) $ab[0]->mutual_count, 'one reciprocal vote, not one per category row');
        self::assertEqualsWithDelta(3.0, (float) $ab[0]->total_weight, 0.0001);
    }
}
