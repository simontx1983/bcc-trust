<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\FraudSignalWorld as W;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Services\ReciprocityPenaltyResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Ordinary reciprocity keeps BASELINE weight, and is still observed.
 *
 * `ReciprocityPenaltyResolver` had no test coverage at all before this.
 *
 * What changed: `RECIPROCITY_MULTIPLIER` 0.50 → 1.00. A mutual pair's cast
 * is no longer halved. The detection is retained and is now surfaced as a
 * boolean so the observation survives the multiplier going to baseline —
 * before this, `reciprocity_mult: 0.50` in the audit row was the ONLY
 * durable record that a cast was reciprocal.
 *
 * ⚠ What deliberately did NOT change: the diversity bonus. The old
 * specification asked for reciprocal pairs not to COMPOUND, which this
 * class never implemented. That half is treated as design evidence, not
 * standing authorization, and is characterised in
 * AttestationScoreSynthesisTest rather than altered.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReciprocityBaselineWeightTest extends TestCase
{
    private const A = 11; // one tradesperson
    private const B = 22; // the other

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/fraud-signal-stubs.php';
        W::reset();
    }

    private function resolver(): ReciprocityPenaltyResolver
    {
        return new ReciprocityPenaltyResolver(new AttestationRepository());
    }

    /** Record that $attestor has an active vouch on $target's profile. */
    private function activeVouch(int $attestor, int $target): void
    {
        W::$reverseAttestations[$attestor . ':user_profile:' . $target] = [
            'vouch'        => (object) ['id' => 1],
            'stand_behind' => null,
        ];
    }

    private function activeStandBehind(int $attestor, int $target): void
    {
        W::$reverseAttestations[$attestor . ':user_profile:' . $target] = [
            'vouch'        => null,
            'stand_behind' => (object) ['id' => 2],
        ];
    }

    // ══ the change ════════════════════════════════════════════════════

    public function testOrdinaryReciprocityKeepsBaselineWeight(): void
    {
        // B already vouched for A. A now vouches back.
        $this->activeVouch(self::B, self::A);

        self::assertSame(
            1.00,
            $this->resolver()->weightFor(self::A, 'user_profile', self::B),
            'was 0.50 — a sparky and a plumber who sub-contract to each other'
        );
    }

    public function testAStandBehindOnTheReverseSideAlsoKeepsBaseline(): void
    {
        $this->activeStandBehind(self::B, self::A);

        self::assertSame(1.00, $this->resolver()->weightFor(self::A, 'user_profile', self::B));
    }

    public function testTheMultiplierConstantIsBaseline(): void
    {
        // Pinned directly: this is the single place a weight effect could be
        // re-enabled from, so a silent change back should fail a test.
        self::assertSame(1.00, ReciprocityPenaltyResolver::RECIPROCITY_MULTIPLIER);
        self::assertSame(1.00, ReciprocityPenaltyResolver::NO_RECIPROCITY);
    }

    // ══ the observation is RETAINED ═══════════════════════════════════

    public function testReciprocityIsStillDetected(): void
    {
        $this->activeVouch(self::B, self::A);

        self::assertTrue(
            $this->resolver()->isReciprocal(self::A, 'user_profile', self::B),
            'the observation must survive the penalty being removed'
        );
    }

    public function testNonReciprocalIsNotDetected(): void
    {
        self::assertFalse($this->resolver()->isReciprocal(self::A, 'user_profile', self::B));
    }

    public function testDetectionAndWeightAreIndependent(): void
    {
        // The whole point of the separation: detected, and yet unpenalised.
        $this->activeVouch(self::B, self::A);
        $r = $this->resolver();

        self::assertTrue($r->isReciprocal(self::A, 'user_profile', self::B));
        self::assertSame(1.00, $r->weightFor(self::A, 'user_profile', self::B));
    }

    public function testMultiplierForMapsADetectionWithoutReReading(): void
    {
        // AttestationService uses this so one reverse-lookup serves both the
        // audit field and the multiplier, inside the locked region.
        self::assertSame(1.00, ReciprocityPenaltyResolver::multiplierFor(true));
        self::assertSame(1.00, ReciprocityPenaltyResolver::multiplierFor(false));
    }

    // ══ ⚠ BOTH CASTING ORDERS — the order-dependence is gone ══════════

    public function testBothCastingOrdersNowAgree(): void
    {
        // Before: whoever cast SECOND was halved, because the first cast was
        // snapshotted before the reverse edge existed. Same relationship,
        // different penalty, decided purely by sequence.
        //
        // Order 1 — A casts first (no reverse edge yet), then B casts back.
        W::reset();
        $first  = $this->resolver()->weightFor(self::A, 'user_profile', self::B); // no reverse yet
        $this->activeVouch(self::A, self::B);                                     // A's cast now exists
        $second = $this->resolver()->weightFor(self::B, 'user_profile', self::A); // B casts back

        // Order 2 — the mirror image.
        W::reset();
        $firstMirror  = $this->resolver()->weightFor(self::B, 'user_profile', self::A);
        $this->activeVouch(self::B, self::A);
        $secondMirror = $this->resolver()->weightFor(self::A, 'user_profile', self::B);

        self::assertSame(1.00, $first);
        self::assertSame(1.00, $second, 'the second caster is no longer the one penalised');
        self::assertSame($first, $second, 'order within a pair must not change the weight');
        self::assertSame($firstMirror, $secondMirror);
        self::assertSame($first, $firstMirror, 'and the mirror image must agree too');
    }

    public function testTheDetectionIsStillAsymmetricInTimeEvenThoughTheWeightIsNot(): void
    {
        // Honest about what did NOT change: the FIRST caster is still not
        // detected as reciprocal, because at that moment no reverse edge
        // exists. That asymmetry is inherent to a cast-time snapshot. It no
        // longer matters for weight; it does matter if the observation is
        // ever read as "this pair is mutual".
        W::reset();
        self::assertFalse($this->resolver()->isReciprocal(self::A, 'user_profile', self::B));

        $this->activeVouch(self::A, self::B);
        self::assertTrue($this->resolver()->isReciprocal(self::B, 'user_profile', self::A));
    }

    // ══ unrelated protections preserved ══════════════════════════════

    public function testCardTargetsAreStillStructurallyExcluded(): void
    {
        // ⚠ This case was VACUOUS until mutation testing caught it: deleting
        // the card guard changed nothing, because the fixture never held a
        // row the guard had to block.
        //
        // It has teeth now because of a subtlety in production: the reverse
        // lookup passes the literal 'user_profile' as the kind REGARDLESS of
        // the incoming $targetKind. So with the guard removed, a cast on
        // card id B would look up (B AS attestor) -> (A AS target) under
        // 'user_profile' and find this seeded row — i.e. a card would
        // inherit a *person's* reciprocity. Seeding it is what makes the
        // guard's removal observable.
        $this->activeVouch(self::B, self::A);

        foreach (['validator_card', 'project_card', 'creator_card'] as $kind) {
            self::assertFalse(
                $this->resolver()->isReciprocal(self::A, $kind, self::B),
                $kind . ' must not inherit a user_profile reverse edge'
            );
            self::assertSame(1.00, $this->resolver()->weightFor(self::A, $kind, self::B), $kind);
        }

        // Control: the same seeded row DOES register for the person.
        self::assertTrue($this->resolver()->isReciprocal(self::A, 'user_profile', self::B));
    }

    public function testSelfAndNonPositiveIdsAreStillExcluded(): void
    {
        // ⚠ Also vacuous until mutation testing caught it. Each of these has
        // to be seeded with the row the guard must refuse to look at,
        // otherwise deleting the guard changes nothing and the case passes
        // on an empty fixture.
        //
        // The guard is marked "caught upstream; defensive here" in
        // production. A defensive guard still deserves a test that fails
        // when it is removed.
        $this->activeVouch(self::A, self::A);  // a self-edge, were one ever to exist
        $this->activeVouch(0, self::B);
        $this->activeVouch(self::A, 0);
        $this->activeVouch(-1, self::B);

        $r = $this->resolver();
        self::assertFalse($r->isReciprocal(self::A, 'user_profile', self::A), 'self');
        self::assertFalse($r->isReciprocal(0, 'user_profile', self::B), 'zero attestor');
        self::assertFalse($r->isReciprocal(self::A, 'user_profile', 0), 'zero target');
        self::assertFalse($r->isReciprocal(-1, 'user_profile', self::B), 'negative attestor');

        // Control: the fixture is live — an ordinary pair still registers.
        $this->activeVouch(self::B, self::A);
        self::assertTrue($r->isReciprocal(self::A, 'user_profile', self::B));
    }

    public function testARevokedReverseAttestationIsNotReciprocal(): void
    {
        // Production filters revoked rows out of the lookup, so the empty
        // shape is what a revoked-only pair yields.
        W::$reverseAttestations[self::B . ':user_profile:' . self::A] = [
            'vouch'        => null,
            'stand_behind' => null,
        ];

        self::assertFalse($this->resolver()->isReciprocal(self::A, 'user_profile', self::B));
    }

    // ══ anti-vacuity ═════════════════════════════════════════════════

    public function testTheHarnessCanActuallyProduceADetection(): void
    {
        // Without this, every assertFalse above would also pass if the
        // reverse-lookup double were never wired up.
        self::assertFalse($this->resolver()->isReciprocal(self::A, 'user_profile', self::B));
        $this->activeVouch(self::B, self::A);
        self::assertTrue($this->resolver()->isReciprocal(self::A, 'user_profile', self::B));
    }

    public function testTheLookupIsKeyedTheWayProductionQueriesIt(): void
    {
        // The reverse direction is (target AS attestor) -> (attestor AS
        // target). Keying it the other way round would make every
        // reciprocity case silently negative.
        $this->activeVouch(self::B, self::A);

        self::assertTrue($this->resolver()->isReciprocal(self::A, 'user_profile', self::B));
        self::assertFalse(
            $this->resolver()->isReciprocal(self::B, 'user_profile', self::A),
            'only the reverse edge that exists should register'
        );
    }
}
