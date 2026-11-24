<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy;
use BCC\Trust\Onchain\ValueObjects\CosmosTxProofEndpoints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The memo claim's proof endpoints, and the pinned networks they are checked
 * against. The rules are the security review's: two independent operators
 * per chain, public https endpoints, and a network id pinned in code.
 */
final class CosmosTxProofEndpointsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
    }

    /** Exactly the chains verified live on 2026-11-24 with two operators. */
    public function testTheConfiguredChainsAreTheVerifiedOnes(): void
    {
        self::assertSame(['cosmos', 'osmosis', 'akash', 'juno', 'injective'], CosmosTxProofEndpoints::listedSlugs());
        foreach (['cryptoorgchain', 'jackal', 'kujira', 'dungeon', 'thorchain', 'ethereum', ''] as $slug) {
            self::assertFalse(CosmosTxProofEndpoints::isSupported($slug), $slug);
            self::assertSame([], CosmosTxProofEndpoints::forSlug($slug), $slug);
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function listedSlugs(): iterable
    {
        foreach (['cosmos', 'osmosis', 'akash', 'juno', 'injective'] as $slug) {
            yield $slug => [$slug];
        }
    }

    /** Every listed chain keeps every rule, so none is refused by its own config. */
    #[DataProvider('listedSlugs')]
    public function testEveryListedChainKeepsTheRules(string $slug): void
    {
        $entries = CosmosTxProofEndpoints::forSlug($slug);

        self::assertTrue(CosmosTxProofEndpoints::isSupported($slug));
        self::assertGreaterThanOrEqual(2, count($entries));
        self::assertNotNull(CosmosEndpointPolicy::pinnedNetwork($slug));
        $operators = array_map(static fn (array $e): string => $e['operator'], $entries);
        self::assertSame($operators, array_values(array_unique($operators)), 'different operators');
        foreach ($entries as $entry) {
            foreach (['rpc', 'rest'] as $kind) {
                self::assertSame($entry[$kind], CosmosEndpointPolicy::normalize($entry[$kind]), "{$slug} {$kind}: normalized https");
                self::assertStringNotContainsString('cosmos.directory', $entry[$kind], 'a multi-backend proxy is no single operator');
            }
        }
    }

    /** The pins, as read from live nodes of two operators. */
    public function testThePinnedNetworks(): void
    {
        self::assertSame('cosmoshub-4', CosmosEndpointPolicy::pinnedNetwork('cosmos'));
        self::assertSame('osmosis-1', CosmosEndpointPolicy::pinnedNetwork('osmosis'));
        self::assertSame('akashnet-2', CosmosEndpointPolicy::pinnedNetwork('akash'));
        self::assertSame('juno-1', CosmosEndpointPolicy::pinnedNetwork('juno'));
        self::assertSame('injective-1', CosmosEndpointPolicy::pinnedNetwork('injective'));
        self::assertNull(CosmosEndpointPolicy::pinnedNetwork('kujira'));
    }

    /** The account prefixes, pinned beside the networks (never the chains row). */
    public function testThePinnedAccountPrefixes(): void
    {
        self::assertSame('cosmos', CosmosEndpointPolicy::pinnedAccountPrefix('cosmos'));
        self::assertSame('osmo', CosmosEndpointPolicy::pinnedAccountPrefix('osmosis'));
        self::assertSame('akash', CosmosEndpointPolicy::pinnedAccountPrefix('akash'));
        self::assertSame('juno', CosmosEndpointPolicy::pinnedAccountPrefix('juno'));
        self::assertSame('inj', CosmosEndpointPolicy::pinnedAccountPrefix('injective'));
        self::assertNull(CosmosEndpointPolicy::pinnedAccountPrefix('kujira'));
        foreach (CosmosTxProofEndpoints::listedSlugs() as $slug) {
            self::assertNotNull(CosmosEndpointPolicy::pinnedAccountPrefix($slug), $slug);
        }
    }

    /** One source: discovery's governed network IS the pin, and governance did not widen. */
    public function testDiscoveryGovernanceReadsThePinAndIsUnchanged(): void
    {
        self::assertSame(CosmosEndpointPolicy::pinnedNetwork('cosmos'), CosmosEndpointPolicy::expectedNetwork('cosmos'));
        self::assertSame(['cosmos'], CosmosEndpointPolicy::governedSlugs());
        self::assertNull(CosmosEndpointPolicy::expectedNetwork('osmosis'), 'pinning a network does not govern discovery');

        $url = 'https://cosmos-api.polkachu.com';
        self::assertSame(CosmosEndpointPolicy::fingerprint('cosmos', $url), CosmosEndpointPolicy::pinnedFingerprint('cosmos', $url));
        self::assertNull(CosmosEndpointPolicy::fingerprint('osmosis', 'https://osmosis-rpc.polkachu.com'), 'discovery fingerprints unchanged');
        self::assertNotNull(CosmosEndpointPolicy::pinnedFingerprint('osmosis', 'https://osmosis-rpc.polkachu.com'));
        self::assertNotSame(
            CosmosEndpointPolicy::pinnedFingerprint('osmosis', 'https://osmosis-rpc.polkachu.com'),
            CosmosEndpointPolicy::pinnedFingerprint('juno', 'https://osmosis-rpc.polkachu.com'),
            'the pin is part of the key, so a changed pin is a new cache key'
        );
    }

    private const A = ['operator' => 'Polkachu', 'rpc' => 'https://cosmos-rpc.polkachu.com', 'rest' => 'https://cosmos-api.polkachu.com'];
    private const B = ['operator' => 'Allnodes (PublicNode)', 'rpc' => 'https://cosmos-rpc.publicnode.com', 'rest' => 'https://cosmos-rest.publicnode.com'];

    /** @return iterable<string, array{0: string, 1: list<array{operator: string, rpc: string, rest: string}>, 2: string}> */
    public static function brokenConfigs(): iterable
    {
        yield 'one operator'           => ['cosmos', [self::A], 'fewer than 2 operators'];
        yield 'the same operator twice' => ['cosmos', [self::A, ['operator' => 'polkachu '] + self::B], 'an operator is listed twice'];
        yield 'one domain twice'       => ['cosmos', [self::A, ['rpc' => 'https://rpc2.polkachu.com', 'rest' => 'https://api2.polkachu.com'] + self::B], 'two entries share a domain'];
        yield 'plain http'             => ['cosmos', [self::A, ['rpc' => 'http://cosmos-rpc.publicnode.com'] + self::B], 'entry 1: rpc is not a normalized https url'];
        yield 'a trailing slash'       => ['cosmos', [self::A, ['rest' => 'https://cosmos-rest.publicnode.com/'] + self::B], 'entry 1: rest is not a normalized https url'];
        yield 'rpc and rest split'     => ['cosmos', [self::A, ['rest' => 'https://cosmos-api.otherop.com'] + self::B], "entry 1: rpc and rest are on different operators' domains"];
        yield 'no operator name'       => ['cosmos', [self::A, ['operator' => ' '] + self::B], 'entry 1: no operator'];
        yield 'no pinned network'      => ['kujira', [self::A, self::B], 'no pinned network'];
        yield 'no pinned prefix'       => ['kujira', [self::A, self::B], 'no pinned account prefix'];
    }

    /**
     * Each rule is enforced at read time, so a bad edit refuses claims on
     * that chain instead of trusting fewer operators.
     *
     * @param list<array{operator: string, rpc: string, rest: string}> $entries
     */
    #[DataProvider('brokenConfigs')]
    public function testABrokenConfigIsCaught(string $slug, array $entries, string $problem): void
    {
        self::assertContains($problem, CosmosTxProofEndpoints::problems($slug, $entries));
    }

    public function testAValidConfigHasNoProblems(): void
    {
        self::assertSame([], CosmosTxProofEndpoints::problems('cosmos', [self::A, self::B]));
    }
}
