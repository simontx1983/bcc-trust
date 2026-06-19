<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Security\TransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vote upsert against a real MySQL. The repository takes a row lock
 * (SELECT … FOR UPDATE) inside a transaction and relies on a UNIQUE key
 * (voter_user_id, page_id, category_id) to guarantee one row per voter/page —
 * the defence against a double-insert race. This proves: (1) a repeat vote by
 * the same voter UPDATES in place (one row, not a duplicate), (2) distinct
 * voters get distinct rows, and (3) the method refuses to run outside a
 * transaction (where its FOR UPDATE lock would be a silent no-op).
 */
#[Group('integration')]
#[CoversClass(VoteRepository::class)]
final class VoteUpsertIntegrationTest extends TestCase
{
    private function table(): string
    {
        return $GLOBALS['wpdb']->prefix . 'bcc_trust_votes';
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `' . $this->table() . '`');
    }

    private function rowCount(string $where = '1'): int
    {
        return (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM `' . $this->table() . "` WHERE {$where}");
    }

    private function castVote(int $voter, int $page, int $type): void
    {
        $repo = new VoteRepository();
        TransactionManager::run(static function () use ($repo, $voter, $page, $type): void {
            $repo->upsertRaw([
                'voter_user_id' => $voter,
                'page_id'       => $page,
                'vote_type'     => $type,
            ]);
        });
    }

    public function testRepeatVoteUpdatesInPlaceNotDuplicated(): void
    {
        $this->castVote(1, 100, 1);  // upvote
        self::assertSame(1, $this->rowCount(), 'one vote row after first cast');

        $this->castVote(1, 100, -1); // same voter+page flips to downvote
        self::assertSame(1, $this->rowCount('voter_user_id = 1 AND page_id = 100'), 'UNIQUE upsert — still one row');

        $type = (int) $GLOBALS['wpdb']->get_var(
            'SELECT vote_type FROM `' . $this->table() . '` WHERE voter_user_id = 1 AND page_id = 100'
        );
        self::assertSame(-1, $type, 'vote_type updated in place');
    }

    public function testDistinctVotersGetDistinctRows(): void
    {
        $this->castVote(1, 100, 1);
        $this->castVote(2, 100, 1);
        self::assertSame(2, $this->rowCount('page_id = 100'), 'two voters → two rows');
    }

    public function testUpsertRefusesOutsideTransaction(): void
    {
        // The FOR UPDATE lock is meaningless under autocommit; the repo guards it.
        $this->expectException(\LogicException::class);
        (new VoteRepository())->upsertRaw(['voter_user_id' => 9, 'page_id' => 1, 'vote_type' => 1]);
    }
}
