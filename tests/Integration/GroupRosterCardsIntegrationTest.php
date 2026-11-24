<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\GroupMembersService;
use BCC\Trust\Core\Support\MemberCardPrefetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * §4.7.7 `card` on GET /groups/{id}/members: each roster item carries the
 * member Card the /members directory shows, built for the viewer by the
 * SAME builder from the SAME prefetch, or `null` where the directory would
 * not show this viewer that member (ProfileAccess::filterVisible: a private
 * profile, a suspended account, a block). The tile fields stay either way.
 *
 * Real groups (`peepso-group` posts and privacy meta), real memberships and
 * the real service, repositories and card builder. Doubled: the session and
 * user lookups (profile-groups-integration-stubs.php) and PeepSo's privacy
 * search (filter-visible-stubs.php: `$GLOBALS['__bcc_fv']['visible']` are
 * the members PeepSo's privacy SQL would admit).
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(GroupMembersService::class)]
final class GroupRosterCardsIntegrationTest extends TestCase
{
    private const OWNER  = 956801;
    private const VIEWER = 956802; // a plain member of every fixture group
    private const HIDDEN = 956803; // a member the viewer may not see (PeepSo's privacy SQL leaves them out)
    /** Plain members: 956811.. */
    private const MEMBER_BASE = 956810;

    private const G_CLOSED = 956901;
    private const G_OPEN   = 956902;
    private const G_WARM   = 956903;
    private const G_FOUR   = 956904;
    private const G_EIGHT  = 956905;
    private const GROUPS   = [self::G_CLOSED, self::G_OPEN, self::G_WARM, self::G_FOUR, self::G_EIGHT];

    private bool $membersWasOurs = false;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-groups-integration-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/member-lists-integration-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/filter-visible-stubs.php';
        require_once dirname(__DIR__, 2) . '/includes/database/schema-trust-attestations.php';

        global $wpdb;
        $GLOBALS['__bcc_pg'] = ['viewer' => self::VIEWER, 'users' => [], 'meta' => []];
        $everyone = [self::OWNER, self::VIEWER, self::HIDDEN];
        for ($i = 1; $i <= 15; $i++) {
            $everyone[] = self::MEMBER_BASE + $i;
        }
        foreach ($everyone as $id) {
            $GLOBALS['__bcc_pg']['users'][$id] = ['login' => 'roster_' . $id, 'handle' => 'roster' . $id, 'name' => 'Roster ' . $id];
            $GLOBALS['__bcc_pg']['meta'][$id]  = ['bcc_handle' => 'roster' . $id];
        }
        $GLOBALS['__bcc_fv'] = [
            'visible'     => array_fill_keys(array_values(array_diff($everyone, [self::HIDDEN])), true),
            'admin'       => false,
            'constructed' => [],
        ];
        $GLOBALS['__bcc_test_caps']         = [];
        $GLOBALS['__bcc_test_object_cache'] = [];
        $wpdb->clearFaultInjection();

        // The REAL TrustReadService answers bcc-core's suspension check, as in
        // ProfileGroupsVisibilityIntegrationTest.
        $locatorCache = new \ReflectionProperty(\BCC\Core\ServiceLocator::class, 'cache');
        $resolved     = (array) $locatorCache->getValue();
        $resolved['bcc.resolve.trust_read_service'] = Plugin::instance()->trustReadService();
        $locatorCache->setValue(null, $resolved);

        bcc_trust_create_trust_attestations_table();
        $this->ensureMembersTable();
        $this->purge();

        $this->group(self::G_CLOSED, 1);
        $this->group(self::G_OPEN, 0);
        foreach ([self::G_CLOSED, self::G_OPEN] as $groupId) {
            $this->join(self::OWNER, $groupId, 'member_owner');
            $this->join(self::VIEWER, $groupId);
            $this->join(self::HIDDEN, $groupId);
            $this->join(self::MEMBER_BASE + 1, $groupId);
        }
        // Disjoint members per measured group, so no per-member memo carries over.
        foreach ([self::G_WARM => [2, 3], self::G_FOUR => [4, 5, 6, 7], self::G_EIGHT => [8, 9, 10, 11, 12, 13, 14, 15]] as $groupId => $members) {
            $this->group($groupId, 0);
            foreach ($members as $n) {
                $this->join(self::MEMBER_BASE + $n, $groupId);
            }
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
        if ($this->membersWasOurs) {
            global $wpdb;
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}peepso_group_members`");
        }
        unset($GLOBALS['__bcc_pg'], $GLOBALS['__bcc_fv'], $GLOBALS['__bcc_test_caps']);
    }

    /**
     * Every visible member's `card` is exactly the directory's card for this
     * viewer (the same builder over the same prefetch); the member the viewer
     * may not see keeps the tile and gets `card: null`. A closed roster shows
     * its members every tile, so this is the card gate alone.
     */
    public function testEachCardIsTheDirectorysCardAndAHiddenMemberHasNone(): void
    {
        $items = $this->roster(self::VIEWER, self::G_CLOSED);
        $ids   = array_map(static fn (array $item): int => (int) $item['id'], $items);
        sort($ids);
        self::assertSame([self::OWNER, self::VIEWER, self::HIDDEN, self::MEMBER_BASE + 1], $ids, 'every tile of a closed roster');

        $prefetched = MemberCardPrefetcher::primeFor($ids, self::VIEWER);
        $cards      = Plugin::instance()->cardViewService();
        foreach ($items as $item) {
            $id = (int) $item['id'];
            self::assertArrayHasKey('card', $item, "member {$id}: the key is always present");
            self::assertSame(['role', 'role_label'], array_values(array_intersect(['role', 'role_label'], array_keys($item))), "member {$id}: the tile fields stay");
            if ($id === self::HIDDEN) {
                self::assertNull($item['card'], 'the directory would not show this viewer this member');
                self::assertSame('roster' . self::HIDDEN, $item['handle'] ?? null, 'the tile stays');
                continue;
            }
            self::assertSame($cards->getMemberCardForList($id, self::VIEWER, $prefetched), $item['card'], "member {$id}: the directory's card");
            self::assertSame('member', $item['card']['card_kind'] ?? null);
        }
    }

    /**
     * An open roster leaves out the member this viewer may not see, as before;
     * the group's OWNER sees that member's tile but, like in the directory,
     * not their card.
     */
    public function testAnOpenRostersOwnerSeesEveryTileButOnlyTheCardsTheDirectoryWouldShow(): void
    {
        $asMember = array_map(static fn (array $item): int => (int) $item['id'], $this->roster(self::VIEWER, self::G_OPEN));
        self::assertNotContains(self::HIDDEN, $asMember, 'an open roster leaves the member out, unchanged');

        $GLOBALS['__bcc_pg']['viewer'] = self::OWNER;
        $byId = [];
        foreach ($this->roster(self::OWNER, self::G_OPEN) as $item) {
            $byId[(int) $item['id']] = $item;
        }
        self::assertArrayHasKey(self::HIDDEN, $byId, "the group's owner sees every tile");
        self::assertNull($byId[self::HIDDEN]['card']);
        self::assertSame('member', $byId[self::VIEWER]['card']['card_kind'] ?? null);
    }

    /**
     * Bounded: the cards add no per-member query. A page of eight members
     * costs exactly what a page of four does (after one warm-up page, so a
     * first-call read is not counted against either).
     */
    public function testAPageOfEightCostsWhatAPageOfFourDoes(): void
    {
        global $wpdb;
        $this->roster(self::VIEWER, self::G_WARM);

        $wpdb->queryCount = 0;
        $four             = $this->roster(self::VIEWER, self::G_FOUR);
        $fourQueries      = $wpdb->queryCount;

        $wpdb->queryCount = 0;
        $eight            = $this->roster(self::VIEWER, self::G_EIGHT);
        $eightQueries     = $wpdb->queryCount;

        self::assertCount(4, $four);
        self::assertCount(8, $eight);
        foreach ([...$four, ...$eight] as $item) {
            self::assertSame('member', $item['card']['card_kind'] ?? null, 'every visible member has a card');
        }
        self::assertSame($fourQueries, $eightQueries, "queries per page: {$fourQueries} for 4 members, {$eightQueries} for 8");
        fwrite(STDERR, "\n[roster cards] queries per page: {$fourQueries} (4 members), {$eightQueries} (8 members)\n");
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function roster(int $viewer, int $groupId): array
    {
        $GLOBALS['__bcc_pg']['viewer'] = $viewer;
        $out = Plugin::instance()->groupMembersService()->listMembers($viewer, $groupId, 0, 24);
        self::assertIsArray($out);
        self::assertArrayHasKey('items', $out, (string) json_encode($out));

        return $out['items'];
    }

    private function group(int $id, int $privacy): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->posts, [
            'ID' => $id, 'post_type' => 'peepso-group', 'post_status' => 'publish',
            'post_title' => 'Roster ' . $id, 'post_name' => 'roster-' . $id,
        ]), $wpdb->last_error);
        update_post_meta($id, 'peepso_group_privacy', (string) $privacy);
    }

    private function join(int $userId, int $groupId, string $status = 'member'): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_group_members', [
            'gm_user_id' => $userId, 'gm_group_id' => $groupId, 'gm_user_status' => $status,
            'gm_joined'  => gmdate('Y-m-d H:i:s', time() - 60 * ($userId % 100)),
        ]), $wpdb->last_error);
    }

    private function ensureMembersTable(): void
    {
        global $wpdb;
        $members = $wpdb->prefix . 'peepso_group_members';
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $members)) !== $members) {
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE IF NOT EXISTS `{$members}` (
                    gm_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    gm_user_id BIGINT UNSIGNED NOT NULL,
                    gm_group_id BIGINT UNSIGNED NOT NULL,
                    gm_user_status VARCHAR(32) NOT NULL DEFAULT 'member',
                    gm_joined DATETIME NULL,
                    PRIMARY KEY (gm_id)
                )"
            ), $wpdb->last_error);
            $this->membersWasOurs = true;
        }
    }

    private function purge(): void
    {
        global $wpdb;
        $in = implode(',', self::GROUPS);
        $wpdb->query("DELETE FROM `{$wpdb->prefix}peepso_group_members` WHERE gm_group_id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})");
    }
}
