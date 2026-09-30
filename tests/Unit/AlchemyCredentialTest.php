<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\AlchemyCredential;
use BCC\Trust\Onchain\Support\AlchemyEndpoint;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use BCC\Trust\Onchain\Support\NftProviderReadiness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * One secure source for the Alchemy credential.
 *
 * ── ⚠⚠⚠ THE THINGS THAT MUST NOT HAPPEN ─────────────────────────────────
 *  1. THE CREDENTIAL ENDS UP IN THE DATABASE AGAIN. A key in
 *     `bcc_chains.rpc_url` is in every backup, every export, every read-only
 *     debugging session, and on screen in any page that prints the row — which is
 *     how a live key leaked on 2026-09-30.
 *  2. A MISSING CREDENTIAL BECOMES A VERDICT. Absent configuration must read as
 *     UNAVAILABLE, never INVALID. INVALID is a statement about the contract, and
 *     recording one because nobody set a constant is a permanent false negative.
 *  3. A BLOCKED REQUEST STILL CALLS THE PROVIDER. Returning the right value
 *     after firing the request spends quota and hands a credentialed URL to a
 *     provider log. Counted, not assumed — see {@see AlchemyHttpSpy}.
 *  4. THE REFACTOR QUIETLY LAUNCHES CHAINS. `driverSupportsChain()` gates the
 *     Alchemy drivers on `chain_type === 'evm'` alone, so Polygon, Arbitrum and
 *     Optimism are held back ONLY by their keyless rpc_url. Resolving them from
 *     the constant would flip all three to ready with no operator action.
 *  5. `eth_call` LOSES ITS PUBLIC-RPC CHAINS. Avalanche and BSC gate ERC-721
 *     ownership through a public node with no credential. Routing standard
 *     JSON-RPC through the Alchemy resolver would silently remove token gating
 *     from two live chains.
 *
 * Constants cannot be un-defined once set, so every case that needs a particular
 * credential state runs in its own process.
 */
#[CoversClass(AlchemyCredential::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AlchemyCredentialTest extends TestCase
{
    private const KEY = 'AlchemyK3yMaterial0123456789';

    /**
     * ⚠⚠ THE STUBS LOAD HERE, NOT AT FILE SCOPE.
     *
     * `#[RunTestsInSeparateProcesses]` means `setUp()` runs in a CHILD process,
     * while a file-scope `require_once` is executed in the PARENT during test
     * discovery. The stub file declares `BCC\Core\Http\SafeHttpClient`, and
     * `XShareVerificationTest` declares the same class unguarded at ITS file
     * scope — so loading here in the parent took the name and made that other
     * file a fatal error. Loading per-process keeps the fake confined to the
     * tests that asked for it.
     */
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/alchemy-credential-stubs.php';
        \BCC\Trust\Onchain\Support\AlchemyHttpSpy::reset();
    }

    /** A chain row shaped like `ChainRepository` returns. */
    private static function chain(string $slug, ?string $hex, string $rpc = '', string $type = 'evm'): object
    {
        return (object) [
            'id'                           => '7',
            'slug'                         => $slug,
            'name'                         => ucfirst($slug),
            'chain_type'                   => $type,
            'chain_id_hex'                 => $hex,
            'rpc_url'                      => $rpc,
            'rest_url'                     => '',
            'bcc_supports_nft_collections' => '1',
        ];
    }

    /** The five EVM chains schema-chains.php seeds with keyless Alchemy templates. */
    private const SEEDED_TEMPLATE = [
        'ethereum' => ['0x1',    'https://eth-mainnet.g.alchemy.com/v2/'],
        'polygon'  => ['0x89',   'https://polygon-mainnet.g.alchemy.com/v2/'],
        'arbitrum' => ['0xa4b1', 'https://arb-mainnet.g.alchemy.com/v2/'],
        'optimism' => ['0xa',    'https://opt-mainnet.g.alchemy.com/v2/'],
        'base'     => ['0x2105', 'https://base-mainnet.g.alchemy.com/v2/'],
    ];

    // ── 1. ⚠⚠ THE LAUNCH CHAINS KEEP WORKING FROM A KEYLESS ROW ──────────

    /** @return array<string, array{0: string, 1: string, 2: string}> slug, hex, expected host */
    public static function launchChains(): array
    {
        return [
            'ethereum' => ['ethereum', '0x1',    'eth-mainnet.g.alchemy.com'],
            'base'     => ['base',     '0x2105', 'base-mainnet.g.alchemy.com'],
        ];
    }

    #[DataProvider('launchChains')]
    public function testALaunchChainResolvesFromTheConstantWithAKeylessRow(string $slug, string $hex, string $host): void
    {
        define('BCC_ALCHEMY_API_KEY', self::KEY);

        $template = self::SEEDED_TEMPLATE[$slug][1];
        $url      = AlchemyCredential::rpcUrlFor(self::chain($slug, $hex, $template));

        self::assertIsString($url, 'a launch chain must resolve from the secure source alone');
        self::assertSame('https://' . $host . '/v2/' . rawurlencode(self::KEY), $url);

        // ⚠ And it is a real, usable Alchemy endpoint by the shared definition —
        // not merely a string that looks like one.
        self::assertTrue(AlchemyEndpoint::isConfigured($url));
        self::assertSame('https://' . $host . '/nft/v3/' . rawurlencode(self::KEY), AlchemyCredential::nftBaseFor(self::chain($slug, $hex, $template)));
    }

    #[DataProvider('launchChains')]
    public function testALaunchChainResolvesEvenWithAnEmptyRow(string $slug, string $hex, string $host): void
    {
        define('BCC_ALCHEMY_API_KEY', self::KEY);

        // ⚠ The row may be a keyless template OR empty. Neither is a credential,
        // and the whole point is that the row need not carry one.
        self::assertIsString(AlchemyCredential::rpcUrlFor(self::chain($slug, $hex, '')));
        self::assertIsString(AlchemyCredential::rpcUrlFor(self::chain($slug, $hex, null ?? '')));
    }

    #[DataProvider('launchChains')]
    public function testReadinessFollowsTheSecureSource(string $slug, string $hex): void
    {
        define('BCC_ALCHEMY_API_KEY', self::KEY);

        $chain = self::chain($slug, $hex, self::SEEDED_TEMPLATE[$slug][1]);
        foreach ([NftDriverRegistry::DRIVER_ALCHEMY_NFT, NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS] as $driver) {
            self::assertTrue(
                NftProviderReadiness::isReady($chain, $driver),
                "{$driver} must be ready on {$slug} once the constant is set"
            );
        }
    }

    // ── 2. ⚠⚠⚠ A MISSING CREDENTIAL IS UNAVAILABLE, NOT INVALID ──────────

    #[DataProvider('launchChains')]
    public function testNoConstantMeansUnresolvableRatherThanAnythingElse(string $slug, string $hex): void
    {
        // No define() at all — the constant is absent.
        $chain = self::chain($slug, $hex, self::SEEDED_TEMPLATE[$slug][1]);

        self::assertNull(AlchemyCredential::rpcUrlFor($chain));
        self::assertNull(AlchemyCredential::nftBaseFor($chain));
        self::assertFalse(AlchemyCredential::isConfigured());

        // ⚠ Unresolvable, but the chain IS supported — the distinction that keeps
        // "fix your wp-config" separate from "this build cannot reach that chain".
        self::assertTrue(AlchemyCredential::supportsChain($chain));

        $status = AlchemyCredential::status($chain);
        self::assertFalse($status->isConfigured());
        self::assertTrue($status->isResolverAvailable(), 'a launch chain always has a resolver');
        self::assertSame('alchemy: unconfigured', $status->summary());
    }

    /**
     * ⚠⚠ DEFINED IS NOT CONFIGURED.
     *
     * `define('BCC_ALCHEMY_API_KEY', '')` is a real production shape — a stripped
     * staging config, a secret that failed to inject, a half-finished
     * provisioning step. Read with `defined()` alone it says configured and
     * produces a URL that cannot authenticate.
     */
    public function testAnEmptyConstantIsNotConfigured(): void
    {
        define('BCC_ALCHEMY_API_KEY', '');

        self::assertFalse(AlchemyCredential::isConfigured());
        self::assertNull(AlchemyCredential::rpcUrlFor(self::chain('ethereum', '0x1')));
    }

    public function testAWhitespaceConstantIsNotConfigured(): void
    {
        define('BCC_ALCHEMY_API_KEY', "  \n ");

        self::assertFalse(AlchemyCredential::isConfigured());
        self::assertNull(AlchemyCredential::rpcUrlFor(self::chain('ethereum', '0x1')));
    }

    /** A key with surrounding whitespace is used, trimmed, not rejected. */
    public function testAKeyWithSurroundingWhitespaceIsTrimmedNotRejected(): void
    {
        define('BCC_ALCHEMY_API_KEY', '  ' . self::KEY . "\n");

        $url = AlchemyCredential::rpcUrlFor(self::chain('ethereum', '0x1'));
        self::assertSame('https://eth-mainnet.g.alchemy.com/v2/' . rawurlencode(self::KEY), $url);
    }

    /**
     * ⚠⚠ A KEY THAT CANNOT ADDRESS ALCHEMY IS NOT A CREDENTIAL.
     *
     * {@see AlchemyEndpoint}'s pattern allows only `[A-Za-z0-9_-]` in the key
     * segment, and `rawurlencode()` introduces `%`, which is not among them. So a
     * key with a URL-significant character produced a URL that
     * {@see AlchemyCredential::rpcUrlFor()} returned and
     * {@see AlchemyCredential::nftBaseFor()} then rejected — two methods on one
     * class disagreeing about whether a chain is usable.
     *
     * ⚠ This gap was found by a mutation, not by review: removing
     * `rawurlencode()` altogether survived the whole suite, because every fixture
     * used an alphanumeric key and encoding was therefore a no-op on all of them.
     *
     * @return array<string, array{0: string}>
     */
    public static function unusableKeyShapes(): array
    {
        return [
            'path separator'   => ['abc/def'],
            'query separator'  => ['abc?def'],
            'fragment'         => ['abc#def'],
            'inner space'      => ['abc def'],
            'at sign'          => ['abc@def'],
            'colon'            => ['abc:def'],
            'percent'          => ['abc%2Fdef'],
            'newline inside'   => ["abc\ndef"],
            'full url'         => ['https://evil.example/v2/x'],
        ];
    }

    #[DataProvider('unusableKeyShapes')]
    public function testAKeyThatCannotFormAnAlchemyEndpointIsRejected(string $bad): void
    {
        define('BCC_ALCHEMY_API_KEY', $bad);

        self::assertFalse(AlchemyCredential::isConfigured(), 'a malformed key must not read as configured');

        $chain = self::chain('ethereum', '0x1');
        self::assertNull(AlchemyCredential::rpcUrlFor($chain));

        // ⚠ The point of the fix: the two methods agree. Before it, one returned
        // a URL and the other rejected the same URL.
        self::assertNull(AlchemyCredential::nftBaseFor($chain));
        self::assertFalse(NftProviderReadiness::isReady($chain, NftDriverRegistry::DRIVER_ALCHEMY_NFT));
    }

    /**
     * ⚠ Whatever a resolved URL is, the NFT base is derivable from it.
     *
     * The invariant the mutation broke, stated directly rather than through a
     * fixture: `rpcUrlFor()` and `nftBaseFor()` must never disagree about a chain.
     */
    public function testAResolvedEndpointAlwaysYieldsAnNftBase(): void
    {
        define('BCC_ALCHEMY_API_KEY', self::KEY);

        foreach (['0x1', '0x2105', '0x89', '0xdead', null, ''] as $hex) {
            $chain = self::chain('c', $hex, '');
            self::assertSame(
                AlchemyCredential::rpcUrlFor($chain) === null,
                AlchemyCredential::nftBaseFor($chain) === null,
                "rpcUrlFor() and nftBaseFor() disagree for chain_id_hex " . var_export($hex, true)
            );
        }
    }

    // ── 3. ⚠⚠⚠ UNSUPPORTED CHAINS STAY DISABLED ──────────────────────────

    /** @return array<string, array{0: string, 1: string}> */
    public static function nonLaunchAlchemySeeded(): array
    {
        return [
            'polygon'  => ['polygon',  '0x89'],
            'arbitrum' => ['arbitrum', '0xa4b1'],
            'optimism' => ['optimism', '0xa'],
        ];
    }

    /**
     * A seeded-but-unlaunched Alchemy chain must NOT become resolvable just
     * because the constant is set.
     *
     * ⚠ This is the regression the refactor would otherwise have been credited
     * for. `driverSupportsChain()` gates the Alchemy drivers on
     * `chain_type === 'evm'` alone, so today Polygon, Arbitrum and Optimism are
     * held back ONLY by their keyless rpc_url. A resolver that mapped every
     * seeded Alchemy network would flip all three to READY the instant a key is
     * present — launching three chains DECISION 7 excludes, silently, with no
     * migration to point at.
     */
    #[DataProvider('nonLaunchAlchemySeeded')]
    public function testASeededButUnlaunchedChainStaysUnresolvable(string $slug, string $hex): void
    {
        define('BCC_ALCHEMY_API_KEY', self::KEY);

        $chain = self::chain($slug, $hex, self::SEEDED_TEMPLATE[$slug][1]);

        self::assertNull(AlchemyCredential::rpcUrlFor($chain), "{$slug} must not resolve from the constant");
        self::assertFalse(AlchemyCredential::supportsChain($chain));

        foreach ([NftDriverRegistry::DRIVER_ALCHEMY_NFT, NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS] as $driver) {
            self::assertFalse(
                NftProviderReadiness::isReady($chain, $driver),
                "{$driver} must stay unready on {$slug} — it is not a launch chain"
            );
        }

        // ⚠ Reported as resolver-unavailable, so an operator is not sent to
        // wp-config.php to fix something no credential can fix.
        $status = AlchemyCredential::status($chain);
        self::assertFalse($status->isResolverAvailable());
        self::assertSame('alchemy: no resolver', $status->summary());
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function publicRpcChains(): array
    {
        return [
            'avalanche' => ['avalanche', '0xa86a', 'https://api.avax.network/ext/bc/C/rpc'],
            'bsc'       => ['bsc',       '0x38',   'https://bsc-dataseed.binance.org/'],
        ];
    }

    #[DataProvider('publicRpcChains')]
    public function testAPublicRpcChainNeverResolvesAnAlchemyEndpoint(string $slug, string $hex, string $rpc): void
    {
        define('BCC_ALCHEMY_API_KEY', self::KEY);

        $chain = self::chain($slug, $hex, $rpc);

        self::assertNull(AlchemyCredential::rpcUrlFor($chain));
        self::assertFalse(AlchemyCredential::supportsChain($chain));
        self::assertFalse(NftProviderReadiness::isReady($chain, NftDriverRegistry::DRIVER_ALCHEMY_NFT));

        // ⚠⚠ But standard JSON-RPC gating SURVIVES. Avalanche and BSC prove
        // ERC-721 ownership through these public nodes, and this is the
        // assertion that would have caught routing `eth_call` through the
        // Alchemy resolver.
        self::assertTrue(
            NftProviderReadiness::isReady($chain, NftDriverRegistry::DRIVER_EVM_RPC),
            'eth_call gating must not depend on an Alchemy credential'
        );
    }

    /** A non-EVM chain is never an Alchemy chain, whatever its hex says. */
    public function testANonEvmChainIsNeverSupported(): void
    {
        define('BCC_ALCHEMY_API_KEY', self::KEY);

        foreach (['solana', 'cosmos', ''] as $type) {
            $chain = self::chain('whatever', '0x1', '', $type);
            self::assertFalse(AlchemyCredential::supportsChain($chain), "chain_type={$type} must not be Alchemy-supported");
            self::assertNull(AlchemyCredential::rpcUrlFor($chain));
        }
    }

    /** @return array<string, array{0: ?string}> */
    public static function unmappedHexValues(): array
    {
        return [
            'null'          => [null],
            'empty'         => [''],
            'whitespace'    => ['   '],
            'decimal'       => ['1'],
            'unknown chain' => ['0xdead'],
            'not hex'       => ['ethereum'],
        ];
    }

    #[DataProvider('unmappedHexValues')]
    public function testAnUnmappedChainIdIsNotSupported(?string $hex): void
    {
        define('BCC_ALCHEMY_API_KEY', self::KEY);

        self::assertNull(AlchemyCredential::rpcUrlFor(self::chain('mystery', $hex)));
    }

    /** ⚠ Case and whitespace in `chain_id_hex` must not create a second chain. */
    public function testChainIdHexIsMatchedCaseInsensitivelyAndTrimmed(): void
    {
        define('BCC_ALCHEMY_API_KEY', self::KEY);

        foreach (['0x2105', '0X2105', ' 0x2105 ', "0x2105\n"] as $hex) {
            self::assertIsString(
                AlchemyCredential::rpcUrlFor(self::chain('base', $hex)),
                "chain_id_hex '{$hex}' must resolve to Base"
            );
        }
    }

    // ── 4. THE TRANSITIONAL PATH: A ROW THAT ALREADY HOLDS A KEY ─────────

    /**
     * A row carrying a complete keyed Alchemy URL keeps working, and wins.
     *
     * Production and staging rows hold real credentials right now. Removing them
     * is a separate guarded procedure, precisely so this change cannot break a
     * working install the moment it deploys.
     */
    public function testARowThatAlreadyHoldsAKeyedEndpointKeepsWorking(): void
    {
        // No constant at all — the row is the only source.
        $row   = 'https://eth-mainnet.g.alchemy.com/v2/ROWKEY';
        $chain = self::chain('ethereum', '0x1', $row);

        self::assertSame($row, AlchemyCredential::rpcUrlFor($chain));
        self::assertTrue(NftProviderReadiness::isReady($chain, NftDriverRegistry::DRIVER_ALCHEMY_NFT));
    }

    /**
     * ⚠ An off-map chain with a hand-configured keyed row also keeps working.
     *
     * Without this, an operator who had finished the Polygon template themselves
     * would silently lose NFT discovery — a regression introduced by a change
     * whose stated purpose is that nothing regresses.
     */
    public function testAnOffMapChainWithAHandConfiguredKeyKeepsWorking(): void
    {
        $row   = 'https://polygon-mainnet.g.alchemy.com/v2/HANDKEY';
        $chain = self::chain('polygon', '0x89', $row);

        self::assertSame($row, AlchemyCredential::rpcUrlFor($chain));
        self::assertTrue(NftProviderReadiness::isReady($chain, NftDriverRegistry::DRIVER_ALCHEMY_NFT));

        $status = AlchemyCredential::status($chain);
        self::assertTrue($status->isConfigured());
        self::assertSame('polygon-mainnet.g.alchemy.com', $status->hostname());
    }

    /** A row with a NON-Alchemy host never wins, even when it looks complete. */
    public function testACredentialIsNeverSentToANonAlchemyHost(): void
    {
        define('BCC_ALCHEMY_API_KEY', self::KEY);

        foreach ([
            'https://evil.example/v2/STOLEN',
            'https://eth-mainnet.g.alchemy.com.evil.example/v2/STOLEN',
            'http://eth-mainnet.g.alchemy.com/v2/STOLEN',
        ] as $hostile) {
            $url = AlchemyCredential::rpcUrlFor(self::chain('ethereum', '0x1', $hostile));

            // The row loses, and the resolver builds the canonical endpoint.
            self::assertSame('https://eth-mainnet.g.alchemy.com/v2/' . rawurlencode(self::KEY), $url);
            self::assertStringNotContainsString('evil.example', (string) $url);
        }
    }

    // ── 5. ⚠⚠⚠ NOTHING LEAKS, AND NOTHING IS CALLED ──────────────────────

    /**
     * Resolving a credential makes NO provider call.
     *
     * A resolver is pure. Counted rather than assumed, because a resolver that
     * "verified" the endpoint would spend quota on every readiness render —
     * which is a page-load path.
     */
    public function testResolvingMakesNoProviderCall(): void
    {
        define('BCC_ALCHEMY_API_KEY', self::KEY);

        $chain = self::chain('base', '0x2105');
        AlchemyCredential::rpcUrlFor($chain);
        AlchemyCredential::nftBaseFor($chain);
        AlchemyCredential::status($chain);
        AlchemyCredential::isConfigured();
        NftProviderReadiness::isReady($chain, NftDriverRegistry::DRIVER_ALCHEMY_NFT);
        NftProviderReadiness::configStatus($chain, NftDriverRegistry::DRIVER_ALCHEMY_NFT);

        self::assertSame(0, \BCC\Trust\Onchain\Support\AlchemyHttpSpy::count(), 'resolution must be pure');
    }

    /**
     * ⚠⚠⚠ A BLOCKED REQUEST MAKES NO PROVIDER CALL.
     *
     * With no credential, every Alchemy-bound fetcher method must refuse BEFORE
     * the transport. Returning the right value after firing the request would
     * spend quota and hand a credentialed URL to a provider log — worse than the
     * bug it replaced.
     */
    public function testABlockedRequestMakesNoProviderCall(): void
    {
        // No constant, keyless row: nothing can resolve.
        $chain   = self::chain('ethereum', '0x1', self::SEEDED_TEMPLATE['ethereum'][1]);
        $fetcher = new \BCC\Trust\Onchain\Fetchers\EvmFetcher($chain);

        self::assertNull($fetcher->fetchContractMetadata('0x' . str_repeat('a', 40)));
        self::assertNull($fetcher->fetchMetadataForToken('0x' . str_repeat('a', 40), '1'));

        $meta = $fetcher->contractMetadataResult('0x' . str_repeat('a', 40));
        self::assertFalse($meta['ok']);
        self::assertSame('credentials_missing', $meta['kind'], 'a missing credential is UNAVAILABLE, never a verdict');

        $call = $fetcher->ethCallResult('0x' . str_repeat('a', 40), '0x01ffc9a7');
        self::assertFalse($call['ok']);
        self::assertSame('credentials_missing', $call['kind']);

        self::assertSame(
            0,
            \BCC\Trust\Onchain\Support\AlchemyHttpSpy::count(),
            'a blocked request reached the transport: ' . \BCC\Trust\Onchain\Support\AlchemyHttpSpy::count() . ' call(s)'
        );
    }

    /**
     * ⚠⚠⚠ `eth_call` STILL RESOLVES A PUBLIC NODE WITH NO CREDENTIAL ANYWHERE.
     *
     * The mirror of the test above, and the one that fails if standard JSON-RPC
     * is routed through the Alchemy resolver. Without it, "no provider call was
     * made" would be satisfied by a version that calls nothing at all — so this
     * is what stops the refactor from silently removing ERC-721 gating from
     * Avalanche and BSC and being credited as a security improvement.
     *
     * ── WHY THIS ASSERTS THE RESOLVER AND NOT THE TRANSPORT ─────────────
     * Reaching `wp_remote_post()` for real pulls in the circuit breaker, which
     * pulls in the object cache, transients, `is_wp_error()` and
     * `BCC\Core\DB\AdvisoryLock` from the sibling plugin. Faking all of that to
     * observe one URL would rebuild the integration bootstrap inside a unit test,
     * and every one of those fakes would be a place the test could pass while
     * lying.
     *
     * The claim being made is about ENDPOINT SELECTION, which is
     * `jsonRpcUrl()`'s whole job, so that is what is asserted — via reflection,
     * because the method is correctly private. The transport beyond it is
     * exercised by the integration suite.
     */
    #[DataProvider('publicRpcChains')]
    public function testEthCallResolvesAPublicNodeWithNoCredential(string $slug, string $hex, string $rpc): void
    {
        $chain   = self::chain($slug, $hex, $rpc);
        $fetcher = new \BCC\Trust\Onchain\Fetchers\EvmFetcher($chain);

        $m = new \ReflectionMethod($fetcher, 'jsonRpcUrl');
        $m->setAccessible(true);

        self::assertSame($rpc, $m->invoke($fetcher), 'a public node must be selected with no credential configured');
    }

    /**
     * ⚠ And when Alchemy IS available it is preferred — one endpoint, known
     * quota — without the public fallback being removed.
     */
    public function testAlchemyIsPreferredOverThePublicFallbackWhenAvailable(): void
    {
        define('BCC_ALCHEMY_API_KEY', self::KEY);

        $chain   = self::chain('ethereum', '0x1', self::SEEDED_TEMPLATE['ethereum'][1]);
        $fetcher = new \BCC\Trust\Onchain\Fetchers\EvmFetcher($chain);

        $m = new \ReflectionMethod($fetcher, 'jsonRpcUrl');
        $m->setAccessible(true);

        self::assertSame('https://eth-mainnet.g.alchemy.com/v2/' . rawurlencode(self::KEY), $m->invoke($fetcher));
    }

    /** A keyless Alchemy TEMPLATE is not a usable public node. */
    public function testAKeylessTemplateIsNotTreatedAsAPublicNode(): void
    {
        $chain   = self::chain('ethereum', '0x1', self::SEEDED_TEMPLATE['ethereum'][1]);
        $fetcher = new \BCC\Trust\Onchain\Fetchers\EvmFetcher($chain);

        $call = $fetcher->ethCallResult('0x' . str_repeat('a', 40), '0x01ffc9a7');

        self::assertSame('credentials_missing', $call['kind']);
        self::assertSame(0, \BCC\Trust\Onchain\Support\AlchemyHttpSpy::count(), 'a guaranteed 401 must not be fired');
    }

    /**
     * ⚠⚠⚠ THE CREDENTIAL NEVER REACHES A REPORTABLE SURFACE.
     *
     * The status object is what a panel, a log line and a REST response get.
     */
    public function testTheStatusSurfaceCarriesNoCredential(): void
    {
        define('BCC_ALCHEMY_API_KEY', self::KEY);

        foreach ([self::chain('ethereum', '0x1'), self::chain('base', '0x2105'), self::chain('polygon', '0x89')] as $chain) {
            $status = AlchemyCredential::status($chain);

            foreach ([
                (string) json_encode($status->toArray()),
                (string) json_encode($status),
                $status->summary(),
                print_r($status, true),
                serialize($status),
            ] as $surface) {
                self::assertStringNotContainsString(self::KEY, $surface);
                self::assertStringNotContainsString(rawurlencode(self::KEY), $surface);
                self::assertStringNotContainsString('/v2/', $surface);
                self::assertStringNotContainsString('/nft/v3/', $surface);
            }
        }
    }

    /**
     * ⚠ The credential is not readable back out of the resolver.
     *
     * `key()` is private and stays private: nothing outside the class has a
     * reason to hold the raw value, and a public accessor is how it ends up
     * interpolated into a log line by a well-meaning caller.
     */
    public function testTheRawKeyIsNotExposedByAnyPublicMethod(): void
    {
        define('BCC_ALCHEMY_API_KEY', self::KEY);

        $ref = new \ReflectionClass(AlchemyCredential::class);
        foreach ($ref->getMethods() as $m) {
            if (!$m->isPublic()) {
                continue;
            }
            self::assertNotSame('key', $m->getName(), 'the raw credential must not have a public accessor');
        }

        // Nothing is stored on the class either — no static cache of the key.
        self::assertSame([], $ref->getStaticProperties());
    }
}
