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
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end pins for CosmWasm CW-721 discovery.
 *
 * WHAT THIS REPLACES. The previous loop learned a chain's CW-721 code IDs
 * by sampling ALREADY-CURATED collections, so a code family with nothing
 * curated under it was never sampled, never enumerated, never discovered
 * — a chain with zero curated collections discovered nothing, forever.
 * The first two tests below are that closed loop, opened.
 *
 * Isolation: the resolver-stubs pattern. Every repository, the chain
 * registry, the fetcher factory and the transport are faked at their
 * PRODUCTION FQNs inside a subprocess. CI NEVER TOUCHES A PUBLIC RPC.
 */
#[CoversClass(CosmwasmDiscoveryService::class)]
#[CoversClass(CosmwasmDiscoveryWorker::class)]
#[CoversClass(CosmwasmDiscoveryGate::class)]
#[CoversClass(CosmwasmTickBudget::class)]
#[CoversClass(CosmosFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmwasmDiscoveryTest extends TestCase
{
    private const CHAIN_ID = 8;
    private const CHAIN_B  = 9;
    private const REST     = 'https://lcd.example';

    private const CONTRACT_A = 'cosmos1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CONTRACT_B = 'cosmos1bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const CONTRACT_C = 'cosmos1cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    /** Live Jackal fixtures — see CosmwasmClassifierTest for provenance. */
    private const MSG_PARSE = 'Error parsing into type intra_mint::msg::QueryMsg: unknown variant `num_tokens`';
    private const MSG_VM    = 'Error calling the VM: Cache error: Error opening Wasm file for reading';

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
        \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestObjectCache::reset();
        \BccTestOptionStore::reset();
        \BccTestCronStore::reset();

        ChainRepository::seed(self::CHAIN_ID, 'cosmos', self::REST);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function fetcher(int $chainId = self::CHAIN_ID): CosmosFetcher
    {
        $chain = ChainRepository::getById($chainId);
        self::assertNotNull($chain);

        return new CosmosFetcher($chain);
    }

    /** @param array<string, mixed> $payload */
    private function queueJson(array $payload, int $code = 200): void
    {
        ApiRetry::$queue[] = ['code' => $code, 'body' => (string) json_encode($payload)];
    }

    /** A wasmd error envelope — the shape both failure classes arrive in. */
    private function queueWasmError(string $message, int $code = 400): void
    {
        ApiRetry::$queue[] = ['code' => $code, 'body' => (string) json_encode(['code' => 3, 'message' => $message])];
    }

    /** A full CONFIRMED probe set: num_tokens + contract_info. */
    private function queueConfirmedProbe(string $name = 'Fixture Collection'): void
    {
        $this->queueJson(['data' => ['count' => 81]]);
        $this->queueJson(['data' => ['name' => $name, 'symbol' => 'FIX']]);
    }

    /** A full NOT-CW721 probe set: three decisive parse refusals. */
    private function queueRefusedProbe(): void
    {
        $this->queueWasmError(self::MSG_PARSE);
        $this->queueWasmError(self::MSG_PARSE);
        $this->queueWasmError(self::MSG_PARSE);
    }

    /** @return list<string> every URL the transport fake saw. */
    private function urls(): array
    {
        return array_map(static fn(array $c): string => $c['url'], ApiRetry::$calls);
    }

    private function budget(int $requests = 100): CosmwasmTickBudget
    {
        return new CosmwasmTickBudget($requests, 60);
    }

    // ── (a) the closed loop, opened ─────────────────────────────────────

    public function testChainWithNoCuratedCollectionsStillDiscoversCodeIds(): void
    {
        // ZERO curated collections, zero configuration. The old loop
        // discovered nothing here by construction.
        self::assertSame([], CollectionRepository::$knownByChain);

        $this->queueJson([
            'code_infos' => [
                ['code_id' => '1', 'data_hash' => 'AA11'],
                ['code_id' => '2', 'data_hash' => 'BB22'],
            ],
            'pagination' => ['next_key' => null],
        ]);

        $result = CosmwasmDiscoveryService::ingestCodePage(
            self::CHAIN_ID,
            $this->fetcher(),
            null,
            $this->budget()
        );

        self::assertTrue($result['ok']);
        self::assertSame(2, $result['families']);
        self::assertSame(2, CosmwasmCodeFamilyRepository::countForChain(self::CHAIN_ID));
        self::assertStringContainsString('/cosmwasm/wasm/v1/code?', $this->urls()[0]);
    }

    public function testUnknownCodeIdIsDiscoveredWithoutConfiguration(): void
    {
        self::assertFalse(defined('BCC_CW721_CODE_IDS'));

        $this->queueJson([
            'code_infos' => [['code_id' => '9999', 'data_hash' => 'ZZ99']],
            'pagination' => ['next_key' => null],
        ]);

        CosmwasmDiscoveryService::ingestCodePage(self::CHAIN_ID, $this->fetcher(), null, $this->budget());

        self::assertNotNull(CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 9999));
    }

    public function testWasmBinaryEndpointIsNeverRequested(): void
    {
        // /cosmwasm/wasm/v1/code/{id} returns the ENTIRE binary base64 in
        // its `data` field. The listing gives us the checksum for free.
        $this->queueJson([
            'code_infos' => [['code_id' => '434', 'data_hash' => 'ABC123']],
            'pagination' => ['next_key' => null],
        ]);

        CosmwasmDiscoveryService::ingestCodePage(self::CHAIN_ID, $this->fetcher(), null, $this->budget());

        foreach ($this->urls() as $url) {
            self::assertDoesNotMatchRegularExpression('~/cosmwasm/wasm/v1/code/\d+(\?|$)~', $url);
        }
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 434);
        self::assertNotNull($family);
        self::assertSame('abc123', $family->checksum);
    }

    // ── (a2) incremental code-id tail — the reverse-walk watermark ──────
    //
    // REGRESSION CONTEXT. The first implementation of this pass used
    // `pagination.offset`. MEASURED 2026-08-06: any non-zero offset
    // returns an EMPTY list with HTTP 200 on cosmoshub, juno, osmosis and
    // injective — only jackal honours it. An empty 200 is not an error, so
    // no retry ever fired and the pass concluded "nothing new" FOREVER,
    // while reporting healthy. Daily discovery would have been dead on the
    // four biggest chains. These tests pin the replacement AND the
    // not-authoritative rule that makes the failure loud instead of
    // silent.

    public function testIncrementalCodeTailUsesReverseAndNeverOffset(): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_max_code_id = '711';

        // Newest-first, reaching down past the watermark.
        $this->queueJson([
            'code_infos' => [
                ['code_id' => '713', 'data_hash' => 'AA'],
                ['code_id' => '712', 'data_hash' => 'BB'],
                ['code_id' => '711', 'data_hash' => 'CC'],
            ],
            'pagination' => ['next_key' => 'MORE=='],
        ]);

        $tail = CosmwasmDiscoveryService::ingestNewCodeFamilies(
            self::CHAIN_ID,
            $this->fetcher(),
            711,
            $this->budget(),
            CosmwasmDiscoveryGate::CODE_TAIL_MAX_PAGES
        );

        self::assertTrue($tail['ok']);
        self::assertTrue($tail['reached_watermark'], 'the walk met code_id 711');
        self::assertSame(713, $tail['newest_code_id']);
        self::assertSame(1, $tail['pages'], 'typical daily cost is ONE request');
        self::assertSame(3, $tail['ingested']);

        $url = $this->urls()[0];
        self::assertStringContainsString('pagination.reverse=true', $url);
        self::assertStringNotContainsString('pagination.offset', $url);
    }

    public function testEmptyTailPageIsNotAuthoritativeWhenAWatermarkExists(): void
    {
        // THE EXACT DEFECT. `pagination.offset` on cosmoshub/juno/osmosis/
        // injective answered 200 with an empty list. If an empty page were
        // trusted, the pass would report "nothing new" forever.
        $this->queueJson(['code_infos' => [], 'pagination' => ['next_key' => null]]);

        $tail = CosmwasmDiscoveryService::ingestNewCodeFamilies(
            self::CHAIN_ID,
            $this->fetcher(),
            713,
            $this->budget(),
            CosmwasmDiscoveryGate::CODE_TAIL_MAX_PAGES
        );

        self::assertTrue($tail['ok'], 'the transport succeeded — that is the trap');
        self::assertTrue($tail['anomaly']);
        self::assertFalse(
            $tail['reached_watermark'],
            'an empty page must NEVER be read as "reached the watermark, nothing new"'
        );
        self::assertSame(0, $tail['newest_code_id']);
    }

    public function testEmptyTailPageIsAuthoritativeOnlyWhenNothingIsInventoried(): void
    {
        // Watermark 0 = we know of no code ids, so an empty listing IS the
        // truth and is not an anomaly.
        $this->queueJson(['code_infos' => [], 'pagination' => ['next_key' => null]]);

        $tail = CosmwasmDiscoveryService::ingestNewCodeFamilies(
            self::CHAIN_ID,
            $this->fetcher(),
            0,
            $this->budget(),
            CosmwasmDiscoveryGate::CODE_TAIL_MAX_PAGES
        );

        self::assertFalse($tail['anomaly']);
        self::assertTrue($tail['reached_watermark']);
    }

    public function testWatermarkIsNotAdvancedWhenTheWalkDidNotReachIt(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);

        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_max_code_id = '100';

        // Every page is above the watermark and reports more after it, so
        // the walk runs out of pages before meeting code_id 100.
        for ($i = 0; $i < CosmwasmDiscoveryGate::CODE_TAIL_MAX_PAGES + 1; $i++) {
            $this->queueJson([
                'code_infos' => [['code_id' => (string) (5000 - $i), 'data_hash' => 'H' . $i]],
                'pagination' => ['next_key' => 'P' . $i],
            ]);
        }

        CosmwasmDiscoveryWorker::runDailyDiscovery();

        // The watermark MUST NOT move: advancing to 5000 would strand
        // everything between 101 and 4995 forever.
        self::assertSame([], ChainCheckpointRepository::$watermarkAdvances);
        self::assertSame('100', (string) ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_max_code_id);
        // And the chain is VISIBLY degraded, not silently healthy.
        self::assertNotSame([], ChainCheckpointRepository::$backfillRestarts);
        self::assertNotNull(ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_last_error);
        self::assertSame(
            ChainCheckpointRepository::CW_STATE_BACKFILLING,
            (string) ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_discovery_state
        );
    }

    public function testDailyPassAdvancesTheWatermarkOnlyOnAProvenWalk(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);

        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_max_code_id = '711';

        $this->queueJson([
            'code_infos' => [
                ['code_id' => '713', 'data_hash' => 'AA'],
                ['code_id' => '711', 'data_hash' => 'CC'],
            ],
            'pagination' => ['next_key' => 'MORE=='],
        ]);

        CosmwasmDiscoveryWorker::runDailyDiscovery();

        self::assertSame(
            [['chain_id' => self::CHAIN_ID, 'max' => 713]],
            ChainCheckpointRepository::$watermarkAdvances
        );
        self::assertSame([], ChainCheckpointRepository::$backfillRestarts);
        self::assertNotNull(CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 713));
    }

    public function testDailyPassOnAnEmptyTailPageDoesNotReportHealthy(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);

        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_max_code_id = '713';

        $this->queueJson(['code_infos' => [], 'pagination' => ['next_key' => null]]);

        CosmwasmDiscoveryWorker::runDailyDiscovery();

        self::assertSame([], ChainCheckpointRepository::$watermarkAdvances);
        self::assertNotSame([], ChainCheckpointRepository::$backfillRestarts);
        self::assertStringContainsString(
            'empty page',
            (string) ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_last_error
        );
    }

    public function testTailWalkIsRobustToNonSequentialCodeIds(): void
    {
        // The stop condition is NUMERIC, not positional, so gaps in the id
        // sequence cannot make it overshoot or stop early.
        $this->queueJson([
            'code_infos' => [
                ['code_id' => '900', 'data_hash' => 'AA'],
                ['code_id' => '450', 'data_hash' => 'BB'],
                ['code_id' => '99',  'data_hash' => 'CC'],
            ],
            'pagination' => ['next_key' => 'MORE=='],
        ]);

        $tail = CosmwasmDiscoveryService::ingestNewCodeFamilies(
            self::CHAIN_ID,
            $this->fetcher(),
            100,
            $this->budget(),
            CosmwasmDiscoveryGate::CODE_TAIL_MAX_PAGES
        );

        self::assertTrue($tail['reached_watermark'], '99 <= 100 ends the walk');
        self::assertSame(900, $tail['newest_code_id']);
        self::assertSame(3, $tail['ingested'], 'the page is ingested FULLY before the stop decision');
    }

    public function testTailWalkStopsAtTheEndOfAShortListing(): void
    {
        // Never meets the watermark because the chain simply has fewer
        // codes than the watermark claims — but the listing ended, so we
        // HAVE seen everything. That is authoritative.
        $this->queueJson([
            'code_infos' => [['code_id' => '5', 'data_hash' => 'AA']],
            'pagination' => ['next_key' => null],
        ]);

        $tail = CosmwasmDiscoveryService::ingestNewCodeFamilies(
            self::CHAIN_ID,
            $this->fetcher(),
            9999,
            $this->budget(),
            CosmwasmDiscoveryGate::CODE_TAIL_MAX_PAGES
        );

        self::assertTrue($tail['reached_watermark']);
        self::assertFalse($tail['anomaly']);
    }

    // ── (b) classification ──────────────────────────────────────────────

    public function testProbableCw721IsIdentified(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 434);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 434);
        self::assertNotNull($family);

        // contracts page, then: num_tokens OK, both info variants refused.
        $this->queueJson(['contracts' => [self::CONTRACT_A], 'pagination' => []]);
        $this->queueJson(['data' => ['count' => 42]]);
        $this->queueWasmError(self::MSG_PARSE);
        $this->queueWasmError(self::MSG_PARSE);

        $result = CosmwasmDiscoveryService::classifyFamily(
            self::CHAIN_ID,
            $this->fetcher(),
            $family,
            $this->budget()
        );

        self::assertSame(CosmwasmClassifier::PROBABLE, $result['classification']);
    }

    public function testNonNftFamilySettlesWithoutScanningAllItsContracts(): void
    {
        // Jackal code 4: 21 contracts, a bindings family. It must settle
        // after the sample, never by walking all 21.
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 4);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 4);
        self::assertNotNull($family);

        $contracts = [];
        for ($i = 0; $i < 21; $i++) {
            $contracts[] = 'cosmos1' . str_pad((string) $i, 59, 'x', STR_PAD_LEFT);
        }
        $this->queueJson(['contracts' => $contracts, 'pagination' => []]);
        for ($i = 0; $i < CosmwasmDiscoveryGate::FAMILY_SAMPLE_SIZE; $i++) {
            $this->queueRefusedProbe();
        }

        $result = CosmwasmDiscoveryService::classifyFamily(
            self::CHAIN_ID,
            $this->fetcher(),
            $family,
            $this->budget()
        );

        self::assertSame(CosmwasmClassifier::NOT_CW721, $result['classification']);

        // ONE contracts page + 3 samples x 3 probes = 10 requests. NOT 21
        // contracts' worth of probing.
        self::assertCount(10, ApiRetry::$calls);
        $probed = 0;
        foreach (CosmwasmContractRepository::$classifications as $entry) {
            $probed++;
        }
        self::assertSame(CosmwasmDiscoveryGate::FAMILY_SAMPLE_SIZE, $probed);

        // The family is CLOSED — the remaining contracts are never requested.
        self::assertSame(
            [['chain_id' => self::CHAIN_ID, 'code_id' => 4]],
            CosmwasmCodeFamilyRepository::$closed
        );
    }

    public function testNodeErrorNeverSettlesAFamilyAsNotCw721(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 1);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 1);
        self::assertNotNull($family);

        $this->queueJson(['contracts' => [self::CONTRACT_A], 'pagination' => []]);
        $this->queueWasmError(self::MSG_VM);
        $this->queueWasmError(self::MSG_VM);
        $this->queueWasmError(self::MSG_VM);

        $result = CosmwasmDiscoveryService::classifyFamily(
            self::CHAIN_ID,
            $this->fetcher(),
            $family,
            $this->budget()
        );

        self::assertSame(CosmwasmClassifier::UNREACHABLE, $result['classification']);
        self::assertSame([], CosmwasmCodeFamilyRepository::$closed);
    }

    public function testCw721FamilyContractsAreEnumerated(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 434, CosmwasmClassifier::CONFIRMED);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 434);
        self::assertNotNull($family);

        $this->queueJson([
            'contracts'  => [self::CONTRACT_A, self::CONTRACT_B],
            'pagination' => ['next_key' => null],
        ]);

        $page = CosmwasmDiscoveryService::enumerateFamilyPage(
            self::CHAIN_ID,
            $this->fetcher(),
            $family,
            $this->budget()
        );

        self::assertTrue($page['ok']);
        self::assertSame(2, $page['seen']);
        self::assertSame(2, $page['fresh']);
        self::assertTrue($page['complete']);
        self::assertSame(2, CosmwasmContractRepository::countForChain(self::CHAIN_ID));
    }

    public function testPreviouslySeenContractsAreNotReprocessed(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 434, CosmwasmClassifier::CONFIRMED);
        CosmwasmContractRepository::seed(self::CHAIN_ID, self::CONTRACT_A, CosmwasmClassifier::NOT_CW721, 434, false, false, CosmwasmClassifier::VERSION, '2026-08-01 00:00:00');
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 434);
        self::assertNotNull($family);

        $this->queueJson([
            'contracts'  => [self::CONTRACT_A, self::CONTRACT_B],
            'pagination' => ['next_key' => null],
        ]);

        $page = CosmwasmDiscoveryService::enumerateFamilyPage(
            self::CHAIN_ID,
            $this->fetcher(),
            $family,
            $this->budget()
        );

        self::assertSame(2, $page['seen']);
        self::assertSame(1, $page['fresh'], 'only CONTRACT_B is new');

        // The already-settled contract keeps its verdict and stays out of
        // the work queue.
        $pending = CosmwasmContractRepository::findPendingClassification(
            self::CHAIN_ID,
            10,
            CosmwasmClassifier::VERSION
        );
        $pendingAddresses = array_map(static fn(object $r): string => (string) $r->contract_address, $pending);
        self::assertNotContains(strtolower(self::CONTRACT_A), $pendingAddresses);
        self::assertContains(strtolower(self::CONTRACT_B), $pendingAddresses);
    }

    public function testNewContractsUnderAnOldCodeIdAreDiscovered(): void
    {
        // A previously-DRAINED family gains a new instantiation. The tail
        // walk is REVERSE (newest-first) and stops at the first address we
        // already hold — never `pagination.offset`, which returns an empty
        // 200 on four of the five chains measured.
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 434, CosmwasmClassifier::CONFIRMED);
        CosmwasmContractRepository::seed(self::CHAIN_ID, self::CONTRACT_A, CosmwasmClassifier::CONFIRMED, 434);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 434);
        self::assertNotNull($family);
        $family->contracts_enumerated = '100';
        $family->enumeration_complete = '1';

        // Newest first: the new one, then one we already have (the stop).
        $this->queueJson([
            'contracts'  => [self::CONTRACT_C, self::CONTRACT_A],
            'pagination' => ['next_key' => 'MORE=='],
        ]);

        $tail = CosmwasmDiscoveryService::enumerateFamilyTail(
            self::CHAIN_ID,
            $this->fetcher(),
            $family,
            $this->budget(),
            CosmwasmDiscoveryGate::CONTRACT_TAIL_MAX_PAGES
        );

        self::assertTrue($tail['ok']);
        self::assertSame(1, $tail['fresh']);
        self::assertTrue($tail['reached_known'], 'meeting a known address is the stop condition');
        self::assertSame(1, $tail['pages'], 'one request — it stopped at the known address');
        self::assertNotNull(CosmwasmContractRepository::find(self::CHAIN_ID, self::CONTRACT_C));

        $url = $this->urls()[0];
        self::assertStringContainsString('pagination.reverse=true', $url);
        self::assertStringNotContainsString('pagination.offset', $url);
    }

    public function testContractTailEmptyPageIsNotTreatedAsNothingNew(): void
    {
        // A DRAINED family with 100 recorded contracts CANNOT genuinely
        // return an empty listing. Concluding "no new contracts" here is
        // the same fake-healthy failure the offset bug had.
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 434, CosmwasmClassifier::CONFIRMED);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 434);
        self::assertNotNull($family);
        $family->contracts_enumerated = '100';
        $family->enumeration_complete = '1';

        $this->queueJson(['contracts' => [], 'pagination' => ['next_key' => null]]);

        $tail = CosmwasmDiscoveryService::enumerateFamilyTail(
            self::CHAIN_ID,
            $this->fetcher(),
            $family,
            $this->budget(),
            CosmwasmDiscoveryGate::CONTRACT_TAIL_MAX_PAGES
        );

        self::assertTrue($tail['anomaly']);
        self::assertFalse($tail['reached_known']);
        // The family is REOPENED for a forward re-walk rather than left
        // looking complete-and-healthy.
        self::assertSame('0', (string) $family->enumeration_complete);
        self::assertNull($family->contracts_cursor);
    }

    public function testContractTailEmptyPageOnAnEmptyFamilyIsFine(): void
    {
        // Nothing recorded yet, so an empty listing is the truth.
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 434, CosmwasmClassifier::CONFIRMED);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 434);
        self::assertNotNull($family);
        $family->enumeration_complete = '1';

        $this->queueJson(['contracts' => [], 'pagination' => ['next_key' => null]]);

        $tail = CosmwasmDiscoveryService::enumerateFamilyTail(
            self::CHAIN_ID,
            $this->fetcher(),
            $family,
            $this->budget(),
            CosmwasmDiscoveryGate::CONTRACT_TAIL_MAX_PAGES
        );

        self::assertFalse($tail['anomaly']);
        self::assertTrue($tail['reached_known']);
    }

    public function testContractTailThatNeverMeetsAKnownAddressReopensTheForwardWalk(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 434, CosmwasmClassifier::CONFIRMED);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 434);
        self::assertNotNull($family);
        $family->contracts_enumerated = '100';
        $family->enumeration_complete = '1';

        // Every page is all-new and reports more after it.
        for ($i = 0; $i < 4; $i++) {
            $this->queueJson([
                'contracts'  => ['cosmos1' . str_pad((string) $i, 59, 'z', STR_PAD_LEFT)],
                'pagination' => ['next_key' => 'P' . $i],
            ]);
        }

        $tail = CosmwasmDiscoveryService::enumerateFamilyTail(
            self::CHAIN_ID,
            $this->fetcher(),
            $family,
            $this->budget(),
            CosmwasmDiscoveryGate::CONTRACT_TAIL_MAX_PAGES
        );

        self::assertFalse($tail['reached_known']);
        self::assertSame(CosmwasmDiscoveryGate::CONTRACT_TAIL_MAX_PAGES, $tail['pages']);
        // Handed back to the forward walk, which is built for volume.
        self::assertSame('0', (string) $family->enumeration_complete);
    }

    public function testStaleContractCursorRestartsInsteadOfConcludingDone(): void
    {
        // The cursor was minted by a different node behind
        // rest.cosmos.directory's round-robin. An empty FINAL page on a
        // resumed read must not be read as "family drained".
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 434, CosmwasmClassifier::CONFIRMED);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 434);
        self::assertNotNull($family);
        $family->contracts_cursor     = 'STALE==';
        $family->contracts_enumerated = '250';

        $this->queueJson(['contracts' => [], 'pagination' => ['next_key' => null]]);

        $page = CosmwasmDiscoveryService::enumerateFamilyPage(
            self::CHAIN_ID,
            $this->fetcher(),
            $family,
            $this->budget()
        );

        self::assertTrue($page['restarted']);
        self::assertFalse($page['complete'], 'an empty resumed page is NOT proof the walk finished');
        self::assertNull($family->contracts_cursor, 'the poisoned key is dropped');
        self::assertSame('0', (string) $family->enumeration_complete);
        // The recorded position is preserved, so nothing already learned is lost.
        self::assertSame('250', (string) $family->contracts_enumerated);
    }

    public function testRejectedContractCursorAlsoRestartsCleanly(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 434, CosmwasmClassifier::CONFIRMED);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 434);
        self::assertNotNull($family);
        $family->contracts_cursor     = 'STALE==';
        $family->contracts_enumerated = '250';

        // Another node rejects the key outright.
        $this->queueJson(['code' => 3, 'message' => 'invalid request: invalid pagination key'], 400);

        $page = CosmwasmDiscoveryService::enumerateFamilyPage(
            self::CHAIN_ID,
            $this->fetcher(),
            $family,
            $this->budget()
        );

        self::assertTrue($page['restarted']);
        self::assertNull($family->contracts_cursor);
    }

    public function testKnownCw721FamiliesAreCheckedForNewContracts(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 434, CosmwasmClassifier::CONFIRMED);
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 3, CosmwasmClassifier::NOT_CW721);

        $enumerable = CosmwasmCodeFamilyRepository::findEnumerable(self::CHAIN_ID, 10, false);
        $codeIds    = array_map(static fn(object $r): int => (int) $r->code_id, $enumerable);

        self::assertSame([434], $codeIds, 'settled non-NFT families are never re-walked');
    }

    // ── (c) checksum reuse: what it may and may not do ──────────────────

    public function testChecksumTwinSettlesAFamilyWithZeroRequests(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 100, CosmwasmClassifier::NOT_CW721, 'deadbeef', CosmwasmClassifier::VERSION, '2026-08-01 00:00:00');
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 200, CosmwasmClassifier::INCONCLUSIVE, 'deadbeef');
        $twin = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 200);
        self::assertNotNull($twin);

        $result = CosmwasmDiscoveryService::classifyFamily(
            self::CHAIN_ID,
            $this->fetcher(),
            $twin,
            $this->budget()
        );

        self::assertSame(CosmwasmClassifier::NOT_CW721, $result['classification']);
        self::assertSame(0, $result['requests']);
        self::assertSame([], ApiRetry::$calls, 'an identical binary needs no probe');
    }

    public function testChecksumTwinNeverVerifiesOrCopiesCollectionMetadata(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 100, CosmwasmClassifier::CONFIRMED, 'cafe', CosmwasmClassifier::VERSION, '2026-08-01 00:00:00');
        $source = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 100);
        self::assertNotNull($source);
        $source->sample_contract = strtolower(self::CONTRACT_A);

        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 200, CosmwasmClassifier::INCONCLUSIVE, 'cafe');
        $twin = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 200);
        self::assertNotNull($twin);

        CosmwasmDiscoveryService::classifyFamily(self::CHAIN_ID, $this->fetcher(), $twin, $this->budget());

        // Classification inherited; IDENTITY and METADATA did not.
        self::assertSame(CosmwasmClassifier::CONFIRMED, (string) $twin->classification);
        self::assertNull($twin->sample_contract, 'the twin sample must NOT be inherited');
        self::assertSame([], CollectionRepository::$upserted, 'inheritance cannot create a collection');
        self::assertSame([], CosmwasmContractRepository::$classifications, 'no per-contract verdict is inherited');
        self::assertStringStartsWith('checksum_twin:', (string) $twin->classification_reason);
    }

    public function testChecksumTwinDoesNotBypassPerContractLivenessOrDeny(): void
    {
        // Family B inherits CONFIRMED from family A by checksum. Its own
        // contracts must STILL be probed individually and STILL be
        // deny-filtered.
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 100, CosmwasmClassifier::CONFIRMED, 'beef', CosmwasmClassifier::VERSION, '2026-08-01 00:00:00');
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 200, CosmwasmClassifier::INCONCLUSIVE, 'beef');
        $twin = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 200);
        self::assertNotNull($twin);

        NftSpamContractRepository::addRule(self::CHAIN_ID, self::CONTRACT_B, NftSpamContractRepository::RULE_DENY);

        CosmwasmDiscoveryService::classifyFamily(self::CHAIN_ID, $this->fetcher(), $twin, $this->budget());

        // Now enumerate the inheriting family's contracts.
        $this->queueJson([
            'contracts'  => [self::CONTRACT_A, self::CONTRACT_B],
            'pagination' => ['next_key' => null],
        ]);
        CosmwasmDiscoveryService::enumerateFamilyPage(
            self::CHAIN_ID,
            $this->fetcher(),
            $twin,
            $this->budget(),
            false
        );

        $denied = CosmwasmContractRepository::find(self::CHAIN_ID, self::CONTRACT_B);
        self::assertNotNull($denied);
        self::assertSame('1', (string) $denied->denied, 'the deny rule is NOT bypassed by inheritance');

        // Neither contract is confirmed by inheritance — both still need
        // their own probe.
        $pending = CosmwasmContractRepository::findPendingClassification(self::CHAIN_ID, 10, CosmwasmClassifier::VERSION);
        $pendingAddresses = array_map(static fn(object $r): string => (string) $r->contract_address, $pending);
        self::assertContains(strtolower(self::CONTRACT_A), $pendingAddresses);
        self::assertNotContains(strtolower(self::CONTRACT_B), $pendingAddresses, 'denied rows never enter the work queue');
    }

    // ── (d) the deny-rule fix ───────────────────────────────────────────

    public function testDenyPreventsReinsertionAndUserFacingRediscovery(): void
    {
        // THE BUG: the old path filtered on COLLECTION-ROW PRESENCE only,
        // so a DENIED contract whose row had been deleted looked "new" and
        // was re-inserted on every sweep.
        NftSpamContractRepository::addRule(self::CHAIN_ID, self::CONTRACT_A, NftSpamContractRepository::RULE_DENY);
        self::assertSame([], CollectionRepository::$knownByChain, 'no collection row exists — the old filter would pass');

        CosmwasmDiscoveryService::recordContracts(self::CHAIN_ID, 434, [self::CONTRACT_A, self::CONTRACT_B]);

        $denied = CosmwasmContractRepository::find(self::CHAIN_ID, self::CONTRACT_A);
        self::assertNotNull($denied);
        self::assertSame('1', (string) $denied->denied);

        // Even if something classifies it CW-721, it can never be emitted.
        CosmwasmContractRepository::seed(self::CHAIN_ID, self::CONTRACT_A, CosmwasmClassifier::CONFIRMED, 434, true);
        $emit = CosmwasmDiscoveryService::emitCollections(self::CHAIN_ID, $this->fetcher(), $this->budget(), 10);

        self::assertSame(0, $emit['emitted']);
        self::assertSame([], CollectionRepository::$upserted);
    }

    public function testDeniedContractIsNotRepeatedlyLoggedAsANewCandidate(): void
    {
        NftSpamContractRepository::addRule(self::CHAIN_ID, self::CONTRACT_A, NftSpamContractRepository::RULE_DENY);

        $first  = CosmwasmDiscoveryService::recordContracts(self::CHAIN_ID, 434, [self::CONTRACT_A]);
        $second = CosmwasmDiscoveryService::recordContracts(self::CHAIN_ID, 434, [self::CONTRACT_A]);
        $third  = CosmwasmDiscoveryService::recordContracts(self::CHAIN_ID, 434, [self::CONTRACT_A]);

        self::assertSame(1, $first, 'genuinely new the first time');
        self::assertSame(0, $second, 'and never "new" again');
        self::assertSame(0, $third);
    }

    public function testDenyRuleLandingBetweenSweepsStillBlocksTheEmit(): void
    {
        // Inventoried BEFORE the rule existed, so `denied` is 0 on the row.
        // The emit path re-checks the LIVE rule; that is why deny is
        // enforced twice.
        CosmwasmContractRepository::seed(self::CHAIN_ID, self::CONTRACT_A, CosmwasmClassifier::CONFIRMED, 434);
        NftSpamContractRepository::addRule(self::CHAIN_ID, self::CONTRACT_A, NftSpamContractRepository::RULE_DENY);

        $emit = CosmwasmDiscoveryService::emitCollections(self::CHAIN_ID, $this->fetcher(), $this->budget(), 10);

        self::assertSame(0, $emit['emitted']);
        self::assertSame(1, $emit['denied']);
        self::assertSame([], CollectionRepository::$upserted);
        // The cached flag is corrected so later sweeps skip it earlier.
        $row = CosmwasmContractRepository::find(self::CHAIN_ID, self::CONTRACT_A);
        self::assertNotNull($row);
        self::assertSame('1', (string) $row->denied);
    }

    public function testExistingDenyRulesAreRespectedDuringTheHistoricalBackfill(): void
    {
        NftSpamContractRepository::addRule(self::CHAIN_ID, self::CONTRACT_B, NftSpamContractRepository::RULE_DENY);
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 434, CosmwasmClassifier::CONFIRMED);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 434);
        self::assertNotNull($family);

        $this->queueJson([
            'contracts'  => [self::CONTRACT_A, self::CONTRACT_B],
            'pagination' => ['next_key' => null],
        ]);

        CosmwasmDiscoveryService::enumerateFamilyPage(
            self::CHAIN_ID,
            $this->fetcher(),
            $family,
            $this->budget()
        );

        $allowed = CosmwasmContractRepository::find(self::CHAIN_ID, self::CONTRACT_A);
        $denied  = CosmwasmContractRepository::find(self::CHAIN_ID, self::CONTRACT_B);
        self::assertNotNull($allowed);
        self::assertNotNull($denied);
        self::assertSame('0', (string) $allowed->denied);
        self::assertSame('1', (string) $denied->denied);
    }

    public function testAllowOverridesNameHeuristicsButNeverAutoVerifies(): void
    {
        // "Claim your free airdrop" trips the default heuristics; an
        // explicit operator ALLOW beats them.
        CosmwasmContractRepository::seed(self::CHAIN_ID, self::CONTRACT_A, CosmwasmClassifier::CONFIRMED, 434);
        NftSpamContractRepository::addRule(self::CHAIN_ID, self::CONTRACT_A, NftSpamContractRepository::RULE_ALLOW);

        $this->queueJson(['data' => ['name' => 'Claim your free airdrop', 'symbol' => 'SPAM']]);

        $emit = CosmwasmDiscoveryService::emitCollections(self::CHAIN_ID, $this->fetcher(), $this->budget(), 10);

        self::assertSame(1, $emit['emitted']);
        self::assertCount(1, CollectionRepository::$upserted);
        // ALLOW is not verification: the row lands unverified and nothing
        // was provisioned.
        self::assertArrayNotHasKey('is_verified', CollectionRepository::$upserted[0]);
        self::assertSame(0, (int) (CollectionRepository::$knownByChain[self::CHAIN_ID][0]->is_verified ?? 1));
    }

    public function testNameHeuristicsStillApplyWithoutAnExplicitRule(): void
    {
        CosmwasmContractRepository::seed(self::CHAIN_ID, self::CONTRACT_A, CosmwasmClassifier::CONFIRMED, 434);

        $this->queueJson(['data' => ['name' => 'Claim your free airdrop', 'symbol' => 'SPAM']]);

        $emit = CosmwasmDiscoveryService::emitCollections(self::CHAIN_ID, $this->fetcher(), $this->budget(), 10);

        self::assertSame(0, $emit['emitted']);
        self::assertSame(1, $emit['denied']);
    }

    public function testHidingSurvivesDeletionAndUnhidingPermitsLaterDiscovery(): void
    {
        // Hide → RULE_DENY. The collection row is then deleted.
        NftSpamContractRepository::addRule(self::CHAIN_ID, self::CONTRACT_A, NftSpamContractRepository::RULE_DENY);
        CosmwasmContractRepository::seed(self::CHAIN_ID, self::CONTRACT_A, CosmwasmClassifier::CONFIRMED, 434);
        CosmwasmDiscoveryService::syncDenyFlags(self::CHAIN_ID, [self::CONTRACT_A]);

        self::assertSame([], CosmwasmContractRepository::findEmittable(self::CHAIN_ID, 10), 'hidden stays hidden across rediscovery');

        // Unhide → RULE_ALLOW. Discovery is PERMITTED again.
        NftSpamContractRepository::addRule(self::CHAIN_ID, self::CONTRACT_A, NftSpamContractRepository::RULE_ALLOW);
        CosmwasmDiscoveryService::syncDenyFlags(self::CHAIN_ID, [self::CONTRACT_A]);

        $emittable = CosmwasmContractRepository::findEmittable(self::CHAIN_ID, 10);
        self::assertCount(1, $emittable);
    }

    // ── (e) discovery cannot verify or provision ────────────────────────

    public function testNewCollectionsAreStoredUnverifiedAndDiscoveryCannotProvision(): void
    {
        CosmwasmContractRepository::seed(self::CHAIN_ID, self::CONTRACT_A, CosmwasmClassifier::CONFIRMED, 434);
        $this->queueJson(['data' => ['name' => 'Discovered Collection', 'symbol' => 'DISC']]);

        CosmwasmDiscoveryService::emitCollections(self::CHAIN_ID, $this->fetcher(), $this->budget(), 10);

        self::assertCount(1, CollectionRepository::$upserted);
        $row = CollectionRepository::$upserted[0];
        self::assertSame('Discovered Collection', $row['collection_name']);
        self::assertSame('CW-721', $row['token_standard']);
        // Discovery emits no verification flag at all — the schema default
        // (0) is what the row gets.
        self::assertArrayNotHasKey('is_verified', $row);
        self::assertArrayNotHasKey('post_id', $row);
    }

    public function testExistingVerifiedCollectionsStayVerifiedAndAreNotClobbered(): void
    {
        // A curated, VERIFIED row with a name and artwork. bulkUpsert
        // assigns collection_name/image_url from VALUES(), so re-emitting
        // would wipe the artwork.
        CollectionRepository::$knownByChain[self::CHAIN_ID][] = (object) [
            'contract_address' => strtolower(self::CONTRACT_A),
            'collection_name'  => 'Bad Kids',
            'image_url'        => 'https://cdn.example/bk.png',
            'is_verified'      => 1,
        ];
        CosmwasmContractRepository::seed(self::CHAIN_ID, self::CONTRACT_A, CosmwasmClassifier::CONFIRMED, 434);

        $emit = CosmwasmDiscoveryService::emitCollections(self::CHAIN_ID, $this->fetcher(), $this->budget(), 10);

        self::assertSame(0, $emit['emitted']);
        self::assertSame(1, $emit['skipped_known']);
        self::assertSame([], CollectionRepository::$upserted);
        self::assertSame(1, (int) CollectionRepository::$knownByChain[self::CHAIN_ID][0]->is_verified);
        self::assertSame('Bad Kids', CollectionRepository::$knownByChain[self::CHAIN_ID][0]->collection_name);
    }

    public function testNoDuplicateCollectionsOnRepeatedPasses(): void
    {
        CosmwasmContractRepository::seed(self::CHAIN_ID, self::CONTRACT_A, CosmwasmClassifier::CONFIRMED, 434);
        $this->queueJson(['data' => ['name' => 'Once', 'symbol' => 'ONCE']]);

        CosmwasmDiscoveryService::emitCollections(self::CHAIN_ID, $this->fetcher(), $this->budget(), 10);
        CosmwasmDiscoveryService::emitCollections(self::CHAIN_ID, $this->fetcher(), $this->budget(), 10);
        CosmwasmDiscoveryService::emitCollections(self::CHAIN_ID, $this->fetcher(), $this->budget(), 10);

        self::assertCount(1, CollectionRepository::$upserted);
    }

    public function testContractMigrationIsRecordedWithoutDuplicatingTheCollection(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 434, CosmwasmClassifier::CONFIRMED);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 434);
        self::assertNotNull($family);
        $family->sample_contract = strtolower(self::CONTRACT_A);
        CosmwasmContractRepository::seed(self::CHAIN_ID, self::CONTRACT_A, CosmwasmClassifier::CONFIRMED, 434, false, true);

        $this->queueJson(['contract_info' => ['code_id' => '999', 'label' => 'migrated']]);

        $result = CosmwasmDiscoveryService::checkFamilyMigration(
            self::CHAIN_ID,
            $this->fetcher(),
            $family,
            $this->budget()
        );

        self::assertTrue($result['migrated']);
        self::assertCount(1, CosmwasmContractRepository::$migrations);
        self::assertSame(999, CosmwasmContractRepository::$migrations[0]['code_id']);
        // ONE contract row, ONE (already-written) collection. No duplicate.
        self::assertSame(1, CosmwasmContractRepository::countForChain(self::CHAIN_ID));
        self::assertSame([], CollectionRepository::$upserted);
    }

    // ── (f) budgets, resumption, concurrency, isolation ─────────────────

    public function testBackfillStopsAtItsBudget(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);

        // Every page reports another page after it — an unbounded walk
        // without the budget.
        for ($i = 0; $i < 20; $i++) {
            $this->queueJson([
                'code_infos' => [['code_id' => (string) ($i + 1), 'data_hash' => 'H' . $i]],
                'pagination' => ['next_key' => 'PAGE' . ($i + 1)],
            ]);
        }

        CosmwasmDiscoveryWorker::runBackfillForChain(self::CHAIN_ID, new CosmwasmTickBudget(3, 60));

        self::assertCount(3, ApiRetry::$calls, 'exactly the request budget, then stop');
    }

    public function testBackfillResumesFromDurableProgress(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);

        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_code_cursor     = 'RESUME==';
        ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_discovery_state = ChainCheckpointRepository::CW_STATE_BACKFILLING;

        $this->queueJson([
            'code_infos' => [['code_id' => '50', 'data_hash' => 'AA']],
            'pagination' => ['next_key' => null],
        ]);

        CosmwasmDiscoveryWorker::runBackfillForChain(self::CHAIN_ID, new CosmwasmTickBudget(1, 60));

        self::assertStringContainsString('pagination.key=RESUME%3D%3D', $this->urls()[0]);
        // Safe progress is durable and the walk is now complete.
        self::assertNotSame([], ChainCheckpointRepository::$codeProgress);
        $last = end(ChainCheckpointRepository::$codeProgress);
        self::assertTrue($last['complete']);
        self::assertSame(
            ChainCheckpointRepository::CW_STATE_BACKFILLED,
            (string) ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_discovery_state
        );
    }

    public function testBackfillResumptionWithAStaleCursorRestartsCleanly(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);

        // Opaque `pagination.key`s are minted BY A NODE, and
        // rest.cosmos.directory round-robins across nodes — the peer that
        // answers next may not understand this key. Returning an empty
        // FINAL page is indistinguishable on the wire from "walk complete",
        // so accepting it would silently truncate the inventory.
        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_code_cursor     = 'STALE==';
        ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_max_code_id     = '400';
        ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_discovery_state = ChainCheckpointRepository::CW_STATE_BACKFILLING;

        $this->queueJson(['code_infos' => [], 'pagination' => ['next_key' => null]]);

        CosmwasmDiscoveryWorker::runBackfillForChain(self::CHAIN_ID, $this->budget());

        // NOT marked complete.
        self::assertNotSame(
            ChainCheckpointRepository::CW_STATE_BACKFILLED,
            (string) ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_discovery_state
        );
        self::assertNotSame([], ChainCheckpointRepository::$backfillRestarts);
        // The poisoned key is dropped so the next tick restarts from the
        // beginning — cheap and safe under `uk_chain_code`.
        self::assertNull(ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_code_cursor);
        self::assertStringContainsString(
            'empty final page',
            (string) ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_last_error
        );
    }

    public function testBackfillResumptionWithARejectedCursorRestartsCleanly(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);

        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_code_cursor     = 'STALE==';
        ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_discovery_state = ChainCheckpointRepository::CW_STATE_BACKFILLING;

        // A peer that rejects another node's key outright.
        $this->queueJson(['code' => 3, 'message' => 'invalid request: invalid pagination key'], 400);

        CosmwasmDiscoveryWorker::runBackfillForChain(self::CHAIN_ID, $this->budget());

        self::assertNull(ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_code_cursor);
        self::assertStringContainsString(
            'stale pagination cursor',
            (string) ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_last_error
        );
    }

    public function testAFreshWalkIsNotRestartedJustBecauseTheChainIsEmpty(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);

        // No stored cursor → not a resumption → an empty final page is the
        // honest answer for a chain with no code ids.
        $this->queueJson(['code_infos' => [], 'pagination' => ['next_key' => null]]);

        CosmwasmDiscoveryWorker::runBackfillForChain(self::CHAIN_ID, $this->budget());

        self::assertSame([], ChainCheckpointRepository::$backfillRestarts);
        self::assertSame(
            ChainCheckpointRepository::CW_STATE_BACKFILLED,
            (string) ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_discovery_state
        );
    }

    public function testSafeProgressIsWrittenBeforeTheDeadlineCanBite(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);

        $this->queueJson([
            'code_infos' => [['code_id' => '1', 'data_hash' => 'AA']],
            'pagination' => ['next_key' => 'MORE=='],
        ]);

        CosmwasmDiscoveryWorker::runBackfillForChain(self::CHAIN_ID, new CosmwasmTickBudget(1, 60));

        $last = end(ChainCheckpointRepository::$codeProgress);
        self::assertIsArray($last);
        self::assertSame('MORE==', $last['cursor']);
        self::assertFalse($last['complete']);
    }

    public function testOverlappingWorkersCannotCorruptCursorState(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);

        // A peer holds the per-chain advisory lock.
        \BCC\Core\DB\AdvisoryLock::$acquirable = false;

        $this->queueJson(['code_infos' => [['code_id' => '1', 'data_hash' => 'AA']], 'pagination' => []]);

        CosmwasmDiscoveryWorker::runBackfillForChain(self::CHAIN_ID, $this->budget());

        self::assertSame([], ApiRetry::$calls, 'the second invocation does no work at all');
        self::assertSame([], ChainCheckpointRepository::$codeProgress, 'and writes no cursor');
    }

    public function testOneBrokenChainDoesNotStopTheOthers(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);

        ChainRepository::seed(self::CHAIN_B, 'osmosis', 'https://osmo.example');

        // Chain 8's first call explodes; chain 9's answers.
        ApiRetry::$queue[] = new \WP_Error('lcd down');
        $this->queueJson(['code_infos' => [['code_id' => '7', 'data_hash' => 'BB']], 'pagination' => []]);

        CosmwasmDiscoveryWorker::runDailyDiscovery();

        // Both chains were attempted, and both got their last-run stamp so
        // neither starves the other next tick.
        self::assertContains(self::CHAIN_ID, ChainCheckpointRepository::$discoveryTouches);
        self::assertContains(self::CHAIN_B, ChainCheckpointRepository::$discoveryTouches);
        self::assertSame(7, (int) ChainCheckpointRepository::$rows[self::CHAIN_B]->cw_max_code_id);
    }

    public function testTimeoutsAndMalformedResponsesDoNotCrashTheWorker(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);

        ApiRetry::$queue[] = new \WP_Error('cURL error 28: Operation timed out');
        ApiRetry::$queue[] = ['code' => 200, 'body' => 'not json at all <html>'];
        ApiRetry::$queue[] = ['code' => 200, 'body' => '{"unexpected":"shape"}'];

        CosmwasmDiscoveryWorker::runDailyDiscovery();
        CosmwasmDiscoveryWorker::runWeeklyRetry();

        self::assertTrue(true, 'no fatal, no exception escaped the worker');
    }

    public function testChainWithNoWasmModuleIsDurablySkipped(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);

        // cryptoorgchain answers the wasmd endpoints with 501 (measured).
        ApiRetry::$queue[] = ['code' => 501, 'body' => '{"code":12,"message":"Not Implemented"}'];

        CosmwasmDiscoveryWorker::runBackfillForChain(self::CHAIN_ID, $this->budget());

        self::assertSame(
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
            (string) ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_discovery_state
        );

        // A later tick spends nothing on it.
        ApiRetry::reset();
        CosmwasmDiscoveryWorker::runBackfillForChain(self::CHAIN_ID, $this->budget());
        self::assertSame([], ApiRetry::$calls);
    }

    public function testUnreachableLcdIsNotTreatedAsUnsupported(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);

        // kujira's LCD currently answers 502 (measured). That is a DOWN
        // node, not a chain without a wasm module.
        ApiRetry::$queue[] = ['code' => 502, 'body' => '<html>Bad Gateway</html>'];

        CosmwasmDiscoveryWorker::runBackfillForChain(self::CHAIN_ID, $this->budget());

        self::assertNotSame(
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
            (string) ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_discovery_state
        );
        self::assertSame(
            ChainCheckpointRepository::CW_STATE_BACKFILLING,
            (string) ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_discovery_state
        );
    }

    public function testOperatorPauseStopsAChain(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);

        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        ChainCheckpointRepository::$rows[self::CHAIN_ID]->cw_discovery_state = ChainCheckpointRepository::CW_STATE_PAUSED;

        CosmwasmDiscoveryWorker::runBackfillForChain(self::CHAIN_ID, $this->budget());

        self::assertSame([], ApiRetry::$calls);
    }

    public function testDuplicateResponsesAreIdempotent(): void
    {
        $page = [
            'code_infos' => [['code_id' => '1', 'data_hash' => 'AA'], ['code_id' => '2', 'data_hash' => 'BB']],
            'pagination' => ['next_key' => null],
        ];
        $this->queueJson($page);
        $this->queueJson($page);

        CosmwasmDiscoveryService::ingestCodePage(self::CHAIN_ID, $this->fetcher(), null, $this->budget());
        CosmwasmDiscoveryService::ingestCodePage(self::CHAIN_ID, $this->fetcher(), null, $this->budget());

        self::assertSame(2, CosmwasmCodeFamilyRepository::countForChain(self::CHAIN_ID));

        $contracts = [self::CONTRACT_A, self::CONTRACT_A, self::CONTRACT_B];
        CosmwasmDiscoveryService::recordContracts(self::CHAIN_ID, 1, $contracts);
        CosmwasmDiscoveryService::recordContracts(self::CHAIN_ID, 1, $contracts);

        self::assertSame(2, CosmwasmContractRepository::countForChain(self::CHAIN_ID));
    }

    public function testADuplicatePageNeverOverwritesASettledVerdict(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 1, CosmwasmClassifier::NOT_CW721, 'AA', CosmwasmClassifier::VERSION, '2026-08-01 00:00:00');

        $this->queueJson([
            'code_infos' => [['code_id' => '1', 'data_hash' => 'aa']],
            'pagination' => ['next_key' => null],
        ]);
        CosmwasmDiscoveryService::ingestCodePage(self::CHAIN_ID, $this->fetcher(), null, $this->budget());

        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 1);
        self::assertNotNull($family);
        self::assertSame(CosmwasmClassifier::NOT_CW721, (string) $family->classification);
    }

    public function testPaginationWorksForBothListings(): void
    {
        // Code listing: two pages.
        $this->queueJson([
            'code_infos' => [['code_id' => '1', 'data_hash' => 'AA']],
            'pagination' => ['next_key' => 'CODEPAGE2'],
        ]);
        $first = CosmwasmDiscoveryService::ingestCodePage(self::CHAIN_ID, $this->fetcher(), null, $this->budget());
        self::assertSame('CODEPAGE2', $first['next_key']);

        $this->queueJson([
            'code_infos' => [['code_id' => '2', 'data_hash' => 'BB']],
            'pagination' => ['next_key' => null],
        ]);
        $second = CosmwasmDiscoveryService::ingestCodePage(self::CHAIN_ID, $this->fetcher(), 'CODEPAGE2', $this->budget());
        self::assertNull($second['next_key']);
        self::assertStringContainsString('pagination.key=CODEPAGE2', $this->urls()[1]);

        // Contract listing: two pages.
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 434, CosmwasmClassifier::CONFIRMED);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 434);
        self::assertNotNull($family);

        $this->queueJson(['contracts' => [self::CONTRACT_A], 'pagination' => ['next_key' => 'CPAGE2']]);
        $p1 = CosmwasmDiscoveryService::enumerateFamilyPage(self::CHAIN_ID, $this->fetcher(), $family, $this->budget());
        self::assertSame('CPAGE2', $p1['next_key']);
        self::assertFalse($p1['complete']);

        $this->queueJson(['contracts' => [self::CONTRACT_B], 'pagination' => ['next_key' => '']]);
        $p2 = CosmwasmDiscoveryService::enumerateFamilyPage(self::CHAIN_ID, $this->fetcher(), $family, $this->budget());
        self::assertNull($p2['next_key']);
        self::assertTrue($p2['complete'], 'an empty-string next_key means last page');
    }

    // ── (g) classifier-version requeue ──────────────────────────────────

    public function testClassifierVersionChangeRequeuesOnlyAffectedRecords(): void
    {
        $old = CosmwasmClassifier::VERSION - 1;

        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 1, CosmwasmClassifier::NOT_CW721, null, $old, '2026-07-01 00:00:00');
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 2, CosmwasmClassifier::INCONCLUSIVE, null, $old, '2026-07-01 00:00:00');
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 3, CosmwasmClassifier::UNREACHABLE, null, $old, '2026-07-01 00:00:00');
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 4, CosmwasmClassifier::CONFIRMED, null, $old, '2026-07-01 00:00:00');

        $requeued = CosmwasmCodeFamilyRepository::requeueForClassifierVersion(
            self::CHAIN_ID,
            CosmwasmClassifier::VERSION,
            100
        );

        self::assertSame(2, $requeued, 'inconclusive + unreachable only');

        $notCw721 = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 1);
        $confirmed = CosmwasmCodeFamilyRepository::find(self::CHAIN_ID, 4);
        self::assertNotNull($notCw721);
        self::assertNotNull($confirmed);
        // Settled negatives and decided CW-721s keep their decision stamp,
        // so they are never re-picked.
        self::assertNotNull($notCw721->classified_at);
        self::assertNotNull($confirmed->classified_at);

        $pendingIds = array_map(
            static fn(object $r): int => (int) $r->code_id,
            CosmwasmCodeFamilyRepository::findPendingClassification(self::CHAIN_ID, 10, CosmwasmClassifier::VERSION)
        );
        self::assertSame([2, 3], $pendingIds);
    }

    public function testConfirmedNonNftIsNotRetriedWeekly(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);

        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 3, CosmwasmClassifier::NOT_CW721, null, CosmwasmClassifier::VERSION, '2026-08-01 00:00:00');
        CosmwasmContractRepository::seed(self::CHAIN_ID, self::CONTRACT_A, CosmwasmClassifier::NOT_CW721, 3, false, false, CosmwasmClassifier::VERSION, '2026-08-01 00:00:00');

        // Only the daily tail read of the code listing is answered; if the
        // settled rows were swept, more calls would follow.
        $this->queueJson(['code_infos' => [], 'pagination' => ['next_key' => null]]);

        CosmwasmDiscoveryWorker::runWeeklyRetry();

        self::assertSame([], CosmwasmCodeFamilyRepository::$classifications);
        self::assertSame([], CosmwasmContractRepository::$classifications);
    }

    // ── (h) configuration semantics ─────────────────────────────────────

    public function testProductionRemainsDisabledWhenTheConstantsAreUndefined(): void
    {
        self::assertFalse(defined('BCC_COSMWASM_DISCOVERY_ENABLED'));
        self::assertFalse(defined('BCC_COSMWASM_BACKFILL_ENABLED'));

        // A MISSING CONSTANT MUST NEVER MEAN ENABLED.
        self::assertFalse(CosmwasmDiscoveryGate::discoveryEnabled());
        self::assertFalse(CosmwasmDiscoveryGate::backfillEnabled());

        $this->queueJson(['code_infos' => [['code_id' => '1', 'data_hash' => 'AA']], 'pagination' => []]);

        CosmwasmDiscoveryWorker::runBackfillTick();
        CosmwasmDiscoveryWorker::runDailyDiscovery();
        CosmwasmDiscoveryWorker::runWeeklyRetry();
        CosmwasmDiscoveryWorker::runMetadataRefresh();

        self::assertSame([], ApiRetry::$calls, 'no transport at all on an environment that has not opted in');
        self::assertSame(0, CosmwasmCodeFamilyRepository::countForChain(self::CHAIN_ID));
    }

    public function testBackfillStaysDisabledWhenOnlyTheMasterGateIsDefined(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);

        self::assertTrue(CosmwasmDiscoveryGate::discoveryEnabled());
        self::assertFalse(CosmwasmDiscoveryGate::backfillEnabled(), 'the backfill needs its OWN switch');

        CosmwasmDiscoveryWorker::runBackfillTick();

        self::assertSame([], ApiRetry::$calls);
    }

    public function testMasterGateOffAlsoStopsAnEnabledBackfill(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', false);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);

        self::assertFalse(CosmwasmDiscoveryGate::backfillEnabled());

        CosmwasmDiscoveryWorker::runBackfillTick();

        self::assertSame([], ApiRetry::$calls);
    }

    public function testConfiguredCodeIdsPrioritizeButDoNotRestrict(): void
    {
        define('BCC_CW721_CODE_IDS', '{"cosmos":[434]}');

        self::assertSame([434], CosmwasmDiscoveryGate::priorityCodeIds('cosmos'));
        // A slug with no entry is NOT restricted to nothing — it simply has
        // no hints. The old semantics (an allowlist that bounded
        // discovery) are gone.
        self::assertSame([], CosmwasmDiscoveryGate::priorityCodeIds('osmosis'));

        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 100);
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 434);
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 900);

        $pending = CosmwasmCodeFamilyRepository::findPendingClassification(
            self::CHAIN_ID,
            10,
            CosmwasmClassifier::VERSION,
            CosmwasmDiscoveryGate::priorityCodeIds('cosmos')
        );
        $ids = array_map(static fn(object $r): int => (int) $r->code_id, $pending);

        self::assertSame(434, $ids[0], 'the hint reorders');
        self::assertCount(3, $ids, 'and restricts nothing');
    }

    public function testRequestBudgetOverrideIsBoundedAndDefaultsSafely(): void
    {
        self::assertSame(
            CosmwasmDiscoveryGate::DEFAULT_REQUEST_BUDGET,
            CosmwasmDiscoveryGate::requestBudget()
        );
    }

    public function testWallClockDeadlineWinsOverTheRequestBudget(): void
    {
        // 1000 requests left, zero seconds. The clock must still stop it.
        $budget = new CosmwasmTickBudget(1000, 1);
        usleep(1_100_000);

        self::assertTrue($budget->timedOut());
        self::assertTrue($budget->exhausted());
        self::assertFalse($budget->canSpend());
        self::assertSame(1000, $budget->remaining());
    }

    // ── (i) cron registration ───────────────────────────────────────────

    public function testAllFourHooksAreRegisteredAndDeclaredInTheCronSsot(): void
    {
        CosmwasmDiscoveryWorker::register();

        $hooks = [
            CosmwasmDiscoveryWorker::BACKFILL_HOOK => CosmwasmDiscoveryWorker::BACKFILL_INTERVAL,
            CosmwasmDiscoveryWorker::DAILY_HOOK    => CosmwasmDiscoveryWorker::DAILY_INTERVAL,
            CosmwasmDiscoveryWorker::WEEKLY_HOOK   => CosmwasmDiscoveryWorker::WEEKLY_INTERVAL,
            CosmwasmDiscoveryWorker::METADATA_HOOK => CosmwasmDiscoveryWorker::METADATA_INTERVAL,
        ];

        /** @var array{recurring: array<string, array{interval: string, description: string}>, cleanup_only: list<string>} $ssot */
        $ssot = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';

        foreach ($hooks as $hook => $interval) {
            self::assertArrayHasKey($hook, \BccTestCronStore::$events, "$hook must self-heal its schedule");
            self::assertSame($interval, \BccTestCronStore::$events[$hook]['interval']);
            self::assertArrayHasKey($hook, $ssot['recurring'], "$hook must be in the cron SSOT or it escapes health tracking");
            self::assertSame($interval, $ssot['recurring'][$hook]['interval']);
        }

        // wp-cron has no monthly interval — we ride daily + an elapsed guard.
        self::assertSame('daily', CosmwasmDiscoveryWorker::METADATA_INTERVAL);
        self::assertSame(30 * 86400, CosmwasmDiscoveryGate::METADATA_REFRESH_MIN_ELAPSED);
    }

    public function testRegistrationIsNotPermission(): void
    {
        // The hooks schedule themselves even with the gate undefined —
        // and the handlers still do nothing.
        CosmwasmDiscoveryWorker::register();

        self::assertNotSame([], \BccTestCronStore::$events);
        self::assertFalse(CosmwasmDiscoveryGate::discoveryEnabled());
    }

    // ── (j) the retired path is really gone ─────────────────────────────

    public function testCosmosFetcherNoLongerRunsCodeIdDiscoveryOnARequestPath(): void
    {
        // fetch_top_collections is reachable from an admin button and the
        // generic refresh sweep. No discovery work may happen there.
        $rows = $this->fetcher()->fetch_top_collections(100);

        self::assertSame([], $rows);
        self::assertSame([], ApiRetry::$calls);
    }

    public function testRetiredSamplingAndCursorApiIsGone(): void
    {
        foreach ([
            'fetchTopCollectionsViaCodeIdEnumeration',
            'cw721CodeIdsForChain',
            'cw721CodeIdOverride',
            'cw721DiscoveryEnabled',
            'cw721PageCap',
            'readScanState',
            'writeScanState',
            'resolveScanStart',
            'nextScanState',
        ] as $method) {
            self::assertFalse(
                method_exists(CosmosFetcher::class, $method),
                "CosmosFetcher::{$method}() is retired — two discovery systems must not coexist"
            );
        }
    }

    public function testInjectiveKeepsItsCuratedWhitelistPath(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', self::REST);

        ApiRetry::$queue[] = new \WP_Error('down');

        $this->fetcher()->fetch_top_collections(100);

        $url = $this->urls()[0];
        self::assertStringContainsString('/smart/', $url);
        self::assertStringNotContainsString('/cosmwasm/wasm/v1/code/', $url);
    }

    // ── (k) admin-facing summary is bounded ─────────────────────────────

    public function testChainSummaryExposesCountsAndProgressWithoutAPercentage(): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 1, CosmwasmClassifier::CONFIRMED);
        CosmwasmCodeFamilyRepository::seed(self::CHAIN_ID, 2, CosmwasmClassifier::NOT_CW721);
        CosmwasmContractRepository::seed(self::CHAIN_ID, self::CONTRACT_A, CosmwasmClassifier::CONFIRMED, 1);
        CosmwasmContractRepository::seed(self::CHAIN_ID, self::CONTRACT_B, CosmwasmClassifier::INCONCLUSIVE, 1, true);

        $summary = CosmwasmDiscoveryService::chainSummary(self::CHAIN_ID);

        self::assertSame(2, $summary['families_total']);
        self::assertSame(1, $summary['families_by_classification'][CosmwasmClassifier::CONFIRMED]);
        self::assertSame(1, $summary['families_by_classification'][CosmwasmClassifier::NOT_CW721]);
        self::assertSame(2, $summary['contracts_total']);
        self::assertSame(1, $summary['contracts_denied']);
        self::assertSame(ChainCheckpointRepository::CW_STATE_IDLE, $summary['state']);
        // Progress is a cursor + a watermark + counts. NEVER a percentage:
        // only one of the nine cosmos chains honours pagination.count_total.
        self::assertArrayNotHasKey('percent_complete', $summary);
        self::assertArrayHasKey('max_code_id', $summary);
    }
}
