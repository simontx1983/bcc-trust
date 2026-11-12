<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Tests\Support\EngagementWriteRoutes;
use BCC\Trust\Tests\Support\GateOrder;
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
    private const HIDDEN_COMMENT_POST        = 2006;
    private const DM_ACT              = 300;  // PeepSo DM (module 6)
    private const DM_POST_ID          = 3000;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/group-interaction-gate-stubs.php';
        self::loadRealFeedAllowlist();

        $GLOBALS['__bcc_gig'] = [
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

    public function testTheLookupUsesTheCanonicalFeedAllowlist(): void
    {
        (self::GATE)::check(self::USER, self::PLAIN_POST_ACT);

        $expected = \BCC\Core\Feed\FeedItemNormalizer::publicFeedModuleIds();
        self::assertSame([$expected], $GLOBALS['__bcc_gig']['allowlists'], 'the PRODUCTION allowlist, passed to the lookup');
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

    public function testCommentRoutesPassTheirOwnNotFoundMessage(): void
    {
        $rest = dirname(__DIR__, 2) . '/app/Domain/Core/REST/';
        foreach (['CommentStokeEndpoint.php', 'HelpfulMarkEndpoint.php'] as $file) {
            $src = (string) file_get_contents($rest . $file);
            self::assertStringContainsString(
                "GroupInteractionGate::checkPost(\$userId, (int) \$meta->parent_post_id, 'Comment not found.')",
                $src,
                "{$file}: the secret-group 404 must match its own unknown-comment 404"
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
}
