<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Support\RingIdentity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;

/**
 * Which accounts a vote-ring action is allowed to act on.
 *
 * ── ⚠⚠ WHAT WAS WRONG ──────────────────────────────────────────────────
 * The two destructive ring actions — invalidate-ring-votes and suspend-all —
 * took their target list from a hidden form field:
 *
 *  1. THE RING WAS SILENTLY TRUNCATED TO ITS FIRST MEMBER. The form posted a
 *     COMMA-SEPARATED STRING ("11,12,13"); the handler cast it with
 *     `(array)`, giving `['11,12,13']`, and absint() of that whole string is
 *     11. "Suspend ALL 3 members" suspended exactly one. The redirect said
 *     `suspended_1` and nothing rendered ring_msg, so nobody could see it.
 *
 *  2. The set of accounts to suspend arrived from the browser. The
 *     manage_options check means only an administrator could reach it, so it
 *     was never a privilege escalation — but the `vote_ring` suspension
 *     reason and the `ring_users` audit row were claims nothing verified.
 *
 * ── ⚠ IT RESOLVES AGAINST THE STORE THAT ALREADY EXISTS ────────────────
 * RingIdentity reads `wp_cache_get('ring_results', 'bcc_trust_admin')` — the
 * same key getRingsData() renders from and dailyGraphUpdate() writes. So this
 * change carries NO storage change, NO payload normalisation and NO scoring
 * change, and these tests seed that key directly to prove it.
 *
 * ── ⚠ AND IT EXERCISES THE HANDLER, NOT ITS SOURCE TEXT ────────────────
 * An earlier version of this suite asserted on the hook's SOURCE
 * (assertStringContainsString('RingSnapshot::findRingByFingerprint', $src)).
 * That passes whether or not the referenced symbol exists — which is exactly
 * how a reference to a Logger class that does not exist reached the refusal
 * path and would have fatalled there. Section 2 calls
 * bcc_trust_resolve_ring_action() for real, so the refusal path RUNS,
 * including its Logger call.
 *
 * It never calls handleRingAction(), because that suspends accounts and
 * soft-deletes votes. The resolver is the gate; proving the gate refuses
 * proves nothing downstream can run.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RingActionSafetyIntegrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // ⚠ The resolver lives in includes/admin/moderation.php, which also
        // registers admin hooks and requires five siblings. Only the one
        // function is needed, so it is extracted rather than the file
        // included — including it would register hooks into the shared test
        // process and pull in surfaces this suite is not about.
        if (!function_exists('bcc_trust_resolve_ring_action')) {
            $src = file_get_contents(dirname(__DIR__, 2) . '/includes/admin/moderation.php');
            self::assertIsString($src, 'moderation.php must be readable');
            $start = strpos($src, 'function bcc_trust_resolve_ring_action');
            self::assertNotFalse($start, 'the resolver must exist');
            $end = strpos($src, "\n}", $start);
            self::assertNotFalse($end, 'and be brace-terminated');
            eval(substr($src, $start, $end - $start + 2));
        }
        self::assertTrue(
            function_exists('bcc_trust_resolve_ring_action'),
            'precondition: the resolver must be callable, or every case below is vacuous'
        );
    }

    protected function setUp(): void
    {
        wp_cache_delete(RingIdentity::CACHE_KEY, RingIdentity::CACHE_GROUP);
        delete_transient('bcc_trust_ring_snapshot');
    }

    protected function tearDown(): void
    {
        wp_cache_delete(RingIdentity::CACHE_KEY, RingIdentity::CACHE_GROUP);
        // ⚠ BOTH stores: the snapshot now has a durable copy, and a
        // transient surviving between cases would make a later "refuses"
        // assertion pass or fail on leftover state.
        delete_transient('bcc_trust_ring_snapshot');
        unset($GLOBALS['__bcc_test_ext_object_cache']);
    }

    /**
     * Seed the store the tab renders from.
     *
     * ⚠ THIS MOVED WITH THE SNAPSHOT STORE. It used to write the raw list
     * straight to wp_cache_set(CACHE_KEY, ...), because that was the store.
     * The store now wraps that key in a payload and adds a durable
     * transient, so seeding the raw shape would no longer resolve — which is
     * precisely the dependency between the two changes, and is why these
     * cases now go through the writer.
     *
     * ⚠ The INPUT is still the raw merged producer shape. normalise()
     * runs inside store(), so handing it a tidied payload would test a shape
     * nothing produces.
     */
    private function seed(array $rings): void
    {
        \BCC\Trust\Core\Support\RingSnapshot::store($rings);
    }

    /** detectVoteRings() shape: no `type`, a real `strength`. */
    private function voteRing(array $users): array
    {
        return ['users' => $users, 'size' => count($users), 'strength' => 0.88, 'detected_at' => '2026-10-12 00:00:00'];
    }

    /** detectEndorsementRings() shape: a `type`, no `strength`. */
    private function endorsementRing(array $users): array
    {
        return ['users' => $users, 'size' => count($users), 'type' => 'endorsement_ring', 'detected_at' => '2026-10-12 00:00:00'];
    }

    // ── 0. ⚠⚠ THE TRUNCATION, AND THE PARSE THAT REPLACES IT
    // ═══════════════════════════════════════════════════════════════════

    public function testTheOldCsvHandlingReducedAThreeMemberRingToOne(): void
    {
        // ⚠ The defect reproduced exactly as the handler performed it, so the
        // number below is a measurement rather than a claim in a comment.
        $asTheOldHandlerDidIt = array_filter(array_map('absint', (array) '11,12,13'));

        self::assertSame([11], $asTheOldHandlerDidIt, 'absint() of the whole CSV is its first number');
    }

    public function testMembersParsesTheWholeRing(): void
    {
        self::assertSame([11, 12, 13], RingIdentity::members($this->voteRing([11, 12, 13])));
    }

    public function testMembersReadsUsersNotMembers(): void
    {
        // ⚠ The tab read $ring['members'] and array_column(…, 'user_id') over
        // it — a different key AND a different shape. Both detectors emit
        // `users` as a flat int list, so the old read always produced [].
        self::assertSame([], RingIdentity::members(['members' => [['user_id' => 11]]]));
        self::assertSame([11], RingIdentity::members(['users' => [11]]));
    }

    public function testMembersNormalisesWithoutInventing(): void
    {
        self::assertSame([5, 6, 7], RingIdentity::members(['users' => ['5', 6, '7']]), 'ints, in order');
        self::assertSame([5], RingIdentity::members(['users' => [5, 5, 5]]), 'de-duplicated');
        self::assertSame([], RingIdentity::members(['users' => [0, -1, 'x']]), 'no id is invented from junk');
        self::assertSame([], RingIdentity::members(['size' => 3]), 'and a ring with no users has no members');
    }

    // ── 1. RESOLUTION, BOTH DETECTOR FORMATS
    // ═══════════════════════════════════════════════════════════════════

    public function testAVoteRingResolves(): void
    {
        $this->seed([$this->voteRing([31, 32, 33])]);

        $ring = RingIdentity::findByFingerprint(RingIdentity::fingerprint([31, 32, 33]));

        self::assertNotNull($ring);
        self::assertSame([31, 32, 33], RingIdentity::members($ring));
        self::assertFalse(RingIdentity::isEndorsementRing($ring));
    }

    public function testAnEndorsementRingResolvesToo(): void
    {
        // ⚠ The shape with no `strength` key at all. If resolution depended
        // on the vote shape, half the rings on the tab would fail at SUBMIT
        // time — after the operator confirmed a suspension.
        $this->seed([$this->endorsementRing([41, 42, 43, 44])]);

        $ring = RingIdentity::findByFingerprint(RingIdentity::fingerprint([41, 42, 43, 44]));

        self::assertNotNull($ring);
        self::assertSame([41, 42, 43, 44], RingIdentity::members($ring));
        self::assertTrue(RingIdentity::isEndorsementRing($ring));
    }

    public function testEachRingInAMixedListResolvesToITSOWNMembers(): void
    {
        // ⚠ THE CASE THAT MATTERS MOST: suspending the wrong ring's members
        // is the worst available outcome, and both shapes sit in the same
        // array because getSuspiciousClusters() array_merges them.
        $this->seed([$this->voteRing([11, 12, 13]), $this->endorsementRing([21, 22, 23, 24])]);

        $a = RingIdentity::findByFingerprint(RingIdentity::fingerprint([11, 12, 13]));
        $b = RingIdentity::findByFingerprint(RingIdentity::fingerprint([21, 22, 23, 24]));

        self::assertSame([11, 12, 13], RingIdentity::members($a));
        self::assertSame([21, 22, 23, 24], RingIdentity::members($b));
    }

    public function testTheFingerprintIsOrderIndependent(): void
    {
        // The producer's component order is a BFS artefact and is not stable.
        $this->seed([$this->voteRing([13, 11, 12])]);

        self::assertNotNull(RingIdentity::findByFingerprint(RingIdentity::fingerprint([11, 12, 13])));
    }

    public function testEveryUncertainCaseRefuses(): void
    {
        $fp = RingIdentity::fingerprint([11, 12, 13]);

        // no list at all
        self::assertNull(RingIdentity::findByFingerprint($fp), 'no ring list');

        $this->seed([]);
        self::assertNull(RingIdentity::findByFingerprint($fp), 'empty list');

        $this->seed([$this->voteRing([11, 12, 13])]);
        self::assertNull(RingIdentity::findByFingerprint(RingIdentity::fingerprint([11, 12, 14])), 'different ring');
        self::assertNull(RingIdentity::findByFingerprint(RingIdentity::fingerprint([11, 12])), 'subset');
        self::assertNull(RingIdentity::findByFingerprint(RingIdentity::fingerprint([11, 12, 13, 99])), 'superset');
        self::assertNull(RingIdentity::findByFingerprint(strtoupper($fp)), 'uppercase hash');
        foreach (['', 'x', '11,12,13', str_repeat('z', 32), '%'] as $bad) {
            self::assertNull(RingIdentity::findByFingerprint($bad), var_export($bad, true));
        }
        // and the exact one still works, so none of the above is vacuous
        self::assertNotNull(RingIdentity::findByFingerprint($fp), 'the exact fingerprint must still resolve');
    }

    public function testANonArrayCachedValueRefuses(): void
    {
        // wp_cache can hold anything. A scalar must not be iterated.
        $GLOBALS['__bcc_test_ext_object_cache'] = true;
        wp_cache_set(RingIdentity::CACHE_KEY, 'not-an-array', RingIdentity::CACHE_GROUP, 3600);

        try {
            self::assertNull(RingIdentity::findByFingerprint(RingIdentity::fingerprint([11, 12, 13])));
        } finally {
            unset($GLOBALS['__bcc_test_ext_object_cache']);
        }
    }

    public function testAPreChangeBareListInTheCacheRefuses(): void
    {
        // ⚠⚠ THE DEPLOY-WINDOW CASE, and the reason the dependency between
        // these two changes is one-directional. A host WITH a persistent
        // object cache can carry a pre-change BARE LIST across the deploy.
        // Reading it would mean dereferencing a computed_at that is not
        // there; refusing until the next daily run is the honest and the safe
        // answer, and it means the destructive buttons refuse rather than
        // acting on a list this build cannot vouch for.
        $GLOBALS['__bcc_test_ext_object_cache'] = true;
        wp_cache_set(
            RingIdentity::CACHE_KEY,
            [['users' => [11, 12, 13], 'size' => 3, 'strength' => 0.9]],
            RingIdentity::CACHE_GROUP,
            3600
        );

        try {
            self::assertNull(
                RingIdentity::findByFingerprint(RingIdentity::fingerprint([11, 12, 13])),
                'a pre-wrapper value is not a payload'
            );
        } finally {
            unset($GLOBALS['__bcc_test_ext_object_cache']);
        }
    }

    public function testAMalformedRingElementIsSkippedNotFatal(): void
    {
        $this->seed([
            'a scalar where a ring should be',
            ['users' => 'not-a-list'],
            $this->voteRing([11, 12, 13]),
        ]);

        self::assertSame(
            [11, 12, 13],
            RingIdentity::members(RingIdentity::findByFingerprint(RingIdentity::fingerprint([11, 12, 13])))
        );
    }

    // ── 2. ⚠⚠ THE REFUSAL PATHS, EXECUTED — INCLUDING THE LOGGER CALL
    // ═══════════════════════════════════════════════════════════════════
    //
    // These call the real resolver. A reference to a class that does not
    // exist fatals here, which is the whole point: the previous suite
    // asserted on the handler's source text and a non-existent Logger passed
    // it while being a guaranteed fatal at runtime.

    public function testTheLoggerClassTheRefusalPathCallsActuallyExists(): void
    {
        // ⚠ Stated directly as well as exercised, so a failure names the
        // cause instead of surfacing as "Class not found" inside a redirect.
        self::assertTrue(
            class_exists(\BCC\Core\Log\Logger::class),
            'the refusal path calls BCC\\Core\\Log\\Logger — NOT BCC\\Trust\\Core\\Log\\Logger, which does not exist'
        );
        self::assertTrue(method_exists(\BCC\Core\Log\Logger::class, 'warning'));
    }

    public function testAnUnmatchedFingerprintRefusesAndRunsItsLogger(): void
    {
        $this->seed([$this->voteRing([11, 12, 13])]);

        $r = bcc_trust_resolve_ring_action([
            'ring_action'      => 'suspend_all',
            'ring_fingerprint' => RingIdentity::fingerprint([77, 78, 79]),
        ]);

        self::assertSame('ring_not_found', $r['refusal']);
        self::assertNull($r['ring']);
        self::assertSame([], $r['user_ids'], 'nothing becomes actionable');
        self::assertSame('suspend_all', $r['action']);
    }

    public function testAnAbsentFingerprintRefuses(): void
    {
        $this->seed([$this->voteRing([11, 12, 13])]);

        $r = bcc_trust_resolve_ring_action(['ring_action' => 'suspend_all']);

        self::assertSame('ring_not_found', $r['refusal']);
        self::assertSame([], $r['user_ids']);
    }

    public function testASubmittedUserIdsFieldIsIGNORED(): void
    {
        // ⚠⚠ THE CENTRAL SAFETY PROPERTY. Even if something posts the old
        // field, it must have no effect on who is acted upon.
        $this->seed([$this->voteRing([11, 12, 13])]);

        $r = bcc_trust_resolve_ring_action([
            'ring_action'      => 'suspend_all',
            'ring_fingerprint' => RingIdentity::fingerprint([11, 12, 13]),
            'user_ids'         => '999,1000,1001',
        ]);

        self::assertSame([11, 12, 13], $r['user_ids'], 'the resolved ring, not the submitted ids');
        self::assertNotContains(999, $r['user_ids']);
    }

    public function testASubmittedUserIdsFieldCannotActAloneEither(): void
    {
        $this->seed([$this->voteRing([11, 12, 13])]);

        $r = bcc_trust_resolve_ring_action([
            'ring_action' => 'suspend_all',
            'user_ids'    => '11,12,13',
        ]);

        self::assertSame('ring_not_found', $r['refusal'], 'no fingerprint, no action');
        self::assertSame([], $r['user_ids']);
    }

    public function testAMemberlessRingRefusesWithItsOwnCode(): void
    {
        // A ring whose members all parse to nothing. normalise() does not
        // exist in this change, so the raw list really can hold one.
        $this->seed([['users' => [0, -1], 'size' => 2]]);

        $r = bcc_trust_resolve_ring_action([
            'ring_action'      => 'invalidate_votes',
            'ring_fingerprint' => RingIdentity::fingerprint([0, -1]),
        ]);

        self::assertSame('no_users', $r['refusal'], 'distinct from ring_not_found: the ring WAS found');
        self::assertNotNull($r['ring']);
        self::assertSame([], $r['user_ids']);
    }

    public function testAResolvedRingCarriesNoRefusal(): void
    {
        // ⚠ The control. Without it every refusal above could be a resolver
        // that refuses everything.
        $this->seed([$this->endorsementRing([21, 22, 23])]);

        $r = bcc_trust_resolve_ring_action([
            'ring_action'      => 'invalidate_votes',
            'ring_fingerprint' => RingIdentity::fingerprint([21, 22, 23]),
        ]);

        self::assertSame('', $r['refusal']);
        self::assertSame([21, 22, 23], $r['user_ids']);
        self::assertSame('invalidate_votes', $r['action']);
    }

    public function testTheActionStringIsSanitisedButNotInterpreted(): void
    {
        // The resolver does not validate the action name — handleRingAction's
        // own switch has an `unknown_action` default. What matters here is
        // that it is sanitised and passed through unchanged.
        $this->seed([$this->voteRing([11, 12, 13])]);

        $r = bcc_trust_resolve_ring_action([
            'ring_action'      => '  suspend_all  ',
            'ring_fingerprint' => RingIdentity::fingerprint([11, 12, 13]),
        ]);

        self::assertSame('suspend_all', $r['action'], 'trimmed by sanitize_text_field');
    }
}
