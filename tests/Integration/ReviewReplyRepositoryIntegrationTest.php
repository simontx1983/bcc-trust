<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\ReviewReplyRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\FeedItemReportVisibility;
use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\Services\GroupsService;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-345: the reads behind review replies, against real MySQL/MariaDB.
 *
 * A reply is a peepso-post (module meta `review_reply`, sidecar = the review's
 * vote id) plus ONE activity row (module 206). The repository must:
 *  - find it by vote id, with the act_id that report/hide key on;
 *  - flag a moderation-hidden reply (the slot stays occupied) rather than
 *    drop it, so callers can render "no reply" yet still refuse a second;
 *  - ignore a trashed post, a post of another module, and a post with no
 *    activity yet (a half-written reply);
 *  - read the explicit edited-at stamp;
 *  - fail CLOSED: a failed read throws, never "no reply".
 * And VoteRepository::findReviewForReply must accept only an active,
 * not-hidden review.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(ReviewReplyRepository::class)]
#[CoversClass(VoteRepository::class)]
#[CoversClass(FeedItemReportVisibility::class)]
final class ReviewReplyRepositoryIntegrationTest extends TestCase
{
    private const OWNER     = 987100;
    private const VOTER     = 987001;
    private const ACT_BASE  = 2700000500;
    private const POST_BASE = 2700000600;

    private int $page;
    private bool $activitiesWasOurs = false;
    /** @var list<string> wp_posts columns this suite added to the harness table */
    private array $addedPostColumns = [];
    private ReviewReplyRepository $replies;
    private VoteRepository $votes;
    private HiddenActivityRepository $hidden;

    /** @var list<int> */
    private array $voteIds = [];
    /** @var list<int> */
    private array $posts = [];
    /** @var list<int> */
    private array $acts = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/moderation-integration-stubs.php';
        $GLOBALS['__bcc_test_actions']      = [];
        $GLOBALS['__bcc_test_object_cache'] = [];

        global $wpdb;
        $wpdb->clearFaultInjection();
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

        // The harness wp_posts is a minimal subset of WordPress's; the reply
        // read selects post_author and post_date_gmt, as production has them.
        foreach ([
            'post_author'   => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
            'post_date_gmt' => "DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00'",
        ] as $column => $definition) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$wpdb->posts}` LIKE %s", $column));
            if ($exists === null) {
                self::assertNotFalse($wpdb->query("ALTER TABLE `{$wpdb->posts}` ADD COLUMN `{$column}` {$definition}"), $wpdb->last_error);
                $this->addedPostColumns[] = $column;
            }
        }

        $this->page    = MemberSelfPageService::ID_BASE + self::OWNER;
        $this->replies = new ReviewReplyRepository();
        $this->votes   = new VoteRepository();
        $this->hidden  = new HiddenActivityRepository();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->clearFaultInjection();
        foreach ($this->voteIds as $voteId) {
            $wpdb->delete($wpdb->prefix . 'bcc_trust_votes', ['id' => $voteId]);
        }
        foreach ($this->acts as $act) {
            $wpdb->delete($wpdb->prefix . 'bcc_hidden_activities', ['act_id' => $act]);
            if (!$this->activitiesWasOurs) {
                $wpdb->delete($wpdb->prefix . 'peepso_activities', ['act_id' => $act]);
            }
        }
        foreach ($this->posts as $post) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $post));
            $wpdb->delete($wpdb->posts, ['ID' => $post]);
        }
        if ($this->activitiesWasOurs) {
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}peepso_activities`");
        }
        foreach ($this->addedPostColumns as $column) {
            $wpdb->query("ALTER TABLE `{$wpdb->posts}` DROP COLUMN `{$column}`");
        }
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function vote(int $status = 1): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_votes', [
            // One vote per (voter, page, category): a distinct voter each.
            'voter_user_id' => self::VOTER + count($this->voteIds),
            'page_id'       => $this->page,
            'vote_type'     => 1,
            'weight'        => 1.0,
            'vested_weight' => 1.0,
            'explanation'   => 'a review',
            'status'        => $status,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
        $id              = (int) $wpdb->insert_id;
        $this->voteIds[] = $id;
        return $id;
    }

    /**
     * A backing post + meta (+ activity unless $withActivity is false).
     * Returns [postId, actId].
     *
     * @return array{int, int}
     */
    private function post(string $module, int $voteId, string $body, string $status = 'publish', bool $withActivity = true): array
    {
        global $wpdb;
        $i    = count($this->posts);
        $post = self::POST_BASE + $i;
        $act  = self::ACT_BASE + $i;
        foreach ([[$wpdb->posts, 'ID', $post], [$wpdb->prefix . 'peepso_activities', 'act_id', $act]] as [$t, $c, $id]) {
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE {$c} = %d", $id)) > 0) {
                self::markTestSkipped("{$t}.{$c}={$id} already exists; refusing to overwrite a real row");
            }
        }
        self::assertNotFalse($wpdb->insert($wpdb->posts, [
            'ID'            => $post,
            'post_type'     => 'peepso-post',
            'post_status'   => $status,
            'post_author'   => self::OWNER,
            'post_content'  => $body,
            'post_date_gmt' => '2026-11-12 10:00:00',
        ]), $wpdb->last_error);
        $this->posts[] = $post;
        self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $post, 'meta_key' => '_bcc_activity_module', 'meta_value' => $module]));
        self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $post, 'meta_key' => '_bcc_activity_sidecar_id', 'meta_value' => (string) $voteId]));

        if ($withActivity) {
            $moduleId = $module === 'review' ? '202' : '206';
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_activities', [
                'act_id' => $act, 'act_module_id' => $moduleId, 'act_external_id' => $post, 'act_owner_id' => self::OWNER,
            ]), $wpdb->last_error);
            $this->acts[] = $act;
        }
        return [$post, $act];
    }

    // ── ReviewReplyRepository ───────────────────────────────────────────

    public function testAReplyIsFoundByItsReviewWithTheActIdReportsUse(): void
    {
        $vote          = $this->vote();
        [$post, $act]  = $this->post('review_reply', $vote, 'thanks for the review');

        $rows = $this->replies->findByVoteIds([$vote]);

        self::assertArrayHasKey($vote, $rows);
        self::assertSame($post, $rows[$vote]['post_id']);
        self::assertSame($act, $rows[$vote]['act_id'], 'the act_id POST /me/reports and the hide key on');
        self::assertSame(self::OWNER, $rows[$vote]['author_id']);
        self::assertSame('thanks for the review', $rows[$vote]['body']);
        self::assertFalse($rows[$vote]['hidden']);
        self::assertNull($rows[$vote]['edited_at']);
        self::assertSame($rows[$vote], $this->replies->findForVote($vote));
    }

    public function testAHiddenReplyIsFlaggedNotDroppedSoTheSlotStaysTaken(): void
    {
        $vote      = $this->vote();
        [, $act]   = $this->post('review_reply', $vote, 'reply');
        self::assertTrue($this->hidden->add($act, 0, 'admin_action'));

        $row = $this->replies->findForVote($vote);

        self::assertNotNull($row, 'a hidden reply still occupies the review\'s one slot');
        self::assertTrue($row['hidden']);
    }

    public function testTrashedOtherModuleAndHalfWrittenPostsAreNotReplies(): void
    {
        $trashed = $this->vote();
        $this->post('review_reply', $trashed, 'deleted', 'trash');

        $otherModule = $this->vote();
        $this->post('review', $otherModule, 'this is the review itself');

        $halfWritten = $this->vote();
        $this->post('review_reply', $halfWritten, 'no activity yet', 'publish', false);

        self::assertSame([], $this->replies->findByVoteIds([$trashed, $otherModule, $halfWritten]));
    }

    public function testTheEditedAtStampIsRead(): void
    {
        global $wpdb;
        $vote         = $this->vote();
        [$post]       = $this->post('review_reply', $vote, 'edited reply');
        $wpdb->insert($wpdb->postmeta, ['post_id' => $post, 'meta_key' => ReviewReplyRepository::META_EDITED_AT, 'meta_value' => '2026-11-12 11:30:00']);

        self::assertSame('2026-11-12 11:30:00', $this->replies->findForVote($vote)['edited_at'] ?? null);
    }

    public function testAFailedReplyReadThrowsRatherThanReadingAsNoReply(): void
    {
        $vote = $this->vote();
        $this->post('review_reply', $vote, 'reply');

        $GLOBALS['wpdb']->failQueriesMatching = '/AS is_hidden/';
        $this->expectException(RepositoryReadFailure::class);
        $this->replies->findForVote($vote);
    }

    // ── VoteRepository::findReviewForReply ──────────────────────────────

    public function testOnlyAnActiveNotHiddenReviewTakesAReply(): void
    {
        $active  = $this->vote(1);
        $removed = $this->vote(0);
        $hidden  = $this->vote(1);
        [, $hiddenReviewAct] = $this->post('review', $hidden, 'review');
        self::assertTrue($this->hidden->add($hiddenReviewAct, 0, 'admin_action'));

        self::assertSame($active, (int) ($this->votes->findReviewForReply($active)->id ?? 0));
        self::assertSame($this->page, (int) ($this->votes->findReviewForReply($active)->page_id ?? 0));
        self::assertNull($this->votes->findReviewForReply($removed), 'a removed review takes no reply');
        self::assertNull($this->votes->findReviewForReply($hidden), 'a moderation-hidden review takes no reply');
    }

    public function testAFailedReviewReadThrows(): void
    {
        $vote = $this->vote();

        $GLOBALS['wpdb']->failQueriesMatching = '/SELECT v\.id, v\.voter_user_id, v\.page_id/';
        $this->expectException(RepositoryReadFailure::class);
        $this->votes->findReviewForReply($vote);
    }
    // ── report visibility: the F-345 reply arm (adjustments A + C) ──────

    private function visibility(): FeedItemReportVisibility
    {
        return new FeedItemReportVisibility(
            new GroupsService(new ReputationRepository(), new GroupContextResolver()),
            $this->hidden,
            $this->votes
        );
    }

    private function canReport(int $act): bool
    {
        $row = PeepSoActivityRepository::getById($act);
        self::assertNotNull($row, 'fixture activity must load');
        return $this->visibility()->canSee(self::VOTER + 50, $row);
    }

    public function testALiveReplyOnAVisibleReviewIsReportableThoughNoFeedShowsIt(): void
    {
        $vote    = $this->vote();
        [, $act] = $this->post('review_reply', $vote, 'reply');

        self::assertTrue($this->canReport($act));
    }

    public function testAHiddenReplyIsNotReportable(): void
    {
        $vote    = $this->vote();
        [, $act] = $this->post('review_reply', $vote, 'reply');
        self::assertTrue($this->hidden->add($act, 0, 'admin_action'));

        self::assertFalse($this->canReport($act));
    }

    /** C: a reply follows its review — a hidden or removed review takes its reply out of reach. */
    public function testAReplyToAHiddenOrRemovedReviewIsNotReportable(): void
    {
        $hiddenReview         = $this->vote();
        [, $reviewAct]        = $this->post('review', $hiddenReview, 'the review');
        [, $replyToHidden]    = $this->post('review_reply', $hiddenReview, 'reply');
        self::assertTrue($this->hidden->add($reviewAct, 0, 'admin_action'));

        $removedReview        = $this->vote(0);
        [, $replyToRemoved]   = $this->post('review_reply', $removedReview, 'reply');

        self::assertFalse($this->canReport($replyToHidden), 'the review is hidden');
        self::assertFalse($this->canReport($replyToRemoved), 'the review was removed');
    }

    public function testATrashedReplyIsNotReportable(): void
    {
        $vote    = $this->vote();
        [, $act] = $this->post('review_reply', $vote, 'deleted', 'trash');

        self::assertFalse($this->canReport($act));
    }
}
