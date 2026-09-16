<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * V1 wallet-link discovery on Cosmos is GONE, and this file pins that it
 * stays gone — behaviourally, not by reading the source.
 *
 * ── WHAT THIS FILE USED TO ASSERT ───────────────────────────────────────
 * It described a working Stargaze-marketplace discovery path: a rollup
 * mapped into upsert rows, a spam gate, a 50-row cap. Every one of those
 * cases required sending the member's Cosmos address — in a URL path — to
 * `marketplace-api…` (deliberately not spelled here), an API Stargaze does
 * not publish as a supported integration. PR 7.12 deleted the client.
 *
 * ── WHY NOTHING REPLACES IT ─────────────────────────────────────────────
 * wasmd has no owner→contracts index. "Which collections does this wallet
 * hold" is not a question the Hub LCD can answer, and the marketplace was
 * the only thing pretending otherwise. Unknown stays unknown.
 *
 * ⚠ THE TWO GUARANTEES, AND WHY THERE ARE TWO. The capability is withdrawn
 * (`supports_feature('collection') === false`) AND the method returns [].
 * Callers check the capability first, so the withdrawal is what actually
 * prevents the work; the empty return is the backstop for a caller that
 * forgets. A test for only one of them would pass while the other rotted.
 *
 * Ownership of a KNOWN contract is a different question and still works —
 * see CosmosFetcher::count_holdings() / list_holdings(), which walk
 * `tokens{owner}` over the LCD against verified collections.
 */
#[CoversClass(CosmosFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmosFetcherDiscoveryTest extends TestCase
{
    private const WALLET = 'cosmos15y38ehvexp6275ptmm4jj3qdds379nk02heclj';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-indexer-stubs.php';
        \BCC\Trust\Onchain\Support\ApiRetry::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestObjectCache::reset();
    }

    private function makeFetcher(string $slug = 'cosmos', int $id = 8): CosmosFetcher
    {
        return new CosmosFetcher((object) [
            'id'         => $id,
            'slug'       => $slug,
            'chain_type' => 'cosmos',
            'rest_url'   => 'https://cosmos-api.polkachu.com',
            'decimals'   => 6,
        ]);
    }

    /**
     * Every Cosmos chain, INCLUDING the Hub — the chain the marketplace
     * used to serve, and the only one where a regression would actually
     * transmit something.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function cosmosChains(): array
    {
        return [
            'cosmos hub' => ['cosmos', 8],
            'osmosis'    => ['osmosis', 9],
            'injective'  => ['injective', 13],
            'dungeon'    => ['dungeon', 17],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. THE CAPABILITY IS WITHDRAWN — callers skip Cosmos entirely
    // ═══════════════════════════════════════════════════════════════════

    #[DataProvider('cosmosChains')]
    public function testCollectionCapabilityIsWithdrawn(string $slug, int $id): void
    {
        self::assertFalse(
            $this->makeFetcher($slug, $id)->supports_feature('collection'),
            'advertising "collection" is what made the three callers fan out'
        );
    }

    /**
     * Anti-vacuity: the driver still advertises everything else it really
     * does, so the assertion above is a withdrawal of ONE capability and
     * not a fetcher that claims nothing.
     */
    #[DataProvider('cosmosChains')]
    public function testOwnershipCapabilitiesSurvive(string $slug, int $id): void
    {
        $fetcher = $this->makeFetcher($slug, $id);

        foreach (['validator', 'delegations', 'holdings_count', 'holdings_list'] as $feature) {
            self::assertTrue($fetcher->supports_feature($feature), "{$feature} must still be supported");
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. THE METHOD ITSELF TRANSMITS NOTHING
    // ═══════════════════════════════════════════════════════════════════

    #[DataProvider('cosmosChains')]
    public function testDiscoveryReturnsEmptyWithZeroTransport(string $slug, int $id): void
    {
        $result = $this->makeFetcher($slug, $id)->fetch_collections(self::WALLET);

        self::assertSame([], $result);
        self::assertSame(
            [],
            \BCC\Trust\Onchain\Support\ApiRetry::$calls,
            'no request may be issued — the address must not leave the process'
        );
    }

    /**
     * The address is not merely unsent — it is untouched. A queued response
     * proves the fetcher never even reached for a transport: if it had, the
     * fake would have handed it this payload and the row would appear.
     */
    public function testAQueuedResponseIsNeverConsumed(): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = [
            'body' => (string) json_encode([
                'total'       => 1,
                'collections' => [[
                    'contractAddress'  => 'cosmos1' . str_pad('z', 58, 'q', STR_PAD_LEFT),
                    'name'             => 'Bad Kids',
                    'ownedTokensCount' => 2,
                ]],
            ]),
        ];

        self::assertSame([], $this->makeFetcher()->fetch_collections(self::WALLET));
        self::assertCount(
            1,
            \BCC\Trust\Onchain\Support\ApiRetry::$queue,
            'the queued response is still queued: nothing consumed it'
        );
        self::assertSame([], \BCC\Trust\Onchain\Support\ApiRetry::$calls);
    }

    /** An empty return is not licence to log the address either. */
    public function testNothingIsLogged(): void
    {
        $this->makeFetcher()->fetch_collections(self::WALLET);

        $logged = json_encode(\BCC\Core\Log\Logger::$lines);
        self::assertIsString($logged);
        self::assertStringNotContainsString(self::WALLET, $logged, 'the wallet address must not reach the log');
        self::assertStringNotContainsString('stargaze', strtolower($logged));
    }
}
