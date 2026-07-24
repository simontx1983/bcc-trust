<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Onchain\Services\DelegationEligibilityService;
use BCC\Trust\Onchain\ValueObjects\ValidatorGatedGroupConfig;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Delegation verdict derivation — DelegationEligibilityService.
 *
 * This is the three-outcome seam the whole feature rests on. Collapsing
 * UNKNOWN into INELIGIBLE is the dangerous direction: on the JOIN path it
 * merely annoys, but the REVOKE sweep reads the same verdict, so an LCD
 * hiccup that reads as "ineligible" would EVICT EVERY MEMBER of every
 * delegator community. The tests below therefore pin the null-vs-empty
 * distinction from the fetcher all the way through to the verdict.
 *
 * Also pinned: max-single-wallet (not cross-wallet sum) stake semantics —
 * mirroring the NFT gate — and the per-wallet fetch amortization that lets
 * N communities on one chain cost one LCD call per wallet.
 *
 * ## Isolation
 * Runs in its own subprocess; setUp() pulls in
 * tests/Stubs/delegation-eligibility-stubs.php which fakes the
 * repositories, the fetcher factory + Cosmos fetcher, and the object cache
 * at their FQNs. The REAL verdict logic runs.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DelegationEligibilityTest extends TestCase
{
    private const USER_ID  = 7;
    private const CHAIN_ID = 8;
    private const VALOPER  = 'cosmosvaloper1fixture';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/delegation-eligibility-stubs.php';
        $GLOBALS['__bcc_deleg_fixture'] = [
            'wallets'     => [],
            'chain_type'  => 'cosmos',
            'responses'   => [],
            'fetch_calls' => [],
            'written'     => [],
        ];
        $GLOBALS['__bcc_deleg_cache'] = [];
    }

    /** @param list<string> $addresses */
    private function withWallets(array $addresses): void
    {
        $wallets = [];
        $id = 100;
        foreach ($addresses as $address) {
            $wallets[] = (object) [
                'id'             => (string) $id++,
                'wallet_address' => $address,
                'chain_id'       => (string) self::CHAIN_ID,
            ];
        }
        $GLOBALS['__bcc_deleg_fixture']['wallets'] = $wallets;
    }

    /** @param array<int, array{validator_address: string, shares: string|null, amount: float|null}>|null $rows */
    private function withResponse(string $address, ?array $rows): void
    {
        $GLOBALS['__bcc_deleg_fixture']['responses'][$address] = $rows;
    }

    /** @return array{validator_address: string, shares: string|null, amount: float|null} */
    private static function row(string $valoper, ?float $amount): array
    {
        return ['validator_address' => $valoper, 'shares' => '1000', 'amount' => $amount];
    }

    private static function config(int $groupId = 3300, float $minStake = 1.0): ValidatorGatedGroupConfig
    {
        return new ValidatorGatedGroupConfig($groupId, self::CHAIN_ID, 4242, self::VALOPER, $minStake);
    }

    private function verdictFor(ValidatorGatedGroupConfig $config): \BCC\Trust\Onchain\ValueObjects\DelegationVerdict
    {
        return (new DelegationEligibilityService())->verdictFor(self::USER_ID, $config);
    }

    public function testSufficientDelegationIsEligible(): void
    {
        $this->withWallets(['cosmos1alice']);
        $this->withResponse('cosmos1alice', [self::row(self::VALOPER, 25.0)]);

        $verdict = $this->verdictFor(self::config());

        self::assertTrue($verdict->isEligible());
        self::assertSame(25.0, $verdict->bestKnownStake);
    }

    public function testTransportFailureIsUnknownNotIneligible(): void
    {
        // THE load-bearing case: null from the fetcher is "we could not
        // check", never "they don't delegate". A revoke sweep reading this
        // as INELIGIBLE would evict the whole community during an outage.
        $this->withWallets(['cosmos1alice']);
        $this->withResponse('cosmos1alice', null);

        $verdict = $this->verdictFor(self::config());

        self::assertTrue($verdict->isUnknown());
        self::assertFalse($verdict->isIneligible());
    }

    public function testEmptyDelegationSetIsIneligibleNotUnknown(): void
    {
        // The mirror of the above: [] means the LCD ANSWERED and the
        // account delegates to nothing — a definite no, safe to revoke on.
        $this->withWallets(['cosmos1alice']);
        $this->withResponse('cosmos1alice', []);

        $verdict = $this->verdictFor(self::config());

        self::assertTrue($verdict->isIneligible());
        self::assertFalse($verdict->isUnknown());
    }

    public function testDelegationToADifferentValidatorIsIneligible(): void
    {
        $this->withWallets(['cosmos1alice']);
        $this->withResponse('cosmos1alice', [self::row('cosmosvaloper1someoneelse', 500.0)]);

        self::assertTrue($this->verdictFor(self::config())->isIneligible());
    }

    public function testStakeBelowMinimumIsIneligible(): void
    {
        $this->withWallets(['cosmos1alice']);
        $this->withResponse('cosmos1alice', [self::row(self::VALOPER, 0.5)]);

        $verdict = $this->verdictFor(self::config(3300, 10.0));

        self::assertTrue($verdict->isIneligible());
        self::assertSame(0.5, $verdict->bestKnownStake);
    }

    public function testMatchedRowWithUnreadableAmountIsUnknown(): void
    {
        // The wallet DOES delegate but the amount is unreadable — we
        // cannot prove it clears the dust gate, so we must not act.
        $this->withWallets(['cosmos1alice']);
        $this->withResponse('cosmos1alice', [self::row(self::VALOPER, null)]);

        self::assertTrue($this->verdictFor(self::config())->isUnknown());
    }

    public function testOneRealEligibleWalletWinsOverAnotherWalletsFailure(): void
    {
        // A proven-eligible wallet is decisive: another wallet's outage
        // cannot demote a delegator who demonstrably qualifies.
        $this->withWallets(['cosmos1alice', 'cosmos1bob']);
        $this->withResponse('cosmos1alice', [self::row(self::VALOPER, 25.0)]);
        $this->withResponse('cosmos1bob', null);

        self::assertTrue($this->verdictFor(self::config())->isEligible());
    }

    public function testPartialFailureWithNoQualifyingWalletIsUnknown(): void
    {
        // No wallet qualifies AND one couldn't be checked → UNKNOWN, so
        // the revoke sweep skips instead of evicting.
        $this->withWallets(['cosmos1alice', 'cosmos1bob']);
        $this->withResponse('cosmos1alice', []);
        $this->withResponse('cosmos1bob', null);

        self::assertTrue($this->verdictFor(self::config())->isUnknown());
    }

    public function testStakeIsMaxPerWalletNotSummedAcrossWallets(): void
    {
        // Mirrors the NFT gate's max-single-wallet semantics: two 6-unit
        // wallets do NOT combine to clear a 10-unit gate.
        $this->withWallets(['cosmos1alice', 'cosmos1bob']);
        $this->withResponse('cosmos1alice', [self::row(self::VALOPER, 6.0)]);
        $this->withResponse('cosmos1bob', [self::row(self::VALOPER, 6.0)]);

        $verdict = $this->verdictFor(self::config(3300, 10.0));

        self::assertTrue($verdict->isIneligible());
        self::assertSame(6.0, $verdict->bestKnownStake);
    }

    public function testNoVerifiedWalletOnChainIsIneligibleWithoutAnyFetch(): void
    {
        $this->withWallets([]);

        $verdict = $this->verdictFor(self::config());

        self::assertTrue($verdict->isIneligible());
        self::assertSame([], $GLOBALS['__bcc_deleg_fixture']['fetch_calls']);
    }

    public function testNonCosmosChainIsUnknownAndNeverFetches(): void
    {
        // Fail closed on an unsupported chain — never a fake "ineligible"
        // that could feed a revoke.
        $GLOBALS['__bcc_deleg_fixture']['chain_type'] = 'evm';
        $this->withWallets(['cosmos1alice']);

        $verdict = $this->verdictFor(self::config());

        self::assertTrue($verdict->isUnknown());
        self::assertSame([], $GLOBALS['__bcc_deleg_fixture']['fetch_calls']);
    }

    public function testBatchedVerdictsFetchEachWalletOnce(): void
    {
        // Three communities on one chain must cost ONE LCD call per
        // wallet, not one per (wallet, community) pair.
        $this->withWallets(['cosmos1alice']);
        $this->withResponse('cosmos1alice', [
            self::row(self::VALOPER, 25.0),
            self::row('cosmosvaloper1other', 3.0),
        ]);

        $configs = [
            self::config(3301),
            new ValidatorGatedGroupConfig(3302, self::CHAIN_ID, 99, 'cosmosvaloper1other', 1.0),
            new ValidatorGatedGroupConfig(3303, self::CHAIN_ID, 77, 'cosmosvaloper1absent', 1.0),
        ];

        $verdicts = (new DelegationEligibilityService())->verdictsForUser(self::USER_ID, $configs);

        self::assertTrue($verdicts[3301]->isEligible());
        self::assertTrue($verdicts[3302]->isEligible());
        self::assertTrue($verdicts[3303]->isIneligible());
        self::assertCount(1, $GLOBALS['__bcc_deleg_fixture']['fetch_calls']);
    }

    public function testSuccessfulFetchWritesThroughToTheDelegationIndex(): void
    {
        $this->withWallets(['cosmos1alice']);
        $this->withResponse('cosmos1alice', [self::row(self::VALOPER, 25.0)]);

        $this->verdictFor(self::config());

        self::assertSame([[100, self::CHAIN_ID, 1]], $GLOBALS['__bcc_deleg_fixture']['written']);
    }

    public function testTransportFailureIsNeverCachedOrWrittenThrough(): void
    {
        // Caching a null would poison the 5-minute window with a fake
        // "no delegations" for every later reader.
        $this->withWallets(['cosmos1alice']);
        $this->withResponse('cosmos1alice', null);

        $this->verdictFor(self::config());
        $this->verdictFor(self::config());

        // Retried rather than served from cache…
        self::assertCount(2, $GLOBALS['__bcc_deleg_fixture']['fetch_calls']);
        // …and no bogus empty set written to the recommender index.
        self::assertSame([], $GLOBALS['__bcc_deleg_fixture']['written']);
    }
}
