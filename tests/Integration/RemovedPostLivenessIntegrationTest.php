<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Services\FeedItemReportVisibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FeedActivityFixture.php';

/**
 * F-265: the real-SQL half of "nothing may act on a post that is gone".
 *
 * The unit suites pin the gate and the comment routes against doubles. What
 * only real SQL can show:
 *   - bcc-core getById()'s `act_status` IS the backing post's post_status,
 *     so a post the author deleted (wp_trash_post → `trash`) is not live;
 *   - a moderation-hide row makes it not live;
 *   - CommentRepository::getCommentMeta()'s new `parent_act_id` resolves the
 *     comment's parent ACTIVITY (act_comment_object_id + act_comment_module_id),
 *     which is what the hidden-parent check looks up.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(FeedItemReportVisibility::class)]
#[CoversClass(CommentRepository::class)]
final class RemovedPostLivenessIntegrationTest extends TestCase
{
    use FeedActivityFixture;

    private const POST_ACT     = 2650000001;
    private const POST_ID      = 2650000001;
    private const COMMENT_ACT  = 2650000002;
    private const COMMENT_POST = 2650000002;
    private const AUTHOR       = 965001;

    protected function setUp(): void
    {
        // The real bcc_hidden_activities table: without it exists() fails
        // CLOSED (F-98) and every post would read as hidden.
        require_once dirname(__DIR__) . '/Stubs/moderation-integration-stubs.php';

        $this->seedFeedActivity(self::POST_ACT, self::POST_ID, '1', self::AUTHOR);
        $this->seedFeedActivity(self::COMMENT_ACT, self::COMMENT_POST, '1', self::AUTHOR);

        global $wpdb;
        $acts = $wpdb->prefix . 'peepso_activities';
        // The fixture's minimal table lacks PeepSo's comment columns; add
        // only what is missing (the shared harness table may have them).
        $existing = (array) $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $acts
        ));
        $adds = [];
        foreach ([
            'act_comment_object_id' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'act_comment_module_id' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        ] as $column => $definition) {
            if (!in_array($column, $existing, true)) {
                $adds[] = "ADD COLUMN {$column} {$definition}";
            }
        }
        if ($adds !== []) {
            self::assertNotFalse($wpdb->query("ALTER TABLE {$acts} " . implode(', ', $adds)), $wpdb->last_error);
        }

        // The second activity is a COMMENT on the first.
        self::assertNotFalse($wpdb->update(
            $acts,
            ['act_comment_object_id' => self::POST_ID, 'act_comment_module_id' => 1],
            ['act_id' => self::COMMENT_ACT]
        ), $wpdb->last_error);
        self::assertNotFalse($wpdb->update(
            $wpdb->posts,
            ['post_type' => 'peepso-comment'],
            ['ID' => self::COMMENT_POST]
        ), $wpdb->last_error);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->prefix . 'bcc_hidden_activities WHERE act_id IN (%d, %d)',
            self::POST_ACT,
            self::COMMENT_ACT
        ));
        $this->removeFeedActivities();
    }

    public function testAPublishedUnhiddenPostIsLive(): void
    {
        self::assertTrue($this->live(self::POST_ACT));
    }

    public function testAPostTheAuthorDeletedIsNotLive(): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->update($wpdb->posts, ['post_status' => 'trash'], ['ID' => self::POST_ID]));

        self::assertFalse($this->live(self::POST_ACT));
    }

    public function testAModerationHiddenPostIsNotLive(): void
    {
        $this->hide(self::POST_ACT);

        self::assertFalse($this->live(self::POST_ACT));
    }

    public function testCommentMetaResolvesTheParentActivity(): void
    {
        $meta = (new CommentRepository())->getCommentMeta(self::COMMENT_ACT);

        self::assertNotNull($meta);
        self::assertSame(self::POST_ID, (int) $meta->parent_post_id);
        self::assertSame(self::POST_ACT, (int) ($meta->parent_act_id ?? 0));
    }

    public function testAnUnresolvableParentActivityReadsAsZero(): void
    {
        global $wpdb;
        // Parent post exists as an id, but no activity has that module.
        self::assertNotFalse($wpdb->update(
            $wpdb->prefix . 'peepso_activities',
            ['act_comment_module_id' => 77],
            ['act_id' => self::COMMENT_ACT]
        ));

        $meta = (new CommentRepository())->getCommentMeta(self::COMMENT_ACT);

        self::assertNotNull($meta, 'the comment itself still resolves');
        self::assertSame(0, (int) ($meta->parent_act_id ?? -1));
    }

    private function live(int $actId): bool
    {
        $row = PeepSoActivityRepository::getById($actId);
        self::assertNotNull($row);

        return FeedItemReportVisibility::isLiveWith($row, new HiddenActivityRepository());
    }

    private function hide(int $actId): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_hidden_activities', [
            'act_id'            => $actId,
            'hidden_at'         => '2026-11-12 00:00:00',
            'hidden_by_user_id' => 1,
            'reason_code'       => 'f265_test',
        ]), $wpdb->last_error);
    }
}
