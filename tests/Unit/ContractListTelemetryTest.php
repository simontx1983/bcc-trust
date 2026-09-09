<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\ValueObjects\CosmwasmEnumerationFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANT: every contract-listing outcome that charges the circuit
 * breaker leaves a bounded token behind.
 *
 * ── THE DEFECT THIS PINS ────────────────────────────────────────────────
 * The 2026-09-09 staging canary ran one authorized Cosmos Hub session under
 * PR 7.6. Chain 8's breaker opened after EIGHT failures inside a ten-second
 * window — two requests at four charges each,
 * `ApiRetry::DEFAULT_MAX_RETRIES = 3` against a threshold of five — and
 * `wp_bcc_chain_checkpoints.cw_last_error` was **NULL**. PR 7.6 had
 * instrumented the two CODE-listing paths; the CONTRACT-listing path
 * (`/cosmwasm/wasm/v1/code/{id}/contracts`) had no hook at all, so the
 * failing requests could only be identified by elimination and their HTTP
 * status had to be reported as unknown.
 *
 * ── WHY THE WHOLE STACK IS REAL HERE ────────────────────────────────────
 * The claim under test is an AGREEMENT between two independently-written
 * rules: `ApiRetry`'s decision to charge the breaker, and
 * `CosmwasmEnumerationFailure::isProviderFault()`'s decision to record. A
 * test that faked either would be comparing a rule to a copy of itself —
 * exactly how PR 7.6 shipped a breaker "fix" that unified three of its four
 * readers. So the fetcher, the retry loop and the breaker are all
 * production code; only the wire is scripted.
 *
 * ⚠ THE CHARGE COUNT IS MEASURED, NEVER ASSUMED. Each scenario reads the
 * real breaker's own counter before and after, so a change to the retry
 * policy shows up here as a changed number rather than as a silent
 * divergence between what charges and what is recorded.
 */
#[CoversClass(CosmwasmEnumerationFailure::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ContractListTelemetryTest extends TestCase
{
    private const CHAIN = 91;

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

    /**
     * A scripted wire failure, named rather than constructed.
     *
     * ⚠ A DATA PROVIDER RUNS BEFORE `setUp()`, so it cannot build a
     * `WP_Error` — the stub that declares that class has not been loaded
     * yet. The provider names the outcome; the test body builds it.
     */
    private const WIRE_FAILURE = '__wire_failure__';

    /**
     * Every wire outcome a contract listing can meet.
     *
     * @return array<string, array{0: mixed, 1: bool, 2: bool, 3: string|null}>
     *         [scripted response, charges the breaker, is a provider fault, expected token]
     */
    public static function wireOutcomes(): array
    {
        $wasmError = static fn(string $m): string
            => (string) json_encode(['code' => 2, 'message' => $m, 'details' => []]);

        return [
            // response                                                              charges  fault   token
            'valid page' => [
                ['code' => 200, 'body' => (string) json_encode(['contracts' => ['cosmos1aaa'], 'pagination' => []])],
                false, false, null,
            ],
            'rate limited' => [
                ['code' => 429, 'body' => $wasmError('too many requests')],
                true, true, CosmwasmEnumerationFailure::RATE_LIMITED,
            ],
            'internal server error' => [
                ['code' => 500, 'body' => $wasmError('rpc error: code = Internal')],
                true, true, CosmwasmEnumerationFailure::HTTP_5XX,
            ],
            'service unavailable' => [
                ['code' => 503, 'body' => $wasmError('upstream connect error')],
                true, true, CosmwasmEnumerationFailure::HTTP_5XX,
            ],
            'gateway timeout' => [
                ['code' => 504, 'body' => ''],
                true, true, CosmwasmEnumerationFailure::HTTP_5XX,
            ],
            // ⚠ 501 IS A 5xx AND THEREFORE CHARGES. The CODE path converts it
            // into the durable `unsupported` state; the CONTRACT path has no
            // such state machine, so excluding it here would leave a
            // breaker-charging failure with nothing recorded — this defect's
            // exact shape.
            'not implemented' => [
                ['code' => 501, 'body' => $wasmError('unknown query path')],
                true, true, CosmwasmEnumerationFailure::HTTP_5XX,
            ],
            'wire failure' => [
                self::WIRE_FAILURE,
                true, true, CosmwasmEnumerationFailure::TRANSPORT,
            ],
            // ⚠ 2xx: `ApiRetry` records a SUCCESS, so nothing charges — yet the
            // node answered something we cannot read, which is neither a
            // contract rejection nor supported 4xx behaviour. Recorded on
            // purpose; see isProviderFault()'s table.
            'unreadable 200' => [
                ['code' => 200, 'body' => '<html>gateway</html>'],
                false, true, CosmwasmEnumerationFailure::MALFORMED_JSON,
            ],
            // ── the deliberate exclusions: answers, not faults ──────────────
            'not found' => [
                ['code' => 404, 'body' => $wasmError('not found')],
                false, false, null,
            ],
            'bad request' => [
                ['code' => 400, 'body' => $wasmError('invalid pagination key')],
                false, false, null,
            ],
            'forbidden' => [
                ['code' => 403, 'body' => $wasmError('forbidden')],
                false, false, null,
            ],
        ];
    }

    /**
     * ⚠ THE CENTRAL GUARANTEE: charging implies recording.
     *
     * Drives the REAL fetcher over the REAL retry loop against the REAL
     * breaker, measures the actual counter movement, and requires that any
     * movement at all is matched by `isProviderFault()` saying "record this".
     */
    #[DataProvider('wireOutcomes')]
    public function testChargingTheBreakerAlwaysImpliesARecordableFault(
        mixed $response,
        bool $expectCharges,
        bool $expectFault,
        ?string $expectToken
    ): void {
        \BccWire::$always = $response === self::WIRE_FAILURE
            ? new \WP_Error('http_request_failed', 'cURL error 7: connection refused')
            : $response;

        $before = \BccBreakerStore::counter(self::CHAIN) ?? 0;
        $page   = $this->fetcher()->listContractsForCodeId(7);
        $after  = \BccBreakerStore::counter(self::CHAIN) ?? 0;

        // ⚠ ANTI-VACUITY. A scenario that never reached the wire would
        // trivially satisfy "did not charge and was not a fault".
        self::assertNotSame([], \BccWire::$urls, 'the real transport must have been called');

        $charged = $after > $before;
        self::assertSame(
            $expectCharges,
            $charged,
            "breaker charge expectation wrong: counter moved {$before} → {$after}"
        );

        $fault = CosmwasmEnumerationFailure::isProviderFault(
            (string) $page['error_kind'],
            (int) $page['http_code']
        );
        self::assertSame($expectFault, $fault);

        // THE INVARIANT ITSELF.
        if ($charged) {
            self::assertTrue(
                $fault,
                'a contract listing that charged the breaker must be recordable, '
                    . 'or cw_last_error goes NULL again exactly as it did on 2026-09-09'
            );
        }

        if ($expectToken !== null) {
            self::assertSame(
                $expectToken,
                CosmwasmEnumerationFailure::fromResult(
                    (string) $page['error_kind'],
                    (int) $page['http_code']
                )
            );
        }
    }

    /**
     * The canary's own arithmetic, reproduced: ONE failing request charges
     * FOUR times, so TWO open a breaker whose threshold is five.
     *
     * Not a change request — a pin. If retry accounting is ever retuned,
     * this number must move deliberately and visibly.
     */
    public function testOneFailingContractListingChargesTheBreakerFourTimes(): void
    {
        \BccWire::$always = ['code' => 503, 'body' => ''];

        $this->fetcher()->listContractsForCodeId(7);

        self::assertSame(4, \BccBreakerStore::counter(self::CHAIN));
        self::assertSame(
            OnchainCircuitBreaker::PHASE_CLOSED,
            OnchainCircuitBreaker::phase(self::CHAIN),
            'four is below the threshold of five, so one request must not open it'
        );

        $this->fetcher()->listContractsForCodeId(8);

        self::assertSame(8, \BccBreakerStore::counter(self::CHAIN));
        self::assertSame(
            OnchainCircuitBreaker::PHASE_OPEN,
            OnchainCircuitBreaker::phase(self::CHAIN),
            'two failing requests reproduce the 8 = 2 x 4 the canary measured'
        );
    }

    /**
     * A LOCAL guard is not a provider failure.
     *
     * `listContractsForCodeId(0)` returns `ok = false` without making a
     * request. Blaming a provider we never contacted would be the fabricated
     * diagnosis this whole telemetry effort exists to replace.
     */
    public function testAGuardThatNeverLeftTheProcessIsNotAProviderFault(): void
    {
        $page = $this->fetcher()->listContractsForCodeId(0);

        self::assertFalse($page['ok']);
        self::assertSame([], \BccWire::$urls, 'no request may be made for an invalid code id');
        self::assertSame(0, \BccBreakerStore::counter(self::CHAIN) ?? 0);
        self::assertFalse(
            CosmwasmEnumerationFailure::isProviderFault(
                (string) $page['error_kind'],
                (int) $page['http_code']
            )
        );
    }

    /**
     * ⚠ A CONTRACT'S OWN REFUSAL IS NEVER AN ENUMERATION FAULT.
     *
     * `query_unsupported` is the smart-query vocabulary: it means a contract
     * answered "I do not have that variant", which is a CLASSIFICATION fact.
     * If it could leak into this gate, a chain would be marked degraded
     * every time a perfectly healthy non-NFT contract said no.
     */
    public function testSmartQueryRefusalsCannotBecomeEnumerationTelemetry(): void
    {
        $classifier = \BCC\Trust\Onchain\Services\CosmwasmClassifier::class;

        foreach ([200, 400, 404] as $status) {
            self::assertFalse(
                CosmwasmEnumerationFailure::isProviderFault($classifier::KIND_QUERY_UNSUPPORTED, $status),
                "query_unsupported must never be an enumeration fault (status {$status})"
            );
        }

        // ⚠ …with ONE honest exception, stated rather than hidden: a 5xx is a
        // provider fault whatever kind it carries, because that is exactly
        // what `ApiRetry` charges for when no `application_error` opt-in is
        // supplied — and enumeration never supplies one.
        self::assertTrue(
            CosmwasmEnumerationFailure::isProviderFault($classifier::KIND_QUERY_UNSUPPORTED, 500)
        );
    }

    /**
     * A contract listing must not hand `application_error` to the transport.
     *
     * That opt-in is what lets a 5xx be re-read as a contract's answer and
     * skip the breaker. On an enumeration endpoint a 5xx really is the
     * node's problem, and this is the wiring that keeps it that way.
     */
    public function testAContractListingNeverOptsOutOfBreakerProtection(): void
    {
        \BccWire::$always = ['code' => 500, 'body' => (string) json_encode([
            'code'    => 2,
            // The exact prose that WOULD be read as a contract answer if the
            // opt-in were ever passed here.
            'message' => 'Error parsing into type cw20_base::msg::QueryMsg: unknown variant '
                . '`num_tokens`, expected one of `balance`: query wasm contract failed',
            'details' => [],
        ])];

        $this->fetcher()->listContractsForCodeId(7);

        self::assertGreaterThan(
            0,
            \BccBreakerStore::counter(self::CHAIN) ?? 0,
            'an enumeration 5xx must charge the breaker even when the body looks like a contract refusal'
        );
    }

    /**
     * No token this path can emit is ever provider prose.
     *
     * The mapper reads only a kind and a status, so a hostile body cannot
     * reach storage — this drives a real response carrying credentials and a
     * URL and proves none of it survives.
     */
    public function testProviderContentCannotReachTheRecordedToken(): void
    {
        \BccWire::$always = new \WP_Error(
            'http_request_failed',
            'cURL error 28: Operation timed out after 20000 ms — https://user:secret@lcd.test/contracts'
        );

        $page  = $this->fetcher()->listContractsForCodeId(7);
        $token = CosmwasmEnumerationFailure::fromResult(
            (string) $page['error_kind'],
            (int) $page['http_code']
        );

        self::assertTrue(CosmwasmEnumerationFailure::isValid($token));
        foreach (['cURL', 'secret', 'https://', 'lcd.test', '20000', 'Operation timed out'] as $leak) {
            self::assertStringNotContainsStringIgnoringCase($leak, $token);
        }
    }

    /**
     * ⚠ HONESTY GUARD: `unexpected_response` is UNREACHABLE from this path,
     * and that is a decision, not an oversight.
     *
     * Every outcome the mapper would name `unexpected_response` is either a
     * non-429 4xx — which `ApiRetry` deliberately does not charge for,
     * calling it "code bug, not provider load" — or the local invalid-code-id
     * guard that never made a request. Recording either would mislabel a
     * supported answer as a provider failure. This test fails if a future
     * change makes the token reachable without that decision being revisited.
     */
    public function testUnexpectedResponseIsNeverEmittedByTheEnumerationGate(): void
    {
        $classifier = \BCC\Trust\Onchain\Services\CosmwasmClassifier::class;
        $kinds      = [
            $classifier::KIND_NONE,
            $classifier::KIND_QUERY_UNSUPPORTED,
            $classifier::KIND_NODE_ERROR,
            $classifier::KIND_TRANSPORT,
            $classifier::KIND_HTTP_4XX,
            $classifier::KIND_NOT_FOUND,
            $classifier::KIND_MALFORMED,
        ];

        $reachable = [];
        foreach ($kinds as $kind) {
            foreach ([0, 200, 301, 400, 401, 403, 404, 409, 422, 429, 500, 501, 502, 503, 504] as $status) {
                if (!CosmwasmEnumerationFailure::isProviderFault($kind, $status)) {
                    continue;
                }
                $reachable[CosmwasmEnumerationFailure::fromResult($kind, $status)] = true;
            }
        }

        self::assertNotSame([], $reachable, 'anti-vacuity: some outcome must be recordable');
        self::assertArrayNotHasKey(CosmwasmEnumerationFailure::UNEXPECTED_RESPONSE, $reachable);
        self::assertArrayNotHasKey(CosmwasmEnumerationFailure::TIMEOUT, $reachable);
        self::assertArrayNotHasKey(CosmwasmEnumerationFailure::DNS, $reachable);

        self::assertSame(
            [
                CosmwasmEnumerationFailure::HTTP_5XX,
                CosmwasmEnumerationFailure::MALFORMED_JSON,
                CosmwasmEnumerationFailure::RATE_LIMITED,
                CosmwasmEnumerationFailure::TRANSPORT,
            ],
            self::sorted(array_keys($reachable))
        );
    }

    /**
     * @param  list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
