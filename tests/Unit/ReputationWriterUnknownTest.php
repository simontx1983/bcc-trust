<?php
/**
 * F-469 batch 3: the reputation WRITERS skip, and a later run retries, when
 * a tier, score or bonus could not be read. They never write from the
 * neutral defaults the display readers answer.
 *
 *   - AttestationScoreSynthesis: the attestor tier set (elite-source cap).
 *   - ContributionScoreService: the reactor tier set (usefulness weights).
 *   - ContributionRecoveryEvaluator: the stored bonus and score, read before
 *     anything is computed.
 *   - TierUpgradeListener: an unreadable tier is no transition.
 *
 * (The tier snapshot's cases live in RankSweepCursorTest.)
 *
 * Each "readable" case is the control: the same path still runs when the
 * read succeeds, so the refusal is specific to the unknown answer.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\PeepSoReactionRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Services\AttestationScoreSynthesis;
use BCC\Trust\Core\Services\ContributionRecoveryEvaluator;
use BCC\Trust\Core\Services\ContributionScoreService;
use BCC\Trust\Core\Services\TierUpgradeListener;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionObject;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReputationWriterUnknownTest extends TestCase
{
    private const LAST_SEEN = 'bcc_last_seen_reputation_tier';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/reputation-writer-unknown-stubs.php';
        \ReputationWriterWorld::reset();
    }

    // ── AttestationScoreSynthesis ───────────────────────────────────────

    public function testAnUnreadableAttestorTierSetWritesNoBonus(): void
    {
        \ReputationWriterWorld::$results['attestor_user_id, weight_at_time'] = [self::attestationRow(5)];
        $scores = self::scoreRepo();

        // Any Throwable is captured, so a path that went on past the tier read
        // and broke somewhere else fails the assertions below, not as an error.
        $thrown = null;
        try {
            self::synthesis(self::reputation(tiers: null), $scores)->recomputeFor('validator_card', 2015);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $thrown, 'an unreadable attestor tier set must stop the recompute');
        self::assertSame('attestor tiers could not be read', $thrown->getMessage());
        self::assertSame([], $scores->writes, 'no bonus, no endorsement count written');
    }

    public function testAReadableAttestorTierSetStillSynthesizes(): void
    {
        \ReputationWriterWorld::$results['attestor_user_id, weight_at_time'] = [self::attestationRow(5)];

        $bonus = self::synthesis(self::reputation(tiers: []), self::scoreRepo())
            ->synthesize('user_profile', 7, new \DateTimeImmutable('2026-11-02 00:00:00', new \DateTimeZone('UTC')));

        self::assertGreaterThan(0.0, $bonus);
    }

    /**
     * The attestation ROWS read (runRead(), fail-closed) failing stops the
     * recompute before any write as well, both when wpdb reports the error
     * and when it returns early without one.
     */
    public function testAnUnreadableAttestationRowSetWritesNoBonusEither(): void
    {
        foreach (['error', 'silent'] as $how) {
            \ReputationWriterWorld::reset();
            \ReputationWriterWorld::$results['attestor_user_id, weight_at_time']  = [self::attestationRow(5)];
            \ReputationWriterWorld::$failures['attestor_user_id, weight_at_time'] = $how;
            $scores = self::scoreRepo();

            $thrown = null;
            try {
                self::synthesis(self::reputation(tiers: []), $scores)->recomputeFor('validator_card', 2015);
            } catch (\Throwable $e) {
                $thrown = $e;
            }

            self::assertInstanceOf(RepositoryReadFailure::class, $thrown, "{$how}: a failed read is not \"no attestations\"");
            self::assertSame([], $scores->writes, "{$how}: no bonus written");
        }
    }

    // ── ContributionScoreService ────────────────────────────────────────

    public function testAnUnreadableReactorTierSetComputesNoUsefulness(): void
    {
        \ReputationWriterWorld::$results['reaction_user_id AS uid'] = [['uid' => '5', 'c' => '3']];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reactor tiers could not be read');

        self::usefulness(self::reputation(tiers: null));
    }

    public function testReadableReactorTiersStillWeighTheEngagement(): void
    {
        \ReputationWriterWorld::$results['reaction_user_id AS uid'] = [['uid' => '5', 'c' => '3']];

        self::assertSame(
            ContributionScoreService::usefulnessFromWeightedEngagement(ContributionScoreService::tierWeight('trusted') * 3.0),
            self::usefulness(self::reputation(tiers: [5 => 'trusted']))
        );
    }

    // ── ContributionRecoveryEvaluator ───────────────────────────────────

    public function testAnUnreadableRowFailsTheEvaluationBeforeAnythingIsComputed(): void
    {
        foreach (['bonus' => [null, 60.0], 'score' => [1.5, null]] as $label => [$bonus, $score]) {
            \ReputationWriterWorld::reset();

            $out = (new ContributionRecoveryEvaluator(
                self::uncomputable(),
                self::reputation(bonus: $bonus, score: $score, risky: [7])
            ))->runDaily();

            self::assertSame(['evaluated' => 1, 'adjusted' => 0, 'failed' => 1], $out, $label);
            self::assertSame(['contribution_recovery.user_eval_failed'], \ReputationWriterWorld::$metrics, $label);
            self::assertTrue(\ReputationWriterWorld::logged('reputation row could not be read'), $label);
        }
    }

    public function testAReadableRowReachesTheComputation(): void
    {
        $out = (new ContributionRecoveryEvaluator(
            self::uncomputable(),
            self::reputation(bonus: 0.0, score: 60.0, risky: [7])
        ))->runDaily();

        // The uncomputable double fails INSIDE computeBonus(): proof the
        // readable row got that far, and failed for another reason.
        self::assertSame(1, $out['failed']);
        self::assertFalse(\ReputationWriterWorld::logged('reputation row could not be read'));
    }

    // ── TierUpgradeListener ─────────────────────────────────────────────

    public function testAnUnreadableTierIsNoTransition(): void
    {
        \ReputationWriterWorld::$userMeta[7][self::LAST_SEEN] = 'trusted';

        (new TierUpgradeListener(self::reputation(tier: null)))->onActivityEvent(7);

        self::assertSame('trusted', \ReputationWriterWorld::$userMeta[7][self::LAST_SEEN], 'last-seen untouched');
        self::assertSame([], \ReputationWriterWorld::$actions, 'nothing announced');
    }

    public function testAnUnreadableTierSeedsNothing(): void
    {
        (new TierUpgradeListener(self::reputation(tier: null)))->onActivityEvent(7);

        self::assertSame([], \ReputationWriterWorld::$userMeta);
    }

    public function testAReadableTierStillSeeds(): void
    {
        (new TierUpgradeListener(self::reputation(tier: 'caution')))->onActivityEvent(7);

        self::assertSame('caution', \ReputationWriterWorld::$userMeta[7][self::LAST_SEEN] ?? null);
    }

    // ── Builders ────────────────────────────────────────────────────────

    /**
     * Tri-state answers as given (null = could not be read). The lenient
     * readers answer the old defaults, so a writer that still used them
     * would carry on and fail these tests.
     *
     * @param array<int, string>|null $tiers
     * @param list<int>               $risky
     */
    private static function reputation(
        ?array $tiers = [],
        ?string $tier = 'neutral',
        ?float $bonus = 0.0,
        ?float $score = 50.0,
        array $risky = []
    ): ReputationRepository {
        return new class ($tiers, $tier, $bonus, $score, $risky) extends ReputationRepository {
            /**
             * @param array<int, string>|null $fTiers
             * @param list<int>               $fRisky
             */
            public function __construct(
                private ?array $fTiers,
                private ?string $fTier,
                private ?float $fBonus,
                private ?float $fScore,
                private array $fRisky
            ) {
            }

            public function getTiersForUsersOrUnknown(array $userIds): ?array
            {
                return $this->fTiers;
            }

            public function getTiersForUsers(array $userIds): array
            {
                return $this->fTiers ?? [];
            }

            public function getTierOrUnknown(int $userId): ?string
            {
                return $this->fTier;
            }

            public function getTier(int $userId): string
            {
                return $this->fTier ?? 'neutral';
            }

            public function getContributionBonusOrUnknown(int $userId): ?float
            {
                return $this->fBonus;
            }

            public function getContributionBonus(int $userId): float
            {
                return $this->fBonus ?? 0.0;
            }

            public function getScoreOrUnknown(int $userId): ?float
            {
                return $this->fScore;
            }

            public function getScore(int $userId): float
            {
                return $this->fScore ?? 50.0;
            }

            public function getCautionAndRiskyUserIds(int $limit = 1000): array
            {
                return $this->fRisky;
            }
        };
    }

    private static function attestationRow(int $attestorId): object
    {
        return (object) [
            'attestor_user_id' => $attestorId,
            'weight_at_time'   => 1.0,
            'created_at'       => '2026-11-01 00:00:00',
            'reaffirmed_at'    => null,
        ];
    }

    /** @return ScoreRepository&object{writes: list<string>} */
    private static function scoreRepo(): ScoreRepository
    {
        return new class extends ScoreRepository {
            /** @var list<string> */
            public array $writes = [];

            public function __construct()
            {
            }

            public function applyAttestationBonus(int $pageId, float $bonus): void
            {
                $this->writes[] = "bonus:{$pageId}";
            }

            public function updateEndorsementCount(int $pageId, int $count): void
            {
                $this->writes[] = "endorsements:{$pageId}";
            }
        };
    }

    private static function synthesis(ReputationRepository $rep, ScoreRepository $scores): AttestationScoreSynthesis
    {
        $s = (new ReflectionClass(AttestationScoreSynthesis::class))->newInstanceWithoutConstructor();
        $o = new ReflectionObject($s);
        $o->getProperty('attestationRepo')->setValue($s, (new ReflectionClass(AttestationRepository::class))->newInstanceWithoutConstructor());
        $o->getProperty('reputationRepo')->setValue($s, $rep);
        $o->getProperty('scoreRepo')->setValue($s, $scores);

        return $s;
    }

    private static function usefulness(ReputationRepository $rep): float
    {
        $svc = (new ReflectionClass(ContributionScoreService::class))->newInstanceWithoutConstructor();
        $o   = new ReflectionObject($svc);
        $o->getProperty('reactionRepo')->setValue($svc, (new ReflectionClass(PeepSoReactionRepository::class))->newInstanceWithoutConstructor());
        $o->getProperty('reputationRepo')->setValue($svc, $rep);

        /** @var float $u */
        $u = (new ReflectionMethod(ContributionScoreService::class, 'engagementUsefulness'))
            ->invoke($svc, 7, '2026-01-01 00:00:00');

        return $u;
    }

    /** A ContributionScoreService with no collaborators: computeBonus() fails if reached. */
    private static function uncomputable(): ContributionScoreService
    {
        return (new ReflectionClass(ContributionScoreService::class))->newInstanceWithoutConstructor();
    }
}
