<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Fetchers\EvmFetcher;
use BCC\Trust\Onchain\Fetchers\SolanaFetcher;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * PR 7.14 — a provider read that did not finish must never read as "owns none".
 *
 * ── WHY THESE LIVE AT THE FETCHER BOUNDARY ──────────────────────────────
 * The revoke sweep removes a member only on a COMPLETE zero. Every false
 * zero it ever acted on was manufactured one layer down, inside a fetcher
 * that turned an unfinished or unreadable answer into an ordinary integer or
 * an ordinary "complete" list:
 *
 *   - EVM: an `eth_call` answering `"0x"` (no contract code at that address,
 *     or an RPC on the wrong network) decoded to balance 0;
 *   - Solana: a DAS walk that stopped at its 10-page ceiling returned the
 *     partial count; a `result` with no `items` list was iterated as if it
 *     were one; an asset with an interface the gallery does not render was
 *     dropped from a list still labelled complete;
 *   - Cosmos: a smart-query envelope with no `tokens` key parsed as `[]`; a
 *     verified-collection list capped at 30 — or unreadable — produced a
 *     walk still labelled complete.
 *
 * Each case below uses the REAL fetcher over the scripted `ApiRetry` wire, so
 * what is asserted is the shape the fetcher itself produces.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HoldingsEvidenceIncompleteTest extends TestCase
{
    // Cosmos (Injective is not a governed slug, so no endpoint refusal applies).
    private const COSMOS_CHAIN_ID = 251;
    private const COSMOS_REST     = 'https://cosmos-api.polkachu.com';
    private const COSMOS_WALLET   = 'inj16naevyffqm33znyf5aky86z8s09zvpyg8u8vtl';
    private const COSMOS_CONTRACT = 'inj1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    // EVM.
    private const EVM_CHAIN_ID = 1;
    private const EVM_RPC      = 'https://eth-mainnet.g.alchemy.com/v2/testkey';
    private const EVM_WALLET   = '0x1111111111111111111111111111111111111111';
    private const EVM_CONTRACT = '0x2222222222222222222222222222222222222222';

    // Solana — deterministic, genuinely valid 32-byte base58 identifiers.
    private const SOL_CHAIN_ID   = 20;
    private const SOL_RPC        = 'https://mainnet.helius-rpc.test/?api-key=unit';
    private const SOL_WALLET     = 'Egez6QLbs5fSLi6H2cgw22uDva7EWhnk4BSqGk7HcRxq';
    private const SOL_COLLECTION = 'FursjsPvEDmMgMr2jbR9foRAxQ2JsSSeByE6k1PPsdaQ';
    private const SOL_OTHER      = 'CRS3P2kyB4Uebj419sJqbvDxP5nD4BUSCFsbaW2gHtJR';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-indexer-stubs.php';
        \BCC\Trust\Onchain\Support\ApiRetry::reset();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestObjectCache::reset();
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function evm(): EvmFetcher
    {
        return new EvmFetcher((object) [
            'id'         => self::EVM_CHAIN_ID,
            'slug'       => 'ethereum',
            'chain_type' => 'evm',
            'rpc_url'    => self::EVM_RPC,
        ]);
    }

    private function solana(): SolanaFetcher
    {
        return new SolanaFetcher((object) [
            'id'         => self::SOL_CHAIN_ID,
            'slug'       => 'solana',
            'chain_type' => 'solana',
            'rpc_url'    => self::SOL_RPC,
        ]);
    }

    private function cosmos(): CosmosFetcher
    {
        return new CosmosFetcher((object) [
            'id'         => self::COSMOS_CHAIN_ID,
            'slug'       => 'injective',
            'chain_type' => 'cosmos',
            'rest_url'   => self::COSMOS_REST,
            'decimals'   => 18,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function queueJson(array $payload): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = ['body' => (string) json_encode($payload)];
    }

    /**
     * One DAS asset. `$collection` null means "no collection grouping".
     *
     * @return array<string, mixed>
     */
    private function asset(string $id, ?string $collection, string $interface = 'V1_NFT'): array
    {
        return [
            'id'        => $id,
            'interface' => $interface,
            'grouping'  => $collection === null ? [] : [['group_key' => 'collection', 'group_value' => $collection]],
            'content'   => ['metadata' => ['name' => 'asset ' . $id]],
        ];
    }

    private function cosmosFirstPageUrl(string $contract): string
    {
        $query   = ['tokens' => ['owner' => self::COSMOS_WALLET, 'limit' => 30]];
        $encoded = strtr(base64_encode((string) json_encode($query)), '+/', '-_');

        return self::COSMOS_REST . '/cosmwasm/wasm/v1/contract/' . rawurlencode($contract) . '/smart/' . $encoded;
    }

    private function registerVerifiedCollection(string $contract): void
    {
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$knownByChain[self::COSMOS_CHAIN_ID][] = (object) [
            'contract_address' => $contract,
            'collection_name'  => 'Collection ' . $contract,
            'image_url'        => null,
            'is_verified'      => 1,
        ];
    }

    // ── EVM ─────────────────────────────────────────────────────────────

    public function testAnEmptyEthCallResultIsNotABalanceOfZero(): void
    {
        // `"0x"` is what an RPC answers for a call to an address with no code:
        // an EOA, a mistyped contract, or a node on the wrong network. It is
        // not an ABI-encoded uint256, so it cannot be a balance of anything.
        $this->queueJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x']);

        self::assertNull(
            $this->evm()->count_holdings(self::EVM_WALLET, self::EVM_CONTRACT),
            'an empty eth_call result must be UNKNOWN, never a real zero'
        );
    }

    public function testAMalformedLinkedWalletIsUnknownNotZero(): void
    {
        // Nothing was asked: a stored wallet that is not an EVM address is a
        // data problem about the MEMBER, not evidence that they hold nothing.
        self::assertNull($this->evm()->count_holdings('not-an-evm-address', self::EVM_CONTRACT));
        self::assertSame([], \BCC\Trust\Onchain\Support\ApiRetry::$calls);
    }

    public function testADecisiveEvmZeroIsStillZero(): void
    {
        $this->queueJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x' . str_repeat('0', 64)]);

        self::assertSame(0, $this->evm()->count_holdings(self::EVM_WALLET, self::EVM_CONTRACT));
    }

    public function testADecisiveEvmPositiveIsStillCounted(): void
    {
        $this->queueJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x' . str_repeat('0', 63) . '2']);

        self::assertSame(2, $this->evm()->count_holdings(self::EVM_WALLET, self::EVM_CONTRACT));
    }

    public function testTheEvmListStubDoesNotClaimACompleteEnumeration(): void
    {
        // EVM cannot enumerate NFTs at all. An empty list it never read must
        // not be labelled complete — that label is what let HoldingsService
        // cache "owns nothing" for a day.
        $list = $this->evm()->list_holdings(self::EVM_WALLET);

        self::assertSame([], $list['items']);
        self::assertFalse($list['complete']);
    }

    // ── Solana ──────────────────────────────────────────────────────────

    public function testASolanaCountThatStoppedAtItsPageCeilingIsNotAConfidentZero(): void
    {
        // Ten FULL pages (10 × 1000) of assets that are not the target, and the
        // walk stops at its ceiling. The target may be on page 11.
        for ($page = 1; $page <= 10; $page++) {
            $items = [];
            for ($i = 0; $i < 1000; $i++) {
                $items[] = ['id' => "p{$page}a{$i}", 'grouping' => []];
            }
            $this->queueJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['items' => $items]]);
        }

        self::assertNull(
            $this->solana()->count_holdings(self::SOL_WALLET, self::SOL_COLLECTION),
            'a walk cut off by the page cap has not proved the wallet owns none'
        );
    }

    public function testASolanaResultWithoutAnItemsListIsUnknown(): void
    {
        // A `result` object with no `items` is not an empty wallet — it is a
        // response this code does not understand.
        $this->queueJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['total' => 0, 'limit' => 1000, 'page' => 1]]);

        self::assertNull($this->solana()->count_holdings(self::SOL_WALLET, self::SOL_COLLECTION));
    }

    public function testASolanaProviderFailureIsUnknown(): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = new \WP_Error('cURL error 28');

        self::assertNull($this->solana()->count_holdings(self::SOL_WALLET, self::SOL_COLLECTION));
    }

    public function testACompleteSolanaZeroIsStillZero(): void
    {
        $this->queueJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['items' => [
            $this->asset('a1', self::SOL_OTHER),
        ]]]);

        self::assertSame(0, $this->solana()->count_holdings(self::SOL_WALLET, self::SOL_COLLECTION));
    }

    public function testACompleteSolanaPositiveIsStillCounted(): void
    {
        $this->queueJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['items' => [
            $this->asset('a1', self::SOL_COLLECTION),
            $this->asset('a2', self::SOL_OTHER),
            $this->asset('a3', self::SOL_COLLECTION, 'MplCoreAsset'),
        ]]]);

        self::assertSame(2, $this->solana()->count_holdings(self::SOL_WALLET, self::SOL_COLLECTION));
    }

    public function testASolanaListThatDroppedAnUnrenderedNftInterfaceIsIncomplete(): void
    {
        // `MplCoreAsset` is a real NFT the gallery does not render. Dropping it
        // is a display choice; calling the remainder complete is a false claim.
        $this->queueJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['items' => [
            $this->asset('core1', self::SOL_COLLECTION, 'MplCoreAsset'),
        ]]]);

        $list = $this->solana()->list_holdings(self::SOL_WALLET);

        self::assertSame([], $list['items']);
        self::assertFalse($list['complete'], 'an NFT was filtered out, so the list is not complete');
    }

    public function testASolanaListThatOnlyDroppedFungiblesStaysComplete(): void
    {
        $this->queueJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['items' => [
            $this->asset('usdc', null, 'FungibleToken'),
            $this->asset('nft1', self::SOL_COLLECTION, 'V1_NFT'),
        ]]]);

        $list = $this->solana()->list_holdings(self::SOL_WALLET);

        self::assertCount(1, $list['items']);
        self::assertTrue($list['complete'], 'a fungible token is not an NFT the list failed to show');
    }

    public function testASolanaListResultWithoutItemsIsIncomplete(): void
    {
        $this->queueJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['total' => 0]]);

        $list = $this->solana()->list_holdings(self::SOL_WALLET);

        self::assertSame([], $list['items']);
        self::assertFalse($list['complete']);
    }

    // ── Cosmos ──────────────────────────────────────────────────────────

    public function testACosmosEnvelopeWithoutTokensIsNotAnEmptyWallet(): void
    {
        $this->queueJson(['data' => ['unexpected' => true]]);

        self::assertNull(
            $this->cosmos()->count_holdings(self::COSMOS_WALLET, self::COSMOS_CONTRACT),
            'a response with no `tokens` field did not say the wallet owns none'
        );

        // And it must not have been cached as an empty page for a day.
        $key = sprintf('cw721_tokens_%d_%s_%s_', self::COSMOS_CHAIN_ID, self::COSMOS_CONTRACT, self::COSMOS_WALLET);
        self::assertFalse(wp_cache_get($key, 'bcc_onchain'), 'an unreadable page must never be cached');
    }

    public function testACosmosTokensFieldThatIsNotAListIsUnknown(): void
    {
        $this->queueJson(['data' => ['tokens' => 'nope']]);

        self::assertNull($this->cosmos()->count_holdings(self::COSMOS_WALLET, self::COSMOS_CONTRACT));
    }

    public function testACosmosLcdFailureIsUnknown(): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = new \WP_Error('Circuit breaker open for chain 251');

        self::assertNull($this->cosmos()->count_holdings(self::COSMOS_WALLET, self::COSMOS_CONTRACT));
    }

    public function testACompleteCosmosEmptyIsStillZero(): void
    {
        $this->queueJson(['data' => ['tokens' => []]]);

        self::assertSame(0, $this->cosmos()->count_holdings(self::COSMOS_WALLET, self::COSMOS_CONTRACT));
    }

    public function testACompleteCosmosPositiveIsStillCounted(): void
    {
        $this->queueJson(['data' => ['tokens' => ['1', '2', '3']]]);

        self::assertSame(3, $this->cosmos()->count_holdings(self::COSMOS_WALLET, self::COSMOS_CONTRACT));
    }

    public function testCosmosOwnershipEvidenceNeverReadsADayOldCachedEmptyPage(): void
    {
        // The gallery cached "owns none" for this contract earlier today. The
        // member has since bought one. A decision must ask the LCD.
        $key = sprintf('cw721_tokens_%d_%s_%s_', self::COSMOS_CHAIN_ID, self::COSMOS_CONTRACT, self::COSMOS_WALLET);
        \BccTestObjectCache::$store['bcc_onchain:' . $key] = [];
        $this->queueJson(['data' => ['tokens' => ['77']]]);

        $evidence = $this->cosmos()->count_holdings_evidence(self::COSMOS_WALLET, self::COSMOS_CONTRACT);

        self::assertNotNull($evidence);
        self::assertSame(1, $evidence->count);
        self::assertTrue($evidence->complete);
        self::assertCount(1, \BCC\Trust\Onchain\Support\ApiRetry::$calls, 'the evidence read went to the LCD');
    }

    public function testACosmosWalkStoppedAtTheTokenCapProvesOwnershipButNoShortfall(): void
    {
        $n = 0;
        for ($page = 0; $page < 4; $page++) {
            $tokens = [];
            for ($i = 0; $i < 30; $i++) {
                $tokens[] = 't' . (++$n);
            }
            $this->queueJson(['data' => ['tokens' => $tokens]]);
        }

        $evidence = $this->cosmos()->count_holdings_evidence(self::COSMOS_WALLET, self::COSMOS_CONTRACT);

        self::assertNotNull($evidence);
        self::assertSame(100, $evidence->count);
        self::assertFalse($evidence->complete, 'the walk stopped at its cap, not at the owner\'s last token');
        self::assertTrue($evidence->decide(100));
        self::assertNull($evidence->decide(101), 'a capped walk cannot prove the owner holds fewer than 101');
        self::assertLessThanOrEqual($this->cosmos()->max_requests_per_evidence_read(), count(\BCC\Trust\Onchain\Support\ApiRetry::$calls));
    }

    public function testASolanaWalkStoppedAtItsPageCeilingIsALowerBound(): void
    {
        for ($page = 1; $page <= 10; $page++) {
            $items = [];
            for ($i = 0; $i < 1000; $i++) {
                $items[] = ['id' => "p{$page}a{$i}", 'grouping' => $page === 1 && $i === 0
                    ? [['group_key' => 'collection', 'group_value' => self::SOL_COLLECTION]]
                    : []];
            }
            $this->queueJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['items' => $items]]);
        }

        $evidence = $this->solana()->count_holdings_evidence(self::SOL_WALLET, self::SOL_COLLECTION);

        self::assertNotNull($evidence);
        self::assertSame(1, $evidence->count);
        self::assertFalse($evidence->complete);
        self::assertTrue($evidence->decide(1), 'one found is still proof of one');
        self::assertNull($evidence->decide(2));
        self::assertCount($this->solana()->max_requests_per_evidence_read(), \BCC\Trust\Onchain\Support\ApiRetry::$calls);
    }

    public function testACosmosWalkLimitedToTheTopThirtyCollectionsIsIncomplete(): void
    {
        // 31 verified collections, every first page a genuine empty. The walk
        // can only look at 30 of them, so it has proved nothing about the 31st.
        for ($i = 1; $i <= 31; $i++) {
            $contract = 'inj1' . str_pad((string) $i, 38, 'c', STR_PAD_LEFT);
            $this->registerVerifiedCollection($contract);
            \BCC\Trust\Onchain\Support\ApiRetry::$batchResponses[$this->cosmosFirstPageUrl($contract)] = [
                'code' => 200,
                'body' => (string) json_encode(['data' => ['tokens' => []]]),
            ];
        }

        $list = $this->cosmos()->list_holdings(self::COSMOS_WALLET);

        self::assertSame([], $list['items']);
        self::assertFalse($list['complete'], 'collection 31 was never asked about');
    }

    public function testACosmosWalkOverExactlyThirtyCollectionsStaysComplete(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $contract = 'inj1' . str_pad((string) $i, 38, 'c', STR_PAD_LEFT);
            $this->registerVerifiedCollection($contract);
            \BCC\Trust\Onchain\Support\ApiRetry::$batchResponses[$this->cosmosFirstPageUrl($contract)] = [
                'code' => 200,
                'body' => (string) json_encode(['data' => ['tokens' => []]]),
            ];
        }

        $list = $this->cosmos()->list_holdings(self::COSMOS_WALLET);

        self::assertTrue($list['complete'], 'every verified collection was asked about');
    }

    public function testAnUnreadableVerifiedCollectionListIsIncomplete(): void
    {
        $this->registerVerifiedCollection(self::COSMOS_CONTRACT);
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$failVerifiedRead = true;

        $list = $this->cosmos()->list_holdings(self::COSMOS_WALLET);

        self::assertSame([], $list['items']);
        self::assertFalse($list['complete'], 'a failed repository read is not "no verified collections"');
    }

    public function testAChainWithNoVerifiedCollectionsStaysComplete(): void
    {
        $list = $this->cosmos()->list_holdings(self::COSMOS_WALLET);

        self::assertSame([], $list['items']);
        self::assertTrue($list['complete']);
    }
}
