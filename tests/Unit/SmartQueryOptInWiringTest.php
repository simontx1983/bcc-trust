<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryService;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\CosmwasmPassReport;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\ValueObjects\CosmwasmEnumerationFailure;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The two wirings a source-text test cannot see.
 *
 * ── WHY THIS FILE EXISTS: TWO MUTATION SURVIVORS ────────────────────────
 * PR 7.6's controls planted two defects that a fully green suite did not
 * notice, and both survived for the same reason — the existing tests
 * asserted that some CODE EXISTS, not that it RUNS.
 *
 *   1. `if ($smartQuery)` → `if (false)` in CosmosFetcher::lcdGetResult().
 *      Every occurrence of the string `'application_error'` stayed exactly
 *      where it was, so `CosmwasmSmartQueryRetryTest`, which COUNTS those
 *      occurrences in the source, stayed green — while no smart query
 *      passed the opt-in any more and every contract rejection went back to
 *      retrying four times and charging the chain-wide breaker.
 *
 *   2. The enumeration-telemetry call on the code-tail failure path was
 *      disabled. The value object's own unit tests and the repository's
 *      integration tests both stayed green, because neither of them drives
 *      the WORKER — the one place that decides whether the call happens.
 *
 * ⚠ SO THESE ASSERT BEHAVIOUR AT THE SEAM: the option is observed arriving
 * at the transport, and the telemetry is observed arriving at the
 * checkpoint, both from a real code path.
 */
#[CoversClass(CosmwasmDiscoveryService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SmartQueryOptInWiringTest extends TestCase
{
    private const CHAIN = 17;

    private const CONTRACT = 'cosmos1contractunderquestion';

    /** A real wasmd rejection: the contract answered, it is simply not CW-721. */
    private const REJECTION =
        'Error parsing into type cw20_base::msg::QueryMsg: unknown variant `num_tokens`, '
        . 'expected one of `balance`, `token_info`: query wasm contract failed';

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
            'slug'       => 'dungeon',
            'chain_type' => 'cosmos',
            'rest_url'   => 'https://api.dungeongames.io',
            'rpc_url'    => '',
            'is_active'  => 1,
            'decimals'   => 6,
        ]);
    }

    private function budget(int $requests = 100): CosmwasmTickBudget
    {
        return new CosmwasmTickBudget($requests, 60);
    }

    /** @param array<string,mixed> $payload */
    private function queueJson(array $payload, int $code = 200): void
    {
        ApiRetry::$queue[] = ['code' => $code, 'body' => (string) json_encode($payload)];
    }

    private function queueWasm500(string $message): void
    {
        ApiRetry::$queue[] = [
            'code' => 500,
            'body' => (string) json_encode(['code' => 2, 'message' => $message, 'details' => []]),
        ];
    }

    // ── survivor 1: the smart-query opt-in must actually be handed over ──

    /**
     * ⚠ THE OPTION IS OBSERVED AT THE TRANSPORT, NOT COUNTED IN THE SOURCE.
     *
     * Classifying a family issues real smart queries through the real
     * fetcher. At least one of them must carry `application_error`, or a
     * contract's own refusal is about to be blamed on the provider.
     */
    public function testASmartQueryActuallyPassesTheApplicationErrorOption(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN, 89);
        $family = CosmwasmCodeFamilyRepository::find(self::CHAIN, 89);
        self::assertNotNull($family);

        $this->queueJson(['contracts' => [self::CONTRACT], 'pagination' => []]);
        $this->queueWasm500(self::REJECTION);
        $this->queueWasm500(str_replace('num_tokens', 'contract_info', self::REJECTION));
        $this->queueWasm500(str_replace('num_tokens', 'get_collection_info_and_extension', self::REJECTION));

        CosmwasmDiscoveryService::classifyFamily(self::CHAIN, $this->fetcher(), $family, $this->budget());

        self::assertNotSame([], ApiRetry::$optionKeys, 'anti-vacuity: the transport must have been called');

        $sawOptIn = false;
        foreach (ApiRetry::$optionKeys as $keys) {
            if (in_array('application_error', $keys, true)) {
                $sawOptIn = true;
                break;
            }
        }

        self::assertTrue(
            $sawOptIn,
            'a smart query must HAND the opt-in to the transport, not merely mention it in the file'
        );
    }

    /**
     * A code listing must NOT opt in — an enumeration 5xx really is a node
     * problem and must keep its retries and its breaker protection.
     */
    public function testACodeListingDoesNotPassTheApplicationErrorOption(): void
    {
        $this->queueJson(['code_infos' => [], 'pagination' => ['next_key' => null]]);

        $this->fetcher()->listCodeFamilies(null, true, 10);

        self::assertNotSame([], ApiRetry::$optionKeys, 'anti-vacuity: the transport must have been called');
        foreach (ApiRetry::$optionKeys as $keys) {
            self::assertNotContains('application_error', $keys);
        }
    }

    // ── survivor 2: the tail path must record bounded telemetry ─────────

    /**
     * A failing code-tail read writes a BOUNDED token to the checkpoint.
     *
     * Before PR 7.6 this path incremented the breaker and recorded nothing,
     * which is exactly why the 2026-09-08 audit had to report the HTTP
     * status of the eight breaker-opening failures as unknown.
     */
    public function testAFailingCodeTailRecordsABoundedFailureCode(): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN, 'dungeon');
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::setCwDiscoveryState(
            self::CHAIN,
            ChainCheckpointRepository::CW_STATE_BACKFILLED
        );

        // ⚠ DRIVE THE WORKER, NOT THE SERVICE. The service performs the
        // read; the WORKER is what decides to record the failure, and the
        // mutation that survived disabled it there.
        $outcome = CosmwasmDiscoveryWorker::runSupervisedSingleChainPass(
            self::CHAIN,
            $this->budget(),
            new CosmwasmPassReport()
        );

        // ⚠ ANTI-VACUITY. An empty cw_last_error would also be produced by a
        // pass that never ran at all, so the outcome is pinned first.
        self::assertSame(CosmwasmDiscoveryWorker::PASS_RAN, $outcome);

        $row = ChainCheckpointRepository::get(self::CHAIN);
        self::assertNotNull($row);

        // The CALL is the guarantee. A later stage of the same pass may
        // legitimately clear the column on a subsequent success, so the call
        // log is what distinguishes "never recorded" from "superseded".
        self::assertNotSame(
            [],
            ChainCheckpointRepository::$enumerationFailures,
            'the tail failure must leave a durable trace'
        );
        foreach (ChainCheckpointRepository::$enumerationFailures as $code) {
            self::assertTrue(
                CosmwasmEnumerationFailure::isValid($code),
                "cw_last_error must hold a bounded token, got '{$code}'"
            );
        }
    }

    /** ⚠ …and the recorded token can never carry provider prose. */
    public function testTheRecordedTokenIsNeverProviderProse(): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN, 'dungeon');
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::setCwDiscoveryState(
            self::CHAIN,
            ChainCheckpointRepository::CW_STATE_BACKFILLED
        );

        ApiRetry::$responder = static fn(string $url) => new \WP_Error(
            'http_request_failed',
            'cURL error 28: Operation timed out after 20000 ms — https://user:secret@lcd.test/path'
        );

        $outcome = CosmwasmDiscoveryWorker::runSupervisedSingleChainPass(
            self::CHAIN,
            $this->budget(),
            new CosmwasmPassReport()
        );
        self::assertSame(CosmwasmDiscoveryWorker::PASS_RAN, $outcome);

        self::assertNotSame([], ChainCheckpointRepository::$enumerationFailures);

        foreach (ChainCheckpointRepository::$enumerationFailures as $recorded) {
            self::assertTrue(CosmwasmEnumerationFailure::isValid($recorded));
            foreach (['cURL', 'secret', 'https://', 'lcd.test', '20000', 'Operation timed out'] as $leak) {
                self::assertStringNotContainsStringIgnoringCase($leak, $recorded);
            }
        }
    }
}
