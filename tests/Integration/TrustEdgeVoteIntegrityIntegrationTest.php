<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\EdgeRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Services\Vote\ReviewVisibilityScoreSync;
use BCC\Trust\Core\Services\VoteService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

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
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $acts)) !== $acts) {
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE IF NOT EXISTS `{$acts}` (
                    act_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    act_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    act_module_id VARCHAR(20) NOT NULL DEFAULT '0',
                    act_external_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    act_access INT NOT NULL DEFAULT 10,
                    PRIMARY KEY (act_id)
                ) DEFAULT CHARSET=utf8mb4"
            ), $wpdb->last_error);
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
