<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\AlchemyEndpoint;
use BCC\Trust\Onchain\Support\HeliusEndpoint;
use BCC\Trust\Onchain\Support\NftCapabilityOptionState;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use BCC\Trust\Onchain\Support\NftProviderReadiness;
use BCC\Trust\Onchain\Support\SolanaEndpoints;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Stubs/nft-capability-stubs.php';

/**
 * Per-driver provider readiness.
 *
 * ── WHAT IS BEING PINNED ────────────────────────────────────────────────
 * 1. READINESS IS PER DRIVER. The headline test is
 *    {@see testConfiguringHeliusDoesNotMakeTheRpcPathReady()}: with Helius
 *    configured and the chain row still on the public RPC, `magiceden` and
 *    `das_helius` are ready while `das_rpc` is NOT — same chain, same
 *    moment, three drivers, and no single answer covers them. Any chain-wide
 *    boolean would have to be wrong about at least one, and would be wrong in
 *    the permissive direction, which is how an operator ends up starting a
 *    job that cannot make a single successful call.
 *
 *    The two Solana DAS drivers are separate because they call different
 *    endpoints: `das_rpc` (wallet discovery, ownership) goes through
 *    `rpcCall()` to the chain row's `rpc_url`; `das_helius` (metadata) goes
 *    to the Helius constants.
 *
 * 2. DEFINED IS NOT CONFIGURED. `define('BCC_HELIUS_API_KEY', '')` is a real
 *    production shape — a stripped staging config, a secret that failed to
 *    inject, a half-finished provisioning step. Read with `defined()` alone
 *    it says "configured" and is useless.
 *
 * 3. THE SEEDED ALCHEMY URLS ARE NOT CONFIGURED. schema-chains.php seeds
 *    five EVM chains with keyless template URLs. They must read as
 *    unconfigured, or every fresh install would claim EVM metadata and
 *    wallet discovery it cannot perform.
 *
 * Constants cannot be un-defined once set, so the Helius cases run in
 * separate processes.
 */
#[CoversClass(NftProviderReadiness::class)]
#[CoversClass(AlchemyEndpoint::class)]
#[CoversClass(HeliusEndpoint::class)]
#[CoversClass(SolanaEndpoints::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NftProviderReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        NftCapabilityOptionState::reset();
    }

    private static function chain(string $type, string $slug, string $rpc = '', string $rest = '', int $id = 1): object
    {
        return (object) [
            'id'         => (string) $id,
            'slug'       => $slug,
            'chain_type' => $type,
            'rpc_url'    => $rpc,
            'rest_url'   => $rest,
        ];
    }

    // ── The reason this class is per-driver ─────────────────────────────

    /**
     * THE REPORTED DEFECT, PINNED.
     *
     * Helius IS configured, and the chain row still carries the public RPC.
     * Three drivers on one chain at one instant, and no single answer covers
     * them:
     *
     *   magiceden   ready     - public marketplace API, no credential
     *   das_helius  ready     - getAsset goes to the Helius constants
     *   das_rpc     NOT ready - getAssetsByOwner goes to the CHAIN ROW's
     *                           rpc_url, which is the public endpoint
     *
     * Before the split, `das` read the Helius credential and reported wallet
     * discovery READY while every call went to an endpoint with no DAS.
     */
    public function testConfiguringHeliusDoesNotMakeTheRpcPathReady(): void
    {
        define('BCC_HELIUS_API_KEY', 'real-key');

        $solana = self::chain('solana', 'solana', SolanaEndpoints::PUBLIC_MAINNET_RPC);

        self::assertTrue(
            NftProviderReadiness::isReady($solana, NftDriverRegistry::DRIVER_MAGICEDEN),
            'the public marketplace feed needs no credential'
        );
        self::assertTrue(
            NftProviderReadiness::isReady($solana, NftDriverRegistry::DRIVER_DAS_HELIUS),
            'Helius-backed metadata is ready independently of the chain row'
        );
        self::assertFalse(
            NftProviderReadiness::isReady($solana, NftDriverRegistry::DRIVER_DAS_RPC),
            'wallet discovery still calls the chain rpc_url, which has no DAS'
        );
    }

    /**
     * The same fact stated as an operation question: a Helius key must not
     * make WALLET_DISCOVERY or OWNERSHIP ready while those paths use a
     * different RPC.
     */
    public function testHeliusDoesNotMakeWalletDiscoveryOrOwnershipReady(): void
    {
        define('BCC_HELIUS_API_KEY', 'real-key');

        $solana = self::chain('solana', 'solana', SolanaEndpoints::PUBLIC_MAINNET_RPC);

        foreach ([NftDriverRegistry::OP_WALLET_DISCOVERY, NftDriverRegistry::OP_OWNERSHIP] as $operation) {
            $drivers = NftDriverRegistry::driversFor($solana, $operation, []);
            self::assertSame(
                [],
                NftProviderReadiness::readyDrivers($solana, $drivers),
                "no driver may be ready for {$operation} while that path uses a non-DAS RPC"
            );
        }

        // METADATA, which really does use Helius, is unaffected.
        self::assertSame(
            [NftDriverRegistry::DRIVER_DAS_HELIUS],
            NftProviderReadiness::readyDrivers(
                $solana,
                NftDriverRegistry::driversFor($solana, NftDriverRegistry::OP_METADATA, [])
            )
        );
    }

    /** Repointing the chain row at a DAS provider makes the rpc path ready. */
    public function testRepointingTheChainRowMakesTheRpcPathReady(): void
    {
        $solana = self::chain('solana', 'solana', 'https://mainnet.helius-rpc.com/?api-key=k');

        self::assertTrue(NftProviderReadiness::isReady($solana, NftDriverRegistry::DRIVER_DAS_RPC));
    }

    /**
     * THE MODEL AND THE FETCHER CANNOT DRIFT.
     *
     * Both resolve the rpc endpoint through SolanaEndpoints, including its
     * public-default fallback. A chain row with a NULL rpc_url still makes
     * calls - to the default - so a readiness check reading the raw column
     * would be describing a different endpoint from the one being used.
     */
    public function testNullRpcUrlIsDescribedAsTheEndpointTheFetcherWouldUse(): void
    {
        $chain = (object) ['id' => '1', 'slug' => 'solana', 'chain_type' => 'solana', 'rpc_url' => null];

        self::assertSame(SolanaEndpoints::PUBLIC_MAINNET_RPC, SolanaEndpoints::rpcEndpoint($chain));
        self::assertFalse(
            NftProviderReadiness::isReady($chain, NftDriverRegistry::DRIVER_DAS_RPC),
            'a null rpc_url falls back to the public endpoint, which has no DAS'
        );
    }

    /** The fetcher resolves its endpoints through the SAME shared class. */
    public function testFetcherResolvesEndpointsThroughTheSharedResolver(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Fetchers/SolanaFetcher.php'
        );

        self::assertStringContainsString('SolanaEndpoints::rpcEndpoint($this->chain)', $src);
        self::assertStringContainsString('SolanaEndpoints::metadataEndpoint()', $src);
        self::assertStringNotContainsString(
            'https://api.mainnet-beta.solana.com',
            $src,
            'the default endpoint literal must live only in SolanaEndpoints'
        );
    }

    // ── Defined is not configured ───────────────────────────────────────

    public function testHeliusIsNotConfiguredWhenNothingIsDefined(): void
    {
        self::assertFalse(HeliusEndpoint::isConfigured());
        self::assertNull(HeliusEndpoint::resolveRpcUrl());
    }

    public function testEmptyHeliusApiKeyIsNotConfigured(): void
    {
        define('BCC_HELIUS_API_KEY', '');

        self::assertFalse(
            HeliusEndpoint::isConfigured(),
            "define('BCC_HELIUS_API_KEY', '') is defined and useless — it must not read as configured"
        );
        self::assertFalse(
            NftProviderReadiness::isReady(self::chain('solana', 'solana'), NftDriverRegistry::DRIVER_DAS_HELIUS),
            'the Helius-backed metadata path is the one an empty key disables'
        );
    }

    public function testEmptyHeliusRpcUrlIsNotConfigured(): void
    {
        define('BCC_HELIUS_RPC_URL', '');

        self::assertFalse(HeliusEndpoint::isConfigured());
    }

    // ── Whitespace-only configuration is not configuration ──────────────

    /**
     * A constant holding only whitespace is the same class of half-finished
     * setup as an empty one — a templated wp-config, a secret injected with
     * a stray newline, a copy-paste with trailing spaces. Untrimmed it is
     * non-empty, reads as configured, and yields a URL that cannot resolve.
     */
    public function testWhitespaceOnlyHeliusApiKeyIsNotConfigured(): void
    {
        define('BCC_HELIUS_API_KEY', "  \t\n ");

        self::assertFalse(HeliusEndpoint::isConfigured());
        self::assertNull(HeliusEndpoint::resolveRpcUrl());
        self::assertFalse(
            NftProviderReadiness::isReady(self::chain('solana', 'solana'), NftDriverRegistry::DRIVER_DAS_HELIUS)
        );
    }

    public function testWhitespaceOnlyHeliusRpcUrlIsNotConfigured(): void
    {
        define('BCC_HELIUS_RPC_URL', "   ");

        self::assertFalse(HeliusEndpoint::isConfigured());
        self::assertNull(HeliusEndpoint::resolveRpcUrl());
    }

    /** A whitespace-only URL falls THROUGH to a usable key. */
    public function testWhitespaceUrlFallsThroughToAValidKey(): void
    {
        define('BCC_HELIUS_RPC_URL', "  \n");
        define('BCC_HELIUS_API_KEY', 'real-key');

        self::assertSame(
            'https://mainnet.helius-rpc.com/?api-key=real-key',
            HeliusEndpoint::resolveRpcUrl()
        );
    }

    /** A padded key is usable, and its padding never reaches the URL. */
    public function testPaddedKeyIsTrimmedRatherThanRejected(): void
    {
        define('BCC_HELIUS_API_KEY', "  real-key\n");

        self::assertSame(
            'https://mainnet.helius-rpc.com/?api-key=real-key',
            HeliusEndpoint::resolveRpcUrl()
        );
    }

    /** A valid explicit URL still wins over a valid key, trimmed. */
    public function testPaddedExplicitUrlStillWins(): void
    {
        define('BCC_HELIUS_RPC_URL', "  https://custom.example/rpc  ");
        define('BCC_HELIUS_API_KEY', 'ignored');

        self::assertSame('https://custom.example/rpc', HeliusEndpoint::resolveRpcUrl());
    }

    /** An empty URL falls THROUGH to the key rather than short-circuiting. */
    public function testEmptyUrlFallsThroughToANonEmptyKey(): void
    {
        define('BCC_HELIUS_RPC_URL', '');
        define('BCC_HELIUS_API_KEY', 'real-key');

        self::assertSame(
            'https://mainnet.helius-rpc.com/?api-key=real-key',
            HeliusEndpoint::resolveRpcUrl()
        );
        self::assertTrue(
            NftProviderReadiness::isReady(self::chain('solana', 'solana'), NftDriverRegistry::DRIVER_DAS_HELIUS)
        );
    }

    public function testExplicitHeliusUrlWins(): void
    {
        define('BCC_HELIUS_RPC_URL', 'https://custom.example/rpc');
        define('BCC_HELIUS_API_KEY', 'ignored');

        self::assertSame('https://custom.example/rpc', HeliusEndpoint::resolveRpcUrl());
    }

    /**
     * A configured key is still not ready when THE ENDPOINT IN USE has
     * already told us it cannot serve `getAssets*`. The mark is written only
     * on an observed -32601/-32603, so it is evidence rather than a guess.
     *
     * The mark records the redacted endpoint, so the current one is put
     * through the same redaction to compare.
     */
    public function testObservedDasUnsupportedOverridesAConfiguredKey(): void
    {
        define('BCC_HELIUS_API_KEY', 'real-key');

        // A DAS-capable endpoint, so the stored mark is the only thing that
        // can make this unready. Using the public default here would prove
        // nothing: it is refused on its own account.
        $endpoint = 'https://das-provider.example/?api-key=k';
        $solana   = self::chain('solana', 'solana', $endpoint, '', 42);
        self::assertTrue(NftProviderReadiness::isReady($solana, NftDriverRegistry::DRIVER_DAS_RPC));

        NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(42)] = [
            'rpc_url'     => HeliusEndpoint::redactEndpoint($endpoint),
            'code'        => -32601,
            'message'     => 'Method not found',
            'detected_at' => 1,
        ];

        self::assertFalse(
            NftProviderReadiness::isReady($solana, NftDriverRegistry::DRIVER_DAS_RPC),
            'the endpoint that answered "method not found" is not ready, key or no key'
        );
    }

    /**
     * THE STALE-MARK DEAD END, closed.
     *
     * A negative observation belongs to the endpoint that produced it.
     * Before this, any stored mark was permanent: the seeded public RPC
     * answered "method not found", the mark was stored, the operator
     * repointed `chains.rpc_url` at a DAS-capable endpoint — and readiness
     * stayed false forever, with no way to clear it short of deleting the
     * option by hand.
     */
    public function testRepointingToADifferentEndpointClearsAStaleMark(): void
    {
        define('BCC_HELIUS_API_KEY', 'real-key');

        // Both endpoints are DAS-capable, so the ONLY difference between the
        // two assertions below is which endpoint the mark describes.
        $oldRpc = 'https://old-provider.example/?api-key=k';
        $newRpc = 'https://mainnet.helius-rpc.com/?api-key=real-key';

        // The old endpoint was observed to lack DAS.
        NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(42)] = [
            'rpc_url'     => HeliusEndpoint::redactEndpoint($oldRpc),
            'code'        => -32601,
            'message'     => 'Method not found',
            'detected_at' => 1,
        ];

        self::assertFalse(
            NftProviderReadiness::isReady(self::chain('solana', 'solana', $oldRpc, '', 42), NftDriverRegistry::DRIVER_DAS_RPC),
            'the same endpoint keeps its refusal'
        );

        self::assertTrue(
            NftProviderReadiness::isReady(self::chain('solana', 'solana', $newRpc, '', 42), NftDriverRegistry::DRIVER_DAS_RPC),
            'a DIFFERENT endpoint must not inherit the previous one\'s verdict'
        );
    }

    /**
     * A mark that cannot be attributed to an endpoint does not apply.
     *
     * This is a NEGATIVE signal, and an unattributable one must not
     * permanently disable a driver an operator has correctly configured. The
     * choice is self-correcting: if the endpoint really is DAS-incapable,
     * the next call re-writes the mark WITH an endpoint attached.
     */
    #[DataProvider('unattributableMarks')]
    public function testUnattributableDasMarkIsIgnored(mixed $stored): void
    {
        define('BCC_HELIUS_API_KEY', 'real-key');

        $solana = self::chain('solana', 'solana', 'https://das-provider.example/?api-key=k', '', 42);
        NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(42)] = $stored;

        self::assertTrue(NftProviderReadiness::isReady($solana, NftDriverRegistry::DRIVER_DAS_RPC));
    }

    /** @return array<string, array{0: mixed}> */
    public static function unattributableMarks(): array
    {
        return [
            'not an array'      => ['garbage'],
            'empty array'       => [[]],
            'no rpc_url key'    => [['code' => -32601, 'message' => 'Method not found']],
            'empty rpc_url'     => [['rpc_url' => '', 'code' => -32601]],
            'whitespace rpc_url'=> [['rpc_url' => '   ', 'code' => -32601]],
        ];
    }

    /**
     * The redaction masks the whole query string, so the same host with a
     * ROTATED key compares equal and keeps its refusal.
     *
     * Deliberate and conservative: a host that has already proven it cannot
     * serve DAS will not start serving it because the key changed, and the
     * redaction is what keeps the secret out of the stored option.
     */
    public function testRotatingAKeyOnTheSameHostKeepsTheRefusal(): void
    {
        define('BCC_HELIUS_API_KEY', 'real-key');

        NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(42)] = [
            'rpc_url' => HeliusEndpoint::redactEndpoint('https://dead.example/?api-key=OLD'),
            'code'    => -32601,
        ];

        self::assertFalse(
            NftProviderReadiness::isReady(
                self::chain('solana', 'solana', 'https://dead.example/?api-key=NEW', '', 42),
                NftDriverRegistry::DRIVER_DAS_RPC
            )
        );
    }

    /** The mark is per chain — it must not leak onto another chain's row. */
    public function testDasMarkIsScopedToItsOwnChain(): void
    {
        define('BCC_HELIUS_API_KEY', 'real-key');

        $rpc = 'https://das-provider.example/?api-key=k';
        NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(42)] = [
            'rpc_url' => HeliusEndpoint::redactEndpoint($rpc),
            'code'    => -32601,
        ];

        self::assertTrue(
            NftProviderReadiness::isReady(self::chain('solana', 'solana', $rpc, '', 99), NftDriverRegistry::DRIVER_DAS_RPC),
            'chain 99 must not inherit chain 42\'s mark'
        );
    }

    // ── EVM: the seeded URLs are templates, not endpoints ───────────────

    /**
     * THE ALCHEMY CREDENTIAL TRAP.
     *
     * Every EVM chain seeded by schema-chains.php carries a keyless Alchemy
     * template. If those read as configured, a fresh install would claim
     * metadata and wallet discovery on five chains that cannot perform them.
     */
    #[DataProvider('seededEvmRpcUrls')]
    public function testSeededKeylessAlchemyUrlsAreNotConfigured(string $seeded): void
    {
        self::assertFalse(AlchemyEndpoint::isConfigured($seeded));
        self::assertNull(AlchemyEndpoint::nftBaseFromRpcUrl($seeded));

        $chain = self::chain('evm', 'ethereum', $seeded);
        self::assertFalse(NftProviderReadiness::isReady($chain, NftDriverRegistry::DRIVER_ALCHEMY_NFT));
        self::assertFalse(NftProviderReadiness::isReady($chain, NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS));
    }

    /** @return array<string, array{0: string}> */
    public static function seededEvmRpcUrls(): array
    {
        return [
            'ethereum' => ['https://eth-mainnet.g.alchemy.com/v2/'],
            'polygon'  => ['https://polygon-mainnet.g.alchemy.com/v2/'],
            'arbitrum' => ['https://arb-mainnet.g.alchemy.com/v2/'],
            'optimism' => ['https://opt-mainnet.g.alchemy.com/v2/'],
            'base'     => ['https://base-mainnet.g.alchemy.com/v2/'],
        ];
    }

    /** Public RPCs never match the Alchemy shape at all. */
    #[DataProvider('publicEvmRpcUrls')]
    public function testPublicRpcsAreNotAlchemy(string $url): void
    {
        self::assertFalse(AlchemyEndpoint::isConfigured($url));
    }

    /** @return array<string, array{0: string}> */
    public static function publicEvmRpcUrls(): array
    {
        return [
            'avalanche'          => ['https://api.avax.network/ext/bc/C/rpc'],
            'bsc'                => ['https://bsc-dataseed.binance.org'],
            'empty'              => [''],
            'lookalike host'     => ['https://eth-mainnet.g.alchemy.com.evil.test/v2/key'],
            'http not https'     => ['http://eth-mainnet.g.alchemy.com/v2/key'],
        ];
    }

    public function testKeyedAlchemyUrlIsConfigured(): void
    {
        $url   = 'https://eth-mainnet.g.alchemy.com/v2/abc123_KEY-9';
        $chain = self::chain('evm', 'ethereum', $url);

        self::assertTrue(AlchemyEndpoint::isConfigured($url));
        self::assertSame('https://eth-mainnet.g.alchemy.com/nft/v3/abc123_KEY-9', AlchemyEndpoint::nftBaseFromRpcUrl($url));
        self::assertTrue(NftProviderReadiness::isReady($chain, NftDriverRegistry::DRIVER_ALCHEMY_NFT));
    }

    /**
     * Avalanche and BSC keep plain-RPC ownership on their public endpoints
     * — `eth_call balanceOf` needs no Alchemy key. This is the fact that
     * keeps ERC-721 gating alive there.
     */
    public function testPlainEvmRpcOwnershipWorksOnPublicEndpoints(): void
    {
        $avalanche = self::chain('evm', 'avalanche', 'https://api.avax.network/ext/bc/C/rpc');

        self::assertTrue(NftProviderReadiness::isReady($avalanche, NftDriverRegistry::DRIVER_EVM_RPC));
        self::assertFalse(NftProviderReadiness::isReady($avalanche, NftDriverRegistry::DRIVER_ALCHEMY_NFT));
    }

    public function testEvmRpcIsNotReadyWithoutAnRpcUrl(): void
    {
        self::assertFalse(
            NftProviderReadiness::isReady(self::chain('evm', 'ethereum', ''), NftDriverRegistry::DRIVER_EVM_RPC)
        );
    }

    // ── Cosmos ──────────────────────────────────────────────────────────

    public function testCosmosDriversNeedARestEndpoint(): void
    {
        $withRest    = self::chain('cosmos', 'cosmos', '', 'https://rest.example');
        $withoutRest = self::chain('cosmos', 'cosmos', 'https://rpc.example', '');

        self::assertTrue(NftProviderReadiness::isReady($withRest, NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION));
        self::assertFalse(NftProviderReadiness::isReady($withoutRest, NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION));
    }

    /** Whitespace is not an endpoint. */
    public function testWhitespaceRestUrlIsNotReady(): void
    {
        self::assertFalse(
            NftProviderReadiness::isReady(
                self::chain('cosmos', 'cosmos', '', '   '),
                NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION
            )
        );
    }

    // ── Cross-family and unknown input fail closed ──────────────────────

    /**
     * A driver that does not serve this chain is never ready FOR it.
     *
     * Without the registry guard, `isReady(solana, evm_rpc)` would answer
     * yes purely because Solana's `rpc_url` is non-empty — a readiness check
     * accidentally satisfied by an unrelated column.
     */
    public function testDriverIsNeverReadyForAChainItDoesNotServe(): void
    {
        $solana = self::chain('solana', 'solana', 'https://api.mainnet-beta.solana.com');
        $cosmos = self::chain('cosmos', 'cosmos', '', 'https://rest.example');

        self::assertFalse(NftProviderReadiness::isReady($solana, NftDriverRegistry::DRIVER_EVM_RPC));
        self::assertFalse(NftProviderReadiness::isReady($cosmos, NftDriverRegistry::DRIVER_ALCHEMY_NFT));
        self::assertFalse(NftProviderReadiness::isReady($solana, NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION));
    }

    #[DataProvider('unknownDriverKeys')]
    public function testUnknownDriverKeyIsNeverReady(string $key): void
    {
        self::assertFalse(
            NftProviderReadiness::isReady(self::chain('cosmos', 'cosmos', '', 'https://rest.example'), $key)
        );
    }

    /** @return array<string, array{0: string}> */
    public static function unknownDriverKeys(): array
    {
        return [
            'empty'          => [''],
            'invented'       => ['evm_enumeration'],
            'future build'   => ['alchemy_v4'],
            'retired intake' => ['user_request'],
        ];
    }

    // ── The helper shapes ───────────────────────────────────────────────

    public function testReadyDriversFiltersAndPreservesOrder(): void
    {
        $avalanche = self::chain('evm', 'avalanche', 'https://api.avax.network/ext/bc/C/rpc');

        self::assertSame(
            [NftDriverRegistry::DRIVER_EVM_RPC],
            NftProviderReadiness::readyDrivers($avalanche, [
                NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS,
                NftDriverRegistry::DRIVER_EVM_RPC,
            ])
        );
    }

    public function testReadinessMapKeepsEveryDriverAsked(): void
    {
        $avalanche = self::chain('evm', 'avalanche', 'https://api.avax.network/ext/bc/C/rpc');

        self::assertSame(
            [
                NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS => false,
                NftDriverRegistry::DRIVER_EVM_RPC           => true,
            ],
            NftProviderReadiness::readinessMap($avalanche, [
                NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS,
                NftDriverRegistry::DRIVER_EVM_RPC,
            ])
        );
    }

    public function testEmptyDriverListYieldsEmptyResults(): void
    {
        $chain = self::chain('evm', 'ethereum', 'https://eth-mainnet.g.alchemy.com/v2/k');

        self::assertSame([], NftProviderReadiness::readyDrivers($chain, []));
        self::assertSame([], NftProviderReadiness::readinessMap($chain, []));
    }
}
