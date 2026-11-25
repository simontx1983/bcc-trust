<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\Support\ProviderOutcomeReceipt;
use BCC\Trust\Onchain\ValueObjects\ProviderFailureKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A provider REFUSAL (a 4xx other than 429) still charges nothing at the
 * transport, and is now noted on the caller's receipt, so the domain charge
 * that follows when the refusal failed its operation can name it `refused`
 * instead of "Not recorded".
 *
 * Driven through the REAL ApiRetry retry loop against a scripted wire
 * (tests/Stubs/apiretry-real-stubs.php), like BreakerRetryAccountingTest.
 */
#[CoversClass(ApiRetry::class)]
#[CoversClass(ProviderOutcomeReceipt::class)]
#[CoversClass(ProviderFailureKind::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProviderRefusalAttributionTest extends TestCase
{
    private const CHAIN = 19;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/apiretry-real-stubs.php';
        OnchainCircuitBreaker::reset();
        \BccSleepSpy::reset();
        \BccApiRetryOptionStore::reset();
        \BCC\Core\Log\Logger::reset();
    }

    /** @param array<string, mixed>|\WP_Error $response */
    private static function send($response, ProviderOutcomeReceipt $receipt, int $chainId = self::CHAIN): void
    {
        ApiRetry::request(static fn () => $response, ['chain_id' => $chainId, 'label' => 'refusal', 'outcome' => $receipt]);
    }

    /** @return iterable<string, array{0: int}> */
    public static function refusals(): iterable
    {
        yield 'Subscan row_limit_exceeded (403)' => [403];
        yield 'bad request (400)'                => [400];
        yield 'unauthorised (401)'               => [401];
        yield 'not found (404)'                  => [404];
        yield 'unprocessable (422)'              => [422];
    }

    #[DataProvider('refusals')]
    public function testARefusalIsNotedButNeverCharged(int $code): void
    {
        $receipt = new ProviderOutcomeReceipt();
        self::send(\BccHttp::response($code, '{"code":403,"message":"row_limit_exceeded"}'), $receipt);

        self::assertSame([], OnchainCircuitBreaker::$failures, 'the transport charging rule is unchanged');
        self::assertSame([], OnchainCircuitBreaker::$successChains);
        self::assertSame(ProviderOutcomeReceipt::NONE, $receipt->lastOutcome(), 'nothing was settled');
        self::assertTrue($receipt->domainMayCharge(), 'the domain verdict is still the one charge');
        self::assertSame(ProviderFailureKind::REFUSED, $receipt->domainChargeKind());
        self::assertSame([], \BccSleepSpy::$slept, 'and still not retried');
    }

    /** The Polkadot shape: pages answered, then one refused. The refusal ended it. */
    public function testARefusalAfterAnsweredPagesIsWhatTheDomainChargeNames(): void
    {
        $receipt = new ProviderOutcomeReceipt();
        self::send(\BccHttp::response(200, '{}'), $receipt);
        self::send(\BccHttp::response(200, '{}'), $receipt);
        self::send(\BccHttp::response(403, '{}'), $receipt);

        self::assertSame(ProviderOutcomeReceipt::SUCCESS, $receipt->lastOutcome());
        self::assertTrue($receipt->domainMayCharge());
        self::assertSame(ProviderFailureKind::REFUSED, $receipt->domainChargeKind());
    }

    /** The last request ends the operation, so a later answer clears an earlier refusal. */
    public function testALaterSuccessClearsTheRefusal(): void
    {
        $receipt = new ProviderOutcomeReceipt();
        self::send(\BccHttp::response(404, '{}'), $receipt);
        self::send(\BccHttp::response(200, '{}'), $receipt);

        self::assertNull($receipt->domainChargeKind());
    }

    public function testALaterChargedFailureClearsTheRefusal(): void
    {
        $receipt = new ProviderOutcomeReceipt();
        self::send(\BccHttp::response(404, '{}'), $receipt);
        self::send(\BccHttp::response(429, '{}'), $receipt);

        self::assertSame(ProviderOutcomeReceipt::FAILURE, $receipt->lastOutcome());
        self::assertFalse($receipt->domainMayCharge(), 'the transport already charged');
        self::assertNull($receipt->domainChargeKind());
    }

    public function testAnOpenBreakerClearsTheRefusal(): void
    {
        $receipt = new ProviderOutcomeReceipt();
        self::send(\BccHttp::response(404, '{}'), $receipt);
        OnchainCircuitBreaker::$open = true;
        self::send(\BccHttp::response(200, '{}'), $receipt);

        self::assertSame(ProviderOutcomeReceipt::BLOCKED, $receipt->lastOutcome());
        self::assertNull($receipt->domainChargeKind());
    }

    /**
     * Status codes, not responses: a provider runs before setUp() loads the stubs.
     *
     * @return iterable<string, array{0: int, 1?: int}>
     */
    public static function notRefusals(): iterable
    {
        yield 'a 429 (charged as rate_limited)' => [429];
        yield 'a 3xx'                           => [301];
        yield 'a 2xx'                           => [200];
        yield 'a 403 with no chain to blame'    => [403, 0];
    }

    #[DataProvider('notRefusals')]
    public function testNothingElseIsNotedAsARefusal(int $code, int $chainId = self::CHAIN): void
    {
        $receipt = new ProviderOutcomeReceipt();
        self::send(\BccHttp::response($code, '{}'), $receipt, $chainId);

        self::assertNull($receipt->domainChargeKind());
    }

    public function testAFreshReceiptNamesNothing(): void
    {
        self::assertNull((new ProviderOutcomeReceipt())->domainChargeKind());
    }

    // ── The token ───────────────────────────────────────────────────────

    public function testRefusedIsABoundedTokenWithItsOwnWording(): void
    {
        self::assertSame('refused', ProviderFailureKind::REFUSED);
        self::assertTrue(ProviderFailureKind::isValid('refused'));
        self::assertSame('Provider rejected the request', ProviderFailureKind::label('refused'));
        self::assertSame('The chain node rejected the request.', ProviderFailureKind::sentence('refused'));
    }

    /** The transport mapper still names no 4xx: only a domain charge records `refused`. */
    public function testTheTransportMapperStillNamesNoRefusal(): void
    {
        foreach ([400, 401, 403, 404, 409, 422] as $code) {
            self::assertNull(ProviderFailureKind::fromOutcome(false, $code), (string) $code);
        }
    }
}
