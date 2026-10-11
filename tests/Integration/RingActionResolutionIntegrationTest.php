<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Support\RingSnapshot;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Which accounts a vote-ring action is allowed to act on.
 *
 * ── ⚠⚠ WHAT WAS WRONG, AND WHY IT ONLY MATTERS NOW ─────────────────────
 * The two destructive ring actions — invalidate-ring-votes and suspend-all —
 * took their target list from a hidden form field. Two defects, both
 * unreachable until a snapshot was actually persisted:
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
 * Membership is now resolved SERVER-SIDE from the stored snapshot, keyed by a
 * fingerprint, so nothing about the target set is submitted.
 *
 * ── WHAT THIS SUITE DOES AND DOES NOT DO ────────────────────────────────
 * It exercises the RESOLUTION only — findRingByFingerprint() — in isolation.
 * It deliberately never calls handleRingAction(), because that suspends
 * accounts and soft-deletes votes. The resolution is the gate; proving the
 * gate returns null is proving nothing downstream can run.
 *
 * ── ⚠⚠ WHICH ASSERTIONS HERE HAVE TEETH, MEASURED BY MUTATION ──────────
 * Four mutants were run against findRingByFingerprint(). Recording the
 * result honestly, because three of its four guards turn out to be redundant
 * and a reader is entitled to know which lines this suite actually pins:
 *
 *   mutant                                      outcome
 *   ------------------------------------------- --------------------------
 *   return the first ring whatever was asked    KILLED — 6 cases
 *   drop the hash-format guard                  SURVIVED
 *   compare with strcasecmp instead of          SURVIVED
 *     hash_equals
 *   drop the `available !== true` early return  SURVIVED
 *
 * The three survivors are redundancy, not gaps in the gate:
 *
 *  - ⚠ The format guard and the comparison MASK EACH OTHER. Removing either
 *    alone still refuses an uppercase hash; removing BOTH makes
 *    testAnUppercaseHashRefusesRatherThanMatchingLoosely fail. So that case
 *    pins the PAIR, not either member, and this was measured by running the
 *    double mutant rather than assumed.
 *
 *  - ⚠ The `available !== true` return is structurally unobservable: read()
 *    returns `rings => []` whenever it is unavailable, so the loop below
 *    finds nothing and returns null regardless. It is kept as an explicit
 *    statement of intent and as protection against a future read() that
 *    returns rings alongside `available: false` — but no test here can
 *    distinguish it, and pretending otherwise would be worse than saying so.
 *
 * What IS pinned, and is the property that matters: a fingerprint only ever
 * resolves to the ring whose members hash to it, and every other case —
 * absent, empty, different ring, subset, superset, malformed, legacy value,
 * memberless, superseded — returns null.
 */
#[Group('integration')]
#[Group('mariadb')]
final class RingActionResolutionIntegrationTest extends TestCase
{
    private const TRANSIENT = 'bcc_trust_ring_snapshot';

    protected function setUp(): void
    {
        $this->clearStores();
    }

    protected function tearDown(): void
    {
        $this->clearStores();
        unset($GLOBALS['__bcc_test_ext_object_cache']);
    }

    private function clearStores(): void
    {
        delete_transient(self::TRANSIENT);
        wp_cache_delete(RingSnapshot::CACHE_KEY, RingSnapshot::CACHE_GROUP);
    }

    /** The producer shape of a VOTE ring: no `type`, a real `strength`. */
    private function voteRing(array $users): array
    {
        return [
            'users'       => $users,
            'size'        => count($users),
            'strength'    => 0.88,
            'detected_at' => '2026-10-12 00:00:00',
        ];
    }

    /** The producer shape of an ENDORSEMENT ring: a `type`, no `strength`. */
    private function endorsementRing(array $users): array
    {
        return [
            'users'       => $users,
            'size'        => count($users),
            'type'        => 'endorsement_ring',
            'detected_at' => '2026-10-12 00:00:00',
        ];
    }

    // ── 0. ⚠⚠ THE TRUNCATION THE OLD HANDLER PERFORMED
    // ═══════════════════════════════════════════════════════════════════

    public function testTheOldCsvHandlingReducedAThreeMemberRingToOne(): void
    {
        // ⚠ The defect, reproduced exactly as the handler did it, so the
        // number below is a measurement rather than a claim in a comment.
        // This is what "Suspend ALL 3 members" actually did.
        $posted = '11,12,13';

        $asTheOldHandlerDidIt = array_filter(array_map('absint', (array) $posted));

        self::assertSame([11], $asTheOldHandlerDidIt, 'absint() of the whole CSV is its first number');
        self::assertCount(1, $asTheOldHandlerDidIt, 'one of three');
    }

    public function testResolutionReturnsTheWHOLERing(): void
    {
        // ⚠ The pair: the same three members, resolved rather than submitted.
        RingSnapshot::store([$this->voteRing([11, 12, 13])]);

        $ring = RingSnapshot::findRingByFingerprint(RingSnapshot::fingerprint([11, 12, 13]));

        self::assertNotNull($ring);
        self::assertSame([11, 12, 13], $ring['users'], 'all three, not the first');
    }

    // ── 1. BOTH DETECTOR FORMATS RESOLVE IDENTICALLY
    // ═══════════════════════════════════════════════════════════════════

    public function testAVoteRingResolves(): void
    {
        RingSnapshot::store([$this->voteRing([31, 32, 33])]);

        $ring = RingSnapshot::findRingByFingerprint(RingSnapshot::fingerprint([31, 32, 33]));

        self::assertNotNull($ring);
        self::assertSame(RingSnapshot::KIND_VOTE, $ring['kind']);
        self::assertSame(0.88, $ring['strength']);
    }

    public function testAnEndorsementRingResolvesToo(): void
    {
        // ⚠ The format with no `strength` key at all. If resolution depended
        // on the vote shape, only half the rings on the tab would be
        // actionable — and the other half would fail at submit time, after
        // the operator confirmed a suspension.
        RingSnapshot::store([$this->endorsementRing([41, 42, 43, 44])]);

        $ring = RingSnapshot::findRingByFingerprint(RingSnapshot::fingerprint([41, 42, 43, 44]));

        self::assertNotNull($ring);
        self::assertSame(RingSnapshot::KIND_ENDORSEMENT, $ring['kind']);
        self::assertNull($ring['strength'], 'and its absent strength is null, not 0.0');
        self::assertSame([41, 42, 43, 44], $ring['users']);
    }

    public function testEachRingInAMixedSnapshotResolvesToITSOWNMembers(): void
    {
        // ⚠ THE CASE THAT MATTERS MOST: suspending the wrong ring's members
        // is the worst available outcome, and both shapes are in the same
        // stored array because getSuspiciousClusters() array_merges them.
        RingSnapshot::store([
            $this->voteRing([11, 12, 13]),
            $this->endorsementRing([21, 22, 23, 24]),
        ]);

        $a = RingSnapshot::findRingByFingerprint(RingSnapshot::fingerprint([11, 12, 13]));
        $b = RingSnapshot::findRingByFingerprint(RingSnapshot::fingerprint([21, 22, 23, 24]));

        self::assertSame([11, 12, 13], $a['users']);
        self::assertSame([21, 22, 23, 24], $b['users']);
    }

    public function testTheFingerprintIsOrderIndependent(): void
    {
        // The producer's component order is a BFS artefact and is not stable.
        // A fingerprint that depended on it would refuse valid actions after
        // an unrelated re-run.
        RingSnapshot::store([$this->voteRing([13, 11, 12])]);

        self::assertNotNull(
            RingSnapshot::findRingByFingerprint(RingSnapshot::fingerprint([11, 12, 13])),
            'the same members in a different order are the same ring'
        );
    }

    // ── 2. ⚠⚠ EVERY UNCERTAIN CASE REFUSES
    // ═══════════════════════════════════════════════════════════════════
    //
    // Each of these must return null. A destructive action needs a positively
    // identified target, so "I am not sure" is never "act on what you were
    // given".

    public function testNoSnapshotRefuses(): void
    {
        self::assertNull(RingSnapshot::findRingByFingerprint(RingSnapshot::fingerprint([11, 12, 13])));
    }

    public function testAnEmptySnapshotRefuses(): void
    {
        // The run happened and found nothing. There is no ring to act on.
        RingSnapshot::store([]);

        self::assertNull(RingSnapshot::findRingByFingerprint(RingSnapshot::fingerprint([11, 12, 13])));
    }

    public function testAFingerprintForADIFFERENTRingRefuses(): void
    {
        RingSnapshot::store([$this->voteRing([11, 12, 13])]);

        self::assertNull(
            RingSnapshot::findRingByFingerprint(RingSnapshot::fingerprint([11, 12, 14])),
            'one member different is a different ring'
        );
    }

    public function testASubsetOfARingRefuses(): void
    {
        // ⚠ Not "act on the two we recognise". A partial ring is a different
        // set of accounts, and acting on it under the `vote_ring` reason
        // would record something untrue.
        RingSnapshot::store([$this->voteRing([11, 12, 13])]);

        self::assertNull(RingSnapshot::findRingByFingerprint(RingSnapshot::fingerprint([11, 12])));
    }

    public function testASupersetOfARingRefuses(): void
    {
        // The direction that matters: an extra id must not be smuggled in.
        RingSnapshot::store([$this->voteRing([11, 12, 13])]);

        self::assertNull(RingSnapshot::findRingByFingerprint(RingSnapshot::fingerprint([11, 12, 13, 99])));
    }

    public function testAMalformedFingerprintRefuses(): void
    {
        RingSnapshot::store([$this->voteRing([11, 12, 13])]);

        foreach (['', 'x', '../../etc/passwd', '11,12,13', str_repeat('z', 32), '%', 'NULL'] as $bad) {
            self::assertNull(
                RingSnapshot::findRingByFingerprint($bad),
                sprintf('%s must not resolve', var_export($bad, true))
            );
        }
    }

    public function testAnUppercaseHashRefusesRatherThanMatchingLoosely(): void
    {
        // ⚠ Deliberate strictness. Accepting a case-insensitive hash would
        // mean the format is negotiable, and the next variation would be too.
        RingSnapshot::store([$this->voteRing([11, 12, 13])]);
        $fp = RingSnapshot::fingerprint([11, 12, 13]);

        self::assertNull(RingSnapshot::findRingByFingerprint(strtoupper($fp)));
        self::assertNotNull(RingSnapshot::findRingByFingerprint($fp), 'and the exact one still works');
    }

    public function testALegacyBareListInTheCacheRefuses(): void
    {
        // A pre-change value left warm in a persistent object cache. It is
        // not a payload, so there is no ring to resolve and nothing to act
        // on until the next daily run.
        $GLOBALS['__bcc_test_ext_object_cache'] = true;
        wp_cache_set(
            RingSnapshot::CACHE_KEY,
            [['users' => [11, 12, 13]]],       // no wrapper, no computed_at
            RingSnapshot::CACHE_GROUP,
            DAY_IN_SECONDS
        );

        self::assertNull(RingSnapshot::findRingByFingerprint(RingSnapshot::fingerprint([11, 12, 13])));
    }

    public function testARingStoredWithoutMembersIsNotResolvable(): void
    {
        // normalise() drops it at write time, so this is belt and braces —
        // but a ring with no members reaching a destructive action is the one
        // outcome worth asserting twice.
        RingSnapshot::store([
            ['size' => 3, 'strength' => 0.5],          // no users
            $this->voteRing([11, 12, 13]),
        ]);

        self::assertNull(RingSnapshot::findRingByFingerprint(RingSnapshot::fingerprint([])));
        self::assertNull(RingSnapshot::findRingByFingerprint(md5('')));
        self::assertNotNull(
            RingSnapshot::findRingByFingerprint(RingSnapshot::fingerprint([11, 12, 13])),
            'and the well-formed ring beside it still resolves'
        );
    }

    // ── 2a. ⚠⚠ THE ONE SCORING CHANGE, PINNED DELIBERATELY
    // ═══════════════════════════════════════════════════════════════════
    //
    // Reading the snapshot instead of recomputing changes WHICH rings a user
    // matches, on a host with no persistent object cache. Both halves
    // measured in an isolated container:
    //
    //   host WITH object-cache.php   before: both kinds (cron's write was
    //                                        visible)        after: both
    //   host WITHOUT (staging)       before: VOTE ONLY (the read always
    //                                        missed, so the fallback ran and
    //                                        detectVoteRings() returns vote
    //                                        rings only)     after: both
    //
    //   probe user in an endorsement-only ring, cacheless host:
    //       before +0    after +40    ** CHANGED **
    //
    // So this does not ADD endorsement scoring; it makes a cacheless host
    // behave the way a drop-in host always did. No uniform rule preserves
    // both, because the two configurations never agreed.
    //
    // It is pinned here so the widening is a decision with a test on it
    // rather than a side effect nobody wrote down.

    public function testTheSnapshotCarriesBOTHRingKindsToTheScoringPath(): void
    {
        RingSnapshot::store([
            $this->voteRing([8001, 8002, 8003]),
            $this->endorsementRing([8101, 8102, 8103]),
        ]);

        $rings = RingSnapshot::read()['rings'];

        // FraudDetector's matching loop, in shape.
        $matched = static function (int $userId) use ($rings): ?string {
            foreach ($rings as $ring) {
                if (in_array($userId, $ring['users'], true)) { return $ring['kind']; }
            }
            return null;
        };

        self::assertSame(RingSnapshot::KIND_VOTE, $matched(8001), 'a vote-ring member still matches');
        self::assertSame(
            RingSnapshot::KIND_ENDORSEMENT,
            $matched(8101),
            'and an endorsement-only member NOW matches, which is the scoring change'
        );
        self::assertNull($matched(9999), 'and someone in neither still matches nothing');
    }

    public function testAnEndorsementRingReachesTheScoringPathWithoutAStrengthKeyError(): void
    {
        // ⚠ The reason the shape normalisation had to ship with the storage
        // change: once an endorsement ring reaches the scoring path, the
        // unguarded $ring['strength'] read raised "Undefined array key". The
        // key is now always present and null for this kind.
        RingSnapshot::store([$this->endorsementRing([8101, 8102, 8103])]);

        $ring = RingSnapshot::read()['rings'][0];

        self::assertArrayHasKey('strength', $ring);
        self::assertNull($ring['strength']);
        self::assertArrayHasKey('size', $ring, 'and ring_size still resolves for the signal');
    }

    // ── 3. ⚠⚠ THE GATES ON THE ADMIN-POST HOOK
    // ═══════════════════════════════════════════════════════════════════
    //
    // The hook is an add_action closure that reads $_POST, so it cannot be
    // invoked here without standing up a WordPress request. What CAN be
    // asserted is that its gates are still present — the same approach
    // EndpointSafetyCallerInventoryTest uses for safety methods that must
    // keep a caller. It will not catch a gate that is present but wrong; it
    // will catch one that is deleted, which is the realistic regression.

    /** @return string the source of the ring-action hook */
    private function hookSource(): string
    {
        $file = dirname(__DIR__, 2) . '/includes/admin/moderation.php';
        $src  = file_get_contents($file);
        self::assertIsString($src, 'moderation.php must be readable');

        $start = strpos($src, "add_action( 'admin_post_bcc_trust_ring_action'");
        self::assertNotFalse($start, 'the ring-action hook must exist');
        $end = strpos($src, "add_action( 'admin_post_bcc_trust_bulk_action'", $start);
        self::assertNotFalse($end, 'and the next hook after it, to bound the slice');

        return substr($src, $start, $end - $start);
    }

    public function testTheHookRequiresTheManageOptionsCapability(): void
    {
        self::assertStringContainsString("current_user_can( 'manage_options' )", $this->hookSource());
    }

    public function testTheHookVerifiesTheNonce(): void
    {
        self::assertStringContainsString("check_admin_referer( 'bcc_trust_ring_action' )", $this->hookSource());
    }

    public function testTheHookRefusesAnythingThatIsNotAPost(): void
    {
        // ⚠ admin-post.php dispatches on $_REQUEST, so this fired for a GET
        // as happily as a POST. A nonce in a URL is a normal WordPress
        // pattern for a link — but these two actions suspend accounts and
        // soft-delete votes, and a destructive action should not be reachable
        // by anything that can be prefetched, bookmarked or logged.
        $src = $this->hookSource();

        self::assertStringContainsString("REQUEST_METHOD", $src);
        self::assertStringContainsString("!== 'POST'", $src);
    }

    public function testTheHookResolvesMembershipAndDoesNotReadItFromThePost(): void
    {
        // ⚠⚠ THE CENTRAL SAFETY PROPERTY OF THIS CHANGE, as an absence AND a
        // presence. Either half alone would pass against a broken hook: no
        // $_POST['user_ids'] read could mean the hook does nothing, and a
        // resolution call could sit beside a surviving fallback to submitted
        // ids.
        $src = $this->hookSource();

        self::assertStringNotContainsString("user_ids", $src, 'membership must not come from the request');
        self::assertStringContainsString('RingSnapshot::findRingByFingerprint', $src);
        self::assertStringContainsString("\$ring['users']", $src, 'and the members must come from the resolved ring');
    }

    public function testTheHookRefusesWhenNoRingResolves(): void
    {
        // The refusal must be an early return, not a logged warning that
        // then carries on.
        $src = $this->hookSource();

        self::assertStringContainsString('$ring === null', $src);
        self::assertStringContainsString('ring_not_found', $src);
        self::assertStringContainsString('exit;', $src, 'the refusal must stop the request');
    }

    public function testResolutionIsStillRefusedAfterTheRingStopsBeingDetected(): void
    {
        // The stale-tab case: the page was rendered, then the daily run found
        // the ring gone. Refusing is the intended failure — the operator
        // reloads and sees the current rings.
        RingSnapshot::store([$this->voteRing([11, 12, 13])]);
        $fp = RingSnapshot::fingerprint([11, 12, 13]);
        self::assertNotNull(RingSnapshot::findRingByFingerprint($fp), 'precondition');

        RingSnapshot::store([$this->voteRing([51, 52, 53])]);   // a later run

        self::assertNull(RingSnapshot::findRingByFingerprint($fp));
    }
}
