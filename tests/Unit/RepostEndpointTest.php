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

    public function testBothRoutesAreRegisteredOnTheSamePath(): void
    {
        RepostEndpoint::register();

        /** @var list<array{namespace: string, route: string, methods: mixed}> $routes */
        $routes = $GLOBALS['__bcc_repost_routes'];

        self::assertCount(2, $routes);
        foreach ($routes as $route) {
            self::assertSame('bcc/v1', $route['namespace']);
            self::assertSame('/feed/(?P<id>\d+)/repost', $route['route']);
        }
        self::assertSame('POST', $routes[0]['methods']);
        self::assertSame('DELETE', $routes[1]['methods']);
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
