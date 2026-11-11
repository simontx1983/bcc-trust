<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Support\ApiRetry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins the wasmd WIRE layer of CosmosFetcher.
 *
 * Replaces CosmosFetcherCodeIdDiscoveryTest, whose subject — the
 * curated-sampling loop, the option-backed page cursor and the 7-day
 * code-ID transient — was retired wholesale. The PARSERS survived and are
 * still load-bearing, so their coverage moves here rather than being
 * dropped, alongside the new code-listing parser and the structured error
 * seam.
 *
 * The seam is the important part. `wasmSmartQuery()` folds every failure
 * into `null`, which is correct for the read paths that only ask "did I
 * get data?" — and fatal for classification, where a node hiccup must
 * never be mistaken for "this contract does not implement CW-721."
 * `wasmSmartQueryResult()` keeps the discriminator; `wasmSmartQuery()`
 * keeps its old signature and behaviour for every existing caller. Both
 * halves of that contract are tested below.
 *
 * Isolation: resolver-stubs pattern, no live HTTP.
 */
#[CoversClass(CosmosFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmosFetcherWasmWireTest extends TestCase
{
    private const CHAIN_ID = 8;
    private const REST     = 'https://cosmos-api.polkachu.com';

    private const CURATED = 'cosmos12gsv9tmjhhg86wg9fnd9cnju28jx3fxva9cn8dh9meketkfxxajqmg3exz';
    private const FRESH   = 'cosmos1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-discovery-stubs.php';
        ApiRetry::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestObjectCache::reset();
        \BccTestOptionStore::reset();
    }

    private function makeFetcher(string $slug = 'cosmos'): CosmosFetcher
    {
        return new CosmosFetcher((object) [
            'id'         => self::CHAIN_ID,
            'slug'       => $slug,
            'chain_type' => 'cosmos',
            'rest_url'   => self::REST,
            'decimals'   => 6,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function queueJson(array $payload, int $code = 200): void
    {
        ApiRetry::$queue[] = ['code' => $code, 'body' => (string) json_encode($payload)];
    }

    /**
     * @param  array<int, mixed> $args
     * @return mixed
     */
    private static function callStatic(string $method, array $args)
    {
        $m = new ReflectionMethod(CosmosFetcher::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs(null, $args);
    }

    // ── (a) surviving parsers ───────────────────────────────────────────





    // ── (b) the new code-listing parser ─────────────────────────────────









    // ── (c) probe payload predicates ────────────────────────────────────

    public function testNumTokensPredicateAcceptsOnlyARealCount(): void
    {
        // Verified live on an SG721 collection: {"data":{"count":9995}}.
        self::assertTrue(self::callStatic('hasNumTokensCount', [['count' => 9995]]));
        self::assertTrue(self::callStatic('hasNumTokensCount', [['count' => '81']]));
        self::assertFalse(self::callStatic('hasNumTokensCount', [null]));
        self::assertFalse(self::callStatic('hasNumTokensCount', [[]]));
        self::assertFalse(self::callStatic('hasNumTokensCount', [['count' => 'many']]));
    }

    public function testCollectionNamePredicateCoversBothEnvelopes(): void
    {
        // Classic contract_info and the modern
        // get_collection_info_and_extension both carry a top-level `name`.
        self::assertTrue(self::callStatic('hasCollectionName', [['name' => 'Bad Kids', 'symbol' => 'BK']]));
        self::assertTrue(self::callStatic('hasCollectionName', [['name' => 'Event SESHIVERSARY']]));
        self::assertFalse(self::callStatic('hasCollectionName', [['name' => '']]));
        self::assertFalse(self::callStatic('hasCollectionName', [null]));
    }

    public function testAOkResponseWithAnUnreadableBodyIsMalformedNotNegative(): void
    {
        // 200 with the wrong shape says nothing about the contract, so it
        // must be non-decisive (retryable) rather than negative evidence.
        $kind = self::callStatic('probeKind', [true, false, CosmwasmClassifier::KIND_NONE]);

        self::assertSame(CosmwasmClassifier::KIND_MALFORMED, $kind);
        self::assertFalse(CosmwasmClassifier::isDecisive($kind));
    }

    // ── (d) the discrimination seam ─────────────────────────────────────

    public function testProbeSeparatesAQueryRefusalFromANodeError(): void
    {
        // num_tokens: a decisive parse refusal (Jackal code 3 fixture).
        $this->queueJson(
            ['code' => 3, 'message' => 'Error parsing into type intra_mint::msg::QueryMsg: unknown variant `num_tokens`'],
            400
        );
        // contract_info: a node-side VM error (Jackal code 1 fixture).
        $this->queueJson(['code' => 3, 'message' => 'Error calling the VM: Cache error'], 400);
        $this->queueJson(['code' => 3, 'message' => 'Error calling the VM: Cache error'], 400);

        $outcomes = $this->makeFetcher()->probeCw721(self::FRESH);

        self::assertCount(3, $outcomes);
        self::assertSame(CosmwasmClassifier::KIND_QUERY_UNSUPPORTED, $outcomes[0]['kind']);
        self::assertSame(CosmwasmClassifier::KIND_NODE_ERROR, $outcomes[1]['kind']);
        self::assertSame(CosmwasmClassifier::KIND_NODE_ERROR, $outcomes[2]['kind']);
    }

    public function testTheSecondInfoVariantIsSkippedWhenTheFirstAnswers(): void
    {
        $this->queueJson(['data' => ['count' => 81]]);
        $this->queueJson(['data' => ['name' => 'Event SESHIVERSARY', 'symbol' => 'EVENT']]);

        $outcomes = $this->makeFetcher()->probeCw721(self::FRESH);

        self::assertCount(2, $outcomes, 'no third round trip once contract_info answered');
        self::assertCount(2, ApiRetry::$calls);
    }

    public function testEvidenceExcerptNeverCarriesTheRawBody(): void
    {
        $huge = str_repeat('A', 50_000);
        $this->queueJson(['code' => 3, 'message' => 'Error parsing into type X: ' . $huge], 400);
        $this->queueJson(['code' => 3, 'message' => 'Error parsing into type X: ' . $huge], 400);
        $this->queueJson(['code' => 3, 'message' => 'Error parsing into type X: ' . $huge], 400);

        $outcomes = $this->makeFetcher()->probeCw721(self::FRESH);

        foreach ($outcomes as $outcome) {
            self::assertLessThanOrEqual(
                CosmwasmClassifier::EXCERPT_MAX,
                mb_strlen($outcome['excerpt']),
                'raw LCD bodies must never reach an evidence column'
            );
        }
    }

    public function testErrorMessageIsReadFromTheEnvelopeFieldNotTheWholeBody(): void
    {
        $extracted = self::callStatic('extractLcdErrorMessage', [
            (string) json_encode([
                'code'    => 3,
                'message' => 'Error parsing into type sg721::QueryMsg: unknown variant `num_tokens`',
                'details' => ['noise', 'more noise'],
            ]),
        ]);

        self::assertSame('Error parsing into type sg721::QueryMsg: unknown variant `num_tokens`', $extracted);
        self::assertStringNotContainsString('noise', $extracted);
    }

    public function testNonEnvelopeBodyStillYieldsABoundedString(): void
    {
        $extracted = self::callStatic('extractLcdErrorMessage', ['<html>' . str_repeat('x', 5000) . '</html>']);

        self::assertLessThanOrEqual(512, strlen($extracted));
    }

    public function testExistingNullFoldingCallersAreUnchanged(): void
    {
        // fetchContractInfo rides wasmSmartQuery, which still collapses a
        // non-200 to null. That behaviour is depended on by holdings,
        // gates and the admin probe, and must not change.
        ApiRetry::$queue[] = new \WP_Error('down');
        ApiRetry::$queue[] = new \WP_Error('down');

        self::assertNull($this->makeFetcher()->fetchContractInfo(self::FRESH));
    }



    // ── (f) HTTP 501 is recorded as "this chain has no wasm module" ───

    /**
     * The STATUS, not the wording, is what marks a chain as wasm-less.
     *
     * `probeKind()` cannot carry this: `errorKindFromMessage()` maps every
     * status >= 500 to KIND_NODE_ERROR, so by the time an outcome exists the
     * 501 is gone. The fetcher therefore records it beside the outcomes, and
     * this is the test that the recording is driven by the real status rather
     * than by a fixture setting a flag.
     */
    public function testAnHttp501MarksTheChainAsHavingNoWasmModule(): void
    {
        for ($i = 0; $i < 16; $i++) {
            $this->queueJson(['message' => 'Not Implemented'], 501);
        }

        $fetcher = $this->makeFetcher();
        $fetcher->probeCw721(self::FRESH);

        self::assertTrue(
            $fetcher->chainHasNoWasmFor(self::FRESH),
            'a 501 from the wasm endpoints is the chain saying it has no wasm module'
        );
    }

    /**
     * ⚠ THE NEGATIVE CONTROL WITH TEETH.
     *
     * A 502 is a node having a bad day on a chain that may well host CW-721.
     * Widening the test to `>= 500` would turn every gateway outage into a
     * durable claim about the chain, so this case must fail if anyone does.
     */
    public function testAnOrdinaryBadGatewayDoesNotMarkTheChain(): void
    {
        for ($i = 0; $i < 16; $i++) {
            $this->queueJson(['message' => 'Bad Gateway'], 502);
        }

        $fetcher = $this->makeFetcher();
        $fetcher->probeCw721(self::FRESH);

        self::assertFalse(
            $fetcher->chainHasNoWasmFor(self::FRESH),
            'an outage is not evidence about the chain'
        );
    }

    /** Per contract, so one address’s 501 is never attributed to the next. */
    public function testTheNoWasmSignalIsKeyedPerContractAndDefaultsFalse(): void
    {
        $fetcher = $this->makeFetcher();

        self::assertFalse(
            $fetcher->chainHasNoWasmFor(self::FRESH),
            'never probed is not the same as probed and absent'
        );

        for ($i = 0; $i < 16; $i++) {
            $this->queueJson(['message' => 'Not Implemented'], 501);
        }
        $fetcher->probeCw721(self::FRESH);

        self::assertTrue($fetcher->chainHasNoWasmFor(self::FRESH));
        self::assertFalse(
            $fetcher->chainHasNoWasmFor(self::CURATED),
            'the signal must not leak to an address this run never probed'
        );
    }

    /**
     * A GENERIC 501 — a gateway page, not a wasmd JSON error.
     *
     * The body is deliberately unparseable: no `message` field, not even
     * JSON. If the status is what drives the signal, this still marks the
     * chain; if anything is secretly reading the body, it does not.
     */
    public function testAGeneric501WithANonJsonBodyStillMarksTheChain(): void
    {
        for ($i = 0; $i < 16; $i++) {
            ApiRetry::$queue[] = ['code' => 501, 'body' => '<html><body>The gateway cannot fulfil this request.</body></html>'];
        }

        $fetcher = $this->makeFetcher();
        $fetcher->probeCw721(self::FRESH);

        self::assertTrue(
            $fetcher->chainHasNoWasmFor(self::FRESH),
            'the HTTP status is the operand, not the body'
        );
    }

    /**
     * ⚠ TRANSIENT SERVER ERRORS ARE NOT A STATEMENT ABOUT THE CHAIN.
     *
     * 500, 502 and 503 are a node or a proxy having a bad day on a chain
     * that may well host CW-721. Marking the chain on any of them would
     * convert an outage into a claim about the chain — and, once the stored
     * measurement is retired, into the only explanation an operator sees.
     *
     * Each code gets a fresh queue and a fresh fetcher so one iteration's
     * leftovers cannot answer the next one's probe.
     */
    public function testTransientServerErrorsNeverMarkTheChain(): void
    {
        foreach ([500, 502, 503] as $code) {
            ApiRetry::reset();
            for ($i = 0; $i < 16; $i++) {
                ApiRetry::$queue[] = ['code' => $code, 'body' => (string) json_encode(['message' => 'upstream unavailable'])];
            }

            $fetcher = $this->makeFetcher();
            $fetcher->probeCw721(self::FRESH);

            self::assertFalse(
                $fetcher->chainHasNoWasmFor(self::FRESH),
                "HTTP {$code} is an outage, not evidence that the chain has no wasm module"
            );
        }
    }

    /**
     * THE FLAG IS RECOMPUTED, NOT ACCUMULATED.
     *
     * The first query of every run ASSIGNS rather than ORs, so a 501 seen
     * once cannot outlive the run that saw it. Without that, one bad probe
     * would make a chain permanently wasm-less for the life of the
     * instance — a latched verdict dressed up as evidence.
     */
    public function testTheFlagIsRecomputedOnEveryProbeOfTheSameContract(): void
    {
        $fetcher = $this->makeFetcher();

        for ($i = 0; $i < 16; $i++) {
            ApiRetry::$queue[] = ['code' => 501, 'body' => (string) json_encode(['message' => 'Not Implemented'])];
        }
        $fetcher->probeCw721(self::FRESH);
        self::assertTrue($fetcher->chainHasNoWasmFor(self::FRESH), 'precondition: the 501 was seen');

        // The same contract, on the same instance, now answering normally.
        ApiRetry::reset();
        $this->queueJson(['data' => ['count' => 12]]);
        $this->queueJson(['data' => ['name' => 'Recovered', 'symbol' => 'REC']]);
        $fetcher->probeCw721(self::FRESH);

        self::assertFalse(
            $fetcher->chainHasNoWasmFor(self::FRESH),
            'a 501 from an earlier probe must not latch'
        );
    }
}
