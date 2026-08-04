<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Rank\Repositories\FindingDecisionsRepository;
use BCC\Trust\Rank\Repositories\FindingsRepository;
use BCC\Trust\Rank\Services\FindingsService;
use BCC\Trust\Rank\Services\RankPromotionEngine;
use BCC\Trust\Rank\Support\RankScoringConfig;
use BCC\Trust\Tests\Stubs\RankUnitWpFakes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Stubs/rank-unit-stubs.php';

/**
 * §15 issuance + reconsideration state machine (Rank Phase 8), against
 * the REAL shipped config (Phase 0 doctrine):
 *
 *   - issue() snapshots the §15.3 class (penalty, ceiling + expiry,
 *     penalty expiry) and appends the 'issued' decision row carrying
 *     the original penalty forever (§15.5);
 *   - severe routes through evaluateImmediate() (no grace); lesser
 *     classes through ordinary evaluate();
 *   - §15.1: invalid severity/source/reason never write anything;
 *   - reduce validates a strictly-lower positive penalty and re-runs
 *     the engine; reverse flips status once (atomic claim — a second
 *     reverse fails) and re-runs the engine (deterministic
 *     restoration); uphold/remand touch appeal_status only and do NOT
 *     re-run the engine;
 *   - the decision history is append-only by construction — the
 *     decisions repository exposes no update/delete surface at all.
 */
final class FindingsServiceTest extends TestCase
{
    private const SUBJECT = 7;
    private const ADMIN   = 3;

    protected function setUp(): void
    {
        RankUnitWpFakes::reset();
    }

    /**
     * @return array{0: FindingsService, 1: object, 2: object, 3: object}
     *         service, findings repo fake, decisions repo fake, engine fake
     */
    private function service(): array
    {
        $findings = new class extends FindingsRepository {
            /** @var array<int, object> */
            public array $rows = [];
            public int $nextId = 1;
            public function __construct()
            {
            }
            public function create(array $data): int
            {
                $id = $this->nextId++;
                $this->rows[$id] = (object) array_merge($data, [
                    'id'              => (string) $id,
                    'status'          => 'active',
                    'appeal_status'   => '',
                    'reversed_at'     => null,
                    'reversal_reason' => null,
                ]);
                return $id;
            }
            public function getById(int $findingId): ?object
            {
                return $this->rows[$findingId] ?? null;
            }
            public function applyReconsideration(
                int $findingId,
                int $subjectUserId,
                string $appealStatus,
                ?float $newPenalty,
                bool $reverse,
                ?string $reversalReason
            ): bool {
                unset($subjectUserId);
                $row = $this->rows[$findingId] ?? null;
                if ($row === null) {
                    return false;
                }
                // Mirror the real repository's atomic status claim.
                if (($reverse || $newPenalty !== null) && $row->status !== 'active') {
                    return false;
                }
                $row->appeal_status = $appealStatus;
                if ($newPenalty !== null) {
                    $row->score_penalty = $newPenalty;
                }
                if ($reverse) {
                    $row->status          = 'reversed';
                    $row->reversed_at     = gmdate('Y-m-d H:i:s');
                    $row->reversal_reason = $reversalReason;
                }
                return true;
            }
        };

        $decisions = new class extends FindingDecisionsRepository {
            /** @var list<array{0: int, 1: string, 2: int, 3: string, 4: float|null}> */
            public array $appended = [];
            public function __construct()
            {
            }
            public function append(
                int $findingId,
                string $decision,
                int $decidedBy,
                string $reason,
                ?float $penaltyAfter
            ): int {
                $this->appended[] = [$findingId, $decision, $decidedBy, $reason, $penaltyAfter];
                return count($this->appended);
            }
        };

        $engine = new class extends RankPromotionEngine {
            /** @var list<int> */
            public array $evaluated = [];
            /** @var list<int> */
            public array $immediate = [];
            public function __construct()
            {
            }
            public function evaluate(int $userId): void
            {
                $this->evaluated[] = $userId;
            }
            public function evaluateImmediate(int $userId): void
            {
                $this->immediate[] = $userId;
            }
        };

        $service = new FindingsService(
            $findings,
            $decisions,
            $engine,
            RankScoringConfig::fromDefaultFile()
        );

        return [$service, $findings, $decisions, $engine];
    }

    // ── Issuance ─────────────────────────────────────────────────────────

    public function testIssueSnapshotsSeriousClassAndRunsOrdinaryEvaluate(): void
    {
        [$service, $findings, $decisions, $engine] = $this->service();

        $id = $service->issue(self::SUBJECT, 'vote_manipulation', 'serious', 'dispute #12', 'dispute', self::ADMIN, 'coordinated ring');

        self::assertSame(1, $id);
        $row = $findings->rows[1];
        self::assertSame(25.0, $row->score_penalty);
        self::assertSame('journeyman', $row->ceiling_rank_slug);
        self::assertSame('serious', $row->severity);
        self::assertSame('dispute', $row->source);
        self::assertSame('dispute #12', $row->evidence_refs);

        // Snapshot expiries ≈ now + the class windows (180d / 180d).
        $ceiling = strtotime((string) $row->ceiling_expires_at . ' UTC');
        $penalty = strtotime((string) $row->penalty_expires_at . ' UTC');
        self::assertNotFalse($ceiling);
        self::assertNotFalse($penalty);
        self::assertEqualsWithDelta(time() + 180 * 86400, $ceiling, 60.0);
        self::assertEqualsWithDelta(time() + 180 * 86400, $penalty, 60.0);

        // §15.5 — 'issued' decision row carries the original penalty.
        self::assertSame([[1, 'issued', self::ADMIN, 'coordinated ring', 25.0]], $decisions->appended);

        // Serious is NOT immediate — ordinary evaluate (grace machinery).
        self::assertSame([self::SUBJECT], $engine->evaluated);
        self::assertSame([], $engine->immediate);

        self::assertSame(['bcc_rank_finding_issued'], RankUnitWpFakes::firedHooks());
        self::assertSame([self::SUBJECT, 1, 'serious'], RankUnitWpFakes::$actions[0][1]);
    }

    public function testIssueSevereDemotesImmediately(): void
    {
        [$service, $findings, , $engine] = $this->service();

        $id = $service->issue(self::SUBJECT, 'fraud', 'severe', null, 'admin', self::ADMIN, 'confirmed fraud');

        self::assertSame(1, $id);
        self::assertSame(40.0, $findings->rows[1]->score_penalty);
        self::assertSame('apprentice', $findings->rows[1]->ceiling_rank_slug);

        self::assertSame([], $engine->evaluated);
        self::assertSame([self::SUBJECT], $engine->immediate, 'severe skips the grace — evaluateImmediate');
    }

    public function testIssueMinorCarriesNoCeiling(): void
    {
        [$service, $findings] = $this->service();

        $service->issue(self::SUBJECT, 'spam', 'minor', null, 'admin', self::ADMIN, 'spam wave');

        self::assertSame(5.0, $findings->rows[1]->score_penalty);
        self::assertNull($findings->rows[1]->ceiling_rank_slug);
        self::assertNull($findings->rows[1]->ceiling_expires_at);
    }

    public function testIssueRejectsInvalidInputsWithoutWriting(): void
    {
        [$service, $findings, $decisions, $engine] = $this->service();

        self::assertSame(0, $service->issue(self::SUBJECT, 'x', 'catastrophic', null, 'admin', self::ADMIN, 'r'), 'unknown severity');
        self::assertSame(0, $service->issue(self::SUBJECT, 'x', 'minor', null, 'automated', self::ADMIN, 'r'), 'unknown source');
        self::assertSame(0, $service->issue(self::SUBJECT, 'x', 'minor', null, 'admin', self::ADMIN, '  '), 'empty reason');
        self::assertSame(0, $service->issue(self::SUBJECT, '  ', 'minor', null, 'admin', self::ADMIN, 'r'), 'empty type');
        self::assertSame(0, $service->issue(0, 'x', 'minor', null, 'admin', self::ADMIN, 'r'), 'no subject');

        self::assertSame([], $findings->rows);
        self::assertSame([], $decisions->appended);
        self::assertSame([], $engine->evaluated);
        self::assertSame([], $engine->immediate);
        self::assertSame([], RankUnitWpFakes::firedHooks());
    }

    // ── Reconsideration ──────────────────────────────────────────────────

    public function testReduceRequiresStrictlyLowerPositivePenalty(): void
    {
        [$service, $findings, $decisions, $engine] = $this->service();
        $service->issue(self::SUBJECT, 'spam', 'serious', null, 'admin', self::ADMIN, 'r');

        self::assertFalse($service->reconsider(1, self::ADMIN, 'reduced', 'same', 25.0), 'equal penalty rejected');
        self::assertFalse($service->reconsider(1, self::ADMIN, 'reduced', 'higher', 30.0), 'higher penalty rejected');
        self::assertFalse($service->reconsider(1, self::ADMIN, 'reduced', 'missing', null), 'missing penalty rejected');
        self::assertFalse($service->reconsider(1, self::ADMIN, 'reduced', 'zero', 0.0), 'zero rejected — use reversed');
        self::assertSame(25.0, $findings->rows[1]->score_penalty, 'rejections never mutate');
        self::assertCount(1, $decisions->appended, 'rejections never append');

        self::assertTrue($service->reconsider(1, self::ADMIN, 'reduced', 'proportionate', 10.0));
        self::assertSame(10.0, $findings->rows[1]->score_penalty);
        self::assertSame('reduced', $findings->rows[1]->appeal_status);
        self::assertSame([1, 'reduced', self::ADMIN, 'proportionate', 10.0], $decisions->appended[1]);

        // Original snapshot survives in the 'issued' row (§15.5).
        self::assertSame(25.0, $decisions->appended[0][4]);

        // Re-evaluate after the reduction (issuance + reduction).
        self::assertSame([self::SUBJECT, self::SUBJECT], $engine->evaluated);
        self::assertContains('bcc_rank_finding_reconsidered', RankUnitWpFakes::firedHooks());
    }

    public function testReverseFlipsStatusOnceAndRestoresViaReEvaluate(): void
    {
        [$service, $findings, $decisions, $engine] = $this->service();
        $service->issue(self::SUBJECT, 'spam', 'moderate', null, 'admin', self::ADMIN, 'r');

        self::assertTrue($service->reconsider(1, self::ADMIN, 'reversed', 'false positive'));
        $row = $findings->rows[1];
        self::assertSame('reversed', $row->status);
        self::assertSame('reversed', $row->appeal_status);
        self::assertNotNull($row->reversed_at);
        self::assertSame('false positive', $row->reversal_reason);

        // Deterministic restoration = engine re-evaluate.
        self::assertSame([self::SUBJECT, self::SUBJECT], $engine->evaluated);

        // Atomic claim: a second reverse (or a reduce) fails cleanly and
        // appends nothing further.
        $countAfterReverse = count($decisions->appended);
        self::assertFalse($service->reconsider(1, self::ADMIN, 'reversed', 'again'));
        self::assertFalse($service->reconsider(1, self::ADMIN, 'reduced', 'late', 5.0));
        self::assertCount($countAfterReverse, $decisions->appended);
    }

    public function testUpholdAndRemandTouchAppealStatusOnly(): void
    {
        [$service, $findings, $decisions, $engine] = $this->service();
        $service->issue(self::SUBJECT, 'spam', 'moderate', null, 'admin', self::ADMIN, 'r');

        self::assertTrue($service->reconsider(1, self::ADMIN, 'upheld', 'stands'));
        self::assertSame('active', $findings->rows[1]->status);
        self::assertSame(15.0, $findings->rows[1]->score_penalty);
        self::assertSame('upheld', $findings->rows[1]->appeal_status);

        self::assertTrue($service->reconsider(1, self::ADMIN, 'remanded', 'look again'));
        self::assertSame('remanded', $findings->rows[1]->appeal_status);

        // No re-evaluate beyond issuance — nothing score-bearing moved.
        self::assertSame([self::SUBJECT], $engine->evaluated);

        // Every reconsideration appended (issued + upheld + remanded).
        self::assertSame(
            ['issued', 'upheld', 'remanded'],
            array_map(static fn (array $d): string => $d[1], $decisions->appended)
        );
    }

    public function testReconsiderRejectsUnknownDecisionAndMissingFinding(): void
    {
        [$service, , $decisions, $engine] = $this->service();

        self::assertFalse($service->reconsider(99, self::ADMIN, 'upheld', 'r'), 'missing finding');
        self::assertFalse($service->reconsider(1, self::ADMIN, 'issued', 'r'), "'issued' reserved for issuance");
        self::assertFalse($service->reconsider(1, self::ADMIN, 'upheld', '  '), 'empty reason');
        self::assertSame([], $decisions->appended);
        self::assertSame([], $engine->evaluated);
    }

    // ── Append-only by construction ──────────────────────────────────────

    public function testDecisionsRepositoryExposesNoMutationSurface(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(FindingDecisionsRepository::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        sort($methods);

        self::assertSame(['__construct', 'append', 'listForFinding'], $methods);
        foreach ($methods as $method) {
            self::assertDoesNotMatchRegularExpression(
                '/update|delete|edit|remove|truncate/i',
                $method,
                '§15.5: the decision history must stay append-only'
            );
        }
    }
}
