<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\REST\GroupsDiscoveryEndpoint;
use BCC\Trust\Core\Services\HallsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Structure A, route map rows 13 and 15: the optional name `q` on
 * GET /groups and GET /halls, on a real server.
 *
 * ⭐ A name never finds a secret group: not in the browse list, and not
 * through `mine`, even when the viewer IS a member of the secret group.
 * The match rides on bcc-core's browsable read, in the same statement as
 * the shared secret exclusion; this runs that statement on the engine,
 * through the endpoint's own candidate step.
 *
 * And a Halls page and its total take the same name, so they agree.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(GroupsDiscoveryEndpoint::class)]
#[CoversClass(HallsService::class)]
final class GroupNameFilterIntegrationTest extends TestCase
{
    private const VIEWER = 995501;

    private const ALPHA_OPEN      = 995101;
    private const ALPHA_CLOSED    = 995102;
    private const ALPHA_SECRET    = 995103;
    private const ALPHA_TWO_ROWS  = 995104;   // privacy rows '0' then '2': secret
    private const BETA_OPEN       = 995105;
    private const PERCENT_CLUB    = 995106;

    /** @var array<int, array{string, list<string>}> group => [title, privacy rows] */
    private const GROUPS = [
        self::ALPHA_OPEN     => ['Alpha Open', ['0']],
        self::ALPHA_CLOSED   => ['Alpha Closed', ['1']],
        self::ALPHA_SECRET   => ['Alpha Secret', ['2']],
        self::ALPHA_TWO_ROWS => ['Alpha Two Rows', ['0', '2']],
        self::BETA_OPEN      => ['Beta Open', ['0']],
        self::PERCENT_CLUB   => ['50% Off Club', ['0']],
    ];

    /** @var array<int, string> hall => title (no privacy row: browsable) */
    private const HALLS = [
        995201 => 'Akash Hall',
        995202 => 'Atom Hall',
        995203 => 'Juno Hall',
    ];

    /** The viewer is a member of these, secret ones included. */
    private const MEMBERSHIPS = [self::ALPHA_OPEN, self::ALPHA_SECRET, self::ALPHA_TWO_ROWS, self::BETA_OPEN];

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}peepso_group_members` (
                gm_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                gm_user_id BIGINT UNSIGNED NOT NULL,
                gm_group_id BIGINT UNSIGNED NOT NULL,
                gm_user_status VARCHAR(32) NOT NULL DEFAULT 'member',
                gm_joined DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (gm_id)
            )"
        );
        $this->purge();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->purge();
    }

    /** @param list<int> $ids */
    private static function sorted(array $ids): array
    {
        sort($ids);
        return $ids;
    }

    // ── GET /groups: the candidate step ────────────────────────────────

    public function testANameFindsOpenAndClosedGroupsNeverASecretOne(): void
    {
        global $wpdb;

        $found = GroupsDiscoveryEndpoint::candidateGroupIds(0, false, 'alpha');

        self::assertSame('', (string) $wpdb->last_error);
        self::assertSame([self::ALPHA_OPEN, self::ALPHA_CLOSED], self::sorted($found));
        self::assertSame($found, GroupsDiscoveryEndpoint::candidateGroupIds(0, false, 'ALPHA'), 'case does not matter');
    }

    /**
     * ⭐ The viewer is a member of both secret Alpha groups (anti-vacuity:
     * the membership read returns them), and `mine` with a name still never
     * finds them.
     */
    public function testMineWithANameNeverFindsTheViewersSecretMemberships(): void
    {
        $memberships = PeepSoGroupRepository::getUserMemberGroupIds(self::VIEWER, 500);
        self::assertContains(self::ALPHA_SECRET, $memberships, 'precondition: a member of the secret group');
        self::assertContains(self::ALPHA_TWO_ROWS, $memberships, 'precondition: a member of the two-row secret group');

        self::assertSame([self::ALPHA_OPEN], GroupsDiscoveryEndpoint::candidateGroupIds(self::VIEWER, true, 'alpha'));
        self::assertSame(
            [self::ALPHA_OPEN, self::BETA_OPEN],
            self::sorted(GroupsDiscoveryEndpoint::candidateGroupIds(self::VIEWER, true, '')),
            'without a name, mine is the browsable memberships'
        );
    }

    public function testAnAnonymousMineIsEmpty(): void
    {
        self::assertSame([], GroupsDiscoveryEndpoint::candidateGroupIds(0, true, ''));
        self::assertSame([], GroupsDiscoveryEndpoint::candidateGroupIds(0, true, 'alpha'));
    }

    public function testWildcardsInANameMatchThemselves(): void
    {
        self::assertSame([self::PERCENT_CLUB], GroupsDiscoveryEndpoint::candidateGroupIds(0, false, '50%'));
        self::assertSame([self::PERCENT_CLUB], GroupsDiscoveryEndpoint::candidateGroupIds(0, false, '%'), 'a bare % is a literal');
        self::assertSame([], GroupsDiscoveryEndpoint::candidateGroupIds(0, false, '_'), 'a bare _ is a literal');
    }

    public function testNoNameKeepsTheWholeBrowsableSet(): void
    {
        self::assertSame(
            self::sorted([self::ALPHA_OPEN, self::ALPHA_CLOSED, self::BETA_OPEN, self::PERCENT_CLUB, ...array_keys(self::HALLS)]),
            self::sorted(GroupsDiscoveryEndpoint::candidateGroupIds(0, false, ''))
        );
    }

    // ── GET /halls ──────────────────────────────────────────────────────

    public function testTheHallsPageAndItsTotalAgreeUnderAName(): void
    {
        $titles = static fn (array $rows): array => array_map(static fn ($r): string => (string) $r->post_title, $rows);

        self::assertSame(['Akash Hall'], $titles(PeepSoGroupRepository::listHalls(null, 0, 20, 'ak')));
        self::assertSame(1, PeepSoGroupRepository::countHalls(null, 'ak'));

        self::assertSame(['Akash Hall', 'Atom Hall', 'Juno Hall'], $titles(PeepSoGroupRepository::listHalls(null, 0, 20, 'HALL')));
        self::assertSame(3, PeepSoGroupRepository::countHalls(null, 'HALL'));

        self::assertSame(['Atom Hall'], $titles(PeepSoGroupRepository::listHalls(null, 1, 1, 'hall')), 'paging still applies');
        self::assertSame(0, PeepSoGroupRepository::countHalls(null, 'no such hall'));
    }

    public function testTheHallsServiceCarriesTheNameToThePageAndTheTotal(): void
    {
        $payload = Plugin::instance()->hallsService()->getHalls(0, 1, 20, null, 'atom');

        self::assertSame(['Atom Hall'], array_column($payload['items'], 'name'));
        self::assertSame(1, $payload['pagination']['total']);
    }

    // ── fixture ─────────────────────────────────────────────────────────

    private function seed(): void
    {
        global $wpdb;

        foreach (self::GROUPS as $id => [$title, $privacy]) {
            $this->group($id, $title);
            foreach ($privacy as $value) {
                self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => 'peepso_group_privacy', 'meta_value' => $value]), $wpdb->last_error);
            }
        }
        foreach (self::HALLS as $id => $title) {
            $this->group($id, $title);
            self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => '_bcc_group_kind', 'meta_value' => 'hall']), $wpdb->last_error);
        }
        foreach (self::MEMBERSHIPS as $groupId) {
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_group_members', [
                'gm_user_id'     => self::VIEWER,
                'gm_group_id'    => $groupId,
                'gm_user_status' => 'member',
            ]), $wpdb->last_error);
        }

        self::assertSame(
            count(self::GROUPS) + count(self::HALLS),
            (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'peepso-group'"),
            'fixture precondition: the fixture groups, and no stray from another suite'
        );
    }

    private function group(int $id, string $title): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->posts, [
            'ID'          => $id,
            'post_title'  => $title,
            'post_name'   => 'name-filter-' . $id,
            'post_type'   => 'peepso-group',
            'post_status' => 'publish',
        ]), $wpdb->last_error);
    }

    /**
     * wp_posts and wp_postmeta are emptied WHOLESALE, as in
     * GroupPrivacyRowsIntegrationTest: another suite's leftover published
     * `peepso-group` is a real match for the browsable read.
     */
    private function purge(): void
    {
        global $wpdb;

        $wpdb->query("DELETE FROM {$wpdb->postmeta}");
        $wpdb->query("DELETE FROM {$wpdb->posts}");
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->prefix}peepso_group_members` WHERE gm_user_id = %d", self::VIEWER));
    }
}
