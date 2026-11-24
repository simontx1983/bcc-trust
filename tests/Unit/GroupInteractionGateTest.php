<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Tests\Support\EngagementWriteRoutes;
use BCC\Trust\Tests\Support\GateOrder;
use BCC\Trust\Tests\Support\PostGroupFactsWpdb;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * GroupInteractionGate::check(): the membership gate behind reactions,
 * stoke and helpful marks on `/feed/{id}/…`.
 *
 * ── ⚠⚠ THE THINGS THAT MUST NOT HAPPEN ──────────────────────────────────
 *  1. A COMMENT WALKS THROUGH A CLOSED GROUP. check() takes ANY act id, and
 *     a comment is an act. A comment's own wp_post carries no
 *     `peepso_group_id`, so gating on it waved a non-member through to
 *     react to, stoke or mark-helpful any comment in a closed or secret
 *     group. A comment must be gated on its PARENT post.
 *  2. AN UNKNOWN ACT IS WAVED THROUGH. The stoke and helpful writers
 *     INSERT blindly, so a null "not my concern" let them write rows
 *     against acts that don't exist. Unknown means 404.
 *  3. AN UNRESOLVABLE COMMENT FALLS BACK TO ITS OWN POST. A comment act
 *     whose parent can't be resolved (e.g. unpublished) must 404, never be
 *     gated on its own group-less post row (which is case 1 again).
 *  4. THE GATE REFUSES TOO MUCH. An ordinary non-group post, and a comment
 *     on one, must pass untouched.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupInteractionGateTest extends TestCase
{
    /** @var class-string */
    private const GATE = \BCC\Trust\Core\Support\GroupInteractionGate::class;

    private const USER     = 7;
    private const GROUP    = 55;

    private const GROUP_POST_ACT     = 100;  // post in GROUP
    private const GROUP_POST_ID      = 1000;
    private const PLAIN_POST_ACT     = 101;  // post outside any group
    private const PLAIN_POST_ID      = 1001;
    private const GROUP_COMMENT_ACT  = 200;  // comment under GROUP_POST_ID
    private const GROUP_COMMENT_POST = 2000;
    private const PLAIN_COMMENT_ACT  = 201;  // comment under PLAIN_POST_ID
    private const PLAIN_COMMENT_POST = 2001;
    private const ORPHAN_COMMENT_ACT = 202;  // comment act getCommentMeta can't resolve
    private const ORPHAN_COMMENT_POST = 2002;
    private const UNKNOWN_ACT        = 999;

    // F-163 fixtures.
    private const SECRET_GROUP        = 66;
    private const SECRET_POST_ACT     = 102;  // post in SECRET_GROUP
    private const SECRET_POST_ID      = 1002;
    private const SECRET_COMMENT_ACT  = 203;  // comment under SECRET_POST_ID
    private const SECRET_COMMENT_POST = 2003;
    private const TRASH_POST_ID       = 1003; // a trashed parent post
    private const TRASH_COMMENT_ACT   = 204;  // comment under TRASH_POST_ID
    private const TRASH_COMMENT_POST  = 2004;
    // F-265 fixtures: posts that are gone (deleted / moderation-hidden).
    private const TRASH_POST_ACT             = 103;  // the act of TRASH_POST_ID
    private const HIDDEN_POST_ACT            = 104;  // moderation-hidden post
    private const HIDDEN_POST_ID             = 1004;
    private const HIDDEN_PARENT_COMMENT_ACT  = 205;  // comment under HIDDEN_POST_ID
    private const HIDDEN_PARENT_COMMENT_POST = 2005;
    private const HIDDEN_COMMENT_ACT         = 206;  // itself hidden, under PLAIN_POST_ID

    // §F-02 audience fixtures: a perfectly ORDINARY comment whose parent
    // act RESOLVES. The two HIDDEN_* comments above cannot serve here —
    // they are moderation-hidden by design, so they 404 at the F-265 step
    // before the audience gate is ever reached.
    private const AUDIENCE_COMMENT_ACT  = 207;
    private const AUDIENCE_COMMENT_POST = 2007;
    private const HIDDEN_COMMENT_POST        = 2006;
    private const DM_ACT              = 300;  // PeepSo DM (module 6)
    private const DM_POST_ID          = 3000;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/group-interaction-gate-stubs.php';
        self::loadRealFeedAllowlist();

        $GLOBALS['__bcc_gig'] = [
            // §F-02: act ids whose audience excludes the viewer, and the
            // enforced reads that actually happened. Empty by default, so
            // every pre-existing test keeps its original meaning.
            'f02_hidden' => [],
            'f02_reads'  => [],
            'acts' => [
                self::GROUP_POST_ACT     => ['external_id' => self::GROUP_POST_ID],
                self::PLAIN_POST_ACT     => ['external_id' => self::PLAIN_POST_ID],
                self::GROUP_COMMENT_ACT  => ['external_id' => self::GROUP_COMMENT_POST],
                self::PLAIN_COMMENT_ACT  => ['external_id' => self::PLAIN_COMMENT_POST],
                self::ORPHAN_COMMENT_ACT => ['external_id' => self::ORPHAN_COMMENT_POST],
                self::SECRET_POST_ACT    => ['external_id' => self::SECRET_POST_ID],
                self::SECRET_COMMENT_ACT => ['external_id' => self::SECRET_COMMENT_POST],
                self::TRASH_COMMENT_ACT  => ['external_id' => self::TRASH_COMMENT_POST],
                self::TRASH_POST_ACT     => ['external_id' => self::TRASH_POST_ID],
                self::HIDDEN_POST_ACT    => ['external_id' => self::HIDDEN_POST_ID],
                self::HIDDEN_PARENT_COMMENT_ACT => ['external_id' => self::HIDDEN_PARENT_COMMENT_POST],
                self::HIDDEN_COMMENT_ACT => ['external_id' => self::HIDDEN_COMMENT_POST],
                self::AUDIENCE_COMMENT_ACT => ['external_id' => self::AUDIENCE_COMMENT_POST],
                // PeepSo's DM module. An act, but not a feed activity.
                self::DM_ACT             => ['external_id' => self::DM_POST_ID, 'module' => 6],
            ],
            'comments' => [
                self::GROUP_COMMENT_ACT  => ['parent_post_id' => self::GROUP_POST_ID],
                self::PLAIN_COMMENT_ACT  => ['parent_post_id' => self::PLAIN_POST_ID],
                self::SECRET_COMMENT_ACT => ['parent_post_id' => self::SECRET_POST_ID],
                self::TRASH_COMMENT_ACT  => ['parent_post_id' => self::TRASH_POST_ID],
                self::HIDDEN_PARENT_COMMENT_ACT => ['parent_post_id' => self::HIDDEN_POST_ID, 'parent_act_id' => self::HIDDEN_POST_ACT],
                self::HIDDEN_COMMENT_ACT => ['parent_post_id' => self::PLAIN_POST_ID, 'parent_act_id' => self::PLAIN_POST_ACT],
                self::AUDIENCE_COMMENT_ACT => ['parent_post_id' => self::PLAIN_POST_ID, 'parent_act_id' => self::PLAIN_POST_ACT],
                // ORPHAN_COMMENT_ACT deliberately absent (unpublished / no parent)
            ],
            'post_type' => [
                self::GROUP_POST_ID       => 'peepso-post',
                self::PLAIN_POST_ID       => 'peepso-post',
                self::GROUP_COMMENT_POST  => 'peepso-comment',
                self::PLAIN_COMMENT_POST  => 'peepso-comment',
                self::ORPHAN_COMMENT_POST => 'peepso-comment',
                self::SECRET_POST_ID      => 'peepso-post',
                self::SECRET_COMMENT_POST => 'peepso-comment',
                self::TRASH_COMMENT_POST  => 'peepso-comment',
                self::DM_POST_ID          => 'peepso-message',
                self::TRASH_POST_ID       => 'peepso-post',
                self::HIDDEN_POST_ID      => 'peepso-post',
                self::HIDDEN_PARENT_COMMENT_POST => 'peepso-comment',
                self::HIDDEN_COMMENT_POST => 'peepso-comment',
                self::AUDIENCE_COMMENT_POST => 'peepso-comment',
            ],
            'post_status' => [
                self::TRASH_POST_ID => 'trash',
            ],
            // Faithful to production: ONLY the parent post carries the group.
            'group_of' => [
                self::GROUP_POST_ID  => self::GROUP,
                self::SECRET_POST_ID => self::SECRET_GROUP,
            ],
            // SECRET_GROUP has no entry: like the real resolveGroupAccess(),
            // a secret group + non-member resolves to NULL (existence hidden).
            'members'      => [],
            'access_calls' => [],
            'hidden'       => [self::HIDDEN_POST_ACT, self::HIDDEN_COMMENT_ACT],
            'allowlists'   => [],
        ];

        // F-461: the gate reads "is this a group post" from the database
        // (PostGroupFactsRepository), never get_post_meta(). By default the
        // "database" agrees with the `group_of` fixture above;
        // $this->wpdb()->dbRows overrides it.
        $GLOBALS['wpdb'] = new PostGroupFactsWpdb(
            static fn (int $postId, string $key): mixed => $key === 'peepso_group_id'
                ? (string) ($GLOBALS['__bcc_gig']['group_of'][$postId] ?? '')
                : ''
        );
    }

    private function wpdb(): PostGroupFactsWpdb
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertInstanceOf(PostGroupFactsWpdb::class, $wpdb);
        return $wpdb;
    }

    /**
     * The REAL bcc-core FeedItemNormalizer from the sibling checkout (CI has
     * it at ../bcc-core), so the allowlist under test is the production one,
     * not a copy. A missing checkout FAILS rather than skips.
     */
    private static function loadRealFeedAllowlist(): void
    {
        $src  = getenv('BCC_CORE_SRC') ?: dirname(__DIR__, 3) . '/bcc-core/src';
        $file = rtrim((string) $src, '/\\') . '/Feed/FeedItemNormalizer.php';
        self::assertFileExists($file, 'bcc-core checkout not found; set BCC_CORE_SRC. Refusing to skip.');
        require_once $file;
    }

    private function setMember(bool $isMember): void
    {
        $GLOBALS['__bcc_gig']['members'][self::USER . ':' . self::GROUP] = $isMember;
    }

    /** @return array{0:int,1:string} */
    private static function statusAndCode(?\WP_REST_Response $r): array
    {
        self::assertNotNull($r, 'expected a refusal, got pass-through');
        $body = $r->get_data();
        self::assertIsArray($body);
        return [$r->get_status(), (string) $body['error']['code']];
    }

    // ── 1. Comments are gated on their parent post ────────────────────────

    public function testNonMemberIsRefusedOnACommentInAGroup(): void
    {
        $this->setMember(false);

        $r = (self::GATE)::check(self::USER, self::GROUP_COMMENT_ACT);

        self::assertSame([403, 'bcc_permission_denied'], self::statusAndCode($r));
        self::assertSame(
            [[self::USER, self::GROUP]],
            $GLOBALS['__bcc_gig']['access_calls'],
            'membership must be resolved against the PARENT post\'s group'
        );
    }

    public function testMemberMayActOnACommentInAGroup(): void
    {
        $this->setMember(true);

        self::assertNull((self::GATE)::check(self::USER, self::GROUP_COMMENT_ACT));
        self::assertSame([[self::USER, self::GROUP]], $GLOBALS['__bcc_gig']['access_calls']);
    }

    public function testACommentOnANonGroupPostPasses(): void
    {
        self::assertNull((self::GATE)::check(self::USER, self::PLAIN_COMMENT_ACT));
        self::assertSame([], $GLOBALS['__bcc_gig']['access_calls']);
    }

    // ── 2. Posts behave exactly as before ─────────────────────────────────

    public function testNonMemberIsRefusedOnAGroupPost(): void
    {
        $this->setMember(false);

        self::assertSame(
            [403, 'bcc_permission_denied'],
            self::statusAndCode((self::GATE)::check(self::USER, self::GROUP_POST_ACT))
        );
    }

    public function testMemberMayActOnAGroupPost(): void
    {
        $this->setMember(true);

        self::assertNull((self::GATE)::check(self::USER, self::GROUP_POST_ACT));
    }

    public function testAnOrdinaryPostPasses(): void
    {
        self::assertNull((self::GATE)::check(self::USER, self::PLAIN_POST_ACT));
        self::assertSame([], $GLOBALS['__bcc_gig']['access_calls']);
    }

    // ── 3. Fail closed on what it can't place ─────────────────────────────

    public function testAnUnknownActIsNotFound(): void
    {
        self::assertSame(
            [404, 'bcc_not_found'],
            self::statusAndCode((self::GATE)::check(self::USER, self::UNKNOWN_ACT))
        );
    }

    public function testAnUnresolvableCommentIsNotFoundAndNeverGatedOnItself(): void
    {
        $this->setMember(false);

        self::assertSame(
            [404, 'bcc_not_found'],
            self::statusAndCode((self::GATE)::check(self::USER, self::ORPHAN_COMMENT_ACT))
        );
        self::assertSame([], $GLOBALS['__bcc_gig']['access_calls']);
    }

    // ── F-163: only feed activities; no secret-group existence oracle ────

    public function testADirectMessageActIsNotFound(): void
    {
        self::assertSame(
            [404, 'bcc_not_found'],
            self::statusAndCode((self::GATE)::check(self::USER, self::DM_ACT))
        );
        self::assertSame([], $GLOBALS['__bcc_gig']['access_calls']);
    }

    /**
     * ⚠ There are now TWO act lookups on this path, and the distinction
     * matters: the FIRST decides whether the act is something a feed route
     * may touch at all (the canonical module allowlist, F-163), and the
     * SECOND is the §F-02 audience re-read, which asks a different
     * question and passes NO module filter on purpose — it answers "may
     * this viewer see this post", and the allowlist question is already
     * settled by the time it runs. Asserting only on the first keeps this
     * test about the thing it is named for.
     */
    public function testTheLookupUsesTheCanonicalFeedAllowlist(): void
    {
        (self::GATE)::check(self::USER, self::PLAIN_POST_ACT);

        $expected = \BCC\Core\Feed\FeedItemNormalizer::publicFeedModuleIds();
        $calls    = $GLOBALS['__bcc_gig']['allowlists'];

        self::assertNotSame([], $calls, 'the act lookup never happened');
        self::assertSame($expected, $calls[0], 'the PRODUCTION allowlist, passed to the FIRST lookup');
        self::assertNotContains(6, $expected, 'control: DMs are not a feed module');
        self::assertContains(1, $expected, 'control: status posts are');
    }

    public function testASecretGroupPostIsIndistinguishableFromAnUnknownAct(): void
    {
        $secret  = (self::GATE)::check(self::USER, self::SECRET_POST_ACT);
        $unknown = (self::GATE)::check(self::USER, self::UNKNOWN_ACT);

        self::assertNotNull($secret);
        self::assertNotNull($unknown);
        self::assertSame($unknown->get_status(), $secret->get_status());
        self::assertSame($unknown->get_data(), $secret->get_data(), 'byte-identical body: no existence oracle');
    }

    public function testASecretGroupCommentIsIndistinguishableFromAnUnknownAct(): void
    {
        $secret  = (self::GATE)::check(self::USER, self::SECRET_COMMENT_ACT);
        $unknown = (self::GATE)::check(self::USER, self::UNKNOWN_ACT);

        self::assertSame($unknown->get_data(), $secret->get_data());
    }

    /**
     * The comment routes call checkPost() with their own unknown-comment
     * message, so their secret-group 404 matches THAT body, not "Post not found.".
     */
    public function testCheckPostUsesTheCallersNotFoundMessage(): void
    {
        $viaComment = (self::GATE)::checkPost(self::USER, self::SECRET_POST_ID, 'Comment not found.');
        $expected   = \BCC\Trust\Core\Support\ApiResponse::error('bcc_not_found', 'Comment not found.', 404);

        self::assertNotNull($viaComment);
        self::assertSame($expected->get_status(), $viaComment->get_status());
        self::assertSame($expected->get_data(), $viaComment->get_data());
    }

    public function testAClosedGroupNonMemberStillGetsTheJoin403(): void
    {
        // Existence of a closed/open group is public: the 403 is not an oracle.
        $this->setMember(false);
        self::assertSame(
            [403, 'bcc_permission_denied'],
            self::statusAndCode((self::GATE)::check(self::USER, self::GROUP_POST_ACT))
        );
    }

    public function testACommentOnATrashedParentIsNotFound(): void
    {
        self::assertSame(
            [404, 'bcc_not_found'],
            self::statusAndCode((self::GATE)::check(self::USER, self::TRASH_COMMENT_ACT))
        );
        self::assertSame([], $GLOBALS['__bcc_gig']['access_calls']);
    }

    // ── F-265: nothing may act on a post that is gone ────────────────────

    public function testADeletedPostIsNotFound(): void
    {
        self::assertSame(
            [404, 'bcc_not_found'],
            self::statusAndCode((self::GATE)::check(self::USER, self::TRASH_POST_ACT))
        );
        self::assertSame([], $GLOBALS['__bcc_gig']['access_calls'], 'refused before any membership lookup');
    }

    public function testAModerationHiddenPostIsNotFound(): void
    {
        self::assertSame(
            [404, 'bcc_not_found'],
            self::statusAndCode((self::GATE)::check(self::USER, self::HIDDEN_POST_ACT))
        );
    }

    public function testACommentUnderAModerationHiddenPostIsNotFound(): void
    {
        self::assertSame(
            [404, 'bcc_not_found'],
            self::statusAndCode((self::GATE)::check(self::USER, self::HIDDEN_PARENT_COMMENT_ACT))
        );
    }

    public function testAModerationHiddenCommentIsNotFound(): void
    {
        self::assertSame(
            [404, 'bcc_not_found'],
            self::statusAndCode((self::GATE)::check(self::USER, self::HIDDEN_COMMENT_ACT))
        );
    }

    /** The viewer-free resolver NotificationDispatcher uses: same rule, no membership. */
    public function testResolveLiveTargetAnswersWithoutAViewer(): void
    {
        $gate = self::GATE;
        self::assertSame(self::PLAIN_POST_ID, $gate::resolveLiveTarget(self::PLAIN_POST_ACT));
        self::assertSame(self::GROUP_POST_ID, $gate::resolveLiveTarget(self::GROUP_COMMENT_ACT), 'a comment resolves to its parent');
        self::assertNull($gate::resolveLiveTarget(self::TRASH_POST_ACT));
        self::assertNull($gate::resolveLiveTarget(self::HIDDEN_POST_ACT));
        self::assertNull($gate::resolveLiveTarget(self::HIDDEN_PARENT_COMMENT_ACT));
        self::assertNull($gate::resolveLiveTarget(self::HIDDEN_COMMENT_ACT));
        self::assertSame([], $GLOBALS['__bcc_gig']['access_calls'], 'no membership lookup');
    }

    // ── checkComment(): the comment-stoke / comment-helpful-mark gate ───

    /** @return array{0:int,1:string,2:string} status, code, message */
    private static function refusal(?\WP_REST_Response $r): array
    {
        [$status, $code] = self::statusAndCode($r);
        $body = $r?->get_data();
        self::assertIsArray($body);

        return [$status, $code, (string) $body['error']['message']];
    }

    private const COMMENT_404 = [404, 'bcc_not_found', 'Comment not found.'];

    /** F-265 (review of #623): a removed thread's comments take no stoke / helpful-mark. */
    public function testCheckCommentRefusesACommentUnderADeletedPost(): void
    {
        self::assertSame(self::COMMENT_404, self::refusal((self::GATE)::checkComment(self::USER, self::TRASH_COMMENT_ACT)));
        self::assertSame([], $GLOBALS['__bcc_gig']['access_calls'], 'refused before any membership lookup');
    }

    public function testCheckCommentRefusesACommentUnderAModerationHiddenPost(): void
    {
        self::assertSame(self::COMMENT_404, self::refusal((self::GATE)::checkComment(self::USER, self::HIDDEN_PARENT_COMMENT_ACT)));
    }

    public function testCheckCommentRefusesAModerationHiddenComment(): void
    {
        self::assertSame(self::COMMENT_404, self::refusal((self::GATE)::checkComment(self::USER, self::HIDDEN_COMMENT_ACT)));
    }

    public function testCheckCommentRefusesAnUnknownOrNonCommentAct(): void
    {
        self::assertSame(self::COMMENT_404, self::refusal((self::GATE)::checkComment(self::USER, self::UNKNOWN_ACT)));
        self::assertSame(
            self::COMMENT_404,
            self::refusal((self::GATE)::checkComment(self::USER, self::PLAIN_POST_ACT)),
            'a POST act is not a comment on these routes'
        );
    }

    public function testCheckCommentKeepsTheSecretGroupOracleClosed(): void
    {
        self::assertSame(self::COMMENT_404, self::refusal((self::GATE)::checkComment(self::USER, self::SECRET_COMMENT_ACT)));
    }

    public function testCheckCommentStillAppliesMembership(): void
    {
        $this->setMember(false);
        self::assertSame(403, self::refusal((self::GATE)::checkComment(self::USER, self::GROUP_COMMENT_ACT))[0]);

        $this->setMember(true);
        self::assertNull((self::GATE)::checkComment(self::USER, self::GROUP_COMMENT_ACT));
        self::assertNull((self::GATE)::checkComment(self::USER, self::PLAIN_COMMENT_ACT), 'a live comment on an ordinary post passes');
    }

    public function testCommentRoutesUseTheSharedCommentGate(): void
    {
        $rest = dirname(__DIR__, 2) . '/app/Domain/Core/REST/';
        foreach (['CommentStokeEndpoint.php', 'HelpfulMarkEndpoint.php'] as $file) {
            $src = (string) file_get_contents($rest . $file);
            self::assertStringContainsString(
                'GroupInteractionGate::checkComment($userId, $commentActId)',
                $src,
                "{$file}: comment routes must gate through checkComment() (liveness + membership)"
            );
            self::assertStringNotContainsString(
                '->parent_post_id',
                $src,
                "{$file}: no route-local parent resolution that could skip liveness"
            );
        }
    }

    // ── 4. Caller inventory: the act-id gate is the one the /feed routes use

    /**
     * The fix lives in check(); it only protects the routes that call it.
     * Pin the inventory so a new act-id write route that skips the gate, or
     * a refactor that drops a call, fails here instead of silently opening.
     */
    public function testEveryActIdWriteRouteCallsTheGate(): void
    {
        $rest = dirname(__DIR__, 2) . '/app/Domain/Core/REST/';
        $expected = [
            'ReactionsEndpoint.php'   => 2, // set + remove
            'StokeEndpoint.php'       => 2, // add + remove
            'HelpfulMarkEndpoint.php' => 1, // the shared post branch
            // F-346. ONE call site, reached TWICE per request: the repost
            // route gates the submitted id and then the resolved repost
            // ROOT, through one shared helper so the two cannot drift.
            // Counting the helper's single call site is what this guard can
            // see; that BOTH ids actually reach it, and that the refusal is
            // honoured for each, is asserted behaviourally in
            // RepostEndpointTest over a real call trace.
            //
            // ⛔ removeRepost deliberately calls NO audience gate — a root
            // can stop being readable, and gating the un-repost would strand
            // the member with a post on their own wall they cannot remove.
            // Its 404-when-nothing-of-yours is what replaces the gate.
            'RepostEndpoint.php'      => 1,
        ];
        foreach ($expected as $file => $count) {
            $src = (string) file_get_contents($rest . $file);
            self::assertSame(
                $count,
                substr_count($src, 'GroupInteractionGate::check($userId, $actId)'),
                "{$file}: expected {$count} call(s) to GroupInteractionGate::check"
            );
        }
    }

    /**
     * F-96: the count above passes with the gate called AFTER the write, or
     * with its answer ignored. In every engagement write handler (comment
     * routes included, which gate on the parent via checkPost) the gate's
     * error is guarded and returned, and no write call comes before that
     * return.
     */
    public function testEveryWriteHandlerReturnsTheGateErrorBeforeItWrites(): void
    {
        foreach (EngagementWriteRoutes::HANDLERS as $h) {
            $label = "{$h['file']}::{$h['method']}()";
            $body  = GateOrder::body(GateOrder::code(EngagementWriteRoutes::REST_DIR . $h['file']), $h['method']);

            GateOrder::assertOrder($body, ['$gateError = ', 'if ($gateError !== null) {', 'return $gateError;'], $label);
            GateOrder::assertNoWorkBefore($body, 'return $gateError;', $h['writes'], $label);
        }
    }

    // ── §F-02: the post's own audience, not only its group ──────────────

    /**
     * ⭐ The gap this closed. `checkPost()` answered "no membership gate"
     * for any post outside a group, so anyone holding a feed id could
     * react to, stoke or helpful-mark a followers-only or only-me post —
     * and ring its author's bell from content they were never shown.
     *
     * 404, not 403: a refusal that said "forbidden" would confirm the post
     * is there, which is the same existence-oracle rule as F-163 above.
     */
    public function testANonGroupPostTheViewerMayNotSeeIsNotFound(): void
    {
        $GLOBALS['__bcc_gig']['f02_hidden'] = [self::PLAIN_POST_ACT];

        self::assertSame(
            [404, 'bcc_not_found'],
            self::statusAndCode((self::GATE)::check(self::USER, self::PLAIN_POST_ACT))
        );
    }

    /**
     * ⭐ CONTROL, and the one that proves the test above is not vacuous:
     * the same act, visible, still passes. The audience read must also
     * have HAPPENED and carried this viewer — a gate that refused for some
     * other reason would satisfy the refusal test alone.
     */
    public function testAVisibleNonGroupPostStillPassesAndWasAudienceChecked(): void
    {
        self::assertNull((self::GATE)::check(self::USER, self::PLAIN_POST_ACT));

        self::assertSame(
            [['act_id' => self::PLAIN_POST_ACT, 'viewer' => self::USER]],
            $GLOBALS['__bcc_gig']['f02_reads'],
            'the audience was asked, for this viewer, about this act'
        );
    }

    /**
     * ⭐⭐ THE RATIFIED CARVE-OUT. Inside a group, posts follow the GROUP's
     * rules — members see them — so §F-02 must not be consulted there at
     * all. Asserted on the recorded reads rather than on the verdict: a
     * group post can be allowed for unrelated reasons, and what must hold
     * is that the audience question is never asked.
     */
    public function testAGroupPostIsNeverAudienceChecked(): void
    {
        $GLOBALS['__bcc_gig']['f02_hidden']  = [self::GROUP_POST_ACT];
        $GLOBALS['__bcc_gig']['members'][self::USER . ':' . self::GROUP] = true;

        // A MEMBER, acting in the room: allowed by the group rule, and the
        // audience never asked. Both halves matter — asserting only the
        // empty read list would also pass for a viewer who was refused
        // before the audience step could run.
        self::assertNull((self::GATE)::check(self::USER, self::GROUP_POST_ACT));
        self::assertSame([], $GLOBALS['__bcc_gig']['f02_reads'], 'the group room must not consult §F-02');
    }

    // ── §F-02 on the COMMENT half (review follow-up) ────────────────────
    //
    // These three paths were wired but untested: checkComment()'s audience
    // branch, judging a comment by its PARENT act, and the zero-parent
    // skip. All are reachable from comment-stoke and comment-helpful.

    /**
     * ⭐ A comment is judged by its PARENT POST's audience. The rule reads
     * `act_access`, which a comment does not meaningfully carry, so the
     * subject of the question has to be the parent's act row.
     */
    public function testACommentIsRefusedWhenItsParentPostsAudienceExcludesTheViewer(): void
    {
        $GLOBALS['__bcc_gig']['f02_hidden'] = [self::PLAIN_POST_ACT];

        self::assertSame(
            [404, 'bcc_not_found'],
            self::statusAndCode((self::GATE)::check(self::USER, self::AUDIENCE_COMMENT_ACT))
        );
        self::assertSame(
            [['act_id' => self::PLAIN_POST_ACT, 'viewer' => self::USER]],
            $GLOBALS['__bcc_gig']['f02_reads'],
            'the PARENT act was the subject, not the comment'
        );
    }

    /**
     * ⭐⭐ THE CONTROL that makes the test above mean something. Hiding the
     * COMMENT'S OWN act id must change nothing. If the gate ever asked
     * about the comment instead of its parent it would refuse here — and
     * it would then also refuse on comments under posts the viewer CAN
     * see, because a comment's own `act_access` is not the post's.
     */
    public function testACommentsOwnActIdIsNeverTheAudienceSubject(): void
    {
        $GLOBALS['__bcc_gig']['f02_hidden'] = [self::AUDIENCE_COMMENT_ACT];

        self::assertNull((self::GATE)::check(self::USER, self::AUDIENCE_COMMENT_ACT));
    }

    /**
     * The documented skip: `parent_act_id` of 0 means the LEFT JOIN on
     * (parent post, comment module) matched nothing, so there is no act
     * row to ask about. Pinned because it is a deliberate narrowing with a
     * known cost — see GroupInteractionGate::audienceAllows(). A future
     * tightening should FAIL this test and be a conscious choice.
     */
    public function testAnUnresolvableParentActSkipsTheGate(): void
    {
        // PLAIN_COMMENT_ACT's fixture carries no parent_act_id → 0.
        $GLOBALS['__bcc_gig']['f02_hidden'] = [self::PLAIN_POST_ACT];

        self::assertNull((self::GATE)::check(self::USER, self::PLAIN_COMMENT_ACT));
        self::assertSame(
            [],
            $GLOBALS['__bcc_gig']['f02_reads'],
            'with no parent act there is nothing to ask about, so nothing is asked'
        );
    }

    /**
     * The same rule on the route that takes a COMMENT act directly —
     * comment stoke and comment helpful-mark — which resolves its own meta
     * and therefore carries its own copy of the branch.
     */
    public function testCheckCommentAppliesTheParentsAudience(): void
    {
        $GLOBALS['__bcc_gig']['f02_hidden'] = [self::PLAIN_POST_ACT];

        self::assertSame(
            [404, 'bcc_not_found'],
            self::statusAndCode((self::GATE)::checkComment(self::USER, self::AUDIENCE_COMMENT_ACT))
        );
    }

    /** ⭐ CONTROL for checkComment: a visible parent is still allowed. */
    public function testCheckCommentStillAllowsAVisibleParent(): void
    {
        self::assertNull((self::GATE)::checkComment(self::USER, self::AUDIENCE_COMMENT_ACT));
    }

    // ── F-461: "is this a group post" comes from the database ───────────
    //
    // Below, get_post_meta() (the `group_of` fixture) says PLAIN_POST_ID has
    // "no group", while the DATABASE says
    // it is a members-only post in SECRET_GROUP. The gate must follow the
    // database.

    private function putThePlainPostInTheSecretGroupInTheDatabase(): void
    {
        $this->wpdb()->dbRows[self::PLAIN_POST_ID] = [
            PostGroupFactsWpdb::row('peepso_group_id', (string) self::SECRET_GROUP),
            PostGroupFactsWpdb::row('_bcc_post_visibility', 'members_only'),
        ];
    }

    public function testReactionsFollowTheDatabasesGroupFacts(): void
    {
        self::assertNull((self::GATE)::check(self::USER, self::PLAIN_POST_ACT), 'control: what the cache alone describes passes');

        $this->putThePlainPostInTheSecretGroupInTheDatabase();

        $refused = (self::GATE)::check(self::USER, self::PLAIN_POST_ACT);
        $unknown  = (self::GATE)::check(self::USER, self::UNKNOWN_ACT);
        self::assertNotNull($refused);
        self::assertNotNull($unknown);
        self::assertSame($unknown->get_data(), $refused->get_data(), 'a secret group post, byte-identical to an unknown act');
    }

    public function testCommentStokeFollowsTheDatabasesGroupFacts(): void
    {
        $this->putThePlainPostInTheSecretGroupInTheDatabase();

        self::assertSame(self::COMMENT_404, self::refusal((self::GATE)::checkComment(self::USER, self::AUDIENCE_COMMENT_ACT)));
        self::assertSame(
            [404, 'bcc_not_found'],
            self::statusAndCode((self::GATE)::check(self::USER, self::PLAIN_COMMENT_ACT)),
            'a comment act on the act-id routes is gated on its parent too'
        );
    }

    public function testCheckPostFollowsTheDatabasesGroupFacts(): void
    {
        $this->putThePlainPostInTheSecretGroupInTheDatabase();

        self::assertSame(
            self::COMMENT_404,
            self::refusal((self::GATE)::checkPost(self::USER, self::PLAIN_POST_ID, 'Comment not found.'))
        );
    }

    /** Unreadable group facts are an unknown target, on every entry point. */
    public function testUnreadableGroupFactsAreNotFound(): void
    {
        $this->wpdb()->failRead = true;

        self::assertSame([404, 'bcc_not_found'], self::statusAndCode((self::GATE)::check(self::USER, self::PLAIN_POST_ACT)));
        self::assertSame(self::COMMENT_404, self::refusal((self::GATE)::checkComment(self::USER, self::PLAIN_COMMENT_ACT)));
        self::assertSame(
            self::COMMENT_404,
            self::refusal((self::GATE)::checkPost(self::USER, self::PLAIN_POST_ID, 'Comment not found.'))
        );
        self::assertSame([], $GLOBALS['__bcc_gig']['access_calls'], 'refused before any membership lookup');
    }

    /** One decision, one read: the audience and membership gates share it. */
    public function testOneGateDecisionRestsOnOneFactsRead(): void
    {
        $this->setMember(true);

        self::assertNull((self::GATE)::check(self::USER, self::GROUP_POST_ACT));
        self::assertSame(1, $this->wpdb()->factsReads);
    }
}
