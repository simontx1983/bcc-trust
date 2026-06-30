<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Core\Services\DivergenceStateClassifier;
use BCC\Trust\Core\Services\MemberSelfPageService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the member-card LIST perf seam: CardViewService::
 * buildNegativeSignals resolves negative_signals for a member
 * (user_profile) from the MemberCardPrefetcher bundle — O(1) per page,
 * NOT a per-row dispute read — and threads the id-duality on the
 * batched maps:
 *
 *   - dispute_active_counts      keyed by selfPageId(user_id);
 *   - attestation_active_counts  keyed by the RAW user id;
 *   - attestation_revoked_counts keyed by the RAW user id.
 *
 * A regression that keys the dispute map on the raw user id (or the
 * attestation maps on the self-page id) turns this suite red.
 *
 * Isolation: buildNegativeSignals' prefetch branch is pure once a bundle
 * is supplied — it reads the three maps and delegates to
 * DivergenceStateClassifier::classifyFromCounts (a pure function). The
 * CardViewService + classifier are built constructor-free and the only
 * dependency the branch touches (divergenceClassifier) is injected by
 * reflection. No DB, no WP.
 */
final class MemberNegativeSignalsPrefetchTest extends TestCase
{
    private const USER_ID = 7;

    /** @return array{under_review: bool, divergence_state: string, volatile: bool, unresolved_claims_count: int} */
    private function buildFromBundle(array $prefetched): array
    {
        $classifier = (new ReflectionClass(DivergenceStateClassifier::class))->newInstanceWithoutConstructor();

        $service = (new ReflectionClass(CardViewService::class))->newInstanceWithoutConstructor();
        $prop = (new ReflectionClass(CardViewService::class))->getProperty('divergenceClassifier');
        $prop->setValue($service, $classifier);

        $method = new ReflectionMethod(CardViewService::class, 'buildNegativeSignals');
        /** @var array{under_review: bool, divergence_state: string, volatile: bool, unresolved_claims_count: int} $result */
        $result = $method->invoke($service, 'user_profile', self::USER_ID, $prefetched);
        return $result;
    }

    public function testDisputeMapKeyedOnSelfPageIdDrivesDisputedAndCount(): void
    {
        $selfPageId = MemberSelfPageService::selfPageId(self::USER_ID);

        $signals = $this->buildFromBundle([
            // Dispute count lives under the SELF-PAGE id.
            'dispute_active_counts'      => [$selfPageId => 2],
            // Attestation counts live under the RAW user id.
            'attestation_active_counts'  => [self::USER_ID => 5],
            'attestation_revoked_counts' => [self::USER_ID => 0],
        ]);

        self::assertTrue($signals['under_review']);
        self::assertSame(2, $signals['unresolved_claims_count']);
        self::assertSame(DivergenceStateClassifier::STATE_DISPUTED, $signals['divergence_state']);
    }

    public function testDisputeCountKeyedOnRawUserIdIsIgnored(): void
    {
        // Inverse id-duality guard: a dispute count mistakenly keyed on
        // the RAW user id must NOT register — the read keys on selfPageId.
        $signals = $this->buildFromBundle([
            'dispute_active_counts'      => [self::USER_ID => 3], // WRONG key
            'attestation_active_counts'  => [self::USER_ID => 5],
            'attestation_revoked_counts' => [self::USER_ID => 0],
        ]);

        self::assertFalse($signals['under_review']);
        self::assertSame(0, $signals['unresolved_claims_count']);
        // 5 active, 0 revoked, no dispute → well_regarded.
        self::assertSame(DivergenceStateClassifier::STATE_WELL_REGARDED, $signals['divergence_state']);
    }

    public function testAttestationCountsKeyedOnRawUserIdDriveTheHeuristic(): void
    {
        $selfPageId = MemberSelfPageService::selfPageId(self::USER_ID);

        // No dispute; high revoked:active ratio on the RAW user id keys.
        $signals = $this->buildFromBundle([
            'dispute_active_counts'      => [$selfPageId => 0],
            'attestation_active_counts'  => [self::USER_ID => 3],
            'attestation_revoked_counts' => [self::USER_ID => 6], // > 3 * 1.5
        ]);

        self::assertFalse($signals['under_review']);
        self::assertSame(DivergenceStateClassifier::STATE_POORLY_REGARDED, $signals['divergence_state']);
    }

    public function testAbsentIdsDefaultToZeroUntested(): void
    {
        // Empty maps (member with no disputes / attestations) → untested.
        $signals = $this->buildFromBundle([
            'dispute_active_counts'      => [],
            'attestation_active_counts'  => [],
            'attestation_revoked_counts' => [],
        ]);

        self::assertFalse($signals['under_review']);
        self::assertSame(0, $signals['unresolved_claims_count']);
        self::assertSame(DivergenceStateClassifier::STATE_UNTESTED, $signals['divergence_state']);
    }
}
