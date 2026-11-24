<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\EdgeRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Services\Vote\ReviewVisibilityScoreSync;
use BCC\Trust\Core\Services\VoteService;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PeepSoActivitiesTable.php';

/**
 * Trust-graph vote edges (voter → page owner), against real MySQL.
 *
 * F-142: recalculateVoteEdge() and backfillFromSources() joined the scores
 * table on page_id only. The scores PK is (page_id, category_id), so a page
 * that carries category rows joined once PER ROW and the vote's weight and
 * count were multiplied. Both now join the canonical category_id = 0 row.
 *
 * Hidden reviews: a moderation-hidden review confers no trust (follow-up to
 * the "a hidden review stops counting" decision). Both edge reads apply the
 * same F-55 predicate as the page score, and ReviewVisibilityScoreSync
 * recalculates the edge when a review is hidden or restored.
 *
 * The target is a member self-page, so owner resolution needs no fixture.
 *
 * ── Why separate processes
 * moderation-integration-stubs.php declares a recording do_action() and the
 * hidden-activities table; isolation keeps both out of sibling suites.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(EdgeRepository::class)]
#[CoversClass(ReviewVisibilityScoreSync::class)]
final class TrustEdgeVoteIntegrityIntegrationTest extends TestCase
{
    private const OWNER     = 987100;
    private const VOTER     = 987001;
    private const ACT_ID    = 2600000700;
    private const POST_ID   = 2600000800;

    private int $page;
    private int $voteId = 0;
    private bool $activitiesWasOurs = false;

    private VoteRepository $votes;
    private EdgeRepository $edges;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/moderation-integration-stubs.php';
        $GLOBALS['__bcc_test_object_cache'] = [];

        global $wpdb;
        $acts = $wpdb->prefix . 'peepso_activities';
        // Creates the table, or widens a copy another suite built, to the full column set.
        if (PeepSoActivitiesTable::ensure()) {
            $this->activitiesWasOurs = true;
        }

        $this->page  = MemberSelfPageService::ID_BASE + self::OWNER;
        $this->votes = new VoteRepository();
        $this->edges = new EdgeRepository($this->votes);

        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_edges`');
        $wpdb->delete($wpdb->prefix . 'bcc_trust_page_scores', ['page_id' => $this->page]);
        // The canonical row AND a category row for the same page (F-142).
        foreach ([0, 5] as $category) {
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_page_scores', [
                'page_id'       => $this->page,
                'category_id'   => $category,
                'page_owner_id' => self::OWNER,
            ]), $wpdb->last_error);
        }

        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_votes', [
            'voter_user_id' => self::VOTER,
            'page_id'       => $this->page,
            'vote_type'     => 1,
            'weight'        => 2.5,
            'vested_weight' => 2.5,
            'explanation'   => 'review',
            'status'        => 1,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
        $this->voteId = (int) $wpdb->insert_id;

        // The review's feed activity + backing post (as handleReviewPublished writes).
        foreach ([[$wpdb->posts, 'ID', self::POST_ID], [$acts, 'act_id', self::ACT_ID]] as [$t, $c, $id]) {
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE {$c} = %d", $id)) > 0) {
                self::markTestSkipped("{$t}.{$c}={$id} already exists; refusing to overwrite a real row");
            }
        }
        self::assertNotFalse($wpdb->insert($wpdb->posts, ['ID' => self::POST_ID, 'post_type' => 'peepso-post', 'post_status' => 'publish']));
        self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => self::POST_ID, 'meta_key' => '_bcc_activity_module', 'meta_value' => 'review']));
        self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => self::POST_ID, 'meta_key' => '_bcc_activity_sidecar_id', 'meta_value' => (string) $this->voteId]));
        self::assertNotFalse($wpdb->insert($acts, ['act_id' => self::ACT_ID, 'act_module_id' => '202', 'act_external_id' => self::POST_ID, 'act_owner_id' => self::VOTER]));
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'bcc_trust_votes', ['id' => $this->voteId]);
        $wpdb->delete($wpdb->prefix . 'bcc_hidden_activities', ['act_id' => self::ACT_ID]);
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", self::POST_ID));
        $wpdb->delete($wpdb->posts, ['ID' => self::POST_ID]);
        $wpdb->delete($wpdb->prefix . 'bcc_trust_page_scores', ['page_id' => $this->page]);
        if ($this->activitiesWasOurs) {
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}peepso_activities`");
        } else {
            $wpdb->delete($wpdb->prefix . 'peepso_activities', ['act_id' => self::ACT_ID]);
        }
    }

    /** @return array{weight: float, vote_count: int}|null */
    private function edge(): ?array
    {
        $row = $GLOBALS['wpdb']->get_row($GLOBALS['wpdb']->prepare(
            'SELECT weight, vote_count FROM `' . $GLOBALS['wpdb']->prefix . "bcc_trust_edges`
              WHERE source_user_id = %d AND target_user_id = %d AND edge_type = 'vote'",
            self::VOTER,
            self::OWNER
        ));
        return is_object($row) ? ['weight' => (float) $row->weight, 'vote_count' => (int) $row->vote_count] : null;
    }

    public function testRecalculationCountsTheVoteOnceDespiteCategoryRows(): void
    {
        $this->edges->recalculateVoteEdge(self::VOTER, self::OWNER);

        self::assertSame(['weight' => 2.5, 'vote_count' => 1], $this->edge(), 'F-142: one vote, not one per category row');
    }

    public function testTheBackfillCountsTheVoteOnceDespiteCategoryRows(): void
    {
        $this->edges->backfillFromSources();

        self::assertSame(['weight' => 2.5, 'vote_count' => 1], $this->edge(), 'F-142: the backfill joins the canonical row only');
    }

    /** Owner-0: a released page's rows belong to nobody, and nobody is not a trust target. */
    public function testTheBackfillDrawsNoEdgeToAReleasedPage(): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'bcc_trust_page_scores', ['page_owner_id' => 0], ['page_id' => $this->page]);

        $this->edges->backfillFromSources();

        self::assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}bcc_trust_edges` WHERE source_user_id = %d",
            self::VOTER
        )));
    }

    /** Owner-0: BehavioralAnalyzer's single-owner focus counts owned pages only. */
    public function testAReleasedPageIsNotAVotedPageWithAnOwner(): void
    {
        global $wpdb;
        self::assertCount(1, $this->votes->getVotedPagesWithOwners(self::VOTER), 'control: an owned page');

        $wpdb->update($wpdb->prefix . 'bcc_trust_page_scores', ['page_owner_id' => 0], ['page_id' => $this->page]);

        self::assertSame([], $this->votes->getVotedPagesWithOwners(self::VOTER));
    }

    public function testAHiddenReviewConfersNoTrustAndRestoringItDoes(): void
    {
        $this->edges->recalculateVoteEdge(self::VOTER, self::OWNER);
        self::assertNotNull($this->edge(), 'precondition');

        (new HiddenActivityRepository())->add(self::ACT_ID, 0, 'autohide_threshold');
        $this->edges->recalculateVoteEdge(self::VOTER, self::OWNER);
        self::assertNull($this->edge(), 'a hidden review removes the edge');

        $this->edges->backfillFromSources();
        self::assertNull($this->edge(), 'the backfill does not resurrect it either');

        (new HiddenActivityRepository())->remove(self::ACT_ID);
        $this->edges->recalculateVoteEdge(self::VOTER, self::OWNER);
        self::assertSame(['weight' => 2.5, 'vote_count' => 1], $this->edge(), 'restored: trust flows again');
    }

    public function testTheVisibilityListenerRecalculatesTheEdge(): void
    {
        $this->edges->recalculateVoteEdge(self::VOTER, self::OWNER);
        self::assertNotNull($this->edge(), 'precondition');

        (new HiddenActivityRepository())->add(self::ACT_ID, 0, 'admin_action');
        $this->sync()->onVisibilityChanged(self::ACT_ID);
        self::assertNull($this->edge(), 'hiding (through the listener) removes the edge');

        (new HiddenActivityRepository())->remove(self::ACT_ID);
        $this->sync()->onVisibilityChanged(self::ACT_ID);
        self::assertSame(['weight' => 2.5, 'vote_count' => 1], $this->edge(), 'restoring (through the listener) brings it back');
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableReads(): iterable
    {
        yield 'the read errors'               => ['failQueriesMatching'];
        yield 'the read bails before flush()' => ['failSilentlyMatching'];
    }

    /**
     * The sum decides a write (a zero weight deletes the edge), so an
     * unreadable sum is refused before anything is written, under both an
     * error and wpdb's pre-flush() bail. Rationale: M7 (private tracker).
     */
    #[DataProvider('unreadableReads')]
    public function testAnUnreadableSumNeverDeletesTheEdge(string $fault): void
    {
        global $wpdb;
        $this->edges->recalculateVoteEdge(self::VOTER, self::OWNER);
        self::assertSame(['weight' => 2.5, 'vote_count' => 1], $this->edge(), 'precondition');

        $thrown = null;
        $wpdb->{$fault} = '/SUM\( v\.weight \)/';
        try {
            $this->edges->recalculateVoteEdge(self::VOTER, self::OWNER);
        } catch (RepositoryReadFailure $e) {
            $thrown = $e;
        } finally {
            $fired = $wpdb->injectedFailures + $wpdb->silentFailures;
            $wpdb->clearFaultInjection();
        }

        self::assertGreaterThan(0, $fired, 'the fault must actually have fired');
        self::assertInstanceOf(RepositoryReadFailure::class, $thrown, 'an unreadable sum is refused, not read as zero');
        self::assertSame(['weight' => 2.5, 'vote_count' => 1], $this->edge(), 'and the edge is untouched');
    }

    /**
     * An unreadable table probe keeps the hidden-review exclusion, so the
     * edge still withholds a hidden review's trust.
     */
    #[DataProvider('unreadableReads')]
    public function testAnUnreadableTableProbeStillWithholdsAHiddenReviewsTrust(string $fault): void
    {
        global $wpdb;
        $this->edges->recalculateVoteEdge(self::VOTER, self::OWNER);
        self::assertNotNull($this->edge(), 'precondition');
        (new HiddenActivityRepository())->add(self::ACT_ID, 0, 'autohide_threshold');

        TableRegistry::flushExistenceCache();
        $wpdb->{$fault} = "/^SHOW TABLES LIKE '[^']*(peepso_activities|bcc_hidden_activities)'$/";
        try {
            $this->edges->recalculateVoteEdge(self::VOTER, self::OWNER);
            $fired = $wpdb->injectedFailures + $wpdb->silentFailures;
        } finally {
            $wpdb->clearFaultInjection();
        }

        self::assertGreaterThan(0, $fired, 'the fault must actually have fired');
        self::assertNull($this->edge(), 'the hidden review confers no trust');
    }

    // ── F-574: the graph reads ────────────────────────────────────────

    /**
     * Runs $read with $fault armed on $pattern, after a different read has
     * left its own rows behind (what wpdb's pre-flush bail would hand back).
     *
     * @return array{result: mixed, thrown: ?RepositoryReadFailure, fired: int}
     */
    private function readUnder(string $fault, string $pattern, \Closure $read): array
    {
        global $wpdb;
        self::assertNotEmpty($wpdb->get_results('SELECT 1 AS left_behind'), 'precondition: rows are left behind');

        $result = null;
        $thrown = null;
        $wpdb->{$fault} = $pattern;
        try {
            $result = $read();
        } catch (RepositoryReadFailure $e) {
            $thrown = $e;
        } finally {
            $fired = $wpdb->injectedFailures + $wpdb->silentFailures;
            $wpdb->clearFaultInjection();
        }

        self::assertGreaterThan(0, $fired, 'the fault must actually have fired');

        return ['result' => $result, 'thrown' => $thrown, 'fired' => $fired];
    }

    /**
     * The full walk pages until an EMPTY page, so an unreadable page is
     * refused rather than read as the end of the table.
     */
    #[DataProvider('unreadableReads')]
    public function testAnUnreadableEdgePageIsRefusedNotReadAsTheEnd(string $fault): void
    {
        $this->edges->recalculateVoteEdge(self::VOTER, self::OWNER);
        self::assertCount(1, $this->edges->getAllEdges(0, 10), 'control: the page reads on this server');

        $out = $this->readUnder($fault, '/SELECT id, source_user_id, target_user_id, weight, edge_type/', fn () => $this->edges->getAllEdges(0, 10));

        self::assertInstanceOf(RepositoryReadFailure::class, $out['thrown'], 'an unreadable page is refused');
    }

    /** An unreadable neighbour walk is refused: an empty one would slip a hub under the 500 cap. */
    #[DataProvider('unreadableReads')]
    public function testAnUnreadableNeighbourWalkIsRefused(string $fault): void
    {
        $this->edges->recalculateVoteEdge(self::VOTER, self::OWNER);
        self::assertSame([self::OWNER], $this->edges->getNeighborIds([self::VOTER]), 'control: the walk reads on this server');

        $out = $this->readUnder($fault, '/SELECT DISTINCT source_user_id AS uid/', fn () => $this->edges->getNeighborIds([self::VOTER]));

        self::assertInstanceOf(RepositoryReadFailure::class, $out['thrown'], 'an unreadable neighbourhood is refused');
    }

    /** Fail-safe: an unreadable subgraph is an empty one (the caller's "skip"), never the rows left behind. */
    #[DataProvider('unreadableReads')]
    public function testAnUnreadableSubgraphIsEmpty(string $fault): void
    {
        $this->edges->recalculateVoteEdge(self::VOTER, self::OWNER);
        self::assertCount(1, $this->edges->getEdgesForUsers([self::VOTER, self::OWNER]), 'control: the subgraph reads on this server');

        $out = $this->readUnder($fault, '/SELECT source_user_id, target_user_id, weight, edge_type/', fn () => $this->edges->getEdgesForUsers([self::VOTER, self::OWNER]));

        self::assertNull($out['thrown'], 'fail-safe: nothing is thrown');
        self::assertSame([], $out['result']);
    }

    /** A hub's neighbour walk stops one past the 500-user cap, so the hub is still deferred. */
    public function testTheNeighbourWalkStopsOnePastTheHubCap(): void
    {
        global $wpdb;
        $now    = gmdate('Y-m-d H:i:s');
        $values = [];
        for ($i = 1; $i <= 600; $i++) {
            $values[] = $wpdb->prepare('(%d, %d, 1, 1, %s, %s, %s, %s)', self::VOTER, self::OWNER + $i, 'vote', $now, $now, $now);
        }
        self::assertNotFalse($wpdb->query(
            "INSERT INTO `{$wpdb->prefix}bcc_trust_edges`
                 (source_user_id, target_user_id, weight, vote_count, edge_type, created_at, updated_at, last_updated)
             VALUES " . implode(', ', $values)
        ), $wpdb->last_error);

        try {
            self::assertSame(600, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT target_user_id) FROM `{$wpdb->prefix}bcc_trust_edges` WHERE source_user_id = %d",
                self::VOTER
            )), 'precondition: the hub has more neighbours than the bound');

            $neighbours = $this->edges->getNeighborIds([self::VOTER]);

            self::assertCount(EdgeRepository::MAX_NEIGHBOR_IDS, $neighbours);
            self::assertGreaterThan(500, count(array_unique(array_merge([self::VOTER], $neighbours))), 'the hub still reads as "more than 500"');
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->prefix}bcc_trust_edges` WHERE source_user_id = %d", self::VOTER));
        }
    }

    private function sync(): ReviewVisibilityScoreSync
    {
        $ref = new \ReflectionClass(VoteService::class);
        $svc = $ref->newInstanceWithoutConstructor();
        foreach ([
            'voteRepo'        => $this->votes,
            'scoreRepo'       => new ScoreRepository(),
            'attestationRepo' => new AttestationRepository(),
        ] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($svc, $value);
        }

        return new ReviewVisibilityScoreSync($this->votes, $svc, $this->edges);
    }
}
