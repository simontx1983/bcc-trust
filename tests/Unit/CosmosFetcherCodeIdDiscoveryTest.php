<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins chain-native CW-721 discovery via wasmd code-ID enumeration
 * (feat/cw721-code-id-discovery).
 *
 * Background: the Stargaze L1 halted and its collections were
 * re-instantiated on the Cosmos Hub; the Constellations indexer that used
 * to feed `fetch_top_collections` went partner-only, so every Cosmos chain
 * except Injective discovered nothing. wasmd itself can enumerate every
 * contract instantiated from a code ID over the SAME LCD, which is what
 * this feature uses (verified live: the Hub's "Bad Kids" contract is code
 * ID 434, and 434 enumerates 3,431 contracts across 35 pages).
 *
 * Invariants under test:
 *   - the wire parsers (code ID out of a contract payload; contracts +
 *     next_key out of a page, including the absent/empty next_key case);
 *   - the resumable cursor state machine: advance within a code ID, roll to
 *     the next code ID when exhausted, wrap to the first when ALL are;
 *   - THE ANTI-CLOBBER GUARANTEE — an already-known (operator-curated)
 *     contract is filtered out BEFORE enrichment and upsert, because
 *     bulkUpsert's ON DUPLICATE KEY UPDATE would otherwise assign the
 *     discovery row's NULL collection_name / image_url over the curated
 *     name and artwork;
 *   - the kill switch stops the walk without a single transport call;
 *   - Injective still takes the curated Talis whitelist path (no regression).
 *
 * Isolation: resolver-stubs pattern (see CosmosFetcherDiscoveryTest). No
 * live HTTP.
 */
#[CoversClass(CosmosFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmosFetcherCodeIdDiscoveryTest extends TestCase
{
    private const CHAIN_ID = 8;
    private const REST     = 'https://lcd.example';

    /** The live-verified Hub example. */
    private const CURATED = 'cosmos12gsv9tmjhhg86wg9fnd9cnju28jx3fxva9cn8dh9meketkfxxajqmg3exz';
    private const FRESH   = 'cosmos1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-indexer-stubs.php';
        \BCC\Trust\Onchain\Support\ApiRetry::reset();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::reset();
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
    private function queueJson(array $payload): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = [
            'body' => (string) json_encode($payload),
        ];
    }

    /**
     * Register a BATCHED response. Code-ID sampling resolves the whole
     * curated set in one same-host wave, so those responses are keyed by
     * full URL rather than queued in call order.
     *
     * @param array<string, mixed> $payload
     */
    private function batchJson(string $path, array $payload): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$batchResponses[self::REST . $path] = [
            'code' => 200,
            'body' => (string) json_encode($payload),
        ];
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

    // ── (a) wire parsers ────────────────────────────────────────────────

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

    public function testParseContractsPageOnTransportFailure(): void
    {
        $page = self::callStatic('parseContractsPage', [null]);

        self::assertSame([], $page['contracts']);
        self::assertNull($page['next_key']);
    }

    // ── (b) the resumable cursor state machine ──────────────────────────

    public function testCursorAdvancesWithinACodeId(): void
    {
        $next = self::callStatic('nextScanState', [[434, 900], 434, 'PAGE2==']);

        self::assertSame(434, $next['code_id']);
        self::assertSame('PAGE2==', $next['next_key']);
    }

    public function testCursorRollsToNextCodeIdWhenExhausted(): void
    {
        $next = self::callStatic('nextScanState', [[434, 900], 434, null]);

        self::assertSame(900, $next['code_id']);
        self::assertNull($next['next_key']);
    }

    public function testCursorWrapsToFirstWhenAllCodeIdsExhausted(): void
    {
        // Wrapping is what makes collections instantiated SINCE the last
        // sweep discoverable — a terminal state would freeze discovery.
        $next = self::callStatic('nextScanState', [[434, 900], 900, null]);

        self::assertSame(434, $next['code_id']);
        self::assertNull($next['next_key']);
    }

    public function testCursorFallsBackToFirstWhenCodeIdLeavesTheList(): void
    {
        // Belt-and-braces: resolveScanStart already discards a stale stored
        // code ID, so this branch should be unreachable — it must still land
        // somewhere valid rather than loop on a code ID we no longer scan.
        $stale = self::callStatic('nextScanState', [[434, 900], 12345, null]);

        self::assertSame(434, $stale['code_id']);
        self::assertNull($stale['next_key']);
    }

    public function testScanStartResumesStoredCursorAndDiscardsStaleOne(): void
    {
        $resumed = self::callStatic('resolveScanStart', [
            [434, 900],
            ['code_id' => 900, 'next_key' => 'MID=='],
        ]);
        self::assertSame(900, $resumed['code_id']);
        self::assertSame('MID==', $resumed['next_key']);

        $cold = self::callStatic('resolveScanStart', [[434, 900], null]);
        self::assertSame(434, $cold['code_id']);
        self::assertNull($cold['next_key']);

        $stale = self::callStatic('resolveScanStart', [
            [434, 900],
            ['code_id' => 12345, 'next_key' => 'MID=='],
        ]);
        self::assertSame(434, $stale['code_id']);
        self::assertNull($stale['next_key']);
    }

    // ── (c) the anti-clobber filter ─────────────────────────────────────

    public function testKnownContractIsFilteredOutBeforeEnrichment(): void
    {
        // bulkUpsert assigns collection_name = VALUES(collection_name) and
        // image_url = VALUES(image_url); a discovery row carries NULL for
        // both, so re-emitting a curated contract would wipe its name and
        // artwork. It must never reach the returned rows.
        $out = self::callStatic('rejectKnownContracts', [
            [self::CURATED, self::FRESH],
            [self::CURATED => true],
        ]);

        self::assertSame([self::FRESH], $out);
    }

    public function testKnownFilterIsCaseInsensitiveAndDeduplicates(): void
    {
        $out = self::callStatic('rejectKnownContracts', [
            [strtoupper(self::CURATED), self::FRESH, self::FRESH],
            [self::CURATED => false],
        ]);

        self::assertSame([self::FRESH], $out);
    }

    // ── (d) end-to-end walk (stubbed transport) ─────────────────────────

    public function testEnumerationEmitsOnlyNewContractsAndPersistsCursor(): void
    {
        define('BCC_CW721_CODE_IDS', '{"cosmos":[434]}');
        define('BCC_CW721_PAGE_CAP', 1);

        // The operator already curated the Bad Kids contract, with a name
        // and artwork that discovery must not touch.
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$knownByChain[self::CHAIN_ID][] = (object) [
            'contract_address' => self::CURATED,
            'collection_name'  => 'Bad Kids',
            'image_url'        => 'https://cdn.example/bk.png',
            'is_verified'      => 1,
        ];

        // 1) the code/434/contracts page …
        $this->queueJson([
            'contracts'  => [self::CURATED, self::FRESH],
            'pagination' => ['next_key' => 'PAGE2=='],
        ]);
        // 2) … then ONE contract_info smart query, for the fresh address only.
        $this->queueJson(['data' => ['name' => 'Migrated Collection', 'symbol' => 'MIGR']]);

        $rows = $this->makeFetcher()->fetch_top_collections(100);

        self::assertCount(1, $rows);
        self::assertSame(self::FRESH, $rows[0]['contract_address']);
        self::assertSame('Migrated Collection', $rows[0]['collection_name']);
        self::assertSame('CW-721', $rows[0]['token_standard']);
        self::assertSame(self::CHAIN_ID, $rows[0]['chain_id']);
        self::assertNull($rows[0]['image_url']);

        // Exactly two transport calls: the page + one enrichment. A third
        // would mean the curated contract got enriched (and would then have
        // been upserted with NULL name/image).
        self::assertCount(2, \BCC\Trust\Onchain\Support\ApiRetry::$calls);
        self::assertStringContainsString(
            '/cosmwasm/wasm/v1/code/434/contracts',
            \BCC\Trust\Onchain\Support\ApiRetry::$calls[0]['url']
        );

        // The cursor moved forward so the next cycle continues rather than
        // re-walking page 1.
        $state = \BccTestOptionStore::$options['bcc_trust_cw721_scan_' . self::CHAIN_ID] ?? null;
        self::assertIsArray($state);
        self::assertSame(434, $state['code_id']);
        self::assertSame('PAGE2==', $state['next_key']);
    }

    public function testResumesFromThePersistedCursor(): void
    {
        define('BCC_CW721_CODE_IDS', '{"cosmos":[434]}');
        define('BCC_CW721_PAGE_CAP', 1);

        \BccTestOptionStore::$options['bcc_trust_cw721_scan_' . self::CHAIN_ID] = [
            'code_id'    => 434,
            'next_key'   => 'RESUME==',
            'updated_at' => 1,
        ];

        $this->queueJson(['contracts' => [], 'pagination' => ['next_key' => null]]);

        $this->makeFetcher()->fetch_top_collections(100);

        self::assertStringContainsString(
            'pagination.key=RESUME%3D%3D',
            \BCC\Trust\Onchain\Support\ApiRetry::$calls[0]['url']
        );
    }

    public function testContractInfoFailureDropsTheContract(): void
    {
        define('BCC_CW721_CODE_IDS', '{"cosmos":[434]}');
        define('BCC_CW721_PAGE_CAP', 1);

        $this->queueJson(['contracts' => [self::FRESH], 'pagination' => ['next_key' => null]]);
        // contract_info + the get_collection_info_and_extension fallback both
        // fail → not a live CW-721 → no row.
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = new \WP_Error('not a cw721');
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = new \WP_Error('not a cw721');

        self::assertSame([], $this->makeFetcher()->fetch_top_collections(100));
    }

    // ── (e) kill switch + no-op paths ───────────────────────────────────

    public function testKillSwitchStopsDiscoveryWithoutTransport(): void
    {
        define('BCC_CW721_DISCOVERY_ENABLED', false);
        define('BCC_CW721_CODE_IDS', '{"cosmos":[434]}');

        self::assertSame([], $this->makeFetcher()->fetch_top_collections(100));
        self::assertSame([], \BCC\Trust\Onchain\Support\ApiRetry::$calls);
    }

    public function testChainWithNoCuratedRowsAndNoOverrideDiscoversNothing(): void
    {
        // Correct outcome, not an error: nothing to learn code IDs from.
        self::assertSame([], $this->makeFetcher('akash')->fetch_top_collections(100));
        self::assertSame([], \BCC\Trust\Onchain\Support\ApiRetry::$calls);
    }

    public function testCodeIdsAreDerivedFromCuratedRowsAndCached(): void
    {
        define('BCC_CW721_PAGE_CAP', 1);

        \BCC\Trust\Onchain\Repositories\CollectionRepository::$knownByChain[self::CHAIN_ID][] = (object) [
            'contract_address' => self::CURATED,
            'collection_name'  => 'Bad Kids',
            'image_url'        => null,
            'is_verified'      => 1,
        ];

        // 1) resolve the curated contract's code ID (BATCHED — the sample
        //    walks the whole curated set in one same-host wave) …
        $this->batchJson(
            '/cosmwasm/wasm/v1/contract/' . self::CURATED,
            ['contract_info' => ['code_id' => '434', 'label' => 'SG721-Bad Kids']]
        );
        // 2) … then enumerate it (empty page — nothing new this cycle).
        $this->queueJson(['contracts' => [], 'pagination' => []]);

        self::assertSame([], $this->makeFetcher()->fetch_top_collections(100));

        self::assertSame(
            [434],
            \BccTestOptionStore::$transients['bcc_trust_cw721_code_ids_' . self::CHAIN_ID] ?? null
        );
        // Sampling goes over the BATCH channel (one same-host wave for the
        // whole curated set), not the sequential one — assert there.
        self::assertSame(
            [self::REST . '/cosmwasm/wasm/v1/contract/' . self::CURATED],
            \BCC\Trust\Onchain\Support\ApiRetry::$batchCalls[0]['urls'] ?? null
        );
        // …and the enumeration that follows is a plain sequential GET.
        self::assertStringContainsString(
            '/cosmwasm/wasm/v1/code/434/contracts',
            \BCC\Trust\Onchain\Support\ApiRetry::$calls[0]['url']
        );
    }

    // ── (f) Injective keeps its curated whitelist path ──────────────────

    public function testInjectiveStillUsesTheTalisWhitelist(): void
    {
        define('BCC_CW721_CODE_IDS', '{"injective":[1099]}');

        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = new \WP_Error('down');

        $this->makeFetcher('injective')->fetch_top_collections(100);

        // A whitelist SMART query, not a code-ID enumeration: a curated
        // whitelist is higher-signal and must not regress to raw code IDs.
        $url = \BCC\Trust\Onchain\Support\ApiRetry::$calls[0]['url'];
        self::assertStringContainsString('/smart/', $url);
        self::assertStringNotContainsString('/cosmwasm/wasm/v1/code/', $url);
    }
}
