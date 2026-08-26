<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryService;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The Dungeon repair, at the wiring and the worker.
 *
 * ── THE FIXTURE BUG THAT LET THIS SHIP ──────────────────────────────────
 * CosmwasmDiscoveryTest proves the classifier settles `not_cw721` on a
 * refused probe — and it is right. But its `queueWasmError()` helper
 * defaults to **HTTP 400**, and a cosmos LCD reports contract-level
 * rejections as **HTTP 500**. 400 never enters ApiRetry's retry-and-blame
 * branch; 500 does. So the suite was green while the real status code went
 * unexercised, and the defect reached a live chain.
 *
 * Every rejection fixture in THIS file is a 500, taken from the wire.
 *
 * ── AND THE SECOND HALF: END-OF-PASS BREAKER SEMANTICS ──────────────────
 * `runChainPass()` used to call `OnchainCircuitBreaker::recordSuccess()`
 * whenever the step returned. Because `dailyChainStep()` swallows
 * per-family failures by design, the step returns normally even when every
 * request failed — so a pass RESET a breaker that real failures had just
 * opened. "The worker returned" is not "the transport is healthy".
 */
#[CoversClass(CosmosFetcher::class)]
#[CoversClass(CosmwasmDiscoveryWorker::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmwasmSmartQueryRetryTest extends TestCase
{
    private const CHAIN    = 17;
    private const CONTRACT = 'dungeon14l6gk0aj9859zkalletngjsv6a8p78cs5qpzywj0xe45v67whdkqnfkf7l';

    /** The preserved family-89 wire message, verbatim. */
    private const MSG_FAMILY_89 =
        'Error parsing into type white_whale_std::pool_network::incentive_factory::QueryMsg: '
        . 'unknown variant `num_tokens`, expected one of `config`, `incentive`, `incentives`: '
        . 'query wasm contract failed';

    private const MSG_RPC = 'rpc error: code = Unavailable desc = connection error';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-discovery-stubs.php';

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
    }

    private function fetcher(): CosmosFetcher
    {
        $chain = (object) [
            'id' => self::CHAIN, 'slug' => 'dungeon', 'chain_type' => 'cosmos',
            'rest_url' => 'https://api.dungeongames.io', 'rpc_url' => '',
            'is_active' => 1, 'decimals' => 6,
        ];

        return new CosmosFetcher($chain);
    }

    private function budget(int $requests = 100): CosmwasmTickBudget
    {
        return new CosmwasmTickBudget($requests, 60);
    }

    /** @param array<string,mixed> $payload */
    private function queueJson(array $payload, int $code = 200): void
    {
        ApiRetry::$queue[] = ['code' => $code, 'body' => (string) json_encode($payload)];
    }

    /** A cosmos LCD contract-rejection — HTTP 500, as the chain really sends it. */
    private function queueWasm500(string $message): void
    {
        ApiRetry::$queue[] = [
            'code' => 500,
            'body' => (string) json_encode(['code' => 2, 'message' => $message, 'details' => []]),
        ];
    }

    private function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    }

    // ── (1) the predicate itself ────────────────────────────────────────

    /** @return bool */
    private function predicate(string $body, int $code)
    {
        $m = new \ReflectionMethod(CosmosFetcher::class, 'isSmartQueryApplicationError');
        $m->setAccessible(true);

        return $m->invoke(null, $body, $code);
    }

    private function envelope(string $message): string
    {
        return (string) json_encode(['code' => 2, 'message' => $message, 'details' => []]);
    }

    public function testThePredicateAcceptsTheFamily89Rejection(): void
    {
        self::assertTrue($this->predicate($this->envelope(self::MSG_FAMILY_89), 500));
    }

    /** THE CONTROL: genuine node faults must never be read as answers. */
    public function testThePredicateRejectsGenuineNodeFaults(): void
    {
        foreach ([
            self::MSG_RPC,
            'Querier system error: Unknown system error',
            'Error calling the VM: Cache error',
            'contract panicked',
            'out of gas',
            'connection refused',
        ] as $message) {
            self::assertFalse(
                $this->predicate($this->envelope($message), 500),
                sprintf('%s must stay a node error', $message)
            );
        }
    }

    /** Unrecognisable bodies fail SAFE — keep retry and breaker. */
    public function testThePredicateFailsSafeOnUnrecognisedBodies(): void
    {
        foreach (['', '{}', '{"code":2,"message":""}', '<html>502</html>', 'garbage'] as $body) {
            self::assertFalse($this->predicate($body, 500), sprintf('body %s must fail safe', var_export($body, true)));
        }
    }

    // ── (2) only the smart-query path opts in ───────────────────────────

    /**
     * THE SCOPE GUARANTEE, asserted on the source.
     *
     * `lcdGetResult()` serves code listings, contract listings and smart
     * queries. Only the last may reinterpret a 5xx — a code listing that
     * 500s really is a node problem. Proven structurally because it is a
     * property of the WIRING, not of any one response.
     */
    public function testOnlyTheSmartQueryPathEnablesApplicationErrorHandling(): void
    {
        $src = $this->source('app/Domain/Onchain/Fetchers/CosmosFetcher.php');

        // Exactly one call site passes the opt-in flag.
        self::assertSame(
            1,
            substr_count($src, '$this->lcdGetResult($path, [], true)'),
            'exactly one caller may opt in'
        );
        // …and it is the smart-query one.
        $smartPos = strpos($src, 'private function wasmSmartQueryResult');
        $optInPos = strpos($src, '$this->lcdGetResult($path, [], true)');
        self::assertIsInt($smartPos);
        self::assertIsInt($optInPos);
        self::assertGreaterThan($smartPos, $optInPos, 'the opt-in sits inside wasmSmartQueryResult');

        // The listing paths call the 2-argument form and never opt in.
        self::assertStringContainsString("lcdGetResult('/cosmwasm/wasm/v1/code/'", $src);
        self::assertSame(
            1,
            substr_count($src, "'application_error'"),
            'the option is constructed in exactly one place'
        );
    }

    /** A code-listing 500 keeps its retries and its breaker blame. */
    public function testCodeListingFiveHundredIsStillANodeFailure(): void
    {
        $this->queueWasm500(self::MSG_FAMILY_89);

        $out = $this->fetcher()->listCodeFamilies(null, true, 100);

        self::assertFalse($out['ok'], 'a listing 500 is a failure');
        self::assertSame(500, $out['http_code']);
    }

    // ── (3) the domain outcome, under the REAL status code ──────────────

    /** Family 89 settles TERMINAL `not_cw721`, not `temporarily_unreachable`. */
    public function testTheWhiteWhaleFamilySettlesAsNotCw721(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN, 89);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN, 89);
        self::assertNotNull($family);

        $this->queueJson(['contracts' => [self::CONTRACT], 'pagination' => []]);
        // num_tokens, contract_info, get_collection_info_and_extension —
        // all refused by the contract, all HTTP 500.
        $this->queueWasm500(self::MSG_FAMILY_89);
        $this->queueWasm500(str_replace('num_tokens', 'contract_info', self::MSG_FAMILY_89));
        $this->queueWasm500(str_replace('num_tokens', 'get_collection_info_and_extension', self::MSG_FAMILY_89));

        $result = CosmwasmDiscoveryService::classifyFamily(self::CHAIN, $this->fetcher(), $family, $this->budget());

        self::assertSame(CosmwasmClassifier::NOT_CW721, $result['classification']);
        self::assertNotSame(CosmwasmClassifier::UNREACHABLE, $result['classification']);
    }

    /** THE CONTROL: a real CW-721 is still recognised. */
    public function testAKnownGoodCw721IsStillConfirmed(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN, 3);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN, 3);
        self::assertNotNull($family);

        $this->queueJson(['contracts' => ['dungeon1hrpna9v7vs3stzyd4z3xf00676kf78zpe2u5ksvljswn2vnjp3ys7uf80g'], 'pagination' => []]);
        // The verbatim AshFall responses measured on chain.
        $this->queueJson(['data' => ['count' => 0]]);
        $this->queueJson(['data' => ['name' => 'AshFall: Lost Artifacts', 'symbol' => 'ASHART']]);

        $result = CosmwasmDiscoveryService::classifyFamily(self::CHAIN, $this->fetcher(), $family, $this->budget());

        self::assertSame(CosmwasmClassifier::CONFIRMED, $result['classification']);
    }

    /** A genuine node fault mid-probe stays retryable — never a settled negative. */
    public function testAGenuineNodeFaultStaysRetryable(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN, 90);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN, 90);
        self::assertNotNull($family);

        $this->queueJson(['contracts' => [self::CONTRACT], 'pagination' => []]);
        $this->queueWasm500(self::MSG_RPC);
        $this->queueWasm500(self::MSG_RPC);
        $this->queueWasm500(self::MSG_RPC);

        $result = CosmwasmDiscoveryService::classifyFamily(self::CHAIN, $this->fetcher(), $family, $this->budget());

        self::assertSame(CosmwasmClassifier::UNREACHABLE, $result['classification']);
    }

    // ── (4) end-of-pass breaker semantics ───────────────────────────────

    /**
     * A PASS THAT FINISHES DOES NOT CLEAR REAL INFRASTRUCTURE FAILURES.
     *
     * The code-tail read fails at the transport layer. `dailyChainStep()`
     * records that on the row and returns normally — which is exactly the
     * shape that used to hand the breaker an unearned success.
     */
    public function testAFinishedPassDoesNotClearRealInfrastructureFailures(): void
    {
        ChainRepository::seed(self::CHAIN, 'dungeon', 'https://api.dungeongames.io', 'cosmos', 1);
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        $this->queueWasm500(self::MSG_RPC);

        $report  = new \BCC\Trust\Onchain\Support\CosmwasmPassReport();
        $outcome = CosmwasmDiscoveryWorker::runSupervisedSingleChainPass(self::CHAIN, $this->budget(), $report);

        self::assertSame(CosmwasmDiscoveryWorker::PASS_RAN, $outcome, 'the pass completed');
        self::assertSame(
            [],
            OnchainCircuitBreaker::$successChains,
            'a completed pass must NOT credit the chain — that erased real failures'
        );
        self::assertNotSame([], OnchainCircuitBreaker::$failureChains, 'the real failure is still recorded');
    }

    /** THE STRUCTURAL GUARANTEE: no unconditional success at the end of a pass. */
    public function testRunChainPassNeverRecordsSuccessUnconditionally(): void
    {
        $src = $this->source('app/Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php');

        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $t[1];
                continue;
            }
            $code .= $t;
        }

        $start = strpos($code, 'private static function runChainPass');
        self::assertIsInt($start);
        $body = substr($code, $start, 1400);

        self::assertStringNotContainsString(
            'OnchainCircuitBreaker::recordSuccess',
            $body,
            'runChainPass must not credit the breaker; ApiRetry does that from real responses'
        );
        self::assertStringContainsString(
            'OnchainCircuitBreaker::recordFailure',
            $body,
            'a thrown pass is still a real failure and must still be recorded'
        );
    }

    /**
     * SCHEDULED AND SUPERVISED SHARE ONE ENVELOPE.
     *
     * Both reach the breaker through the single `runChainPass()`
     * implementation, so their semantics cannot diverge by construction.
     */
    public function testTheSupervisedPathUsesTheOneBreakerEnvelope(): void
    {
        $src = $this->source('app/Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php');

        self::assertSame(1, substr_count($src, 'private static function runChainPass'), 'one envelope');
        self::assertSame(1, substr_count($src, 'self::runChainPass('), 'the supervised one-shot only');
        self::assertStringContainsString('return self::runChainPass(', $src);
    }
}
