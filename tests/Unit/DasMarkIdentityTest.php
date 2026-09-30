<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\EndpointDescriptor;
use BCC\Trust\Onchain\Support\HeliusEndpoint;
use BCC\Trust\Onchain\Support\NftCapabilityOptionState;
use BCC\Trust\Onchain\Support\NftProviderReadiness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The DAS-unsupported mark: identity compares, description renders, and the two
 * never swap jobs.
 *
 * ── ⚠⚠⚠ THE CONFLATION THIS BREAKS APART ────────────────────────────────
 * One stored field, `rpc_url`, did both jobs at once. It was rendered by
 * `SettingsPage` and `NftDiscoveryPage`, AND compared by
 * `NftProviderReadiness::dasMarkApplies()`.
 *
 * That made the two requirements pull in opposite directions. Rendering wants
 * the value as lossy as possible; comparison wants it as precise as possible.
 * The old code resolved the tension by being lossy only about query strings —
 * which left path-embedded credentials rendered in admin HTML, and would have
 * collapsed identity entirely the moment the redaction was hardened.
 *
 * So the mark now stores TWO fields:
 *
 *   endpoint_display  scheme://host — safe to render, useless for comparison
 *   endpoint_id       site-keyed HMAC over the whole URL — compares, never renders
 *
 * ⚠ A stored mark that cannot be matched is treated as ABSENT, not as evidence.
 * That direction is deliberate: the mark's effect is to DISABLE a driver, so
 * ignoring an unverifiable mark re-enables a driver which will re-mark itself if
 * the endpoint really is unsupported. The opposite default would let one
 * unreadable option disable a provider permanently with no way to tell why.
 */
#[CoversClass(NftProviderReadiness::class)]
#[CoversClass(HeliusEndpoint::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DasMarkIdentityTest extends TestCase
{
    private const CHAIN_ID = 77;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-discovery-matrix-stubs.php';
        NftCapabilityOptionState::reset();
    }

    private static function solana(string $rpc): object
    {
        return (object) [
            'id'                                  => (string) self::CHAIN_ID,
            'slug'                                => 'solana',
            'name'                                => 'Solana',
            'chain_type'                          => 'solana',
            'rpc_url'                             => $rpc,
            'rest_url'                            => '',
            'bcc_supports_nft_collections'        => '1',
            'manual_collection_discovery_enabled' => '1',
        ];
    }

    /** Write a mark the way the production writer now does. */
    private static function mark(string $endpoint, int $code = -32601, string $msg = 'Method not found'): void
    {
        NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(self::CHAIN_ID)] = [
            'endpoint_display' => EndpointDescriptor::display($endpoint),
            'endpoint_id'      => EndpointDescriptor::identity($endpoint),
            'code'             => $code,
            'message'          => $msg,
            'detected_at'      => 1700000000,
        ];
    }

    private static function refusalFor(object $chain): ?array
    {
        return NftProviderReadiness::endpointRefusal($chain, \BCC\Trust\Onchain\Support\NftDriverRegistry::DRIVER_DAS_RPC);
    }

    // ── 1. ⚠⚠ Same host, different credential: NO inheritance ───────────

    /** @return array<string, array{0: string, 1: string}> marked, current */
    public static function sameHostDifferentEndpoint(): array
    {
        return [
            'different path credential'  => ['https://das.example/v1/KEY-AAA', 'https://das.example/v1/KEY-BBB'],
            'different query credential' => ['https://das.example/?api-key=AAA', 'https://das.example/?api-key=BBB'],
            'different path shape'       => ['https://das.example/v1/KEY', 'https://das.example/nft/v3/KEY'],
            'key added'                  => ['https://das.example/v1/', 'https://das.example/v1/KEY'],
            'key removed'                => ['https://das.example/v1/KEY', 'https://das.example/v1/'],
        ];
    }

    #[DataProvider('sameHostDifferentEndpoint')]
    public function testAMarkCannotBeInheritedByADifferentEndpointOnTheSameHost(string $marked, string $current): void
    {
        self::mark($marked);

        self::assertNull(
            self::refusalFor(self::solana($current)),
            'a mark for one credential/path must not attach to another on the same host'
        );
    }

    #[DataProvider('sameHostDifferentEndpoint')]
    public function testReadinessIsNotSuppressedByAnInheritedMark(string $marked, string $current): void
    {
        self::mark($marked);

        // The stale mark must not disable the driver for the CURRENT endpoint.
        $ready = NftProviderReadiness::isReady(
            self::solana($current),
            \BCC\Trust\Onchain\Support\NftDriverRegistry::DRIVER_DAS_RPC
        );

        self::assertTrue($ready, 'a mark that does not describe the current endpoint must not disable it');
    }

    // ── 2. Equivalent endpoints still match ─────────────────────────────

    public function testAMarkAppliesToTheExactSameEndpoint(): void
    {
        $rpc = 'https://das.example/?api-key=SAME';
        self::mark($rpc);

        $r = self::refusalFor(self::solana($rpc));
        self::assertIsArray($r, 'the mark must still apply to the endpoint it was written for');
        self::assertSame(-32601, $r['code']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function equivalentEndpoints(): array
    {
        return [
            'host case'    => ['https://DAS.Example/v1/KEY', 'https://das.example/v1/KEY'],
            'default port' => ['https://das.example:443/v1/KEY', 'https://das.example/v1/KEY'],
            'whitespace'   => ['  https://das.example/v1/KEY ', 'https://das.example/v1/KEY'],
        ];
    }

    #[DataProvider('equivalentEndpoints')]
    public function testEquivalentlyWrittenEndpointsStillMatch(string $marked, string $current): void
    {
        self::mark($marked);

        self::assertIsArray(
            self::refusalFor(self::solana($current)),
            'a cosmetic difference must not drop a real negative signal'
        );
    }

    // ── 3. ⚠⚠⚠ No comparison value reaches any output ───────────────────

    public function testTheRefusalPayloadCarriesNoIdentityAndNoCredential(): void
    {
        $rpc = 'https://das.example/v1/SUPERSECRETKEY';
        self::mark($rpc);

        $r = self::refusalFor(self::solana($rpc));
        self::assertIsArray($r);

        $flat = wp_json_encode($r);
        self::assertIsString($flat);
        self::assertStringNotContainsString('SUPERSECRETKEY', $flat, 'the credential reached the refusal payload');
        self::assertStringNotContainsString(EndpointDescriptor::identity($rpc), $flat, 'the identity reached the refusal payload');
        self::assertArrayNotHasKey('endpoint_id', $r, 'the comparison token must not be exported');
        self::assertArrayNotHasKey('rpc_url', $r, 'the old conflated field must be gone');
    }

    public function testTheRefusalPayloadExposesOnlyASafeDescription(): void
    {
        $rpc = 'https://das.example/v1/SUPERSECRETKEY';
        self::mark($rpc);

        $r = self::refusalFor(self::solana($rpc));
        self::assertIsArray($r);
        self::assertArrayHasKey('endpoint_display', $r);
        self::assertSame('https://das.example', $r['endpoint_display']);
        self::assertSame(2, substr_count((string) $r['endpoint_display'], '/'), 'no path may survive');
    }

    /** The capability matrix carries the description through, and nothing else. */
    public function testTheCapabilityMatrixCarriesNoCredentialOrIdentity(): void
    {
        $rpc = 'https://das.example/v1/SUPERSECRETKEY';
        self::mark($rpc);

        $matrix = \BCC\Trust\Onchain\Support\NftChainCapability::operationMatrix(self::solana($rpc));
        $flat = wp_json_encode($matrix);
        self::assertIsString($flat);

        self::assertStringNotContainsString('SUPERSECRETKEY', $flat);
        self::assertStringNotContainsString(EndpointDescriptor::identity($rpc), $flat);
        self::assertStringNotContainsString('/v1/', $flat);
    }

    // ── 4. Legacy marks fail safe ───────────────────────────────────────

    /**
     * ⚠ A mark written by the OLD code stores a query-redacted `rpc_url` and no
     * identity. It cannot be verified against the current endpoint, so it is
     * treated as ABSENT — which re-enables the driver, and the driver re-marks
     * itself if the endpoint is genuinely unsupported.
     */
    public function testALegacyMarkIsTreatedAsAbsentRatherThanTrusted(): void
    {
        NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(self::CHAIN_ID)] = [
            'rpc_url'     => 'https://das.example/?***REDACTED***',
            'code'        => -32601,
            'message'     => 'Method not found',
            'detected_at' => 1700000000,
        ];

        self::assertNull(
            self::refusalFor(self::solana('https://das.example/?api-key=KEY')),
            'an unverifiable legacy mark must not be trusted'
        );
    }

    public function testALegacyMarkNeverLeaksItsStoredUrl(): void
    {
        NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(self::CHAIN_ID)] = [
            'rpc_url'     => 'https://das.example/v1/LEGACYSECRET',
            'code'        => -32601,
            'message'     => 'Method not found',
            'detected_at' => 1700000000,
        ];

        $matrix = \BCC\Trust\Onchain\Support\NftChainCapability::operationMatrix(self::solana('https://das.example/v1/LEGACYSECRET'));
        $flat = (string) wp_json_encode($matrix);

        self::assertStringNotContainsString('LEGACYSECRET', $flat, 'a legacy payload leaked its path credential');
    }

    /** A malformed or empty mark is absent, never a match. */
    public function testAMalformedMarkIsAbsent(): void
    {
        foreach ([[], ['code' => -32601], ['endpoint_id' => ''], ['endpoint_id' => 'not-a-digest']] as $stored) {
            NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(self::CHAIN_ID)] = $stored;
            self::assertNull(
                self::refusalFor(self::solana('https://das.example/?api-key=KEY')),
                'a malformed mark must not apply'
            );
        }
    }

    /** An unusable CURRENT endpoint can never match a stored mark. */
    public function testAnUnusableCurrentEndpointMatchesNothing(): void
    {
        self::mark('https://das.example/v1/KEY');

        foreach (['', '   ', 'not a url'] as $bad) {
            self::assertNull(self::refusalFor(self::solana($bad)));
        }
    }

    // ── 5. ⚠⚠⚠ THE REAL WRITER, not this test's helper ───────────────────

    /**
     * Every test above writes the option through {@see mark()}, which means they
     * pin the READER and say nothing about the WRITER.
     *
     * That gap was not hypothetical. Mutations that made
     * `SolanaFetcher::markDasUnsupported()` store the raw credentialed URL, and
     * that stored the identity in the display field, both SURVIVED the entire
     * suite — because no test ever ran the writer. A reader that is perfectly
     * safe cannot protect an option that the writer filled with a key.
     *
     * ⚠ Reflection is used deliberately. The method is private static and is
     * reached in production from `rpcCall()` on an observed -32601/-32603, which
     * would need a stubbed transport to drive. What is being pinned here is the
     * PAYLOAD CONTRACT — two fields, one describable, one opaque — and that is a
     * property of the writer itself, not of the path that calls it.
     */
    public function testTheProductionWriterStoresNoCredential(): void
    {
        $raw = 'https://das.example/v1/WRITERSECRETKEY?api-key=WRITERSECRETKEY';

        $m = new \ReflectionMethod(\BCC\Trust\Onchain\Fetchers\SolanaFetcher::class, 'markDasUnsupported');
        $m->setAccessible(true);
        $m->invoke(null, self::CHAIN_ID, $raw, -32601, 'Method not found');

        $stored = NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(self::CHAIN_ID)] ?? null;
        self::assertIsArray($stored, 'the writer stored nothing');

        $flat = (string) wp_json_encode($stored);
        self::assertStringNotContainsString('WRITERSECRETKEY', $flat, 'the writer persisted the credential');
        self::assertStringNotContainsString('/v1/', $flat, 'the writer persisted a path');
        self::assertStringNotContainsString('api-key', $flat, 'the writer persisted a query parameter name');

        // The description is a description.
        self::assertSame('https://das.example', $stored['endpoint_display'] ?? null);

        // ⚠ And the identity is the IDENTITY — not the raw URL, and not the
        // description. Without this assertion a writer that stored the identity
        // in the display field would pass every check above, because an HMAC
        // contains no credential either.
        self::assertSame(EndpointDescriptor::identity($raw), $stored['endpoint_id'] ?? null);
        self::assertNotSame($stored['endpoint_display'] ?? null, $stored['endpoint_id'] ?? null);
    }

    /** What the writer stores is what the reader can attribute. */
    public function testTheProductionWriterProducesAMarkTheReaderHonours(): void
    {
        $raw = 'https://das.example/v1/ROUNDTRIPKEY';

        $m = new \ReflectionMethod(\BCC\Trust\Onchain\Fetchers\SolanaFetcher::class, 'markDasUnsupported');
        $m->setAccessible(true);
        $m->invoke(null, self::CHAIN_ID, $raw, -32601, 'Method not found');

        self::assertIsArray(self::refusalFor(self::solana($raw)), 'the writer and the reader disagree');
        self::assertNull(
            self::refusalFor(self::solana('https://das.example/v1/DIFFERENTKEY')),
            'the round trip must not widen to the whole host'
        );
    }

    // ── 6. ⚠⚠ A STORED VALUE IS UNTRUSTED INPUT ──────────────────────────

    /**
     * The reader re-describes the stored display value instead of trusting it.
     *
     * The writer is correct today, so trusting the field would pass every other
     * test here. But the option is long-lived: it survives deployments, it is
     * restored from backups taken before this change, and an administrator can
     * edit it directly. A reader that forwards whatever it finds turns any one of
     * those into the original leak.
     *
     * ⚠ A valid `endpoint_id` is stored alongside the hostile display value, so
     * the mark genuinely APPLIES and the payload really is returned. Without that
     * the test would pass for the wrong reason — `dasMarkApplies()` would reject
     * the mark and there would be nothing to describe.
     */
    public function testAHandEditedDisplayValueIsReDescribedRatherThanForwarded(): void
    {
        $rpc = 'https://das.example/?api-key=CURRENT';

        NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(self::CHAIN_ID)] = [
            // A complete credentialed URL, exactly what a legacy or hand-edited
            // payload holds.
            'endpoint_display' => 'https://evil.example/v2/TAMPEREDSECRET?api-key=TAMPEREDSECRET',
            'endpoint_id'      => EndpointDescriptor::identity($rpc),
            'code'             => -32601,
            'message'          => 'Method not found',
            'detected_at'      => 1700000000,
        ];

        $r = self::refusalFor(self::solana($rpc));
        self::assertIsArray($r, 'the mark should still apply — its identity is valid');

        self::assertStringNotContainsString('TAMPEREDSECRET', (string) wp_json_encode($r));
        self::assertSame('https://evil.example', $r['endpoint_display']);
    }

    /** An undescribable stored value becomes a placeholder, never an echo. */
    public function testAnUndescribableStoredValueIsNotEchoed(): void
    {
        $rpc = 'https://das.example/?api-key=CURRENT';

        NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(self::CHAIN_ID)] = [
            'endpoint_display' => 'not-a-url-but-still-holds-TAMPEREDSECRET',
            'endpoint_id'      => EndpointDescriptor::identity($rpc),
            'code'             => -32601,
            'message'          => 'Method not found',
            'detected_at'      => 1700000000,
        ];

        $r = self::refusalFor(self::solana($rpc));
        self::assertIsArray($r);
        self::assertStringNotContainsString('TAMPEREDSECRET', (string) wp_json_encode($r));
        self::assertSame(EndpointDescriptor::UNRECOGNISED, $r['endpoint_display']);
    }
}
