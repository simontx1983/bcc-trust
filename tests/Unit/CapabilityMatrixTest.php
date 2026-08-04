<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\AttestationException;
use BCC\Trust\Core\Services\Capability\CapabilityCatalog;
use BCC\Trust\Core\Services\Capability\CapabilityResolver;
use BCC\Trust\Core\ValueObjects\CapabilityDecision;
use PHPUnit\Framework\TestCase;

/**
 * POST-CUTOVER AUTHORIZATION MATRIX (approved plan R2 — the Phase 5
 * flip).
 *
 * Pins the AUTHORITATIVE CapabilityResolver's verdicts after the Rank
 * redesign Phase 5 atomic cutover. The live delegate policy is:
 *
 *   - write_review  → Apprentice+ (rank_state row exists — New Members
 *                     denied, fail-safe on the missing row) AND Trust
 *                     Neutral+. Journeyman is never required (§20.1).
 *   - vouch / stand_behind → AttestationService::checkCastEligibility,
 *                     which carries the New-Member exclusion inside it.
 *   - open_dispute  → not-suspended + affected-party; rank never
 *                     blocks opening (§20.4).
 *
 * The resolver's routing over those delegates is what this matrix pins
 * (fail-closed unknown keys, unauthenticated deny, future keys deny,
 * vote keys deferred to the live gate). Any change to these
 * expectations is an authorization change and needs its own sanctioned
 * boundary — do not "fix" a failure here by editing the expectations.
 *
 * Delegate hooks are overridden with controllable fakes; the delegates
 * themselves (rank_state + tier gate math, attestation eligibility,
 * Permissions) are covered by their own suites.
 */
final class CapabilityMatrixTest extends TestCase
{
    private const FUTURE_KEYS = [
        CapabilityCatalog::CREATE_COMMUNITY,
        CapabilityCatalog::TRANSFER_COMMUNITY,
        CapabilityCatalog::RECEIVE_COMMUNITY,
        CapabilityCatalog::POST_RANKED_HALL_FEED,
        CapabilityCatalog::LIST_AS_MENTOR,
        CapabilityCatalog::RECEIVE_RANK_VOTE_MULTIPLIER,
    ];

    /**
     * @param bool $writeReview  writeReviewGate() verdict
     * @param \Throwable|null $attestationThrows attestationGate() behavior
     * @param bool $notSuspended notSuspended() verdict
     * @param bool $ownsPage     ownsPage() verdict
     */
    private function resolver(
        bool $writeReview = false,
        ?\Throwable $attestationThrows = null,
        bool $notSuspended = true,
        bool $ownsPage = false
    ): CapabilityResolver {
        return new class ($writeReview, $attestationThrows, $notSuspended, $ownsPage) extends CapabilityResolver {
            public function __construct(
                private readonly bool $fakeWriteReview,
                private readonly ?\Throwable $fakeAttestationThrows,
                private readonly bool $fakeNotSuspended,
                private readonly bool $fakeOwnsPage
            ) {
            }

            protected function writeReviewGate(int $userId): bool
            {
                return $this->fakeWriteReview;
            }

            protected function attestationGate(int $userId, string $targetKind, int $targetId, string $kind): void
            {
                if ($this->fakeAttestationThrows !== null) {
                    throw $this->fakeAttestationThrows;
                }
            }

            protected function notSuspended(int $userId): bool
            {
                return $this->fakeNotSuspended;
            }

            protected function ownsPage(int $pageId, int $userId): bool
            {
                return $this->fakeOwnsPage;
            }
        };
    }

    public function testCatalogPinsExactlyThirteenKeys(): void
    {
        // The catalog is a plan-approved list (§25). Adding or removing a
        // key is a deliberate phase deliverable, not a drive-by.
        self::assertCount(13, CapabilityCatalog::KEYS);
        self::assertSame(array_unique(CapabilityCatalog::KEYS), CapabilityCatalog::KEYS);
        // Master never appears in any form (§3.2 / invariant 34).
        foreach (CapabilityCatalog::KEYS as $key) {
            self::assertStringNotContainsString('master', $key);
        }
    }

    public function testUnknownKeyFailsClosed(): void
    {
        $decision = $this->resolver()->can(7, 'grant_admin');

        self::assertTrue($decision->isDenied());
        self::assertSame('unknown_capability', $decision->reason);
        self::assertSame('fail_closed', $decision->source);
    }

    public function testUnauthenticatedPrincipalDeniedForEveryKey(): void
    {
        $resolver = $this->resolver(writeReview: true, notSuspended: true, ownsPage: true);

        foreach (CapabilityCatalog::KEYS as $key) {
            $decision = $resolver->can(0, $key, ['page_id' => 5, 'target_kind' => 'member', 'target_id' => 9]);
            self::assertTrue($decision->isDenied(), "key {$key} must deny for userId 0");
            self::assertSame('not_authenticated', $decision->reason, "key {$key}");
        }
    }

    public function testFutureKeysDenyFailClosed(): void
    {
        $resolver = $this->resolver(writeReview: true, notSuspended: true, ownsPage: true);

        foreach (self::FUTURE_KEYS as $key) {
            $decision = $resolver->can(7, $key);
            self::assertTrue($decision->isDenied(), "key {$key}");
            self::assertSame('feature_not_built', $decision->reason, "key {$key}");
            self::assertSame('future', $decision->source, "key {$key}");
        }
    }

    public function testVoteKeysDeferToLiveGate(): void
    {
        // VoteEligibilityChecker::check consumes rate-limit quota — not
        // shadowable. UNKNOWN is the honest verdict; if resolver output
        // is ever enforced, UNKNOWN must be treated as DENY.
        $resolver = $this->resolver();

        foreach ([CapabilityCatalog::CAST_MEANINGFUL_VOTE, CapabilityCatalog::DOWNVOTE] as $key) {
            $decision = $resolver->can(7, $key);
            self::assertSame(CapabilityDecision::UNKNOWN, $decision->outcome, "key {$key}");
            self::assertSame('deferred_to_live_gate', $decision->reason, "key {$key}");
        }
    }

    public function testWriteReviewMirrorsFeatureAccessGate(): void
    {
        // Phase 5 final policy: Apprentice+ (rank_state row) AND
        // Neutral+ via the writeReviewGate delegate; the delegate's own
        // math is exercised against live repositories, not here.
        self::assertTrue($this->resolver(writeReview: true)->can(7, CapabilityCatalog::WRITE_REVIEW)->isAllowed());

        $denied = $this->resolver(writeReview: false)->can(7, CapabilityCatalog::WRITE_REVIEW);
        self::assertTrue($denied->isDenied());
        self::assertSame('feature_gate', $denied->reason);
        self::assertSame('feature_access', $denied->source);
    }

    public function testCastDisputeVoteRoutesThroughTheWriteActionPolicy(): void
    {
        // Rank Phase 6 Wave 3 (D-7): the panel is retired; the key now
        // carries the same Apprentice+ AND Neutral+ policy shape as the
        // other write actions. The live gate (DisputeVoteService)
        // additionally enforces §18 party exclusion + fraud hard-block
        // per dispute — exercised in DisputePanelRetirementTest.
        self::assertTrue(
            $this->resolver(writeReview: true)->can(7, CapabilityCatalog::CAST_DISPUTE_VOTE)->isAllowed()
        );

        $denied = $this->resolver(writeReview: false)->can(7, CapabilityCatalog::CAST_DISPUTE_VOTE);
        self::assertTrue($denied->isDenied());
        self::assertSame('feature_gate', $denied->reason);
        self::assertSame('feature_access', $denied->source);
    }

    public function testAttestationKeysDelegateToCastEligibility(): void
    {
        $context = ['target_kind' => 'member', 'target_id' => 9];

        foreach ([CapabilityCatalog::VOUCH, CapabilityCatalog::STAND_BEHIND] as $key) {
            // Eligible: checkCastEligibility returns without throwing.
            self::assertTrue(
                $this->resolver()->can(7, $key, $context)->isAllowed(),
                "key {$key} allowed when the gate passes"
            );

            // Ineligible: the gate's exception code becomes the reason.
            $ineligible = new AttestationException(
                AttestationException::CODE_INELIGIBLE,
                'Reach Neutral standing to vouch.',
                403
            );
            $denied = $this->resolver(attestationThrows: $ineligible)->can(7, $key, $context);
            self::assertTrue($denied->isDenied(), "key {$key}");
            self::assertSame(AttestationException::CODE_INELIGIBLE, $denied->reason, "key {$key}");
            self::assertSame('attestation_gate', $denied->source, "key {$key}");

            // Missing target context: honest UNKNOWN, never a guess.
            $unknown = $this->resolver()->can(7, $key);
            self::assertSame(CapabilityDecision::UNKNOWN, $unknown->outcome, "key {$key}");
            self::assertSame('missing_context', $unknown->reason, "key {$key}");
        }
    }

    public function testOpenDisputeMirrorsDisputeControllerChain(): void
    {
        // §20.4: rank never blocks opening; the live chain is
        // not-suspended + affected-party (Permissions::owns_page).
        $context = ['page_id' => 5];

        self::assertTrue(
            $this->resolver(notSuspended: true, ownsPage: true)->can(7, CapabilityCatalog::OPEN_DISPUTE, $context)->isAllowed()
        );

        $suspended = $this->resolver(notSuspended: false, ownsPage: true)->can(7, CapabilityCatalog::OPEN_DISPUTE, $context);
        self::assertTrue($suspended->isDenied());
        self::assertSame('suspended', $suspended->reason);

        $notOwner = $this->resolver(notSuspended: true, ownsPage: false)->can(7, CapabilityCatalog::OPEN_DISPUTE, $context);
        self::assertTrue($notOwner->isDenied());
        self::assertSame('not_affected_party', $notOwner->reason);

        $noContext = $this->resolver(notSuspended: true, ownsPage: true)->can(7, CapabilityCatalog::OPEN_DISPUTE);
        self::assertSame(CapabilityDecision::UNKNOWN, $noContext->outcome);
        self::assertSame('missing_context', $noContext->reason);
    }
}
