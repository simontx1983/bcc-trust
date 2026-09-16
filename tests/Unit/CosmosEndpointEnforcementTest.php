<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANT: a governed chain pointed at an endpoint the policy does not
 * approve — or at no endpoint at all — issues NOTHING and costs NOTHING.
 *
 * ── WHAT THIS PINS THAT THE POLICY TESTS CANNOT ─────────────────────────
 * `CosmosEndpointPolicyTest` proves the policy's ANSWERS: Polkachu is the
 * primary, the incumbent is still approved, PublicNode is approved in no
 * role, lookalike hosts and wrong paths are refused. All true, and all about
 * a pure function nobody is obliged to call.
 *
 * This file is about the CALL. `CosmosEndpointPolicy` was advisory when it
 * shipped: `CosmosFetcher` read a chain's `rest_url` and used it, whatever
 * the policy thought. So every assertion here is made at the WIRE — what was
 * requested, and what the breaker was charged — because that is the only
 * place "the fetcher refused" and "the fetcher tried and failed" look
 * different.
 *
 * ── THE WHOLE STACK IS REAL EXCEPT THE WIRE ─────────────────────────────
 * `CosmosFetcher` → {@see \BCC\Trust\Onchain\Support\ApiRetry} →
 * {@see \BCC\Trust\Onchain\Support\OnchainCircuitBreaker} all execute for
 * real; only `wp_remote_get` / `SafeHttpClient::getBatchSameHost` are
 * scripted. A refusal that "makes no request" has to be proved against the
 * real retry loop, because the retry loop is what would make four of them.
 *
 * ⚠ ZERO CHARGES IS A SEPARATE CLAIM FROM ZERO REQUESTS, and the batch path
 * is where they come apart. `ApiRetry::getBatchSameHost()` asks
 * `OnchainCircuitBreaker::isOpen()` BEFORE it calls out — and `isOpen()`
 * CLAIMS THE HALF-OPEN PROBE. A guard placed after that call would make no
 * request, charge nothing, and still steal the one probe the breaker had to
 * spend on recovery. So both are asserted, every time.
 */
#[CoversClass(CosmosFetcher::class)]
#[CoversClass(CosmosEndpointPolicy::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmosEndpointEnforcementTest extends TestCase
{
    private const CHAIN = 8;

    /** The governed slug. Everything else is ungoverned by construction. */
    private const GOVERNED = 'cosmos';

    private const INCUMBENT = 'https://rest.cosmos.directory/cosmoshub';
    private const PRIMARY   = 'https://cosmos-api.polkachu.com';

    /** Won the reliability bake and is STILL disqualified — route coverage. */
    private const PUBLICNODE = 'https://cosmos-rest.publicnode.com';

    private const WALLET = 'cosmos1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/enumeration-real-transport-stubs.php';

        \BccWire::reset();
        \BccBreakerStore::reset();
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function fetcher(string $restUrl, string $slug = self::GOVERNED): CosmosFetcher
    {
        return new CosmosFetcher((object) [
            'id'         => self::CHAIN,
            'slug'       => $slug,
            'chain_type' => 'cosmos',
            'rest_url'   => $restUrl,
            // Present ON PURPOSE. A fetcher that silently fell back to RPC
            // would have somewhere to fall back TO, which is what makes the
            // no-fallback assertions below meaningful rather than vacuous.
            'rpc_url'    => 'https://rpc.example',
            'decimals'   => 6,
        ]);
    }

    private function charges(): int
    {
        return \BccBreakerStore::counter(self::CHAIN) ?? 0;
    }

    /**
     * Drive the SINGLE-request path through the real stack.
     *
     * @return array<string, mixed>
     */
    private function single(CosmosFetcher $f, string $path = '/cosmos/base/tendermint/v1beta1/node_info'): array
    {
        $m = new \ReflectionMethod(CosmosFetcher::class, 'lcdGetResult');
        $m->setAccessible(true);

        /** @var array<string, mixed> $r */
        $r = $m->invoke($f, $path);

        return $r;
    }

    /**
     * Drive the BATCH path through the real stack.
     *
     * @param  list<string> $paths
     * @return array<int, array<string, mixed>|null>
     */
    private function batch(CosmosFetcher $f, array $paths): array
    {
        $m = new \ReflectionMethod(CosmosFetcher::class, 'lcdGetBatch');
        $m->setAccessible(true);

        /** @var array<int, array<string, mixed>|null> $r */
        $r = $m->invoke($f, $paths);

        return $r;
    }

    /** A scripted 200 that would satisfy any of these calls. */
    private function scriptSuccess(): void
    {
        \BccWire::$always = [
            'code'    => 200,
            'body'    => (string) json_encode(['data' => ['tokens' => []]]),
            'headers' => [],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. NO ENDPOINT CONFIGURED
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠ THE GAP THIS PR CLOSES. `lcdGetResult()` had this guard; its batch
     * sibling did not, so an empty `rest_url` produced N relative URLs that
     * `SafeHttpClient::validateAndPinUrl()` rejected as hostless — no real
     * request, but a WP_Error at every index, which is `getBatchSameHost()`'s
     * definition of a host-level failure and therefore a breaker charge for a
     * host that was never contacted.
     */
    public function testAnEmptyEndpointMakesNoBatchRequestAndChargesNothing(): void
    {
        $result = $this->batch($this->fetcher(''), ['/a', '/b', '/c']);

        self::assertSame([], \BccWire::$urls, 'no request may be issued');
        self::assertSame(0, $this->charges(), 'nothing may be charged for a host we never contacted');
        self::assertSame([null, null, null], $result, 'index-aligned nulls, one per requested path');
    }

    /** The single path keeps the protection it already had. */
    public function testAnEmptyEndpointMakesNoSingleRequestAndChargesNothing(): void
    {
        $result = $this->single($this->fetcher(''));

        self::assertSame([], \BccWire::$urls);
        self::assertSame(0, $this->charges());
        self::assertFalse($result['ok']);
    }

    /**
     * ⚠ AND IT NEVER REACHES FOR THE RPC URL. The constructor used to fall
     * back to `rpc_url` when `rest_url` was empty, which sent LCD paths to a
     * Tendermint RPC — a different protocol on a host nobody approved. The
     * row here HAS an rpc_url, so this asserts a choice rather than an
     * absence.
     */
    public function testAnEmptyRestEndpointNeverFallsBackToRpc(): void
    {
        $this->scriptSuccess();

        $this->single($this->fetcher(''));
        $this->batch($this->fetcher(''), ['/a']);

        self::assertSame([], \BccWire::$urls, 'not one request, to REST or RPC');

        foreach (\BccWire::$urls as $url) {
            self::assertStringNotContainsString('rpc.example', $url);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. A GOVERNED CHAIN ON AN UNAPPROVED ENDPOINT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Every shape of "not the approved endpoint" that a misconfiguration, a
     * typo or an attacker could produce.
     *
     * @return array<string, array{0: string}>
     */
    public static function unapprovedEndpoints(): array
    {
        return [
            // Reliable, well-behaved, and refuses
            // /validators/{valoper}/delegations with a 503. Route coverage is
            // not uptime, and the policy is the place that difference lives.
            'publicnode'            => [self::PUBLICNODE],
            'a plain unknown host'  => ['https://lcd.example'],
            'lookalike suffix'      => ['https://rest.cosmos.directory.evil.test/cosmoshub'],
            'lookalike prefix'      => ['https://cosmos-api.polkachu.com.evil.test'],
            'approved host, wrong path' => ['https://rest.cosmos.directory/osmosis'],
            'approved host, no path'    => ['https://rest.cosmos.directory'],
            'traversal back out'    => ['https://rest.cosmos.directory/cosmoshub/../osmosis'],
            'http not https'        => ['http://cosmos-api.polkachu.com'],
            'credentials in the url' => ['https://user:secret@cosmos-api.polkachu.com'],
            'a non-default port'    => ['https://cosmos-api.polkachu.com:8443'],
            'query string'          => ['https://cosmos-api.polkachu.com?key=abc'],
            'fragment'              => ['https://cosmos-api.polkachu.com#frag'],
            'not a url at all'      => ['nonsense'],
        ];
    }

    #[DataProvider('unapprovedEndpoints')]
    public function testAnUnapprovedEndpointIsRefusedWithoutARequestOrACharge(string $url): void
    {
        $this->scriptSuccess();

        $single = $this->single($this->fetcher($url));
        $batch  = $this->batch($this->fetcher($url), ['/a', '/b']);

        self::assertSame([], \BccWire::$urls, 'a refused endpoint must not be contacted');
        self::assertSame(0, $this->charges(), 'a refusal is our decision, not the provider\'s fault');
        self::assertFalse($single['ok']);
        self::assertSame([null, null], $batch);
    }

    /**
     * ⚠ AND IT DOES NOT QUIETLY TRY THE APPROVED ONE INSTEAD.
     *
     * Automatic failover is the tempting "helpful" behaviour and it is
     * forbidden: it would move a chain's traffic to a different provider
     * without an operator deciding to, while the breaker, the cursors and
     * the audit trail all still describe the endpoint that was configured.
     * Switching endpoints is an explicit, audited, cursor-clearing operation
     * — see {@see \BCC\Trust\Onchain\Services\CosmosEndpointTransition}.
     */
    public function testARefusedEndpointNeverFailsOverToAnApprovedOne(): void
    {
        $this->scriptSuccess();

        $this->single($this->fetcher(self::PUBLICNODE));
        $this->batch($this->fetcher(self::PUBLICNODE), ['/a']);

        self::assertSame([], \BccWire::$urls);

        foreach (\BccWire::$urls as $url) {
            self::assertStringNotContainsString('polkachu', $url, 'no silent failover to the primary');
            self::assertStringNotContainsString('cosmos.directory', $url, 'no silent failover to the incumbent');
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. THE APPROVED ENDPOINTS ARE ACTUALLY USED
    // ═══════════════════════════════════════════════════════════════════
    //
    //  Anti-vacuity. Every assertion above is "zero requests", and a fetcher
    //  that refused EVERYTHING would satisfy all of them.

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function approvedEndpoints(): array
    {
        return [
            // Deploying the policy must not orphan the endpoint production is
            // actually running on.
            'incumbent' => [self::INCUMBENT, CosmosEndpointPolicy::ROLE_INCUMBENT],
            'primary'   => [self::PRIMARY,   CosmosEndpointPolicy::ROLE_PRIMARY],
        ];
    }

    #[DataProvider('approvedEndpoints')]
    public function testAnApprovedEndpointIsContactedNormally(string $url, string $expectedRole): void
    {
        self::assertSame(
            $expectedRole,
            CosmosEndpointPolicy::roleFor(self::GOVERNED, $url),
            'the fixture must be the role this test claims to cover'
        );

        $this->scriptSuccess();

        $single = $this->single($this->fetcher($url));
        self::assertTrue($single['ok']);
        self::assertCount(1, \BccWire::$urls);
        self::assertStringStartsWith($url, \BccWire::$urls[0]);

        \BccWire::$urls = [];
        $this->batch($this->fetcher($url), ['/a', '/b']);
        self::assertCount(2, \BccWire::$urls, 'the batch path reaches the wire too');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  4. UNGOVERNED CHAINS ARE UNTOUCHED
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠ THE POLICY GOVERNS ONE SLUG. Every other Cosmos-family chain —
     * osmosis, juno, injective, stargaze — has no approved-endpoint list, and
     * inventing one here would silence eight working chains the moment this
     * merged. `isGoverned()` is asked FIRST, before normalization or
     * approval, so an ungoverned chain never meets a rule it cannot satisfy.
     *
     * @return array<string, array{0: string}>
     */
    public static function ungovernedSlugs(): array
    {
        return [
            'osmosis'   => ['osmosis'],
            'juno'      => ['juno'],
            'injective' => ['injective'],
            'stargaze'  => ['stargaze'],
        ];
    }

    #[DataProvider('ungovernedSlugs')]
    public function testAnUngovernedChainKeepsItsOwnEndpoint(string $slug): void
    {
        self::assertFalse(
            CosmosEndpointPolicy::isGoverned($slug),
            'this test is only meaningful for a slug the policy does not govern'
        );

        $this->scriptSuccess();

        // The SAME url that is refused for the governed slug above.
        $single = $this->single($this->fetcher('https://lcd.example', $slug));

        self::assertTrue($single['ok'], 'an ungoverned chain must behave exactly as it did before');
        self::assertCount(1, \BccWire::$urls);
        self::assertStringStartsWith('https://lcd.example', \BccWire::$urls[0]);

        \BccWire::$urls = [];
        $this->batch($this->fetcher('https://lcd.example', $slug), ['/a', '/b']);
        self::assertCount(2, \BccWire::$urls);
    }

    /**
     * An ungoverned chain with NO endpoint is still refused — that guard is
     * about having somewhere to send the request, not about policy.
     */
    public function testAnUngovernedChainWithNoEndpointIsStillRefused(): void
    {
        $this->scriptSuccess();

        $this->single($this->fetcher('', 'osmosis'));
        $this->batch($this->fetcher('', 'osmosis'), ['/a']);

        self::assertSame([], \BccWire::$urls);
        self::assertSame(0, $this->charges());
    }

    // ═══════════════════════════════════════════════════════════════════
    //  5. THE REFUSAL DOES NOT TOUCH THE BREAKER'S RECOVERY PATH
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠ THE HALF-OPEN PROBE IS A SINGLE-USE RESOURCE, and `isOpen()` claims
     * it. A guard that sat after `ApiRetry`'s breaker check would make no
     * request and charge nothing — passing every assertion above — while
     * consuming the one probe the breaker had reserved for finding out
     * whether the provider had recovered. The chain would then stay open for
     * another cooldown for a reason that has nothing to do with the provider.
     *
     * Asserted by state, not by inspection: a refused batch must leave the
     * breaker exactly as it found it.
     */
    public function testARefusedBatchLeavesTheBreakerStateUntouched(): void
    {
        $before = [
            'options'    => \BccBreakerStore::$options,
            'transients' => \BccBreakerStore::$transients,
            'cache'      => \BccBreakerStore::$cache,
        ];

        $this->batch($this->fetcher(self::PUBLICNODE), ['/a', '/b', '/c']);
        $this->batch($this->fetcher(''), ['/a']);

        self::assertSame($before['options'], \BccBreakerStore::$options, 'no counter, no open marker');
        self::assertSame($before['transients'], \BccBreakerStore::$transients, 'no probe claimed, no cooldown set');
        self::assertSame($before['cache'], \BccBreakerStore::$cache);
    }

    /**
     * Anti-vacuity for the test above: the same batch against an APPROVED
     * endpoint whose host fails at the wire DOES move breaker state. If this
     * did not, the assertion above would be proving that batches never touch
     * the breaker at all.
     */
    public function testATransportFailureOnAnApprovedEndpointDoesMoveTheBreaker(): void
    {
        \BccWire::$always = new \WP_Error('http_request_failed', 'cURL error 28');

        $this->batch($this->fetcher(self::PRIMARY), ['/a', '/b']);

        self::assertCount(2, \BccWire::$urls, 'the wire really was reached');
        self::assertSame(1, $this->charges(), 'a host-level batch failure charges once for the whole batch');
    }
}
