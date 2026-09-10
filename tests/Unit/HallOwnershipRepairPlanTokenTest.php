<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\CLI\HallOwnershipRepairCommand;
use BCC\Trust\Onchain\Repair\HallOwnershipRepairService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The planner and the token generator, WIRED TOGETHER.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────
 * The token tests used to build plan entries by hand:
 *
 *     private static function planOf(array ...$rows) { … }
 *
 * That helper proved `confirmationToken()` is a good hash of the array it is
 * given. It could not, and did not, prove anything about the array the
 * planner actually produces — so when `planOne()` returned `row_id => 0` for
 * every Hall (PHP array union keeps the LEFT operand), the "token bound to
 * planned rows" test still passed, and a live staging dry run printed `row 0`
 * against all 21 Halls with a token that bound only to (group, chain).
 *
 * A pure function verified in isolation is not evidence anything feeds it
 * correctly. Every case here therefore runs the REAL planner over a seeded
 * ledger and hands its output to the REAL token generator.
 *
 * Numeric ids only throughout — the plan is asserted to carry no profile
 * field of any kind.
 */
#[CoversClass(HallOwnershipRepairService::class)]
#[CoversClass(HallOwnershipRepairCommand::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HallOwnershipRepairPlanTokenTest extends TestCase
{
    private const OWNER = 1;
    private const HALL  = 6703;
    private const CHAIN = 10;
    private const ROW   = 9000;   // the gm_id seedBrokenHall() hands out first

    private function boot(): void
    {
        require_once __DIR__ . '/../Stubs/hall-ownership-repair-stubs.php';

        \HallRepairTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Core\Security\TransactionManager::reset();
    }

    /** @return list<array<string, mixed>> */
    private function realPlan(): array
    {
        return (new HallOwnershipRepairService())->plan(self::OWNER);
    }

    private function realToken(string $env = 'staging'): string
    {
        return HallOwnershipRepairCommand::confirmationToken($env, $this->realPlan());
    }

    private static function digestOf(string $token): string
    {
        return substr($token, strrpos($token, '-') + 1);
    }

    // ── THE REGRESSION ───────────────────────────────────────────────────

    /**
     * ⚠ THE `row 0` BUG. Reverting `planOne()` to `$base + [...]` fails here.
     */
    public function testAnEligibleHallCarriesItsRealLedgerRowId(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);

        $entry = $this->realPlan()[0];

        self::assertSame(HallOwnershipRepairService::RESULT_WOULD_REPAIR, $entry['result']);
        self::assertGreaterThan(0, $entry['row_id'], 'row_id must never be 0 on an eligible Hall');
        self::assertSame(self::ROW, $entry['row_id'], 'and it must be the orphan owner row, exactly');
    }

    public function testEveryEligibleHallInAMultiHallPlanCarriesItsOwnRowId(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \HallRepairTestState::seedBrokenHall(6704, 8);
        \HallRepairTestState::seedBrokenHall(6705, 14);

        $rowIds = array_map(static fn(array $e): int => (int) $e['row_id'], $this->realPlan());

        self::assertSame([9000, 9001, 9002], $rowIds, 'distinct, non-zero, one per Hall');
        self::assertCount(3, array_unique($rowIds));
    }

    // ── THE PLANNER → TOKEN WIRING ───────────────────────────────────────

    /**
     * The property the hand-built helper could not express: move the LEDGER
     * and the token must move.
     */
    public function testChangingTheLedgerRowIdChangesTheToken(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        $a = $this->realToken();

        $this->boot();
        \HallRepairTestState::$nextGmId = 7777;          // same Hall, different row
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        $b = $this->realToken();

        self::assertNotSame($a, $b);
        self::assertNotSame(self::digestOf($a), self::digestOf($b));
    }

    public function testChangingTheHallChangesTheToken(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        $a = $this->realToken();

        $this->boot();
        \HallRepairTestState::seedBrokenHall(6704, self::CHAIN);
        $b = $this->realToken();

        self::assertNotSame(self::digestOf($a), self::digestOf($b));
    }

    public function testChangingTheChainChangesTheToken(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        $a = $this->realToken();

        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, 99);
        $b = $this->realToken();

        self::assertNotSame(self::digestOf($a), self::digestOf($b));
    }

    /**
     * The proposed owner is resolved, not fixed — suspend the first
     * administrator and the plan proposes the next one, so the token moves.
     */
    public function testChangingTheProposedOwnerChangesTheToken(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        $planA = $this->realPlan();
        $a     = HallOwnershipRepairCommand::confirmationToken('staging', $planA);

        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \HallRepairTestState::$suspended[1] = '1';
        $owner = HallOwnershipRepairService::resolveIntendedOwner();
        self::assertSame(2, $owner, 'precondition: the resolver moves to the next active admin');

        $planB = (new HallOwnershipRepairService())->plan($owner);
        $b     = HallOwnershipRepairCommand::confirmationToken('staging', $planB);

        self::assertSame(self::OWNER, $planA[0]['proposed_owner_id']);
        self::assertSame(2, $planB[0]['proposed_owner_id']);
        self::assertNotSame(self::digestOf($a), self::digestOf($b));
    }

    public function testChangingTheEnvironmentChangesTheDigestNotJustTheLabel(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        $plan = $this->realPlan();

        $staging    = HallOwnershipRepairCommand::confirmationToken('staging', $plan);
        $production = HallOwnershipRepairCommand::confirmationToken('production', $plan);

        self::assertStringContainsString('STAGING', $staging);
        self::assertStringContainsString('PRODUCTION', $production);
        // The DIGEST, not the human-readable prefix — otherwise dropping the
        // environment from the hash would still pass.
        self::assertNotSame(self::digestOf($staging), self::digestOf($production));
    }

    /**
     * `current_owner_id` and `status` are invariants of the planner — it can
     * only ever produce `0` and `member_owner`. They are still part of what
     * the token authorises, so the sensitivity is asserted by perturbing a
     * REAL plan rather than by pretending the planner could emit something
     * else.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    public static function invariantFieldProvider(): array
    {
        return [
            'current owner' => ['current_owner_id', 4],
            'status'        => ['status', 'member_manager'],
        ];
    }

    #[DataProvider('invariantFieldProvider')]
    public function testTheTokenIsSensitiveToEveryCanonicalField(string $field, mixed $other): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);

        $plan = $this->realPlan();
        $base = HallOwnershipRepairCommand::confirmationToken('staging', $plan);

        self::assertNotSame($other, $plan[0][$field], 'precondition: the perturbation must differ');
        $plan[0][$field] = $other;

        self::assertNotSame(
            self::digestOf($base),
            self::digestOf(HallOwnershipRepairCommand::confirmationToken('staging', $plan)),
            "the token must change when {$field} changes"
        );
    }

    // ── Determinism ──────────────────────────────────────────────────────

    public function testTheSamePlanYieldsTheSameToken(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \HallRepairTestState::seedBrokenHall(6704, 8);

        self::assertSame($this->realToken(), $this->realToken());
    }

    /**
     * Row order must not reach the digest. The ledger is read in whatever
     * order the database returns, so a token that depended on it would
     * change for no reason an operator could see.
     */
    public function testRowOrderDoesNotChangeTheToken(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \HallRepairTestState::seedBrokenHall(6704, 8);
        \HallRepairTestState::seedBrokenHall(6705, 14);
        $a = $this->realToken();

        // Same Halls, same rows, reversed in the underlying store.
        \HallRepairTestState::$rows = array_reverse(\HallRepairTestState::$rows, true);
        $b = $this->realToken();

        self::assertSame($a, $b);
    }

    /**
     * Sorting must be numeric, not lexical: a string sort puts "10" before
     * "9", so two plans differing only in id magnitude could otherwise
     * canonicalise inconsistently.
     */
    public function testSortingIsNumericNotLexical(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(9, 1);
        \HallRepairTestState::seedBrokenHall(10, 2);
        $a = $this->realToken();

        \HallRepairTestState::$rows = array_reverse(\HallRepairTestState::$rows, true);
        \HallRepairTestState::$meta = array_reverse(\HallRepairTestState::$meta, true);
        $b = $this->realToken();

        self::assertSame($a, $b);
    }

    /**
     * ⚠ THE SORT, TESTED WHERE IT ACTUALLY MATTERS.
     *
     * `listAllHallIds()` already returns ids in order, so reversing the
     * underlying store does not reorder the PLAN — an earlier version of
     * this test reversed the store and proved nothing, and the mutation
     * control for the sort survived. The plan array itself has to be
     * shuffled, because that is the input the token generator receives.
     */
    public function testThePlanOrderDoesNotChangeTheToken(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \HallRepairTestState::seedBrokenHall(6704, 8);
        \HallRepairTestState::seedBrokenHall(6705, 14);

        $plan = $this->realPlan();
        self::assertCount(3, $plan);

        $forward  = HallOwnershipRepairCommand::confirmationToken('staging', $plan);
        $reversed = HallOwnershipRepairCommand::confirmationToken('staging', array_reverse($plan));

        self::assertSame($forward, $reversed, 'the digest must not depend on plan order');
    }

    /**
     * And the sort must be NUMERIC. A lexical sort orders group 10 before
     * group 9, so two orderings of the same plan would canonicalise
     * differently once ids cross a digit boundary.
     */
    public function testTheSortIsNumericAcrossADigitBoundary(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(9, 1);
        \HallRepairTestState::seedBrokenHall(10, 2);
        \HallRepairTestState::seedBrokenHall(100, 3);

        $plan = $this->realPlan();
        self::assertCount(3, $plan);

        $a = HallOwnershipRepairCommand::confirmationToken('staging', $plan);
        $b = HallOwnershipRepairCommand::confirmationToken('staging', array_reverse($plan));

        self::assertSame($a, $b);
    }

    /**
     * ⚠ THE SORT MUST BE A TOTAL ORDER, NOT A PARTIAL ONE.
     *
     * Sorting on `group_id` alone looks equivalent while every Hall has a
     * distinct id — and it is, right up until two entries share one. PHP's
     * sort is stable, so equal keys keep their INPUT order, and the digest
     * silently becomes order-dependent again. The tuple's second element
     * (`row_id`) is what prevents that, so it needs a case where the first
     * element ties.
     *
     * Two entries with the same group id are not something the planner
     * produces today; the point is that the canonical form must not depend
     * on that remaining true.
     */
    public function testTheCanonicalOrderIsTotalEvenWhenGroupIdsTie(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);

        $plan = $this->realPlan();
        $twin = $plan[0];
        $twin['row_id'] = (int) $plan[0]['row_id'] + 1;   // same group, different row

        $forward  = HallOwnershipRepairCommand::confirmationToken('staging', [$plan[0], $twin]);
        $reversed = HallOwnershipRepairCommand::confirmationToken('staging', [$twin, $plan[0]]);

        self::assertSame($forward, $reversed, 'row_id must break the tie, or order leaks into the digest');
    }

    public function testRefusedHallsDoNotContributeToTheToken(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        $clean = $this->realToken();

        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \HallRepairTestState::seedBrokenHall(6704, 8);
        \HallRepairTestState::$meta[6704]['peepso_group_privacy'] = ['1'];   // refused
        $noisy = $this->realToken();

        self::assertSame($clean, $noisy, 'an unrelated refusal must not invalidate a correct token');
    }

    // ── Plan integrity fails closed ──────────────────────────────────────

    /** @return list<array{0: string, 1: mixed, 2: string}> */
    public static function badRowIdProvider(): array
    {
        return [
            'zero'        => ['zero', 0, 'non-positive'],
            'negative'    => ['negative', -5, 'non-positive'],
            'string'      => ['string', '9000', 'non-integer'],
            'null'        => ['null', null, 'non-integer'],
        ];
    }

    #[DataProvider('badRowIdProvider')]
    public function testAnUnusableRowIdRefusesTheWholePlan(string $label, mixed $bad, string $expect): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);

        $plan = $this->realPlan();
        $plan[0]['row_id'] = $bad;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/plan integrity: .*' . $expect . '/');

        (new HallOwnershipRepairService())->assertPlanIntegrity($plan);
    }

    public function testAMissingRowIdRefusesTheWholePlan(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);

        $plan = $this->realPlan();
        unset($plan[0]['row_id']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/plan integrity: .*has no row_id/');

        (new HallOwnershipRepairService())->assertPlanIntegrity($plan);
    }

    /**
     * Two Halls claiming the same ledger row means the planner does not know
     * which row it is fixing. That is not a fact about one Hall the others
     * can be trusted around.
     */
    public function testTwoHallsClaimingOneRowRefusesTheWholePlan(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \HallRepairTestState::seedBrokenHall(6704, 8);

        $plan = $this->realPlan();
        $plan[1]['row_id'] = $plan[0]['row_id'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/plan integrity: row_id \d+ is claimed by groups/');

        (new HallOwnershipRepairService())->assertPlanIntegrity($plan);
    }

    public function testARefusedEntryWithRowIdZeroIsNotAnIntegrityFailure(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \HallRepairTestState::$meta[self::HALL]['peepso_group_privacy'] = ['1'];

        $plan = $this->realPlan();
        self::assertSame(0, $plan[0]['row_id']);

        // Refusals legitimately carry 0; only would_repair entries are checked.
        (new HallOwnershipRepairService())->assertPlanIntegrity($plan);
        self::assertTrue(true);
    }

    /**
     * ⚠ THE GATE IS ON `run()`, INCLUDING THE DRY RUN.
     *
     * Injecting a corrupt plan proves `assertPlanIntegrity()` works; it does
     * not prove anything calls it. Here the PLANNER genuinely produces two
     * Halls whose orphan owner rows share a `gm_id`, so `run(false, …)` —
     * the path the dry run takes — must refuse before printing or
     * tokenising anything.
     */
    public function testTheDryRunItselfRefusesAnIncoherentPlan(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \HallRepairTestState::seedBrokenHall(6704, 8);

        // Same ledger row id claimed by both Halls.
        \HallRepairTestState::$rows[6704][0]['gm_id'] = \HallRepairTestState::$rows[self::HALL][0]['gm_id'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/plan integrity: row_id \d+ is claimed by groups/');

        (new HallOwnershipRepairService())->run(false, self::OWNER, 4, 'testrun', sys_get_temp_dir());
    }

    public function testAHealthyPlanPassesIntegrity(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \HallRepairTestState::seedBrokenHall(6704, 8);

        (new HallOwnershipRepairService())->assertPlanIntegrity($this->realPlan());
        self::assertTrue(true);
    }

    // ── The fields the dry run has to show ───────────────────────────────

    public function testThePlanCarriesEveryFieldTheDryRunReports(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN, [49]);

        $e = $this->realPlan()[0];

        self::assertSame(self::HALL, $e['group_id']);
        self::assertSame(self::CHAIN, $e['chain_id']);
        self::assertSame('chain' . self::CHAIN, $e['chain_slug']);
        self::assertSame(self::ROW, $e['row_id']);
        self::assertSame(0, $e['current_owner_id']);
        self::assertSame(self::OWNER, $e['proposed_owner_id']);
        self::assertSame('member_owner', $e['status']);
        self::assertSame(HallOwnershipRepairService::RESULT_WOULD_REPAIR, $e['result']);
    }

    /**
     * The proposed count is PREDICTED with PeepSo's own rule, one row
     * substituted — never `current + 1`.
     */
    public function testProposedCountUsesThePeepSoCountingRule(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN, [49]);

        $e = $this->realPlan()[0];

        // user 0 is not countable, user 49 is → current 1.
        self::assertSame(1, $e['members_count_current']);
        // After the repoint the owner row names user 1, who IS countable → 2.
        self::assertSame(2, $e['members_count_proposed']);
        self::assertSame(
            \BCC\Trust\Onchain\Repositories\HallOwnershipRepairRepository::computePeepSoMemberCountAsIf(
                self::HALL,
                (int) $e['row_id'],
                (int) $e['proposed_owner_id']
            ),
            $e['members_count_proposed']
        );
    }

    /**
     * ⚠ WHICH METHOD THE PLANNER CALLS, not merely what number it lands on.
     *
     * Inside the eligible set, `computePeepSoMemberCount() + 1` and the real
     * prediction are arithmetically EQUAL — the guards make sure of it — so
     * no seeded data can tell the two apart, and a mutation swapping one for
     * the other survives every count assertion. The seam forces the AsIf
     * method to answer with a sentinel: only a planner that actually calls
     * it reports that number.
     *
     * This matters because the equality is a property of TODAY'S guards. If
     * PeepSo's exclusion rule ever changes, the shortcut drifts silently and
     * the dry run starts promising a number the apply will not produce.
     */
    public function testTheProposedCountComesFromThePeepSoRuleNotArithmetic(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN, [49]);
        \HallRepairTestState::$forcedAsIfCount = 41;

        $e = $this->realPlan()[0];

        self::assertSame(1, $e['members_count_current'], 'the current count is measured normally');
        self::assertSame(
            41,
            $e['members_count_proposed'],
            'the planner must ask computePeepSoMemberCountAsIf(), not compute current + 1'
        );
    }

    /**
     * An owner PeepSo would not count must not inflate the prediction — the
     * eligibility guard refuses that Hall, but the arithmetic shortcut
     * (`current + 1`) would have reported a rise regardless.
     */
    public function testAnUncountableOwnerDoesNotInflateThePrediction(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN, [49]);
        \HallRepairTestState::$countable = [49];   // the owner is not countable

        self::assertSame(
            1,
            \BCC\Trust\Onchain\Repositories\HallOwnershipRepairRepository::computePeepSoMemberCountAsIf(
                self::HALL,
                self::ROW,
                self::OWNER
            ),
            'substituting an uncountable user changes nothing'
        );
    }

    public function testThePlanCarriesNoProfileInformation(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN, [2, 49]);

        $encoded = (string) json_encode($this->realPlan());

        foreach (['@', 'user_login', 'display_name', 'user_email', 'user_nicename', 'user_pass'] as $needle) {
            self::assertStringNotContainsString($needle, $encoded);
        }
    }
}
