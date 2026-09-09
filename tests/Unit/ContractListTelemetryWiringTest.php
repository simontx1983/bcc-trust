<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryService;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\ValueObjects\CosmwasmEnumerationFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The telemetry must actually be CALLED — from all three contract-listing
 * call sites, and from none of the paths that are not faults.
 *
 * ── WHY THIS FILE IS SEPARATE FROM {@see ContractListTelemetryTest} ─────
 * That one proves the RULE is right, with the real transport and the real
 * breaker. This one proves the rule is REACHED, by driving
 * {@see CosmwasmDiscoveryService} against recording repositories.
 *
 * PR 7.6 shipped a mutation survivor of exactly this shape: the enumeration
 * telemetry call was disabled and both the value object's unit tests and the
 * repository's integration tests stayed green, because neither drove the
 * caller — the one place that decides whether the call happens. So every
 * assertion below runs through a public service method and reads the
 * repository's CALL LOG.
 *
 * ⚠ ASSERT ON THE CALL LOG, NOT ON THE COLUMN. `cw_last_error` is
 * last-writer-wins by design: a later success in the same pass legitimately
 * clears it, so reading the column at the end cannot tell "never recorded"
 * from "recorded, then superseded".
 */
#[CoversClass(CosmwasmDiscoveryService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ContractListTelemetryWiringTest extends TestCase
{
    private const CHAIN = 23;

    private const CODE = 404;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-discovery-stubs.php';

        ApiRetry::reset();
        CosmwasmCodeFamilyRepository::reset();
        CosmwasmContractRepository::reset();
        ChainCheckpointRepository::reset();
        OnchainCircuitBreaker::reset();
        \BCC\Core\DB\AdvisoryLock::reset();
        \BCC\Core\Log\Logger::reset();
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

    private function budget(int $requests = 50): CosmwasmTickBudget
    {
        return new CosmwasmTickBudget($requests, 60);
    }

    private function queue(int $code, string $body): void
    {
        ApiRetry::$queue[] = ['code' => $code, 'body' => $body];
    }

    private function queueFailure(int $code = 503): void
    {
        $this->queue($code, (string) json_encode(['code' => 2, 'message' => 'upstream', 'details' => []]));
    }

    private function queuePage(string ...$contracts): void
    {
        $this->queue(200, (string) json_encode([
            'contracts'  => $contracts,
            'pagination' => ['next_key' => null],
        ]));
    }

    private function family(int $codeId = self::CODE): object
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN, $codeId);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN, $codeId);
        self::assertNotNull($family);

        return $family;
    }

    // ── all three call sites record ─────────────────────────────────────

    /**
     * The three public entry points that read
     * `/cosmwasm/wasm/v1/code/{id}/contracts`.
     *
     * ⚠ ALL THREE, NOT ONE. They charge the SAME chain-wide breaker through
     * the same transport, so instrumenting one and calling the job done is
     * how the original gap survived a green suite.
     *
     * @return array<string, array{0: string}>
     */
    public static function contractListingEntryPoints(): array
    {
        return [
            'classification sample' => ['classifyFamily'],
            'forward walk'          => ['enumerateFamilyPage'],
            'reverse tail'          => ['enumerateFamilyTail'],
        ];
    }

    private function drive(string $entryPoint): void
    {
        $fetcher = $this->fetcher();
        $budget  = $this->budget();
        $family  = $this->family();

        switch ($entryPoint) {
            case 'classifyFamily':
                CosmwasmDiscoveryService::classifyFamily(self::CHAIN, $fetcher, $family, $budget);
                break;
            case 'enumerateFamilyPage':
                CosmwasmDiscoveryService::enumerateFamilyPage(self::CHAIN, $fetcher, $family, $budget);
                break;
            case 'enumerateFamilyTail':
                CosmwasmDiscoveryService::enumerateFamilyTail(self::CHAIN, $fetcher, $family, $budget, 2);
                break;
            default:
                self::fail("unknown entry point {$entryPoint}");
        }
    }

    /** A failing contract listing leaves a bounded token, whichever path read it. */
    #[DataProvider('contractListingEntryPoints')]
    public function testEveryContractListingPathRecordsABoundedToken(string $entryPoint): void
    {
        $this->queueFailure(503);

        $this->drive($entryPoint);

        // ⚠ ANTI-VACUITY: a path that never made the request would also leave
        // an empty failure log.
        self::assertNotSame([], ApiRetry::$optionKeys, 'the transport must have been called');

        self::assertNotSame(
            [],
            ChainCheckpointRepository::$enumerationFailures,
            "{$entryPoint} charged the breaker and recorded nothing — the 2026-09-09 defect"
        );

        foreach (ChainCheckpointRepository::$enumerationFailures as $code) {
            self::assertTrue(
                CosmwasmEnumerationFailure::isValid($code),
                "cw_last_error must hold a bounded token, got '{$code}'"
            );
        }

        self::assertSame(
            CosmwasmEnumerationFailure::HTTP_5XX,
            ChainCheckpointRepository::$enumerationFailures[0]
        );
    }

    /** …and a wire failure maps to `transport`, not to a guess. */
    #[DataProvider('contractListingEntryPoints')]
    public function testEveryContractListingPathRecordsTransportForAWireFailure(string $entryPoint): void
    {
        ApiRetry::$responder = static fn(string $url) => new \WP_Error(
            'http_request_failed',
            'cURL error 7: connection refused — https://user:secret@lcd.test/x'
        );

        $this->drive($entryPoint);

        self::assertNotSame([], ChainCheckpointRepository::$enumerationFailures);
        self::assertSame(
            CosmwasmEnumerationFailure::TRANSPORT,
            ChainCheckpointRepository::$enumerationFailures[0],
            '⚠ never timeout and never dns — those are guesses, and this is a measurement'
        );

        // Requirement: raw provider content cannot reach storage.
        foreach (ChainCheckpointRepository::$enumerationFailures as $recorded) {
            foreach (['cURL', 'secret', 'https://', 'lcd.test', 'connection refused'] as $leak) {
                self::assertStringNotContainsStringIgnoringCase($leak, $recorded);
            }
        }
    }

    // ── recovery clears ─────────────────────────────────────────────────

    /**
     * A CONFIRMED successful enumeration retires the previous token.
     *
     * Without this a chain that recovered would stay visibly degraded until
     * some unrelated code-listing success happened to clear the column.
     */
    #[DataProvider('contractListingEntryPoints')]
    public function testASuccessfulContractListingClearsTheTelemetry(string $entryPoint): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::recordCwEnumerationFailure(
            self::CHAIN,
            CosmwasmEnumerationFailure::HTTP_5XX
        );

        $row = ChainCheckpointRepository::get(self::CHAIN);
        self::assertNotNull($row);
        self::assertSame(
            CosmwasmEnumerationFailure::HTTP_5XX,
            $row->cw_last_error,
            'anti-vacuity: the column must start dirty, or "cleared" proves nothing'
        );

        // A page, then the probes a classification sample would issue.
        $this->queuePage('cosmos1aaa');
        ApiRetry::$responder = static fn(string $url) => [
            'code' => 200,
            'body' => (string) json_encode(['data' => ['name' => 'A Collection']]),
        ];

        $this->drive($entryPoint);

        self::assertContains(
            self::CHAIN,
            ChainCheckpointRepository::$enumerationClears,
            "{$entryPoint} succeeded but never retired the stale token"
        );

        $row = ChainCheckpointRepository::get(self::CHAIN);
        self::assertNotNull($row);
        self::assertNull($row->cw_last_error);
    }

    // ── the deliberate exclusions ───────────────────────────────────────

    /**
     * A non-429 4xx is an ANSWER, and recording it would send an operator
     * hunting a provider problem that does not exist.
     *
     * `ApiRetry` neither retries nor charges the breaker for these — it calls
     * them "code bug, not provider load", a rule written after the Stargaze
     * unpadded-base64 regression burned fifty calls in seconds.
     *
     * @return array<string, array{0: int}>
     */
    public static function supportedClientAnswers(): array
    {
        return ['not found' => [404], 'bad request' => [400], 'forbidden' => [403]];
    }

    #[DataProvider('supportedClientAnswers')]
    public function testASupportedClientAnswerIsNotRecordedAsAProviderFault(int $status): void
    {
        $this->queueFailure($status);

        CosmwasmDiscoveryService::enumerateFamilyPage(
            self::CHAIN,
            $this->fetcher(),
            $this->family(),
            $this->budget()
        );

        self::assertNotSame([], ApiRetry::$optionKeys, 'anti-vacuity: the request must have happened');
        self::assertSame(
            [],
            ChainCheckpointRepository::$enumerationFailures,
            "HTTP {$status} is an answer, not a provider fault"
        );
        self::assertSame([], ChainCheckpointRepository::$enumerationClears);
    }

    /**
     * ⚠ THE FAILURE PATH MUST NOT ALSO CLEAR.
     *
     * Recording and then clearing in the same call would leave the column
     * NULL again — the original defect wearing a passing test.
     */
    public function testAFailingListingDoesNotAlsoClearTheColumn(): void
    {
        $this->queueFailure(500);

        CosmwasmDiscoveryService::enumerateFamilyPage(
            self::CHAIN,
            $this->fetcher(),
            $this->family(),
            $this->budget()
        );

        self::assertNotSame([], ChainCheckpointRepository::$enumerationFailures);
        self::assertSame([], ChainCheckpointRepository::$enumerationClears);

        $row = ChainCheckpointRepository::get(self::CHAIN);
        self::assertNotNull($row);
        self::assertSame(CosmwasmEnumerationFailure::HTTP_5XX, $row->cw_last_error);
    }

    /**
     * The telemetry is a MEASUREMENT, not a second breaker charge.
     *
     * `ApiRetry` already charges from inside the transport. Adding another
     * charge at this seam would silently make one failing contract page cost
     * more than it costs today, on a breaker shared by discovery,
     * enrichment, chain refresh and the EVM indexer.
     */
    public function testRecordingTelemetryDoesNotAddABreakerCharge(): void
    {
        $this->queueFailure(503);

        CosmwasmDiscoveryService::enumerateFamilyPage(
            self::CHAIN,
            $this->fetcher(),
            $this->family(),
            $this->budget()
        );

        self::assertNotSame([], ChainCheckpointRepository::$enumerationFailures);
        self::assertSame(
            [],
            OnchainCircuitBreaker::$failureChains,
            'the discovery layer must not charge the breaker for a contract listing — '
                . 'ApiRetry already does that from inside the transport'
        );
    }
}
