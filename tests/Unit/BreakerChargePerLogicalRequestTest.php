<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\Support\ProviderOutcomeReceipt;
use BCC\Trust\Onchain\Workers\NftEthIndexerWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * ONE logical provider request costs the breaker AT MOST ONE failure —
 * measured on the REAL counter, through REAL callers.
 *
 * ── WHY THIS FILE EXISTS SEPARATELY ─────────────────────────────────────
 * {@see BreakerRetryAccountingTest} asserts what the retry loop TELLS the
 * breaker, using a recording double. That is the right tool for the retry
 * rules and the wrong one for this claim: a regression to per-attempt
 * charging would still be "one call per attempt", and a double counts calls.
 *
 * Everything here reads `_bcc_cb_counter_<chain>` — the same option an
 * operator reads on the Chains page — after driving the REAL worker, the
 * REAL fetcher, the REAL retry loop and the REAL breaker. The only fake is
 * the wire.
 *
 * ── THE TWO DOMAIN-VERDICT CASES, PROVEN SEPARATELY ─────────────────────
 *   1. Transport exhausts, the caller then sees the same failed result:
 *      the delta must stay exactly 1. It used to be 5 — four per-attempt
 *      charges plus the verdict — which is the entire threshold, so one bad
 *      head poll opened a chain-wide breaker by itself.
 *   2. Transport succeeds but the payload is unusable: the credit lands and
 *      the verdict charges exactly 1. A genuine semantic failure is NOT
 *      suppressed to avoid double charging; it is the only charge there is.
 */
#[CoversClass(ApiRetry::class)]
#[CoversClass(ProviderOutcomeReceipt::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BreakerChargePerLogicalRequestTest extends TestCase
{
    private const CHAIN = 4;

    /** Head block 1000 → safe head 988 (CONFIRMATIONS = 12). */
    private const HEAD_BLOCK = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/logical-request-charge-stubs.php';

        // ⚠ NO Logger::reset() HERE. The logger in play is the one
        // breaker-real-stubs.php declares (it is loaded first and wins the
        // class_exists guard), and it has no reset — calling one that only
        // the nft-indexer logger defines would error in every test.
        \BccWire::reset();
        \BccBreakerStore::reset();
        \BCC\Core\Observability\DegradationMetrics::reset();
        \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\WalletRepository::reset();
        \BCC\Trust\Onchain\Services\NftHoldingsIndexer::reset();

        \BCC\Trust\Onchain\Repositories\ChainRepository::$chain = (object) [
            'id'         => self::CHAIN,
            'slug'       => 'optimism',
            'chain_type' => 'evm',
            'rpc_url'    => 'https://opt.example/v2/testkey',
        ];
        \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$checkpoint = (object) [
            'chain_id'             => self::CHAIN,
            'state'                => 'healthy',
            'last_processed_block' => 500,
        ];
    }

    private function charges(): int
    {
        return \BccBreakerStore::counter(self::CHAIN) ?? 0;
    }

    /** @return array{code: int, body: string} */
    private static function rpcResult(string $result): array
    {
        return ['code' => 200, 'body' => (string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'result'  => $result,
        ])];
    }

    /** @param list<int> $blocks */
    private static function transferPage(array $blocks, ?string $pageKey): array
    {
        $transfers = [];
        foreach ($blocks as $i => $block) {
            $transfers[] = [
                'rawContract' => ['address' => '0xabcdef000000000000000000000000000000000' . ($i % 10)],
                'tokenId'     => '0x' . dechex($i + 1),
                'category'    => 'erc721',
                'blockNum'    => '0x' . dechex($block),
                'from'        => '0x0000000000000000000000000000000000000000',
                'to'          => '0x1111111111111111111111111111111111111111',
                'value'       => 1,
                'metadata'    => ['blockTimestamp' => '2026-07-01T00:00:00.000Z'],
                'asset'       => 'TestNFT',
            ];
        }
        $result = ['transfers' => $transfers];
        if ($pageKey !== null) {
            $result['pageKey'] = $pageKey;
        }

        return ['code' => 200, 'body' => (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result])];
    }

    // ── 0: the harness itself ───────────────────────────────────────────

    /**
     * ⚠ ANTI-VACUITY FOR EVERY NUMBER BELOW. If the composition in
     * logical-request-charge-stubs.php ever silently handed back a fake
     * breaker or a fake retry loop, every delta in this file would become a
     * statement about a recording double and would still be green.
     */
    public function testTheStackUnderTestIsTheRealOne(): void
    {
        $provenance = \BccRealStackGuard::provenance();

        self::assertStringContainsString('app/Domain/Onchain/Support/ApiRetry.php', str_replace('\\', '/', $provenance['api_retry']));
        self::assertStringContainsString('app/Domain/Onchain/Support/OnchainCircuitBreaker.php', str_replace('\\', '/', $provenance['breaker']));
        self::assertStringNotContainsString('tests/Stubs', str_replace('\\', '/', $provenance['api_retry']));
        self::assertStringNotContainsString('tests/Stubs', str_replace('\\', '/', $provenance['breaker']));
    }

    // ── 1: transport exhaustion + a redundant domain verdict ────────────

    /**
     * THE HEADLINE CASE. A head poll that dies on the wire: four attempts,
     * one transport charge, and the worker's `head <= 0` verdict adds
     * nothing because it is the same failed request.
     *
     * Before PR B this exact scenario wrote 5 — the threshold — and opened
     * the chain-wide breaker on a single tick.
     */
    public function testAnExhaustedHeadPollPlusItsDomainVerdictChargesExactlyOnce(): void
    {
        \BccWire::$always = new \WP_Error('http_request_failed', 'connection reset');

        NftEthIndexerWorker::runForChain(self::CHAIN);

        self::assertCount(4, \BccWire::$urls, 'anti-vacuity: the real retry loop made all four attempts');
        self::assertSame(1, $this->charges(), 'four attempts plus a domain verdict is ONE logical failure');
        self::assertLessThan(
            OnchainCircuitBreaker::FAILURE_THRESHOLD,
            $this->charges(),
            'one bad head poll must not open the chain-wide breaker by itself'
        );
        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(self::CHAIN));

        // The operator-facing record still says the tick failed.
        self::assertNotSame([], \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$failures);
    }

    /** The same shape on a 5xx rather than a wire failure. */
    public function testAnExhaustedFiveHundredHeadPollAlsoChargesExactlyOnce(): void
    {
        \BccWire::$always = ['code' => 503, 'body' => 'upstream unavailable'];

        NftEthIndexerWorker::runForChain(self::CHAIN);

        self::assertCount(4, \BccWire::$urls);
        self::assertSame(1, $this->charges());
    }

    // ── 2: transport success + a genuine semantic failure ───────────────

    /**
     * A 200 carrying a JSON-RPC error object. The node answered, so the
     * transport CREDITS a success — which clears any earlier failures — and
     * the worker's verdict then charges exactly one for an answer it cannot
     * use.
     *
     * ⚠ THE CREDIT IS NOT SUPPRESSED AND NEITHER IS THE VERDICT. Both are
     * true statements about different things: the host is reachable, and the
     * operation failed.
     */
    public function testATwoHundredCarryingAnRpcErrorCreditsThenChargesExactlyOnce(): void
    {
        // Three prior failures, so the credit has something to clear.
        OnchainCircuitBreaker::recordFailure(self::CHAIN);
        OnchainCircuitBreaker::recordFailure(self::CHAIN);
        OnchainCircuitBreaker::recordFailure(self::CHAIN);
        self::assertSame(3, $this->charges(), 'precondition');

        \BccWire::$always = ['code' => 200, 'body' => (string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'error'   => ['code' => -32000, 'message' => 'MATIC_MAINNET is not enabled for this app'],
        ])];

        NftEthIndexerWorker::runForChain(self::CHAIN);

        self::assertCount(1, \BccWire::$urls, 'a 200 is not retried');
        self::assertSame(
            1,
            $this->charges(),
            'the success cleared 3, then the semantic verdict charged its own 1'
        );
    }

    /**
     * A request that never reached the wire at all — misconfigured rpc_url.
     * Nothing was charged by transport, so the verdict is the only charge,
     * and it must still happen.
     */
    public function testAVerdictWithNoTransportCallStillChargesOnce(): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::$chain = (object) [
            'id'         => self::CHAIN,
            'slug'       => 'optimism',
            'chain_type' => 'evm',
            'rpc_url'    => 'https://opt.example/v2/',   // placeholder, refused before the wire
        ];

        NftEthIndexerWorker::runForChain(self::CHAIN);

        self::assertSame([], \BccWire::$urls, 'nothing was contacted');
        self::assertSame(1, $this->charges(), 'a genuine failure is never suppressed to avoid double charging');
    }

    // ── 3: pagination — one page is one logical request ─────────────────

    /**
     * ⚠ TWO PAGES IN ONE LOOP, AND THE RECEIPT MUST NOT LEAK BETWEEN THEM.
     *
     * Page 1 succeeds (credit), page 2 dies on the wire (one charge), and
     * the worker's fetch-failed verdict adds nothing because page 2 already
     * charged. Total delta: 1.
     */
    public function testAFailingSecondPageChargesOnceForTheWholeTick(): void
    {
        \BccWire::$queue = [
            self::rpcResult('0x' . dechex(self::HEAD_BLOCK)),   // head poll
            self::transferPage([600, 601], 'page-2'),            // page 1 — succeeds
        ];
        \BccWire::$always = new \WP_Error('http_request_failed', 'page 2 died');

        NftEthIndexerWorker::runForChain(self::CHAIN);

        self::assertSame(
            1,
            $this->charges(),
            'one failing page is one logical request, and the tick verdict is not a second charge'
        );
        self::assertNotSame([], \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$failures);
    }

    /**
     * The counterpart: a page that SUCCEEDS credits, so an earlier unrelated
     * failure on the chain is cleared rather than carried.
     */
    public function testASuccessfulTickLeavesNoCharge(): void
    {
        OnchainCircuitBreaker::recordFailure(self::CHAIN);
        self::assertSame(1, $this->charges(), 'precondition');

        \BccWire::$queue = [
            self::rpcResult('0x' . dechex(self::HEAD_BLOCK)),
            self::transferPage([600, 601], null),   // single page, fully drained
        ];

        NftEthIndexerWorker::runForChain(self::CHAIN);

        self::assertSame(0, $this->charges(), 'a healthy tick clears the chain');
        self::assertSame([], \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$failures);
    }

    // ── 4: two distinct logical requests are never merged ───────────────

    /**
     * Two ticks, each failing, are two logical requests and must read as 2.
     * The fix defers a charge within one request; it must never start
     * collapsing separate ones.
     */
    public function testTwoFailingTicksChargeTwice(): void
    {
        \BccWire::$always = new \WP_Error('http_request_failed', 'down');

        NftEthIndexerWorker::runForChain(self::CHAIN);
        NftEthIndexerWorker::runForChain(self::CHAIN);

        self::assertSame(2, $this->charges());
    }

    /**
     * ⚠ SHARING ONE RECEIPT ACROSS TWO REQUESTS MUST NOT SILENCE THE SECOND.
     *
     * A driver legitimately threads ONE receipt through a paginated helper —
     * PolkadotFetcher's validator walk does exactly that — so the receipt
     * outlives a single request by design. What it must never do is become a
     * deduplication key: each request still settles on its own, and the
     * receipt only ever REPORTS the last outcome.
     *
     * This is the difference between "defer the charge within one request"
     * and "charge once per chain", and only a counter can tell them apart.
     */
    public function testAReceiptSharedAcrossTwoRequestsStillChargesTwice(): void
    {
        \BccWire::$always = ['code' => 503, 'body' => '{}'];
        $shared = new ProviderOutcomeReceipt();

        ApiRetry::get('https://lcd.test/page-1', [], ['chain_id' => self::CHAIN, 'label' => 'p1', 'outcome' => $shared]);
        ApiRetry::get('https://lcd.test/page-2', [], ['chain_id' => self::CHAIN, 'label' => 'p2', 'outcome' => $shared]);

        self::assertSame(2, $this->charges(), 'two logical requests, two charges, one receipt');
        self::assertSame(2, $shared->failureCharges(), 'and the receipt reports both');
    }

    /**
     * ⚠ A LEAKED RECEIPT WOULD SWALLOW A LATER TICK'S ONLY CHARGE.
     *
     * Tick 1 fails on the wire, so ApiRetry charges and the receipt records
     * FAILURE. Tick 2 never reaches the wire at all (placeholder rpc_url),
     * so nothing charges it and the worker's verdict is its only charge. If
     * tick 1's receipt survived into tick 2 — hoisted, cached or made static
     * — that verdict would be suppressed and the second failure would vanish.
     */
    public function testATickThatNeverReachesTheWireStillChargesAfterAFailingTick(): void
    {
        \BccWire::$always = new \WP_Error('http_request_failed', 'down');
        NftEthIndexerWorker::runForChain(self::CHAIN);
        self::assertSame(1, $this->charges(), 'tick 1 charged once on the wire');

        \BCC\Trust\Onchain\Repositories\ChainRepository::$chain = (object) [
            'id'         => self::CHAIN,
            'slug'       => 'optimism',
            'chain_type' => 'evm',
            'rpc_url'    => 'https://opt.example/v2/',   // refused before the wire
        ];
        \BccWire::reset();

        NftEthIndexerWorker::runForChain(self::CHAIN);

        self::assertSame([], \BccWire::$urls, 'tick 2 contacted nothing');
        self::assertSame(2, $this->charges(), 'and still recorded its own failure');
    }

    /**
     * ⚠ TWO WALLETS IN ONE OWNERSHIP OPERATION ARE TWO LOGICAL REQUESTS.
     *
     * An ownership read for wallet A and one for wallet B hit the same chain
     * and the same endpoint, but they are separate subjects and each failure
     * is its own. Collapsing them would under-report a failing provider by
     * exactly the number of members being checked.
     */
    public function testTwoWalletsInOneOwnershipOperationChargeTwice(): void
    {
        \BccWire::$always = ['code' => 503, 'body' => '{}'];

        $fetcher = new CosmosFetcher((object) [
            'id'         => self::CHAIN,
            'slug'       => 'testchain',
            'chain_type' => 'cosmos',
            'rest_url'   => 'https://lcd.test',
            'rpc_url'    => '',
            'is_active'  => 1,
            'decimals'   => 6,
        ]);

        $contract = 'stars1contractaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $fetcher->cw721OwnerOf($contract, '1');
        $fetcher->cw721OwnerOf($contract, '2');

        self::assertNotSame([], \BccWire::$urls, 'anti-vacuity: both reads reached the wire');
        self::assertSame(2, $this->charges(), 'one charge per wallet-scoped read, never one for both');
    }

    // ── 5: the batch wave ───────────────────────────────────────────────

    /** Every URL failed at transport: the whole wave is ONE charge. */
    public function testAFullyFailedBatchWaveChargesOnce(): void
    {
        \BccWire::$always = new \WP_Error('http_request_failed', 'host unreachable');

        ApiRetry::getBatchSameHost(
            ['https://lcd.test/a', 'https://lcd.test/b', 'https://lcd.test/c'],
            [],
            ['chain_id' => self::CHAIN, 'label' => 'batch']
        );

        self::assertCount(3, \BccWire::$urls, 'all three URLs were attempted');
        self::assertSame(1, $this->charges(), 'one wave, one charge — not one per URL');
    }

    /** At least one answer means the host is reachable: a credit, not a charge. */
    public function testAPartiallySuccessfulBatchWaveCreditsAndChargesNothing(): void
    {
        OnchainCircuitBreaker::recordFailure(self::CHAIN);
        self::assertSame(1, $this->charges(), 'precondition');

        \BccWire::$queue = [
            new \WP_Error('http_request_failed', 'one url died'),
            ['code' => 404, 'body' => '{}'],
            ['code' => 200, 'body' => '{}'],
        ];

        ApiRetry::getBatchSameHost(
            ['https://lcd.test/a', 'https://lcd.test/b', 'https://lcd.test/c'],
            [],
            ['chain_id' => self::CHAIN, 'label' => 'batch']
        );

        self::assertSame(0, $this->charges(), 'a 404 among the answers is still the host answering');
    }

    /** The batch reports its outcome on a receipt too. */
    public function testTheBatchReportsItsOutcomeOnTheReceipt(): void
    {
        \BccWire::$always = new \WP_Error('http_request_failed', 'host unreachable');
        $receipt = new ProviderOutcomeReceipt();

        ApiRetry::getBatchSameHost(
            ['https://lcd.test/a'],
            [],
            ['chain_id' => self::CHAIN, 'label' => 'batch', 'outcome' => $receipt]
        );

        self::assertSame(ProviderOutcomeReceipt::FAILURE, $receipt->lastOutcome());
        self::assertFalse($receipt->domainMayCharge());
    }

    // ── 6: an already-open breaker ──────────────────────────────────────

    /**
     * An open breaker refuses the request before the wire. Nothing was
     * observed, so nothing may be charged — including by a domain verdict
     * that sees an empty result.
     */
    public function testAnOpenBreakerIsNotChargedFurther(): void
    {
        for ($i = 0; $i < OnchainCircuitBreaker::FAILURE_THRESHOLD; $i++) {
            OnchainCircuitBreaker::recordFailure(self::CHAIN);
        }
        self::assertSame(OnchainCircuitBreaker::PHASE_OPEN, OnchainCircuitBreaker::phase(self::CHAIN), 'precondition');
        $before = $this->charges();

        \BccWire::$always = new \WP_Error('http_request_failed', 'down');
        NftEthIndexerWorker::runForChain(self::CHAIN);

        self::assertSame([], \BccWire::$urls, 'an open breaker makes no request');
        self::assertSame($before, $this->charges(), 'and collects no further charges');
    }

    // ── 7: the recovery probe ───────────────────────────────────────────

    /**
     * A half-open probe still gets its FULL retry budget. Reducing a
     * recovery probe to one HTTP attempt would be a different behaviour
     * change, and this pins that PR B did not make it.
     */
    public function testAHalfOpenProbeKeepsItsFullRetryBudgetAndChargesOnce(): void
    {
        // Open, with the cooldown already elapsed, so the next look is HALF-OPEN.
        \BccBreakerStore::seedOpen(
            self::CHAIN,
            OnchainCircuitBreaker::FAILURE_THRESHOLD,
            time() - (OnchainCircuitBreaker::COOLDOWN_SECONDS + 60)
        );
        self::assertSame(OnchainCircuitBreaker::PHASE_HALF_OPEN, OnchainCircuitBreaker::phase(self::CHAIN), 'precondition');

        $before = $this->charges();
        \BccWire::$always = new \WP_Error('http_request_failed', 'still down');

        ApiRetry::get('https://lcd.test/probe', [], ['chain_id' => self::CHAIN, 'label' => 'probe']);

        self::assertCount(4, \BccWire::$urls, 'the probe keeps 1 + DEFAULT_MAX_RETRIES attempts');
        self::assertSame($before + 1, $this->charges(), 'and a failed probe costs exactly one');
    }

    /** A probe that succeeds closes the breaker and clears the counter. */
    public function testAHalfOpenProbeThatSucceedsClosesTheBreaker(): void
    {
        \BccBreakerStore::seedOpen(
            self::CHAIN,
            OnchainCircuitBreaker::FAILURE_THRESHOLD,
            time() - (OnchainCircuitBreaker::COOLDOWN_SECONDS + 60)
        );
        self::assertSame(OnchainCircuitBreaker::PHASE_HALF_OPEN, OnchainCircuitBreaker::phase(self::CHAIN), 'precondition');

        \BccWire::$always = ['code' => 200, 'body' => '{}'];
        ApiRetry::get('https://lcd.test/probe', [], ['chain_id' => self::CHAIN, 'label' => 'probe']);

        self::assertSame(0, $this->charges(), 'the counter row is gone');
        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(self::CHAIN));
    }

    /**
     * A probe whose retries eventually succeed is a SUCCESS, not an
     * accumulated failure — the property the whole change exists to produce.
     */
    public function testAProbeThatSucceedsOnItsLastAttemptIsASuccess(): void
    {
        OnchainCircuitBreaker::recordFailure(self::CHAIN);
        OnchainCircuitBreaker::recordFailure(self::CHAIN);
        self::assertSame(2, $this->charges(), 'precondition');

        \BccWire::$queue = [
            new \WP_Error('http_request_failed', 'attempt 1'),
            new \WP_Error('http_request_failed', 'attempt 2'),
            new \WP_Error('http_request_failed', 'attempt 3'),
            ['code' => 200, 'body' => '{}'],
        ];

        ApiRetry::get('https://lcd.test/p', [], ['chain_id' => self::CHAIN, 'label' => 'recovering']);

        self::assertCount(4, \BccWire::$urls);
        self::assertSame(0, $this->charges(), 'the request succeeded, so it charged nothing and cleared the chain');
    }
}
