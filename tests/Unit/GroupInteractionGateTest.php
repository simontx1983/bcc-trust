<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

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

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/group-interaction-gate-stubs.php';

        $GLOBALS['__bcc_gig'] = [
            'acts' => [
                self::GROUP_POST_ACT     => ['external_id' => self::GROUP_POST_ID],
                self::PLAIN_POST_ACT     => ['external_id' => self::PLAIN_POST_ID],
                self::GROUP_COMMENT_ACT  => ['external_id' => self::GROUP_COMMENT_POST],
                self::PLAIN_COMMENT_ACT  => ['external_id' => self::PLAIN_COMMENT_POST],
                self::ORPHAN_COMMENT_ACT => ['external_id' => self::ORPHAN_COMMENT_POST],
            ],
            'comments' => [
                self::GROUP_COMMENT_ACT => ['parent_post_id' => self::GROUP_POST_ID],
                self::PLAIN_COMMENT_ACT => ['parent_post_id' => self::PLAIN_POST_ID],
                // ORPHAN_COMMENT_ACT deliberately absent (unpublished / no parent)
            ],
            'post_type' => [
                self::GROUP_POST_ID       => 'peepso-post',
                self::PLAIN_POST_ID       => 'peepso-post',
                self::GROUP_COMMENT_POST  => 'peepso-comment',
                self::PLAIN_COMMENT_POST  => 'peepso-comment',
                self::ORPHAN_COMMENT_POST => 'peepso-comment',
            ],
            // Faithful to production: ONLY the parent post carries the group.
            'group_of' => [
                self::GROUP_POST_ID => self::GROUP,
            ],
            'members'      => [],
            'access_calls' => [],
        ];
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
}
