<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Services\DivergenceStateClassifier;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Pins the §J.8 five-state classifier for member self-pages
 * (user_profile target_kind) — the surfacing this PR wires up.
 *
 * ## Isolation strategy
 *
 * Each method runs in its OWN subprocess; setUp() loads in-namespace
 * stubs (divergence-stubs.php) for AttestationRepository +
 * DisputeRepository at the production FQNs, then manually requires the
 * real DivergenceStateClassifier. Because the stubs register before the
 * classifier references them, Composer's autoloader never loads the
 * real repositories in the subprocess. MemberSelfPageService stays REAL
 * (pure static selfPageId()).
 *
 * ## What is locked
 *
 *   - members now produce the FULL enum (disputed/poorly/well/untested),
 *     no longer a 4-state surface that skips `disputed`;
 *   - the id-duality: the dispute existence read keys on the member's
 *     self-page id (ID_BASE + user_id) while the attestation counts key
 *     on the RAW user id — a regression that crosses the two turns this
 *     suite red.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DivergenceStateClassifierTest extends TestCase
{
    private const USER_ID = 42;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../Stubs/divergence-stubs.php';

        AttestationRepository::reset();
        DisputeRepository::reset();

        require_once __DIR__ . '/../../app/Domain/Core/Services/DivergenceStateClassifier.php';
    }

    private function classifier(): DivergenceStateClassifier
    {
        return new DivergenceStateClassifier(new AttestationRepository());
    }

    private function selfPageId(): int
    {
        return MemberSelfPageService::selfPageId(self::USER_ID);
    }

    public function testUserProfileWithActiveDisputeOnSelfPageClassifiesDisputed(): void
    {
        // Active dispute lives on the SELF-PAGE id, not the raw user id.
        DisputeRepository::$hasActiveMap[$this->selfPageId()] = true;
        // Plenty of attestations too — disputed still wins.
        AttestationRepository::$activeTotal = 10;

        self::assertSame(
            DivergenceStateClassifier::STATE_DISPUTED,
            $this->classifier()->classify('user_profile', self::USER_ID)
        );
    }

    public function testUserProfileDisputeReadUsesSelfPageIdAndAttestationCountsUseRawUserId(): void
    {
        // The id-duality assertion. A member with an active dispute on
        // their SELF-PAGE id AND attestations on their RAW user id must
        // classify `disputed` — proving each read hit the right id.
        DisputeRepository::$hasActiveMap[$this->selfPageId()] = true;
        AttestationRepository::$activeTotal = 5;

        $state = $this->classifier()->classify('user_profile', self::USER_ID);
        self::assertSame(DivergenceStateClassifier::STATE_DISPUTED, $state);

        // Dispute existence read keyed on the self-page id, NEVER the raw id.
        self::assertSame([$this->selfPageId()], DisputeRepository::$hasActiveCalls);
        self::assertNotContains(self::USER_ID, DisputeRepository::$hasActiveCalls);

        // Attestation counts keyed on the RAW user id, NEVER the self-page id.
        self::assertSame(
            [['kind' => 'user_profile', 'id' => self::USER_ID]],
            AttestationRepository::$countByTargetCalls
        );
        self::assertSame(
            [['kind' => 'user_profile', 'id' => self::USER_ID]],
            AttestationRepository::$countRevokedCalls
        );
    }

    public function testUserProfileNoDisputeFewAttestationsIsUntested(): void
    {
        AttestationRepository::$activeTotal = 2; // <= UNTESTED_ATTESTATION_FLOOR
        AttestationRepository::$revokedTotal = 0;

        self::assertSame(
            DivergenceStateClassifier::STATE_UNTESTED,
            $this->classifier()->classify('user_profile', self::USER_ID)
        );
    }

    public function testUserProfileNoDisputeManyActiveIsWellRegarded(): void
    {
        AttestationRepository::$activeTotal = 5;  // > floor
        AttestationRepository::$revokedTotal = 1; // not > 5 * 1.5

        self::assertSame(
            DivergenceStateClassifier::STATE_WELL_REGARDED,
            $this->classifier()->classify('user_profile', self::USER_ID)
        );
    }

    public function testUserProfileHighRevocationRatioIsPoorlyRegarded(): void
    {
        AttestationRepository::$activeTotal = 3;   // > floor
        AttestationRepository::$revokedTotal = 6;  // > 3 * 1.5 = 4.5

        self::assertSame(
            DivergenceStateClassifier::STATE_POORLY_REGARDED,
            $this->classifier()->classify('user_profile', self::USER_ID)
        );
    }

    public function testActiveDisputeAgainstRawUserIdAloneDoesNotFire(): void
    {
        // A stray active dispute keyed on the RAW user id (the WRONG id)
        // must NOT classify disputed — the read only consults the
        // self-page id. This is the inverse id-duality guard.
        DisputeRepository::$hasActiveMap[self::USER_ID] = true;
        AttestationRepository::$activeTotal = 5;
        AttestationRepository::$revokedTotal = 0;

        self::assertSame(
            DivergenceStateClassifier::STATE_WELL_REGARDED,
            $this->classifier()->classify('user_profile', self::USER_ID)
        );
    }
}
