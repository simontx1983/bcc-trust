<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\CosmosEndpointTransition;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy;
use BCC\Trust\Onchain\ValueObjects\ProviderFailureKind;
use BCC\Trust\Onchain\ValueObjects\ProviderRequestClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE THREE GAPS THE FIRST STAGING SWITCH EXPOSED, EACH PINNED.
 *
 * The switch merged in #251 ran correctly on staging on 2026-09-11 — but only
 * because the operator's script compensated for it. The shipped `execute()`:
 *
 *   1. took NO LOCK, so a discovery worker could write a cursor between the
 *      digest check and the clear;
 *   2. made NO BREAKER CALL, so a counter, open state and attribution earned
 *      by the replaced provider would keep counting against the new one;
 *   3. recorded NO FINGERPRINT for the endpoint it moved to.
 *
 * Staging was safe only because its breaker happened to be closed and empty
 * that day. The button must not depend on that.
 *
 * ⚠ The transition, the policy and the breaker all run for REAL. Only the
 * persistence edges are faked, so "the breaker was cleared" is evidence the
 * production clearing code produced — not a recorder saying it was asked.
 */
#[CoversClass(CosmosEndpointTransition::class)]
#[CoversClass(OnchainCircuitBreaker::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EndpointTransitionLockAndBreakerTest extends TestCase
{
    private const OLD  = 'https://rest.cosmos.directory/cosmoshub';
    private const NEW  = 'https://cosmos-api.polkachu.com';
    private const LOCK = 'bcc_cosmwasm_chain_8';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/endpoint-transition-stubs.php';

        \BccBreakerStore::reset();
        \BccTransitionWorld::reset();
        \BccTransitionWorld::seedChain8(self::OLD, [416, 434]);
    }

    private function reviewedDigest(): string
    {
        $plan = CosmosEndpointTransition::plan(8, self::NEW);
        self::assertTrue($plan['ok'], 'test precondition: the plan must be ready');

        return $plan['digest'];
    }

    /** Give chain 8 a breaker record earned by the OLD endpoint. */
    private function seedOldProviderBreaker(): void
    {
        $oldFp = (string) CosmosEndpointPolicy::fingerprint('cosmos', self::OLD);
        for ($i = 0; $i < 6; $i++) {
            OnchainCircuitBreaker::recordFailure(
                8,
                ProviderFailureKind::HTTP_5XX,
                ProviderRequestClass::STANDARD_REQUEST,
                $oldFp
            );
        }
        self::assertSame('http_5xx', OnchainCircuitBreaker::attribution(8)['kind'], 'precondition: old state present');
        self::assertNotNull(\BccBreakerStore::counter(8), 'precondition: counter present');
    }

    // ── 1. the lock ─────────────────────────────────────────────────────

    public function testExecuteTakesTheWorkersOwnLock(): void
    {
        CosmosEndpointTransition::execute(8, self::NEW, $this->reviewedDigest(), 1);

        self::assertContains(
            self::LOCK,
            \BccBreakerStore::$lockAttempts,
            'execute() must contend for the SAME lock the discovery worker takes'
        );
    }

    /** A worker mid-pass holds the lock: refuse, change nothing, leave its lock alone. */
    public function testExecuteRefusesWhenTheWorkerHoldsTheLock(): void
    {
        $digest = $this->reviewedDigest();
        \BccBreakerStore::$locks[self::LOCK] = true; // a worker is walking chain 8

        $result = CosmosEndpointTransition::execute(8, self::NEW, $digest, 1);

        self::assertFalse($result['ok']);
        self::assertSame('lock_contended', $result['reason']);
        self::assertSame([], \BccTransitionWorld::$writes, 'a contended switch must write nothing');
        self::assertSame(self::OLD, \BccTransitionWorld::$chains[8]->rest_url);
        self::assertTrue(
            \BccBreakerStore::$locks[self::LOCK] ?? false,
            'the worker\'s lock must be left exactly as the worker holds it'
        );
    }

    public function testTheLockIsReleasedAfterASuccessfulSwitch(): void
    {
        $result = CosmosEndpointTransition::execute(8, self::NEW, $this->reviewedDigest(), 1);

        self::assertTrue($result['ok'], 'reason=' . $result['reason']);
        self::assertArrayNotHasKey(self::LOCK, \BccBreakerStore::$locks, 'lock must be released');
    }

    /** `finally` must release on the refusal paths too, or the worker is locked out forever. */
    public function testTheLockIsReleasedAfterARefusal(): void
    {
        $result = CosmosEndpointTransition::execute(8, self::NEW, 'not-the-reviewed-digest', 1);

        self::assertSame('plan_stale', $result['reason']);
        self::assertSame([], \BccTransitionWorld::$writes);
        self::assertArrayNotHasKey(self::LOCK, \BccBreakerStore::$locks, 'a refusal must still release');
    }

    // ── 2. the breaker ──────────────────────────────────────────────────

    public function testASuccessfulSwitchClearsTheOldProvidersBreaker(): void
    {
        $this->seedOldProviderBreaker();
        $digest = $this->reviewedDigest();

        $result = CosmosEndpointTransition::execute(8, self::NEW, $digest, 1);

        self::assertTrue($result['ok'], 'reason=' . $result['reason']);
        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(8));
        self::assertNull(\BccBreakerStore::counter(8), 'the old counter must not keep counting against the new provider');
        $attribution = OnchainCircuitBreaker::attribution(8);
        self::assertNull($attribution['kind'], 'the old provider\'s failure must not describe the new one');
        self::assertNull($attribution['request_class']);
        self::assertNull($attribution['endpoint_fp']);
    }

    /**
     * ⚠ THE REASON THIS IS NOT recordSuccess(). Clearing must not write a
     * last-success timestamp: nobody has contacted the new endpoint yet, and
     * the stale-chain detector reads that option.
     */
    public function testClearingTheBreakerDoesNotFakeASuccess(): void
    {
        $this->seedOldProviderBreaker();
        $before = \BccBreakerStore::$options['bcc_onchain_last_success_8'] ?? null;

        CosmosEndpointTransition::execute(8, self::NEW, $this->reviewedDigest(), 1);

        self::assertSame(
            $before,
            \BccBreakerStore::$options['bcc_onchain_last_success_8'] ?? null,
            'a switch must not record a success that never happened'
        );
    }

    /** A refused switch leaves the breaker exactly as it was. */
    public function testARefusedSwitchDoesNotTouchTheBreaker(): void
    {
        $this->seedOldProviderBreaker();
        $counterBefore = \BccBreakerStore::counter(8);
        \BccTransitionWorld::$verifyOk = false; // destination fails identity

        $result = CosmosEndpointTransition::execute(8, self::NEW, $this->reviewedDigest(), 1);

        self::assertSame('target_network_mismatch', $result['reason']);
        self::assertSame($counterBefore, \BccBreakerStore::counter(8));
        self::assertSame('http_5xx', OnchainCircuitBreaker::attribution(8)['kind']);
        self::assertSame(self::OLD, \BccTransitionWorld::$chains[8]->rest_url, 'unverified host must not be written');
    }

    public function testForgetReportsWhetherAnythingWasCleared(): void
    {
        self::assertFalse(OnchainCircuitBreaker::forgetForEndpointChange(8), 'nothing to clear');

        $this->seedOldProviderBreaker();
        self::assertTrue(OnchainCircuitBreaker::forgetForEndpointChange(8), 'old state was cleared');
        self::assertFalse(OnchainCircuitBreaker::forgetForEndpointChange(8), 'idempotent');
    }

    public function testForgetRejectsANonsenseChainId(): void
    {
        self::assertFalse(OnchainCircuitBreaker::forgetForEndpointChange(0));
        self::assertFalse(OnchainCircuitBreaker::forgetForEndpointChange(-3));
    }

    // ── 3. the fingerprint ──────────────────────────────────────────────

    public function testTheAuditRecordsTheNewEndpointFingerprint(): void
    {
        $this->seedOldProviderBreaker();

        CosmosEndpointTransition::execute(8, self::NEW, $this->reviewedDigest(), 1);

        self::assertCount(1, \BccTransitionWorld::$audits);
        $meta = \BccTransitionWorld::$audits[0]['meta'];
        self::assertSame(
            CosmosEndpointPolicy::fingerprint('cosmos', self::NEW),
            $meta['endpoint_fp'] ?? null,
            'the audit must name the complete identity the chain now answers to'
        );
        self::assertTrue(CosmosEndpointPolicy::isFingerprint((string) $meta['endpoint_fp']));
        self::assertTrue($meta['breaker_cleared'] ?? null, 'breaker_cleared must be recorded as a fact');
    }

    public function testBreakerClearedIsFalseWhenThereWasNothingToClear(): void
    {
        CosmosEndpointTransition::execute(8, self::NEW, $this->reviewedDigest(), 1);

        self::assertFalse(\BccTransitionWorld::$audits[0]['meta']['breaker_cleared'] ?? null);
    }

    // ── order ───────────────────────────────────────────────────────────

    /** The row moves, the cursors clear, THEN the audit — nothing out of order. */
    public function testWritesHappenInTheIntendedOrder(): void
    {
        CosmosEndpointTransition::execute(8, self::NEW, $this->reviewedDigest(), 1);

        self::assertSame(
            ['chain.rest_url=' . self::NEW, 'families.clear_cursors', 'audit.admin_cosmos_endpoint_switch'],
            \BccTransitionWorld::$writes
        );
        self::assertSame([416 => null, 434 => null], \BccTransitionWorld::$cursors[8]);
    }
}
