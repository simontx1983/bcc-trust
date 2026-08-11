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
    private const REST     = 'https://lcd.example';

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

    public function testParsesCodeIdFromContractPayload(): void
    {
        // wasmd serialises uint64 as a JSON STRING — the cast is the point.
        $codeId = self::callStatic('parseWasmCodeId', [[
            'contract_info' => [
                'code_id' => '434',
                'creator' => 'cosmos1creator',
                'label'   => 'SG721-Bad Kids',
            ],
        ]]);

        self::assertSame(434, $codeId);
    }

    public function testParseCodeIdReturnsNullOnMissingOrFailedPayload(): void
    {
        self::assertNull(self::callStatic('parseWasmCodeId', [null]));
        self::assertNull(self::callStatic('parseWasmCodeId', [[]]));
        self::assertNull(self::callStatic('parseWasmCodeId', [['contract_info' => []]]));
        self::assertNull(self::callStatic('parseWasmCodeId', [['contract_info' => ['code_id' => '0']]]));
    }

    public function testParsesContractsPageWithNextKey(): void
    {
        $page = self::callStatic('parseContractsPage', [[
            'contracts'  => [self::CURATED, self::FRESH, ''],
            'pagination' => ['next_key' => 'CURSOR=='],
        ]]);

        self::assertSame([self::CURATED, self::FRESH], $page['contracts']);
        self::assertSame('CURSOR==', $page['next_key']);
    }

    public function testParsesLastPageWhenNextKeyAbsentOrEmpty(): void
    {
        // The LCD is inconsistent — absent, null and '' all mean "done".
        $absent = self::callStatic('parseContractsPage', [['contracts' => [self::FRESH]]]);
        self::assertSame([self::FRESH], $absent['contracts']);
        self::assertNull($absent['next_key']);

        $empty = self::callStatic('parseContractsPage', [[
            'contracts'  => [self::FRESH],
            'pagination' => ['next_key' => ''],
        ]]);
        self::assertNull($empty['next_key']);

        $nulled = self::callStatic('parseContractsPage', [[
            'contracts'  => [self::FRESH],
            'pagination' => ['next_key' => null],
        ]]);
        self::assertNull($nulled['next_key']);
    }

    // ── (b) the new code-listing parser ─────────────────────────────────

    public function testParsesCodeInfosPageWithChecksumsAndWatermark(): void
    {
        $page = self::callStatic('parseCodeInfosPage', [[
            'code_infos' => [
                ['code_id' => '434', 'data_hash' => 'ABCDEF', 'creator' => 'cosmos1x'],
                ['code_id' => '467', 'data_hash' => 'FEDCBA'],
                ['code_id' => '0',   'data_hash' => 'ZZ'],       // dropped
                ['not_a_code' => true],                          // dropped
            ],
            'pagination' => ['next_key' => 'NEXT=='],
        ]]);

        self::assertSame(
            [
                ['code_id' => 434, 'checksum' => 'abcdef'],
                ['code_id' => 467, 'checksum' => 'fedcba'],
            ],
            $page['families']
        );
        self::assertSame('NEXT==', $page['next_key']);
        // The watermark is a MAX, never a total: only one of the nine
        // cosmos chains honours pagination.count_total (measured), so a
        // reported total is not a number progress may be derived from.
        self::assertSame(467, $page['max_code_id']);
    }

    public function testCodeInfosPageReportsBothEndsOfADescendingPage(): void
    {
        // The reverse tail walk compares `min_code_id` against the stored
        // watermark, so a descending page has to report BOTH ends.
        $page = self::callStatic('parseCodeInfosPage', [[
            'code_infos' => [
                ['code_id' => '713', 'data_hash' => 'AA'],
                ['code_id' => '712', 'data_hash' => 'BB'],
                ['code_id' => '711', 'data_hash' => 'CC'],
            ],
            'pagination' => ['next_key' => 'MORE=='],
        ]]);

        self::assertSame(713, $page['max_code_id']);
        self::assertSame(711, $page['min_code_id']);
    }

    public function testReverseModeAsksForReverseAndNeverOffset(): void
    {
        // `pagination.offset` was MEASURED returning an empty 200 on
        // cosmoshub, juno, osmosis and injective (only jackal honours it).
        // It must never appear on the wire again.
        $this->queueJson(['code_infos' => [], 'pagination' => []]);
        $this->makeFetcher()->listCodeFamilies(null, true, 100);

        $url = ApiRetry::$calls[0]['url'];
        self::assertStringContainsString('pagination.reverse=true', $url);
        self::assertStringNotContainsString('pagination.offset', $url);
    }

    public function testAnOpaqueKeyWinsOverReverse(): void
    {
        // Continuation pages ride the key the node handed back; asking for
        // reverse AND a key at the same time is not a thing.
        $this->queueJson(['code_infos' => [], 'pagination' => []]);
        $this->makeFetcher()->listCodeFamilies('PAGE2==', true, 100);

        $url = ApiRetry::$calls[0]['url'];
        self::assertStringContainsString('pagination.key=PAGE2', $url);
        self::assertStringNotContainsString('pagination.reverse', $url);
    }

    public function testContractListingReverseModeNeverUsesOffset(): void
    {
        $this->queueJson(['contracts' => [], 'pagination' => []]);
        $this->makeFetcher()->listContractsForCodeId(434, null, true);

        $url = ApiRetry::$calls[0]['url'];
        self::assertStringContainsString('pagination.reverse=true', $url);
        self::assertStringNotContainsString('pagination.offset', $url);
    }

    public function testNoDiscoveryPathCanEmitAnOffsetParameter(): void
    {
        // Belt-and-braces against reintroduction: exercise every listing
        // mode and assert the parameter never appears.
        foreach ([[null, false], [null, true], ['KEY==', false], ['KEY==', true]] as [$key, $reverse]) {
            $this->queueJson(['code_infos' => [], 'pagination' => []]);
            $this->makeFetcher()->listCodeFamilies($key, $reverse, 100);

            $this->queueJson(['contracts' => [], 'pagination' => []]);
            $this->makeFetcher()->listContractsForCodeId(434, $key, $reverse);
        }

        foreach (ApiRetry::$calls as $call) {
            self::assertStringNotContainsString('pagination.offset', $call['url']);
        }
    }

    public function testCodeInfosPageToleratesAMissingChecksum(): void
    {
        $page = self::callStatic('parseCodeInfosPage', [[
            'code_infos' => [['code_id' => '5']],
        ]]);

        self::assertSame([['code_id' => 5, 'checksum' => null]], $page['families']);
        self::assertNull($page['next_key']);
    }

    public function testCodeInfosPageOnTransportFailure(): void
    {
        $page = self::callStatic('parseCodeInfosPage', [null]);

        self::assertSame([], $page['families']);
        self::assertNull($page['next_key']);
        self::assertSame(0, $page['max_code_id']);
    }

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

    public function testContractCodeIdReadFailsSafeToNull(): void
    {
        ApiRetry::$queue[] = new \WP_Error('down');

        self::assertNull($this->makeFetcher()->fetchContractCodeId(self::FRESH));
    }

    public function testInvalidCodeIdNeverHitsTheNetwork(): void
    {
        $page = $this->makeFetcher()->listContractsForCodeId(0, null);

        self::assertFalse($page['ok']);
        self::assertSame([], ApiRetry::$calls);
    }
}
