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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PeepSoActivitiesTable.php';
require_once __DIR__ . '/PeepSoBlocksTableFixture.php';

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
    use PeepSoBlocksTableFixture;

    private const OWNER     = 987100;
    private const NEW_OWNER = 987101;
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
        // The reply report arm asks PeepSo who the reporter may see
        // (ProfileAccess): PeepSoUserSearch and the CURRENT user from
        // filter-visible-stubs (declared BEFORE the moderation stubs' fixed
        // 0), the profile ladder and suspension from profile-access-stubs.
        // Both are subprocess-only, as this suite is. By default every
        // fixture member is visible and every profile open.
        require_once dirname(__DIR__) . '/Stubs/filter-visible-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/profile-access-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/moderation-integration-stubs.php';
        $GLOBALS['__bcc_fv'] = [
            'visible'     => array_fill_keys([self::OWNER, self::NEW_OWNER, self::VOTER + 50, self::PAGE_MEMBER], true),
            'admin'       => false,
            'constructed' => [],
        ];
        $GLOBALS['__bcc_profile_access'] = [
            'verdicts' => [10 => true, 20 => true, 40 => false],
        ];
        $GLOBALS['__bcc_test_actions']      = [];
        $GLOBALS['__bcc_test_object_cache'] = [];

        global $wpdb;
        $wpdb->clearFaultInjection();
        // Creates the table, or widens a copy another suite built, to the full column set.
        if (PeepSoActivitiesTable::ensure()) {
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
        $this->removePeepSoBlocksTable();
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
    private function post(string $module, int $voteId, string $body, string $status = 'publish', bool $withActivity = true, int $author = self::OWNER): array
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
            'post_author'   => $author,
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
        self::assertCount(1, $rows[$vote]);
        $row = $rows[$vote][0];
        self::assertSame($post, $row['post_id']);
        self::assertSame($act, $row['act_id'], 'the act_id POST /me/reports and the hide key on');
        self::assertSame(self::OWNER, $row['author_id']);
        self::assertSame('thanks for the review', $row['body']);
        self::assertFalse($row['hidden']);
        self::assertNull($row['edited_at']);
        self::assertSame($row, $this->replies->findForVoteByAuthor($vote, self::OWNER));
        self::assertNull($this->replies->findForVoteByAuthor($vote, self::NEW_OWNER), 'another author has no reply here');
    }

    public function testAfterAHandOverEachAuthorsReplyIsReadOldestFirst(): void
    {
        $vote         = $this->vote();
        [$oldPost]    = $this->post('review_reply', $vote, 'from the old owner');
        [$newPost]    = $this->post('review_reply', $vote, 'from the new owner', 'publish', true, self::NEW_OWNER);

        $rows = $this->replies->findByVoteIds([$vote]);

        self::assertSame([$oldPost, $newPost], array_column($rows[$vote], 'post_id'));
        self::assertSame($oldPost, $this->replies->findForVoteByAuthor($vote, self::OWNER)['post_id'] ?? null);
        self::assertSame($newPost, $this->replies->findForVoteByAuthor($vote, self::NEW_OWNER)['post_id'] ?? null);
    }

    public function testAReadThatHitsItsRowBoundThrowsRatherThanRiskMissingTheCurrentReply(): void
    {
        $vote = $this->vote();
        for ($i = 0; $i < 5; $i++) {
            $this->post('review_reply', $vote, 'reply ' . $i, 'publish', true, self::OWNER + 10 + $i);
        }

        $this->expectException(RepositoryReadFailure::class);
        $this->replies->findByVoteIds([$vote]);
    }

    public function testAHiddenReplyIsFlaggedNotDroppedSoTheSlotStaysTaken(): void
    {
        $vote      = $this->vote();
        [, $act]   = $this->post('review_reply', $vote, 'reply');
        self::assertTrue($this->hidden->add($act, 0, 'admin_action'));

        $row = $this->replies->findForVoteByAuthor($vote, self::OWNER);

        self::assertNotNull($row, 'a hidden reply still occupies its author\'s slot');
        self::assertTrue($row['hidden']);
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableProbes(): iterable
    {
        yield 'the probe errors'               => ['failQueriesMatching'];
        yield 'the probe bails before flush()' => ['failSilentlyMatching'];
    }

    /**
     * An unreadable existence probe for the hidden-activities table keeps the
     * hidden check, so a moderation-hidden reply still reads as hidden.
     * Rationale: L7 (private tracker).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unreadableProbes')]
    public function testAnUnreadableTableProbeStillFlagsAHiddenReply(string $fault): void
    {
        global $wpdb;
        $vote    = $this->vote();
        [, $act] = $this->post('review_reply', $vote, 'reply');
        self::assertTrue($this->hidden->add($act, 0, 'admin_action'));

        \BCC\Trust\Core\Database\TableRegistry::flushExistenceCache();
        $wpdb->{$fault} = "/^SHOW TABLES LIKE '[^']*bcc_hidden_activities'$/";
        try {
            $row   = $this->replies->findForVoteByAuthor($vote, self::OWNER);
            $fired = $wpdb->injectedFailures + $wpdb->silentFailures;
        } finally {
            $wpdb->clearFaultInjection();
        }

        self::assertGreaterThan(0, $fired, 'the fault must actually have fired');
        self::assertNotNull($row);
        self::assertTrue($row['hidden'], 'still flagged hidden');
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

        self::assertSame('2026-11-12 11:30:00', $this->replies->findForVoteByAuthor($vote, self::OWNER)['edited_at'] ?? null);
    }

    public function testAFailedReplyReadThrowsRatherThanReadingAsNoReply(): void
    {
        $vote = $this->vote();
        $this->post('review_reply', $vote, 'reply');

        $GLOBALS['wpdb']->failQueriesMatching = '/AS is_hidden/';
        $this->expectException(RepositoryReadFailure::class);
        $this->replies->findForVoteByAuthor($vote, self::OWNER);
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
        return $this->canReportAs($act, self::VOTER + 50);
    }

    /** The request's user, for WordPress and for PeepSo's ladder alike. */
    private function signInAs(int $userId): void
    {
        $GLOBALS['__bcc_fv_viewer']                   = $userId;
        $GLOBALS['__bcc_profile_access']['viewer_id'] = $userId;
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

    // ── F-532: a reply on a page's review follows the page's privacy ────

    private const PAGE_MEMBER = 987200;
    private const PEEPSO_PAGE = 993401;

    /**
     * A PeepSo page with one privacy row, and PAGE_MEMBER a member of it.
     * The reviews below are of THIS page, not of the owner's self-page.
     */
    private function onPeepSoPage(string $privacy): int
    {
        require_once dirname(__DIR__) . '/Stubs/post-row-integration-stubs.php';
        global $wpdb;
        self::assertNotFalse($wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}peepso_page_members` (
                pm_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                pm_user_id BIGINT UNSIGNED NOT NULL,
                pm_page_id BIGINT UNSIGNED NOT NULL,
                pm_user_status VARCHAR(32) NOT NULL DEFAULT 'member',
                PRIMARY KEY (pm_id)
            )"
        ), $wpdb->last_error);

        // ⚠ Below MemberSelfPageService::ID_BASE: an id above it IS a member
        // self-page, which has no page rule, and the case would pass for the
        // wrong reason.
        $page = self::PEEPSO_PAGE;
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $page)) > 0) {
            self::markTestSkipped("{$wpdb->posts}.ID={$page} already exists; refusing to overwrite a real row");
        }
        self::assertNotFalse($wpdb->insert($wpdb->posts, [
            'ID' => $page, 'post_type' => 'peepso-page', 'post_status' => 'publish', 'post_author' => self::OWNER,
        ]), $wpdb->last_error);
        $this->posts[] = $page;
        self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $page, 'meta_key' => 'peepso_page_privacy', 'meta_value' => $privacy]));
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_page_members', [
            'pm_user_id' => self::PAGE_MEMBER, 'pm_page_id' => $page, 'pm_user_status' => 'member',
        ]), $wpdb->last_error);
        $this->pageMemberRows[] = (int) $wpdb->insert_id;

        $this->page = $page;
        return $page;
    }

    /** @var list<int> */
    private array $pageMemberRows = [];

    /** $viewer reports in their own request (they are the current user). */
    private function canReportAs(int $act, int $viewer): bool
    {
        $row = PeepSoActivityRepository::getById($act);
        self::assertNotNull($row, 'fixture activity must load');
        $this->signInAs($viewer);
        return $this->visibility()->canSee($viewer, $row);
    }

    private function forgetPageMembers(): void
    {
        global $wpdb;
        foreach ($this->pageMemberRows as $id) {
            $wpdb->delete($wpdb->prefix . 'peepso_page_members', ['pm_id' => $id]);
        }
    }

    public function testAReplyOnASecretPagesReviewIsReportableOnlyByThoseThePageAdmits(): void
    {
        $this->onPeepSoPage('2');
        [, $act] = $this->post('review_reply', $this->vote(), 'reply on a secret page');

        try {
            self::assertFalse($this->canReportAs($act, self::VOTER + 50), 'a stranger gets what a missing item gets');
            self::assertFalse($this->canReportAs($act, 0), 'and so does anonymous');
            self::assertTrue($this->canReportAs($act, self::PAGE_MEMBER), 'a member of the page may report it');
            self::assertTrue($this->canReportAs($act, self::OWNER), 'and so may its author');
        } finally {
            $this->forgetPageMembers();
        }
    }

    public function testAReplyOnAnOpenPagesReviewIsReportableByAnyone(): void
    {
        $this->onPeepSoPage('0');
        [, $act] = $this->post('review_reply', $this->vote(), 'reply on an open page');

        try {
            self::assertTrue($this->canReportAs($act, self::VOTER + 50));
        } finally {
            $this->forgetPageMembers();
        }
    }

    /** The page's privacy could not be read: nobody but its author may report the reply. */
    public function testAFailedPagePrivacyReadMakesTheReplyUnreportable(): void
    {
        $this->onPeepSoPage('0');
        [, $act] = $this->post('review_reply', $this->vote(), 'reply');
        $GLOBALS['wpdb']->failQueriesMatching = "/meta_key = 'peepso_page_privacy'/";

        try {
            self::assertFalse($this->canReportAs($act, self::VOTER + 50));
            self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'the fault really hit the privacy read');
        } finally {
            $GLOBALS['wpdb']->clearFaultInjection();
            $this->forgetPageMembers();
        }
    }

    // ── a reply is reportable exactly where its Reviews tab shows it ────

    /**
     * A member's Reviews tab answers 403 to a viewer who may not open their
     * profile (CardReviewsEndpoint), so a reply on a review of that member
     * is not reportable by them. PeepSo's member search still lists the
     * author here, so only the profile gate refuses.
     */
    public function testAReplyOnAMembersReviewIsReportableOnlyByThoseWhoMayOpenTheirProfile(): void
    {
        [, $act] = $this->post('review_reply', $this->vote(), 'the member answers');

        self::assertTrue($this->canReportAs($act, self::VOTER + 50), 'control: an open profile');

        $GLOBALS['__bcc_profile_access']['levels'] = [self::OWNER => 40];
        self::assertFalse($this->canReportAs($act, self::VOTER + 50), 'a profile closed to the reporter');

        $GLOBALS['__bcc_profile_access']['levels']    = [];
        $GLOBALS['__bcc_profile_access']['suspended'] = [self::OWNER => true];
        self::assertFalse($this->canReportAs($act, self::VOTER + 50), 'a suspended member');
    }

    /** The member's profile opens, but the reporter may not see them as the reply's author (a block). */
    public function testOnAMembersReviewAReplyWhoseAuthorTheReporterMayNotSeeIsNotReportable(): void
    {
        [, $act] = $this->post('review_reply', $this->vote(), 'the member answers');
        $this->ensurePeepSoBlocksTable();
        $this->seedBlock(self::VOTER + 50, self::OWNER);

        self::assertTrue($this->canReportAs($act, self::VOTER + 50), 'control: PeepSo still lists the author');

        unset($GLOBALS['__bcc_fv']['visible'][self::OWNER]);
        self::assertFalse($this->canReportAs($act, self::VOTER + 50));
    }

    /**
     * On a page's review, a reply whose author is hidden from the reporter
     * ONLY by the level they chose for their profile is shown as the page's
     * response, so it stays reportable.
     */
    private function onAPageWithAProfileLevelHiddenAuthor(): int
    {
        $this->onPeepSoPage('0');
        [, $act] = $this->post('review_reply', $this->vote(), 'the page answers');
        $this->ensurePeepSoBlocksTable();
        unset($GLOBALS['__bcc_fv']['visible'][self::OWNER]);
        $GLOBALS['__bcc_profile_access']['levels'] = [self::OWNER => 40];
        return $act;
    }

    public function testOnAPagesReviewAReplyShownAsThePagesStaysReportable(): void
    {
        $act = $this->onAPageWithAProfileLevelHiddenAuthor();

        try {
            self::assertTrue($this->canReportAs($act, self::VOTER + 50));
        } finally {
            $this->forgetPageMembers();
        }
    }

    /** @return array<string, array{string}> */
    public static function hiddenAuthorReasons(): array
    {
        return [
            'the reporter blocked the author' => ['blocked'],
            'the author blocked the reporter' => ['blocker'],
            'the block read failed'           => ['block_read_failed'],
            'the author is suspended'         => ['suspended'],
            'the author is banned'            => ['banned'],
        ];
    }

    /** Every other reason the tab shows no reply refuses the report too. */
    #[DataProvider('hiddenAuthorReasons')]
    public function testOnAPagesReviewABlockedSuspendedOrBannedAuthorsReplyIsNotReportable(string $reason): void
    {
        $act = $this->onAPageWithAProfileLevelHiddenAuthor();
        match ($reason) {
            'blocked'           => $this->seedBlock(self::VOTER + 50, self::OWNER),
            'blocker'           => $this->seedBlock(self::OWNER, self::VOTER + 50),
            'block_read_failed' => $GLOBALS['wpdb']->failQueriesMatching = '/peepso_blocks/',
            'suspended'         => $GLOBALS['__bcc_profile_access']['suspended'] = [self::OWNER => true],
            'banned'            => $GLOBALS['__bcc_profile_access']['roles'] = [self::OWNER => 'ban'],
        };

        try {
            self::assertFalse($this->canReportAs($act, self::VOTER + 50));
            if ($reason === 'block_read_failed') {
                self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'the fault really hit the block read');
            }
        } finally {
            $GLOBALS['wpdb']->clearFaultInjection();
            $this->forgetPageMembers();
        }
    }

    /**
     * ⚠ The HAND-OVER carve-out stays (contract v1.159, user decision
     * 2026-11-18): after the page changes hands, the old author's reply no
     * longer shows on the tab, yet it stays reportable while it is live.
     * "Reportable where the tab shows it" is about who may be seen, not
     * about who answers for the page now.
     */
    public function testAReplyWhoseAuthorNoLongerAnswersForThePageStaysReportable(): void
    {
        $page    = $this->onPeepSoPage('0');
        [, $act] = $this->post('review_reply', $this->vote(), 'the old owner answered');

        global $wpdb;
        self::assertNotFalse($wpdb->update($wpdb->posts, ['post_author' => self::NEW_OWNER], ['ID' => $page]), $wpdb->last_error);

        try {
            self::assertTrue($this->canReportAs($act, self::VOTER + 50));
        } finally {
            $this->forgetPageMembers();
        }
    }

    /**
     * PeepSo answers who may be seen for the CURRENT user only, so a viewer
     * who is not the request's user is refused rather than answered as them.
     */
    public function testOnlyTheRequestsOwnUserIsAnswered(): void
    {
        [, $act] = $this->post('review_reply', $this->vote(), 'reply');
        $row     = PeepSoActivityRepository::getById($act);
        self::assertNotNull($row);

        $this->signInAs(self::VOTER + 50);
        self::assertTrue($this->visibility()->canSee(self::VOTER + 50, $row), 'control: the reporter in their own request');

        $this->signInAs(self::PAGE_MEMBER);
        self::assertFalse($this->visibility()->canSee(self::VOTER + 50, $row));
    }
}
