<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\ValueObjects\DelegatorCount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANT: a delegations request that fails, in ANY way, leaves the
 * stored delegator count exactly as it was — and still tells the truth about
 * the provider.
 *
 * ── WHY THE WHOLE STACK IS REAL ─────────────────────────────────────────
 * The defect lived in the seam between the transport and the value:
 * `lcdGet()` folds a transport error, a 429, a 4xx refusal, a 5xx and
 * unparseable JSON all into `null`, and the caller's `?? 0` then made every
 * one of them mean "zero delegators". Proving the fix therefore requires the
 * REAL retry loop and the REAL folding behaviour — a test that faked either
 * would be asserting against a second copy of the rule it is checking, the
 * exact mistake PR 7.6 shipped. Only the wire is scripted.
 *
 * ⚠ TWO THINGS MUST BE TRUE AT ONCE, and they pull in opposite directions:
 * the VALUE must fail safe (keep the old number) while the PROVIDER FAILURE
 * must stay visible (still retry, still charge the breaker, still attribute).
 * Making the count safe by swallowing the error would trade a data bug for a
 * blindness bug, so every scenario asserts both halves.
 *
 * @see DelegatorCountTest for the pure parsing table.
 */
#[CoversClass(DelegatorCount::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DelegatorCountFailSafeTest extends TestCase
{
    private const CHAIN    = 93;
    private const VALOPER  = 'cosmosvaloper1testtesttesttesttesttesttesttesttest';
    private const PREVIOUS = 4321;

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

    /** Reach the private fetch seam without going through a full enrichment. */
    private function fetchCount(?int $previous): ?int
    {
        $m = new \ReflectionMethod(CosmosFetcher::class, 'fetchDelegatorCount');
        $m->setAccessible(true);

        /** @var int|null $out */
        $out = $m->invoke($this->fetcher(), self::VALOPER, $previous);

        return $out;
    }

    private function charges(): int
    {
        return \BccBreakerStore::counter(self::CHAIN) ?? 0;
    }

    /**
     * ⚠ NAMED, NOT CONSTRUCTED. A data provider runs before `setUp()`, so it
     * cannot build a `WP_Error` — the stub declaring that class has not been
     * loaded yet. PR 7.7 hit this exact fatal.
     *
     * @return array<string, array{string, bool}>
     */
    public static function failures(): array
    {
        return [
            // name                        => [wire scenario, charges breaker?]
            'transport failure'            => ['transport', true],
            'http 429 rate limited'        => ['429', true],
            'http 503 provider refusal'    => ['503', true],
            'http 500 server error'        => ['500', true],
            'http 404 not found'           => ['404', false],
            'http 403 forbidden'           => ['403', false],
            'malformed json body'          => ['malformed', false],
            'html error page'              => ['html', false],
            'success but no total field'   => ['no_total', false],
            'success but total malformed'  => ['bad_total', false],
            'success but total overflows'  => ['overflow', false],
        ];
    }

    /** Script the wire for a named scenario. */
    private function arm(string $scenario): void
    {
        switch ($scenario) {
            case 'transport':
                \BccWire::$always = new \WP_Error('http_request_failed', 'cURL error 28');
                break;
            case '429':
                \BccWire::$always = ['code' => 429, 'body' => '{"code":8,"message":"too many requests"}'];
                break;
            case '503':
                \BccWire::$always = ['code' => 503, 'body' => '{"error" : "Please use your own full node."}'];
                break;
            case '500':
                \BccWire::$always = ['code' => 500, 'body' => '{"code":13,"message":"internal"}'];
                break;
            case '404':
                \BccWire::$always = ['code' => 404, 'body' => '{"code":5,"message":"not found"}'];
                break;
            case '403':
                \BccWire::$always = ['code' => 403, 'body' => '{"code":7,"message":"forbidden"}'];
                break;
            case 'malformed':
                \BccWire::$always = ['code' => 200, 'body' => '{"pagination":{"total":'];
                break;
            case 'html':
                \BccWire::$always = ['code' => 200, 'body' => '<html><body>gateway</body></html>'];
                break;
            case 'no_total':
                \BccWire::$always = ['code' => 200, 'body' => '{"delegation_responses":[],"pagination":{"next_key":null}}'];
                break;
            case 'bad_total':
                \BccWire::$always = ['code' => 200, 'body' => '{"pagination":{"total":"lots"}}'];
                break;
            case 'overflow':
                \BccWire::$always = ['code' => 200, 'body' => '{"pagination":{"total":"99999999999999999999"}}'];
                break;
            default:
                self::fail("unknown wire scenario '{$scenario}'");
        }
    }

    /**
     * THE CENTRAL CLAIM: for every failure shape, the previous value survives
     * unchanged and identically typed.
     */
    #[DataProvider('failures')]
    public function testPreviousValueSurvivesEveryFailure(string $scenario, bool $chargesBreaker): void
    {
        $this->arm($scenario);

        $out = $this->fetchCount(self::PREVIOUS);

        self::assertSame(self::PREVIOUS, $out, "scenario '{$scenario}' did not preserve the previous count");
        self::assertIsInt($out);
        self::assertNotSame(0, $out, "scenario '{$scenario}' produced the fabricated zero this fix exists to remove");

        // Anti-vacuity: the wire really was exercised.
        self::assertNotSame([], \BccWire::$urls, 'no request was made — the scenario proved nothing');
    }

    /**
     * With NO previous value, a failure must yield null ("unknown"), never 0.
     * This is the initial-fetch path, where a fabricated 0 would be written
     * into a fresh row and then treated as a real reading forever after.
     */
    #[DataProvider('failures')]
    public function testUnknownStaysNullWhenThereIsNoPrevious(string $scenario, bool $chargesBreaker): void
    {
        $this->arm($scenario);

        self::assertNull(
            $this->fetchCount(null),
            "scenario '{$scenario}' invented a value with nothing to fall back on"
        );
    }

    /**
     * ⚠ THE OTHER HALF. Failing safe on the value must not make the provider's
     * failure invisible — the breaker must still be charged exactly as it was
     * before this change, because a staking 5xx is a real provider fault.
     *
     * The charge count is MEASURED from the real breaker, never assumed, so a
     * future retry-policy change shows up here as a number rather than as
     * silent drift.
     */
    #[DataProvider('failures')]
    public function testBreakerAttributionStaysTruthful(string $scenario, bool $chargesBreaker): void
    {
        $this->arm($scenario);

        $before = $this->charges();
        $this->fetchCount(self::PREVIOUS);
        $after = $this->charges();

        if ($chargesBreaker) {
            self::assertGreaterThan(
                $before,
                $after,
                "scenario '{$scenario}' hid a real provider failure from the breaker"
            );
            $attribution = OnchainCircuitBreaker::attribution(self::CHAIN);
            self::assertNotNull(
                $attribution['kind'],
                "scenario '{$scenario}' charged the breaker with no failure kind"
            );
            self::assertSame(
                'standard_request',
                $attribution['request_class'],
                'a staking read is a standard request, never a smart query'
            );
        } else {
            self::assertSame(
                $before,
                $after,
                "scenario '{$scenario}' charged the breaker for something that is not a provider fault"
            );
        }
    }

    /**
     * A genuine zero MUST be stored. Fail-safe must not become "the count can
     * never fall", which would be the same class of lie pointing the other
     * way — a validator really can lose its last delegator.
     */
    public function testExplicitZeroOverwritesAPreviousCount(): void
    {
        \BccWire::$always = ['code' => 200, 'body' => '{"pagination":{"total":"0"}}'];

        $out = $this->fetchCount(self::PREVIOUS);

        self::assertSame(0, $out);
        self::assertIsInt($out);
    }

    /** The ordinary success path still works and is still an int. */
    public function testSuccessfulCountIsReturned(): void
    {
        \BccWire::$always = ['code' => 200, 'body' => '{"pagination":{"total":"512"}}'];

        self::assertSame(512, $this->fetchCount(self::PREVIOUS));
        self::assertSame(512, $this->fetchCount(null));
    }

    /**
     * ⚠ ANTI-VACUITY FOR THE WHOLE SUITE. Prove the harness can produce a
     * DIFFERENT answer — otherwise "previous was preserved" might just mean
     * "this test never changes anything".
     */
    public function testHarnessCanChangeTheValue(): void
    {
        \BccWire::$always = ['code' => 200, 'body' => '{"pagination":{"total":"7"}}'];
        self::assertSame(7, $this->fetchCount(self::PREVIOUS));

        \BccWire::reset();
        \BccWire::$always = new \WP_Error('http_request_failed', 'down');
        self::assertSame(self::PREVIOUS, $this->fetchCount(self::PREVIOUS));
    }
}
