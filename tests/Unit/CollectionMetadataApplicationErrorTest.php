<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANT: a contract REFUSING a metadata query variant costs one
 * request and zero breaker charges, while a PROVIDER failing the same request
 * keeps every retry and every charge it has today.
 *
 * ── WHY THE WHOLE STACK IS REAL ─────────────────────────────────────────
 * The defect was a seam: `cw721CollectionInfoQuery()` asked its two variants
 * through the fold-to-null helper, which passes no `application_error`
 * callback, so {@see \BCC\Trust\Onchain\Support\ApiRetry} could not tell a
 * refusal from a node fault and charged the breaker four times per variant —
 * eight against a threshold of five, every pass, on any provider. A test that
 * faked the retry loop or the breaker would be asserting against a second copy
 * of the rule it is checking, so only the WIRE is scripted here.
 *
 * ── THE MEASUREMENTS THIS PINS ──────────────────────────────────────────
 * Staging run 10 (2026-09-11) opened chain 8's breaker 10 s after its last
 * success with 8 charges, `http_5xx` / `standard_request`. A direct
 * two-request diagnostic (2026-09-12) then got HTTP 500, grpc code 2 and a
 * message matching "Error parsing into type" from BOTH variants of the
 * queue-head candidate — the contract answering, not the provider failing.
 * {@see testTheLegacyPathStillChargesEightTimes} reproduces the old cost so
 * the fix cannot be read as a change in the provider's behaviour.
 */
#[CoversClass(CosmosFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CollectionMetadataApplicationErrorTest extends TestCase
{
    private const CHAIN    = 94;
    private const CONTRACT = 'cosmos1collectionmetadatatestcontractaddressvalue';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/enumeration-real-transport-stubs.php';

        \BccWire::reset();
        \BccBreakerStore::reset();
    }

    private function fetcher(): CosmosFetcher
    {
        return new CosmosFetcher((object) [
            'id'         => self::CHAIN,
            'slug'       => 'testchain',
            'chain_type' => 'cosmos',
            'rest_url'   => 'https://lcd.test',
            'rpc_url'    => '',
            'is_active'  => 1,
            'decimals'   => 6,
        ]);
    }

    private function charges(): int
    {
        return \BccBreakerStore::counter(self::CHAIN) ?? 0;
    }

    /**
     * The contract's own refusal, in the shape a CosmWasm LCD delivers it: a
     * 5xx whose `message` is the cw721 QueryMsg parse error. Deliberately
     * carries NO node-error token — {@see CosmwasmClassifier::errorKindFromMessage()}
     * checks those first, and a body that said "rpc error" would be a node
     * fault by the documented rule.
     *
     * @return array{code: int, body: string}
     */
    private static function refusal(string $variant): array
    {
        return [
            'code' => 500,
            'body' => json_encode([
                'code'    => 2,
                'message' => 'Error parsing into type cw721_base::msg::QueryMsg: unknown variant `'
                    . $variant . '`, expected one of `owner_of`, `num_tokens`, `all_nft_info`: query wasm contract failed',
                'details' => [],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /** A genuine node-side failure: same status, node-error vocabulary. */
    private static function nodeFault(): array
    {
        return [
            'code' => 500,
            'body' => json_encode([
                'code'    => 13,
                'message' => 'rpc error: code = Internal desc = Querier system error: node is not caught up',
                'details' => [],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function success(array $payload): array
    {
        return ['code' => 200, 'body' => json_encode(['data' => $payload], JSON_THROW_ON_ERROR)];
    }

    /**
     * Which smart-query variants the wire was actually asked for, in order.
     * Decoded from the request path, so it proves the REQUESTS, not the intent.
     *
     * @return list<string>
     */
    private static function variantsAsked(): array
    {
        $out = [];
        foreach (\BccWire::$urls as $url) {
            $pos = strrpos($url, '/smart/');
            if ($pos === false) {
                $out[] = 'not-a-smart-query';
                continue;
            }
            $raw     = substr($url, $pos + strlen('/smart/'));
            $decoded = json_decode((string) base64_decode(strtr($raw, '-_', '+/'), true), true);
            $out[]   = is_array($decoded) ? (string) array_key_first($decoded) : 'undecodable';
        }

        return $out;
    }

    // ── 1. the confirmed case: two refusals ─────────────────────────────

    public function testTwoRefusalsCostTwoRequestsNoRetriesAndNoCharges(): void
    {
        \BccWire::$queue = [
            self::refusal(CosmwasmClassifier::PROBE_CONTRACT_INFO),
            self::refusal(CosmwasmClassifier::PROBE_COLLECTION_INFO),
        ];

        $info = $this->fetcher()->fetchContractInfo(self::CONTRACT);

        self::assertNull($info, 'a contract that implements neither variant has no metadata to return');
        self::assertSame(
            [CosmwasmClassifier::PROBE_CONTRACT_INFO, CosmwasmClassifier::PROBE_COLLECTION_INFO],
            self::variantsAsked(),
            'exactly the two metadata variants, classic first'
        );
        self::assertCount(2, \BccWire::$urls, 'TWO wire calls — one per variant, none retried');
        self::assertSame([], \BccWire::$sleeps, 'a refusal must not be retried, so nothing may back off');
        self::assertSame(0, $this->charges(), 'the contract answering is not the provider failing');
        self::assertNull(\BccBreakerStore::counter(self::CHAIN), 'no counter row may be created at all');
        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(self::CHAIN));
    }

    /**
     * ⚠ THE CONTROL FOR THE FIX. The legacy helper is still there for its
     * other callers, and it still behaves exactly as it did — so this test
     * reproduces the eight charges the emission path used to pay, proving the
     * improvement came from the routing change and not from the wire.
     */
    public function testTheLegacyPathStillChargesEightTimes(): void
    {
        \BccWire::$always = self::refusal(CosmwasmClassifier::PROBE_CONTRACT_INFO);

        $legacy = new \ReflectionMethod(CosmosFetcher::class, 'wasmSmartQuery');
        $legacy->setAccessible(true);
        $fetcher = $this->fetcher();

        // Exactly what the old cw721CollectionInfoQuery() did: both variants
        // through the helper that supplies no application-error callback.
        foreach ([CosmwasmClassifier::PROBE_CONTRACT_INFO, CosmwasmClassifier::PROBE_COLLECTION_INFO] as $variant) {
            self::assertNull($legacy->invoke($fetcher, self::CONTRACT, [$variant => new \stdClass()]));
        }

        self::assertSame(8, $this->charges(), '2 requests x 4 attempts — the measured pre-fix cost');
        self::assertCount(8, \BccWire::$urls, 'every attempt really went to the wire');
        self::assertNotSame([], \BccWire::$sleeps, 'the legacy path backs off between attempts');
        self::assertGreaterThanOrEqual(
            OnchainCircuitBreaker::FAILURE_THRESHOLD,
            $this->charges(),
            'which is why the breaker opened on every pass'
        );
    }

    // ── 2. genuine provider faults keep today's behaviour ───────────────

    public function testAGenuineFiveHundredStillRetriesAndCharges(): void
    {
        \BccWire::$always = self::nodeFault();

        self::assertNull($this->fetcher()->fetchContractInfo(self::CONTRACT));

        self::assertSame(8, $this->charges(), 'four charges per variant, unchanged');
        self::assertCount(8, \BccWire::$urls, 'four attempts per variant, unchanged');
        self::assertNotSame([], \BccWire::$sleeps, 'a node fault is still retried with backoff');
        $attribution = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertSame('http_5xx', $attribution['kind'], 'a real 5xx is still recorded as one');
    }

    public function testARateLimitStillChargesOncePerRequestAndDoesNotRetry(): void
    {
        \BccWire::$always = ['code' => 429, 'body' => '{"code":8,"message":"too many requests"}'];

        self::assertNull($this->fetcher()->fetchContractInfo(self::CONTRACT));

        self::assertSame(2, $this->charges(), 'one charge per request, no retry — unchanged 429 handling');
        self::assertCount(2, \BccWire::$urls);
        self::assertSame('rate_limited', OnchainCircuitBreaker::attribution(self::CHAIN)['kind']);
    }

    public function testATransportFailureStillChargesAndRetries(): void
    {
        \BccWire::$always = new \WP_Error('http_request_failed', 'cURL error 28');

        self::assertNull($this->fetcher()->fetchContractInfo(self::CONTRACT));

        self::assertGreaterThan(0, $this->charges(), 'a transport failure still blames the provider');
        self::assertSame('transport', OnchainCircuitBreaker::attribution(self::CHAIN)['kind']);
    }

    // ── 3. the success paths are untouched ──────────────────────────────

    public function testTheClassicVariantAnsweringSkipsTheFallbackEntirely(): void
    {
        \BccWire::$queue = [self::success(['name' => 'Classic Collection', 'symbol' => 'CLSC'])];

        $info = $this->fetcher()->fetchContractInfo(self::CONTRACT);

        self::assertIsArray($info);
        self::assertSame('Classic Collection', $info['name']);
        self::assertSame('CLSC', $info['symbol']);
        self::assertSame([CosmwasmClassifier::PROBE_CONTRACT_INFO], self::variantsAsked(), 'ONE request only');
        self::assertSame(0, $this->charges());
    }

    public function testARefusedClassicVariantFallsBackAndTheModernOneAnswers(): void
    {
        \BccWire::$queue = [
            self::refusal(CosmwasmClassifier::PROBE_CONTRACT_INFO),
            self::success(['name' => 'Modern Collection', 'extension' => ['description' => 'from the extension']]),
        ];

        $info = $this->fetcher()->fetchContractInfo(self::CONTRACT);

        self::assertIsArray($info);
        self::assertSame('Modern Collection', $info['name']);
        self::assertSame('from the extension', $info['description'], 'the extension variant carries it');
        self::assertSame(
            [CosmwasmClassifier::PROBE_CONTRACT_INFO, CosmwasmClassifier::PROBE_COLLECTION_INFO],
            self::variantsAsked()
        );
        self::assertSame(0, $this->charges(), 'the refusal on the way to the answer cost nothing');
        self::assertSame([], \BccWire::$sleeps);
    }

    // ── 4. the request authorizer (how a budget meters the pair) ────────

    public function testDecliningTheSecondRequestStopsAfterTheFirst(): void
    {
        \BccWire::$always = self::refusal(CosmwasmClassifier::PROBE_CONTRACT_INFO);
        $allowed = 1;

        $info = $this->fetcher()->fetchContractInfo(self::CONTRACT, static function () use (&$allowed): bool {
            if ($allowed <= 0) {
                return false;
            }
            $allowed--;

            return true;
        });

        self::assertNull($info);
        self::assertCount(1, \BccWire::$urls, 'the fallback was never asked for');
        self::assertSame(0, $this->charges());
    }

    public function testDecliningEveryRequestAsksForNothing(): void
    {
        \BccWire::$always = self::refusal(CosmwasmClassifier::PROBE_CONTRACT_INFO);

        $info = $this->fetcher()->fetchContractInfo(self::CONTRACT, static fn (): bool => false);

        self::assertNull($info);
        self::assertSame([], \BccWire::$urls, 'no budget, no requests');
        self::assertSame(0, $this->charges());
    }

    public function testTheAuthorizerIsConsultedOncePerRequestNotOncePerPair(): void
    {
        \BccWire::$queue = [
            self::refusal(CosmwasmClassifier::PROBE_CONTRACT_INFO),
            self::refusal(CosmwasmClassifier::PROBE_COLLECTION_INFO),
        ];
        $asked = 0;

        $this->fetcher()->fetchContractInfo(self::CONTRACT, static function () use (&$asked): bool {
            $asked++;

            return true;
        });

        self::assertSame(2, $asked, 'two requests, two authorizations — the budget can count them separately');
        self::assertCount(2, \BccWire::$urls);
    }

    // ── 5. attribution is never fabricated ─────────────────────────────

    public function testARefusalStoresNoProviderAttributionAtAll(): void
    {
        \BccWire::$queue = [
            self::refusal(CosmwasmClassifier::PROBE_CONTRACT_INFO),
            self::refusal(CosmwasmClassifier::PROBE_COLLECTION_INFO),
        ];

        $this->fetcher()->fetchContractInfo(self::CONTRACT);

        $attribution = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertNull($attribution['kind'], 'no failure kind may be invented for a contract answer');
        self::assertNull($attribution['request_class']);
        self::assertNull($attribution['endpoint_fp']);
        self::assertNull(\BccBreakerStore::state(self::CHAIN), 'no breaker state row at all');
    }

    /**
     * ⚠ A REFUSAL IS NOT A SUCCESS EITHER. Recording one would move
     * `bcc_onchain_last_success_*`, which the stale-chain detector reads, and
     * claim the provider served us when it refused on the contract's behalf.
     */
    public function testARefusalIsNotRecordedAsASuccessEither(): void
    {
        \BccWire::$queue = [
            self::refusal(CosmwasmClassifier::PROBE_CONTRACT_INFO),
            self::refusal(CosmwasmClassifier::PROBE_COLLECTION_INFO),
        ];

        $before = \BccBreakerStore::$options['bcc_onchain_last_success_' . self::CHAIN] ?? null;
        $this->fetcher()->fetchContractInfo(self::CONTRACT);

        self::assertSame(
            $before,
            \BccBreakerStore::$options['bcc_onchain_last_success_' . self::CHAIN] ?? null,
            'nothing succeeded, so no success timestamp may move'
        );
    }
}
