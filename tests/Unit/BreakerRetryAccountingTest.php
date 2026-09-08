<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Retry accounting is PER ATTEMPT. Pinned, not endorsed.
 *
 * ── THE DECISION THIS FILE RECORDS ──────────────────────────────────────
 * PR 7.6 measured that one failing logical request charges the chain-wide
 * breaker FOUR times — the initial attempt plus
 * {@see ApiRetry::DEFAULT_MAX_RETRIES} retries — against a threshold of
 * five. Chain 8 opened on a counter of exactly 8 on 2026-09-08, which is
 * 2 × 4, inside a 23-second window. Two bad requests open a "five
 * consecutive failures" breaker.
 *
 * One-failure-per-exhausted-operation is the better semantic, and PR 7.6
 * says so in writing. It was NOT adopted here, deliberately: this breaker is
 * keyed by chain id alone and shared by CosmWasm discovery, NFT enrichment,
 * chain refresh and the EVM indexer. Changing the accounting divides the
 * effective failure count by four for all of them, and PR 7.6 gathered
 * evidence about exactly one. Quadrupling how long three unmeasured services
 * hammer a failing provider is not a side effect a Cosmos classification fix
 * gets to have.
 *
 * ⚠ SO THESE TESTS ASSERT TODAY'S BEHAVIOUR, INCLUDING THE PART WE WANT TO
 * CHANGE. They exist so the follow-up is a deliberate edit with a failing
 * test in front of it, and so the behaviour cannot drift in the meantime in
 * either direction. If you are here because one of them failed, you are
 * changing shared cross-service safety — bring per-service evidence.
 */
#[CoversClass(ApiRetry::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BreakerRetryAccountingTest extends TestCase
{
    private const CHAIN = 8;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/apiretry-real-stubs.php';
        OnchainCircuitBreaker::reset();
        \BccSleepSpy::reset();
        \BccApiRetryOptionStore::reset();
        \BCC\Core\Log\Logger::reset();
    }

    /**
     * @param list<array<string,mixed>|\WP_Error> $responses
     * @return array{result: mixed, attempts: int}
     */
    private function attempt(array $responses, array $options = []): array
    {
        $attempts = 0;
        $fn = static function () use (&$attempts, $responses) {
            $r = $responses[$attempts] ?? $responses[count($responses) - 1];
            $attempts++;

            return $r;
        };

        $result = ApiRetry::request($fn, $options + ['chain_id' => self::CHAIN, 'label' => 'accounting']);

        return ['result' => $result, 'attempts' => $attempts];
    }

    // ── the multiplier ──────────────────────────────────────────────────

    /** The constant that produces the multiplier. */
    public function testTheRetryDefaultIsThree(): void
    {
        self::assertSame(3, ApiRetry::DEFAULT_MAX_RETRIES);
    }

    /** ⚠ ONE failing 5xx request = FOUR breaker failures. */
    public function testOneFailingRequestChargesTheBreakerFourTimes(): void
    {
        $run = $this->attempt([\BccHttp::response(503, '{"message":"upstream unavailable"}')]);

        self::assertSame(4, $run['attempts'], 'one initial attempt plus three retries');
        self::assertCount(
            4,
            OnchainCircuitBreaker::$failureChains,
            'accounting is per ATTEMPT: four attempts, four breaker charges'
        );
        self::assertSame(
            [self::CHAIN, self::CHAIN, self::CHAIN, self::CHAIN],
            OnchainCircuitBreaker::$failureChains
        );
    }

    /**
     * ⚠ THE OPERATIONAL CONSEQUENCE, STATED AS ARITHMETIC.
     *
     * Two failing requests exceed a threshold of five. Written against the
     * constants rather than the literal 8, so raising one without the other
     * fails here instead of in production.
     */
    public function testTwoFailingRequestsExceedTheThreshold(): void
    {
        $this->attempt([\BccHttp::response(500, '{"message":"boom"}')]);
        $this->attempt([\BccHttp::response(500, '{"message":"boom"}')]);

        $charges = count(OnchainCircuitBreaker::$failureChains);

        self::assertSame((ApiRetry::DEFAULT_MAX_RETRIES + 1) * 2, $charges);
        self::assertGreaterThanOrEqual(
            OnchainCircuitBreaker::FAILURE_THRESHOLD,
            $charges,
            'two failing requests are enough to open a five-failure breaker'
        );
    }

    /** A transport failure has the same multiplier. */
    public function testATransportFailureAlsoChargesFourTimes(): void
    {
        $run = $this->attempt([new \WP_Error('http_request_failed', 'connection reset')]);

        self::assertSame(4, $run['attempts']);
        self::assertCount(4, OnchainCircuitBreaker::$failureChains);
    }

    // ── the paths that are NOT multiplied ───────────────────────────────

    /** 429 is charged ONCE and never retried. */
    public function testRateLimitingIsChargedOnceAndNotRetried(): void
    {
        $run = $this->attempt([\BccHttp::response(429, '{"message":"slow down"}')]);

        self::assertSame(1, $run['attempts'], '429 must not be retried');
        self::assertCount(1, OnchainCircuitBreaker::$failureChains);
        self::assertSame([], \BccSleepSpy::$slept, 'a cron worker must not sleep on a 429');
    }

    /** A 4xx that is not 429 never touches the breaker at all. */
    public function testClientErrorsNeverChargeTheBreaker(): void
    {
        $run = $this->attempt([\BccHttp::response(404, '{"message":"no such path"}')]);

        self::assertSame(1, $run['attempts']);
        self::assertSame([], OnchainCircuitBreaker::$failureChains);
    }

    /** A recognised contract rejection carried in a 5xx charges nothing. */
    public function testARecognisedContractRejectionChargesNothing(): void
    {
        $body = json_encode([
            'code'    => 2,
            'message' => 'Error parsing into type cw20_base::msg::QueryMsg: unknown variant '
                . '`num_tokens`, expected one of `balance`, `token_info`: query wasm contract failed',
        ]);

        $run = $this->attempt(
            [\BccHttp::response(500, (string) $body)],
            ['application_error' => $this->predicate()]
        );

        self::assertSame(1, $run['attempts'], 'a contract answer is not retried');
        self::assertSame(
            [],
            OnchainCircuitBreaker::$failureChains,
            'an ordinary contract refusing a CW-721 query must never charge the provider breaker'
        );
    }

    /** A success credits the chain exactly once. */
    public function testASuccessCreditsOnce(): void
    {
        $this->attempt([\BccHttp::response(200, '{"data":{}}')]);

        self::assertSame([self::CHAIN], OnchainCircuitBreaker::$successChains);
        self::assertSame([], OnchainCircuitBreaker::$failureChains);
    }

    /** A retry that eventually succeeds credits, and the credit resets. */
    public function testAFailureThenSuccessCreditsAfterCharging(): void
    {
        $this->attempt([
            \BccHttp::response(500, '{"message":"boom"}'),
            \BccHttp::response(200, '{"data":{}}'),
        ]);

        self::assertCount(1, OnchainCircuitBreaker::$failureChains, 'only the failed attempt is charged');
        self::assertSame([self::CHAIN], OnchainCircuitBreaker::$successChains);
    }

    /** The production predicate, mirroring CosmosFetcher's wiring. */
    private function predicate(): callable
    {
        return static function (string $body, int $code): bool {
            $decoded = json_decode($body, true);
            $message = is_array($decoded) ? (string) ($decoded['message'] ?? '') : '';
            if (trim($message) === '') {
                return false;
            }

            return \BCC\Trust\Onchain\Services\CosmwasmClassifier::errorKindFromMessage($message, $code)
                === \BCC\Trust\Onchain\Services\CosmwasmClassifier::KIND_QUERY_UNSUPPORTED;
        };
    }
}
