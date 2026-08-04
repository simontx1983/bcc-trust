<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\MentorListingService;
use BCC\Trust\Core\ValueObjects\CapabilityDecision;
use PHPUnit\Framework\TestCase;

/**
 * Rank Phase 7 (§21.4) — mentor listing composition.
 *
 * Listing = explicit opt-in AND live can('list_as_mentor'); nothing is
 * materialized, so a tier/rank/recovery change pauses the listing on
 * the NEXT read (no sweep, no flag). This suite pins that composition
 * with scripted opt-in + capability hooks; the eligibility matrix
 * itself (veteran / trusted / recovery / suspension reasons) is pinned
 * in CapabilityMatrixTest::testListAsMentorMatrix.
 */
final class MentorListingServiceTest extends TestCase
{
    /**
     * @param list<int> $optIns opted-in user ids
     * @param array<int, CapabilityDecision> $verdicts userId => list_as_mentor verdict
     */
    private function service(array $optIns, array $verdicts): RecordingMentorListingService
    {
        return new RecordingMentorListingService($optIns, $verdicts);
    }

    public function testEligibleOptedInUserIsListed(): void
    {
        $svc = $this->service([7], [7 => CapabilityDecision::allowed('mentor_listing')]);

        self::assertSame(
            ['opted_in' => true, 'listed' => true, 'reason' => null],
            $svc->listingFor(7)
        );
    }

    public function testTierDropPausesListingLiveWithTheReasonExposed(): void
    {
        // Opted in, but tier slid below Trusted — the listing pauses on
        // this very read and the stable reason explains the pause state.
        $svc = $this->service([7], [7 => CapabilityDecision::denied('below_trusted', 'mentor_listing')]);

        self::assertSame(
            ['opted_in' => true, 'listed' => false, 'reason' => 'below_trusted'],
            $svc->listingFor(7)
        );
    }

    public function testEligibleButNotOptedInIsNotListedAndCarriesNoReason(): void
    {
        // Pre-opt-in is the FE's call to action; reason stays null
        // because eligibility itself holds.
        $svc = $this->service([], [7 => CapabilityDecision::allowed('mentor_listing')]);

        self::assertSame(
            ['opted_in' => false, 'listed' => false, 'reason' => null],
            $svc->listingFor(7)
        );
    }

    public function testActiveMentorIdsFiltersOptInsThroughTheLiveCapability(): void
    {
        $svc = $this->service(
            [1, 2, 3],
            [
                1 => CapabilityDecision::allowed('mentor_listing'),
                2 => CapabilityDecision::denied('in_recovery', 'mentor_listing'),
                3 => CapabilityDecision::allowed('mentor_listing'),
            ]
        );

        self::assertSame([1, 3], $svc->activeMentorIds());
    }

    public function testListedAmongIntersectsPageUsersWithTheActiveSet(): void
    {
        $svc = $this->service(
            [2, 3],
            [
                2 => CapabilityDecision::denied('below_veteran', 'mentor_listing'),
                3 => CapabilityDecision::allowed('mentor_listing'),
            ]
        );

        // 4 never opted in; 2 opted in but ineligible; 3 is listed.
        self::assertSame([3 => true], $svc->listedAmong([2, 3, 4]));
        self::assertSame([], $svc->listedAmong([]));
    }
}

/**
 * Test double — scripts the opt-in enumeration + capability verdicts.
 * The default verdict for unscripted users is a deny so composition
 * failures surface as "not listed", never a phantom mentor.
 */
final class RecordingMentorListingService extends MentorListingService
{
    /**
     * @param list<int> $optIns
     * @param array<int, CapabilityDecision> $verdicts
     */
    public function __construct(
        private readonly array $optIns,
        private readonly array $verdicts
    ) {
    }

    public function isOptedIn(int $userId): bool
    {
        return in_array($userId, $this->optIns, true);
    }

    /** @return list<int> */
    protected function optedInUserIds(): array
    {
        return $this->optIns;
    }

    protected function capability(int $userId, string $key): CapabilityDecision
    {
        return $this->verdicts[$userId]
            ?? CapabilityDecision::denied('below_veteran', 'mentor_listing');
    }
}
