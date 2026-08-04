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
 * §15.3 finding penalties + ceilings inside the REAL assess()/evaluate()
 * pipeline (Rank Phase 8), plus the recovery-reminder and decay-warning
 * signals:
 *
 *   - active-unexpired penalties sum, capped at the 60-point total
 *     (each already ≤ the 40-point per-finding cap by config snapshot);
 *     expired penalties are excluded in PHP UTC;
 *   - an active ceiling makes every rung ABOVE it unsatisfiable (the
 *     satisfied[] AND-term) and lifts on expiry;
 *   - a ceiling on a higher-ranked member flows through the ORDINARY
 *     §14.1 grace machinery on evaluate();
 *   - evaluateImmediate() (severe path) demotes NOW — no grace — to the
 *     highest satisfied rung under the ceiling, and delegates to
 *     ordinary evaluate() when no demotion is needed;
 *   - the recovery reminder fires at exactly the 30-/7-day marks;
 *   - the decay warning fires when decay is active, throttled by the
 *     30-day usermeta stamp.
 *
 * Fakes feed exact evidence pictures; the engine, calculator, and
 * config are all real (config = the shipped file, Phase 0 doctrine).
 */
final class RankFindingAssessmentTest extends TestCase
{
    private const USER = 7;

    protected function setUp(): void
    {
        RankUnitWpFakes::reset();
        \BCC\Core\Permissions\Permissions::$suspended = [];

        // IndependenceResolver memoizes its cluster map statically per
        // request; reset so this suite always sees the empty fake map.
        $memo = new \ReflectionProperty(IndependenceResolver::class, 'memberToRepresentative');
        $memo->setValue(null, null);
    }

    /** Ledger-row shape (RankEventsRepository::listActiveForSubject). */
    private function event(string $category, string $sourceType, float $capped, int $relationship = 0): object
    {
        return (object) [
            'category'             => $category,
            'source_type'          => $sourceType,
            'relationship_user_id' => (string) $relationship,
            'capped_value'         => (string) $capped,
        ];
    }

    /**
     * Evidence that satisfies EVERY veteran requirement exactly:
     * contribution 25 (5 types), helping 25 (10 recipients),
     * recognition 15 (5 recognizers), outcomes 15 (5 events, 2 types);
     * time = 20 via 20 distinct login months. Total 100.
     *
     * @return list<object>
     */
    private function veteranEvidence(): array
    {
        $events = [];
        foreach (['post', 'comment', 'review', 'stewardship', 'onboarding'] as $type) {
            $events[] = $this->event('contribution', $type, 5.0);
        }
        for ($i = 101; $i <= 110; $i++) {
            $events[] = $this->event('helping', 'helpful_mark', 2.5, $i);
        }
        for ($i = 201; $i <= 205; $i++) {
            $events[] = $this->event('recognition', 'recognition', 3.0, $i);
        }
        for ($i = 0; $i < 5; $i++) {
            $events[] = $this->event('outcomes', $i % 2 === 0 ? 'outcome' : 'report_upheld', 3.0);
        }
        return $events;
    }

    /** FindingRow shape (FindingsRepository::getActiveForUser). */
    private function finding(
        float $penalty,
        string $penaltyExpiresAt,
        ?string $ceilingRank = null,
        ?string $ceilingExpiresAt = null
    ): object {
        return (object) [
            'score_penalty'      => (string) $penalty,
            'penalty_expires_at' => $penaltyExpiresAt,
            'ceiling_rank_slug'  => $ceilingRank,
            'ceiling_expires_at' => $ceilingExpiresAt,
            'status'             => 'active',
        ];
    }

    /**
     * @param array{rank_slug: string, recovery_status: string, recovery_deadline: string|null} $stateRow
     * @param list<object> $findingRows
     * @param list<object> $events
     * @return array{0: RankPromotionEngine, 1: object}
     */
    private function engine(array $stateRow, array $findingRows, array $events, ?string $lastLoginDay = null): array
    {
        $row = (object) ($stateRow + ['apprentice_awarded_at' => '2026-01-01 00:00:00']);

        $state = new class ($row) extends RankStateRepository {
            /** @var list<array{0: int, 1: string, 2: string, 3: string|null}> */
            public array $persisted = [];
            public function __construct(private readonly object $row)
            {
            }
            public function getForUser(int $userId): ?object
            {
                unset($userId);
                return $this->row;
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

        $eventsRepo = new class ($events) extends RankEventsRepository {
            /** @param list<object> $rows */
            public function __construct(private readonly array $rows)
            {
            }
            public function listActiveForSubject(int $subjectUserId): array
            {
                unset($subjectUserId);
                return $this->rows;
            }
        };

        $login = new class ($lastLoginDay ?? gmdate('Y-m-d')) extends LoginDaysRepository {
            public function __construct(private readonly string $lastDay)
            {
            }
            public function distinctMonthCount(int $userId): int
            {
                unset($userId);
                return 20;
            }
            public function lastLoginDay(int $userId): ?string
            {
                unset($userId);
                return $this->lastDay;
            }
        };

        $tier = new class extends TierDaysRepository {
            public function __construct()
            {
            }
            public function countQualifyingDays(int $userId, string $sinceDay, int $minTierOrd): int
            {
                unset($userId, $sinceDay, $minTierOrd);
                return 999;
            }
        };

        $findings = new class ($findingRows) extends FindingsRepository {
            /** @param list<object> $rows */
            public function __construct(private readonly array $rows)
            {
            }
            public function getActiveForUser(int $userId): array
            {
                unset($userId);
                return $this->rows;
            }
        };

        $clusters = new class extends ClusterFindingsRepository {
            public function __construct()
            {
            }
            public function listActiveConfirmed(): array
            {
                return [];
            }
        };

        $config = RankScoringConfig::fromDefaultFile();

        $engine = new RankPromotionEngine(
            $state,
            $eventsRepo,
            $login,
            $tier,
            $findings,
            new IndependenceResolver($clusters),
            new RankScoreCalculator($config),
            $config
        );

        return [$engine, $state];
    }

    private function future(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() + ($days * 86400));
    }

    private function past(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    }

    // ── Penalty math ─────────────────────────────────────────────────────

    public function testPenaltySumRespectsExpiryAndTotalCap(): void
    {
        // Two active 40s (sum 80 → capped at 60) + one EXPIRED 25
        // (excluded). Earned 100 → total 40 → journeyman exactly.
        [$engine] = $this->engine(
            ['rank_slug' => 'veteran', 'recovery_status' => '', 'recovery_deadline' => null],
            [
                $this->finding(40.0, $this->future(300)),
                $this->finding(40.0, $this->future(300)),
                $this->finding(25.0, $this->past(1)),
            ],
            $this->veteranEvidence()
        );

        $assessment = $engine->assess(self::USER);
        self::assertNotNull($assessment);

        self::assertSame(60.0, $assessment['finding_penalty'], 'total-active cap applied, expired excluded');
        self::assertSame(40.0, $assessment['total']);
        self::assertSame('journeyman', $assessment['target']);
    }

    public function testCleanRecordCarriesNoPenalty(): void
    {
        [$engine] = $this->engine(
            ['rank_slug' => 'veteran', 'recovery_status' => '', 'recovery_deadline' => null],
            [],
            $this->veteranEvidence()
        );

        $assessment = $engine->assess(self::USER);
        self::assertNotNull($assessment);

        self::assertSame(0.0, $assessment['finding_penalty']);
        self::assertSame(100.0, $assessment['total']);
        self::assertSame('veteran', $assessment['target']);
    }

    // ── Ceiling AND-term ─────────────────────────────────────────────────

    public function testActiveCeilingMakesHigherRungsUnsatisfiable(): void
    {
        // Score stays veteran-grade (95 ≥ 80) — the CEILING alone caps
        // the target at journeyman (the satisfied[] AND-term).
        [$engine] = $this->engine(
            ['rank_slug' => 'veteran', 'recovery_status' => '', 'recovery_deadline' => null],
            [$this->finding(5.0, $this->future(90), 'journeyman', $this->future(180))],
            $this->veteranEvidence()
        );

        $assessment = $engine->assess(self::USER);
        self::assertNotNull($assessment);

        self::assertSame(95.0, $assessment['total']);
        self::assertTrue($assessment['satisfied']['journeyman']);
        self::assertFalse($assessment['satisfied']['veteran'], 'veteran unsatisfiable under a journeyman ceiling');
        self::assertSame('journeyman', $assessment['target']);
    }

    public function testExpiredCeilingLifts(): void
    {
        [$engine] = $this->engine(
            ['rank_slug' => 'veteran', 'recovery_status' => '', 'recovery_deadline' => null],
            [$this->finding(5.0, $this->future(90), 'journeyman', $this->past(1))],
            $this->veteranEvidence()
        );

        $assessment = $engine->assess(self::USER);
        self::assertNotNull($assessment);

        self::assertTrue($assessment['satisfied']['veteran'], 'expired ceiling no longer caps');
        self::assertSame('veteran', $assessment['target']);
    }

    public function testApprenticeCeilingCapsEverythingAboveIt(): void
    {
        [$engine] = $this->engine(
            ['rank_slug' => 'veteran', 'recovery_status' => '', 'recovery_deadline' => null],
            [$this->finding(5.0, $this->future(90), 'apprentice', $this->future(365))],
            $this->veteranEvidence()
        );

        $assessment = $engine->assess(self::USER);
        self::assertNotNull($assessment);

        self::assertFalse($assessment['satisfied']['journeyman']);
        self::assertFalse($assessment['satisfied']['veteran']);
        self::assertSame('apprentice', $assessment['target']);
    }

    // ── Ordinary grace vs immediate demotion ─────────────────────────────

    public function testCeilingStartsOrdinaryGraceOnEvaluate(): void
    {
        // Class-3 consequence: a serious ceiling on a veteran does NOT
        // demote instantly — evaluate() starts the ordinary 90-day
        // grace (§14.1.3) and emits the recovery notice.
        [$engine, $state] = $this->engine(
            ['rank_slug' => 'veteran', 'recovery_status' => '', 'recovery_deadline' => null],
            [$this->finding(25.0, $this->future(180), 'journeyman', $this->future(180))],
            $this->veteranEvidence()
        );

        $engine->evaluate(self::USER);

        [$userId, $rank, $status, $deadline] = $state->persisted[0];
        self::assertSame(self::USER, $userId);
        self::assertSame('veteran', $rank, 'rank retained through grace start');
        self::assertSame('grace', $status);
        self::assertNotNull($deadline);
        self::assertSame(['bcc_rank_recovery_started'], RankUnitWpFakes::firedHooks());
    }

    public function testEvaluateImmediateDemotesNowUnderApprenticeCeiling(): void
    {
        // Severe path: 40-point penalty + apprentice ceiling → demote
        // NOW, no grace, recovery cleared.
        [$engine, $state] = $this->engine(
            ['rank_slug' => 'veteran', 'recovery_status' => '', 'recovery_deadline' => null],
            [$this->finding(40.0, $this->future(365), 'apprentice', $this->future(365))],
            $this->veteranEvidence()
        );

        $engine->evaluateImmediate(self::USER);

        self::assertSame([self::USER, 'apprentice', '', null], $state->persisted[0]);
        self::assertSame(['bcc_rank_demoted'], RankUnitWpFakes::firedHooks());
        self::assertSame([self::USER, 'apprentice', 'veteran'], RankUnitWpFakes::$actions[0][1]);
    }

    public function testEvaluateImmediateLandsOnHighestSatisfiedUnderCeiling(): void
    {
        // Ceiling journeyman + 40 penalty: total 60 still satisfies
        // journeyman → the immediate demotion lands there, never an
        // assumed full drop.
        [$engine, $state] = $this->engine(
            ['rank_slug' => 'veteran', 'recovery_status' => '', 'recovery_deadline' => null],
            [$this->finding(40.0, $this->future(365), 'journeyman', $this->future(365))],
            $this->veteranEvidence()
        );

        $engine->evaluateImmediate(self::USER);

        self::assertSame([self::USER, 'journeyman', '', null], $state->persisted[0]);
        self::assertSame(['bcc_rank_demoted'], RankUnitWpFakes::firedHooks());
    }

    public function testEvaluateImmediateDelegatesWhenNoDemotionNeeded(): void
    {
        // No findings, journeyman member with veteran-grade evidence —
        // evaluateImmediate falls through to ordinary evaluate(),
        // which promotes (proving the delegation path).
        [$engine, $state] = $this->engine(
            ['rank_slug' => 'journeyman', 'recovery_status' => '', 'recovery_deadline' => null],
            [],
            $this->veteranEvidence()
        );

        $engine->evaluateImmediate(self::USER);

        self::assertSame([self::USER, 'veteran', '', null], $state->persisted[0]);
        self::assertSame(['bcc_rank_awarded'], RankUnitWpFakes::firedHooks());
    }

    // ── Recovery reminder marks ──────────────────────────────────────────

    public function testRecoveryReminderFiresAtThirtyDayMark(): void
    {
        $deadline = gmdate('Y-m-d H:i:s', time() + (30 * 86400) + 7200); // 30 whole days + 2h
        [$engine, $state] = $this->engine(
            ['rank_slug' => 'veteran', 'recovery_status' => 'grace', 'recovery_deadline' => $deadline],
            [$this->finding(25.0, $this->future(180), 'journeyman', $this->future(180))],
            $this->veteranEvidence()
        );

        $engine->evaluate(self::USER);

        self::assertSame([self::USER, 'veteran', 'grace', $deadline], $state->persisted[0]);
        self::assertSame(['bcc_rank_recovery_reminder'], RankUnitWpFakes::firedHooks());
        self::assertSame([self::USER, 30], RankUnitWpFakes::$actions[0][1]);
    }

    public function testRecoveryReminderSilentOffTheMarks(): void
    {
        $deadline = gmdate('Y-m-d H:i:s', time() + (8 * 86400) + 7200); // 8 whole days
        [$engine] = $this->engine(
            ['rank_slug' => 'veteran', 'recovery_status' => 'grace', 'recovery_deadline' => $deadline],
            [$this->finding(25.0, $this->future(180), 'journeyman', $this->future(180))],
            $this->veteranEvidence()
        );

        $engine->evaluate(self::USER);

        self::assertSame([], RankUnitWpFakes::firedHooks());
    }

    // ── Decay warning throttle ───────────────────────────────────────────

    public function testDecayWarningFiresOnceThenThrottles(): void
    {
        // Last login 400 days ago → one decay step active. Apprentice
        // with no evidence: no transition, just the warning.
        [$engine] = $this->engine(
            ['rank_slug' => 'apprentice', 'recovery_status' => '', 'recovery_deadline' => null],
            [],
            [],
            gmdate('Y-m-d', time() - (400 * 86400))
        );

        $engine->evaluate(self::USER);
        self::assertSame(['bcc_rank_decay_warning'], RankUnitWpFakes::firedHooks());
        self::assertArrayHasKey('bcc_rank_decay_warned_at', RankUnitWpFakes::$userMeta[self::USER]);

        // Second evaluate inside the 30-day gate — no second warning.
        $engine->evaluate(self::USER);
        self::assertSame(['bcc_rank_decay_warning'], RankUnitWpFakes::firedHooks(), 'usermeta stamp throttles');

        // Stamp aged past the gate — warns again.
        RankUnitWpFakes::$userMeta[self::USER]['bcc_rank_decay_warned_at'] = (string) (time() - (31 * 86400));
        $engine->evaluate(self::USER);
        self::assertSame(
            ['bcc_rank_decay_warning', 'bcc_rank_decay_warning'],
            RankUnitWpFakes::firedHooks()
        );
    }
}
