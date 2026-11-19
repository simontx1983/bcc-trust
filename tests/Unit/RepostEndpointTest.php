<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\RepostEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * F-346 — POST / DELETE /feed/{id}/repost.
 *
 * The REAL endpoint runs; its collaborators are doubled in
 * `tests/Stubs/repost-endpoint-stubs.php`, which records every call into
 * ONE ordered trace. See that file for what is doubled and why — in short,
 * what is under test here is the ORCHESTRATION (both ids gated, the lock
 * wrapping the check-then-write, the ROOT reaching the writer), not the
 * gates themselves, which have their own suites.
 *
 * ⚠ So nothing here would catch a gate that wrongly admits a secret-group
 * post. It would catch the endpoint forgetting to ASK the gate, which is
 * the failure this route introduces.
 */
#[CoversClass(RepostEndpoint::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RepostEndpointTest extends TestCase
{
    private const VIEWER = 42;

    /** An ordinary post: it is its own root. */
    private const PLAIN = 500;

    /** A repost of {@see ROOT}, so the submitted id and the root differ. */
    private const A_REPOST = 600;
    private const ROOT     = 700;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/repost-endpoint-stubs.php';

        $GLOBALS['__bcc_repost_trace']  = [];
        $GLOBALS['__bcc_repost_user']   = self::VIEWER;
        $GLOBALS['__bcc_repost_routes'] = [];
        $GLOBALS['__bcc_repost_mine']   = [];
        $GLOBALS['__bcc_repost_count']  = 3;
        $GLOBALS['__bcc_repost_roots']  = [self::A_REPOST => self::ROOT];

        $GLOBALS['__bcc_repost_throttled']           = false;
        $GLOBALS['__bcc_repost_suspended']           = false;
        $GLOBALS['__bcc_repost_read_fails']          = false;
        $GLOBALS['__bcc_repost_write_fails']         = false;
        $GLOBALS['__bcc_repost_lock_held_elsewhere'] = false;
        $GLOBALS['__bcc_repost_trash_fail']          = [];

        $GLOBALS['__bcc_repost_refuse_feed_item']    = [];
        $GLOBALS['__bcc_repost_refuse_group']        = [];
        $GLOBALS['__bcc_repost_refuse_no_reactions'] = [];

        // F-346 review: the root's SHAPE and the reposter's READ access.
        $GLOBALS['__bcc_repost_comment_acts'] = [];
        $GLOBALS['__bcc_repost_unreadable']   = [];

        // F-346 X model: quotes are unlimited and counted separately.
        $GLOBALS['__bcc_repost_quote_count'] = 7;
        $GLOBALS['__bcc_repost_quote_error'] = null;
    }

    // ── helpers ──────────────────────────────────────────────────────

    private static function post(int $id): WP_REST_Response
    {
        return (new RepostEndpoint())->addRepost(new WP_REST_Request(['id' => $id]));
    }

    private static function delete(int $id): WP_REST_Response
    {
        return (new RepostEndpoint())->removeRepost(new WP_REST_Request(['id' => $id]));
    }

    private static function quote(int $id, string $body): WP_REST_Response
    {
        return (new RepostEndpoint())->addQuote(new WP_REST_Request(['id' => $id, 'body' => $body]));
    }

    /** @return list<mixed> */
    private static function trace(): array
    {
        /** @var list<mixed> $trace */
        $trace = $GLOBALS['__bcc_repost_trace'] ?? [];

        return $trace;
    }

    /** Just the event names, in order. */
    private static function events(): array
    {
        return array_map(
            static fn($entry): string => is_array($entry) ? (string) $entry[0] : (string) $entry,
            self::trace()
        );
    }

    private static function errorCode(WP_REST_Response $response): string
    {
        /** @var array{error?: array{code?: string}} $data */
        $data = $response->get_data();

        return (string) ($data['error']['code'] ?? '');
    }

    /** @return array<string, mixed> */
    private static function payload(WP_REST_Response $response): array
    {
        /** @var array{data?: array<string, mixed>} $data */
        $data = $response->get_data();

        return $data['data'] ?? [];
    }

    /**
     * The details of EVERY traced $event, in order — for asserting WHICH
     * ids a repeated collaborator was asked about, not merely that it ran.
     *
     * @return list<mixed>
     */
    private static function detailsOf(string $event): array
    {
        $out = [];
        foreach (self::trace() as $entry) {
            if (is_array($entry) && $entry[0] === $event) {
                $out[] = $entry[1];
            }
        }

        return $out;
    }

    /** The detail of the first traced $event, or null. */
    private static function detailOf(string $event)
    {
        foreach (self::trace() as $entry) {
            if (is_array($entry) && $entry[0] === $event) {
                return $entry[1];
            }
        }

        return null;
    }

    // ── routes ───────────────────────────────────────────────────────

    /**
     * ⭐ THREE routes, and the QUOTE one is deliberately its own path.
     *
     * Under the X model a plain repost is capped at one per member per root
     * while a quote is unlimited, so the two cannot share a route whose
     * once-rule turns on the presence of a body: a client sending
     * `body: ""` rather than omitting it would silently cross between
     * "capped, 409s" and "unlimited". Asserted as an exact, ordered list so
     * a fourth route, a changed method or a renamed path cannot slip in.
     */
    public function testTheThreeRoutesAreRegisteredWithTheirOwnPathsAndMethods(): void
    {
        RepostEndpoint::register();

        /** @var list<array{namespace: string, route: string, methods: mixed}> $routes */
        $routes = $GLOBALS['__bcc_repost_routes'];

        self::assertSame(
            [
                ['POST',   '/feed/(?P<id>\d+)/repost'],
                ['DELETE', '/feed/(?P<id>\d+)/repost'],
                ['POST',   '/feed/(?P<id>\d+)/quote'],
            ],
            array_map(
                static fn(array $r): array => [(string) $r['methods'], $r['route']],
                $routes
            )
        );
        foreach ($routes as $route) {
            self::assertSame('bcc/v1', $route['namespace']);
        }
    }

    // ── POST: the gate ladder ────────────────────────────────────────

    public function testAnonymousIsRefusedBeforeAnythingElseRuns(): void
    {
        $GLOBALS['__bcc_repost_user'] = 0;

        $response = self::post(self::PLAIN);

        self::assertSame(401, $response->get_status());
        self::assertSame('bcc_unauthorized', self::errorCode($response));
        self::assertSame([], self::events(), 'nothing may run before auth');
    }

    /**
     * ⚠ THROTTLE BEFORE CREDENTIALS, the house rule: a scripted burst must
     * be stopped before it can learn anything from the suspension check.
     */
    public function testTheThrottleRunsBeforeTheSuspensionCheck(): void
    {
        $GLOBALS['__bcc_repost_throttled'] = true;

        $response = self::post(self::PLAIN);

        self::assertSame(429, $response->get_status());
        self::assertSame('bcc_rate_limited', self::errorCode($response));
        self::assertSame(['throttle'], self::events());
    }

    public function testASuspendedAccountCannotRepost(): void
    {
        $GLOBALS['__bcc_repost_suspended'] = true;

        $response = self::post(self::PLAIN);

        self::assertSame(403, $response->get_status());
        self::assertSame('bcc_forbidden', self::errorCode($response));
        self::assertSame(['throttle', 'suspension'], self::events());
    }

    /**
     * ⚠ §M: the suspension gate opts OUT of the admin bypass, so an admin
     * is refused too. Asserted on the argument rather than on an admin
     * fixture, because `false` IS the opt-out.
     */
    public function testTheSuspensionCheckOptsOutOfTheAdminBypass(): void
    {
        self::post(self::PLAIN);

        self::assertSame([self::VIEWER, false], self::detailOf('suspension'));
    }

    public function testANonPositiveIdIsRejected(): void
    {
        $response = self::post(0);

        self::assertSame(400, $response->get_status());
        self::assertSame('bcc_invalid_request', self::errorCode($response));
    }

    public function testEachGateOnTheSubmittedIdShortCircuits(): void
    {
        foreach (
            [
                '__bcc_repost_refuse_feed_item'    => 'FeedItemGate',
                '__bcc_repost_refuse_group'        => 'GroupInteractionGate',
                '__bcc_repost_refuse_no_reactions' => 'NoReactionsGate',
            ] as $dial => $gate
        ) {
            $GLOBALS['__bcc_repost_trace'] = [];
            $GLOBALS['__bcc_repost_refuse_feed_item']    = [];
            $GLOBALS['__bcc_repost_refuse_group']        = [];
            $GLOBALS['__bcc_repost_refuse_no_reactions'] = [];
            $GLOBALS[$dial] = [self::PLAIN];

            $response = self::post(self::PLAIN);

            self::assertContains(
                $response->get_status(),
                [400, 404],
                $gate . ' must refuse'
            );
            self::assertNotContains(
                'createSelfStatus',
                self::events(),
                $gate . ' refused, so nothing may be written'
            );
            self::assertNotContains(
                'lock_acquire',
                self::events(),
                $gate . ' refused before the lock, so no lock is taken'
            );
        }
    }

    // ── POST: ⭐ the double root gate ─────────────────────────────────

    /**
     * ⭐ THE REASON THIS ROUTE NEEDS TWO GATES. The submitted post is
     * somebody's repost sitting on their own wall — it passes every check.
     * The content it republishes belongs to the ROOT's audience, which this
     * viewer cannot read. Gating only the submitted id republishes it.
     */
    public function testRepostingSomeoneElsesRepostIsRefusedWhenTheROOTIsUnreadable(): void
    {
        $GLOBALS['__bcc_repost_refuse_group'] = [self::ROOT];

        $response = self::post(self::A_REPOST);

        self::assertSame(404, $response->get_status());
        self::assertNotContains('createSelfStatus', self::events());

        // And it really was the root that was refused, not the submitted id.
        $groupChecks = [];
        foreach (self::trace() as $entry) {
            if (is_array($entry) && $entry[0] === 'GroupInteractionGate') {
                $groupChecks[] = $entry[1][1];
            }
        }
        self::assertSame([self::A_REPOST, self::ROOT], $groupChecks);
    }

    /** All three gates are applied to the root, not just the group one. */
    public function testEachGateIsAppliedToTheRootToo(): void
    {
        foreach (
            [
                '__bcc_repost_refuse_feed_item'    => 'FeedItemGate',
                '__bcc_repost_refuse_group'        => 'GroupInteractionGate',
                '__bcc_repost_refuse_no_reactions' => 'NoReactionsGate',
            ] as $dial => $gate
        ) {
            $GLOBALS['__bcc_repost_trace'] = [];
            $GLOBALS['__bcc_repost_refuse_feed_item']    = [];
            $GLOBALS['__bcc_repost_refuse_group']        = [];
            $GLOBALS['__bcc_repost_refuse_no_reactions'] = [];
            $GLOBALS[$dial] = [self::ROOT];

            $response = self::post(self::A_REPOST);

            self::assertContains($response->get_status(), [400, 404], $gate . ' on the root');
            self::assertNotContains('createSelfStatus', self::events(), $gate . ' on the root');
        }
    }

    /**
     * The second pass is SKIPPED when the submitted id is already the root
     * — otherwise every ordinary repost pays for a duplicate set of gate
     * queries that can only return the same verdict.
     */
    public function testTheRootIsNotGatedTwiceWhenTheSubmittedIdIsTheRoot(): void
    {
        self::post(self::PLAIN);

        $groupChecks = array_filter(
            self::events(),
            static fn(string $e): bool => $e === 'GroupInteractionGate'
        );
        self::assertCount(1, $groupChecks);
    }

    public function testAnUnresolvableRootIsNotFound(): void
    {
        $GLOBALS['__bcc_repost_roots'] = [self::PLAIN => 0];

        $response = self::post(self::PLAIN);

        self::assertSame(404, $response->get_status());
        self::assertSame('bcc_not_found', self::errorCode($response));
        self::assertNotContains('createSelfStatus', self::events());
    }

    // ── POST: the lock, the once-only rule, the write ────────────────

    /**
     * ⭐ The lock is taken BEFORE the check and the check BEFORE the write,
     * all three inside the lock. A double POST that interleaved between the
     * check and the write is the only way to duplicate a repost, since no
     * database constraint can express the rule.
     */
    public function testTheLockWrapsTheCheckAndTheWrite(): void
    {
        self::post(self::PLAIN);

        $events = self::events();
        $lock   = array_search('lock_acquire', $events, true);
        $check  = array_search('viewerReposts', $events, true);
        $write  = array_search('createSelfStatus', $events, true);
        $free   = array_search('lock_release', $events, true);

        self::assertIsInt($lock);
        self::assertIsInt($check);
        self::assertIsInt($write);
        self::assertIsInt($free);
        self::assertLessThan($check, $lock, 'the lock must precede the check');
        self::assertLessThan($write, $check, 'the check must precede the write');
        self::assertLessThan($free, $write, 'the lock is released after the write');
    }

    /**
     * ⚠ KEYED ON (MEMBER, ROOT) — not on the submitted id, or two requests
     * naming different ids for the same content would not contend.
     */
    public function testTheLockIsKeyedOnTheMemberAndTheRoot(): void
    {
        self::post(self::A_REPOST);

        self::assertSame(
            ['bcc_repost_' . self::VIEWER . '_' . self::ROOT, 5],
            self::detailOf('lock_acquire')
        );
    }

    public function testContentionIsRetryableAndWritesNothing(): void
    {
        $GLOBALS['__bcc_repost_lock_held_elsewhere'] = true;

        $response = self::post(self::PLAIN);

        self::assertSame(429, $response->get_status());
        self::assertSame('bcc_rate_limited', self::errorCode($response));
        self::assertNotContains('viewerReposts', self::events());
        self::assertNotContains('createSelfStatus', self::events());
    }

    public function testASecondRepostOfTheSameRootIsAConflict(): void
    {
        $GLOBALS['__bcc_repost_mine'] = [['act_id' => 11, 'post_id' => 12]];

        $response = self::post(self::PLAIN);

        self::assertSame(409, $response->get_status());
        self::assertSame('bcc_conflict', self::errorCode($response));
        self::assertNotContains('createSelfStatus', self::events());
    }

    /**
     * ⚠⚠ FAIL CLOSED. An unreadable table is the one read whose failure
     * would create the duplicate, so it must refuse — never fall through
     * to the write as "has not reposted yet".
     */
    public function testAnUnreadableCheckRefusesInsteadOfWriting(): void
    {
        $GLOBALS['__bcc_repost_read_fails'] = true;

        $response = self::post(self::PLAIN);

        self::assertSame(503, $response->get_status());
        self::assertSame('bcc_unavailable', self::errorCode($response));
        self::assertNotContains('createSelfStatus', self::events());
    }

    /** ⭐ The writer is handed the ROOT, with empty content and no group. */
    public function testTheWriteCarriesTheRootAndAnEmptyBody(): void
    {
        self::post(self::A_REPOST);

        self::assertSame(
            [
                'author'     => self::VIEWER,
                'content'    => '',
                'group'      => 0,
                'visibility' => 'members_only',
                'root'       => self::ROOT,
            ],
            self::detailOf('createSelfStatus')
        );
    }

    public function testAFailedWriteIsAnInternalErrorAndStillReleasesTheLock(): void
    {
        $GLOBALS['__bcc_repost_write_fails'] = true;

        $response = self::post(self::PLAIN);

        self::assertSame(500, $response->get_status());
        self::assertSame('bcc_internal_error', self::errorCode($response));
        self::assertContains('lock_release', self::events());
    }

    /**
     * ⚠ The lock is released on EVERY exit from the locked block, not just
     * the happy one — a `finally`, not a line at the end. A leaked lock
     * would freeze this member's repost button until the session ended.
     */
    public function testTheLockIsReleasedOnEveryRefusalInsideIt(): void
    {
        foreach (
            [
                '__bcc_repost_mine'          => [['act_id' => 11, 'post_id' => 12]],
                '__bcc_repost_read_fails'    => true,
                '__bcc_repost_write_fails'   => true,
            ] as $dial => $value
        ) {
            $GLOBALS['__bcc_repost_trace']      = [];
            $GLOBALS['__bcc_repost_mine']        = [];
            $GLOBALS['__bcc_repost_read_fails']  = false;
            $GLOBALS['__bcc_repost_write_fails'] = false;
            $GLOBALS[$dial] = $value;

            self::post(self::PLAIN);

            self::assertContains('lock_release', self::events(), $dial . ' must still release');
        }
    }

    // ── POST: the response ───────────────────────────────────────────

    public function testTheSuccessPayloadReportsTheRootAndTheCount(): void
    {
        $GLOBALS['__bcc_repost_count'] = 7;
        $GLOBALS['__bcc_repost_mine']  = [];

        $response = self::post(self::A_REPOST);
        self::assertSame(200, $response->get_status());

        $payload = self::payload($response);
        self::assertSame('feed_' . self::ROOT, $payload['root_feed_id']);
        self::assertSame(7, $payload['repost_count']);
        self::assertSame('no-store', $response->get_headers()['Cache-Control'] ?? '');
    }

    /**
     * ⚠ `repost_count: null` means "could not read", never 0 — the
     * F-197/F-216/F-230 posture. A successful write can still answer null,
     * and that is honest rather than contradictory.
     */
    public function testAnUnreadableCountIsNullAndNotZero(): void
    {
        $GLOBALS['__bcc_repost_count'] = null;

        $payload = self::payload(self::post(self::PLAIN));

        self::assertArrayHasKey('repost_count', $payload);
        self::assertNull($payload['repost_count']);
    }

    // ── DELETE ───────────────────────────────────────────────────────

    public function testUnRepostTrashesEveryOneOfTheMembersRepostsOfTheRoot(): void
    {
        $GLOBALS['__bcc_repost_mine'] = [
            ['act_id' => 11, 'post_id' => 1100],
            ['act_id' => 22, 'post_id' => 2200],
        ];

        $response = self::delete(self::ROOT);
        self::assertSame(200, $response->get_status());

        $trashed = [];
        foreach (self::trace() as $entry) {
            if (is_array($entry) && $entry[0] === 'trash') {
                $trashed[] = $entry[1];
            }
        }
        self::assertSame([1100, 2200], $trashed, 'a duplicate that slipped through is cleaned up too');
    }

    /**
     * ⚠ The SAME event the owner-delete path fires, with the same argument
     * order — it is what evicts the post from the warmed hot-feed page
     * (F-277) and what the Rank ledger keys its reversal on.
     */
    public function testUnRepostFiresThePostDeletedEventPerRemovedRepost(): void
    {
        $GLOBALS['__bcc_repost_mine'] = [['act_id' => 11, 'post_id' => 1100]];

        self::delete(self::ROOT);

        self::assertSame(
            ['bcc_post_deleted', self::VIEWER, 11, 'owner', 1100],
            self::detailOf('do_action')
        );
    }

    /**
     * ⭐ DELIBERATELY NOT AUDIENCE-GATED. A root can stop being readable
     * after the fact; an audience gate here would strand the member with a
     * post on their own wall they cannot remove. Nothing this route touches
     * belongs to anyone else.
     */
    public function testUnRepostSucceedsEvenWhenTheRootIsNoLongerReadable(): void
    {
        $GLOBALS['__bcc_repost_mine']             = [['act_id' => 11, 'post_id' => 1100]];
        $GLOBALS['__bcc_repost_refuse_group']     = [self::ROOT, self::A_REPOST];
        $GLOBALS['__bcc_repost_refuse_feed_item'] = [self::ROOT, self::A_REPOST];

        $response = self::delete(self::A_REPOST);

        self::assertSame(200, $response->get_status());
        self::assertContains('trash', self::events());
        self::assertNotContains(
            'GroupInteractionGate',
            self::events(),
            'no audience gate runs on the un-repost path at all'
        );
    }

    /**
     * ⚠ WHAT REPLACES THE AUDIENCE GATE: with no gate, an idempotent 200
     * would answer "does act N exist" and hand back its repost count for
     * ANY id. Answering only when the caller owned a repost closes that
     * oracle, so this route diverges from Stoke's idempotent DELETE.
     */
    public function testUnRepostingNothingIsNotFoundRatherThanAnIdempotentSuccess(): void
    {
        $GLOBALS['__bcc_repost_mine'] = [];

        $response = self::delete(self::ROOT);

        self::assertSame(404, $response->get_status());
        self::assertSame('bcc_not_found', self::errorCode($response));
        self::assertNotContains('trash', self::events());
        self::assertNotContains(
            'repostCount',
            self::events(),
            'and it must not read the count either — that is the signal being withheld'
        );
    }

    public function testUnRepostFailsClosedWhenTheReadFails(): void
    {
        $GLOBALS['__bcc_repost_read_fails'] = true;

        $response = self::delete(self::ROOT);

        self::assertSame(503, $response->get_status());
        self::assertSame('bcc_unavailable', self::errorCode($response));
        self::assertNotContains('trash', self::events());
    }

    public function testAFailedTrashIsAnInternalErrorAndReleasesTheLock(): void
    {
        $GLOBALS['__bcc_repost_mine']       = [['act_id' => 11, 'post_id' => 1100]];
        $GLOBALS['__bcc_repost_trash_fail'] = [1100];

        $response = self::delete(self::ROOT);

        self::assertSame(500, $response->get_status());
        self::assertSame('bcc_internal_error', self::errorCode($response));
        self::assertContains('lock_release', self::events());
    }

    public function testUnRepostTakesTheSameLockAsTheRepost(): void
    {
        $GLOBALS['__bcc_repost_mine'] = [['act_id' => 11, 'post_id' => 1100]];

        self::delete(self::A_REPOST);

        self::assertSame(
            ['bcc_repost_' . self::VIEWER . '_' . self::ROOT, 5],
            self::detailOf('lock_acquire')
        );
    }

    public function testUnRepostIsFrozenForASuspendedAccount(): void
    {
        $GLOBALS['__bcc_repost_suspended'] = true;

        $response = self::delete(self::ROOT);

        self::assertSame(403, $response->get_status());
        self::assertNotContains('trash', self::events());
    }

    public function testAnonymousCannotUnRepost(): void
    {
        $GLOBALS['__bcc_repost_user'] = 0;

        $response = self::delete(self::ROOT);

        self::assertSame(401, $response->get_status());
        self::assertSame([], self::events());
    }

    // ── POST: the ROOT must be a TOP-LEVEL post (F-346 review) ──────

    /**
     * ⚠⚠ THE FAILURE THIS CLOSES. PeepSo writes an activities row for
     * EVERY comment and stores `act_module_id = 1` on it — the same module
     * a status post carries. So a comment act id is ON the public-feed
     * module allowlist and passes FeedItemGate, and GroupInteractionGate
     * deliberately ADMITS a comment act by gating it on its parent post.
     * Nothing upstream refuses it, and a repost row keyed on a comment
     * would publish that comment as a standalone feed item.
     */
    public function testACommentRootIsRefusedAndNothingIsWritten(): void
    {
        $GLOBALS['__bcc_repost_comment_acts'] = [self::ROOT];

        $response = self::post(self::A_REPOST);

        self::assertSame(404, $response->get_status());
        self::assertSame('bcc_not_found', self::errorCode($response));
        self::assertNotContains('createSelfStatus', self::events(), 'a comment must never be reposted');
    }

    /** The same refusal when the comment id is submitted directly. */
    public function testACommentSubmittedDirectlyIsRefused(): void
    {
        $GLOBALS['__bcc_repost_comment_acts'] = [self::PLAIN];

        $response = self::post(self::PLAIN);

        self::assertSame(404, $response->get_status());
        self::assertNotContains('createSelfStatus', self::events());
    }

    /**
     * ⚠ Before the LOCK, not merely before the write: a refusal that took
     * the lock first would let a comment id contend for — and briefly hold
     * — the lock of the root it resolves to.
     */
    public function testTheCommentCheckRunsBeforeTheLockIsTaken(): void
    {
        $GLOBALS['__bcc_repost_comment_acts'] = [self::ROOT];

        self::post(self::A_REPOST);

        self::assertNotContains('lock_acquire', self::events());
        self::assertContains('CommentRepository::getCommentMeta', self::events());
    }

    /**
     * ⭐ Asked about the ROOT, which is the id that gets STORED. Keyed on
     * the submitted id instead, the check is bypassed by reposting a
     * comment through any id that resolves to it.
     */
    public function testTheCommentCheckIsAskedAboutTheRootNotTheSubmittedId(): void
    {
        self::post(self::A_REPOST);

        self::assertSame(
            [self::ROOT],
            self::detailsOf('CommentRepository::getCommentMeta'),
            'the shape check must name the root'
        );
    }

    /**
     * A DM activity (PeepSo module 6) never reaches the writer.
     *
     * ⚠ HONEST SCOPE: FeedItemGate is DOUBLED here, so this pins only that
     * the endpoint honours its refusal. The load-bearing half — that
     * PeepSo's non-post modules really are off the public-feed allowlist,
     * so a DM act id really does get refused — is asserted against the
     * REAL allowlist in FeedItemGateTest
     * ({@see FeedItemGateTest::testPeepSoNonPostModulesAreNotFeedItems()}).
     * Neither test is worth much without the other.
     */
    public function testAMessageActivityIsRefusedBeforeAnythingIsWritten(): void
    {
        $dmActId = 6006;
        $GLOBALS['__bcc_repost_refuse_feed_item'] = [$dmActId];

        $response = self::post($dmActId);

        self::assertSame(404, $response->get_status());
        self::assertSame('bcc_not_found', self::errorCode($response));
        self::assertNotContains('createSelfStatus', self::events());
        self::assertNotContains('lock_acquire', self::events());
    }

    // ── POST: the reposter must be able to READ the root ─────────────

    /**
     * ⚠⚠ A DIFFERENT QUESTION FROM THE GATES ABOVE, which answer "may this
     * member INTERACT here". They do not apply the viewer-specific
     * presentation filters — mutual blocks either way, the caution tier,
     * the moderation hide list — because FeedItemReportVisibility
     * deliberately skips them for REPORTING. Reposting republishes, so this
     * route asks the permalink's question too.
     */
    public function testARootTheMemberCannotReadIsRefusedAndNothingIsWritten(): void
    {
        $GLOBALS['__bcc_repost_unreadable'] = [self::ROOT];

        $response = self::post(self::A_REPOST);

        self::assertSame(404, $response->get_status());
        self::assertSame('bcc_not_found', self::errorCode($response));
        self::assertNotContains('createSelfStatus', self::events());
    }

    /** Before the lock, for the same reason the shape check is. */
    public function testTheReadCheckRunsBeforeTheLockIsTaken(): void
    {
        $GLOBALS['__bcc_repost_unreadable'] = [self::ROOT];

        self::post(self::A_REPOST);

        self::assertNotContains('lock_acquire', self::events());
        self::assertContains('FeedRankingService::getActivityById', self::events());
    }

    /**
     * ⭐ Asked about the ROOT, and about the ROOT ONLY — the root carries
     * the content being republished. Keyed on the submitted id, a member
     * could republish a post they are blocked from by going through
     * somebody else's repost of it.
     */
    public function testTheReadCheckIsAskedAboutTheRootAndNamesTheViewer(): void
    {
        self::post(self::A_REPOST);

        self::assertSame(
            [[self::ROOT, self::VIEWER]],
            self::detailsOf('FeedRankingService::getActivityById'),
            'the read gate must name the root and the viewer'
        );
    }

    /**
     * ⚠ Anti-vacuity for BOTH new dials: with them off, the ordinary path
     * still reaches the write. Without this, a check that refused
     * everything would satisfy every test above.
     */
    public function testAReadableTopLevelRootStillReachesTheWrite(): void
    {
        $response = self::post(self::A_REPOST);

        self::assertSame(200, $response->get_status());
        self::assertContains('createSelfStatus', self::events());
        self::assertContains('CommentRepository::getCommentMeta', self::events());
        self::assertContains('FeedRankingService::getActivityById', self::events());
    }

    // ── DELETE skips both, deliberately ─────────────────────────────

    /**
     * ⚠ Un-repost is NOT audience-gated (the ratified divergence from
     * /stoke), and neither new check applies to it either: it only ever
     * trashes rows this member owns, and a root that became unreadable —
     * or that was never a post — must not strand them with a repost on
     * their own wall they cannot remove.
     */
    public function testUnRepostConsultsNeitherNewCheck(): void
    {
        $GLOBALS['__bcc_repost_mine']          = [['act_id' => 601, 'post_id' => 9601]];
        $GLOBALS['__bcc_repost_unreadable']    = [self::ROOT];
        $GLOBALS['__bcc_repost_comment_acts']  = [self::ROOT];

        $response = self::delete(self::A_REPOST);

        self::assertSame(200, $response->get_status());
        self::assertNotContains('FeedRankingService::getActivityById', self::events());
        self::assertNotContains('CommentRepository::getCommentMeta', self::events());
        self::assertContains('trash', self::events());
    }

    // ── POST /quote — the X model (user decision 2026-11-18) ────────

    /**
     * ⭐ THE DIFFERENCE THAT DEFINES THIS ROUTE: a quote is UNLIMITED, so
     * nothing here may take the lock or run the once-only check. Two quotes
     * in a row both succeed, and neither sees a 409.
     *
     * The lock in addRepost() exists only to make "once per member per root"
     * true. There is no "once" here to protect, and a lock would serialise
     * unrelated writes for no reason.
     */
    public function testAQuoteIsNotCappedAndTakesNoLock(): void
    {
        $first  = self::quote(self::A_REPOST, 'first thoughts');
        $second = self::quote(self::A_REPOST, 'more thoughts');

        self::assertSame(200, $first->get_status());
        self::assertSame(200, $second->get_status());
        self::assertNotContains('lock_acquire', self::events(), 'a quote must not take the repost lock');

        // ⚠ `viewerReposts` DOES appear here, and must: buildStateResponse
        // reads it to answer `viewer_has_reposted`. What would make it a cap
        // is reading it BEFORE the write and refusing on it, so the property
        // worth pinning is the ORDER — the write happens first, so no read
        // of the member's existing reposts can gate a quote.
        $events = self::events();
        $write  = array_search('createStatus', $events, true);
        $read   = array_search('viewerReposts', $events, true);
        self::assertIsInt($write);
        self::assertIsInt($read);
        self::assertLessThan($read, $write, 'the quote is written before its state is read back');
    }

    /**
     * ⚠ The write is DELEGATED to PostsService::createStatus(), which is
     * what makes a quote obey every status rule (the §D2 cap, mentions,
     * moderation, the §A3 event) instead of this handler re-validating
     * content. So what is asserted here is the delegation, and that it
     * receives the TRIMMED body and the resolved ROOT.
     */
    public function testTheQuoteIsWrittenThroughTheStatusPathWithTheRootAndTrimmedBody(): void
    {
        self::quote(self::A_REPOST, '  worth reading  ');

        self::assertSame(
            ['author' => self::VIEWER, 'content' => 'worth reading', 'root' => self::ROOT],
            self::detailOf('createStatus')
        );
        self::assertNotContains('createSelfStatus', self::events(), 'the status path owns the write, not this route');
    }

    /** A quote with nothing in it is a plain repost, and that is the other route. */
    public function testAnEmptyOrWhitespaceQuoteIsRefused(): void
    {
        foreach (['', '   ', "\n\t "] as $blank) {
            $response = self::quote(self::A_REPOST, $blank);

            self::assertSame(400, $response->get_status(), 'blank quote must be refused');
            self::assertSame('bcc_invalid_request', self::errorCode($response));
            self::assertNotContains('createStatus', self::events(), 'nothing may be written');
        }
    }

    /** A service-side refusal (the cap, mentions, moderation) is forwarded as-is. */
    public function testAStatusRefusalIsForwardedWithItsOwnCodeAndStatus(): void
    {
        $GLOBALS['__bcc_repost_quote_error'] = [
            'error'   => 'bcc_too_many_mentions',
            'message' => 'Too many mentions.',
        ];

        $response = self::quote(self::A_REPOST, '@a @b @c');

        self::assertSame(400, $response->get_status());
        self::assertSame('bcc_too_many_mentions', self::errorCode($response));
    }

    /**
     * ⚠ THROTTLED BEFORE THE GATES. The seatbelt inside createStatus()
     * guards the write, but it is only reached once the gates have
     * answered — so without this, act ids could be probed through the quote
     * route at full speed. Same numbers as a status post, because a quote
     * is one.
     */
    public function testTheQuoteRouteThrottlesOnItsOwnKeyBeforeTheGates(): void
    {
        self::quote(self::A_REPOST, 'hello');

        self::assertSame(
            ['quote_' . self::VIEWER, 5, 120],
            self::detailOf('throttle'),
            'the quote route uses the STATUS-post limits, on its own key'
        );
        self::assertSame('throttle', self::events()[0] ?? null, 'and it runs first');
    }

    public function testAThrottledQuoteWritesNothing(): void
    {
        $GLOBALS['__bcc_repost_throttled'] = true;

        $response = self::quote(self::A_REPOST, 'hello');

        self::assertSame(429, $response->get_status());
        self::assertSame(['throttle'], self::events());
    }

    /**
     * ⭐ The quote route runs the SAME root ladder as the plain one — the
     * double gate, the comment refusal and the read check — because both
     * answer the same question about WHAT is being republished. They share
     * one helper for exactly this reason.
     */
    public function testTheQuoteRouteRunsTheSameRootLadder(): void
    {
        $GLOBALS['__bcc_repost_unreadable'] = [self::ROOT];
        self::assertSame(404, self::quote(self::A_REPOST, 'hi')->get_status(), 'unreadable root');
        self::assertNotContains('createStatus', self::events());

        $GLOBALS['__bcc_repost_unreadable']   = [];
        $GLOBALS['__bcc_repost_trace']        = [];
        $GLOBALS['__bcc_repost_comment_acts'] = [self::ROOT];
        self::assertSame(404, self::quote(self::A_REPOST, 'hi')->get_status(), 'comment root');
        self::assertNotContains('createStatus', self::events());

        $GLOBALS['__bcc_repost_comment_acts'] = [];
        $GLOBALS['__bcc_repost_trace']        = [];
        $GLOBALS['__bcc_repost_refuse_group'] = [self::ROOT];
        self::assertSame(404, self::quote(self::A_REPOST, 'hi')->get_status(), 'group gate on the root');
        self::assertNotContains('createStatus', self::events());
    }

    public function testAnonymousCannotQuote(): void
    {
        $GLOBALS['__bcc_repost_user'] = 0;

        $response = self::quote(self::A_REPOST, 'hi');

        self::assertSame(401, $response->get_status());
        self::assertSame([], self::events());
    }

    /**
     * The response carries the new post's id so a client can link to it,
     * plus the same count block the plain route returns.
     */
    public function testTheQuoteResponseCarriesTheNewPostIdAndBothCounts(): void
    {
        $GLOBALS['__bcc_repost_count']       = 4;
        $GLOBALS['__bcc_repost_quote_count'] = 9;

        $payload = self::payload(self::quote(self::A_REPOST, 'hi'));

        self::assertSame('feed_' . self::ROOT, $payload['root_feed_id']);
        self::assertSame('feed_9100', $payload['quote_feed_id'], 'the new quote post');
        self::assertSame(4, $payload['repost_count']);
        self::assertSame(9, $payload['quote_count']);
    }

    /**
     * ⚠ The two counts are SEPARATE reads with separate meanings, so a
     * handler that answered one for the other would be caught here: the
     * dials are set to different values on purpose.
     */
    public function testQuoteCountIsOnThePlainRepostResponseToo(): void
    {
        $GLOBALS['__bcc_repost_count']       = 2;
        $GLOBALS['__bcc_repost_quote_count'] = 11;

        $payload = self::payload(self::post(self::PLAIN));

        self::assertSame(2, $payload['repost_count']);
        self::assertSame(11, $payload['quote_count']);
    }

    /** null is "could not read", never 0 — for the quote count as well. */
    public function testAnUnreadableQuoteCountIsNullNotZero(): void
    {
        $GLOBALS['__bcc_repost_quote_count'] = null;

        $payload = self::payload(self::quote(self::A_REPOST, 'hi'));

        self::assertNull($payload['quote_count']);
    }

    // ── anti-vacuity ─────────────────────────────────────────────────

    /**
     * ⚠ Every refusal above is asserted by status code, so a handler that
     * refused EVERYTHING would pass most of them. This is the control: the
     * ordinary path really does reach the write and return 200.
     */
    public function testTheOrdinaryPathReachesTheWriteAndSucceeds(): void
    {
        $response = self::post(self::PLAIN);

        self::assertSame(200, $response->get_status());
        self::assertContains('createSelfStatus', self::events());
        self::assertSame('', self::errorCode($response));
    }
}
