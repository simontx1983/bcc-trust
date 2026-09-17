<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\HoldingsService;
use BCC\Trust\Onchain\Services\NftGroupGateService;
use BCC\Trust\Onchain\Services\NftGroupRevokeService;
use BCC\Trust\Onchain\ValueObjects\EligibilityVerdict;
use BCC\Trust\Onchain\ValueObjects\GatedGroupConfig;
use BCC\Trust\Onchain\ValueObjects\GateIdentity;
use BCC\Trust\Onchain\ValueObjects\JoinResult;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * PR 7.14 — a member is removed only on complete, trustworthy NOT_OWNED.
 *
 * ── THE TRUTH TABLE UNDER TEST ──────────────────────────────────────────
 *
 *   evidence                                   verdict      join   member
 *   some wallet proves ≥ min                   ELIGIBLE     allow  keep
 *   every wallet: complete read, below min     INELIGIBLE   deny   REVOKE
 *   anything else                              UNKNOWN      503    keep
 *
 * "Anything else" is the whole point of this suite: every case below is a
 * way the pre-PR code manufactured a confident zero out of evidence it never
 * finished collecting — a failed repository read, an inactive or missing
 * chain, a driver that cannot count, a capped or cached list, an index with
 * no history — and every one of them reached `PeepSoGroupWriter::leave()`.
 *
 * ── HOW TO READ A FAILURE ───────────────────────────────────────────────
 * The doubles record every provider call, every removal and every
 * transient write. Where a test asserts "nobody was removed" it also asserts
 * the verdict that caused it, so a green run cannot come from the sweep
 * simply not reaching the member.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NftRevocationFailSafeTest extends TestCase
{
    private const USER     = 501;
    private const OWNER    = 900;
    private const GROUP    = 7001;

    private const COSMOS_CHAIN    = 8;
    private const COSMOS_WALLET   = 'cosmos1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq';
    private const COSMOS_CONTRACT = 'cosmos12gsv9tmjhhg86wg9fnd9cnju28jx3fxva9cn8dh9meketkfxxajqmg3exz';

    private const EVM_CHAIN    = 1;
    private const EVM_WALLET   = '0x1111111111111111111111111111111111111111';
    private const EVM_CONTRACT = '0x2222222222222222222222222222222222222222';

    private const SOL_CHAIN      = 20;
    private const SOL_WALLET     = 'Egez6QLbs5fSLi6H2cgw22uDva7EWhnk4BSqGk7HcRxq';
    private const SOL_COLLECTION = 'FursjsPvEDmMgMr2jbR9foRAxQ2JsSSeByE6k1PPsdaQ';

    private const LINK = 4242;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/revocation-fail-safe-stubs.php';
        \BccRevokeWorld::reset();
        \BCC\Core\Log\Logger::reset();

        \BccRevokeWorld::seedChain(self::COSMOS_CHAIN, 'cosmos', 'cosmos');
        \BccRevokeWorld::seedChain(self::EVM_CHAIN, 'ethereum', 'evm');
        \BccRevokeWorld::seedChain(self::SOL_CHAIN, 'solana', 'solana');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function verdict(string $slug, string $contract, int $min = 1): EligibilityVerdict
    {
        return HoldingsService::eligibilityVerdict(self::USER, $slug, $contract, $min);
    }

    private function linkCosmos(int $link = self::LINK, string $address = self::COSMOS_WALLET): void
    {
        \BccRevokeWorld::linkWallet(self::USER, $link, self::COSMOS_CHAIN, $address);
    }

    private function answer(string $wallet, string $contract, string $kind, int $n = 0): void
    {
        \BccRevokeWorld::$answers[$wallet . '|' . $contract] = [$kind, $n];
    }

    private function assertUnknownBecause(string $reason, EligibilityVerdict $v): void
    {
        self::assertTrue($v->isUnknown(), 'expected UNKNOWN, got ' . $v->outcome);
        self::assertSame($reason, $v->reason);
    }

    /** @param array<string, mixed> $extra */
    private function cachedList(string $contract, array $extra): void
    {
        \BccRevokeWorld::$transients['bcc_holdings_w_' . self::LINK] = array_merge([
            'items'     => [['contract_address' => $contract, 'token_id' => '1']],
            'truncated' => false,
            'complete'  => true,
        ], $extra);
    }

    private function gate(string $slug, int $chainId, string $contract, int $min = 1, ?int $groupId = null): int
    {
        $gid = $groupId ?? self::GROUP;
        \BccRevokeWorld::$gateConfigs[$gid] = new GatedGroupConfig($gid, $chainId, $contract, $min, 55);
        $family = (string) \BccRevokeWorld::$chains[$chainId]->chain_type;
        \BccRevokeWorld::$identities[$gid] = GateIdentity::resolved($contract, $slug, $family, 55);

        return $gid;
    }

    /** @return object */
    private function member(int $userId, string $role = 'member')
    {
        return (object) ['user_id' => $userId, 'role' => $role];
    }

    private function fetchWalletHoldings(int $link, string $address, object $chain): ?array
    {
        $m = new \ReflectionMethod(HoldingsService::class, 'fetchWalletHoldings');
        $m->setAccessible(true);

        /** @var array<string, mixed>|null $r */
        $r = $m->invoke(null, $link, $address, $chain, false);

        return $r;
    }

    // ════════════════════════════════════════════════════════════════════
    // 1. Repository and configuration failures → UNKNOWN, never INELIGIBLE
    // ════════════════════════════════════════════════════════════════════

    public function testAWalletReadFailureIsUnknownNotIneligible(): void
    {
        $this->linkCosmos();
        \BccRevokeWorld::$failWalletRead = true;

        $this->assertUnknownBecause('repository_read_failed', $this->verdict('cosmos', self::COSMOS_CONTRACT));
        self::assertSame(0, \BccRevokeWorld::providerCalls());
    }

    public function testAChainReadFailureIsUnknownNotIneligible(): void
    {
        $this->linkCosmos();
        \BccRevokeWorld::$failChainRead = true;

        $this->assertUnknownBecause('chain_unavailable', $this->verdict('cosmos', self::COSMOS_CONTRACT));
    }

    public function testAMissingChainIsUnknownNotIneligible(): void
    {
        $this->linkCosmos();

        $this->assertUnknownBecause('chain_unavailable', $this->verdict('no-such-chain', self::COSMOS_CONTRACT));
    }

    public function testAnInactiveChainIsUnknownNotIneligible(): void
    {
        $this->linkCosmos();
        \BccRevokeWorld::$chains[self::COSMOS_CHAIN]->is_active = 0;

        $this->assertUnknownBecause('chain_unavailable', $this->verdict('cosmos', self::COSMOS_CONTRACT));
    }

    public function testAMissingDriverIsUnknownNotIneligible(): void
    {
        $this->linkCosmos();
        \BccRevokeWorld::$drivers['cosmos'] = false;

        $this->assertUnknownBecause('holdings_driver_unsupported', $this->verdict('cosmos', self::COSMOS_CONTRACT));
    }

    public function testADriverThatCannotCountHoldingsIsUnknownNotIneligible(): void
    {
        $this->linkCosmos();
        \BccRevokeWorld::$features['cosmos'] = ['validator'];

        $this->assertUnknownBecause('holdings_driver_unsupported', $this->verdict('cosmos', self::COSMOS_CONTRACT));
    }

    public function testATokenStandardReadFailureIsUnknown(): void
    {
        \BccRevokeWorld::linkWallet(self::USER, self::LINK, self::EVM_CHAIN, self::EVM_WALLET);
        \BccRevokeWorld::$failTokenStandardRead = true;
        $this->answer(self::EVM_WALLET, self::EVM_CONTRACT, 'exact', 0);

        $this->assertUnknownBecause('repository_read_failed', $this->verdict('ethereum', self::EVM_CONTRACT));
    }

    public function testAProviderExceptionIsUnknown(): void
    {
        $this->linkCosmos();
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'throw');

        $this->assertUnknownBecause('provider_unavailable', $this->verdict('cosmos', self::COSMOS_CONTRACT));
    }

    public function testAProviderThatCouldNotAnswerIsUnknown(): void
    {
        $this->linkCosmos();
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'unknown');

        $this->assertUnknownBecause('provider_unavailable', $this->verdict('cosmos', self::COSMOS_CONTRACT));
    }

    public function testASuccessfullyReadEmptyWalletListIsStillIneligible(): void
    {
        // Control: the member genuinely has no wallet on this chain. That is
        // a complete answer, not a failure — the gate cannot be satisfied.
        $v = $this->verdict('cosmos', self::COSMOS_CONTRACT);

        self::assertTrue($v->isIneligible());
        self::assertSame('', $v->reason);
    }

    public function testACompleteProviderZeroIsStillIneligible(): void
    {
        $this->linkCosmos();
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 0);

        $v = $this->verdict('cosmos', self::COSMOS_CONTRACT);

        self::assertTrue($v->isIneligible());
        self::assertSame(0, $v->bestKnownBalance);
    }

    public function testACompleteProviderPositiveIsEligible(): void
    {
        $this->linkCosmos();
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 2);

        self::assertTrue($this->verdict('cosmos', self::COSMOS_CONTRACT, 2)->isEligible());
    }

    public function testOneUnknownWalletBlocksIneligibleButOnePositiveWins(): void
    {
        $this->linkCosmos(self::LINK, self::COSMOS_WALLET);
        $other = 'cosmos1zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz';
        $this->linkCosmos(self::LINK + 1, $other);

        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 0);
        $this->answer($other, self::COSMOS_CONTRACT, 'unknown');
        self::assertTrue($this->verdict('cosmos', self::COSMOS_CONTRACT)->isUnknown(), 'a zero plus an unknown is not a complete zero');

        \BccRevokeWorld::reset();
        $this->setUp();
        $this->linkCosmos(self::LINK, self::COSMOS_WALLET);
        $this->linkCosmos(self::LINK + 1, $other);
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'unknown');
        $this->answer($other, self::COSMOS_CONTRACT, 'exact', 1);
        self::assertTrue($this->verdict('cosmos', self::COSMOS_CONTRACT)->isEligible(), 'proof in one wallet settles it');
    }

    // ════════════════════════════════════════════════════════════════════
    // 2. Incomplete evidence never proves a shortfall
    // ════════════════════════════════════════════════════════════════════

    public function testACappedCountBelowTheThresholdIsUnknown(): void
    {
        \BccRevokeWorld::linkWallet(self::USER, self::LINK, self::SOL_CHAIN, self::SOL_WALLET);
        $this->answer(self::SOL_WALLET, self::SOL_COLLECTION, 'atLeast', 0);

        $this->assertUnknownBecause('evidence_incomplete', $this->verdict('solana', self::SOL_COLLECTION));
    }

    public function testACappedCountAboveTheThresholdStillProvesOwnership(): void
    {
        \BccRevokeWorld::linkWallet(self::USER, self::LINK, self::SOL_CHAIN, self::SOL_WALLET);
        $this->answer(self::SOL_WALLET, self::SOL_COLLECTION, 'atLeast', 3);

        self::assertTrue($this->verdict('solana', self::SOL_COLLECTION, 2)->isEligible());
    }

    public function testACappedCountBetweenOneAndTheThresholdIsUnknown(): void
    {
        \BccRevokeWorld::linkWallet(self::USER, self::LINK, self::SOL_CHAIN, self::SOL_WALLET);
        $this->answer(self::SOL_WALLET, self::SOL_COLLECTION, 'atLeast', 1);

        $this->assertUnknownBecause('evidence_incomplete', $this->verdict('solana', self::SOL_COLLECTION, 2));
    }

    public function testAnIntegerFromADriverThatNeverVouchedForCompletenessCannotProveZero(): void
    {
        \BccRevokeWorld::$legacyFetcher = true;
        $this->linkCosmos();
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 0);

        $this->assertUnknownBecause('evidence_incomplete', $this->verdict('cosmos', self::COSMOS_CONTRACT));
    }

    public function testAPositiveIntegerFromSuchADriverStillProvesOwnership(): void
    {
        \BccRevokeWorld::$legacyFetcher = true;
        $this->linkCosmos();
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 2);

        self::assertTrue($this->verdict('cosmos', self::COSMOS_CONTRACT)->isEligible());
    }

    // ── EVM ERC-721: the picker's empty "complete" list ──────────────────

    public function testADriverWithoutAHoldingsListNeverCachesACompleteEmptyWalk(): void
    {
        $walk = $this->fetchWalletHoldings(self::LINK, self::EVM_WALLET, \BccRevokeWorld::$chains[self::EVM_CHAIN]);

        self::assertIsArray($walk);
        self::assertSame([], $walk['items']);
        self::assertFalse($walk['complete'], 'a driver that cannot enumerate has not read an empty wallet');
        self::assertNotContains('bcc_holdings_w_' . self::LINK, \BccRevokeWorld::$transientWrites, 'nothing may be cached');
    }

    public function testAnEmptyCachedListNeverStopsTheDirectBalanceOfCheck(): void
    {
        // Exactly what the pre-PR picker wrote for every EVM wallet.
        \BccRevokeWorld::linkWallet(self::USER, self::LINK, self::EVM_CHAIN, self::EVM_WALLET);
        \BccRevokeWorld::$transients['bcc_holdings_w_' . self::LINK] = ['items' => [], 'truncated' => false, 'complete' => true];
        $this->answer(self::EVM_WALLET, self::EVM_CONTRACT, 'exact', 1);

        self::assertTrue($this->verdict('ethereum', self::EVM_CONTRACT)->isEligible());
        self::assertContains('count_holdings_evidence:' . self::EVM_WALLET . '|' . self::EVM_CONTRACT, \BccRevokeWorld::$fetcherCalls);
    }

    public function testADecisiveDirectZeroStillRevokesAfterAnEmptyCache(): void
    {
        \BccRevokeWorld::linkWallet(self::USER, self::LINK, self::EVM_CHAIN, self::EVM_WALLET);
        \BccRevokeWorld::$transients['bcc_holdings_w_' . self::LINK] = ['items' => [], 'truncated' => false, 'complete' => true];
        $this->answer(self::EVM_WALLET, self::EVM_CONTRACT, 'exact', 0);

        self::assertTrue($this->verdict('ethereum', self::EVM_CONTRACT)->isIneligible());
    }

    public function testACompleteCachedListOutsideItsCoverageCannotDenyACosmosHolder(): void
    {
        // A Cosmos walk only covers the top verified collections. The target
        // is not in it; the chain says the wallet holds one.
        $this->linkCosmos();
        \BccRevokeWorld::$transients['bcc_holdings_w_' . self::LINK] = [
            'items'             => [['contract_address' => 'cosmos1somethingelse', 'token_id' => '9']],
            'truncated'         => false,
            'complete'          => true,
            'observed_at'       => time(),
            'served_from_cache' => false,
        ];
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 1);

        self::assertTrue($this->verdict('cosmos', self::COSMOS_CONTRACT)->isEligible());
    }

    // ── Safeguard 1: cached positive evidence has a bounded lifetime ─────

    public function testAFreshCachedPositiveNeedsNoProviderCall(): void
    {
        $this->linkCosmos();
        $this->cachedList(self::COSMOS_CONTRACT, ['observed_at' => time() - 3600, 'served_from_cache' => false]);

        self::assertTrue($this->verdict('cosmos', self::COSMOS_CONTRACT)->isEligible());
        self::assertSame(0, \BccRevokeWorld::providerCalls(), 'a recent, first-hand positive is enough');
    }

    public function testACachedPositiveOlderThanADayIsReVerified(): void
    {
        $this->linkCosmos();
        $this->cachedList(self::COSMOS_CONTRACT, ['observed_at' => time() - 86401, 'served_from_cache' => false]);
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 0);

        self::assertTrue($this->verdict('cosmos', self::COSMOS_CONTRACT)->isIneligible(), 'the NFT was sold; a day-old positive must not keep it');
        self::assertSame(1, \BccRevokeWorld::providerCalls());
    }

    public function testACachedPositiveWithNoObservationTimeIsReVerified(): void
    {
        $this->linkCosmos();
        $this->cachedList(self::COSMOS_CONTRACT, []); // a pre-PR payload
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 0);

        self::assertTrue($this->verdict('cosmos', self::COSMOS_CONTRACT)->isIneligible());
        self::assertSame(1, \BccRevokeWorld::providerCalls());
    }

    public function testACachedPositiveBuiltFromCachedPagesIsReVerified(): void
    {
        $this->linkCosmos();
        $this->cachedList(self::COSMOS_CONTRACT, ['observed_at' => time() - 60, 'served_from_cache' => true]);
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 0);

        self::assertTrue($this->verdict('cosmos', self::COSMOS_CONTRACT)->isIneligible(), 'its pages could already have been a day old');
        self::assertSame(1, \BccRevokeWorld::providerCalls());
    }

    // ── EVM ERC-1155: the transfer index has no history ──────────────────

    private function erc1155(int $link = self::LINK): void
    {
        \BccRevokeWorld::linkWallet(self::USER, $link, self::EVM_CHAIN, self::EVM_WALLET);
        \BccRevokeWorld::$tokenStandards[self::EVM_CHAIN . '|' . self::EVM_CONTRACT] = 'ERC-1155';
    }

    private function checkpoint(string $state, int $ageSeconds): void
    {
        \BccRevokeWorld::$checkpoints[self::EVM_CHAIN] = (object) [
            'state'       => $state,
            'last_run_at' => gmdate('Y-m-d H:i:s', time() - $ageSeconds),
        ];
    }

    public function testAnAbsentIndexRowIsNotProofOfNonOwnership(): void
    {
        // The wallet held the token before it was linked; the index began at
        // chain head and has no backfill. Absence proves nothing.
        $this->erc1155();
        $this->checkpoint('healthy', 60);

        $this->assertUnknownBecause('evidence_incomplete', $this->verdict('ethereum', self::EVM_CONTRACT));
    }

    public function testARelinkedWalletDoesNotInheritAConfidentZero(): void
    {
        // Unlink deleted the old link's rows; relink minted a new id.
        $this->erc1155(self::LINK + 1);
        \BccRevokeWorld::$index = [self::LINK => 3];
        $this->checkpoint('healthy', 60);

        $this->assertUnknownBecause('evidence_incomplete', $this->verdict('ethereum', self::EVM_CONTRACT));
    }

    public function testAnIndexReadFailureIsUnknown(): void
    {
        $this->erc1155();
        $this->checkpoint('healthy', 60);
        \BccRevokeWorld::$failIndexRead = true;

        $this->assertUnknownBecause('repository_read_failed', $this->verdict('ethereum', self::EVM_CONTRACT));
    }

    public function testFreshPositiveIndexEvidenceProvesOwnership(): void
    {
        $this->erc1155();
        \BccRevokeWorld::$index = [self::LINK => 2];
        $this->checkpoint('healthy', 60);

        self::assertTrue($this->verdict('ethereum', self::EVM_CONTRACT)->isEligible());
    }

    public function testPositiveIndexEvidenceFromAStaleIndexerIsUnknown(): void
    {
        $this->erc1155();
        \BccRevokeWorld::$index = [self::LINK => 2];
        $this->checkpoint('healthy', 2 * 86400);

        $this->assertUnknownBecause('evidence_stale', $this->verdict('ethereum', self::EVM_CONTRACT));
    }

    public function testPositiveIndexEvidenceFromADisabledIndexerIsUnknown(): void
    {
        $this->erc1155();
        \BccRevokeWorld::$index = [self::LINK => 2];
        $this->checkpoint('disabled', 60);

        $this->assertUnknownBecause('evidence_stale', $this->verdict('ethereum', self::EVM_CONTRACT));
    }

    // ════════════════════════════════════════════════════════════════════
    // 3. Join
    // ════════════════════════════════════════════════════════════════════

    public function testJoinDuringAWalletReadFailureIsTemporarilyUnavailable(): void
    {
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->linkCosmos();
        \BccRevokeWorld::$failWalletRead = true;

        $result = (new NftGroupGateService())->joinIfEligible(self::USER, self::GROUP);

        self::assertSame(JoinResult::CODE_VERIFY_UNAVAILABLE, $result->code);
        self::assertSame([], \BccRevokeWorld::$joins);
    }

    public function testJoinOnAnInactiveChainIsTemporarilyUnavailable(): void
    {
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->linkCosmos();
        \BccRevokeWorld::$chains[self::COSMOS_CHAIN]->is_active = 0;

        self::assertSame(
            JoinResult::CODE_VERIFY_UNAVAILABLE,
            (new NftGroupGateService())->joinIfEligible(self::USER, self::GROUP)->code
        );
    }

    public function testJoinWithACompleteZeroIsDenied(): void
    {
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->linkCosmos();
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 0);

        self::assertSame(JoinResult::CODE_NOT_ELIGIBLE, (new NftGroupGateService())->joinIfEligible(self::USER, self::GROUP)->code);
        self::assertSame([], \BccRevokeWorld::$joins);
    }

    public function testJoinWithProofSucceeds(): void
    {
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->linkCosmos();
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 1);

        self::assertSame(JoinResult::CODE_OK, (new NftGroupGateService())->joinIfEligible(self::USER, self::GROUP)->code);
        self::assertSame([self::GROUP . ':' . self::USER], \BccRevokeWorld::$joins);
    }

    // ════════════════════════════════════════════════════════════════════
    // 4. Revoke sweep
    // ════════════════════════════════════════════════════════════════════

    private function seedMembersWithCompleteZeros(int $count, int $firstUserId = 10_000): void
    {
        $rows = [$this->member(self::OWNER, 'member_owner')];
        for ($i = 0; $i < $count; $i++) {
            $uid = $firstUserId + $i;
            $address = 'cosmos1' . str_pad((string) $uid, 38, 'q', STR_PAD_LEFT);
            \BccRevokeWorld::linkWallet($uid, 50_000 + $i, self::COSMOS_CHAIN, $address);
            $this->answer($address, self::COSMOS_CONTRACT, 'exact', 0);
            $rows[] = $this->member($uid);
        }
        \BccRevokeWorld::$members[self::GROUP] = $rows;
    }

    public function testTheSweepNeverRemovesAMemberOnAWalletReadFailure(): void
    {
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->linkCosmos();
        \BccRevokeWorld::$members[self::GROUP] = [$this->member(self::OWNER, 'member_owner'), $this->member(self::USER)];
        \BccRevokeWorld::$failWalletRead = true;

        $stats = (new NftGroupRevokeService())->sweep();

        self::assertSame([], \BccRevokeWorld::$leaves, 'a failed read must never remove anyone');
        self::assertSame(0, $stats['revoked']);
        self::assertSame(1, $stats['skipped_unknown']);
    }

    public function testTheSweepNeverRemovesAMemberOfAnInactiveChainsGroup(): void
    {
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->linkCosmos();
        \BccRevokeWorld::$members[self::GROUP] = [$this->member(self::USER)];
        \BccRevokeWorld::$chains[self::COSMOS_CHAIN]->is_active = 0;

        (new NftGroupRevokeService())->sweep();

        self::assertSame([], \BccRevokeWorld::$leaves);
    }

    public function testTheSweepStillRemovesACompleteZero(): void
    {
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->linkCosmos();
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 0);
        \BccRevokeWorld::$members[self::GROUP] = [$this->member(self::OWNER, 'member_owner'), $this->member(self::USER)];

        $stats = (new NftGroupRevokeService())->sweep();

        self::assertSame([self::GROUP . ':' . self::USER], \BccRevokeWorld::$leaves);
        self::assertSame(1, $stats['revoked']);
    }

    public function testTheSweepKeepsAProvenHolder(): void
    {
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->linkCosmos();
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 1);
        \BccRevokeWorld::$members[self::GROUP] = [$this->member(self::OWNER, 'member_owner'), $this->member(self::USER)];

        $stats = (new NftGroupRevokeService())->sweep();

        self::assertSame([], \BccRevokeWorld::$leaves, 'a holder is never removed');
        self::assertSame(1, $stats['checked']);
        self::assertSame(0, $stats['revoked']);
        self::assertSame(0, $stats['skipped_unknown']);
    }

    public function testAMemberTooExpensiveForAWholeTickCannotStallTheRotation(): void
    {
        // One read costs more than a whole tick. Stopping the tick on that
        // member and resuming on it next time would repeat forever and no
        // one after them would ever be checked.
        \BccRevokeWorld::$maxRequestsPerRead = 301;
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->seedMembersWithCompleteZeros(2);

        $first  = (new NftGroupRevokeService())->sweep();
        $second = (new NftGroupRevokeService())->sweep();

        self::assertSame(['verification_budget_exhausted' => 1], $first['skipped_reasons']);
        self::assertTrue($first['stopped_on_budget'], 'the second member did not fit after the first was tried');
        self::assertSame(['verification_budget_exhausted' => 1], $second['skipped_reasons'], 'the next tick moved on to the second member');
        self::assertSame(0, \BccRevokeWorld::providerCalls(), 'no read that could not fit was attempted');
        self::assertSame([], \BccRevokeWorld::$leaves);
    }

    public function testTheSweepNeverRemovesAnOwnerEvenOnACompleteZero(): void
    {
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        \BccRevokeWorld::linkWallet(self::OWNER, 777, self::COSMOS_CHAIN, self::COSMOS_WALLET);
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 0);
        \BccRevokeWorld::$members[self::GROUP] = [$this->member(self::OWNER, 'member_owner')];

        (new NftGroupRevokeService())->sweep();

        self::assertSame([], \BccRevokeWorld::$leaves);
        self::assertSame(0, \BccRevokeWorld::providerCalls(), 'owners are not even evaluated');
    }

    public function testTheSweepSkipsAGroupWhoseIdentityIsUnresolved(): void
    {
        \BccRevokeWorld::$gateConfigs[self::GROUP] = new GatedGroupConfig(self::GROUP, self::COSMOS_CHAIN, 'SYMBOL', 1, 55);
        \BccRevokeWorld::$members[self::GROUP] = [$this->member(self::USER)];
        $this->linkCosmos();

        (new NftGroupRevokeService())->sweep();

        self::assertSame([], \BccRevokeWorld::$leaves);
        self::assertSame(0, \BccRevokeWorld::providerCalls());
    }

    public function testOneSweepProcessesEveryMemberExactlyOnceAcrossPages(): void
    {
        // 250 non-owners, all complete zeros, three pages of 100. Pre-PR, each
        // removal shifted the list under the OFFSET and half were never seen.
        \BccRevokeWorld::$filters['bcc_gated_group_revoke_batch_size'] = 1000;
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->seedMembersWithCompleteZeros(250);

        $stats = (new NftGroupRevokeService())->sweep();

        self::assertSame(250, $stats['checked']);
        self::assertSame(250, $stats['revoked']);
        self::assertCount(250, \BccRevokeWorld::$leaves);
        self::assertCount(250, array_unique(\BccRevokeWorld::$leaves), 'nobody removed twice');
        self::assertEquals([$this->member(self::OWNER, 'member_owner')], \BccRevokeWorld::$members[self::GROUP], 'only the owner remains');
    }

    public function testKeptMembersBetweenRemovalsAreNeitherSkippedNorRevisited(): void
    {
        // Alternate: remove, keep (unknown), remove, keep… across pages.
        \BccRevokeWorld::$filters['bcc_gated_group_revoke_batch_size'] = 1000;
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->seedMembersWithCompleteZeros(230);
        foreach (\BccRevokeWorld::$members[self::GROUP] as $i => $row) {
            if ($i > 0 && $i % 2 === 0) {
                $address = 'cosmos1' . str_pad((string) $row->user_id, 38, 'q', STR_PAD_LEFT);
                $this->answer($address, self::COSMOS_CONTRACT, 'unknown');
            }
        }

        $stats = (new NftGroupRevokeService())->sweep();

        self::assertSame(230, $stats['checked'], 'every non-owner evaluated exactly once');
        self::assertSame(115, $stats['revoked']);
        self::assertSame(115, $stats['skipped_unknown']);
        $evaluated = array_filter(\BccRevokeWorld::$fetcherCalls, static fn(string $c): bool => str_starts_with($c, 'count_holdings_evidence:'));
        self::assertCount(230, array_unique($evaluated));
        self::assertCount(230, $evaluated, 'nobody evaluated twice');
    }

    // ── Safeguard 2: every surface has a hard provider budget ────────────

    public function testTheSweepStopsAtItsBudgetAndResumesOnTheNextUnevaluatedMember(): void
    {
        // 300 units per tick, 100 per read → three evaluations per tick.
        \BccRevokeWorld::$maxRequestsPerRead = 100;
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->seedMembersWithCompleteZeros(5);

        $first = (new NftGroupRevokeService())->sweep();
        self::assertSame(3, \BccRevokeWorld::providerCalls(), 'the budget bounds provider reads per tick');
        self::assertSame(3, $first['revoked']);

        \BccRevokeWorld::$fetcherCalls = [];
        $second = (new NftGroupRevokeService())->sweep();
        self::assertSame(2, $second['revoked'], 'the next tick picks up exactly where the budget stopped');
        self::assertCount(5, array_unique(\BccRevokeWorld::$leaves));
    }

    public function testJoinStopsAtItsBudgetAndFailsClosed(): void
    {
        // 30 units, 10 per read → at most three wallets are asked.
        \BccRevokeWorld::$maxRequestsPerRead = 10;
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        for ($i = 0; $i < 6; $i++) {
            $address = 'cosmos1' . str_pad((string) $i, 38, 'w', STR_PAD_LEFT);
            \BccRevokeWorld::linkWallet(self::USER, self::LINK + $i, self::COSMOS_CHAIN, $address);
            $this->answer($address, self::COSMOS_CONTRACT, 'exact', 0);
        }

        $result = (new NftGroupGateService())->joinIfEligible(self::USER, self::GROUP);

        self::assertLessThanOrEqual(3, \BccRevokeWorld::providerCalls());
        self::assertSame(JoinResult::CODE_VERIFY_UNAVAILABLE, $result->code, 'an unfinished check is not a denial');
        self::assertSame([], \BccRevokeWorld::$joins);
    }

    public function testEligibleGroupDiscoveryIsBudgeted(): void
    {
        // Reconcile budget: 60 units, 10 per read → at most six reads.
        \BccRevokeWorld::$maxRequestsPerRead = 10;
        $this->linkCosmos();
        for ($g = 0; $g < 20; $g++) {
            $contract = 'cosmos1' . str_pad((string) $g, 58, 'k', STR_PAD_LEFT);
            $this->gate('cosmos', self::COSMOS_CHAIN, $contract, 1, 8000 + $g);
            $this->answer(self::COSMOS_WALLET, $contract, 'exact', 0);
        }

        $eligible = (new NftGroupGateService())->findEligibleGroups(self::USER);

        self::assertSame([], $eligible);
        self::assertLessThanOrEqual(6, \BccRevokeWorld::providerCalls());
    }

    public function testOwnsAnyManyHonoursACallerSuppliedBudget(): void
    {
        \BccRevokeWorld::$maxRequestsPerRead = 4;
        $this->linkCosmos();
        $pairs = [];
        for ($g = 0; $g < 10; $g++) {
            $contract = 'cosmos1' . str_pad((string) $g, 58, 'm', STR_PAD_LEFT);
            $pairs[] = ['cosmos', $contract];
            $this->answer(self::COSMOS_WALLET, $contract, 'exact', 0);
        }

        $balances = HoldingsService::ownsAnyMany(self::USER, $pairs, new \BCC\Trust\Onchain\Support\CosmwasmTickBudget(8, 30));

        self::assertLessThanOrEqual(2, \BccRevokeWorld::providerCalls());
        $unknown = array_filter($balances, static fn(?int $b): bool => $b === null);
        self::assertGreaterThanOrEqual(8, count($unknown), 'pairs the budget could not reach are unknown, not zero');
    }

    // ════════════════════════════════════════════════════════════════════
    // 5. Join fair continuation — no holder starves behind the budget
    // ════════════════════════════════════════════════════════════════════
    //
    // There is no per-chain wallet cap, and the join budget reads a bounded
    // number of wallets (30 at one unit, 7 at Cosmos's four, 3 at Solana's
    // ten). Each budget-exhausted attempt stores where it stopped; the next
    // attempt by the same user on the same gate starts there.

    private function linkEvmWallets(int $count): array
    {
        $addresses = [];
        for ($i = 0; $i < $count; $i++) {
            $address = '0x' . str_pad(dechex($i + 1), 40, '0', STR_PAD_LEFT);
            \BccRevokeWorld::linkWallet(self::USER, self::LINK + $i, self::EVM_CHAIN, $address);
            $this->answer($address, self::EVM_CONTRACT, 'exact', 0);
            $addresses[] = $address;
        }

        return $addresses;
    }

    /** @return list<string> wallet addresses asked about, in order */
    private function askedWallets(): array
    {
        $out = [];
        foreach (\BccRevokeWorld::$fetcherCalls as $call) {
            if (str_starts_with($call, 'count_holdings_evidence:')) {
                $out[] = explode('|', substr($call, strlen('count_holdings_evidence:')))[0];
            }
        }

        return $out;
    }

    public function testAQualifyingWalletPastTheJoinBudgetIsReachedByARetry(): void
    {
        // 40 wallets at one unit each; only wallet 31 holds the token.
        $this->gate('ethereum', self::EVM_CHAIN, self::EVM_CONTRACT);
        $wallets = $this->linkEvmWallets(40);
        $this->answer($wallets[30], self::EVM_CONTRACT, 'exact', 1);

        $first = (new NftGroupGateService())->joinIfEligible(self::USER, self::GROUP);

        self::assertSame(JoinResult::CODE_VERIFY_UNAVAILABLE, $first->code, 'the budget ran out: 503, never 403');
        self::assertSame(array_slice($wallets, 0, 30), $this->askedWallets());
        self::assertSame([], \BccRevokeWorld::$joins);

        \BccRevokeWorld::$fetcherCalls = [];
        $second = (new NftGroupGateService())->joinIfEligible(self::USER, self::GROUP);

        self::assertSame(JoinResult::CODE_OK, $second->code);
        self::assertSame([$wallets[30]], $this->askedWallets(), 'the retry started with wallet 31');
        self::assertSame([self::GROUP . ':' . self::USER], \BccRevokeWorld::$joins);
        self::assertSame(
            [],
            \BccRevokeWorld::$userMeta[self::USER]['_bcc_ownership_wallet_rotation'] ?? [],
            'a decided check clears its continuation'
        );
    }

    public function testRepeatedJoinAttemptsReadEveryWalletAndNeverDeny(): void
    {
        $this->gate('ethereum', self::EVM_CHAIN, self::EVM_CONTRACT);
        $wallets = $this->linkEvmWallets(70);

        $asked = [];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            \BccRevokeWorld::$fetcherCalls = [];
            $result = (new NftGroupGateService())->joinIfEligible(self::USER, self::GROUP);

            self::assertSame(JoinResult::CODE_VERIFY_UNAVAILABLE, $result->code, "attempt {$attempt}: not every wallet was read in one request, so no denial");
            self::assertCount(30, $this->askedWallets(), "attempt {$attempt} is still bounded by the budget");
            $asked = array_merge($asked, $this->askedWallets());
        }

        self::assertSame($wallets, array_slice($asked, 0, 70), 'three attempts read wallets 1–70 in order, none twice');
        self::assertSame(array_slice($wallets, 0, 20), array_slice($asked, 70), 'then the rotation wrapped to the first wallet');
        self::assertSame([], \BccRevokeWorld::$joins);
    }

    public function testJoinContinuationAtCosmosCostReachesALateWallet(): void
    {
        // Four units per read: seven wallets per attempt. Wallet 9 holds it.
        \BccRevokeWorld::$maxRequestsPerRead = 4;
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $wallets = [];
        for ($i = 0; $i < 10; $i++) {
            $address = 'cosmos1' . str_pad((string) $i, 38, 'j', STR_PAD_LEFT);
            \BccRevokeWorld::linkWallet(self::USER, self::LINK + $i, self::COSMOS_CHAIN, $address);
            $this->answer($address, self::COSMOS_CONTRACT, 'exact', $i === 8 ? 1 : 0);
            $wallets[] = $address;
        }

        self::assertSame(JoinResult::CODE_VERIFY_UNAVAILABLE, (new NftGroupGateService())->joinIfEligible(self::USER, self::GROUP)->code);
        self::assertSame(array_slice($wallets, 0, 7), $this->askedWallets());

        \BccRevokeWorld::$fetcherCalls = [];
        self::assertSame(JoinResult::CODE_OK, (new NftGroupGateService())->joinIfEligible(self::USER, self::GROUP)->code);
        self::assertSame([$wallets[7], $wallets[8]], $this->askedWallets());
    }

    public function testAnExpiredContinuationStartsFromTheFirstWalletAgain(): void
    {
        $this->gate('ethereum', self::EVM_CHAIN, self::EVM_CONTRACT);
        $wallets = $this->linkEvmWallets(40);
        $key = self::EVM_CHAIN . ':' . sha1(self::EVM_CONTRACT);
        \BccRevokeWorld::$userMeta[self::USER]['_bcc_ownership_wallet_rotation'] = [
            $key => ['offset' => 25, 'at' => time() - 8 * 86400],
        ];

        (new NftGroupGateService())->joinIfEligible(self::USER, self::GROUP);

        self::assertSame($wallets[0], $this->askedWallets()[0]);
    }

    public function testTheSweepNeverReadsOrWritesJoinContinuation(): void
    {
        \BccRevokeWorld::$maxRequestsPerRead = 100;
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->seedMembersWithCompleteZeros(5);

        $stats = (new NftGroupRevokeService())->sweep();

        self::assertTrue($stats['stopped_on_budget']);
        self::assertSame([], \BccRevokeWorld::$userMetaWrites, 'a cron sweep must not write members\' user meta');
    }

    // ════════════════════════════════════════════════════════════════════
    // 6. The sweep reaches every member across ticks
    // ════════════════════════════════════════════════════════════════════

    public function testSuccessiveTicksReachEveryMemberBeforeRevisitingAny(): void
    {
        // 2 groups × 120 members at 4 units = 960 units against 300 per tick,
        // so 75 checks per tick. Every fifth member is a complete zero
        // (removed), the rest are proven holders (kept).
        \BccRevokeWorld::$maxRequestsPerRead = 4;
        $expected = [];
        foreach ([8101, 8102] as $g => $gid) {
            $contract = 'cosmos1' . str_pad((string) $gid, 58, 't', STR_PAD_LEFT);
            $this->gate('cosmos', self::COSMOS_CHAIN, $contract, 1, $gid);
            $rows = [$this->member(self::OWNER, 'member_owner')];
            for ($i = 0; $i < 120; $i++) {
                $uid     = 20_000 + $g * 1000 + $i;
                $address = 'cosmos1' . str_pad((string) $uid, 38, 'x', STR_PAD_LEFT);
                \BccRevokeWorld::linkWallet($uid, 60_000 + $g * 1000 + $i, self::COSMOS_CHAIN, $address);
                $this->answer($address, $contract, 'exact', $i % 5 === 0 ? 0 : 1);
                $rows[]     = $this->member($uid);
                $expected[] = $address . '|' . $contract;
            }
            \BccRevokeWorld::$members[$gid] = $rows;
        }

        $sequence = [];
        for ($tick = 1; $tick <= 5; $tick++) {
            \BccRevokeWorld::$fetcherCalls = [];
            (new NftGroupRevokeService())->sweep();
            $calls = array_values(array_filter(
                \BccRevokeWorld::$fetcherCalls,
                static fn(string $c): bool => str_starts_with($c, 'count_holdings_evidence:')
            ));
            self::assertLessThanOrEqual(75, count($calls), "tick {$tick} stayed inside its budget");
            foreach ($calls as $call) {
                $sequence[] = substr($call, strlen('count_holdings_evidence:'));
            }
        }

        $firstRepeat = count($sequence);
        $seen = [];
        foreach ($sequence as $i => $pair) {
            if (isset($seen[$pair])) {
                $firstRepeat = $i;
                break;
            }
            $seen[$pair] = true;
        }

        self::assertSame($expected, array_slice($sequence, 0, $firstRepeat), 'every member of both groups, in order, before anyone was checked twice');
        self::assertCount(48, \BccRevokeWorld::$leaves, 'exactly the 48 complete zeros were removed');
        self::assertSame(
            192,
            count(\BccRevokeWorld::$members[8101]) + count(\BccRevokeWorld::$members[8102]) - 2,
            'the 192 proven holders are all still members'
        );
    }

    // ════════════════════════════════════════════════════════════════════
    // 7. The stance panel's stored rows never decide membership
    // ════════════════════════════════════════════════════════════════════
    //
    // `NftHoldingsRepository::findVisibleForWallet` rows have no freshness
    // bound (an indexer that stops leaves them in place). They back the stance
    // panel and the stance write gate only.

    private function storeStaleRow(): void
    {
        \BccRevokeWorld::$storedRows[self::LINK] = [
            (object) ['contract_address' => self::COSMOS_CONTRACT, 'token_id' => '1', 'collection_name' => 'Sold long ago', 'image_url' => null],
        ];
    }

    public function testAStoredHoldingsRowNeverSatisfiesAJoin(): void
    {
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->linkCosmos();
        $this->storeStaleRow();
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 0);

        self::assertSame(JoinResult::CODE_NOT_ELIGIBLE, (new NftGroupGateService())->joinIfEligible(self::USER, self::GROUP)->code);
        self::assertSame([], \BccRevokeWorld::$joins);
        self::assertSame([], \BccRevokeWorld::$storedReads, 'the join never looked at stored rows');
    }

    public function testAStoredHoldingsRowNeverKeepsAMember(): void
    {
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->linkCosmos();
        $this->storeStaleRow();
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 0);
        \BccRevokeWorld::$members[self::GROUP] = [$this->member(self::OWNER, 'member_owner'), $this->member(self::USER)];

        $stats = (new NftGroupRevokeService())->sweep();

        self::assertSame(1, $stats['revoked'], 'the stale stored row did not preserve the membership');
        self::assertSame([], \BccRevokeWorld::$storedReads);
    }

    public function testAStoredHoldingsRowNeverMakesAGroupEligibleForAutoJoin(): void
    {
        $this->gate('cosmos', self::COSMOS_CHAIN, self::COSMOS_CONTRACT);
        $this->linkCosmos();
        $this->storeStaleRow();
        $this->answer(self::COSMOS_WALLET, self::COSMOS_CONTRACT, 'exact', 0);

        self::assertSame([], (new NftGroupGateService())->findEligibleGroups(self::USER));
        self::assertSame([], \BccRevokeWorld::$storedReads);
    }
}
