<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\Support\ProviderOutcomeReceipt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Retry accounting is PER LOGICAL REQUEST. Pinned, and now endorsed.
 *
 * ── WHAT THIS FILE USED TO SAY ──────────────────────────────────────────
 * It pinned the opposite: one failing logical request charged the chain-wide
 * breaker FOUR times — the initial attempt plus
 * {@see ApiRetry::DEFAULT_MAX_RETRIES} retries — against a threshold of five,
 * so TWO bad requests opened a "five consecutive failures" breaker. Chain 8
 * opened on a counter of exactly 8 on 2026-09-08, which is 2 × 4, inside a
 * 23-second window. PR 7.6 recorded that as the wrong semantic and pinned it
 * anyway, because fixing it changes shared cross-service safety and PR 7.6
 * had evidence about one service out of four.
 *
 * PR B is that follow-up, with the per-service evidence gathered. The
 * accounting now settles ONCE per logical provider request — one endpoint,
 * one subject, one operation, including every retry of that operation.
 *
 * ── THE ARITHMETIC THAT CHANGED, STATED PLAINLY ─────────────────────────
 * It now takes FIVE failing logical requests to open the breaker, where two
 * used to be enough. That is the intended effect and the whole point: the
 * threshold constant says "five consecutive failures" and now means it. A
 * chain that is genuinely down still trips, because a down chain fails every
 * request; a chain that fails one request no longer trips on its own.
 *
 * ── WHAT DID NOT CHANGE ─────────────────────────────────────────────────
 * Retry COUNT, backoff delays, the 429 rule, the 4xx rule and the
 * application-error opt-in are all byte-for-byte the behaviour they were.
 * Pinned below, next to the parts that did change, so a future edit cannot
 * quietly trade one for the other.
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

    private static function serverError(): array
    {
        return \BccHttp::response(500, '{"message":"boom"}');
    }

    // ── the retry budget itself is untouched ────────────────────────────

    /** The constant that used to produce the multiplier. */
    public function testTheRetryDefaultIsThree(): void
    {
        self::assertSame(3, ApiRetry::DEFAULT_MAX_RETRIES);
    }

    /**
     * ⚠ THE ATTEMPTS ARE STILL FOUR. Deferring the charge must not quietly
     * reduce a request — including a half-open recovery probe — to a single
     * HTTP attempt. That would be a different behaviour change with a
     * different risk profile, and it is not this one.
     */
    public function testAFailingRequestStillMakesFourAttempts(): void
    {
        $run = $this->attempt([self::serverError()]);

        self::assertSame(4, $run['attempts'], 'one initial attempt plus three retries, unchanged');
    }

    /** The backoff between attempts is unchanged: two sleeps of 2s and one of 3s. */
    public function testTheBackoffDelaysAreUnchanged(): void
    {
        $this->attempt([self::serverError()]);

        self::assertSame([2, 3, 3], \BccSleepSpy::$slept, 'three capped backoffs between four attempts');
    }

    // ── the accounting that changed ─────────────────────────────────────

    /** ⚠ ONE failing 5xx request = ONE breaker failure, not four. */
    public function testOneFailingRequestChargesTheBreakerExactlyOnce(): void
    {
        $run = $this->attempt([self::serverError()]);

        self::assertSame(4, $run['attempts'], 'four attempts…');
        self::assertSame(
            [self::CHAIN],
            OnchainCircuitBreaker::$failureChains,
            '…and exactly one charge for the logical request they belong to'
        );
    }

    /** A transport failure settles the same way. */
    public function testATransportFailureAlsoChargesExactlyOnce(): void
    {
        $run = $this->attempt([new \WP_Error('http_request_failed', 'connection reset')]);

        self::assertSame(4, $run['attempts']);
        self::assertCount(1, OnchainCircuitBreaker::$failureChains);
    }

    /**
     * ⚠ THE OPERATIONAL CONSEQUENCE, STATED AS ARITHMETIC.
     *
     * Two failing requests no longer reach a threshold of five. Written
     * against the constants rather than the literal 2, so changing either
     * one without the other fails here instead of in production.
     */
    public function testTwoFailingRequestsNoLongerReachTheThreshold(): void
    {
        $this->attempt([self::serverError()]);
        $this->attempt([self::serverError()]);

        $charges = count(OnchainCircuitBreaker::$failureChains);

        self::assertSame(2, $charges, 'one charge per logical request');
        self::assertLessThan(
            OnchainCircuitBreaker::FAILURE_THRESHOLD,
            $charges,
            'two failing requests must NOT open a five-failure breaker'
        );
    }

    /** …and five of them do, so a genuinely failing chain still trips. */
    public function testFiveFailingRequestsReachTheThreshold(): void
    {
        for ($i = 0; $i < OnchainCircuitBreaker::FAILURE_THRESHOLD; $i++) {
            $this->attempt([self::serverError()]);
        }

        self::assertCount(
            OnchainCircuitBreaker::FAILURE_THRESHOLD,
            OnchainCircuitBreaker::$failureChains,
            'a down chain still trips — it just takes five failed requests to say so'
        );
    }

    /**
     * ⚠ TWO DISTINCT LOGICAL REQUESTS ARE NEVER COLLAPSED INTO ONE CHARGE.
     *
     * The fix defers a charge to the end of ONE request. It must not start
     * merging separate requests — two failing pages, wallets or methods are
     * two failures and have to read as two.
     */
    public function testTwoDistinctFailingRequestsChargeTwice(): void
    {
        $this->attempt([self::serverError()]);
        $this->attempt([new \WP_Error('http_request_failed', 'connection reset')]);

        self::assertSame(
            [self::CHAIN, self::CHAIN],
            OnchainCircuitBreaker::$failureChains,
            'a 5xx request and a transport request are two logical requests'
        );
    }

    // ── a retry that eventually succeeds ────────────────────────────────

    /** 5xx then 200: the request SUCCEEDED, so it charges nothing at all. */
    public function testAFailedAttemptFollowedBySuccessChargesNothing(): void
    {
        $run = $this->attempt([
            self::serverError(),
            \BccHttp::response(200, '{"data":{}}'),
        ]);

        self::assertSame(2, $run['attempts']);
        self::assertSame(
            [],
            OnchainCircuitBreaker::$failureChains,
            'the intermediate failure was local retry state and never reached the breaker'
        );
        self::assertSame([self::CHAIN], OnchainCircuitBreaker::$successChains);
    }

    /** Three transport errors then 200: still a success, still zero charges. */
    public function testThreeTransportErrorsThenSuccessChargesNothing(): void
    {
        $run = $this->attempt([
            new \WP_Error('http_request_failed', 'reset 1'),
            new \WP_Error('http_request_failed', 'reset 2'),
            new \WP_Error('http_request_failed', 'reset 3'),
            \BccHttp::response(200, '{"data":{}}'),
        ]);

        self::assertSame(4, $run['attempts'], 'the last attempt in the budget succeeded');
        self::assertSame([], OnchainCircuitBreaker::$failureChains);
        self::assertSame([self::CHAIN], OnchainCircuitBreaker::$successChains);
    }

    // ── the paths that were already correct ─────────────────────────────

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

    // ── the recovery probe ──────────────────────────────────────────────

    /**
     * The probe lock is released exactly once per logical request, on every
     * exit path. Four attempts must not mean four releases, and a settled
     * outcome must not mean a missed one.
     */
    public function testTheProbeIsReleasedExactlyOncePerRequest(): void
    {
        $this->attempt([self::serverError()]);
        self::assertSame([self::CHAIN], OnchainCircuitBreaker::$probeReleases, 'exhausted sequence');

        OnchainCircuitBreaker::reset();
        $this->attempt([\BccHttp::response(200, '{}')]);
        self::assertSame([self::CHAIN], OnchainCircuitBreaker::$probeReleases, 'successful sequence');

        OnchainCircuitBreaker::reset();
        $this->attempt([\BccHttp::response(301, '')]);
        self::assertSame([self::CHAIN], OnchainCircuitBreaker::$probeReleases, 'unrecorded 3xx exit');
    }

    /** A callable that throws must still release the probe, and charge nothing. */
    public function testAThrownExceptionReleasesTheProbeAndChargesNothing(): void
    {
        try {
            ApiRetry::request(
                static function (): array {
                    throw new \RuntimeException('provider client blew up');
                },
                ['chain_id' => self::CHAIN, 'label' => 'throwing']
            );
            self::fail('the exception must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('provider client blew up', $e->getMessage());
        }

        self::assertSame([self::CHAIN], OnchainCircuitBreaker::$probeReleases);
        self::assertSame([], OnchainCircuitBreaker::$failureChains, 'no wire outcome, no charge');
    }

    // ── the receipt ─────────────────────────────────────────────────────

    /** An exhausted sequence reports one charge, and forbids a second. */
    public function testTheReceiptReportsAnExhaustedSequenceAsCharged(): void
    {
        $receipt = new ProviderOutcomeReceipt();
        $this->attempt([self::serverError()], ['outcome' => $receipt]);

        self::assertSame(ProviderOutcomeReceipt::FAILURE, $receipt->lastOutcome());
        self::assertSame(1, $receipt->failureCharges(), 'one charge for four attempts');
        self::assertTrue($receipt->transportChargedFailure());
        self::assertFalse($receipt->domainMayCharge(), 'a domain verdict would be the second charge');
    }

    /** A success reports a credit, and still allows a semantic verdict. */
    public function testTheReceiptReportsSuccessAndStillAllowsASemanticVerdict(): void
    {
        $receipt = new ProviderOutcomeReceipt();
        $this->attempt([\BccHttp::response(200, '{"result":"garbage"}')], ['outcome' => $receipt]);

        self::assertSame(ProviderOutcomeReceipt::SUCCESS, $receipt->lastOutcome());
        self::assertSame(1, $receipt->successCredits());
        self::assertTrue(
            $receipt->domainMayCharge(),
            'a 200 carrying an unusable payload is a real failure of this operation'
        );
    }

    /** An outcome that charges nothing leaves the verdict to the caller. */
    public function testTheReceiptStaysEmptyWhenNothingWasSettled(): void
    {
        $receipt = new ProviderOutcomeReceipt();
        $this->attempt([\BccHttp::response(404, '{}')], ['outcome' => $receipt]);

        self::assertSame(ProviderOutcomeReceipt::NONE, $receipt->lastOutcome());
        self::assertSame(0, $receipt->failureCharges());
        self::assertTrue($receipt->domainMayCharge());
    }

    /** An open breaker refused the call, so there is nothing to judge. */
    public function testTheReceiptReportsAnOpenBreakerAsBlocked(): void
    {
        OnchainCircuitBreaker::$open = true;
        $receipt = new ProviderOutcomeReceipt();
        $run = $this->attempt([\BccHttp::response(200, '{}')], ['outcome' => $receipt]);

        self::assertSame(0, $run['attempts'], 'the request was never made');
        self::assertSame(ProviderOutcomeReceipt::BLOCKED, $receipt->lastOutcome());
        self::assertFalse(
            $receipt->domainMayCharge(),
            'nothing was observed, so no verdict may be charged onto an already-open breaker'
        );
    }

    /**
     * ⚠ NO LEAK BETWEEN REQUESTS. Two receipts, two logical requests, two
     * independent answers — the first request's failure must not travel into
     * the second request's verdict.
     */
    public function testAReceiptNeverCarriesAnotherRequestsOutcome(): void
    {
        $first  = new ProviderOutcomeReceipt();
        $second = new ProviderOutcomeReceipt();

        $this->attempt([self::serverError()], ['outcome' => $first]);
        $this->attempt([\BccHttp::response(200, '{}')], ['outcome' => $second]);

        self::assertSame(ProviderOutcomeReceipt::FAILURE, $first->lastOutcome());
        self::assertFalse($first->domainMayCharge());

        self::assertSame(ProviderOutcomeReceipt::SUCCESS, $second->lastOutcome());
        self::assertSame(0, $second->failureCharges(), 'the second request charged nothing of its own');
        self::assertTrue($second->domainMayCharge());
    }

    /** Passing no receipt changes nothing — the option is purely additive. */
    public function testOmittingTheReceiptChangesNothing(): void
    {
        $run = $this->attempt([self::serverError()]);

        self::assertSame(4, $run['attempts']);
        self::assertCount(1, OnchainCircuitBreaker::$failureChains);
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
