<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\AttestorReliabilityCacheRepository;
use BCC\Trust\Core\Repositories\BlogChainTagRepository;
use BCC\Trust\Core\Repositories\PageReadModelRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Rank\Repositories\BallotRepository;
use BCC\Trust\Rank\Repositories\PollRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * F-101: a FAILED read is never cached as "absent".
 *
 * get_row()/get_var() return null on a SQL error exactly as for "no row",
 * and get_results() returns [] — so these repositories used to cache their
 * "not found" / empty / zero answer for the whole TTL after one DB blip: a
 * poll 404'd, a voter's ballot vanished, a page lost its score, a recalc
 * zeroed a page's bonuses.
 *
 * Each case uses a REAL SQL error (the table is renamed away), reads, puts
 * the table back, and reads AGAIN: the second read must see the row. If the
 * failure had been cached it would still answer "absent".
 */
#[Group('integration')]
#[CoversNothing]
final class FailedReadNotCachedIntegrationTest extends TestCase
{
    private const PAGE  = 980001;
    private const OWNER = 980002;
    private const VOTER = 980003;
    private const POST  = 980004;

    /** @var list<string> tables renamed away by the current test */
    private array $broken = [];

    /**
     * The harness bootstrap does not install these; use the plugin's REAL
     * installers (same pattern as AdminDashboardReadFailureIntegrationTest).
     */
    private function ensureTables(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $db   = dirname(__DIR__, 2) . '/includes/database/';
        // The polls/ballots installers read DB_NAME (wp-config defines it in
        // WordPress; the harness does not).
        if (!defined('DB_NAME')) {
            define('DB_NAME', (string) $wpdb->get_var('SELECT DATABASE()'));
        }
        foreach ([
            [TableRegistry::pageReadModel(), 'schema-project.php', 'bcc_trust_create_page_tables'],
            [TableRegistry::attestorReliabilityCache(), 'schema-attestor-reliability-cache.php', 'bcc_trust_create_attestor_reliability_cache_table'],
            [TableRegistry::polls(), 'schema-polls.php', 'bcc_trust_create_polls_table'],
            [TableRegistry::ballots(), 'schema-ballots.php', 'bcc_trust_create_ballots_table'],
            [$this->blogTagTable(), 'schema-blog-chain-tags.php', 'bcc_blog_create_chain_tags_table'],
        ] as [$table, $file, $installer]) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                require_once $db . $file;
                $installer();
            }
            self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)), "{$table} must be installed");
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['__bcc_test_object_cache'] = [];
        $this->ensureTables();
        $wpdb = $GLOBALS['wpdb'];
        foreach ([TableRegistry::scores(), TableRegistry::votes(), TableRegistry::pageReadModel(),
                  TableRegistry::attestorReliabilityCache(), TableRegistry::polls(), TableRegistry::ballots(),
                  $this->blogTagTable()] as $t) {
            $wpdb->query("DELETE FROM `{$t}`");
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->broken as $t) {
            $this->restore($t);
        }
        $this->broken = [];
        $GLOBALS['__bcc_test_object_cache'] = [];
    }

    private function blogTagTable(): string
    {
        $m = new \ReflectionMethod(BlogChainTagRepository::class, 'table');
        return (string) $m->invoke(null);
    }

    private function break(string $table): void
    {
        $GLOBALS['wpdb']->query("RENAME TABLE `{$table}` TO `{$table}_away`");
        $this->broken[] = $table;
    }

    private function restore(string $table): void
    {
        $wpdb   = $GLOBALS['wpdb'];
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s',
            $table . '_away'
        ));
        if ($exists === 1) {
            $wpdb->query("RENAME TABLE `{$table}_away` TO `{$table}`");
        }
        $wpdb->last_error = '';
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): int
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert($table, $row), $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    private function seedScore(): void
    {
        $this->insert(TableRegistry::scores(), [
            'page_id' => self::PAGE, 'category_id' => 0, 'page_owner_id' => self::OWNER,
        ]);
    }

    private function seedVote(): int
    {
        return $this->insert(TableRegistry::votes(), [
            'voter_user_id' => self::VOTER, 'page_id' => self::PAGE, 'category_id' => 0,
            'vote_type' => 1, 'weight' => 1.0, 'vested_weight' => 1.0, 'status' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    // ── Core: scores ─────────────────────────────────────────────────────

    public function testAFailedScoreReadIsNotCachedAndTheWritePathVariantThrows(): void
    {
        $this->seedScore();
        $repo = new ScoreRepository();

        $this->break(TableRegistry::scores());
        self::assertNull($repo->getByPageId(self::PAGE), 'display read fails open');
        try {
            $repo->getByPageIdOrFail(self::PAGE);
            self::fail('the write-path read must throw, not read as "no score row"');
        } catch (RepositoryReadFailure) {
        }
        $this->restore(TableRegistry::scores());

        self::assertNotNull($repo->getByPageId(self::PAGE), 'the failure was not cached');
    }

    public function testAFailedTierDistributionReadIsNotCached(): void
    {
        $repo = new ScoreRepository();

        $this->break(TableRegistry::pageReadModel());
        self::assertSame(0, $repo->getTierDistribution()['trusted']);
        $this->restore(TableRegistry::pageReadModel());

        $this->insert(TableRegistry::pageReadModel(), ['page_id' => self::PAGE, 'reputation_tier' => 'trusted']);
        self::assertSame(1, $repo->getTierDistribution()['trusted'], 'the zero tiers were not cached');
    }

    // ── Core: votes ──────────────────────────────────────────────────────

    public function testAFailedVoteReadIsNotCached(): void
    {
        $voteId = $this->seedVote();
        $repo   = new VoteRepository();

        $this->break(TableRegistry::votes());
        self::assertNull($repo->getById($voteId));
        $this->restore(TableRegistry::votes());

        self::assertNotNull($repo->getById($voteId));
    }

    public function testAFailedPageStatsReadIsNotCachedAndTheWritePathVariantThrows(): void
    {
        $this->seedVote();
        $repo = new VoteRepository();

        $this->break(TableRegistry::votes());
        self::assertSame(0, (int) $repo->getPageStats(self::PAGE)->total_votes, 'display read fails open');
        try {
            $repo->getPageStatsOrFail(self::PAGE);
            self::fail('RepairService must not rewrite a score from a failed read');
        } catch (RepositoryReadFailure) {
        }
        $this->restore(TableRegistry::votes());

        self::assertSame(1, (int) $repo->getPageStats(self::PAGE)->total_votes);
    }

    // ── Core: page read model ────────────────────────────────────────────

    public function testFailedReadModelReadsAreNotCached(): void
    {
        $this->insert(TableRegistry::pageReadModel(), ['page_id' => self::PAGE]);
        $repo = new PageReadModelRepository();

        $this->break(TableRegistry::pageReadModel());
        self::assertNull($repo->getByPageId(self::PAGE));
        self::assertSame([], $repo->getByPageIds([self::PAGE]));
        self::assertFalse($repo->hasData());
        $this->restore(TableRegistry::pageReadModel());

        // Re-read BEFORE anything clears the object cache: a cached failure
        // must still be there to be caught.
        self::assertTrue($repo->hasData(), 'hasData failure not cached');
        self::assertNotNull($repo->getByPageId(self::PAGE), 'getByPageId failure not cached');
        self::assertArrayHasKey(self::PAGE, $repo->getByPageIds([self::PAGE]));
    }

    public function testAFailedBatchReadModelReadDoesNotNegativeCacheThePages(): void
    {
        $this->insert(TableRegistry::pageReadModel(), ['page_id' => self::PAGE]);
        $repo = new PageReadModelRepository();

        $this->break(TableRegistry::pageReadModel());
        $repo->getByPageIds([self::PAGE]);
        $this->restore(TableRegistry::pageReadModel());

        self::assertNotNull($repo->getByPageId(self::PAGE), 'the batch failure must not prime "not projected"');
    }

    // ── Core: attestor reliability, blog chain tags ──────────────────────

    public function testAFailedReliabilityReadIsNotCached(): void
    {
        $this->insert(TableRegistry::attestorReliabilityCache(), [
            'user_id' => self::VOTER, 'computed_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $repo = new AttestorReliabilityCacheRepository();

        $this->break(TableRegistry::attestorReliabilityCache());
        self::assertNull($repo->getByUserId(self::VOTER));
        $this->restore(TableRegistry::attestorReliabilityCache());

        self::assertNotNull($repo->getByUserId(self::VOTER));
    }

    public function testAFailedChainTagReadIsNotCached(): void
    {
        $this->insert($this->blogTagTable(), ['post_id' => self::POST, 'chain_id' => 7, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $repo = new BlogChainTagRepository();

        $this->break($this->blogTagTable());
        self::assertSame([], $repo->findByPostId(self::POST));
        $this->restore($this->blogTagTable());

        self::assertSame([7], $repo->findByPostId(self::POST));
    }

    // ── Rank: polls and ballots ──────────────────────────────────────────

    private function seedPoll(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        return $this->insert(TableRegistry::polls(), [
            'poll_type' => 'test', 'subject_type' => 'page', 'subject_id' => self::PAGE,
            'opened_at' => $now, 'binding_earliest_at' => $now, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
    }

    public function testAFailedPollReadIsNotCached(): void
    {
        $pollId = $this->seedPoll();
        $repo   = new PollRepository();

        $this->break(TableRegistry::polls());
        self::assertNull($repo->getById($pollId));
        $this->restore(TableRegistry::polls());

        self::assertNotNull($repo->getById($pollId), 'the poll must not 404 for the cache TTL');
    }

    public function testAFailedActiveBallotReadIsNotCached(): void
    {
        $pollId = $this->seedPoll();
        $now    = gmdate('Y-m-d H:i:s');
        $this->insert(TableRegistry::ballots(), [
            'poll_id' => $pollId, 'voter_user_id' => self::VOTER, 'choice' => 'yes', 'status' => 'active',
            'maturity' => 1, 'rank_multiplier' => 1, 'trust_multiplier' => 1, 'trust_score' => 50,
            'fraud_discount' => 1, 'effective_weight' => 1, 'cast_at' => $now, 'last_action_at' => $now,
        ]);
        $repo = new BallotRepository();

        $this->break(TableRegistry::ballots());
        self::assertNull($repo->getActiveForVoter($pollId, self::VOTER));
        $this->restore(TableRegistry::ballots());

        self::assertNotNull($repo->getActiveForVoter($pollId, self::VOTER), 'the ballot must not vanish for the cache TTL');
    }
}
