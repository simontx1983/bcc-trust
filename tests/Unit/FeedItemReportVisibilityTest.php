<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Services\FeedItemReportVisibility;
use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\Services\GroupsService;
use BCC\Trust\Tests\Support\CoreSibling;
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
        // F-94: the sibling reads below go through CoreSibling, which FAILS in
        // CI when the checkout is missing (a skip would be invisible in a
        // green run) and SKIPS in a bare local worktree. A raw require_once
        // did neither: it raised Error "Failed opening required" on every test
        // in the file, which reads as a crash rather than as a missing
        // checkout.
        //
        // ⚠ That guarantee belongs to the FIRST read only, and the comment
        // used to claim it for all three. The other two sit behind
        // `class_exists(..., false)`, so they reach CoreSibling only when the
        // class is not already loaded — if an earlier test in the same process
        // has loaded them, a missing checkout would pass those two unnoticed.
        //
        // It is not a hole in practice: the unguarded read below runs FIRST,
        // so a missing checkout fails the file before either guard is reached.
        // And the guards are themselves correct and must keep their `false`
        // second argument — a bare `class_exists()` would ask the autoloader,
        // which loads the real class and makes the guard always true, exactly
        // the trap this file is guarding against.
        require_once CoreSibling::require('src/Repositories/PeepSoGroupRepository.php');
        // The REAL public-feed module allowlist, not a copy, so this suite
        // fails if the two ever disagree.
        if (!\class_exists('BCC\\Core\\Feed\\FeedItemNormalizer', false)) {
            require_once CoreSibling::require('src/Feed/FeedItemNormalizer.php');
        }
        // The syndication gate the group rule now consults DIRECTLY, because
        // bcc-core #111 defers it to this layer. REAL class, like the two
        // above, so this suite fails if the gate's own logic ever changes.
        if (!\class_exists('BCC\\Core\\Feed\\GroupSyndicationGate', false)) {
            require_once CoreSibling::require('src/Feed/GroupSyndicationGate.php');
        }
        require_once __DIR__ . '/../Stubs/nonopen-groups-seed.php';
        // §F-02: canSee() now also asks the post's own AUDIENCE, which
        // re-reads the act with enforcement on. Without this double the
        // suite dies with "Class PeepSoActivityRepository not found" — a
        // FATAL, not a failed assertion.
        require_once __DIR__ . '/../Stubs/activity-audience-stubs.php';
        $GLOBALS['__bcc_aud'] = ['hidden' => [], 'calls' => []];
        // Default: NO group is non-open, the pre-F-23 shape bcc-core's own
        // tests pin. So the matrix below exercises the GROUP gate, and the
        // syndication question is seeded explicitly by the tests that are
        // about it. ⚠ Without this the real code would fall through to a
        // $wpdb double that cannot answer its JOIN.
        \bcc_test_seed_nonopen_groups([]);
        $GLOBALS['__bcc_halls_gate_fixture'] = [];

        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';
            /** CommentRepository::getCommentMeta() joins it. */
            public string $posts = 'wp_posts';
            /** @var array<string, string> "user:group" => status */
            public array $members = [];
            /** @var list<int> */
            public array $hidden = [];
            public string $last_error = '';
            /** @var list<array<int, string>> wpdb::$last_result */
            private array $lastResult = [];
            /** @var array<int, mixed> */
            private array $args = [];
            /** @var list<int> act ids CommentRepository::getCommentMeta() finds as comments */
            public array $commentActs = [];

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

            /** CommentRepository::getCommentMeta(): a row only for a comment act. */
            public function get_row(?string $sql = null): ?object
            {
                if ($sql !== null && str_contains($sql, 'act_comment_object_id > 0')) {
                    $actId = (int) ($this->args[0] ?? 0);

                    return in_array($actId, $this->commentActs, true)
                        ? (object) ['act_id' => (string) $actId, 'parent_post_id' => '1', 'parent_act_id' => '1']
                        : null;
                }

                return null;
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

    /**
     * ⚠ NARROWED by §F-02's audience rule, and the old name over-claimed.
     * A post outside any group is not refused BY THE GROUP RULE — that is
     * what this asserts, and it is still true. What changed is that
     * canSee() no longer stops there: the post's own audience is asked
     * too, which is the next two tests.
     */
    public function testANonGroupPostIsNotRefusedByTheGroupRule(): void
    {
        $this->post([]);
        self::assertTrue($this->gate()->canSee(self::VIEWER, $this->activity()));
        self::assertTrue($this->gate()->canSee(0, $this->activity()), 'even a signed-out viewer sees the global feed');
    }

    /**
     * ⭐ The §F-02 hole this closed. A post outside any group used to be
     * visible to anyone who could name it, because the group rule answers
     * TRUE for a non-group post and nothing else was asked. Its own
     * audience is now consulted.
     */
    public function testANonGroupPostIsRefusedWhenItsAudienceExcludesTheViewer(): void
    {
        $this->post([]);
        $GLOBALS['__bcc_aud']['hidden'] = [(int) $this->activity()->act_id];

        self::assertFalse($this->gate()->canSee(self::VIEWER, $this->activity()));
    }

    /**
     * ⭐⭐ THE CARVE-OUT, as a control. Inside a group, posts follow the
     * GROUP's rules — members see them — so the audience question must not
     * be asked at all there. Asserted on the RECORDED READS rather than on
     * the answer: a group post could come out visible for unrelated
     * reasons, and what must hold is that §F-02 was never consulted.
     *
     * Without this, applying the audience rule everywhere would look
     * correct while hiding a members_only group post's thread from a
     * fellow member whose author keeps a private profile.
     */
    public function testAGroupPostIsDecidedByTheGroupRuleAloneNotTheAudience(): void
    {
        $this->group(self::PRIVACY['open']);
        $this->post(['peepso_group_id' => (string) self::GROUP, '_bcc_post_visibility' => 'public_group']);
        $this->wpdb()->members[self::VIEWER . ':' . self::GROUP] = 'member';
        $GLOBALS['__bcc_aud']['hidden'] = [(int) $this->activity()->act_id];

        self::assertTrue($this->gate()->canSee(self::VIEWER, $this->activity()));

        $enforced = array_filter(
            \bcc_aud_fixture()['calls'],
            static fn(array $c): bool => $c['enforce'] === true
        );
        self::assertSame([], array_values($enforced), 'the group room must not consult §F-02');
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

    /**
     * A COMMENT act id is not a feed item. Its row carries the status post's
     * module, and its own wp_post no group, so every rule below would read it
     * as an ordinary public post: a `feed_item` report on a comment under a
     * secret group's post was filed. Now it is never visible, whoever asks.
     * The SAME act id, not a comment, is visible: the refusal is the comment
     * check's alone.
     */
    public function testACommentActIsNeverVisibleAndTheSameActNotACommentIs(): void
    {
        $this->post([]);
        $this->wpdb()->commentActs = [self::ACT];

        self::assertFalse($this->gate()->canSee(self::VIEWER, $this->activity()), 'a comment act');
        self::assertFalse($this->gate()->canSee(0, $this->activity()), 'signed out too');

        $this->wpdb()->commentActs = [];
        self::assertTrue($this->gate()->canSee(self::VIEWER, $this->activity()), 'the same act, not a comment');
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

    /**
     * A `public_all` post syndicates to anyone WHILE ITS GROUP IS STILL
     * OPEN — the default seed says no group is non-open, which is that
     * state.
     *
     * ⚠ The name is deliberately specific. An earlier version of this test
     * was called "...IsVisibleToAnyone" and asserted exactly this with a
     * SECRET group fixture, which made it read as "public_all is a free
     * pass". It is not, and the pair below is the proof.
     */
    public function testHydratedPublicAllPostSyndicatesWhileTheGroupIsOpen(): void
    {
        $this->group(self::PRIVACY['open']);
        $this->post(['peepso_group_id' => (string) self::GROUP, '_bcc_post_visibility' => 'public_all']);

        self::assertTrue($this->gate()->canSeeHydratedItem(0, ['external_id' => self::POST]));
    }

    // ── the syndication gate (MED-HIGH review finding) ──────────────
    //
    // ⚠⚠ WHY THIS GROUP OF TESTS EXISTS. `groupRuleAllows()` used to
    // short-circuit on `public_all`, which was safe ONLY because bcc-core's
    // `isVisibleGlobally()` had already applied the GroupSyndicationGate
    // upstream. Passing `deferGroupVisibilityToCaller: true` skips that
    // whole branch, so the shortcut would have left the gate running
    // NOWHERE — a post made `public_all` while its group was OPEN would
    // stay permalink-reachable by anonymous visitors after the group went
    // SECRET.
    //
    // These seed the non-open set, which is what "the group has since
    // closed" means to the gate.

    /**
     * ⭐⭐ THE LEAK, pinned. Made `public_all` while open, group now
     * non-open: an anonymous visitor and a signed-in non-member both get
     * nothing.
     */
    public function testOpenThenSecretPublicAllIsRefusedToOutsiders(): void
    {
        $this->group(self::PRIVACY['secret']);
        $this->post(['peepso_group_id' => (string) self::GROUP, '_bcc_post_visibility' => 'public_all']);
        \bcc_test_seed_nonopen_groups([self::GROUP]);

        self::assertFalse(
            $this->gate()->canSeeHydratedItem(0, ['external_id' => self::POST]),
            'anonymous must not reach a post whose group has gone secret'
        );
        self::assertFalse(
            $this->gate()->canSeeHydratedItem(self::VIEWER, ['external_id' => self::POST]),
            'a signed-in non-member must not either'
        );
    }

    /**
     * ⭐ And the other half: a MEMBER still reads their own group's post.
     * The syndication refusal falls through to the group's gate rather
     * than ending the question, which is why this is not a regression for
     * the people the room is for.
     */
    public function testOpenThenSecretPublicAllIsStillVisibleToAMember(): void
    {
        $this->group(self::PRIVACY['secret']);
        $this->post(['peepso_group_id' => (string) self::GROUP, '_bcc_post_visibility' => 'public_all']);
        \bcc_test_seed_nonopen_groups([self::GROUP]);
        $this->wpdb()->members[self::VIEWER . ':' . self::GROUP] = 'member';

        self::assertTrue($this->gate()->canSeeHydratedItem(self::VIEWER, ['external_id' => self::POST]));
    }

    /**
     * A post DELIBERATELY made `public_all` while the group was already
     * non-open keeps syndicating whatever the group does later — the
     * `madeWhileNonOpen` branch. Mirrors bcc-core; without it this change
     * would silently retract deliberate syndications.
     */
    public function testADeliberateNonOpenSyndicationKeepsWorking(): void
    {
        $this->group(self::PRIVACY['secret']);
        $this->post([
            'peepso_group_id'                  => (string) self::GROUP,
            '_bcc_post_visibility'             => 'public_all',
            '_bcc_post_group_privacy_at_post'  => '2',
        ]);
        \bcc_test_seed_nonopen_groups([self::GROUP]);

        self::assertTrue($this->gate()->canSeeHydratedItem(0, ['external_id' => self::POST]));
    }

    /**
     * ⚠ THE SAME FIX LANDS ON `canSee()`, the report/reaction path, because
     * both entry points share `groupRuleAllows()`. Before this, a
     * non-member could report a post in a group that had gone secret just
     * because it carried `public_all`.
     */
    public function testTheReportPathAlsoHonoursTheSyndicationGate(): void
    {
        $this->group(self::PRIVACY['secret']);
        $this->post(['peepso_group_id' => (string) self::GROUP, '_bcc_post_visibility' => 'public_all']);
        \bcc_test_seed_nonopen_groups([self::GROUP]);

        self::assertFalse($this->gate()->canSee(self::VIEWER, $this->activity()));

        $this->wpdb()->members[self::VIEWER . ':' . self::GROUP] = 'member';
        self::assertTrue(
            $this->gate()->canSee(self::VIEWER, $this->activity()),
            'a member can still report their own group\'s post'
        );
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
            new HiddenActivityRepository(),
            null,
            new CommentRepository()
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
