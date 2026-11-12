<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Services\FeedItemReportVisibility;
use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\Services\GroupsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-73 — "you can report what you can actually see", the full matrix.
 *
 * Runs the REAL GroupsService::gateGroupFeed() (with the real
 * GroupContextResolver and PeepSoPrivacy) — the exact gate the group feed
 * endpoint uses. Only the I/O edges are doubled: WordPress post/meta reads
 * via the existing halls-gate fixture stubs, and `$wpdb` for the PeepSo
 * membership and hidden-activity lookups.
 *
 * The EXPECTED column is an independent oracle written from what each feed
 * shows (global feed: non-group or public_all; group feed: members see
 * everything, non-members of a non-secret group see the public_group /
 * public_all teaser, secret + non-member sees nothing) — NOT computed from
 * the code under test.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FeedItemReportVisibilityTest extends TestCase
{
    private const VIEWER = 77;
    private const GROUP  = 5001;
    private const POST   = 6001;
    private const ACT    = 7001;

    private const PRIVACY = ['open' => 0, 'closed' => 1, 'secret' => 2];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/halls-gate-stubs.php';
        require_once dirname(__DIR__, 2) . '/../bcc-core/src/Repositories/PeepSoGroupRepository.php';
        // The REAL public-feed module allowlist, not a copy, so this suite
        // fails if the two ever disagree.
        if (!\class_exists('BCC\\Core\\Feed\\FeedItemNormalizer', false)) {
            require_once dirname(__DIR__, 2) . '/../bcc-core/src/Feed/FeedItemNormalizer.php';
        }
        $GLOBALS['__bcc_halls_gate_fixture'] = [];

        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';
            /** @var array<string, string> "user:group" => status */
            public array $members = [];
            /** @var list<int> */
            public array $hidden = [];
            public string $last_error = '';
            /** @var list<array<int, string>> wpdb::$last_result */
            private array $lastResult = [];
            /** @var array<int, mixed> */
            private array $args = [];

            public function prepare(string $sql, mixed ...$args): string
            {
                $this->args = $args;
                return $sql;
            }

            /**
             * Real wpdb shape: query() runs the SELECT, returns its row count
             * (or false), and fills last_result for get_var(null)/get_col(null).
             * HiddenActivityRepository reads through query() + get_var(null)
             * since #410 (runRead), and PeepSoGroupRepository through
             * get_var($sql), which calls query() itself, as wpdb does.
             */
            public function query(string $sql): int|false
            {
                $this->last_error = '';
                $value = $this->resolve($sql);
                $this->lastResult = $value === null ? [] : [[$value]];
                return count($this->lastResult);
            }

            public function get_var(?string $sql = null): ?string
            {
                if ($sql !== null) {
                    $this->query($sql);
                }
                return $this->lastResult[0][0] ?? null;
            }

            /** @return list<string> */
            public function get_col(?string $sql = null): array
            {
                if ($sql !== null) {
                    $this->query($sql);
                }
                return array_map(static fn (array $r): string => $r[0], $this->lastResult);
            }

            private function resolve(string $sql): ?string
            {
                if (str_contains($sql, 'peepso_group_members')) {
                    return $this->members[$this->args[0] . ':' . $this->args[1]] ?? null;
                }
                if (str_contains($sql, 'hidden_activities')) {
                    return in_array((int) $this->args[0], $this->hidden, true) ? '1' : '0';
                }
                return null;
            }
        };
    }

    /** @return array<string, array{0: string, 1: bool, 2: string, 3: bool}> */
    public static function matrix(): array
    {
        $cells = [];
        foreach (['open', 'closed', 'secret'] as $privacy) {
            foreach ([true, false] as $member) {
                foreach (['public_all', 'public_group', 'members_only'] as $vis) {
                    $expected = match (true) {
                        $vis === 'public_all'  => true,  // syndicated into the global feed
                        $member                => true,  // members read the whole group feed
                        $privacy === 'secret'  => false, // secret + non-member: group 404s
                        $vis === 'public_group' => true, // non-member teaser (open AND closed)
                        default                => false, // members_only never reaches a non-member
                    };
                    $key = sprintf('%s/%s/%s', $privacy, $member ? 'member' : 'non-member', $vis);
                    $cells[$key] = [$privacy, $member, $vis, $expected];
                }
            }
        }
        return $cells;
    }

    #[DataProvider('matrix')]
    public function testReportableEqualsVisible(string $privacy, bool $member, string $vis, bool $expected): void
    {
        $this->group(self::PRIVACY[$privacy]);
        $this->post(['peepso_group_id' => (string) self::GROUP, '_bcc_post_visibility' => $vis]);
        if ($member) {
            $this->wpdb()->members[self::VIEWER . ':' . self::GROUP] = 'member';
        }

        self::assertSame($expected, $this->gate()->canSee(self::VIEWER, $this->activity()));
    }

    public function testANonGroupPostIsVisibleToEveryone(): void
    {
        $this->post([]);
        self::assertTrue($this->gate()->canSee(self::VIEWER, $this->activity()));
        self::assertTrue($this->gate()->canSee(0, $this->activity()), 'even a signed-out viewer sees the global feed');
    }

    public function testAMissingVisibilityMarkerIsMembersOnly(): void
    {
        // Absent meta ⇒ members_only, the group feed's security invariant.
        $this->group(self::PRIVACY['open']);
        $this->post(['peepso_group_id' => (string) self::GROUP]);
        self::assertFalse($this->gate()->canSee(self::VIEWER, $this->activity()));
    }

    public function testAReadOnlyMemberStillSees(): void
    {
        $this->group(self::PRIVACY['secret']);
        $this->post(['peepso_group_id' => (string) self::GROUP, '_bcc_post_visibility' => 'members_only']);
        $this->wpdb()->members[self::VIEWER . ':' . self::GROUP] = 'member_readonly';
        self::assertTrue($this->gate()->canSee(self::VIEWER, $this->activity()));
    }

    public function testABannedOrPendingMemberIsANonMember(): void
    {
        $this->group(self::PRIVACY['closed']);
        $this->post(['peepso_group_id' => (string) self::GROUP, '_bcc_post_visibility' => 'members_only']);
        foreach (['banned', 'pending_user', 'pending_admin'] as $status) {
            $this->wpdb()->members[self::VIEWER . ':' . self::GROUP] = $status;
            self::assertFalse($this->gate()->canSee(self::VIEWER, $this->activity()), $status);
        }
    }

    public function testUnpublishedHiddenAndPrivateMessageActivitiesAreNeverVisible(): void
    {
        $this->post([]);
        self::assertFalse($this->gate()->canSee(self::VIEWER, $this->activity(status: 'trash')), 'unpublished');
        self::assertFalse($this->gate()->canSee(self::VIEWER, $this->activity(module: '6')), 'a private message');

        $this->wpdb()->hidden = [self::ACT];
        self::assertFalse($this->gate()->canSee(self::VIEWER, $this->activity()), 'moderation-hidden');
    }

    // ── canSeeHydratedItem(): the group-permalink entry point ───────
    //
    // ⚠⚠ WHY THESE EXIST. FeedRankingService's wiring suite STUBS this
    // class, so it proves the permalink CONSULTS the rule and nothing
    // more. A mutation that made this method `return true` unconditionally
    // survived that suite — it is the real implementation, exercised
    // through the real GroupsService, that these assertions pin.
    //
    // The permalink calls this after asking bcc-core with
    // `deferGroupVisibilityToCaller: true`, i.e. after bcc-core has
    // STOPPED applying the group gate. So a wrong answer here is a public
    // permalink for a closed-group post, not a cosmetic bug.

    /**
     * ⭐ THE CASE THE WHOLE CHANGE EXISTS FOR. A `members_only` post —
     * the default for a group post — is readable by a fellow member.
     * bcc-core's permalink alone admits only `public_all`, so before the
     * alignment this 404'd for the very members reading it in the room.
     */
    public function testHydratedMembersOnlyPostIsVisibleToAFellowMember(): void
    {
        $this->group(self::PRIVACY['closed']);
        $this->post(['peepso_group_id' => (string) self::GROUP, '_bcc_post_visibility' => 'members_only']);
        $this->wpdb()->members[self::VIEWER . ':' . self::GROUP] = 'member';

        self::assertTrue($this->gate()->canSeeHydratedItem(self::VIEWER, ['external_id' => self::POST]));
    }

    /** ⚠ And a NON-member of that same group still cannot. */
    public function testHydratedMembersOnlyPostIsHiddenFromANonMember(): void
    {
        $this->group(self::PRIVACY['closed']);
        $this->post(['peepso_group_id' => (string) self::GROUP, '_bcc_post_visibility' => 'members_only']);

        self::assertFalse($this->gate()->canSeeHydratedItem(self::VIEWER, ['external_id' => self::POST]));
        self::assertFalse($this->gate()->canSeeHydratedItem(0, ['external_id' => self::POST]));
    }

    /** A secret group's post stays invisible to a non-member. */
    public function testHydratedSecretGroupPostIsHiddenFromANonMember(): void
    {
        $this->group(self::PRIVACY['secret']);
        $this->post(['peepso_group_id' => (string) self::GROUP, '_bcc_post_visibility' => 'members_only']);

        self::assertFalse($this->gate()->canSeeHydratedItem(self::VIEWER, ['external_id' => self::POST]));
    }

    /**
     * The non-member teaser filter applies here too: `public_group` is
     * readable by someone who can see the group, `members_only` is not.
     */
    public function testHydratedPublicGroupPostIsVisibleToANonMember(): void
    {
        $this->group(self::PRIVACY['open']);
        $this->post(['peepso_group_id' => (string) self::GROUP, '_bcc_post_visibility' => 'public_group']);

        self::assertTrue($this->gate()->canSeeHydratedItem(self::VIEWER, ['external_id' => self::POST]));
    }

    /** A globally syndicated post is visible to anyone, as in the feed. */
    public function testHydratedPublicAllPostIsVisibleToAnyone(): void
    {
        $this->group(self::PRIVACY['secret']);
        $this->post(['peepso_group_id' => (string) self::GROUP, '_bcc_post_visibility' => 'public_all']);

        self::assertTrue($this->gate()->canSeeHydratedItem(0, ['external_id' => self::POST]));
    }

    /** A post with no group marker is a global-feed post: visible. */
    public function testHydratedNonGroupPostIsVisible(): void
    {
        $this->post([]);

        self::assertTrue($this->gate()->canSeeHydratedItem(self::VIEWER, ['external_id' => self::POST]));
    }

    /**
     * ⚠ FAIL-CLOSED on a malformed item. `external_id` must be a real
     * int: a string, a missing key or a non-positive id cannot be
     * privacy-classified, and answering "visible" would hand back a row
     * bcc-core deferred.
     */
    public function testHydratedItemWithoutAUsableBackingPostIsRefused(): void
    {
        $this->post([]);

        self::assertFalse($this->gate()->canSeeHydratedItem(self::VIEWER, []));
        self::assertFalse($this->gate()->canSeeHydratedItem(self::VIEWER, ['external_id' => 0]));
        self::assertFalse($this->gate()->canSeeHydratedItem(self::VIEWER, ['external_id' => -1]));
        self::assertFalse(
            $this->gate()->canSeeHydratedItem(self::VIEWER, ['external_id' => (string) self::POST]),
            'a numeric STRING is not an int; bcc-core hands back ints'
        );
    }

    /**
     * ⭐ SCOPE CONTROL, and deliberately asserting the OPPOSITE of what
     * `canSee()` does. This entry point does NOT re-check the
     * hidden-activity list or the module allowlist, because the same
     * bcc-core call that produced the item already applied them — only
     * the group branch is deferred. Re-checking would cost an extra
     * HiddenActivityRepository read per permalink.
     *
     * So a moderation-hidden activity still answers TRUE here while
     * `canSee()` answers false for it. That asymmetry is intentional and
     * documented on the method; this test is what stops someone
     * "fixing" it and silently adding a query to every permalink.
     */
    public function testHydratedEntryPointDoesNotRecheckTheHiddenList(): void
    {
        $this->post([]);
        $this->wpdb()->hidden = [self::ACT];

        self::assertFalse(
            $this->gate()->canSee(self::VIEWER, $this->activity()),
            'canSee() DOES consult the hidden list'
        );
        self::assertTrue(
            $this->gate()->canSeeHydratedItem(self::VIEWER, ['external_id' => self::POST]),
            'the hydrated entry point deliberately does not — bcc-core already did'
        );
    }

    /** @return array<string, array{0: string}> */
    public static function nonFeedModules(): array
    {
        return [
            'private messages'     => ['6'],
            'group-join notice'    => ['8'],
            'page wall'            => ['9'],
            'polls'                => ['30'],
            'post backgrounds'     => ['111'],
            'raw peepso-blog post' => ['6661'],
            'an unknown module'    => ['99999'],
        ];
    }

    /**
     * #416 review M1: content NO feed or permalink shows is not reportable,
     * even when it is published, non-group and not hidden. Before, only
     * module 6 was refused (a denylist).
     */
    #[DataProvider('nonFeedModules')]
    public function testAModuleNoFeedShowsIsNotReportable(string $module): void
    {
        $this->post([]); // not a group post: everything else says "visible"
        self::assertFalse($this->gate()->canSee(self::VIEWER, $this->activity(module: $module)));
    }

    public function testFeedModulesStayReportable(): void
    {
        $this->post([]);
        foreach (['1', '4', '202', '204'] as $module) { // status, photo, review, blog_excerpt
            self::assertTrue($this->gate()->canSee(self::VIEWER, $this->activity(module: $module)), "module {$module}");
        }
    }

    private function gate(): FeedItemReportVisibility
    {
        return new FeedItemReportVisibility(
            new GroupsService(new ReputationRepository(), new GroupContextResolver()),
            new HiddenActivityRepository()
        );
    }

    private function group(int $privacy): void
    {
        $GLOBALS['__bcc_halls_gate_fixture'][self::GROUP] = [
            'post_type' => 'peepso-group',
            'meta'      => ['_bcc_group_kind' => '', 'peepso_group_privacy' => (string) $privacy],
        ];
    }

    /** @param array<string, string> $meta */
    private function post(array $meta): void
    {
        $GLOBALS['__bcc_halls_gate_fixture'][self::POST] = ['post_type' => 'peepso-post', 'meta' => $meta];
    }

    private function activity(string $status = 'publish', string $module = '1'): object
    {
        return (object) [
            'act_id'          => (string) self::ACT,
            'act_module_id'   => $module,
            'act_external_id' => (string) self::POST,
            'act_status'      => $status,
            'act_user_id'     => '900',
        ];
    }

    private function wpdb(): object
    {
        global $wpdb;
        return $wpdb;
    }
}
