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
 * A cosmos 500 is sometimes the CONTRACT's answer, not the node's failure.
 *
 * ── THE INCIDENT THESE TESTS ENCODE ─────────────────────────────────────
 * Dungeon canary, 2026-08-19. Code family 89's only contract is a White
 * Whale `incentive_factory` — a DeFi contract, unambiguously not a CW-721.
 * Asked `num_tokens`, the LCD answered, correctly and in 0.43s:
 *
 *   HTTP 500 {"code":2,"message":"Error parsing into type
 *   white_whale_std::pool_network::incentive_factory::QueryMsg: unknown
 *   variant `num_tokens`, expected one of `config`, `incentive`,
 *   `incentives`: query wasm contract failed"}
 *
 * ApiRetry saw `>= 500`, called it a server fault, retried it four times,
 * slept 8 seconds, and charged the CHAIN-WIDE circuit breaker each time.
 * Two probes of that one healthy contract opened the breaker, which then
 * blocked 15 unrelated code families — and corrupted the verdict on
 * family 89 itself, which became `temporarily_unreachable` instead of the
 * terminal, correct `not_cw721`.
 *
 * ── WHY THIS FILE USES THE REAL ApiRetry ────────────────────────────────
 * Every other CosmWasm suite fakes ApiRetry at its production FQN. That is
 * why a green suite never noticed: with the retry loop replaced, "retried
 * four times and blamed the chain" is not observable. Here the class under
 * test is REAL and its collaborators are the doubles — see
 * tests/Stubs/apiretry-real-stubs.php.
 *
 * Assertions are on the BREAKER LISTS and the ATTEMPT COUNT, never on
 * elapsed time.
 */
#[CoversClass(ApiRetry::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ApiRetryApplicationErrorTest extends TestCase
{
    private const CHAIN = 17;

    /** The preserved family-89 wire message, verbatim. */
    private const FAMILY_89_MESSAGE =
        'Error parsing into type white_whale_std::pool_network::incentive_factory::QueryMsg: '
        . 'unknown variant `num_tokens`, expected one of `config`, `incentive`, `incentives`: '
        . 'query wasm contract failed';

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
     * The CosmWasm smart-query predicate, as production supplies it.
     *
     * Mirrors `CosmosFetcher::isSmartQueryApplicationError()`; the fetcher's
     * own wiring is proven separately by CosmwasmSmartQueryRetryTest.
     */
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

    /**
     * Run one request, counting how many times the transport was invoked.
     *
     * @param list<array<string,mixed>|\WP_Error> $responses returned in order
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

        $result = ApiRetry::request($fn, $options + ['chain_id' => self::CHAIN, 'label' => 'test']);

        return ['result' => $result, 'attempts' => $attempts];
    }

    // ── the incident, encoded ───────────────────────────────────────────

    /** THE REGRESSION TEST. One attempt, no retry, no breaker failure. */
    public function testTheFamily89ResponseIsOneAttemptAndNeverBlamesTheChain(): void
    {
        $out = $this->attempt(
            [\BccHttp::lcdError(self::FAMILY_89_MESSAGE)],
            ['application_error' => $this->predicate()]
        );

        self::assertSame(1, $out['attempts'], 'a contract answer must not be retried');
        self::assertSame([], OnchainCircuitBreaker::$failureChains, 'the chain must not be blamed');
        self::assertSame([], OnchainCircuitBreaker::$successChains, 'nor credited — it was not a 2xx');
        self::assertSame([], \BccSleepSpy::$slept, 'no backoff for a permanent answer');
    }

    /** The response is PRESERVED so the domain can classify it. */
    public function testTheResponseBodySurvivesForDomainClassification(): void
    {
        $out = $this->attempt(
            [\BccHttp::lcdError(self::FAMILY_89_MESSAGE)],
            ['application_error' => $this->predicate()]
        );

        self::assertFalse(is_wp_error($out['result']), 'it is a response, not an error');
        self::assertSame(500, wp_remote_retrieve_response_code($out['result']));
        self::assertStringContainsString('unknown variant', wp_remote_retrieve_body($out['result']));
    }

    /** No number of contract rejections can open the chain breaker. */
    public function testContractRejectionsCannotOpenTheChainBreaker(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->attempt(
                [\BccHttp::lcdError(self::FAMILY_89_MESSAGE)],
                ['application_error' => $this->predicate()]
            );
        }

        self::assertSame([], OnchainCircuitBreaker::$failureChains, '20 rejections, zero blame');
    }

    // ── the controls: real failures keep their protection ───────────────

    /**
     * THE CONTROL THAT STOPS THIS BEING A BREAKER-DISABLING PATCH.
     *
     * `rpc error` is in the classifier's NODE_ERROR_TOKENS, checked BEFORE
     * the query-unsupported tokens — so even with the predicate supplied,
     * a genuine node fault retries and blames the chain exactly as before.
     */
    public function testAGenuineRpcErrorStillRetriesAndBlamesTheChain(): void
    {
        $out = $this->attempt(
            [\BccHttp::lcdError('rpc error: code = Unavailable desc = connection error')],
            ['application_error' => $this->predicate(), 'max_retries' => 3]
        );

        self::assertSame(4, $out['attempts'], '1 + 3 retries');
        self::assertCount(4, OnchainCircuitBreaker::$failureChains);
        self::assertSame([2, 3, 3], \BccSleepSpy::$slept, 'the documented capped backoff');
    }

    /** Same for a Querier system error. */
    public function testAQuerierSystemErrorStillRetriesAndBlamesTheChain(): void
    {
        $out = $this->attempt(
            [\BccHttp::lcdError('Querier system error: Unknown system error')],
            ['application_error' => $this->predicate(), 'max_retries' => 3]
        );

        self::assertSame(4, $out['attempts']);
        self::assertCount(4, OnchainCircuitBreaker::$failureChains);
    }

    /** An unrecognisable 500 body FAILS SAFE — it is treated as a node fault. */
    public function testAnUnrecognisedFiveHundredFailsSafeAsANodeError(): void
    {
        foreach (['', '<html>502 Bad Gateway</html>', '{"code":2,"message":""}', 'not json at all'] as $body) {
            OnchainCircuitBreaker::reset();
            \BccSleepSpy::reset();

            $out = $this->attempt(
                [\BccHttp::response(500, $body)],
                ['application_error' => $this->predicate(), 'max_retries' => 1]
            );

            self::assertSame(2, $out['attempts'], sprintf('body %s must still retry', var_export($body, true)));
            self::assertCount(2, OnchainCircuitBreaker::$failureChains, 'and must still blame the chain');
        }
    }

    /** A transport failure is never reinterpreted — the predicate is not consulted. */
    public function testTransportFailuresAreNeverReclassified(): void
    {
        $out = $this->attempt(
            [new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out')],
            ['application_error' => static fn(): bool => true, 'max_retries' => 1]
        );

        self::assertSame(2, $out['attempts'], 'timeouts still retry');
        self::assertCount(2, OnchainCircuitBreaker::$failureChains);
    }

    /** 429 keeps its own path: immediate return, blamed, never slept on. */
    public function testRateLimitingIsUnaffected(): void
    {
        $out = $this->attempt(
            [\BccHttp::response(429, '{"message":"unknown variant `num_tokens`"}')],
            ['application_error' => static fn(): bool => true]
        );

        self::assertSame(1, $out['attempts']);
        self::assertSame([self::CHAIN], OnchainCircuitBreaker::$failureChains, '429 still counts');
        self::assertSame([], \BccSleepSpy::$slept);
    }

    /** A 4xx already neither retried nor blamed. Prove it still does not. */
    public function testClientErrorsAreUnaffected(): void
    {
        $out = $this->attempt(
            [\BccHttp::response(404, '{"message":"not found"}')],
            ['application_error' => $this->predicate()]
        );

        self::assertSame(1, $out['attempts']);
        self::assertSame([], OnchainCircuitBreaker::$failureChains);
    }

    /** A 2xx still credits the chain. */
    public function testSuccessStillCreditsTheChain(): void
    {
        $out = $this->attempt(
            [\BccHttp::response(200, '{"data":{"count":0}}')],
            ['application_error' => $this->predicate()]
        );

        self::assertSame(1, $out['attempts']);
        self::assertSame([self::CHAIN], OnchainCircuitBreaker::$successChains);
    }

    // ── the default: every other caller is untouched ────────────────────

    /**
     * WITHOUT the option, the family-89 response behaves exactly as it did
     * before this change. This is what makes the feature opt-in rather
     * than a global relaxation.
     */
    public function testWithoutThePredicateTheSameResponseRetriesAndBlames(): void
    {
        $out = $this->attempt(
            [\BccHttp::lcdError(self::FAMILY_89_MESSAGE)],
            ['max_retries' => 3]
        );

        self::assertSame(4, $out['attempts'], 'unchanged legacy behaviour');
        self::assertCount(4, OnchainCircuitBreaker::$failureChains);
    }

    /** A non-callable option is ignored rather than fatal. */
    public function testANonCallableOptionIsIgnoredSafely(): void
    {
        $out = $this->attempt(
            [\BccHttp::lcdError(self::FAMILY_89_MESSAGE)],
            ['application_error' => 'not a callable at all', 'max_retries' => 1]
        );

        self::assertSame(2, $out['attempts'], 'falls back to strict behaviour');
        self::assertCount(2, OnchainCircuitBreaker::$failureChains);
    }
}
