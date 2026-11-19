<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Log\Logger;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\REST\FeedEndpoint;
use BCC\Trust\Core\Services\Feed\FeedRankingService;
use BCC\Trust\Core\Services\MemberSelfPageService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PeepSoActivitiesTable.php';

/**
 * "My Groups" (F-416): `GET /feed?scope=groups`, proved on REAL ROWS.
 *
 * ⭐ The principle under test: the union of the viewer's own rooms, each post
 * under its ROOM's rules — never more and never less than opening each room
 * ({@see FeedRankingService::getGroupFeed()}) shows. So besides who is in and
 * who is out, one case compares the two directly, room by room.
 *
 * Real: the group, membership, block, profile-level and score rows, the
 * per-post markers, bcc-core's membership read and feed query, and the whole
 * FeedRankingService + FeedEndpoint path. Doubled: PeepSo's runtime classes
 * and the session identity only (page-posts-stubs.php).
 *
 * ⚠ Requires bcc-core with SCOPE_GROUPS and getActiveMemberGroupIdsOrFail(),
 * resolved from the adjacent checkout the harness autoloads.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(FeedRankingService::class)]
#[CoversClass(FeedEndpoint::class)]
final class MyGroupsFeedIntegrationTest extends TestCase
{
    private const VIEWER   = 5101;
    private const AUTHOR   = 5102;
    private const CAUTION  = 5103; // caution-tier author
    private const ONLY_ME  = 5104; // profile set to "only me" (level 40)
    private const BLOCKED  = 5105; // the viewer blocks them
    private const STRANGER = 5106; // in no group

    private const G_OPEN    = 95101;
    private const G_CLOSED  = 95102;
    private const G_SECRET  = 95103;
    private const G_HALL    = 95104;
    private const G_NFT     = 95105;
    private const G_OTHER   = 95106; // open, the viewer is NOT a member
    private const G_BANNED  = 95107; // a member row AND a banned row (F-354)
    private const G_PENDING = 95108; // pending only
    private const G_TRASHED = 95109; // member, but the group is trashed

    /** The membership candidate read in bcc-core. */
    private const MEMBERSHIP_READ = '/GROUP BY gm\.gm_group_id/';

    /** @var array<string, int> fixture name => wp_post id */
    private array $p = [];

    /** @var list<int> */
    private array $postIds = [];

    private int $clock = 0;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/page-posts-stubs.php';

        $GLOBALS['__bcc_actions']        = [];
        $GLOBALS['__bcc_throttle_calls'] = [];
        $GLOBALS['__bcc_current_user']   = self::VIEWER;
        Logger::reset();

        $this->buildTables();

        $this->insertGroup(self::G_OPEN, '0');
        $this->insertGroup(self::G_CLOSED, '1');
        $this->insertGroup(self::G_SECRET, '2');
        $this->insertGroup(self::G_HALL, '0', 'hall');
        $this->insertGroup(self::G_NFT, '1', 'holders');
        $this->insertGroup(self::G_OTHER, '0');
        $this->insertGroup(self::G_BANNED, '0');
        $this->insertGroup(self::G_PENDING, '1');
        $this->insertGroup(self::G_TRASHED, '0', null, 'trash');

        foreach ([self::G_OPEN, self::G_CLOSED, self::G_SECRET, self::G_HALL, self::G_NFT, self::G_BANNED, self::G_TRASHED] as $g) {
            $this->joinGroup(self::VIEWER, $g, 'member');
        }
        $this->joinGroup(self::VIEWER, self::G_BANNED, 'banned');
        $this->joinGroup(self::VIEWER, self::G_PENDING, 'pending_user');

        $this->p['open']        = $this->post(self::AUTHOR, self::G_OPEN, 'members_only');
        $this->p['closed']      = $this->post(self::AUTHOR, self::G_CLOSED, 'members_only');
        $this->p['secret']      = $this->post(self::AUTHOR, self::G_SECRET, null); // absent meta = members_only
        $this->p['hall']        = $this->post(self::AUTHOR, self::G_HALL, 'public_group');
        $this->p['hallRanked']  = $this->post(self::AUTHOR, self::G_HALL, 'public_group', ranked: true);
        $this->p['nft']         = $this->post(self::AUTHOR, self::G_NFT, 'members_only');
        $this->p['other']       = $this->post(self::AUTHOR, self::G_OTHER, 'public_all');
        $this->p['banned']      = $this->post(self::AUTHOR, self::G_BANNED, 'public_all');
        $this->p['pending']     = $this->post(self::AUTHOR, self::G_PENDING, 'public_all');
        $this->p['trashed']     = $this->post(self::AUTHOR, self::G_TRASHED, 'public_all');
        $this->p['noGroup']     = $this->post(self::AUTHOR, null, null);
        $this->p['blocked']     = $this->post(self::BLOCKED, self::G_OPEN, 'members_only');
        $this->p['cautionOpen'] = $this->post(self::CAUTION, self::G_OPEN, 'members_only');
        $this->p['cautionNft']  = $this->post(self::CAUTION, self::G_NFT, 'members_only');
        $this->p['onlyMe']      = $this->post(self::ONLY_ME, self::G_OPEN, 'members_only');
        $this->p['dual']        = $this->post(self::AUTHOR, self::G_OPEN, 'members_only', alsoIn: self::G_OTHER);

        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_blocks', ['blk_user_id' => self::VIEWER, 'blk_blocked_id' => self::BLOCKED]), $wpdb->last_error);
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_users', ['usr_id' => self::ONLY_ME, 'usr_profile_acc' => 40]), $wpdb->last_error);
        $this->markCaution(self::CAUTION);

        PeepSoGroupRepository::bustNonOpenGroupIdsCache();
    }

    /**
     * Deletes only this suite's rows, by their own ids; the schema is left
     * alone (see BlogTabGroupVisibilityIntegrationTest::tearDown()).
     */
    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->clearFaultInjection();

        $postIds = array_merge($this->postIds, [
            self::G_OPEN, self::G_CLOSED, self::G_SECRET, self::G_HALL, self::G_NFT,
            self::G_OTHER, self::G_BANNED, self::G_PENDING, self::G_TRASHED,
        ]);
        $in = implode(',', array_map('intval', $postIds));
        $wpdb->query("DELETE FROM {$wpdb->prefix}peepso_activities WHERE act_external_id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})");

        $users = implode(',', [self::VIEWER, self::AUTHOR, self::CAUTION, self::ONLY_ME, self::BLOCKED, self::STRANGER]);
        $wpdb->query("DELETE FROM {$wpdb->prefix}peepso_group_members WHERE gm_user_id IN ({$users})");
        $wpdb->query("DELETE FROM {$wpdb->prefix}peepso_blocks WHERE blk_user_id IN ({$users}) OR blk_blocked_id IN ({$users})");
        $wpdb->query("DELETE FROM {$wpdb->prefix}peepso_users WHERE usr_id IN ({$users})");
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bcc_trust_page_scores WHERE page_id = %d",
            MemberSelfPageService::ID_BASE + self::CAUTION
        ));

        PeepSoGroupRepository::bustNonOpenGroupIdsCache();
        unset($GLOBALS['__bcc_current_user']);
    }

    // ── who is in, who is out ───────────────────────────────────────────

    public function testEveryRoomTheViewerIsAnActiveMemberOfAndNothingElse(): void
    {
        $ids = $this->myGroups(self::VIEWER);

        foreach (['open', 'closed', 'secret', 'hall', 'hallRanked', 'nft', 'onlyMe', 'cautionNft', 'dual'] as $in) {
            self::assertContains($this->p[$in], $ids, "'{$in}' must be in My Groups");
        }
        foreach ([
            'other'   => 'a room the viewer did not join, even a public_all post',
            'banned'  => 'a ban beside a stale member row wins (F-354)',
            'pending' => 'a pending request is not membership',
            'trashed' => 'a trashed group is not a room',
            'noGroup' => 'a post in no group belongs to the mixed feed',
        ] as $out => $why) {
            self::assertNotContains($this->p[$out], $ids, "'{$out}': {$why}");
        }
    }

    /** Members-only posts belong to members: the scope reads as a member. */
    public function testMembersOnlyPostsAreTheMembersAndAbsentMetaCountsAsMembersOnly(): void
    {
        $ids = $this->myGroups(self::VIEWER);

        self::assertContains($this->p['open'], $ids, 'members_only');
        self::assertContains($this->p['secret'], $ids, 'absent visibility meta, in a secret room');
        self::assertNotContains($this->p['open'], $this->forYou(self::VIEWER), 'control: the mixed feed does not show it');
    }

    public function testAViewerInNoGroupGetsAnEmptyPageNotAnError(): void
    {
        $GLOBALS['__bcc_current_user'] = self::STRANGER;
        $response = (new FeedEndpoint())->feed(new \WP_REST_Request(['scope' => 'groups']));

        self::assertSame(200, $response->get_status());
        $data = $response->get_data()['data'];
        self::assertSame([], $data['items']);
        self::assertFalse($data['pagination']['has_more']);
        self::assertNull($data['pagination']['next_cursor']);
    }

    public function testABlockHoldsInsideASharedRoom(): void
    {
        self::assertNotContains($this->p['blocked'], $this->myGroups(self::VIEWER));
        self::assertNotContains($this->p['blocked'], $this->room(self::G_OPEN), 'control: the room hides them too');
    }

    /**
     * Ratified 2026-11-18: §F-02 does not decide a group post in a room the
     * viewer belongs to. An "only me" author's room post shows in the room
     * AND in My Groups, and the mixed feed still withholds it.
     */
    public function testAnOnlyMeAuthorsRoomPostShowsInTheRoomAndInMyGroups(): void
    {
        self::assertContains($this->p['onlyMe'], $this->room(self::G_OPEN), 'the room shows it');
        self::assertContains($this->p['onlyMe'], $this->myGroups(self::VIEWER), 'so My Groups does');
    }

    /** The shadow-limit follows the room: lifted in the NFT holder room, kept in the open one. */
    public function testTheShadowLimitFollowsEachRoom(): void
    {
        $mine = $this->myGroups(self::VIEWER);

        self::assertNotContains($this->p['cautionOpen'], $mine);
        self::assertNotContains($this->p['cautionOpen'], $this->room(self::G_OPEN), 'the open room hides it too');
        self::assertContains($this->p['cautionNft'], $mine);
        self::assertContains($this->p['cautionNft'], $this->room(self::G_NFT), 'the NFT room shows it too');
    }

    /**
     * ⭐ THE PRINCIPLE, room by room: My Groups is exactly the union of what
     * opening each of the viewer's rooms shows (both Hall channels).
     */
    public function testMyGroupsIsExactlyTheUnionOfTheViewersRooms(): void
    {
        $union = [];
        foreach ([self::G_OPEN, self::G_CLOSED, self::G_SECRET, self::G_HALL, self::G_NFT] as $g) {
            $union = array_merge($union, $this->room($g), $this->room($g, 'ranked'));
        }
        $union = array_values(array_unique($union));
        sort($union);

        $mine = $this->myGroups(self::VIEWER);
        sort($mine);

        self::assertNotSame([], $mine, 'precondition: the fixture puts posts in the rooms');
        self::assertSame($union, $mine);
    }

    // ── the endpoint ────────────────────────────────────────────────────

    public function testTheEndpointServesTheScopeSignedInOnly(): void
    {
        $ok = (new FeedEndpoint())->feed(new \WP_REST_Request(['scope' => 'groups']));
        self::assertSame(200, $ok->get_status());
        self::assertContains($this->p['closed'], $this->postIdsOf($ok->get_data()['data']['items']));

        $GLOBALS['__bcc_current_user'] = 0;
        $anon = (new FeedEndpoint())->feed(new \WP_REST_Request(['scope' => 'groups']));
        self::assertSame(401, $anon->get_status());
    }

    /** "Try again", never "you are in no group". */
    public function testAFailedMembershipReadIsARetryable503(): void
    {
        global $wpdb;
        $wpdb->failQueriesMatching = self::MEMBERSHIP_READ;

        $response = (new FeedEndpoint())->feed(new \WP_REST_Request(['scope' => 'groups']));

        self::assertSame(1, $wpdb->injectedFailures, 'precondition: the membership read was the query failed');
        self::assertSame(503, $response->get_status());
        self::assertSame('bcc_unavailable', $response->get_data()['error']['code'] ?? $response->get_data()['code'] ?? null);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return list<int> */
    private function myGroups(int $viewerId): array
    {
        $GLOBALS['__bcc_current_user'] = $viewerId;
        return $this->postIdsOf(Plugin::instance()->feedRankingService()->getFeed($viewerId, 'groups', null, 50)['items']);
    }

    /** @return list<int> */
    private function forYou(int $viewerId): array
    {
        $GLOBALS['__bcc_current_user'] = $viewerId;
        return $this->postIdsOf(Plugin::instance()->feedRankingService()->getFeed($viewerId, 'for_you', null, 50)['items']);
    }

    /** What opening the room shows a member. @return list<int> */
    private function room(int $groupId, string $hallFeed = 'main'): array
    {
        $GLOBALS['__bcc_current_user'] = self::VIEWER;
        return $this->postIdsOf(
            Plugin::instance()->feedRankingService()->getGroupFeed(self::VIEWER, $groupId, null, 50, false, $hallFeed)['items']
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<int>
     */
    private function postIdsOf(array $items): array
    {
        global $wpdb;
        $ids = [];
        foreach ($items as $item) {
            $actId  = (int) substr((string) $item['id'], strlen('feed_'));
            $postId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT act_external_id FROM {$wpdb->prefix}peepso_activities WHERE act_id = %d",
                $actId
            ));
            if ($postId > 0) {
                $ids[] = $postId;
            }
        }
        return $ids;
    }

    private function insertGroup(int $groupId, string $privacy, ?string $kind = null, string $status = 'publish'): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->posts} (ID, post_type, post_status, post_name, post_title, post_author)
             VALUES (%d, 'peepso-group', %s, %s, %s, 0)",
            $groupId,
            $status,
            'g-' . $groupId,
            'Group ' . $groupId
        )), 'group fixture: ' . $wpdb->last_error);

        update_post_meta($groupId, 'peepso_group_privacy', $privacy);
        if ($kind !== null) {
            update_post_meta($groupId, '_bcc_group_kind', $kind);
        }
    }

    private function joinGroup(int $userId, int $groupId, string $status): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}peepso_group_members (gm_group_id, gm_user_id, gm_user_status, gm_joined)
             VALUES (%d, %d, %s, '2026-01-01 00:00:00')",
            $groupId,
            $userId,
            $status
        )), 'membership fixture: ' . $wpdb->last_error);
    }

    /** A published status post, optionally in a group. Returns its wp_post id. */
    private function post(int $author, ?int $groupId, ?string $visibility, bool $ranked = false, ?int $alsoIn = null): int
    {
        global $wpdb;
        $this->clock++;

        self::assertNotFalse($wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->posts}
                (post_type, post_status, post_name, post_title, post_author, post_excerpt, post_content, post_date_gmt)
             VALUES ('peepso-post', 'publish', %s, '', %d, '', %s, %s)",
            'mg-' . $this->clock,
            $author,
            'My Groups fixture ' . $this->clock,
            gmdate('Y-m-d H:i:s', strtotime('2026-03-01 00:00:00') + $this->clock * 60)
        )), 'post fixture: ' . $wpdb->last_error);
        $postId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $postId);
        $this->postIds[] = $postId;

        // Raw rows, so a second `peepso_group_id` row (the dual-tag fixture)
        // is a real duplicate key, as PeepSo would leave it.
        foreach ([$groupId, $alsoIn] as $tag) {
            if ($tag !== null) {
                self::assertNotFalse($wpdb->insert($wpdb->postmeta, [
                    'post_id'    => $postId,
                    'meta_key'   => 'peepso_group_id',
                    'meta_value' => (string) $tag,
                ]), 'group tag fixture: ' . $wpdb->last_error);
            }
        }
        if ($visibility !== null) {
            update_post_meta($postId, '_bcc_post_visibility', $visibility);
        }
        if ($ranked) {
            update_post_meta($postId, '_bcc_ranked_feed', '1');
        }

        self::assertNotFalse($wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}peepso_activities
                (act_owner_id, act_module_id, act_external_id, act_access, act_comment_object_id)
             VALUES (%d, 1, %d, 0, 0)",
            $author,
            $postId
        )), 'activity fixture: ' . $wpdb->last_error);

        return $postId;
    }

    /** A caution-tier member, the way ReputationRepository reads it: their self-page score row. */
    private function markCaution(int $userId): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_page_scores', [
            'page_id'         => MemberSelfPageService::ID_BASE + $userId,
            'category_id'     => 0,
            'page_owner_id'   => $userId,
            'reputation_tier' => 'caution',
        ]), 'score fixture: ' . $wpdb->last_error);
    }

    private function buildTables(): void
    {
        global $wpdb;

        $wanted = [
            'post_author'   => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'post_content'  => 'LONGTEXT NULL',
            'post_excerpt'  => 'TEXT NULL',
            'post_date_gmt' => "DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00'",
        ];
        $existing = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $wpdb->posts
        ));
        $adds = [];
        foreach ($wanted as $column => $definition) {
            if (!in_array($column, $existing, true)) {
                $adds[] = "ADD COLUMN {$column} {$definition}";
            }
        }
        $statements = [];
        if ($adds !== []) {
            $statements[] = "ALTER TABLE {$wpdb->posts} " . implode(', ', $adds);
        }
        $statements[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}peepso_blocks (
            blk_user_id BIGINT UNSIGNED NOT NULL,
            blk_blocked_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (blk_user_id, blk_blocked_id)
        ) DEFAULT CHARSET=utf8mb4";
        $statements[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}peepso_users (
            usr_id BIGINT UNSIGNED NOT NULL,
            usr_profile_acc TINYINT NOT NULL DEFAULT 10,
            PRIMARY KEY (usr_id)
        ) DEFAULT CHARSET=utf8mb4";
        $statements[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}peepso_group_members (
            gm_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            gm_group_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            gm_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            gm_user_status VARCHAR(32) NOT NULL DEFAULT 'member',
            gm_joined DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00',
            PRIMARY KEY (gm_id)
        ) DEFAULT CHARSET=utf8mb4";
        foreach ($statements as $sql) {
            self::assertNotFalse($wpdb->query($sql), 'fixture: ' . $wpdb->last_error);
        }
        PeepSoActivitiesTable::ensure();

        require_once dirname(__DIR__, 2) . '/includes/database/schema-hidden-activities.php';
        bcc_trust_create_hidden_activities_table();
    }
}
