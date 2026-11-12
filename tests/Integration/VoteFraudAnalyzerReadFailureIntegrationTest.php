<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\Vote\VoteFraudAnalyzer;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-107 (review M1): a FAILED vote read must throw into the retry path.
 *
 * VoteFraudAnalyzer::run() began with getById(), which since F-101 answers
 * null on a failed read — and null means "vote gone", so run() returned as
 * if it had succeeded and the post-vote retry never fired for the DB-blip
 * case it exists for. run() now uses getByIdOrFail(). Real SQL error: the
 * votes table is renamed away.
 */
#[Group('integration')]
#[CoversClass(VoteFraudAnalyzer::class)]
#[CoversClass(VoteRepository::class)]
final class VoteFraudAnalyzerReadFailureIntegrationTest extends TestCase
{
    private bool $broken = false;

    protected function setUp(): void
    {
        $GLOBALS['__bcc_test_object_cache'] = [];
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `' . TableRegistry::votes() . '`');
    }

    protected function tearDown(): void
    {
        $this->restore();
        $GLOBALS['__bcc_test_object_cache'] = [];
    }

    private function breakVotes(): void
    {
        $t = TableRegistry::votes();
        $GLOBALS['wpdb']->query("RENAME TABLE `{$t}` TO `{$t}_away`");
        $this->broken = true;
    }

    private function restore(): void
    {
        if (!$this->broken) {
            return;
        }
        $t = TableRegistry::votes();
        $GLOBALS['wpdb']->query("RENAME TABLE `{$t}_away` TO `{$t}`");
        $GLOBALS['wpdb']->last_error = '';
        $this->broken = false;
    }

    private function seedVote(): int
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert(TableRegistry::votes(), [
            'voter_user_id' => 970301, 'page_id' => 970302, 'category_id' => 0, 'vote_type' => 1,
            'weight' => 1.0, 'vested_weight' => 1.0, 'status' => 1, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    public function testGetByIdOrFailThrowsOnAFailedReadAndCachesNothing(): void
    {
        $id   = $this->seedVote();
        $repo = new VoteRepository();

        $this->breakVotes();
        try {
            $repo->getByIdOrFail($id);
            self::fail('a failed read must throw, not read as "no such vote"');
        } catch (RepositoryReadFailure) {
        }
        self::assertNull($repo->getById($id), 'the fail-open variant still answers null');
        $this->restore();

        self::assertNotNull($repo->getByIdOrFail($id), 'nothing was cached by the failure');
    }

    public function testTheAnalyzerThrowsIntoTheRetryPathInsteadOfReturning(): void
    {
        $id = $this->seedVote();

        $this->breakVotes();
        $this->expectException(RepositoryReadFailure::class);
        (new VoteFraudAnalyzer(new VoteRepository()))->run($id);
    }

    public function testAGenuinelyMissingVoteIsStillANoOp(): void
    {
        // The read RAN and found nothing (retracted/deleted vote): no throw.
        (new VoteFraudAnalyzer(new VoteRepository()))->run(123456789);
        $this->addToAssertionCount(1);
    }
}
