<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * An unverified collection is never a wallet-gallery QUERY TARGET.
 *
 * ── WHAT WENT WRONG ─────────────────────────────────────────────────────
 * `list_holdings` selected every KNOWN collection on the chain, verified or
 * not. CosmWasm discovery writes unverified candidates into
 * `bcc_onchain_collections` automatically, so every row an automated scanner
 * produced silently became (a) a contract queried on a user's behalf on each
 * gallery load and (b) a potential gallery item. Discovery is an operator
 * INTAKE QUEUE; landing in it is not permission to do either.
 *
 * ── WHY THE FIXTURE LOOKS "TOO GENEROUS" ────────────────────────────────
 * The unverified contract here is registered with a POPULATED first page and
 * queued metadata — exactly like the verified one. If the selector leaked it,
 * the batch would return tokens and they WOULD appear in `items`. A fixture
 * that simply omitted a response for the unverified contract would pass
 * whether or not the filter existed, because an unregistered URL 404s and is
 * dropped anyway. Exclusion is therefore the ONLY thing that can produce the
 * asserted result.
 *
 * The stub's `listVerifiedByChain` mirrors production and filters on
 * `is_verified`; the seed array deliberately holds BOTH rows so the filtering
 * happens in the code under test rather than in the fixture.
 *
 * Isolation: resolver-stubs pattern (see CosmosFetcherListHoldingsTest).
 */
#[CoversClass(CosmosFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmosGalleryVerifiedOnlyTest extends TestCase
{
    private const CHAIN_ID = 251;
    private const WALLET   = 'inj16naevyffqm33znyf5aky86z8s09zvpyg8u8vtl';
    private const REST     = 'https://lcd.example';

    /** Verified — the positive control. */
    private const VERIFIED = 'inj1vvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvv';
    /** Unverified discovery candidate — must never be queried. */
    private const UNVERIFIED = 'inj1uuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuu';
    /** Has no collection row at all — used for the gate-independence proof. */
    private const GATE_ONLY = 'inj1gggggggggggggggggggggggggggggggggggggg';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-indexer-stubs.php';
        \BCC\Trust\Onchain\Support\ApiRetry::reset();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestObjectCache::reset();
    }

    private function makeFetcher(): CosmosFetcher
    {
        return new CosmosFetcher((object) [
            'id'         => self::CHAIN_ID,
            'slug'       => 'injective',
            'chain_type' => 'cosmos',
            'rest_url'   => self::REST,
            'decimals'   => 18,
        ]);
    }

    private function registerCollection(string $contract, string $name, bool $isVerified): void
    {
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$knownByChain[self::CHAIN_ID][] = (object) [
            'contract_address' => $contract,
            'collection_name'  => $name,
            'image_url'        => null,
            'is_verified'      => $isVerified ? 1 : 0,
        ];
    }

    private function firstPageUrl(string $contract, int $limit = 30): string
    {
        $query   = ['tokens' => ['owner' => self::WALLET, 'limit' => $limit]];
        $encoded = strtr(base64_encode((string) json_encode($query)), '+/', '-_');
        return self::REST . '/cosmwasm/wasm/v1/contract/' . rawurlencode($contract) . '/smart/' . $encoded;
    }

    private function registerFirstPage(string $contract, string ...$tokenIds): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$batchResponses[$this->firstPageUrl($contract)] = [
            'code' => 200,
            'body' => (string) json_encode(['data' => ['tokens' => array_values($tokenIds)]]),
        ];
    }

    private function queueMetadata(string $name): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = [
            'body' => (string) json_encode([
                'data' => ['token_uri' => null, 'extension' => ['name' => $name, 'image' => null]],
            ]),
        ];
    }

    /** Every URL the fetcher actually asked for, batched or single. */
    private function requestedUrls(): array
    {
        $urls = [];
        foreach (\BCC\Trust\Onchain\Support\ApiRetry::$batchCalls as $call) {
            foreach ($call['urls'] as $url) {
                $urls[] = $url;
            }
        }
        foreach (\BCC\Trust\Onchain\Support\ApiRetry::$calls as $call) {
            $urls[] = (string) ($call['url'] ?? '');
        }

        return $urls;
    }

    /**
     * THE CONTRACT. The verified collection is queried and returned; the
     * unverified one is neither, despite having a perfectly good response
     * waiting for it.
     */
    public function testUnverifiedCollectionIsNeitherQueriedNorReturned(): void
    {
        $this->registerCollection(self::VERIFIED, 'Verified Collection', true);
        $this->registerCollection(self::UNVERIFIED, 'Discovery Candidate', false);

        // BOTH are answerable. Only the filter decides the outcome.
        $this->registerFirstPage(self::VERIFIED, '1');
        $this->registerFirstPage(self::UNVERIFIED, '99');
        $this->queueMetadata('Verified #1');
        $this->queueMetadata('Candidate #99');

        $result = $this->makeFetcher()->list_holdings(self::WALLET);

        // Positive control: the verified collection still works, end to end.
        self::assertCount(1, $result['items'], 'exactly the verified collection is returned');
        self::assertSame(self::VERIFIED, $result['items'][0]['contract_address']);
        self::assertSame('1', $result['items'][0]['token_id']);
        self::assertSame('Verified Collection', $result['items'][0]['collection_name']);

        // The unverified contract never reached the wire.
        $urls = $this->requestedUrls();
        self::assertContains(
            $this->firstPageUrl(self::VERIFIED),
            $urls,
            'the verified positive control must genuinely have been queried'
        );
        self::assertNotContains(
            $this->firstPageUrl(self::UNVERIFIED),
            $urls,
            'an unverified collection must never become an outbound query target'
        );
        foreach ($urls as $url) {
            self::assertStringNotContainsString(
                self::UNVERIFIED,
                $url,
                'no request of any shape may mention the unverified contract'
            );
        }
    }

    /**
     * Exclusion happens BEFORE the network, not after it. With nothing but
     * unverified rows there is no batch at all — zero outbound requests, so
     * a chain full of scanner output costs a gallery load nothing.
     */
    public function testAnAllUnverifiedChainIssuesNoRequestAtAll(): void
    {
        $this->registerCollection(self::UNVERIFIED, 'Discovery Candidate', false);
        $this->registerCollection('inj1zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz', 'Another Candidate', false);
        $this->registerFirstPage(self::UNVERIFIED, '99');

        $result = $this->makeFetcher()->list_holdings(self::WALLET);

        self::assertSame(['items' => [], 'truncated' => false, 'cursor' => null], $result);
        self::assertSame([], \BCC\Trust\Onchain\Support\ApiRetry::$batchCalls, 'no batch may be issued');
        self::assertSame([], $this->requestedUrls(), 'no request of any kind may be issued');
    }

    /**
     * Ownership resolution is unchanged and independent of the selector: a
     * gate contract is resolved by ADDRESS, so `count_holdings` answers for a
     * contract that has no collection row at all. Narrowing the gallery query
     * therefore cannot narrow gating.
     */
    public function testGateResolutionIsIndependentOfTheCollectionSelector(): void
    {
        // Deliberately NOT registered as a collection on this chain.
        $key = sprintf(
            'cw721_tokens_%d_%s_%s_',
            self::CHAIN_ID,
            strtolower(self::GATE_ONLY),
            strtolower(self::WALLET)
        );
        \BccTestObjectCache::$store['bcc_onchain:' . $key] = ['5', '6'];

        $count = $this->makeFetcher()->count_holdings(self::WALLET, self::GATE_ONLY);

        self::assertSame(2, $count, 'gating resolves per contract address, not via the collections table');
        self::assertSame(
            [],
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$knownByChain,
            'the gate path consulted no collection rows at all'
        );
    }
}
