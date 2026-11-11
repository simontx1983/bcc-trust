<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-55: a moderation-hidden review must not appear on the review LISTS:
 * the entity Reviews tab (findByPageIdPaginated / countByPageId) and the
 * member profile's "reviews written" (findByVoterPaginated /
 * countReviewsByVoter). Real SQL, real HiddenActivityRepository.
 *
 * A review is a votes row; its feed activity points at a backing wp_post
 * whose meta carries `_bcc_activity_module = 'review'` and
 * `_bcc_activity_sidecar_id = <vote id>`. Moderation hides that ACTIVITY.
 *
 * Fixture hygiene: `peepso_activities` is created only when absent and
 * dropped only when this suite created it; the shared wp_posts/wp_postmeta
 * and the votes table get only this suite's ids, deleted in tearDown.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(VoteRepository::class)]
final class HiddenReviewListsIntegrationTest extends TestCase
{
    private const PAGE     = 2600000101;
    private const VOTER_A  = 985001;
    private const VOTER_B  = 985002;
    private const VOTER_C  = 985003;
    private const ACT_BASE = 2600000200;
    private const POST_BASE = 2600000300;

    private bool $activitiesWasOurs = false;

    /** @var array<int, int> vote id => act id */
    private array $actByVote = [];

    /** @var list<int> */
    private array $voteIds = [];

    private VoteRepository $votes;
    private HiddenActivityRepository $hidden;

    protected function setUp(): void
    {
        // do_action / get_current_user_id + the hidden_activities table.
        require_once dirname(__DIR__) . '/Stubs/moderation-integration-stubs.php';

        global $wpdb;
        $acts = $wpdb->prefix . 'peepso_activities';
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $acts)) !== $acts) {
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}peepso_activities` (
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

        $this->votes  = new VoteRepository();
        $this->hidden = new HiddenActivityRepository();

        // Three reviews of the same page by three members, each with its
        // feed activity + backing post (as handleReviewPublished writes).
        foreach ([self::VOTER_A, self::VOTER_B, self::VOTER_C] as $i => $voter) {
            $this->review($voter, $i);
        }
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ($this->voteIds as $voteId) {
            $wpdb->delete($wpdb->prefix . 'bcc_trust_votes', ['id' => $voteId]);
        }
        foreach ($this->actByVote as $actId) {
            $wpdb->delete($wpdb->prefix . 'bcc_hidden_activities', ['act_id' => $actId]);
        }
        for ($i = 0; $i < 5; $i++) {
            $post = self::POST_BASE + $i;
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $post));
            $wpdb->delete($wpdb->posts, ['ID' => $post]);
            if (!$this->activitiesWasOurs) {
                $wpdb->delete($wpdb->prefix . 'peepso_activities', ['act_id' => self::ACT_BASE + $i]);
            }
        }
        if ($this->activitiesWasOurs) {
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}peepso_activities`");
        }
    }

    public function testAHiddenReviewLeavesTheEntityReviewsList(): void
    {
        self::assertSame(3, $this->votes->countByPageId(self::PAGE), 'precondition');

        $hiddenVote = $this->voteIds[1];
        self::assertTrue($this->hidden->add($this->actByVote[$hiddenVote], 0, 'autohide_threshold'));

        self::assertSame(2, $this->votes->countByPageId(self::PAGE), 'the count agrees with the list');
        $listed = $this->ids($this->votes->findByPageIdPaginated(self::PAGE, 10, 0));
        self::assertCount(2, $listed);
        self::assertNotContains($hiddenVote, $listed);

        // Pagination stays consistent: page 2 of size 1 is the 2nd VISIBLE row.
        $p1 = $this->ids($this->votes->findByPageIdPaginated(self::PAGE, 1, 0));
        $p2 = $this->ids($this->votes->findByPageIdPaginated(self::PAGE, 1, 1));
        $p3 = $this->ids($this->votes->findByPageIdPaginated(self::PAGE, 1, 2));
        self::assertSame($listed, array_merge($p1, $p2));
        self::assertSame([], $p3, 'no page beyond the visible rows');
    }

    public function testRestoringTheReviewBringsItBack(): void
    {
        $hiddenVote = $this->voteIds[0];
        $this->hidden->add($this->actByVote[$hiddenVote], 940001, 'admin_action');
        self::assertSame(2, $this->votes->countByPageId(self::PAGE));

        $this->hidden->remove($this->actByVote[$hiddenVote]);

        self::assertSame(3, $this->votes->countByPageId(self::PAGE));
        self::assertContains($hiddenVote, $this->ids($this->votes->findByPageIdPaginated(self::PAGE, 10, 0)));
    }

    public function testAHiddenReviewLeavesTheAuthorsReviewsWrittenList(): void
    {
        $voteA = $this->voteIds[0];
        self::assertSame(1, $this->votes->countReviewsByVoter(self::VOTER_A));

        $this->hidden->add($this->actByVote[$voteA], 0, 'autohide_threshold');

        self::assertSame(0, $this->votes->countReviewsByVoter(self::VOTER_A));
        self::assertSame([], $this->votes->findByVoterPaginated(self::VOTER_A, 10, 0));
        // countByVoter (vote weight / quests / stats) is deliberately unchanged.
        self::assertSame(1, $this->votes->countByVoter(self::VOTER_A), 'scoring counts are out of F-55 scope');
    }

    public function testTheProfileReviewsServiceTotalAgreesWithItsList(): void
    {
        $service = new \BCC\Trust\Core\Services\UserReviewsService(
            $this->votes,
            new \BCC\Trust\Core\Repositories\UserMiniRepository()
        );
        $before = $service->getReviews(self::VOTER_A, self::VOTER_A); // self view: no privacy lookup
        self::assertSame(1, $before['pagination']['total']);
        self::assertCount(1, $before['items']);

        $this->hidden->add($this->actByVote[$this->voteIds[0]], 0, 'autohide_threshold');

        $after = $service->getReviews(self::VOTER_A, self::VOTER_A);
        self::assertSame(0, $after['pagination']['total'], 'the service uses the hidden-aware count');
        self::assertSame([], $after['items']);
    }

    public function testOnlyAREVIEWActivityExcludesItsVote(): void
    {
        // A hidden activity of ANOTHER module whose sidecar id happens to
        // equal a vote id must not hide that vote's review.
        $voteC = $this->voteIds[2];
        global $wpdb;
        $post = self::POST_BASE + 4;
        $act  = self::ACT_BASE + 4;
        self::assertNotFalse($wpdb->insert($wpdb->posts, ['ID' => $post, 'post_type' => 'peepso-post', 'post_status' => 'publish']));
        add_post_meta_row($post, '_bcc_activity_module', 'watch_batch');
        add_post_meta_row($post, '_bcc_activity_sidecar_id', (string) $voteC);
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_activities', ['act_id' => $act, 'act_module_id' => '200', 'act_external_id' => $post]));
        $this->hidden->add($act, 0, 'admin_action');
        $this->actByVote[-1] = $act;

        self::assertSame(3, $this->votes->countByPageId(self::PAGE));
        self::assertContains($voteC, $this->ids($this->votes->findByPageIdPaginated(self::PAGE, 10, 0)));
    }

    private function review(int $voter, int $i): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_votes', [
            'voter_user_id' => $voter,
            'page_id'       => self::PAGE,
            'vote_type'     => 1,
            'explanation'   => "review {$i}",
            'status'        => 1,
            'created_at'    => gmdate('Y-m-d H:i:s', time() - 60 * $i),
        ]), $wpdb->last_error);
        $voteId = (int) $wpdb->insert_id;
        $this->voteIds[] = $voteId;

        $post = self::POST_BASE + $i;
        $act  = self::ACT_BASE + $i;
        foreach ([[$wpdb->posts, 'ID', $post], [$wpdb->prefix . 'peepso_activities', 'act_id', $act]] as [$t, $c, $id]) {
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE {$c} = %d", $id)) > 0) {
                self::markTestSkipped("{$t}.{$c}={$id} already exists; refusing to overwrite a real row");
            }
        }
        self::assertNotFalse($wpdb->insert($wpdb->posts, ['ID' => $post, 'post_type' => 'peepso-post', 'post_status' => 'publish']));
        add_post_meta_row($post, '_bcc_activity_module', 'review');
        add_post_meta_row($post, '_bcc_activity_sidecar_id', (string) $voteId);
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_activities', [
            'act_id' => $act, 'act_module_id' => '202', 'act_external_id' => $post, 'act_owner_id' => $voter,
        ]));
        $this->actByVote[$voteId] = $act;
    }

    /**
     * @param list<object> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (object $r): int => (int) $r->id, $rows);
    }
}

/** Plain meta insert (the harness's update_post_meta also works; this keeps the fixture explicit). */
function add_post_meta_row(int $postId, string $key, string $value): void
{
    global $wpdb;
    $ok = $wpdb->insert($wpdb->postmeta, ['post_id' => $postId, 'meta_key' => $key, 'meta_value' => $value]);
    \PHPUnit\Framework\Assert::assertNotFalse($ok, (string) $wpdb->last_error);
}
