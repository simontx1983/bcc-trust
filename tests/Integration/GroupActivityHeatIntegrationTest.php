<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Services\GroupActivityHeatService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PeepSoActivitiesTable.php';

/**
 * A community's activity counts exclude moderated posts.
 *
 * A post moderation hides stays `publish`, with a row in the hidden-activity
 * table, so core's getActivityHeat() leaves the hidden act ids out
 * of the posts, the active posters and the last activity, and
 * GroupActivityHeatService passes it the hidden set. When the hidden set
 * cannot be read, no number is guessed: the activity block is null and the
 * community card shows no posts or active stat.
 *
 * Real peepso_activities, wp_posts, postmeta and hidden-activity rows; the
 * real core read, repository and service.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(GroupActivityHeatService::class)]
final class GroupActivityHeatIntegrationTest extends TestCase
{
    private const GROUP = 958801;

    private const AUTHOR_A = 958811;
    private const AUTHOR_B = 958812;
    private const AUTHOR_C = 958813; // their only post is the newest, and is hidden

    private const POST_A = 958901;
    private const POST_B = 958902;
    private const POST_C = 958903;

    /** The hidden-id list read, and no other statement. */
    private const HIDDEN_LIST_READ = '/SELECT act_id FROM \S*bcc_hidden_activities WHERE act_id </';

    private bool $activitiesWasOurs = false;

    /** @var array<int, int> post id => act id */
    private array $actOf = [];

    /** @var array<int, string> post id => post_date_gmt */
    private array $dateOf = [];

    protected function setUp(): void
    {
        global $wpdb;
        $GLOBALS['__bcc_test_object_cache'] = [];
        unset($GLOBALS['__bcc_test_options'][HiddenActivityRepository::LAST_GOOD_OPTION]);
        $wpdb->clearFaultInjection();

        if (PeepSoActivitiesTable::ensure()) {
            $this->activitiesWasOurs = true;
        }
        // do_action, and the hidden-activity table, as the other moderation suites use them.
        require_once dirname(__DIR__) . '/Stubs/moderation-integration-stubs.php';
        $this->addMissingPostColumns();
        $this->purge();

        foreach ([self::POST_A => [self::AUTHOR_A, 7200], self::POST_B => [self::AUTHOR_B, 3600], self::POST_C => [self::AUTHOR_C, 600]] as $postId => [$author, $ago]) {
            $this->dateOf[$postId] = gmdate('Y-m-d H:i:s', time() - $ago);
            self::assertNotFalse($wpdb->insert($wpdb->posts, [
                'ID' => $postId, 'post_type' => 'peepso-post', 'post_status' => 'publish', 'post_title' => 'Heat ' . $postId,
                'post_name' => 'heat-' . $postId, 'post_author' => $author, 'post_date_gmt' => $this->dateOf[$postId],
            ]), $wpdb->last_error);
            self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $postId, 'meta_key' => 'peepso_group_id', 'meta_value' => (string) self::GROUP]), $wpdb->last_error);
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_activities', ['act_owner_id' => $author, 'act_external_id' => $postId]), $wpdb->last_error);
            $this->actOf[$postId] = (int) $wpdb->insert_id;
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
        if ($this->activitiesWasOurs) {
            global $wpdb;
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}peepso_activities`");
        }
    }

    /** Core: the excluded act ids drop out of the posts, the active posters and the last activity. */
    public function testExcludedPostsDropOutOfEveryNumber(): void
    {
        $all = PeepSoGroupRepository::getActivityHeat([self::GROUP])[self::GROUP] ?? null;
        self::assertNotNull($all);
        self::assertSame([3, 3, $this->dateOf[self::POST_C]], [$all->posts, $all->actives, $all->last_at]);

        $without = PeepSoGroupRepository::getActivityHeat([self::GROUP], 7 * DAY_IN_SECONDS, [$this->actOf[self::POST_C]])[self::GROUP] ?? null;
        self::assertNotNull($without);
        self::assertSame([2, 2, $this->dateOf[self::POST_B]], [$without->posts, $without->actives, $without->last_at]);
    }

    /** The community activity block excludes a post moderation has hidden. */
    public function testTheActivityBlockExcludesModeratedPosts(): void
    {
        self::assertTrue(Plugin::instance()->hiddenActivityRepository()->add($this->actOf[self::POST_C], 1, 'manual'));

        $block = Plugin::instance()->groupActivityHeatService()->forGroups([self::GROUP])[self::GROUP];

        self::assertSame(2, $block['posts_last_7d']);
        self::assertSame(2, $block['active_members_7d']);
        self::assertSame(gmdate('c', (int) strtotime($this->dateOf[self::POST_B] . ' UTC')), $block['last_activity_at']);
        self::assertSame('cold', $block['heat']);
    }

    /**
     * When the hidden set cannot be read and no last-known-good list exists,
     * every number is null, never an unfiltered count or 0, and the community
     * card shows no posts or active stat.
     */
    public function testAnUnknownHiddenSetGivesNoNumbers(): void
    {
        global $wpdb;
        $wpdb->failQueriesMatching = self::HIDDEN_LIST_READ;

        $block = Plugin::instance()->groupActivityHeatService()->forGroups([self::GROUP])[self::GROUP];

        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault hit the hidden-id read');
        self::assertSame(
            ['posts_last_7d' => null, 'active_members_7d' => null, 'last_activity_at' => null, 'heat' => null, 'heat_label' => null],
            $block
        );

        $card = Plugin::instance()->cardViewService()->getCommunityCardFromGroupData(self::groupData($block), 0);
        self::assertSame(['members', 'access'], array_column($card['stats'], 'key'), 'no posts or active stat');
    }

    /** A last-known-good hidden list is still a list: it is used, and numbers are shown. */
    public function testALastKnownGoodHiddenListIsUsed(): void
    {
        global $wpdb;
        $GLOBALS['__bcc_test_options'][HiddenActivityRepository::LAST_GOOD_OPTION] = [
            'ids' => [$this->actOf[self::POST_C]], 'read_at' => microtime(true) - 60, 'hash' => 'x',
        ];
        $wpdb->failQueriesMatching = self::HIDDEN_LIST_READ;

        $block = Plugin::instance()->groupActivityHeatService()->forGroups([self::GROUP])[self::GROUP];

        self::assertSame(2, $block['posts_last_7d']);
        self::assertSame(2, $block['active_members_7d']);
    }

    /** With numbers, the community card shows them as before. */
    public function testTheCardShowsTheFilteredNumbers(): void
    {
        self::assertTrue(Plugin::instance()->hiddenActivityRepository()->add($this->actOf[self::POST_C], 1, 'manual'));
        $block = Plugin::instance()->groupActivityHeatService()->forGroups([self::GROUP])[self::GROUP];

        $card  = Plugin::instance()->cardViewService()->getCommunityCardFromGroupData(self::groupData($block), 0);
        $stats = array_column($card['stats'], 'raw', 'key');

        self::assertSame(['members', 'posts_7d', 'active', 'access'], array_column($card['stats'], 'key'));
        self::assertSame(2, $stats['posts_7d']);
        self::assertSame(2, $stats['active']);
    }

    /**
     * The rank stewardship sweep's poster count excludes moderated posts too,
     * and is unknown (null: no credit) when they cannot be read.
     */
    public function testStewardshipPosterCountsExcludeModeratedPosts(): void
    {
        global $wpdb;
        self::assertTrue(Plugin::instance()->hiddenActivityRepository()->add($this->actOf[self::POST_C], 1, 'manual'));
        $heat = new \ReflectionMethod(\BCC\Trust\Rank\Services\StewardshipSweepService::class, 'activityHeat');
        $sweep = (new \ReflectionClass(\BCC\Trust\Rank\Services\StewardshipSweepService::class))->newInstanceWithoutConstructor();

        self::assertSame([self::GROUP => 2], $heat->invoke($sweep, [self::GROUP], 7 * DAY_IN_SECONDS), 'the hidden post\'s author is not counted');

        $GLOBALS['__bcc_test_object_cache'] = [];
        unset($GLOBALS['__bcc_test_options'][HiddenActivityRepository::LAST_GOOD_OPTION]);
        $wpdb->failQueriesMatching = self::HIDDEN_LIST_READ;
        self::assertNull($heat->invoke($sweep, [self::GROUP], 7 * DAY_IN_SECONDS), 'unknown, so no credit');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * The community data a producer hands the card, with this activity.
     *
     * @param array{posts_last_7d: int|null, active_members_7d: int|null} $block
     * @return array<string, mixed>
     */
    private static function groupData(array $block): array
    {
        return [
            'group_id' => self::GROUP, 'name' => 'Heat', 'slug' => 'heat', 'type' => 'user', 'privacy' => 'open',
            'member_count' => 12, 'description' => '', 'image_url' => null, 'verification' => null,
            'collection_stats' => null, 'chain_tag' => null, 'trust_min' => null, 'viewer_is_member' => false,
            'posts_last_7d' => $block['posts_last_7d'], 'active_members_7d' => $block['active_members_7d'],
        ];
    }

    private function addMissingPostColumns(): void
    {
        global $wpdb;
        foreach (['post_author' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0', 'post_date_gmt' => "DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00'"] as $column => $definition) {
            if ($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$wpdb->posts}` LIKE %s", $column)) === null) {
                self::assertNotFalse($wpdb->query("ALTER TABLE `{$wpdb->posts}` ADD COLUMN `{$column}` {$definition}"), $wpdb->last_error);
            }
        }
    }

    private function purge(): void
    {
        global $wpdb;
        $posts = implode(',', [self::POST_A, self::POST_B, self::POST_C]);
        $wpdb->query("DELETE FROM `" . TableRegistry::hiddenActivities() . "` WHERE act_id IN (SELECT act_id FROM `{$wpdb->prefix}peepso_activities` WHERE act_external_id IN ({$posts}))");
        $wpdb->query("DELETE FROM `{$wpdb->prefix}peepso_activities` WHERE act_external_id IN ({$posts})");
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$posts})");
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$posts})");
    }
}
