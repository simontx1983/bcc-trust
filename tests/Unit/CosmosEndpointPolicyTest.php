<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\CosmosEndpointVerifier;
use BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANTS: an endpoint is approved only by EXACT identity, that
 * identity includes the path, and the only host approved as primary for the
 * Cosmos Hub is the one that passed every required route family.
 */
#[CoversClass(CosmosEndpointPolicy::class)]
final class CosmosEndpointPolicyTest extends TestCase
{
    private const HUB = 'cosmos';

    protected function setUp(): void
    {
        parent::setUp();
        // Global-namespace shims — see the file's docblock for why they
        // cannot be declared here.
        require_once __DIR__ . '/../Stubs/endpoint-policy-stubs.php';
    }

    // ── normalization ───────────────────────────────────────────────────

    /** @return array<string, array{string, string|null}> */
    public static function urls(): array
    {
        return [
            'plain https'              => ['https://a.test', 'https://a.test'],
            'uppercase host'           => ['https://A.TEST', 'https://a.test'],
            'uppercase scheme'         => ['HTTPS://a.test', 'https://a.test'],
            'trailing slash'           => ['https://a.test/', 'https://a.test'],
            'path kept'                => ['https://a.test/cosmoshub', 'https://a.test/cosmoshub'],
            'path trailing slash'      => ['https://a.test/cosmoshub/', 'https://a.test/cosmoshub'],
            'explicit 443 dropped'     => ['https://a.test:443/x', 'https://a.test/x'],
            'non-default port kept'    => ['https://a.test:8443/x', 'https://a.test:8443/x'],
            'surrounding whitespace'   => ['  https://a.test  ', 'https://a.test'],

            // every one of these must be REFUSED, not repaired
            'http rejected'            => ['http://a.test', null],
            'no scheme'                => ['a.test', null],
            'empty'                    => ['', null],
            'whitespace only'          => ['   ', null],
            'credentials rejected'     => ['https://user:pw@a.test', null],
            'user only rejected'       => ['https://user@a.test', null],
            'query rejected'           => ['https://a.test/x?k=v', null],
            'fragment rejected'        => ['https://a.test/x#f', null],
            'traversal rejected'       => ['https://a.test/cosmoshub/../osmosis', null],
            'ftp rejected'             => ['ftp://a.test', null],
            'port out of range'        => ['https://a.test:99999/x', null],
        ];
    }

    #[DataProvider('urls')]
    public function testNormalize(string $raw, ?string $expected): void
    {
        self::assertSame($expected, CosmosEndpointPolicy::normalize($raw));
    }

    // ── approval is EXACT ───────────────────────────────────────────────

    /**
     * ⚠ THE COLLISION THIS PREVENTS. `rest.cosmos.directory/cosmoshub` and
     * `rest.cosmos.directory/osmosis` are DIFFERENT CHAINS ON ONE HOST. A
     * hostname-only check would approve the second because the first is
     * approved, and discovery would write Osmosis contracts into chain 8.
     */
    public function testPathIsPartOfIdentity(): void
    {
        self::assertTrue(CosmosEndpointPolicy::isApproved(self::HUB, 'https://rest.cosmos.directory/cosmoshub'));
        self::assertFalse(CosmosEndpointPolicy::isApproved(self::HUB, 'https://rest.cosmos.directory/osmosis'));
        self::assertFalse(CosmosEndpointPolicy::isApproved(self::HUB, 'https://rest.cosmos.directory'));

        self::assertNotSame(
            CosmosEndpointPolicy::fingerprint(self::HUB, 'https://rest.cosmos.directory/cosmoshub'),
            CosmosEndpointPolicy::fingerprint(self::HUB, 'https://rest.cosmos.directory/osmosis'),
            'two chains on one host must not share a fingerprint'
        );
    }

    /** A lookalike host must never pass by prefix or suffix. */
    public function testLookalikeHostsAreRefused(): void
    {
        foreach ([
            'https://cosmos-api.polkachu.com.evil.test',
            'https://evil.test/cosmos-api.polkachu.com',
            'https://not-cosmos-api.polkachu.com',
            'https://cosmos-api.polkachu.com.',
        ] as $hostile) {
            self::assertFalse(
                CosmosEndpointPolicy::isApproved(self::HUB, $hostile),
                "{$hostile} must not be approved"
            );
        }
    }

    // ── the approved set ────────────────────────────────────────────────

    public function testPolkachuIsThePrimary(): void
    {
        self::assertSame('https://cosmos-api.polkachu.com', CosmosEndpointPolicy::primaryFor(self::HUB));
        self::assertSame(
            CosmosEndpointPolicy::ROLE_PRIMARY,
            CosmosEndpointPolicy::roleFor(self::HUB, 'https://cosmos-api.polkachu.com')
        );
    }

    /**
     * ⚠ THE INCUMBENT MUST STAY APPROVED. Chain 8 is running
     * `rest.cosmos.directory/cosmoshub` right now. If merging this made the
     * live endpoint unapproved, CosmosFetcher would refuse to construct and a
     * safety feature would become an outage.
     */
    public function testTheRunningEndpointIsStillApproved(): void
    {
        self::assertTrue(CosmosEndpointPolicy::isApproved(self::HUB, 'https://rest.cosmos.directory/cosmoshub'));
        self::assertSame(
            CosmosEndpointPolicy::ROLE_INCUMBENT,
            CosmosEndpointPolicy::roleFor(self::HUB, 'https://rest.cosmos.directory/cosmoshub')
        );
    }

    /**
     * ⚠ PUBLICNODE IS DISQUALIFIED AND MUST NOT APPEAR IN ANY ROLE.
     *
     * It refuses `/cosmos/staking/v1beta1/validators/{valoper}/delegations`
     * with 503 — a route `fetch_validator` and `enrich_validator` both use.
     * Measured over six attempts against two validators, with and without
     * `pagination.count_total`. A 503 costs four breaker charges against a
     * threshold of five, so pinning it would open the breaker after two
     * validators.
     */
    public function testPublicNodeIsNotApprovedInAnyRole(): void
    {
        foreach ([
            'https://cosmos-rest.publicnode.com',
            'https://cosmos-rest.publicnode.com/',
            'https://COSMOS-REST.PUBLICNODE.COM',
        ] as $url) {
            self::assertFalse(CosmosEndpointPolicy::isApproved(self::HUB, $url), "{$url} must be refused");
            self::assertNull(CosmosEndpointPolicy::roleFor(self::HUB, $url));
        }

        self::assertNotContains('cosmos-rest.publicnode.com', CosmosEndpointPolicy::approvedHosts());
        foreach (CosmosEndpointPolicy::approvedEndpoints(self::HUB) as $url => $role) {
            self::assertStringNotContainsStringIgnoringCase('publicnode', $url);
        }
    }

    /** Exactly one primary — an ambiguous policy is not a policy. */
    public function testExactlyOnePrimaryPerGovernedChain(): void
    {
        foreach (CosmosEndpointPolicy::governedSlugs() as $slug) {
            $primaries = array_filter(
                CosmosEndpointPolicy::approvedEndpoints($slug),
                static fn(string $role): bool => $role === CosmosEndpointPolicy::ROLE_PRIMARY
            );
            self::assertCount(1, $primaries, "chain {$slug} must have exactly one primary");
        }
    }

    /** Every policy key must already be in normal form, or lookups miss. */
    public function testEveryPolicyKeyIsNormalized(): void
    {
        foreach (CosmosEndpointPolicy::governedSlugs() as $slug) {
            foreach (array_keys(CosmosEndpointPolicy::approvedEndpoints($slug)) as $url) {
                self::assertSame($url, CosmosEndpointPolicy::normalize($url), "{$url} is not normalized");
            }
        }
    }

    // ── network identity ────────────────────────────────────────────────

    /**
     * ⚠ PINS THE LITERAL AGAINST bcc-core. `cosmoshub-4` also appears in
     * `CosmosSignatureVerifier` and `WalletVerifier` as the ADR-036 signing
     * default. Those make no HTTP request and are not endpoint policy, but if
     * the two ever disagreed one of them would be silently wrong.
     */
    public function testExpectedNetworkIsCosmoshub4(): void
    {
        self::assertSame('cosmoshub-4', CosmosEndpointPolicy::expectedNetwork(self::HUB));
    }

    public function testUngovernedChainsAreLeftAlone(): void
    {
        self::assertFalse(CosmosEndpointPolicy::isGoverned('osmosis'));
        self::assertNull(CosmosEndpointPolicy::expectedNetwork('osmosis'));
        self::assertSame([], CosmosEndpointPolicy::approvedEndpoints('osmosis'));
        self::assertNull(CosmosEndpointPolicy::fingerprint('osmosis', 'https://rest.cosmos.directory/osmosis'));
    }

    // ── fingerprints ────────────────────────────────────────────────────

    public function testFingerprintShapeAndStability(): void
    {
        $fp = CosmosEndpointPolicy::fingerprint(self::HUB, 'https://cosmos-api.polkachu.com');
        self::assertNotNull($fp);
        self::assertTrue(CosmosEndpointPolicy::isFingerprint($fp));
        self::assertSame(CosmosEndpointPolicy::fingerprintLength(), strlen($fp));
        self::assertSame($fp, CosmosEndpointPolicy::fingerprint(self::HUB, 'https://cosmos-api.polkachu.com/'));
    }

    public function testDifferentPortsAndSchemesDoNotShareAFingerprint(): void
    {
        $a = CosmosEndpointPolicy::fingerprint(self::HUB, 'https://rest.cosmos.directory/cosmoshub');
        $b = CosmosEndpointPolicy::fingerprint(self::HUB, 'https://rest.cosmos.directory:8443/cosmoshub');
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNotSame($a, $b, 'the effective port is part of the identity');
    }

    /** @return array<string, array{string}> */
    public static function nonFingerprints(): array
    {
        return [
            'too short'     => ['abc'],
            'too long'      => ['0123456789abcdef0'],
            'uppercase hex' => ['0123456789ABCDEF'],
            'not hex'       => ['zzzzzzzzzzzzzzzz'],
            'empty'         => [''],
        ];
    }

    #[DataProvider('nonFingerprints')]
    public function testIsFingerprintRejectsJunk(string $candidate): void
    {
        self::assertFalse(CosmosEndpointPolicy::isFingerprint($candidate));
    }

    // ── identity parsing (the verifier's pure half) ─────────────────────

    /** @return array<string, array{string, string|null}> */
    public static function bodies(): array
    {
        return [
            'modern shape'        => ['{"default_node_info":{"network":"cosmoshub-4"}}', 'cosmoshub-4'],
            'legacy shape'        => ['{"node_info":{"network":"cosmoshub-4"}}', 'cosmoshub-4'],
            'other network'       => ['{"default_node_info":{"network":"osmosis-1"}}', 'osmosis-1'],
            'whitespace trimmed'  => ['{"default_node_info":{"network":"  cosmoshub-4 "}}', 'cosmoshub-4'],
            'empty network'       => ['{"default_node_info":{"network":""}}', null],
            'network not string'  => ['{"default_node_info":{"network":4}}', null],
            'no network key'      => ['{"default_node_info":{"moniker":"n"}}', null],
            'info not object'     => ['{"default_node_info":"x"}', null],
            'no info key'         => ['{"application_version":{}}', null],
            'not json'            => ['<html>gateway</html>', null],
            'empty body'          => ['', null],
            'json array'          => ['[1,2,3]', null],
        ];
    }

    #[DataProvider('bodies')]
    public function testReadNetwork(string $body, ?string $expected): void
    {
        self::assertSame($expected, CosmosEndpointVerifier::readNetwork($body));
    }

    /** Every refusal reason must have operator-safe wording. */
    public function testEveryReasonHasALabel(): void
    {
        foreach ([
            CosmosEndpointVerifier::OK,
            CosmosEndpointVerifier::NOT_GOVERNED,
            CosmosEndpointVerifier::MALFORMED_URL,
            CosmosEndpointVerifier::HOST_NOT_APPROVED,
            CosmosEndpointVerifier::UNREACHABLE,
            CosmosEndpointVerifier::IDENTITY_UNREADABLE,
            CosmosEndpointVerifier::NETWORK_MISMATCH,
        ] as $reason) {
            $label = CosmosEndpointVerifier::label($reason);
            self::assertNotSame('', $label);
            // Never leak a URL, a token or provider prose into a rendered label.
            self::assertDoesNotMatchRegularExpression('#https?://#', $label);
            self::assertStringNotContainsString('_', $label);
        }
    }
}
