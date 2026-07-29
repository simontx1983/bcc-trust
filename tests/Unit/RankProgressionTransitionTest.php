<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Services\Tests;

use BCC\Trust\Core\Services\RankProgressionListener;
use BCC\Trust\Core\Support\RankCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Locks RankProgressionListener::decideTransition — the branch that decides
 * whether an activity event celebrates, demotes, seeds, or no-ops.
 *
 * Until contract v1.58 this class had NO tests at all, despite being the
 * contract's sole named rank producer (§4.8) and the only emitter of
 * `bcc_rank_awarded`. The decision is now a pure static over resolved
 * inputs (same split as EliteEligibilityService::evaluate and
 * ReliabilityStandingComputer::fromReliability), so it is testable without
 * WordPress — which matters here because the shared-process WP function
 * stubs in this suite are first-wins and already bind get_user_meta to an
 * unrelated int store.
 *
 * The extraction is behaviour-preserving: the wrapper resolves the rank,
 * asks this function what happened, persists when told to, and then runs
 * exactly the celebration / logging / `bcc_rank_awarded` side effects it
 * ran before. No WordPress or repository call moved into the pure core.
 */
#[CoversClass(RankProgressionListener::class)]
final class RankProgressionTransitionTest extends TestCase
{
    /** Convenience: resolve a real ladder order so tests can't drift. */
    private static function orderOf(string $rank): int
    {
        $order = RankCatalog::orderOf($rank);
        self::assertNotNull($order, "fixture rank {$rank} must be on the ladder");
        return $order;
    }

    // ── Seeding ──────────────────────────────────────────────────────

    public function testAbsentMetaSeedsQuietlyWithoutCelebrating(): void
    {
        $d = RankProgressionListener::decideTransition(
            '',
            RankCatalog::RANK_VETERAN,
            self::orderOf(RankCatalog::RANK_VETERAN)
        );

        self::assertSame(RankProgressionListener::TRANSITION_SEED, $d['outcome']);
        self::assertTrue($d['persist'], 'the seed must be written or every event re-seeds');
        self::assertNull($d['from']);
    }

    public function testNonStringMetaIsTreatedAsAbsentRatherThanUnknown(): void
    {
        // get_user_meta can hand back non-strings on a corrupted row. Those
        // must seed, NOT take the unknown-slug promotion branch — otherwise a
        // corrupt row mints a celebration.
        foreach ([false, null, 0, []] as $corrupt) {
            $d = RankProgressionListener::decideTransition(
                $corrupt,
                RankCatalog::RANK_JOURNEYMAN,
                self::orderOf(RankCatalog::RANK_JOURNEYMAN)
            );
            self::assertSame(
                RankProgressionListener::TRANSITION_SEED,
                $d['outcome'],
                'corrupt meta of type ' . gettype($corrupt) . ' should seed'
            );
        }
    }

    // ── No-op ────────────────────────────────────────────────────────

    public function testUnchangedRankIsANoOpAndDoesNotRewriteMeta(): void
    {
        $d = RankProgressionListener::decideTransition(
            RankCatalog::RANK_JOURNEYMAN,
            RankCatalog::RANK_JOURNEYMAN,
            self::orderOf(RankCatalog::RANK_JOURNEYMAN)
        );

        self::assertSame(RankProgressionListener::TRANSITION_NONE, $d['outcome']);
        self::assertFalse($d['persist'], 'an unchanged rank must not write on every event');
    }

    // ── Promotion ────────────────────────────────────────────────────

    public function testClimbingOneRungIsAPromotion(): void
    {
        $d = RankProgressionListener::decideTransition(
            RankCatalog::RANK_APPRENTICE,
            RankCatalog::RANK_JOURNEYMAN,
            self::orderOf(RankCatalog::RANK_JOURNEYMAN)
        );

        self::assertSame(RankProgressionListener::TRANSITION_PROMOTION, $d['outcome']);
        self::assertSame(RankCatalog::RANK_APPRENTICE, $d['from']);
        self::assertTrue($d['persist']);
    }

    public function testSkippingARungIsStillASinglePromotion(): void
    {
        // Apprentice → Veteran happens when a user crosses both gates
        // between two events. It must celebrate once, not twice.
        $d = RankProgressionListener::decideTransition(
            RankCatalog::RANK_APPRENTICE,
            RankCatalog::RANK_VETERAN,
            self::orderOf(RankCatalog::RANK_VETERAN)
        );

        self::assertSame(RankProgressionListener::TRANSITION_PROMOTION, $d['outcome']);
        self::assertSame(RankCatalog::RANK_APPRENTICE, $d['from']);
    }

    // ── Demotion ─────────────────────────────────────────────────────

    public function testFallingBackIsADemotionThatStillPersists(): void
    {
        // Rank is recomputed from live counters with no monotonic clamp, so
        // unfollowing below the pulls gate really does demote. It must
        // persist (or the user re-celebrates on the way back up) and must
        // NOT celebrate (§E2 / §O1.2: no Heavy toast for negative events).
        $d = RankProgressionListener::decideTransition(
            RankCatalog::RANK_VETERAN,
            RankCatalog::RANK_JOURNEYMAN,
            self::orderOf(RankCatalog::RANK_JOURNEYMAN)
        );

        self::assertSame(RankProgressionListener::TRANSITION_DEMOTION, $d['outcome']);
        self::assertSame(RankCatalog::RANK_VETERAN, $d['from']);
        self::assertTrue($d['persist']);
    }

    public function testDemotionThenRepromotionCelebratesExactlyOnce(): void
    {
        $down = RankProgressionListener::decideTransition(
            RankCatalog::RANK_VETERAN,
            RankCatalog::RANK_JOURNEYMAN,
            self::orderOf(RankCatalog::RANK_JOURNEYMAN)
        );
        self::assertSame(RankProgressionListener::TRANSITION_DEMOTION, $down['outcome']);
        self::assertTrue($down['persist'], 'demotion must persist for this to hold');

        // Meta now holds journeyman (because demotion persisted). Climbing
        // back is a fresh promotion — exactly one celebration, not zero.
        $up = RankProgressionListener::decideTransition(
            RankCatalog::RANK_JOURNEYMAN,
            RankCatalog::RANK_VETERAN,
            self::orderOf(RankCatalog::RANK_VETERAN)
        );
        self::assertSame(RankProgressionListener::TRANSITION_PROMOTION, $up['outcome']);
    }

    // ── Retired slugs fail safe ──────────────────────────────────────

    /**
     * Retired and unknown slugs must resolve to a defined outcome, never a
     * crash. `master` (retired v1.58) and `foreman` (retired v1.36) are
     * both off the ladder, so `orderOf()` is null and the unknown branch
     * treats them as a climb — deliberate: failing toward celebrating a
     * real promotion beats silently dropping one.
     *
     * The clean-break assertions that these slugs are not on the ladder
     * live in IdentityRankLevelTest; this covers what the listener does if
     * one ever reaches it anyway.
     */
    public function testRetiredSlugsFailSafeRatherThanCrashing(): void
    {
        foreach (['master', 'foreman'] as $retired) {
            self::assertNull(
                RankCatalog::orderOf($retired),
                "precondition: {$retired} is off the ladder"
            );

            $d = RankProgressionListener::decideTransition(
                $retired,
                RankCatalog::RANK_VETERAN,
                self::orderOf(RankCatalog::RANK_VETERAN)
            );

            self::assertSame(
                RankProgressionListener::TRANSITION_PROMOTION,
                $d['outcome'],
                "{$retired} must resolve to a defined outcome"
            );
            self::assertSame(
                $retired,
                $d['from'],
                'the unknown value is reported, not swallowed'
            );
            self::assertTrue($d['persist'], 'a stale slug must be replaced with a live one');
        }
    }
}
