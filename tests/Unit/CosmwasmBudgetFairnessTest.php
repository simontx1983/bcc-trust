<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\CosmwasmPassReport;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A classification backlog must not starve emission.
 *
 * ── THE MEASURED DEFECT ─────────────────────────────────────────────────
 * `dailyChainStep()` runs four stages in a fixed order against ONE shared
 * 50-request budget, and nothing stopped the first stage spending all of
 * it. On Dungeon it reliably did: a `confirmed_cw721` family and an
 * already-emittable contract sat untouched behind a 64-family queue, pass
 * after pass, while `findEnumerable()`, the contract selector and
 * `findEmittable()` all returned rows the whole time. Every stage was
 * individually correct and the pipeline produced nothing.
 *
 * ── WHY A STAGE-BOUNDARY GUARD IS NOT ENOUGH ────────────────────────────
 * `classifyFamily()` costs up to 10 requests across four separate
 * `canSpend()` calls, so a stage that was affordable when it started can
 * still overshoot from the inside. The reserve therefore lives on the
 * BUDGET and is read by `canSpend()`/`exhausted()`, which makes the floor
 * hold at the granularity of one request.
 *
 * ── THE FIXTURE IS ROUTED, NOT QUEUED ───────────────────────────────────
 * A FIFO cannot express this test. The whole point is that stage c1 stops
 * early, so the number of responses it consumes is the thing under test —
 * with a queue, every later stage would read someone else's reply and the
 * test would be measuring the fixture. The responder answers by URL and is
 * STRICT: an unrecognised request fails the test rather than returning a
 * generic success.
 */
#[CoversClass(CosmwasmDiscoveryWorker::class)]
#[CoversClass(CosmwasmTickBudget::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmwasmBudgetFairnessTest extends TestCase
{
    private const CHAIN  = 42;
    private const REST   = 'https://lcd.example';
    private const BUDGET = 50;

    /** The confirmed family whose sample contract is already emittable. */
    private const CONFIRMED_CODE = 105;

    /** Requests seen, in order, as "operation" strings. */
    private array $seen = [];

    /** Operations the responder was told to expect but never received. */
    private array $required = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-cli-stubs.php';

        ApiRetry::reset();
        CosmwasmCodeFamilyRepository::reset();
        CosmwasmContractRepository::reset();
        CollectionRepository::reset();
        ChainCheckpointRepository::reset();
        ChainRepository::reset();
        NftSpamContractRepository::reset();
        OnchainCircuitBreaker::reset();
        \BCC\Core\DB\AdvisoryLock::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestObjectCache::reset();
        \BccTestOptionStore::reset();

        $this->seen     = [];
        $this->required = [];
    }

    // ── addresses ───────────────────────────────────────────────────────

    /** The confirmed, already-emittable sample. */
    private function sample(): string
    {
        return 'cosmos1' . str_repeat('e', 59);
    }

    /** A sibling under the confirmed family, awaiting classification. */
    private function sibling(int $i): string
    {
        return 'cosmos1s' . str_pad((string) $i, 58, '0', STR_PAD_LEFT);
    }

    /** A throwaway contract under an ordinary backlog family. */
    private function throwaway(int $codeId, int $i): string
    {
        return 'cosmos1t' . str_pad($codeId . 'x' . $i, 58, '0', STR_PAD_LEFT);
    }

    // ── the STRICT routed responder ─────────────────────────────────────

    /**
     * Classify a URL into a production call shape.
     *
     * Returns null for anything the production code is not expected to
     * ask for, which the responder turns into a test failure.
     */
    private function operationFor(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($path === '/cosmwasm/wasm/v1/code') {
            return 'code_listing';
        }
        if (preg_match('#^/cosmwasm/wasm/v1/code/(\d+)/contracts$#', $path, $m)) {
            return 'contract_listing:' . $m[1];
        }
        if (preg_match('#^/cosmwasm/wasm/v1/contract/([^/]+)/smart/(.+)$#', $path, $m)) {
            $decoded = base64_decode(strtr(rawurldecode($m[2]), '-_', '+/'), false);
            $q       = is_string($decoded) ? json_decode($decoded, true) : null;
            $variant = is_array($q) ? (string) (array_key_first($q) ?? '?') : '?';

            return 'smart:' . $variant . ':' . $m[1];
        }

        return null;
    }

    /**
     * Install the responder.
     *
     * @param int $contractsPerFamily addresses an ordinary family page returns
     */
    private function respond(int $contractsPerFamily = 1): void
    {
        $sample = $this->sample();
        $test   = $this;

        ApiRetry::$responder = static function (string $url) use ($test, $sample, $contractsPerFamily) {
            $op = $test->operationFor($url);
            if ($op === null) {
                TestCase::fail('Unexpected request shape the production code should never make: ' . $url);
            }
            $test->seen[] = $op;
            unset($test->required[$op]);

            $json = static fn(array $p, int $c = 200): array => ['code' => $c, 'body' => (string) json_encode($p)];

            if ($op === 'code_listing') {
                // Nothing new on chain: stage (a) costs exactly one request.
                return $json(['code_infos' => [['code_id' => '900', 'data_hash' => 'AA']], 'pagination' => ['next_key' => null]]);
            }

            if (str_starts_with($op, 'contract_listing:')) {
                $codeId = (int) substr($op, strlen('contract_listing:'));
                if ($codeId === self::CONFIRMED_CODE) {
                    return $json(['contracts' => [$sample], 'pagination' => []]);
                }
                $out = [];
                for ($i = 0; $i < $contractsPerFamily; $i++) {
                    $out[] = $test->throwaway($codeId, $i);
                }
                return $json(['contracts' => $out, 'pagination' => []]);
            }

            // Smart queries. The confirmed sample answers like a real
            // CW-721; everything else gives the deterministic HTTP 500
            // contract-level rejection a cosmos LCD really sends.
            [, $variant, $addr] = explode(':', $op, 3);

            // `cosmos1a…` addresses are the deny-test candidates. They MUST
            // answer contract_info: emission skips a null-info contract, so
            // without a real answer a bypassed deny filter would look like a
            // withheld one and the mutation would survive.
            if ($addr === $sample || str_starts_with($addr, 'cosmos1a')) {
                if ($variant === 'num_tokens') {
                    return $json(['data' => ['count' => 7]]);
                }
                if ($variant === 'contract_info') {
                    return $json(['data' => ['name' => 'Fixture Collection', 'symbol' => 'FIX']]);
                }
            }

            return $json(['code' => 2, 'message' => 'Error parsing into type x::QueryMsg: unknown variant `' . $variant . '`'], 500);
        };
    }

    /** Every operation the test insists must actually be requested. */
    private function require(string ...$ops): void
    {
        foreach ($ops as $op) {
            $this->required[$op] = true;
        }
    }

    private function assertAllRequiredRequestsMade(): void
    {
        self::assertSame([], array_keys($this->required),
            'a required response was never consumed: ' . implode(', ', array_keys($this->required)));
    }

    /** @return array<string, int> operation prefix => count */
    private function seenByKind(): array
    {
        $out = ['code_listing' => 0, 'contract_listing' => 0, 'smart' => 0];
        foreach ($this->seen as $op) {
            $out[explode(':', $op, 2)[0]]++;
        }

        return $out;
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function seedChain(): void
    {
        ChainRepository::seed(self::CHAIN, 'testchain', self::REST, 'cosmos', 1);
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::$rows[self::CHAIN]->cw_max_code_id = '900';
    }

    /** 25 pending families, one confirmed family, 5 siblings, 1 emittable. */
    private function seedSaturated(int $pendingFamilies = 25, int $contractsPerFamily = 1): void
    {
        $this->seedChain();

        for ($i = 0; $i < $pendingFamilies; $i++) {
            CosmwasmCodeFamilyRepository::seed(self::CHAIN, 200 + $i);
        }

        CosmwasmCodeFamilyRepository::seed(
            self::CHAIN,
            self::CONFIRMED_CODE,
            CosmwasmClassifier::CONFIRMED,
            null,
            CosmwasmClassifier::VERSION,
            '2026-08-19 00:00:00'
        );
        CosmwasmContractRepository::seed(
            self::CHAIN,
            $this->sample(),
            CosmwasmClassifier::CONFIRMED,
            self::CONFIRMED_CODE,
            false,
            false,
            CosmwasmClassifier::VERSION,
            '2026-08-19 00:00:00'
        );
        for ($i = 0; $i < 5; $i++) {
            CosmwasmContractRepository::seed(self::CHAIN, $this->sibling($i), CosmwasmClassifier::INCONCLUSIVE, self::CONFIRMED_CODE);
        }

        $this->respond($contractsPerFamily);
    }

    /** @return array{outcome:string,spent:int,remaining:int,budget:CosmwasmTickBudget} */
    private function runPass(int $budget = self::BUDGET): array
    {
        $b       = new CosmwasmTickBudget($budget, 120);
        $outcome = CosmwasmDiscoveryWorker::runSupervisedSingleChainPass(self::CHAIN, $b, new CosmwasmPassReport());

        return ['outcome' => $outcome, 'spent' => $b->spent(), 'remaining' => $b->remaining(), 'budget' => $b];
    }

    // ── (1) the defect, and the exact per-stage split ────────────────────

    /** THE REGRESSION TEST, with the whole execution trace pinned. */
    public function testASaturatedFamilyQueueCannotStarveEmission(): void
    {
        $this->seedSaturated();
        $this->require(
            'code_listing',
            'contract_listing:' . self::CONFIRMED_CODE,
            'smart:contract_info:' . $this->sample()
        );

        $out = $this->runPass();

        self::assertSame(CosmwasmDiscoveryWorker::PASS_RAN, $out['outcome']);
        $this->assertAllRequiredRequestsMade();

        // Every stage ran.
        self::assertGreaterThan(0, count(CosmwasmCodeFamilyRepository::$classifications), 'c1: families progressed');
        self::assertContains('contract_listing:' . self::CONFIRMED_CODE, $this->seen, 'c2: enumeration ran');
        self::assertContains('smart:num_tokens:' . $this->sibling(0), $this->seen, 'c3: contract classification ran');
        self::assertCount(1, CollectionRepository::$upserted, 'd: exactly one candidate emitted');

        // The exact split, request for request.
        $kinds = $this->seenByKind();
        self::assertSame(1, $kinds['code_listing'], 'stage a: one code page');
        self::assertSame(self::BUDGET, $out['spent'], 'the pass used the whole ceiling');
        self::assertSame(0, $out['remaining']);
        self::assertSame(count($this->seen), $out['spent'], 'one budget counted every request');
    }

    /** The candidate is created through bulkUpsert, unverified and bare. */
    public function testTheEmittedCandidateIsUnverifiedAndUnpublished(): void
    {
        $this->seedSaturated();
        $this->runPass();

        self::assertCount(1, CollectionRepository::$upserted);
        $row = CollectionRepository::$upserted[0];
        self::assertIsArray($row);
        self::assertSame(self::CHAIN, $row['chain_id']);
        self::assertSame($this->sample(), $row['contract_address']);
        self::assertSame('CW-721', $row['token_standard']);
        self::assertSame('Fixture Collection', $row['collection_name']);
        self::assertArrayNotHasKey('is_verified', $row, 'never set — the schema default 0 stands');
        foreach (['total_supply', 'floor_price', 'floor_currency', 'unique_holders', 'total_volume',
                  'listed_percentage', 'royalty_percentage', 'metadata_storage', 'image_url'] as $k) {
            self::assertNull($row[$k], $k . ' stays null so nothing is published');
        }
    }

    // ── (2) emission safety controls ────────────────────────────────────

    public function testADeniedContractIsNeverEmitted(): void
    {
        $this->seedSaturated();
        NftSpamContractRepository::addRule(self::CHAIN, $this->sample(), 'deny', 'fixture');

        $this->runPass();

        self::assertSame([], CollectionRepository::$upserted, 'the live deny rule still runs on the emit path');
    }

    public function testAnAlreadyKnownCollectionIsNotRewritten(): void
    {
        $this->seedSaturated();
        CollectionRepository::$knownByChain[self::CHAIN] = [
            (object) ['contract_address' => $this->sample(), 'collection_name' => 'Operator Curated'],
        ];

        $this->runPass();

        self::assertSame([], CollectionRepository::$upserted, 'an operator-curated row is never clobbered');
    }

    public function testNoEmittableCandidateMeansNoCollectionWrite(): void
    {
        // Deliberately WITHOUT the confirmed family or its contract.
        $this->seedChain();
        for ($i = 0; $i < 3; $i++) {
            CosmwasmCodeFamilyRepository::seed(self::CHAIN, 200 + $i);
        }
        $this->respond(1);

        $out = $this->runPass();

        self::assertLessThan(self::BUDGET, $out['spent'], 'budget remained for emission');
        self::assertSame([], CollectionRepository::$upserted, 'no candidate, no row');
    }

    public function testASecondInvocationDoesNotDuplicateTheCollection(): void
    {
        $this->seedSaturated();
        $this->runPass();
        self::assertCount(1, CollectionRepository::$upserted);

        $this->seen = [];
        $this->runPass();

        self::assertCount(1, CollectionRepository::$upserted, 'idempotent: collection_row_written blocks a second emit');
    }

    // ── (3) the budget contract ─────────────────────────────────────────

    public function testReserveZeroIsTheOriginalBehaviour(): void
    {
        $b = new CosmwasmTickBudget(10, 120);
        $b->reserve(0);
        self::assertSame(10, $b->available());
        self::assertTrue($b->canSpend(10));
        self::assertFalse($b->exhausted());
        $b->spend(10);
        self::assertTrue($b->exhausted());
        self::assertSame(10, $b->spent());
        self::assertSame(0, $b->remaining());
    }

    public function testANegativeReserveCannotIncreaseCapacity(): void
    {
        $b = new CosmwasmTickBudget(10, 120);
        $b->reserve(-5);
        self::assertSame(10, $b->available(), 'clamped to 0, never negative');
        self::assertFalse($b->canSpend(11), 'cannot exceed the real ceiling');
    }

    public function testAnOversizedReserveYieldsZeroSafely(): void
    {
        $b = new CosmwasmTickBudget(3, 120);
        $b->reserve(99);
        self::assertSame(0, $b->available());
        self::assertTrue($b->exhausted());
        self::assertFalse($b->canSpend());
        self::assertSame(3, $b->remaining(), 'the real requests still exist, just not for this stage');
    }

    public function testLoweringAReserveNeverRestoresSpentRequests(): void
    {
        $b = new CosmwasmTickBudget(10, 120);
        $b->reserve(4);
        $b->spend(6);
        self::assertSame(0, $b->available());
        $b->reserve(0);
        self::assertSame(4, $b->available(), 'only the genuinely remaining four');
        self::assertSame(6, $b->spent());
    }

    public function testCanSpendAndExhaustedBothRespectTheReserve(): void
    {
        $b = new CosmwasmTickBudget(10, 120);
        $b->reserve(8);
        self::assertTrue($b->canSpend(2));
        self::assertFalse($b->canSpend(3));
        self::assertFalse($b->exhausted());
        $b->spend(2);
        self::assertTrue($b->exhausted(), 'exhausted for THIS stage');
        self::assertSame(8, $b->remaining(), 'but eight remain for later stages');
    }

    /**
     * `spend()` is deliberately an ACCOUNTING call, not a gate.
     *
     * Every production spend is preceded by a canSpend()/exhausted() check
     * for at least that amount, and this pins that: crossing the reserve
     * is recorded honestly rather than silently discarded, because the
     * request really was made. The protection is the check, and the test
     * below proves the production call graph always performs it.
     */
    public function testSpendRecordsHonestlyAndNeverGoesNegativeAvailable(): void
    {
        $b = new CosmwasmTickBudget(10, 120);
        $b->reserve(6);
        $b->spend(9);
        self::assertSame(9, $b->spent(), 'the request happened and is counted');
        self::assertSame(1, $b->remaining());
        self::assertSame(0, $b->available(), 'available floors at zero, never negative');
        self::assertTrue($b->exhausted());
    }

    /** No production spend happens without a preceding affordability check. */
    public function testEveryProductionSpendIsGatedByACheck(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/CosmwasmDiscoveryService.php');
        $lines = preg_split('/\r?\n/', $src) ?: [];

        foreach ($lines as $i => $line) {
            if (!preg_match('/\$budget->spend\(/', $line)) {
                continue;
            }
            // 20 lines back: emitCollections()'s `exhausted()` guard sits at
            // the top of its foreach, 14 lines above its spend(), with the
            // already-known-collection `continue` in between.
            $window = implode("\n", array_slice($lines, max(0, $i - 20), 20));
            self::assertMatchesRegularExpression(
                '/\$budget->(canSpend|exhausted)\(/',
                $window,
                sprintf('spend() at line %d is not preceded by an affordability check', $i + 1)
            );
        }
    }

    public function testTheRuntimeDeadlineStillWins(): void
    {
        $b = new CosmwasmTickBudget(50, 1);
        $b->reserve(0);
        usleep(1_100_000);
        self::assertTrue($b->timedOut());
        self::assertTrue($b->exhausted(), 'the clock beats a full request budget');
        self::assertFalse($b->canSpend());
    }

    // ── (4) the floors ──────────────────────────────────────────────────

    public function testTheStageFloorsAreExactlyAsDerived(): void
    {
        $r = new \ReflectionClass(CosmwasmDiscoveryWorker::class);
        self::assertSame(1, $r->getConstant('RESERVE_EMIT'));
        self::assertSame(4, $r->getConstant('RESERVE_AFTER_ENUMERATION'), '3 contract + 1 emit');
        self::assertSame(5, $r->getConstant('RESERVE_AFTER_FAMILIES'), '1 enumeration + 4');
        self::assertSame(15, $r->getConstant('RESERVE_AFTER_TAIL'), '10 family + 5');
    }

    /** Worst-cost families (10 each) still leave the downstream floor. */
    public function testMaximumCostFamiliesPreserveTheDownstreamFloor(): void
    {
        $this->seedSaturated(25, 3);
        $this->require('smart:contract_info:' . $this->sample());

        $out = $this->runPass();

        $this->assertAllRequiredRequestsMade();
        self::assertLessThanOrEqual(self::BUDGET, $out['spent']);
        self::assertCount(1, CollectionRepository::$upserted, 'emission survived worst-cost families');
    }

    public function testTotalSpendNeverExceedsTheCeiling(): void
    {
        foreach ([1, 3] as $perFamily) {
            $this->setUp();
            $this->seedSaturated(25, $perFamily);
            $out = $this->runPass();
            self::assertLessThanOrEqual(self::BUDGET, $out['spent'], "contractsPerFamily={$perFamily}");
        }
    }

    public function testStageOrderIsUnchangedAndOneBudgetIsShared(): void
    {
        $src   = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php');
        $start = strpos($src, 'private static function classifyAndEnumerate');
        self::assertIsInt($start);
        $body = substr($src, $start, 4000);

        $c1 = strpos($body, 'findPendingClassification');
        $c2 = strpos($body, 'findEnumerable');
        $c3 = strpos($body, 'CosmwasmContractRepository::findPendingClassification');
        $d  = strpos($body, 'emitCollections');
        self::assertLessThan($c2, $c1, 'family classification first');
        self::assertLessThan($c3, $c2, 'then enumeration');
        self::assertLessThan($d, $c3, 'then contract classification, then emission');

        self::assertSame(2, substr_count($src, 'new CosmwasmTickBudget'),
            'forEachChain + the backfill default only — no hidden second allowance');
        self::assertStringContainsString('$budget->reserve(0);' , $body, 'released before emission');
    }

    // ── (5) partial items stay honest ───────────────────────────────────

    /**
     * A family cut short mid-sampling must not invent a verdict.
     *
     * The reserve can stop the sample loop after the contracts page, which
     * is exactly the shape that would be dangerous if it settled anything.
     */
    public function testAFamilyCutShortMidSamplingStaysHonest(): void
    {
        $this->seedSaturated();
        $this->runPass();

        $touched = 0;
        foreach (CosmwasmCodeFamilyRepository::$families[self::CHAIN] ?? [] as $codeId => $row) {
            if ($codeId === self::CONFIRMED_CODE) {
                continue;
            }
            $class = (string) $row->classification;
            self::assertNotSame(CosmwasmClassifier::CONFIRMED, $class, 'no invented confirmation');
            self::assertNotSame(CosmwasmClassifier::UNREACHABLE, $class, 'no fake transport failure');
            if ($class !== CosmwasmClassifier::INCONCLUSIVE) {
                $touched++;
            }
        }
        self::assertGreaterThan(0, $touched, 'some families genuinely settled');

        self::assertSame([], OnchainCircuitBreaker::$failureChains, 'no breaker increment from a budget stop');
    }

    /** A budget stop leaves the queue resumable, not poisoned. */
    public function testABudgetStoppedPassLeavesResumableState(): void
    {
        $this->seedSaturated();
        $this->runPass();

        $pending = CosmwasmCodeFamilyRepository::findPendingClassification(
            self::CHAIN,
            500,
            CosmwasmClassifier::VERSION
        );
        self::assertNotSame([], $pending, 'unfinished families are still selectable next pass');

        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN, self::CONFIRMED_CODE);
        self::assertNotNull($family);
        self::assertSame(CosmwasmClassifier::CONFIRMED, (string) $family->classification, 'confirmed stays confirmed');
    }

    // ── (6) each floor made LOAD-BEARING ────────────────────────────────
    //
    // A floor is only proven when removing it changes the outcome. The
    // fixtures below are tuned so that each stage would, without its own
    // reserve, consume the request the next stage needs. An earlier
    // revision left c2/c3/tail unexercised and their mutations survived.

    /** Seed a confirmed family that still needs enumerating. */
    private function seedEnumerable(int $codeId, bool $drained = false): void
    {
        CosmwasmCodeFamilyRepository::seed(
            self::CHAIN,
            $codeId,
            CosmwasmClassifier::CONFIRMED,
            null,
            CosmwasmClassifier::VERSION,
            '2026-08-19 00:00:00'
        );
        if ($drained) {
            CosmwasmCodeFamilyRepository::$families[self::CHAIN][$codeId]->enumeration_complete = '1';
        }
    }

    /** The emittable candidate on its own, with no other work. */
    private function seedEmittableOnly(): void
    {
        CosmwasmContractRepository::seed(
            self::CHAIN,
            $this->sample(),
            CosmwasmClassifier::CONFIRMED,
            self::CONFIRMED_CODE,
            false,
            false,
            CosmwasmClassifier::VERSION,
            '2026-08-19 00:00:00'
        );
    }

    /**
     * c2 FLOOR: six enumerable families would eat the downstream share.
     *
     * Without RESERVE_AFTER_ENUMERATION the enumeration loop spends every
     * remaining request (one page each) and emission never runs.
     */
    public function testTheEnumerationFloorProtectsContractClassificationAndEmission(): void
    {
        $this->seedChain();
        for ($i = 0; $i < 6; $i++) {
            $this->seedEnumerable(400 + $i);
        }
        $this->seedEmittableOnly();
        $this->respond(1);

        // 1 code page + 6 possible enumeration pages + 1 emit = more than
        // this budget allows, so the floor is what decides the outcome.
        $out = $this->runPass(6);

        self::assertCount(1, CollectionRepository::$upserted, 'emission survived a crowded enumeration queue');
        self::assertLessThanOrEqual(6, $out['spent']);
    }

    /**
     * c3 FLOOR: one classifiable contract would take the last emit request.
     *
     * Budget 4 = 1 code page + 3 left. A contract classification costs
     * exactly 3, so without RESERVE_EMIT it consumes all of them and
     * emission starves; with it, c3 correctly declines and emission runs.
     */
    public function testTheContractClassificationFloorProtectsTheEmissionRequest(): void
    {
        $this->seedChain();
        $this->seedEmittableOnly();
        CosmwasmContractRepository::seed(self::CHAIN, $this->sibling(0), CosmwasmClassifier::INCONCLUSIVE, self::CONFIRMED_CODE);
        $this->respond(1);

        $out = $this->runPass(4);

        self::assertCount(1, CollectionRepository::$upserted, 'the last request went to emission, not to a probe');
        self::assertSame($this->sample(), CollectionRepository::$upserted[0]['contract_address']);
        self::assertLessThanOrEqual(4, $out['spent']);
    }

    /**
     * TAIL FLOOR: drained families would consume the pass before c1 runs.
     *
     * Stage b walks up to 3 pages per drained family. With a budget below
     * RESERVE_AFTER_TAIL the stage must decline entirely and leave the
     * downstream stages their share.
     */
    public function testTheTailFloorProtectsEverythingDownstream(): void
    {
        // TEN drained families — FAMILIES_ENUMERATED_PER_PASS. Each tail
        // walk costs one page here (the fixture returns no next_key), so
        // ten of them would spend every request the budget has left. Five
        // was not enough: stage b stopped early of its own accord and the
        // mutation survived because emission was never actually threatened.
        $this->seedChain();
        for ($i = 0; $i < 10; $i++) {
            $this->seedEnumerable(500 + $i, true); // drained => stage-b tail work
        }
        $this->seedEmittableOnly();
        $this->respond(1);

        $out = $this->runPass(8);

        self::assertCount(1, CollectionRepository::$upserted, 'emission survived a crowded tail queue');
        self::assertLessThanOrEqual(8, $out['spent']);
    }

    /**
     * DENY: a denied candidate is withheld while an allowed one proceeds.
     *
     * The positive control is the point — an earlier version denied the
     * only candidate, so the test also passed when emission never ran at
     * all, and bypassing the filter did not fail it.
     */
    public function testADeniedCandidateIsWithheldWhileAnAllowedOneIsEmitted(): void
    {
        $allowed = 'cosmos1a' . str_pad('1', 58, '0', STR_PAD_LEFT);

        $this->seedChain();
        $this->seedEmittableOnly();                       // denied below
        CosmwasmContractRepository::seed(
            self::CHAIN,
            $allowed,
            CosmwasmClassifier::CONFIRMED,
            self::CONFIRMED_CODE,
            false,
            false,
            CosmwasmClassifier::VERSION,
            '2026-08-19 00:00:00'
        );
        // Deny the cosmos1a candidate; the sample stays ALLOWED and acts as
        // the positive control that proves emission really ran.
        NftSpamContractRepository::addRule(self::CHAIN, $allowed, 'deny', 'fixture');
        $this->respond(1);

        $this->runPass();

        $emitted = array_map(
            static fn(array $r): string => (string) $r['contract_address'],
            CollectionRepository::$upserted
        );
        self::assertContains($this->sample(), $emitted, 'the ALLOWED candidate was emitted — emission really ran');
        self::assertNotContains($allowed, $emitted, 'the DENIED candidate was withheld despite valid metadata');
    }

    /**
     * DUPLICATE GUARD: the marker, asserted by its observable effect.
     *
     * After a successful emission the candidate must no longer be
     * emittable. Asserting the final row count instead would pass on a
     * unique key alone and would not exercise markCollectionRowWritten.
     */
    public function testTheEmittedCandidateIsNoLongerEmittable(): void
    {
        $this->seedSaturated();
        self::assertCount(1, CosmwasmContractRepository::findEmittable(self::CHAIN, 25), 'precondition');

        $this->runPass();
        self::assertCount(1, CollectionRepository::$upserted);

        self::assertSame(
            [],
            CosmwasmContractRepository::findEmittable(self::CHAIN, 25),
            'markCollectionRowWritten() removed it from the emit queue'
        );
    }

    /** With no downstream work, classification is not throttled to a trickle. */
    public function testUpstreamStillProgressesWithNoDownstreamWork(): void
    {
        $this->seedChain();
        for ($i = 0; $i < 25; $i++) {
            CosmwasmCodeFamilyRepository::seed(self::CHAIN, 300 + $i);
        }
        $this->respond(0);

        $out = $this->runPass();

        self::assertGreaterThanOrEqual(20, count(CosmwasmCodeFamilyRepository::$classifications),
            'an empty downstream must not starve the upstream in turn');
        self::assertSame([], CollectionRepository::$upserted);
        self::assertLessThanOrEqual(self::BUDGET, $out['spent']);
    }
}
