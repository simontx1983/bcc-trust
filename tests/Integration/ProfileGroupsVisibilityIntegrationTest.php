<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\REST\UserGroupsEndpoint;
use BCC\Trust\Core\REST\UsersEndpoint;
use BCC\Trust\Core\Support\ProfileGroupVisibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-330: the two profile surfaces that publish a member's groups, END TO END
 * through their REST handlers on real rows:
 *
 *   - GET /users/{slug}/groups  (UserGroupsEndpoint::getList, the Groups tab)
 *   - GET /users/{handle}       (UsersEndpoint::getUser → the `halls` field)
 *
 * F-323 (High) survived because each layer was tested alone: the rule had
 * unit coverage, the wiring a static check, and nothing read either route
 * over real group, meta and membership rows. Here the groups are real
 * `peepso-group` posts, their privacy and kind are real post meta, the
 * memberships are real `peepso_group_members` rows, and the assertion is on
 * WHICH GROUP IDS each route returns.
 *
 * The oracle is the user-decided matrix ProfileGroupVisibility documents
 * (2026-11-12), restated per viewer below:
 *   - the owner and an admin see everything;
 *   - SECRET groups only to fellow members, never to anonymous viewers;
 *   - NFT-holder and delegator groups only to signed-in viewers;
 *   - "show my groups" OFF: other viewers see halls only.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(UserGroupsEndpoint::class)]
#[CoversClass(UsersEndpoint::class)]
#[CoversClass(ProfileGroupVisibility::class)]
final class ProfileGroupsVisibilityIntegrationTest extends TestCase
{
    private const OWNER    = 933001;
    private const STRANGER = 933002; // signed in, in none of the owner's groups
    private const FELLOW   = 933003; // signed in, a member of the owner's SECRET group
    private const ADMIN    = 933004;

    private const G_OPEN      = 933101;
    private const G_CLOSED    = 933102;
    private const G_SECRET    = 933103;
    private const G_HOLDERS   = 933104; // NFT-holder group
    private const G_DELEGATOR = 933105;
    private const G_HALL      = 933106;

    private const ALL = [self::G_OPEN, self::G_CLOSED, self::G_SECRET, self::G_HOLDERS, self::G_DELEGATOR, self::G_HALL];

    private bool $membersWasOurs = false;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-groups-integration-stubs.php';

        $GLOBALS['__bcc_pg'] = [
            'viewer' => 0,
            'users'  => [],
            'meta'   => [],
        ];
        foreach ([self::OWNER => 'owner', self::STRANGER => 'stranger', self::FELLOW => 'fellow', self::ADMIN => 'admin'] as $id => $name) {
            $GLOBALS['__bcc_pg']['users'][$id] = ['login' => 'f330_' . $name, 'handle' => 'f330' . $name, 'name' => 'F330 ' . $name];
            $GLOBALS['__bcc_pg']['meta'][$id]  = ['bcc_handle' => 'f330' . $name];
        }
        $GLOBALS['__bcc_test_caps'] = [];

        // The REAL bcc-trust TrustReadService answers bcc-core's suspension
        // check (over the real user_info table: no row = not suspended).
        // The harness's apply_filters is a pass-through, so bcc-core would
        // otherwise resolve its NULL service, which fails closed and reports
        // every profile owner as suspended: every non-owner, non-admin read
        // would 403 as `profile_private` before the group rule ran.
        $locatorCache = new \ReflectionProperty(\BCC\Core\ServiceLocator::class, 'cache');
        $resolved     = (array) $locatorCache->getValue();
        $resolved['bcc.resolve.trust_read_service'] = \BCC\Trust\Core\Plugin::instance()->trustReadService();
        $locatorCache->setValue(null, $resolved);

        $this->ensureMembersTable();
        $this->purge();

        $this->group(self::G_OPEN, 'open', '', 0);
        $this->group(self::G_CLOSED, 'closed', '', 1);
        $this->group(self::G_SECRET, 'secret', '', 2);
        $this->group(self::G_HOLDERS, 'holders', 'holders', 0);
        $this->group(self::G_DELEGATOR, 'delegators', 'delegators', 0);
        $this->group(self::G_HALL, 'hall', 'hall', 0);

        foreach (self::ALL as $groupId) {
            $this->join(self::OWNER, $groupId);
        }
        $this->join(self::FELLOW, self::G_SECRET);
    }

    protected function tearDown(): void
    {
        $this->purge();
        if ($this->membersWasOurs) {
            global $wpdb;
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}peepso_group_members`");
        }
        unset($GLOBALS['__bcc_pg'], $GLOBALS['__bcc_test_caps']);
    }

    // ── the matrix ──────────────────────────────────────────────────────

    /** @return array<string, array{int, list<int>, bool}> viewer, expected ids, admin? */
    public static function viewers(): array
    {
        $all = self::ALL;
        sort($all);

        return [
            'the owner'                     => [self::OWNER, $all, false],
            'a signed-in non-member'        => [self::STRANGER, [self::G_OPEN, self::G_CLOSED, self::G_HOLDERS, self::G_DELEGATOR, self::G_HALL], false],
            'a fellow secret-group member'  => [self::FELLOW, $all, false],
            'an anonymous caller'           => [0, [self::G_OPEN, self::G_CLOSED, self::G_HALL], false],
            'an admin'                      => [self::ADMIN, $all, true],
        ];
    }

    /** @param list<int> $expected */
    #[DataProvider('viewers')]
    public function testTheGroupsTabShowsWhatTheMatrixAllows(int $viewer, array $expected, bool $admin): void
    {
        $this->actAs($viewer, $admin);

        self::assertSame($expected, $this->groupsTabIds());
    }

    /** @param list<int> $expected */
    #[DataProvider('viewers')]
    public function testTheProfileHallsFieldShowsWhatTheMatrixAllows(int $viewer, array $expected, bool $admin): void
    {
        $this->actAs($viewer, $admin);

        self::assertSame($expected, $this->hallsFieldIds());
    }

    // ── "show my groups on my profile" OFF ──────────────────────────────

    public function testWithTheToggleOffOthersSeeHallsOnlyOnBothSurfaces(): void
    {
        $GLOBALS['__bcc_pg']['meta'][self::OWNER][ProfileGroupVisibility::META_SHOW_GROUPS] = '0';

        foreach ([self::STRANGER, self::FELLOW, 0] as $viewer) {
            $this->actAs($viewer, false);
            self::assertSame([self::G_HALL], $this->groupsTabIds(), "groups tab, viewer {$viewer}");
            self::assertSame([self::G_HALL], $this->hallsFieldIds(), "halls field, viewer {$viewer}");
        }

        $all = self::ALL;
        sort($all);
        $this->actAs(self::OWNER, false);
        self::assertSame($all, $this->groupsTabIds(), 'the owner still sees what the toggle hides');
        self::assertSame($all, $this->hallsFieldIds());
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function actAs(int $viewer, bool $admin): void
    {
        $GLOBALS['__bcc_pg']['viewer'] = $viewer;
        $GLOBALS['__bcc_test_caps']    = $admin ? ['manage_options'] : [];
    }

    /** @return list<int> */
    private function groupsTabIds(): array
    {
        $response = (new UserGroupsEndpoint())->getList(new \WP_REST_Request(['slug' => 'f330owner']));
        self::assertSame(200, $response->get_status(), (string) json_encode($response->get_data()));

        $data  = $response->get_data();
        $items = $data['data']['items'] ?? $data['data'] ?? [];
        self::assertIsArray($items, (string) json_encode($data));

        $ids = array_map(static fn (array $i): int => (int) $i['group_id'], $items);
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    private function hallsFieldIds(): array
    {
        $response = (new UsersEndpoint())->getUser(new \WP_REST_Request(['handle' => 'f330owner']));
        self::assertSame(200, $response->get_status(), (string) json_encode($response->get_data()));

        $data  = $response->get_data();
        $halls = $data['data']['halls'] ?? null;
        self::assertIsArray($halls, 'the user view-model carries `halls`: ' . (string) json_encode(array_keys((array) ($data['data'] ?? []))));

        $ids = array_map(static fn (array $h): int => (int) ($h['id'] ?? $h['group_id'] ?? 0), $halls);
        sort($ids);

        return $ids;
    }

    private function group(int $id, string $slug, string $kind, int $privacy): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->posts, [
            'ID' => $id, 'post_type' => 'peepso-group', 'post_status' => 'publish',
            'post_title' => 'F330 ' . $slug, 'post_name' => 'f330-' . $slug,
        ]), $wpdb->last_error);
        update_post_meta($id, 'peepso_group_privacy', (string) $privacy);
        if ($kind !== '') {
            update_post_meta($id, '_bcc_group_kind', $kind);
        }
    }

    private function join(int $userId, int $groupId): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_group_members', [
            'gm_user_id' => $userId, 'gm_group_id' => $groupId, 'gm_user_status' => 'member',
        ]), $wpdb->last_error);
    }

    private function ensureMembersTable(): void
    {
        global $wpdb;
        $members = $wpdb->prefix . 'peepso_group_members';
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $members)) !== $members) {
            // PeepSo-owned table, declared in the same IF NOT EXISTS +
            // backticked form the other PeepSo fixtures use.
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}peepso_group_members` (
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
        $in = implode(',', array_map('intval', self::ALL));
        $wpdb->query("DELETE FROM `{$wpdb->prefix}peepso_group_members` WHERE gm_group_id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})");
    }
}
