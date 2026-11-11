<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

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

    private bool $membersWasOurs = false;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/halls-gate-stubs.php';
        $GLOBALS['__bcc_halls_gate_fixture'] = [];
        $GLOBALS['__bcc_test_actions']       = [];

        global $wpdb;
        $members = $wpdb->prefix . 'peepso_group_members';
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $members)) !== $members) {
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE {$members} (
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
                new HiddenActivityRepository()
            )
        );
    }

}
