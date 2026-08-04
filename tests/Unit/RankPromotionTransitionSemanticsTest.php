<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Rank\Repositories\ClusterFindingsRepository;
use BCC\Trust\Rank\Repositories\FindingsRepository;
use BCC\Trust\Rank\Repositories\LoginDaysRepository;
use BCC\Trust\Rank\Repositories\RankEventsRepository;
use BCC\Trust\Rank\Repositories\RankStateRepository;
use BCC\Trust\Rank\Repositories\TierDaysRepository;
use BCC\Trust\Rank\Services\IndependenceResolver;
use BCC\Trust\Rank\Services\RankPromotionEngine;
use BCC\Trust\Rank\Services\RankScoreCalculator;
use BCC\Trust\Rank\Support\RankScoringConfig;
use BCC\Trust\Tests\Stubs\RankUnitWpFakes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Stubs/rank-unit-stubs.php';

/**
 * §14.1 / §23 transition semantics — the "ordinary recovery rules"
 * half of R1 case (e) plus the grace-first doctrine:
 *
 *   - requirement loss NEVER demotes instantly — it starts the
 *     90-day recovery grace (§14.1.3);
 *   - requirements restored during grace → recovery ends without
 *     demotion (§14.1.6);
 *   - promotion is blocked during grace (§14.1.4) — restoring higher
 *     requirements first clears the grace, and the promotion lands on
 *     a subsequent evaluation;
 *   - only a passed deadline demotes, and to the HIGHEST currently
 *     satisfied rung — never "one rung down" by assumption (§23.3,
 *     including the veteran→apprentice multi-rung case);
 *   - transitions emit exactly the sanctioned actions
 *     (`bcc_rank_awarded` / `bcc_rank_demoted`, and — Phase 8 —
 *     `bcc_rank_recovery_started` when the grace begins).
 *
 * evaluate() runs against an assess() override so every requirement
 * picture is exact; persistence is captured by a RankStateRepository
 * stub. The real RankScoringConfig supplies the 90-day grace.
 */
final class RankPromotionTransitionSemanticsTest extends TestCase
{
    private const USER = 7;

    protected function setUp(): void
    {
        RankUnitWpFakes::reset();
        \BCC\Core\Permissions\Permissions::$suspended = [];
    }

    /**
     * @param array{rank_slug: string, recovery_status: string, recovery_deadline: string|null} $row
     * @return array{0: RankPromotionEngine, 1: object}
     */
    private function engine(array $row, string $target): array
    {
        $state = new class extends RankStateRepository {
            /** @var list<array{0: int, 1: string, 2: string, 3: string|null}> */
            public array $persisted = [];
            public function __construct()
            {
            }
            public function persistEvaluation(
                int $userId,
                string $rankSlug,
                float $score,
                array $categories,
                string $recoveryStatus,
                ?string $recoveryDeadline
            ): bool {
                unset($score, $categories);
                $this->persisted[] = [$userId, $rankSlug, $recoveryStatus, $recoveryDeadline];
                return true;
            }
        };

        $events = new class extends RankEventsRepository {
            public function __construct()
            {
            }
        };
        $login = new class extends LoginDaysRepository {
            public function __construct()
            {
            }
        };
        $tier = new class extends TierDaysRepository {
            public function __construct()
            {
            }
        };
        $clusters = new class extends ClusterFindingsRepository {
            public function __construct()
            {
            }
        };
        $findings = new class extends FindingsRepository {
            public function __construct()
            {
            }
        };

        $config = RankScoringConfig::fromDefaultFile();

        $engine = new class (
            $row,
            $target,
            $state,
            $events,
            $login,
            $tier,
            $findings,
            new IndependenceResolver($clusters),
            new RankScoreCalculator($config),
            $config
        ) extends RankPromotionEngine {
            /** @param array{rank_slug: string, recovery_status: string, recovery_deadline: string|null} $fakeRow */
            public function __construct(
                private readonly array $fakeRow,
                private readonly string $fakeTarget,
                RankStateRepository $state,
                RankEventsRepository $events,
                LoginDaysRepository $login,
                TierDaysRepository $tier,
                FindingsRepository $findings,
                IndependenceResolver $independence,
                RankScoreCalculator $calculator,
                RankScoringConfig $config
            ) {
                parent::__construct($state, $events, $login, $tier, $findings, $independence, $calculator, $config);
            }

            public function assess(int $userId): ?array
            {
                unset($userId);
                $order = ['apprentice' => 1, 'journeyman' => 2, 'veteran' => 3];

                return [
                    'row'                => (object) ($this->fakeRow + ['apprentice_awarded_at' => '2026-07-01 00:00:00']),
                    'categories'         => ['contribution' => 0.0, 'helping' => 0.0, 'recognition' => 0.0, 'outcomes' => 0.0, 'time' => 0.0],
                    'decay'              => 0.0,
                    'finding_penalty'    => 0.0,
                    'total'              => 0.0,
                    'contribution_types' => 0,
                    'recognizers'        => 0,
                    'outcomes'           => 0,
                    'outcome_types'      => 0,
                    'windows'            => [],
                    'satisfied'          => [
                        'apprentice' => true,
                        'journeyman' => $order[$this->fakeTarget] >= 2,
                        'veteran'    => $order[$this->fakeTarget] >= 3,
                    ],
                    'target'             => $this->fakeTarget,
                ];
            }
        };

        return [$engine, $state];
    }

    public function testPromotionWhenHigherRungSatisfied(): void
    {
        [$engine, $state] = $this->engine(
            ['rank_slug' => 'apprentice', 'recovery_status' => '', 'recovery_deadline' => null],
            'journeyman'
        );
        $engine->evaluate(self::USER);

        self::assertSame([self::USER, 'journeyman', '', null], $state->persisted[0]);
        self::assertSame(['bcc_rank_awarded'], RankUnitWpFakes::firedHooks());
    }

    public function testPromotionBlockedDuringGraceButGraceClears(): void
    {
        // §14.1.4: in grace with HIGHER requirements now satisfied —
        // no promotion this evaluation; the restored requirements end
        // the recovery (§14.1.6). The promotion lands next evaluation.
        [$engine, $state] = $this->engine(
            ['rank_slug' => 'journeyman', 'recovery_status' => 'grace', 'recovery_deadline' => gmdate('Y-m-d H:i:s', time() + 86400)],
            'veteran'
        );
        $engine->evaluate(self::USER);

        self::assertSame([self::USER, 'journeyman', '', null], $state->persisted[0]);
        self::assertSame([], RankUnitWpFakes::firedHooks(), 'no award, no demotion');
    }

    public function testRequirementLossStartsGraceNeverInstantDemotion(): void
    {
        [$engine, $state] = $this->engine(
            ['rank_slug' => 'journeyman', 'recovery_status' => '', 'recovery_deadline' => null],
            'apprentice'
        );
        $engine->evaluate(self::USER);

        [$userId, $rank, $status, $deadline] = $state->persisted[0];
        self::assertSame(self::USER, $userId);
        self::assertSame('journeyman', $rank, 'rank retained through grace start');
        self::assertSame('grace', $status);
        self::assertNotNull($deadline);

        // Deadline ≈ now + the config's 90-day grace (§14.1.3).
        $expected = time() + RankScoringConfig::fromDefaultFile()->recoveryGraceDays * 86400;
        $actual   = strtotime($deadline . ' UTC');
        self::assertNotFalse($actual);
        self::assertEqualsWithDelta($expected, $actual, 3600.0);

        // Phase 8: grace start emits the recovery notice — but NEVER a
        // demotion action (§14.1.3 still holds: no instant demotion).
        self::assertSame(['bcc_rank_recovery_started'], RankUnitWpFakes::firedHooks());
        self::assertSame([self::USER, $deadline], RankUnitWpFakes::$actions[0][1]);
    }

    public function testGraceHoldsUntilDeadline(): void
    {
        $deadline = gmdate('Y-m-d H:i:s', time() + 86400);
        [$engine, $state] = $this->engine(
            ['rank_slug' => 'journeyman', 'recovery_status' => 'grace', 'recovery_deadline' => $deadline],
            'apprentice'
        );
        $engine->evaluate(self::USER);

        self::assertSame([self::USER, 'journeyman', 'grace', $deadline], $state->persisted[0]);
        self::assertSame([], RankUnitWpFakes::firedHooks());
    }

    public function testExpiredDeadlineDemotesToHighestSatisfied(): void
    {
        [$engine, $state] = $this->engine(
            ['rank_slug' => 'journeyman', 'recovery_status' => 'grace', 'recovery_deadline' => gmdate('Y-m-d H:i:s', time() - 60)],
            'apprentice'
        );
        $engine->evaluate(self::USER);

        self::assertSame([self::USER, 'apprentice', '', null], $state->persisted[0]);
        self::assertSame(['bcc_rank_demoted'], RankUnitWpFakes::firedHooks());
    }

    public function testMultiRungDemotionGoesToHighestSatisfiedNotOneDown(): void
    {
        // §23.3: veteran whose only satisfied rung is apprentice lands
        // on apprentice — never an assumed single-rung step.
        [$engine, $state] = $this->engine(
            ['rank_slug' => 'veteran', 'recovery_status' => 'grace', 'recovery_deadline' => gmdate('Y-m-d H:i:s', time() - 60)],
            'apprentice'
        );
        $engine->evaluate(self::USER);

        self::assertSame([self::USER, 'apprentice', '', null], $state->persisted[0]);
        self::assertSame(['bcc_rank_demoted'], RankUnitWpFakes::firedHooks());
    }
}
