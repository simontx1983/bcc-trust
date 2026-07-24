<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Onchain\Services\DelegationEligibilityService;
use BCC\Trust\Onchain\Services\NftGroupGateService;
use BCC\Trust\Onchain\Services\ValidatorGroupGateService;
use BCC\Trust\Onchain\ValueObjects\DelegationVerdict;
use BCC\Trust\Onchain\ValueObjects\JoinResult;
use BCC\Trust\Onchain\ValueObjects\ValidatorGatedGroupConfig;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Delegator-community join gate — ValidatorGroupGateService.
 *
 * PeepSoGroupWriter::join is the TRUSTED-BACKEND DOOR: it writes
 * gm_user_status='member' unconditionally, bypassing PeepSo's own UI
 * approval. So this service's checks are the ONLY thing standing between
 * an arbitrary caller and a closed, gated community. Every case below
 * asserts on the writer call-log, not just the returned code — a gate that
 * returns the right error but still wrote membership is the failure mode
 * that matters.
 *
 * The load-bearing invariant is FAIL-CLOSED on UNKNOWN: an LCD outage must
 * never admit someone we cannot prove delegates (it is indistinguishable
 * from "sold everything" at the data layer, which is precisely why
 * DelegationVerdict has three outcomes instead of a nullable int).
 *
 * ## Isolation
 * Runs in its own subprocess; setUp() pulls in
 * tests/Stubs/validator-gate-stubs.php which fakes the repositories, the
 * PeepSo writer, and the two injected services at their FQNs.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ValidatorGroupGateTest extends TestCase
{
    private const USER_ID  = 7;
    private const GROUP_ID = 3200;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/validator-gate-stubs.php';
        $GLOBALS['__bcc_valgate_fixture'] = [
            'config'      => new ValidatorGatedGroupConfig(
                self::GROUP_ID,
                8,
                4242,
                'cosmosvaloper1fixture',
                1.0
            ),
            'opted_out'   => false,
            'is_member'   => false,
            'chain_type'  => 'cosmos',
            'verdict'     => null,
            'join_result' => true,
            'join_calls'  => [],
            'cleared'     => [],
        ];
    }

    private function service(): ValidatorGroupGateService
    {
        return new ValidatorGroupGateService(
            new NftGroupGateService(),
            new DelegationEligibilityService()
        );
    }

    /** @return list<array{0: int, 1: int}> */
    private static function joinCalls(): array
    {
        return $GLOBALS['__bcc_valgate_fixture']['join_calls'];
    }

    private static function assertNoMembershipWritten(): void
    {
        self::assertSame([], self::joinCalls(), 'the PeepSo writer must not run when the gate rejects');
    }

    public function testEligibleDelegatorJoins(): void
    {
        $GLOBALS['__bcc_valgate_fixture']['verdict'] = DelegationVerdict::eligible(1.0, 25.0);

        $result = $this->service()->joinIfEligible(self::USER_ID, self::GROUP_ID);

        self::assertTrue($result->success);
        self::assertSame(JoinResult::CODE_OK, $result->code);
        self::assertSame([[self::USER_ID, self::GROUP_ID]], self::joinCalls());
        // A successful join clears any stale opt-out so the buckets move
        // the group out of `opted_out`.
        self::assertSame([[self::USER_ID, self::GROUP_ID]], $GLOBALS['__bcc_valgate_fixture']['cleared']);
    }

    public function testUnknownVerdictFailsClosedAndWritesNothing(): void
    {
        // LCD outage: we cannot prove the delegation. Never admit.
        $GLOBALS['__bcc_valgate_fixture']['verdict'] = DelegationVerdict::unknown(1.0, null);

        $result = $this->service()->joinIfEligible(self::USER_ID, self::GROUP_ID);

        self::assertFalse($result->success);
        self::assertSame(JoinResult::CODE_VERIFY_UNAVAILABLE, $result->code);
        self::assertNoMembershipWritten();
    }

    public function testIneligibleDelegatorIsRejected(): void
    {
        $GLOBALS['__bcc_valgate_fixture']['verdict'] = DelegationVerdict::ineligible(1.0, 0.0);

        $result = $this->service()->joinIfEligible(self::USER_ID, self::GROUP_ID);

        self::assertFalse($result->success);
        self::assertSame(JoinResult::CODE_NOT_ELIGIBLE, $result->code);
        self::assertNoMembershipWritten();
    }

    public function testDustStakeBelowMinimumIsRejected(): void
    {
        // The min-stake gate exists to stop dust-delegation farming.
        $GLOBALS['__bcc_valgate_fixture']['config']  = new ValidatorGatedGroupConfig(
            self::GROUP_ID,
            8,
            4242,
            'cosmosvaloper1fixture',
            10.0
        );
        $GLOBALS['__bcc_valgate_fixture']['verdict'] = DelegationVerdict::ineligible(10.0, 0.000001);

        $result = $this->service()->joinIfEligible(self::USER_ID, self::GROUP_ID);

        self::assertFalse($result->success);
        self::assertSame(JoinResult::CODE_NOT_ELIGIBLE, $result->code);
        self::assertNoMembershipWritten();
    }

    public function testActiveOptOutBlocksJoinBeforeAnyEligibilityCheck(): void
    {
        $GLOBALS['__bcc_valgate_fixture']['opted_out'] = true;
        // Even a fully-eligible delegator stays out while the opt-out is
        // live — including the PERMANENT opt-out a mod eviction writes.
        $GLOBALS['__bcc_valgate_fixture']['verdict']   = DelegationVerdict::eligible(1.0, 99.0);

        $result = $this->service()->joinIfEligible(self::USER_ID, self::GROUP_ID);

        self::assertFalse($result->success);
        self::assertSame(JoinResult::CODE_OPT_OUT_ACTIVE, $result->code);
        self::assertNoMembershipWritten();
    }

    public function testAlreadyMemberIsIdempotentWithNoWrite(): void
    {
        $GLOBALS['__bcc_valgate_fixture']['is_member'] = true;
        $GLOBALS['__bcc_valgate_fixture']['verdict']   = DelegationVerdict::eligible(1.0, 25.0);

        $result = $this->service()->joinIfEligible(self::USER_ID, self::GROUP_ID);

        self::assertTrue($result->success);
        self::assertSame(JoinResult::CODE_ALREADY_MEMBER, $result->code);
        self::assertNoMembershipWritten();
    }

    public function testNonCosmosChainIsUnsupported(): void
    {
        // V1 cut: the gate needs a Cosmos LCD. An EVM-backed row must not
        // fall through to a join.
        $GLOBALS['__bcc_valgate_fixture']['chain_type'] = 'evm';
        $GLOBALS['__bcc_valgate_fixture']['verdict']    = DelegationVerdict::eligible(1.0, 25.0);

        $result = $this->service()->joinIfEligible(self::USER_ID, self::GROUP_ID);

        self::assertFalse($result->success);
        self::assertSame(JoinResult::CODE_CHAIN_UNSUPPORTED, $result->code);
        self::assertNoMembershipWritten();
    }

    public function testMissingChainRowIsUnsupported(): void
    {
        $GLOBALS['__bcc_valgate_fixture']['chain_type'] = null;
        $GLOBALS['__bcc_valgate_fixture']['verdict']    = DelegationVerdict::eligible(1.0, 25.0);

        $result = $this->service()->joinIfEligible(self::USER_ID, self::GROUP_ID);

        self::assertFalse($result->success);
        self::assertSame(JoinResult::CODE_CHAIN_UNSUPPORTED, $result->code);
        self::assertNoMembershipWritten();
    }

    public function testNonDelegatorGroupIsRejected(): void
    {
        $GLOBALS['__bcc_valgate_fixture']['config'] = null;

        $result = $this->service()->joinIfEligible(self::USER_ID, self::GROUP_ID);

        self::assertFalse($result->success);
        self::assertSame(JoinResult::CODE_NOT_A_HOLDER_GROUP, $result->code);
        self::assertNoMembershipWritten();
    }

    public function testBannedMembershipRefusalSurfacesAsTransientAndClearsNoOptOut(): void
    {
        // PeepSoGroupWriter::join returns false for a banned row (it
        // refuses to flip a group-level ban back to member). Nothing was
        // written, so nothing may be reported as joined — and the opt-out
        // must NOT be cleared.
        $GLOBALS['__bcc_valgate_fixture']['verdict']     = DelegationVerdict::eligible(1.0, 25.0);
        $GLOBALS['__bcc_valgate_fixture']['join_result'] = false;

        $result = $this->service()->joinIfEligible(self::USER_ID, self::GROUP_ID);

        self::assertFalse($result->success);
        self::assertSame(JoinResult::CODE_VERIFY_UNAVAILABLE, $result->code);
        self::assertSame([], $GLOBALS['__bcc_valgate_fixture']['cleared']);
    }

    public function testInvalidIdentifiersAreRejected(): void
    {
        self::assertFalse($this->service()->joinIfEligible(0, self::GROUP_ID)->success);
        self::assertFalse($this->service()->joinIfEligible(self::USER_ID, 0)->success);
        self::assertNoMembershipWritten();
    }
}
