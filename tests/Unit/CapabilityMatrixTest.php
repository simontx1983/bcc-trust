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
 * RANK PHASE 7 SANCTIONED BOUNDARY (2026-08-04): the five §21
 * community-privilege keys (create_community, transfer_community,
 * receive_community, post_ranked_hall_feed, list_as_mentor) moved out
 * of the FUTURE fail-closed branch into live gates — the §21.2–§21.4
 * matrices below pin their deny-reason vocabulary and gate ORDER.
 * receive_rank_vote_multiplier stays FUTURE on purpose: the multiplier
 * policy already lives in the §16.6 weight formula, and its
 * recovery-pause value is a Phase 8 owner decision.
 *
 * Delegate hooks are overridden with controllable fakes; the delegates
 * themselves (rank_state + tier gate math, attestation eligibility,
 * Permissions, custody fact reads) are covered by their own suites.
 * The custody COMPARISONS (cap boundary, 30-day cooldown boundary)
 * live in the resolver itself and run REAL here against faked facts.
 */
final class CapabilityMatrixTest extends TestCase
{
    private const FUTURE_KEYS = [
        CapabilityCatalog::RECEIVE_RANK_VOTE_MULTIPLIER,
    ];

    private const DAY = 86400;

    /**
     * @param bool $writeReview  writeReviewGate() verdict
     * @param \Throwable|null $attestationThrows attestationGate() behavior
     * @param bool $notSuspended notSuspended() verdict
     * @param bool $ownsPage     ownsPage() verdict
     * @param ?string $rank      rank_state rank_slug (null = New Member / no row)
     * @param string $tier       current reputation tier slug
     * @param bool $inRecovery   §14.2 recovery pause (rank_state 'grace')
     * @param array{owned: int, cap: int, last_event_ts: int|null, cooldown_seconds: int}|null $custody
     *                           custody facts (null = fresh under-cap default)
     */
    private function resolver(
        bool $writeReview = false,
        ?\Throwable $attestationThrows = null,
        bool $notSuspended = true,
        bool $ownsPage = false,
        ?string $rank = null,
        string $tier = 'neutral',
        bool $inRecovery = false,
        ?array $custody = null
    ): CapabilityResolver {
        return new class ($writeReview, $attestationThrows, $notSuspended, $ownsPage, $rank, $tier, $inRecovery, $custody) extends CapabilityResolver {
            /**
             * @param array{owned: int, cap: int, last_event_ts: int|null, cooldown_seconds: int}|null $fakeCustody
             */
            public function __construct(
                private readonly bool $fakeWriteReview,
                private readonly ?\Throwable $fakeAttestationThrows,
                private readonly bool $fakeNotSuspended,
                private readonly bool $fakeOwnsPage,
                private readonly ?string $fakeRank,
                private readonly string $fakeTier,
                private readonly bool $fakeInRecovery,
                private readonly ?array $fakeCustody
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

            protected function rankAtLeast(int $userId, string $slug): bool
            {
                if ($this->fakeRank === null) {
                    return false; // no rank_state row = New Member
                }
                $order = ['apprentice' => 0, 'journeyman' => 1, 'veteran' => 2];
                return isset($order[$this->fakeRank], $order[$slug])
                    && $order[$this->fakeRank] >= $order[$slug];
            }

            protected function tierAtLeast(int $userId, string $tierSlug): bool
            {
                $ord = ['risky' => 0, 'caution' => 1, 'neutral' => 2, 'trusted' => 3, 'elite' => 4];
                return ($ord[$this->fakeTier] ?? 2) >= ($ord[$tierSlug] ?? 2);
            }

            protected function inRecovery(int $userId): bool
            {
                return $this->fakeInRecovery;
            }

            protected function custodyState(int $userId): array
            {
                return $this->fakeCustody ?? [
                    'owned'            => 0,
                    'cap'              => 2,
                    'last_event_ts'    => null,
                    'cooldown_seconds' => 30 * 86400,
                ];
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
        // Rank Phase 7 shrank this list to ONE key: the five §21
        // community-privilege keys are now live gates (matrices below).
        // receive_rank_vote_multiplier stays FUTURE — its policy lives
        // in the §16.6 formula; recovery-pause value = Phase 8.
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

    public function testSuspendedMemberIsDeniedBothWriteActions(): void
    {
        // Participation opt-out (SuspensionGateParity): suspension with the
        // admin bypass OFF blocks both write actions BEFORE the rank/tier
        // feature gate — even a member who would otherwise pass
        // writeReviewGate. Mirrors the open_dispute gate.
        foreach ([CapabilityCatalog::WRITE_REVIEW, CapabilityCatalog::CAST_DISPUTE_VOTE] as $key) {
            $denied = $this->resolver(writeReview: true, notSuspended: false)->can(7, $key);
            self::assertTrue($denied->isDenied(), "key {$key}");
            self::assertSame('suspended', $denied->reason, "key {$key}");
            self::assertSame('permissions', $denied->source, "key {$key}");
        }
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

    // ── Rank Phase 7 — §21.2 create / receive custody acquisition ────

    public function testCreateAndReceiveCommunityHappyPath(): void
    {
        // Apprentice + Neutral + no recovery + under cap + no cooldown.
        $resolver = $this->resolver(rank: 'apprentice', tier: 'neutral');

        foreach ([CapabilityCatalog::CREATE_COMMUNITY, CapabilityCatalog::RECEIVE_COMMUNITY] as $key) {
            $decision = $resolver->can(7, $key);
            self::assertTrue($decision->isAllowed(), "key {$key}");
            self::assertSame('community_custody', $decision->source, "key {$key}");
        }
    }

    public function testCustodyAcquisitionDenyReasonsInGateOrder(): void
    {
        foreach ([CapabilityCatalog::CREATE_COMMUNITY, CapabilityCatalog::RECEIVE_COMMUNITY] as $key) {
            // 1. suspended — first gate wins over every later failure.
            $suspended = $this->resolver(
                notSuspended: false,
                rank: null,
                tier: 'risky',
                inRecovery: true
            )->can(7, $key);
            self::assertSame('suspended', $suspended->reason, "key {$key}");

            // 2. no rank_state row = New Member.
            $newMember = $this->resolver(rank: null, tier: 'elite')->can(7, $key);
            self::assertSame('new_member', $newMember->reason, "key {$key}");

            // 3. tier below Neutral.
            $belowNeutral = $this->resolver(rank: 'veteran', tier: 'caution')->can(7, $key);
            self::assertSame('below_neutral', $belowNeutral->reason, "key {$key}");

            // 4. §14.2 recovery pause — wins over cap + cooldown below.
            $inRecovery = $this->resolver(
                rank: 'journeyman',
                tier: 'neutral',
                inRecovery: true,
                custody: ['owned' => 5, 'cap' => 5, 'last_event_ts' => time() - 1, 'cooldown_seconds' => 30 * self::DAY]
            )->can(7, $key);
            self::assertSame('in_recovery', $inRecovery->reason, "key {$key}");

            // 5. cap before cooldown when both fail.
            $capFirst = $this->resolver(
                rank: 'apprentice',
                tier: 'neutral',
                custody: ['owned' => 2, 'cap' => 2, 'last_event_ts' => time() - 1, 'cooldown_seconds' => 30 * self::DAY]
            )->can(7, $key);
            self::assertSame('cap_reached', $capFirst->reason, "key {$key}");

            // 6. cooldown alone.
            $cooldown = $this->resolver(
                rank: 'apprentice',
                tier: 'neutral',
                custody: ['owned' => 0, 'cap' => 2, 'last_event_ts' => time() - 1, 'cooldown_seconds' => 30 * self::DAY]
            )->can(7, $key);
            self::assertSame('cooldown_active', $cooldown->reason, "key {$key}");
            self::assertSame('community_custody', $cooldown->source, "key {$key}");
        }
    }

    public function testOwnershipCapDeniesExactlyAtCap(): void
    {
        // owned == cap denies; owned == cap - 1 allows.
        $atCap = $this->resolver(
            rank: 'journeyman',
            tier: 'neutral',
            custody: ['owned' => 5, 'cap' => 5, 'last_event_ts' => null, 'cooldown_seconds' => 30 * self::DAY]
        )->can(7, CapabilityCatalog::CREATE_COMMUNITY);
        self::assertSame('cap_reached', $atCap->reason);

        $underCap = $this->resolver(
            rank: 'journeyman',
            tier: 'neutral',
            custody: ['owned' => 4, 'cap' => 5, 'last_event_ts' => null, 'cooldown_seconds' => 30 * self::DAY]
        )->can(7, CapabilityCatalog::CREATE_COMMUNITY);
        self::assertTrue($underCap->isAllowed());
    }

    public function testCooldownBlocksWithinThirtyDaysAndExpiresAtExactlyThirty(): void
    {
        // One second short of the full window — still cooling down.
        $within = $this->resolver(
            rank: 'apprentice',
            tier: 'neutral',
            custody: ['owned' => 0, 'cap' => 2, 'last_event_ts' => time() - (30 * self::DAY) + 1, 'cooldown_seconds' => 30 * self::DAY]
        )->can(7, CapabilityCatalog::RECEIVE_COMMUNITY);
        self::assertSame('cooldown_active', $within->reason);

        // Exactly 30 days elapsed — the cooldown has expired (strict <).
        $expired = $this->resolver(
            rank: 'apprentice',
            tier: 'neutral',
            custody: ['owned' => 0, 'cap' => 2, 'last_event_ts' => time() - (30 * self::DAY), 'cooldown_seconds' => 30 * self::DAY]
        )->can(7, CapabilityCatalog::RECEIVE_COMMUNITY);
        self::assertTrue($expired->isAllowed());

        // No custody event ever — nothing to cool down from.
        $never = $this->resolver(
            rank: 'apprentice',
            tier: 'neutral',
            custody: ['owned' => 0, 'cap' => 2, 'last_event_ts' => null, 'cooldown_seconds' => 30 * self::DAY]
        )->can(7, CapabilityCatalog::CREATE_COMMUNITY);
        self::assertTrue($never->isAllowed());
    }

    // ── Rank Phase 7 — §21.2 transfer-away (the GIVER's gate) ────────

    public function testTransferCommunityIgnoresCapAndCooldown(): void
    {
        // At cap AND inside the cooldown — transfer-away still allowed:
        // the cooldown blocks ACQUIRING custody, never relinquishing it.
        $decision = $this->resolver(
            rank: 'apprentice',
            tier: 'neutral',
            custody: ['owned' => 2, 'cap' => 2, 'last_event_ts' => time() - 1, 'cooldown_seconds' => 30 * self::DAY]
        )->can(7, CapabilityCatalog::TRANSFER_COMMUNITY);

        self::assertTrue($decision->isAllowed());
        self::assertSame('community_custody', $decision->source);
    }

    public function testTransferCommunityDenyReasonsInGateOrder(): void
    {
        $suspended = $this->resolver(notSuspended: false, rank: null, inRecovery: true)
            ->can(7, CapabilityCatalog::TRANSFER_COMMUNITY);
        self::assertSame('suspended', $suspended->reason);

        // An owner can't actually be a New Member, but fail closed.
        $newMember = $this->resolver(rank: null)->can(7, CapabilityCatalog::TRANSFER_COMMUNITY);
        self::assertSame('new_member', $newMember->reason);

        $inRecovery = $this->resolver(rank: 'veteran', tier: 'elite', inRecovery: true)
            ->can(7, CapabilityCatalog::TRANSFER_COMMUNITY);
        self::assertSame('in_recovery', $inRecovery->reason);
    }

    // ── Rank Phase 7 — §21.3 ranked Hall feed posting ────────────────

    public function testPostRankedHallFeedMatrix(): void
    {
        $key = CapabilityCatalog::POST_RANKED_HALL_FEED;

        // Journeyman + Neutral — allowed (Hall membership is checked at
        // the gate site, PostsService::gateGroupPost, not here).
        $ok = $this->resolver(rank: 'journeyman', tier: 'neutral')->can(7, $key);
        self::assertTrue($ok->isAllowed());
        self::assertSame('ranked_hall', $ok->source);

        // Veteran qualifies too (Journeyman-OR-HIGHER).
        self::assertTrue($this->resolver(rank: 'veteran', tier: 'trusted')->can(7, $key)->isAllowed());

        $suspended = $this->resolver(notSuspended: false, rank: 'veteran', tier: 'elite')->can(7, $key);
        self::assertSame('suspended', $suspended->reason);

        $newMember = $this->resolver(rank: null, tier: 'elite')->can(7, $key);
        self::assertSame('new_member', $newMember->reason);

        // Apprentice — ranked channel starts at Journeyman.
        $apprentice = $this->resolver(rank: 'apprentice', tier: 'elite')->can(7, $key);
        self::assertSame('below_journeyman', $apprentice->reason);

        $belowNeutral = $this->resolver(rank: 'journeyman', tier: 'caution')->can(7, $key);
        self::assertSame('below_neutral', $belowNeutral->reason);

        $inRecovery = $this->resolver(rank: 'journeyman', tier: 'neutral', inRecovery: true)->can(7, $key);
        self::assertSame('in_recovery', $inRecovery->reason);
    }

    // ── Rank Phase 7 — §21.4 mentor eligibility ──────────────────────

    public function testListAsMentorMatrix(): void
    {
        $key = CapabilityCatalog::LIST_AS_MENTOR;

        // Veteran + Trusted — eligible. The explicit opt-in composes
        // OUTSIDE this key (MentorListingService), so the resolver
        // answers pure eligibility.
        $ok = $this->resolver(rank: 'veteran', tier: 'trusted')->can(7, $key);
        self::assertTrue($ok->isAllowed());
        self::assertSame('mentor_listing', $ok->source);

        // Elite exceeds Trusted — still eligible.
        self::assertTrue($this->resolver(rank: 'veteran', tier: 'elite')->can(7, $key)->isAllowed());

        $suspended = $this->resolver(notSuspended: false, rank: 'veteran', tier: 'elite')->can(7, $key);
        self::assertSame('suspended', $suspended->reason);

        $newMember = $this->resolver(rank: null, tier: 'elite')->can(7, $key);
        self::assertSame('new_member', $newMember->reason);

        $belowVeteran = $this->resolver(rank: 'journeyman', tier: 'elite')->can(7, $key);
        self::assertSame('below_veteran', $belowVeteran->reason);

        // The §21.4 pause case: a Veteran whose tier dropped below
        // Trusted stops being eligible LIVE — no sweep, no flag.
        $belowTrusted = $this->resolver(rank: 'veteran', tier: 'neutral')->can(7, $key);
        self::assertSame('below_trusted', $belowTrusted->reason);

        $inRecovery = $this->resolver(rank: 'veteran', tier: 'trusted', inRecovery: true)->can(7, $key);
        self::assertSame('in_recovery', $inRecovery->reason);
    }
}
