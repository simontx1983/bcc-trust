<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\CosmosEndpointReview;
use BCC\Trust\Onchain\Services\CosmosEndpointTransition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The audited Cosmos endpoint switch: ordering, bindings and the result model.
 *
 * ── WHAT THIS FILE IS FOR, AND WHAT IT IS NOT ───────────────────────────
 * It pins the SEQUENCE and the REPORTING: which lock is taken, that the review
 * is claimed before anything is contacted, that a binding mismatch refuses
 * before a write, and — the part most easily got wrong — that once the
 * compare-and-swap has affected a row, no outcome is ever reported as though
 * nothing happened.
 *
 * It does NOT pin whether the CAS's SQL comparison is byte-exact. The fake
 * repository compares with `===`, which is the right call: byte-exactness is a
 * property of a collation and a cast, and only a database can answer it. That
 * is {@see \BCC\Trust\Tests\Integration\CosmosEndpointSwitchCasIntegrationTest},
 * which runs on MySQL and on production-engine MariaDB because the two disagree
 * about trailing spaces.
 */
#[CoversClass(CosmosEndpointTransition::class)]
#[CoversClass(CosmosEndpointReview::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EndpointTransitionLockAndBreakerTest extends TestCase
{
    private const CHAIN = 8;
    private const OPERATOR = 7;

    private const INCUMBENT = 'https://rest.cosmos.directory/cosmoshub';
    private const TARGET    = 'https://cosmos-api.polkachu.com';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/endpoint-transition-stubs.php';

        \BccTransitionWorld::reset();
        \BccTransitionWorld::seedChain8(self::INCUMBENT);
    }

    /** Mint a review and return its id. */
    private function review(string $target = self::TARGET): string
    {
        $r = CosmosEndpointTransition::review(self::CHAIN, $target, self::OPERATOR);
        self::assertTrue($r['ok'], 'test precondition: the review must be accepted — got ' . $r['reason']);

        return $r['review_id'];
    }

    /** @return array{ok: bool, reason: string, verified_network: string|null, failed_followups: list<string>} */
    private function switchIt(?string $reviewId = null, int $chainId = self::CHAIN): array
    {
        return CosmosEndpointTransition::execute($chainId, $reviewId ?? $this->review(), self::OPERATOR);
    }

    private function storedRestUrl(): ?string
    {
        return \BccTransitionWorld::$chains[self::CHAIN]->rest_url ?? null;
    }

    /** Did the switch contact the provider at all? */
    private function verified(): bool
    {
        foreach (\BccTransitionWorld::$writes as $w) {
            if (str_starts_with($w, 'verify(')) {
                return true;
            }
        }

        return false;
    }

    private function endpointWrites(): int
    {
        $n = 0;
        foreach (\BccTransitionWorld::$writes as $w) {
            if (str_starts_with($w, 'chain.rest_url=')) {
                $n++;
            }
        }

        return $n;
    }

    // ── The lock ────────────────────────────────────────────────────────

    public function testItTakesItsOwnLockAndNotTheScanners(): void
    {
        $this->switchIt();

        self::assertContains('bcc_cosmos_endpoint_8', \BccBreakerStore::$lockAttempts);
        self::assertNotContains(
            'bcc_cosmwasm_chain_8',
            \BccBreakerStore::$lockAttempts,
            'the switch must not borrow the discovery worker\'s lock'
        );
    }

    /**
     * THE DECOUPLING PROOF. A peer holding the scanner's lock must not block a
     * switch, or the two are still coupled however the constant is spelled.
     */
    public function testAPeerHoldingTheScannerLockDoesNotBlockTheSwitch(): void
    {
        \BccBreakerStore::$locks['bcc_cosmwasm_chain_8'] = true;

        $result = $this->switchIt();

        self::assertTrue($result['ok'], 'the scanner lock is nothing to do with this any more');
        self::assertSame(self::TARGET, $this->storedRestUrl());
    }

    public function testAContendedLockRefusesAndWritesNothing(): void
    {
        $reviewId = $this->review();
        \BccBreakerStore::$locks['bcc_cosmos_endpoint_8'] = true;

        $result = $this->switchIt($reviewId);

        self::assertFalse($result['ok']);
        self::assertSame('lock_contended', $result['reason']);
        self::assertSame(0, $this->endpointWrites());
        self::assertFalse($this->verified());
    }

    public function testTheLockIsReleasedOnSuccessAndOnRefusal(): void
    {
        $this->switchIt();
        self::assertArrayNotHasKey(
            'bcc_cosmos_endpoint_8',
            \BccBreakerStore::$locks,
            'released after a successful switch'
        );

        // The first switch moved the chain, so put it back: a second review
        // of the same target would otherwise be refused as already_current.
        \BccTransitionWorld::reset();
        \BccTransitionWorld::seedChain8(self::INCUMBENT);
        \BccTransitionWorld::$verifyOk = false;
        $this->switchIt();
        self::assertArrayNotHasKey(
            'bcc_cosmos_endpoint_8',
            \BccBreakerStore::$locks,
            'released on a refusal too - the finally covers every exit'
        );
    }

    // ── The review, and replay ──────────────────────────────────────────

    /**
     * ⚠ A REPLACEMENT REVIEW RETIRES THE OLDER CONFIRMATION — even though the
     * chain and the target are identical, which is exactly the case a binding
     * on chain and target alone would miss.
     */
    public function testAReplacementReviewInvalidatesTheOlderConfirmation(): void
    {
        $first  = $this->review();
        $second = $this->review();
        self::assertNotSame($first, $second, 'precondition: a replacement mints a new id');

        $stale = $this->switchIt($first);

        self::assertFalse($stale['ok']);
        self::assertSame('review_token_invalid', $stale['reason']);
        self::assertSame(0, $this->endpointWrites());
        self::assertFalse($this->verified(), 'a retired confirmation must not reach the provider');

        self::assertTrue($this->switchIt($second)['ok'], 'the current confirmation still works');
    }

    /** Replay cannot verify twice and cannot write twice. */
    public function testAReplayedConfirmationNeitherVerifiesNorWritesAgain(): void
    {
        $reviewId = $this->review();
        self::assertTrue($this->switchIt($reviewId)['ok']);

        $writesAfterFirst = \BccTransitionWorld::$writes;

        $replay = $this->switchIt($reviewId);

        self::assertFalse($replay['ok']);
        self::assertSame('review_token_invalid', $replay['reason']);
        self::assertSame(
            $writesAfterFirst,
            \BccTransitionWorld::$writes,
            'a replay must add no write and no provider call of any kind'
        );
    }

    /**
     * ⚠ CONSUMPTION IS CHECKED BEFORE THE PROVIDER IS CONTACTED. If the claim
     * cannot be made, a second arrival could still make it — so this must cost
     * zero provider calls and zero writes.
     */
    public function testAFailedConsumptionStopsBeforeAnyProviderCallOrWrite(): void
    {
        $reviewId = $this->review();
        \BccBreakerStore::$deleteTransientFails = true;

        $result = $this->switchIt($reviewId);

        self::assertFalse($result['ok']);
        self::assertSame('review_consume_failed', $result['reason']);
        self::assertFalse($this->verified(), 'the claim failed, so nothing may be contacted');
        self::assertSame(0, $this->endpointWrites());
        self::assertSame([], \BccTransitionWorld::$audits);
    }

    public function testAConfirmationForAnotherChainIsRefused(): void
    {
        $reviewId = $this->review();

        $result = CosmosEndpointTransition::execute(99, $reviewId, self::OPERATOR);

        self::assertFalse($result['ok']);
        self::assertSame('review_chain_mismatch', $result['reason']);
        self::assertSame(0, $this->endpointWrites());
    }

    public function testAnotherOperatorsConfirmationIsNotUsable(): void
    {
        $reviewId = $this->review();

        $result = CosmosEndpointTransition::execute(self::CHAIN, $reviewId, 99);

        self::assertFalse($result['ok']);
        self::assertSame('review_token_invalid', $result['reason']);
        self::assertSame(0, $this->endpointWrites());
    }

    // ── Bindings ────────────────────────────────────────────────────────

    public function testAChangedIncumbentRefusesWithoutWriting(): void
    {
        $reviewId = $this->review();
        \BccTransitionWorld::$chains[self::CHAIN]->rest_url = 'https://cosmos-api.polkachu.com/';

        $result = $this->switchIt($reviewId);

        self::assertFalse($result['ok']);
        self::assertSame('from_mismatch', $result['reason']);
        self::assertSame(0, $this->endpointWrites());
        self::assertFalse($this->verified());
    }

    /**
     * @return list<array{0: string, 1: mixed}>
     */
    public static function identityFields(): array
    {
        return [
            'slug'      => ['slug', 'osmosis'],
            'is_active' => ['is_active', 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('identityFields')]
    public function testAChangedIdentityRefusesWithoutWriting(string $field, mixed $value): void
    {
        $reviewId = $this->review();
        \BccTransitionWorld::$chains[self::CHAIN]->{$field} = $value;

        $result = $this->switchIt($reviewId);

        self::assertFalse($result['ok']);
        self::assertSame(0, $this->endpointWrites(), 'nothing may be written on a moved identity');
        self::assertFalse($this->verified());
        self::assertSame([], \BccTransitionWorld::$audits);
    }

    /**
     * ⚠ THE RACE. Identity changes AFTER the proof and BEFORE the swap — the
     * window the lock cannot close, because nothing else takes this lock.
     * The write predicate is what refuses.
     */
    public function testAnIdentityChangeBetweenProofAndWriteIsRefusedByTheWrite(): void
    {
        $reviewId = $this->review();
        \BccTransitionWorld::$afterVerify = static function (): void {
            \BccTransitionWorld::$chains[8]->slug = 'osmosis';
        };

        $result = $this->switchIt($reviewId);

        self::assertFalse($result['ok']);
        self::assertSame('identity_changed', $result['reason']);
        self::assertTrue($this->verified(), 'precondition: the proof was made before the race');
        self::assertSame(0, $this->endpointWrites(), 'the write must refuse a moved identity');
        self::assertSame(self::INCUMBENT, $this->storedRestUrl());
        self::assertSame([], \BccTransitionWorld::$audits);
    }

    // ── Proof before write ──────────────────────────────────────────────

    public function testAFailedProofWritesNothingAndAuditsNothing(): void
    {
        \BccTransitionWorld::$verifyOk = false;

        $result = $this->switchIt();

        self::assertFalse($result['ok']);
        self::assertSame('target_network_mismatch', $result['reason']);
        self::assertSame(self::INCUMBENT, $this->storedRestUrl());
        self::assertSame([], \BccTransitionWorld::$audits);
        self::assertSame(0, \BccTransitionWorld::$cacheBusts);
    }

    /**
     * ⚠ THE PROOF IS MADE NOW, NOT SERVED FROM AN EARLIER ONE.
     *
     * `CosmosEndpointVerifier::verify()` caches a success per endpoint
     * fingerprint and consults that cache BY DEFAULT. On this path the cache
     * is bypassed deliberately: a recorded proof says the host answered
     * correctly at some past moment, and what authorises repointing a chain is
     * that it answers correctly NOW. A `true` here would let an operator switch
     * to a host that has since failed, for as long as the transient lived.
     *
     * The argument is asserted rather than read off the source line, because
     * the default is the unsafe value and a dropped argument would be silent.
     */
    public function testTheProofBypassesTheCacheSoAnEarlierProofCannotStandIn(): void
    {
        $this->switchIt();

        self::assertSame(
            [false],
            \BccTransitionWorld::$verifyCacheFlags,
            'exactly one verification, and it must not be allowed to use the cache'
        );
    }

    /**
     * ANTI-VACUITY for the test above. The recorder captures `true` when `true`
     * is what it is given — so `[false]` is a measurement of the call the
     * service makes, not an artefact of a recorder that can only say `false`.
     */
    public function testTheCacheFlagRecorderWouldCaptureACachedProof(): void
    {
        \BccTransitionWorld::$verifyCacheFlags = [];

        // The fake's own default is the UNSAFE value, which is the point.
        \BCC\Trust\Onchain\Support\CosmosEndpointVerifier::verify((object) [
            'id' => 8, 'slug' => 'cosmos', 'rest_url' => self::TARGET,
        ]);

        self::assertSame([true], \BccTransitionWorld::$verifyCacheFlags);
    }

    public function testTheProofHappensBeforeTheWrite(): void
    {
        $this->switchIt();

        $order = array_values(array_filter(
            \BccTransitionWorld::$writes,
            static fn(string $w): bool => str_starts_with($w, 'verify(') || str_starts_with($w, 'chain.rest_url=')
        ));

        self::assertSame(
            ['verify(' . self::TARGET . ')', 'chain.rest_url=' . self::TARGET],
            $order,
            'the destination is proven before the row moves, never after'
        );
    }

    // ── The result model after the swap ─────────────────────────────────

    public function testASuccessfulSwitchClearsTheBreakerAuditsAndBustsTheCache(): void
    {
        $result = $this->switchIt();

        self::assertTrue($result['ok']);
        self::assertSame('switched', $result['reason']);
        self::assertSame([], $result['failed_followups']);
        self::assertSame(self::TARGET, $this->storedRestUrl());
        self::assertGreaterThan(0, \BccTransitionWorld::$cacheBusts);
        self::assertCount(1, \BccTransitionWorld::$audits);
        self::assertSame('switched', \BccTransitionWorld::$audits[0]['meta']['outcome']);
    }

    /**
     * ⚠ FALSE FROM THE BREAKER IS A FACT, NOT A FAILURE. On a healthy chain
     * there is nothing to clear, and reporting that as a failed follow-up would
     * warn the operator on every ordinary switch.
     */
    public function testNothingToClearIsNotReportedAsAFailure(): void
    {
        $result = $this->switchIt();

        self::assertSame('switched', $result['reason']);
        self::assertSame([], $result['failed_followups']);
    }

    /**
     * ⚠ THE ENDPOINT CHANGED. A read-back that cannot be performed does not
     * turn a completed write into "nothing happened" — and the breaker is left
     * alone, because we cannot say which host the chain is now on.
     */
    public function testAnUnreadableReadBackReportsSwitchedUnconfirmedAndSpareTheBreaker(): void
    {
        \BccTransitionWorld::$readBackAvailable = false;

        $result = $this->switchIt();

        self::assertTrue($result['ok']);
        self::assertSame('switched_unconfirmed', $result['reason']);
        self::assertGreaterThan(0, \BccTransitionWorld::$cacheBusts, 'the cache is busted even unconfirmed');
        self::assertCount(1, \BccTransitionWorld::$audits, 'a write that happened is always recorded');
        self::assertSame('switched_unconfirmed', \BccTransitionWorld::$audits[0]['meta']['outcome']);
        // ⚠ THE KEY MUST EXIST. Written `?? null` this passed while the audit
        // row carried no `breaker_cleared` at all — which it did not, for the
        // whole of this branch's life, though the design requires it and the
        // service's own comment claimed it. A vacuous assertion hid a real gap.
        $meta = \BccTransitionWorld::$audits[0]['meta'];
        self::assertArrayHasKey('breaker_cleared', $meta);
        self::assertNull(
            $meta['breaker_cleared'],
            'the breaker must not be touched for an endpoint we cannot confirm'
        );
    }

    public function testASupersedingWriteIsReportedAsSuchAndSparesTheBreaker(): void
    {
        \BccTransitionWorld::$readBackRestUrl = 'https://rest.cosmos.directory/cosmoshub';

        $result = $this->switchIt();

        self::assertTrue($result['ok']);
        self::assertSame('switched_then_superseded', $result['reason']);
        self::assertCount(1, \BccTransitionWorld::$audits);
        $meta = \BccTransitionWorld::$audits[0]['meta'];
        self::assertArrayHasKey('breaker_cleared', $meta);
        self::assertNull($meta['breaker_cleared'], 'a superseded endpoint is not confirmed either');
    }

    public function testAFailedAuditIsReportedWithoutHidingTheWrite(): void
    {
        \BccTransitionWorld::$auditOk = false;

        $result = $this->switchIt();

        self::assertTrue($result['ok'], 'the endpoint DID change');
        self::assertSame('switched_followups_failed', $result['reason']);
        self::assertSame(['audit'], $result['failed_followups']);
        self::assertSame(self::TARGET, $this->storedRestUrl());
    }

    public function testAnErroredWriteIsUnconfirmedNotNothingHappened(): void
    {
        \BccTransitionWorld::$forceCasResult = -1;

        $result = $this->switchIt();

        self::assertFalse($result['ok']);
        self::assertSame('write_unconfirmed', $result['reason']);
        self::assertGreaterThan(
            0,
            \BccTransitionWorld::$cacheBusts,
            'a write that may have applied must not leave the cache holding the old host'
        );
    }

    public function testAZeroRowWriteReportsNothingChanged(): void
    {
        \BccTransitionWorld::$forceCasResult = 0;

        $result = $this->switchIt();

        self::assertFalse($result['ok']);
        self::assertSame('from_mismatch', $result['reason']);
        self::assertSame(0, \BccTransitionWorld::$cacheBusts, 'nothing changed, so nothing to invalidate');
        self::assertSame([], \BccTransitionWorld::$audits);
    }

    /**
     * ⚠⚠⚠ THE INVARIANT THIS WHOLE FILE EXISTS FOR.
     *
     * Once the compare-and-swap has affected a row the switch has happened, and
     * no reason reported from that point may be one that means "nothing
     * changed". Enumerated rather than spot-checked, so a new post-write branch
     * cannot quietly reuse a pre-write reason.
     */
    public function testNoPostWriteOutcomeEverReportsThatNothingChanged(): void
    {
        $nothingChanged = [
            'from_mismatch', 'identity_changed', 'review_token_invalid',
            'review_chain_mismatch', 'review_consume_failed', 'lock_contended',
            'already_current', 'not_governed', 'chain_inactive', 'invalid_chain',
            'target_malformed', 'target_not_approved', 'target_unreachable',
            'target_identity_unreadable', 'target_network_mismatch', 'missing_input',
        ];

        $cases = [
            'clean'      => static function (): void {},
            'audit fails' => static function (): void {
                \BccTransitionWorld::$auditOk = false;
            },
            'read-back unavailable' => static function (): void {
                \BccTransitionWorld::$readBackAvailable = false;
            },
            'superseded' => static function (): void {
                \BccTransitionWorld::$readBackRestUrl = 'https://rest.cosmos.directory/cosmoshub';
            },
        ];

        foreach ($cases as $label => $arrange) {
            \BccTransitionWorld::reset();
            \BccTransitionWorld::seedChain8(self::INCUMBENT);
            $arrange();

            $result = $this->switchIt();

            self::assertSame(1, $this->endpointWrites(), "{$label}: precondition — the write landed");
            self::assertNotContains(
                $result['reason'],
                $nothingChanged,
                "{$label}: the endpoint changed, so '{$result['reason']}' must not be a no-change reason"
            );
            self::assertTrue($result['ok'], "{$label}: a completed write is not a failure");
        }
    }

    // ── The audit row ───────────────────────────────────────────────────

    public function testTheAuditCarriesHostsRolesAndNetworkAndNoCursorFields(): void
    {
        $this->switchIt();

        $meta = \BccTransitionWorld::$audits[0]['meta'];

        self::assertSame(self::TARGET, $meta['to']);
        self::assertSame(self::INCUMBENT, $meta['from']);
        self::assertSame('cosmoshub-4', $meta['verified_network']);
        self::assertArrayHasKey('endpoint_fp', $meta);
        self::assertArrayHasKey('to_role', $meta);
        self::assertSame(self::OPERATOR, $meta['actor']);

        // ⚠ RECORDED ON A CONFIRMED SWITCH. Design §7.8 #37 lists
        // `breaker_cleared` among the fields the row must carry, and a confirmed
        // switch always has an answer — false when there was nothing to clear.
        self::assertArrayHasKey('breaker_cleared', $meta);
        self::assertIsBool($meta['breaker_cleared'], 'a confirmed switch records a fact, not null');

        foreach (['cleared_families', 'code_cursor_cleared', 'watermark_kept'] as $gone) {
            self::assertArrayNotHasKey($gone, $meta, "the scanner field '{$gone}' must be gone");
        }
    }

    /** The fingerprint of the incumbent must never travel in the audit row. */
    public function testTheAuditNeverCarriesTheReviewFingerprint(): void
    {
        $fp = CosmosEndpointReview::fingerprint(self::INCUMBENT);
        $this->switchIt();

        $encoded = (string) json_encode(\BccTransitionWorld::$audits[0]['meta']);
        self::assertStringNotContainsString($fp, $encoded);
    }

    // ── Follow-up failures, singly and together ─────────────────

    /**
     * A breaker clear that THROWS is a failure (unlike a false return, which
     * merely means there was nothing to clear). It must be reported without
     * implying the endpoint did not move.
     */
    public function testABreakerExceptionIsReportedWithoutHidingTheWrite(): void
    {
        \BccBreakerStore::$deleteCounterThrows = true;

        $result = $this->switchIt();

        self::assertTrue($result['ok'], 'the endpoint DID change');
        self::assertSame('switched_followups_failed', $result['reason']);
        self::assertSame(['breaker'], $result['failed_followups']);
        self::assertSame(self::TARGET, $this->storedRestUrl());
        self::assertCount(1, \BccTransitionWorld::$audits, 'the write is still recorded');
    }

    /** Both failing names BOTH. Neither may hide the other. */
    public function testBreakerAndAuditFailingTogetherAreBothReported(): void
    {
        \BccBreakerStore::$deleteCounterThrows = true;
        \BccTransitionWorld::$auditOk = false;

        $result = $this->switchIt();

        self::assertTrue($result['ok']);
        self::assertSame('switched_followups_failed', $result['reason']);
        self::assertSame(
            ['breaker', 'audit'],
            $result['failed_followups'],
            'a combined failure must name both halves, in a stable order'
        );
        self::assertSame(self::TARGET, $this->storedRestUrl());
    }

    /**
     * ⚠ THE LOG IS THE TRACE WHEN THE DURABLE ROW IS NOT.
     *
     * An audit failure on a completed write must still leave an accurate
     * record somewhere, or a real endpoint change becomes invisible.
     */
    public function testAFailedAuditStillLeavesALogTraceOfTheWrite(): void
    {
        \BccTransitionWorld::$auditOk = false;

        $this->switchIt();

        $log = implode(' | ', \BccBreakerStore::$log);
        self::assertStringContainsString(
            'audit row was not written',
            $log,
            'a write that happened and was not recorded must say so in the log'
        );
    }

    /**
     * Read-back failure keeps an accurate trace AND names the uncertainty, so
     * a later reader is not told the chain is confirmably on the new host.
     */
    public function testAnUnconfirmedSwitchRecordsItsUncertaintyRatherThanAHappyOutcome(): void
    {
        \BccTransitionWorld::$readBackAvailable = false;

        $this->switchIt();

        self::assertCount(1, \BccTransitionWorld::$audits);
        $meta = \BccTransitionWorld::$audits[0]['meta'];
        self::assertSame('switched_unconfirmed', $meta['outcome']);
        self::assertSame(self::TARGET, $meta['to'], 'the trace names what was written');
        self::assertNull($meta['breaker_cleared'] ?? null, 'unconfirmed state is left alone');
    }

    // ── Replay totals (SEQUENTIAL — not a concurrency test) ──────────

    /**
     * ⚠ AT MOST ONE VERIFICATION AND ONE WRITE, however many arrivals.
     *
     * ⚠⚠ THE FOUR ARRIVALS BELOW ARE SEQUENTIAL, IN ONE PROCESS. Nothing
     * interleaves: each call returns before the next begins. So what is proved
     * here is REPLAY — a confirmation already spent buys nothing a second,
     * third or fourth time — and NOT that two racing sessions are serialised.
     *
     * The racing case is argued rather than executed, and the three parts of
     * the argument are named here so the gap stays visible:
     *   - the single-use review: `consume()` deletes before any outbound call,
     *     so a second arrival has nothing left to spend;
     *   - the lock, asserted above by name, acquisition and release against a
     *     fake — `lock_contended` is simulated by making the fake refuse, not
     *     by genuinely contending;
     *   - the CAS predicate, exercised against a real engine in
     *     `CosmosEndpointSwitchCasIntegrationTest`, which likewise presses
     *     twice in sequence rather than from two connections.
     *
     * A genuine concurrency test needs two sessions on two connections, so
     * that MySQL `GET_LOCK` actually contends. THAT IS NOT IN THIS SUITE.
     */
    public function testManyArrivalsWithOneReviewProduceOneVerificationAndOneWrite(): void
    {
        $reviewId = $this->review();

        $accepted = 0;
        for ($i = 0; $i < 4; $i++) {
            if ($this->switchIt($reviewId)['ok']) {
                $accepted++;
            }
        }

        self::assertSame(1, $accepted, 'exactly one arrival may succeed');
        self::assertSame(1, $this->endpointWrites(), 'and exactly one write may land');

        $verifications = 0;
        foreach (\BccTransitionWorld::$writes as $w) {
            if (str_starts_with($w, 'verify(')) {
                $verifications++;
            }
        }
        self::assertSame(1, $verifications, 'and the provider is contacted at most once');
    }

    /**
     * The replacement case, stated as a total rather than a single refusal: a
     * second review plus a stale confirmation must still yield one write.
     */
    public function testAReplacementReviewStillYieldsAtMostOneWrite(): void
    {
        $first  = $this->review();
        $second = $this->review();

        $this->switchIt($first);
        $this->switchIt($second);
        $this->switchIt($first);

        self::assertSame(1, $this->endpointWrites());
    }
}
