<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryService;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANTS OF THE EMISSION PASS, measured through the real budget, the
 * real retry loop and the real circuit breaker:
 *
 *   1. A candidate whose own verdict says both metadata variants were refused
 *      is skipped WITHOUT a request — so it can no longer sit at the head of
 *      the queue re-charging the breaker on every pass — and the NEXT eligible
 *      candidate is still processed in the same pass.
 *   2. Both metadata requests are charged to the budget separately, and the
 *      pass never exceeds it, including when exactly one unit is left.
 *   3. Nothing is emitted from metadata nobody could read, and the skipped row
 *      is not promoted, denied, deleted, reclassified or marked written.
 *
 * ⚠ THE FAKE QUEUE APPLIES NO HOLD OF ITS OWN (see
 * tests/Stubs/emission-metadata-stubs.php): `findEmittable()` hands back every
 * seeded candidate in id order exactly as the SQL does, so a passing test
 * proves the PRODUCTION service skipped the row, not that a double hid it.
 */
#[CoversClass(CosmwasmDiscoveryService::class)]
#[CoversClass(CosmwasmClassifier::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmissionQueueBudgetAndBreakerTest extends TestCase
{
    private const CHAIN = 8;
    private const HELD  = 'cosmos1heldprobablecontractawaitingadminreview00';
    private const GOOD  = 'cosmos1goodconfirmedcontractthatanswersmetadata0';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/emission-metadata-stubs.php';

        \BccWire::reset();
        \BccBreakerStore::reset();
        \BccEmissionWorld::reset();
    }

    private function fetcher(): CosmosFetcher
    {
        return new CosmosFetcher((object) [
            'id'         => self::CHAIN,
            'slug'       => 'testchain',
            'chain_type' => 'cosmos',
            'rest_url'   => 'https://lcd.test',
            'rpc_url'    => '',
            'is_active'  => 1,
            'decimals'   => 6,
        ]);
    }

    private function charges(): int
    {
        return \BccBreakerStore::counter(self::CHAIN) ?? 0;
    }

    /** @return array{code: int, body: string} */
    private static function refusal(string $variant): array
    {
        return [
            'code' => 500,
            'body' => json_encode([
                'code'    => 2,
                'message' => 'Error parsing into type cw721_base::msg::QueryMsg: unknown variant `'
                    . $variant . '`, expected one of `owner_of`, `num_tokens`: query wasm contract failed',
                'details' => [],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function success(array $payload): array
    {
        return ['code' => 200, 'body' => json_encode(['data' => $payload], JSON_THROW_ON_ERROR)];
    }

    /** Seed the queue head with the known poison-pill shape. */
    private static function seedHeldHead(): void
    {
        \BccEmissionWorld::candidate(
            1,
            self::HELD,
            CosmwasmClassifier::PROBABLE,
            CosmwasmClassifier::REASON_NUM_TOKENS_ONLY,
            540
        );
    }

    // ── 1. the queue advances past the held candidate ───────────────────

    public function testTheHeldCandidateIsSkippedAndTheNextOneIsProcessed(): void
    {
        self::seedHeldHead();
        \BccEmissionWorld::candidate(2, self::GOOD);
        \BccWire::$queue = [self::success(['name' => 'Good Collection', 'symbol' => 'GOOD'])];

        $budget = new ProviderRequestBudget(25, 20);
        $result = CosmwasmDiscoveryService::emitCollections(self::CHAIN, $this->fetcher(), $budget, 25);

        self::assertSame(1, $result['held_for_review'], 'the poison pill is counted, not silently dropped');
        self::assertSame(1, $result['emitted'], 'and the candidate behind it still got emitted');
        self::assertCount(1, \BccWire::$urls, 'exactly ONE request: the held row was never asked about');
        self::assertSame(1, $budget->spent(), 'and it cost exactly one request');
        self::assertSame(0, $this->charges(), 'no breaker charge anywhere in this pass');
        self::assertCount(1, \BccEmissionWorld::$upserts);
        self::assertSame(self::GOOD, \BccEmissionWorld::$upserts[0]['contract_address']);
    }

    public function testAQueueOfOnlyHeldCandidatesAsksForNothingAndChargesNothing(): void
    {
        self::seedHeldHead();
        \BccEmissionWorld::candidate(
            2,
            'cosmos1anotherheldprobablecontractnumtokensonly00',
            CosmwasmClassifier::PROBABLE,
            CosmwasmClassifier::REASON_NUM_TOKENS_ONLY,
            565
        );
        \BccWire::$always = self::refusal(CosmwasmClassifier::PROBE_CONTRACT_INFO);

        $budget = new ProviderRequestBudget(25, 20);
        $result = CosmwasmDiscoveryService::emitCollections(self::CHAIN, $this->fetcher(), $budget, 25);

        self::assertSame(2, $result['held_for_review']);
        self::assertSame(0, $result['emitted']);
        self::assertSame([], \BccWire::$urls, 'THE REGRESSION: this pass used to spend 8 charges here');
        self::assertSame(0, $this->charges());
        self::assertSame(0, $budget->spent(), 'a skipped candidate costs no budget either');
        self::assertSame([], \BccEmissionWorld::$writes, 'and writes nothing at all');
    }

    /**
     * The held row keeps every column that describes it. The brief is explicit:
     * preserve it as `probable_cw721` for administrator review — do not
     * promote it, delete it, deny it, or call it negative.
     */
    public function testTheHeldCandidateIsNeitherPromotedDeniedNorMarkedWritten(): void
    {
        self::seedHeldHead();
        \BccWire::$always = self::refusal(CosmwasmClassifier::PROBE_CONTRACT_INFO);

        CosmwasmDiscoveryService::emitCollections(self::CHAIN, $this->fetcher(), new ProviderRequestBudget(25, 20), 25);

        self::assertSame([], \BccEmissionWorld::$writes, 'no write touched the row');
        self::assertSame([], \BccEmissionWorld::$upserts, 'no collection was created from it');
        $row = \BccEmissionWorld::$candidates[0];
        self::assertSame(CosmwasmClassifier::PROBABLE, $row->classification, 'still probable');
        self::assertSame(CosmwasmClassifier::REASON_NUM_TOKENS_ONLY, $row->classification_reason);
        self::assertSame('0', $row->denied, 'not denied');
        self::assertSame('0', $row->collection_row_written, 'not marked written');
    }

    /**
     * ⚠ `info_only` IS THE OTHER PROBABLE VERDICT, AND IT MUST STILL FLOW. It
     * means an info variant ANSWERED and `num_tokens` refused, so emission can
     * resolve a name. Holding it would quietly stop emitting a whole class of
     * real collections.
     */
    public function testAProbableCandidateWhoseInfoQueryAnsweredIsNotHeld(): void
    {
        \BccEmissionWorld::candidate(1, self::GOOD, CosmwasmClassifier::PROBABLE, 'info_only');
        \BccWire::$queue = [self::success(['name' => 'Info Only Collection'])];

        $result = CosmwasmDiscoveryService::emitCollections(self::CHAIN, $this->fetcher(), new ProviderRequestBudget(25, 20), 25);

        self::assertSame(0, $result['held_for_review']);
        self::assertSame(1, $result['emitted']);
        self::assertCount(1, \BccWire::$urls);
    }

    // ── 1b. the rule itself, in one place ───────────────────────────────

    /**
     * The hold is ONE pure predicate, and this is its whole truth table. Both
     * consumers — the emit pass and the inventory aggregate — ask this; there
     * is deliberately no second copy in SQL, because the evidence column it
     * would have to match (`probes_failed`) is length-capped and can truncate.
     */
    public function testTheHoldPredicateHoldsExactlyTheRefusedMetadataVerdict(): void
    {
        self::assertTrue(
            CosmwasmClassifier::awaitsMetadataReview(CosmwasmClassifier::PROBABLE, CosmwasmClassifier::REASON_NUM_TOKENS_ONLY),
            'probable + both metadata variants refused: held'
        );
        self::assertFalse(
            CosmwasmClassifier::awaitsMetadataReview(CosmwasmClassifier::PROBABLE, 'info_only'),
            'probable because num_tokens refused: an info variant ANSWERED, so emission can still finish it'
        );
        self::assertFalse(
            CosmwasmClassifier::awaitsMetadataReview(CosmwasmClassifier::CONFIRMED, 'num_tokens_and_info'),
            'confirmed is never held'
        );
        self::assertFalse(
            CosmwasmClassifier::awaitsMetadataReview(CosmwasmClassifier::CONFIRMED, CosmwasmClassifier::REASON_NUM_TOKENS_ONLY),
            'the reason alone does not hold a row — the classification must agree'
        );
        self::assertFalse(
            CosmwasmClassifier::awaitsMetadataReview(CosmwasmClassifier::PROBABLE, null),
            'no recorded reason is not evidence of a refusal'
        );
        self::assertFalse(
            CosmwasmClassifier::awaitsMetadataReview(CosmwasmClassifier::NOT_CW721, CosmwasmClassifier::REASON_NUM_TOKENS_ONLY),
            'a terminal negative is not a review candidate'
        );
        self::assertSame(
            'num_tokens_only',
            CosmwasmClassifier::REASON_NUM_TOKENS_ONLY,
            'the promoted constant must keep the stored value, or every existing row changes meaning'
        );
    }

    // ── 2. budget accounting ────────────────────────────────────────────

    public function testAnsweringOnTheFirstVariantCostsExactlyOneUnit(): void
    {
        \BccEmissionWorld::candidate(1, self::GOOD);
        \BccWire::$queue = [self::success(['name' => 'Answered First Try'])];

        $budget = new ProviderRequestBudget(25, 20);
        $result = CosmwasmDiscoveryService::emitCollections(self::CHAIN, $this->fetcher(), $budget, 25);

        self::assertCount(1, \BccWire::$urls);
        self::assertSame(1, $budget->spent(), 'the classic variant answered: one request, one unit');
        self::assertSame(1, $result['emitted']);
    }

    /** ⚠ THE FIX ITSELF: the pair used to cost ONE unit no matter what. */
    public function testAnsweringOnTheFallbackCostsExactlyTwoUnits(): void
    {
        \BccEmissionWorld::candidate(1, self::GOOD);
        \BccWire::$queue = [
            self::refusal(CosmwasmClassifier::PROBE_CONTRACT_INFO),
            self::success(['name' => 'Answered On Fallback']),
        ];

        $budget = new ProviderRequestBudget(25, 20);
        $result = CosmwasmDiscoveryService::emitCollections(self::CHAIN, $this->fetcher(), $budget, 25);

        self::assertCount(2, \BccWire::$urls, 'two requests were really made');
        self::assertSame(2, $budget->spent(), 'THE FIX: both metadata queries are charged, not one');
        self::assertSame(1, $result['emitted']);
        self::assertSame(0, $this->charges(), 'and the refusal on the way cost no charge');
    }

    /**
     * The metadata cache is part of the budget story: a contract already read
     * this tick is not re-asked, so a second pass over the same candidate
     * costs nothing. Asserted so a future change to the cache key cannot
     * quietly double this stage's request count.
     */
    public function testASecondPassOverTheSameContractIsServedFromCacheForFree(): void
    {
        \BccEmissionWorld::candidate(1, self::GOOD);
        \BccWire::$queue = [self::success(['name' => 'Cached Collection'])];

        $first = new ProviderRequestBudget(25, 20);
        CosmwasmDiscoveryService::emitCollections(self::CHAIN, $this->fetcher(), $first, 25);
        self::assertSame(1, $first->spent());

        \BccEmissionWorld::$writes  = [];
        \BccEmissionWorld::$upserts = [];
        $second = new ProviderRequestBudget(25, 20);
        CosmwasmDiscoveryService::emitCollections(self::CHAIN, $this->fetcher(), $second, 25);

        self::assertCount(1, \BccWire::$urls, 'still ONE request in total — the second pass asked nothing');
        self::assertSame(0, $second->spent(), 'a cache hit authorizes no request, so it charges no budget');
    }

    /**
     * ⚠ THE ONE-UNIT CASE. With a single request left, the pass may ask the
     * classic variant and must then STOP: no second request, no overrun, and
     * the candidate stays queued for the next pass rather than being written
     * from metadata nobody read.
     */
    public function testWithOneUnitLeftTheFallbackIsDeclinedAndTheBudgetIsNotExceeded(): void
    {
        \BccEmissionWorld::candidate(1, self::GOOD);
        \BccWire::$always = self::refusal(CosmwasmClassifier::PROBE_CONTRACT_INFO);

        $budget = new ProviderRequestBudget(1, 20);
        $result = CosmwasmDiscoveryService::emitCollections(self::CHAIN, $this->fetcher(), $budget, 25);

        self::assertCount(1, \BccWire::$urls, 'exactly one request — the fallback was refused');
        self::assertSame(1, $budget->spent(), 'spent exactly the one unit it had');
        self::assertSame(0, $budget->remaining(), 'and not one request more');
        self::assertSame(0, $result['emitted'], 'nothing is emitted from an unanswered pair');
        self::assertSame([], \BccEmissionWorld::$upserts);
        self::assertSame([], \BccEmissionWorld::$writes, 'the candidate stays queued, unmarked');
        self::assertSame(0, $this->charges());
    }

    public function testAnExhaustedBudgetAsksForNothing(): void
    {
        \BccEmissionWorld::candidate(1, self::GOOD);
        \BccWire::$always = self::success(['name' => 'Never Asked']);

        $budget = new ProviderRequestBudget(1, 20);
        $budget->spend();

        $result = CosmwasmDiscoveryService::emitCollections(self::CHAIN, $this->fetcher(), $budget, 25);

        self::assertSame([], \BccWire::$urls);
        self::assertSame(0, $result['emitted']);
        self::assertSame(1, $budget->spent(), 'the pass spent nothing of its own');
    }

    // ── 3. nothing is emitted from unreadable metadata ──────────────────

    public function testBothVariantsRefusedAtRuntimeEmitsNothingAndClassifiesNothing(): void
    {
        // A CONFIRMED row — so the hold does not apply — whose contract
        // nevertheless refuses both variants right now.
        \BccEmissionWorld::candidate(1, self::GOOD);
        \BccWire::$queue = [
            self::refusal(CosmwasmClassifier::PROBE_CONTRACT_INFO),
            self::refusal(CosmwasmClassifier::PROBE_COLLECTION_INFO),
        ];

        $budget = new ProviderRequestBudget(25, 20);
        $result = CosmwasmDiscoveryService::emitCollections(self::CHAIN, $this->fetcher(), $budget, 25);

        self::assertSame(0, $result['emitted'], 'no name, no row');
        self::assertSame([], \BccEmissionWorld::$upserts);
        self::assertSame([], \BccEmissionWorld::$writes, 'not marked written, not denied, not reclassified');
        self::assertSame(2, $budget->spent(), 'both attempts were paid for');
        self::assertSame(0, $this->charges(), 'and neither blamed the provider');
        self::assertSame(
            OnchainCircuitBreaker::PHASE_CLOSED,
            OnchainCircuitBreaker::phase(self::CHAIN),
            'a contract refusing a query must never open the chain breaker'
        );
    }

    public function testAGenuineProviderFaultDuringEmissionIsStillAttributedToTheProvider(): void
    {
        \BccEmissionWorld::candidate(1, self::GOOD);
        \BccWire::$always = [
            'code' => 500,
            'body' => '{"code":13,"message":"rpc error: code = Internal desc = Querier system error"}',
        ];

        $result = CosmwasmDiscoveryService::emitCollections(self::CHAIN, $this->fetcher(), new ProviderRequestBudget(25, 20), 25);

        self::assertSame(0, $result['emitted']);
        // ⚠ STILL BLAMED, NO LONGER INSTANTLY FATAL. The node fault is still
        // charged to the provider — two probe variants, two logical requests,
        // two charges — but one emission pass against a broken node no longer
        // exhausts a five-failure threshold on its own. That is PR B's
        // intended effect: the breaker opens when a chain keeps failing, not
        // when one pass retries.
        self::assertSame(
            2,
            $this->charges(),
            'a real node fault is still charged, once per logical request'
        );
        self::assertSame(
            OnchainCircuitBreaker::PHASE_CLOSED,
            OnchainCircuitBreaker::phase(self::CHAIN),
            'two charges are below the threshold of five'
        );
        $attribution = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertSame('http_5xx', $attribution['kind'], 'recorded as what it was');
        self::assertSame(
            'smart_query',
            $attribution['request_class'],
            'and on the request class it really used — the truthful label, not the old standard_request'
        );
    }

    // ── 4. the deny re-check still runs before the metadata request ─────

    public function testADenyRuleStillWinsAndCostsNoMetadataRequest(): void
    {
        \BccEmissionWorld::candidate(1, self::GOOD);
        \BccEmissionWorld::$rules[strtolower(self::GOOD)] = 'deny';
        \BccWire::$always = self::success(['name' => 'Denied Collection']);

        $result = CosmwasmDiscoveryService::emitCollections(self::CHAIN, $this->fetcher(), new ProviderRequestBudget(25, 20), 25);

        self::assertSame(1, $result['denied']);
        self::assertSame(0, $result['emitted']);
        self::assertContains('set_denied:' . strtolower(self::GOOD) . ':1', \BccEmissionWorld::$writes);
        self::assertSame([], \BccEmissionWorld::$upserts);
    }

    public function testAnAlreadyKnownCollectionIsMarkedWithoutAnyRequest(): void
    {
        \BccEmissionWorld::candidate(1, self::GOOD);
        \BccEmissionWorld::$known[strtolower(self::GOOD)] = true;

        $budget = new ProviderRequestBudget(25, 20);
        $result = CosmwasmDiscoveryService::emitCollections(self::CHAIN, $this->fetcher(), $budget, 25);

        self::assertSame(1, $result['skipped_known']);
        self::assertSame([], \BccWire::$urls, 'a known collection needs no metadata request');
        self::assertSame(0, $budget->spent());
        self::assertContains('mark_written:' . strtolower(self::GOOD), \BccEmissionWorld::$writes);
    }
}
