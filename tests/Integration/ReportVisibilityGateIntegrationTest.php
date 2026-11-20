<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Repositories\ContentReportRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Services\ContentReportService;
use BCC\Trust\Core\Services\FeedItemReportVisibility;
use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\Services\GroupsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FeedActivityFixture.php';

/**
 * F-73 — the REAL-FIXTURE anchor for FeedItemReportVisibilityTest's matrix:
 * POST /me/reports' service path, end to end, against real SQL —
 * PeepSoActivityRepository::getById() on a real activity + wp_posts row,
 * PeepSoGroupRepository's real membership query on a real
 * `peepso_group_members` row, and the real GroupsService::gateGroupFeed().
 *
 * Only the WordPress post/meta READS for the group and the post come from
 * the halls-gate fixture stubs (the harness has no `get_post`), which is why
 * each case runs in its own process.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(ContentReportService::class)]
#[CoversClass(FeedItemReportVisibility::class)]
final class ReportVisibilityGateIntegrationTest extends TestCase
{
    use FeedActivityFixture;

    private const ACT_ID   = 2400000001;
    private const POST_ID  = 2400000001;
    private const GROUP_ID = 2400000101;
    private const REPORTER = 970001;
    private const AUTHOR   = 970002;

    // A comment under the post above: its own activity row and wp_post.
    private const COMMENT_ACT  = 2400000002;
    private const COMMENT_POST = 2400000002;
    private const COMMENTER    = 970003;

    private bool $membersWasOurs = false;
    private bool $usersWasOurs = false;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/halls-gate-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/moderation-integration-stubs.php';
        $GLOBALS['__bcc_halls_gate_fixture'] = [];
        $GLOBALS['__bcc_test_actions']       = [];

        global $wpdb;
        $members = $wpdb->prefix . 'peepso_group_members';
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $members)) !== $members) {
            self::assertNotFalse($wpdb->query(
                // PeepSo-owned table, not BCC schema: declared in the same
                // `IF NOT EXISTS` + backticked-prefix form the other PeepSo
                // fixtures use (DelegatorGroupIntegrationTest), which the
                // schema-drift guard does not treat as a BCC declaration.
                "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}peepso_group_members` (
                    gm_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    gm_user_id BIGINT UNSIGNED NOT NULL,
                    gm_group_id BIGINT UNSIGNED NOT NULL,
                    gm_user_status VARCHAR(32) NOT NULL DEFAULT 'member',
                    PRIMARY KEY (gm_id)
                )"
            ), $wpdb->last_error);
            $this->membersWasOurs = true;
        }

        $this->seedFeedActivity(self::ACT_ID, self::POST_ID, '1', self::AUTHOR);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->prefix . 'bcc_content_reports WHERE target_kind = %s AND target_id = %d',
            'feed_item',
            self::ACT_ID
        ));
        if ($this->usersWasOurs) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}peepso_users");
        }
        $members = $wpdb->prefix . 'peepso_group_members';
        if ($this->membersWasOurs) {
            $wpdb->query("DROP TABLE IF EXISTS {$members}");
        } else {
            $wpdb->query($wpdb->prepare("DELETE FROM {$members} WHERE gm_group_id = %d", self::GROUP_ID));
        }
        $this->removeFeedActivities();
    }

    public function testANonMemberCannotReportAClosedGroupsMembersOnlyPost(): void
    {
        $this->groupPost(privacy: 1, visibility: 'members_only');

        self::assertSame(['error' => 'bcc_not_found', 'message' => 'Not found.'], $this->resolve(self::ACT_ID));
    }

    public function testAMemberCanReportTheSamePost(): void
    {
        $this->groupPost(privacy: 1, visibility: 'members_only');
        $this->join('member');

        self::assertSame(['author_id' => self::AUTHOR], $this->resolve(self::ACT_ID));
    }

    public function testANonMemberCanReportAClosedGroupsTeaserPost(): void
    {
        $this->groupPost(privacy: 1, visibility: 'public_group');

        self::assertSame(['author_id' => self::AUTHOR], $this->resolve(self::ACT_ID), 'the closed-group teaser shows public_group posts');
    }

    public function testASecretGroupPostAnswersExactlyLikeAMissingId(): void
    {
        $this->groupPost(privacy: 2, visibility: 'public_group');
        $secret  = $this->resolve(self::ACT_ID);
        $missing = $this->resolve(self::ACT_ID + 7);

        self::assertSame(['error' => 'bcc_not_found', 'message' => 'Not found.'], $secret);
        self::assertSame($missing, $secret, 'a refusal never confirms the post exists');
    }

    /**
     * A COMMENT act id is not a feed item, so reporting it as one is the
     * missing id's 404 — for a member of the secret group as much as for a
     * stranger. Before, its row passed every check as if it were a public
     * post (same module as a status, no group on its own wp_post), and the
     * report was FILED. Real SQL end to end: the comment row is a real
     * `peepso_activities` row with `act_comment_object_id` set, found by the
     * real CommentRepository::getCommentMeta().
     */
    public function testACommentUnderASecretGroupPostIsTheMissingIds404EvenForAMember(): void
    {
        $this->groupPost(privacy: 2, visibility: 'members_only');
        $this->seedComment();
        $missing = $this->resolve(self::ACT_ID + 7);

        self::assertSame(['error' => 'bcc_not_found', 'message' => 'Not found.'], $this->resolve(self::COMMENT_ACT), 'a stranger');
        self::assertSame($missing, $this->resolve(self::COMMENT_ACT));

        $this->join('member');
        self::assertSame(['author_id' => self::AUTHOR], $this->resolve(self::ACT_ID), 'the member can report the POST');
        self::assertSame($missing, $this->resolve(self::COMMENT_ACT), 'but the comment is not a feed item');
    }

    /**
     * Under a closed group's TEASER post, which a non-member may report: the
     * post is reportable, its comment still is not a feed item.
     */
    public function testACommentUnderAReportableTeaserPostIsNotAFeedItemEither(): void
    {
        $this->groupPost(privacy: 1, visibility: 'public_group');
        $this->seedComment();

        self::assertSame(['author_id' => self::AUTHOR], $this->resolve(self::ACT_ID), 'the post is reportable');
        self::assertSame(['error' => 'bcc_not_found', 'message' => 'Not found.'], $this->resolve(self::COMMENT_ACT));
    }

    /**
     * A comment, seeded so that WITHOUT the comment check it would pass every
     * other rule as production's would: its wp_post carries no group, and its
     * author's §F-02 row is readable (a missing `peepso_users` row reads as
     * public, as in production). Without that table the audience re-read
     * fails in this harness and refuses every non-group post, which would let
     * these tests pass with the check removed.
     */
    private function seedComment(): void
    {
        global $wpdb;
        $users = $wpdb->prefix . 'peepso_users';
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $users)) !== $users) {
            self::assertNotFalse($wpdb->query(
                // PeepSo-owned table, in the IF NOT EXISTS + backticked-prefix
                // form the other PeepSo fixtures use (not a BCC declaration).
                "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}peepso_users` (
                    usr_id BIGINT UNSIGNED NOT NULL,
                    usr_profile_acc TINYINT NOT NULL DEFAULT 10,
                    PRIMARY KEY (usr_id)
                )"
            ), $wpdb->last_error);
            $this->usersWasOurs = true;
        }
        $this->seedFeedActivity(self::COMMENT_ACT, self::COMMENT_POST, '1', self::COMMENTER);
        self::assertNotFalse($wpdb->update(
            PeepSoActivitiesTable::name(),
            ['act_comment_object_id' => self::POST_ID, 'act_comment_module_id' => 1],
            ['act_id' => self::COMMENT_ACT]
        ), $wpdb->last_error);
        $GLOBALS['__bcc_halls_gate_fixture'][self::COMMENT_POST] = ['post_type' => 'peepso-post', 'meta' => []];
    }

    private function groupPost(int $privacy, string $visibility): void
    {
        $GLOBALS['__bcc_halls_gate_fixture'][self::GROUP_ID] = [
            'post_type' => 'peepso-group',
            'meta'      => ['_bcc_group_kind' => '', 'peepso_group_privacy' => (string) $privacy],
        ];
        $GLOBALS['__bcc_halls_gate_fixture'][self::POST_ID] = [
            'post_type' => 'peepso-post',
            'meta'      => ['peepso_group_id' => (string) self::GROUP_ID, '_bcc_post_visibility' => $visibility],
        ];
    }

    private function join(string $status): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_group_members', [
            'gm_user_id' => self::REPORTER, 'gm_group_id' => self::GROUP_ID, 'gm_user_status' => $status,
        ]), $wpdb->last_error);
    }

    /**
     * The feed arm of ContentReportService::fileReport(), exactly: the
     * envelope it returns is what POST /me/reports answers. (The full
     * fileReport() also runs the WP-backed suspension gate, which this
     * harness cannot host.)
     *
     * @return array<string, mixed>
     */
    private function resolve(int $actId): array
    {
        $m = new \ReflectionMethod(ContentReportService::class, 'resolveFeedItemTarget');
        /** @var array<string, mixed> $out */
        $out = $m->invoke($this->service(), self::REPORTER, $actId);
        return $out;
    }

    private function service(): ContentReportService
    {
        return new ContentReportService(
            new ContentReportRepository(),
            new FeedItemReportVisibility(
                new GroupsService(new ReputationRepository(), new GroupContextResolver()),
                new HiddenActivityRepository(),
                null,
                // The REAL comment lookup, against the real activities table.
                new CommentRepository()
            )
        );
    }

}
