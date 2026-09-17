<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Core\REST\UserGroupsEndpoint;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use BCC\Trust\Onchain\REST\HolderGroupsEndpoint;
use BCC\Trust\Onchain\Services\CollectionStanceService;
use BCC\Trust\Onchain\ValueObjects\GatedGroupConfig;
use BCC\Trust\Onchain\ValueObjects\GateIdentity;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * PR 7.14, safeguard 2 — the request surfaces that are NOT the sweep or join.
 *
 * An ordinary page render must not be able to fan out into an unbounded
 * number of provider reads. Each surface below is driven with more
 * (group × wallet) work than its budget covers, through the REAL endpoint or
 * service code, and the provider calls the fetcher double recorded are
 * counted against that surface's cap:
 *
 *   surface                                        budget   per read here   max calls
 *   GET /me/holder-groups   (HolderGroupsEndpoint)   40            1             40
 *   profile groups badge    (UserGroupsEndpoint)     20            1             20
 *   stance write            (CollectionStanceService) 30           4              7
 *
 * Plus the two behaviours that go with it: the listing no longer asks about
 * groups the viewer has already joined or opted out of (their balance was
 * never used), and a stance on a chain that cannot be verified is a
 * retryable 503, not a 403 "you don't hold this".
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NftVerificationSurfaceBudgetTest extends TestCase
{
    private const USER        = 501;
    private const COSMOS      = 8;
    private const WALLET      = 'cosmos1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq';
    private const LINK        = 4242;
    private const FIRST_GROUP = 9000;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/revocation-fail-safe-stubs.php';
        \BccRevokeWorld::reset();
        \BCC\Core\Log\Logger::reset();
        \BccRevokeWorld::seedChain(self::COSMOS, 'cosmos', 'cosmos');
        \BccRevokeWorld::$currentUser = self::USER;
    }

    private static function contract(int $i): string
    {
        return 'cosmos1' . str_pad((string) $i, 58, 'g', STR_PAD_LEFT);
    }

    /** Gate $n groups on distinct contracts; the viewer's wallet holds none of them. */
    private function gates(int $n, int $minBalance = 1): void
    {
        \BccRevokeWorld::linkWallet(self::USER, self::LINK, self::COSMOS, self::WALLET);
        for ($i = 0; $i < $n; $i++) {
            $gid      = self::FIRST_GROUP + $i;
            $contract = self::contract($i);
            \BccRevokeWorld::$gateConfigs[$gid] = new GatedGroupConfig($gid, self::COSMOS, $contract, $minBalance, 55);
            \BccRevokeWorld::$identities[$gid]  = GateIdentity::resolved($contract, 'cosmos', 'cosmos', 55);
            \BccRevokeWorld::$answers[self::WALLET . '|' . $contract] = ['exact', 0];
            \BccRevokeWorld::$members[$gid] = [(object) ['user_id' => 1, 'role' => 'member_owner']];
        }
    }

    /** @return array<string, mixed> */
    private function listing(): array
    {
        $endpoint = (new \ReflectionClass(HolderGroupsEndpoint::class))->newInstanceWithoutConstructor();
        $response = $endpoint->getList(new \WP_REST_Request());
        self::assertSame(200, $response->get_status());

        /** @var array{data: array<string, mixed>} $body */
        $body = $response->get_data();

        return $body['data'];
    }

    // ── GET /me/holder-groups ───────────────────────────────────────────

    public function testTheListingNeverAsksAboutGroupsTheViewerAlreadyJoined(): void
    {
        $this->gates(25);
        for ($i = 0; $i < 20; $i++) {
            \BccRevokeWorld::$members[self::FIRST_GROUP + $i][] = (object) ['user_id' => self::USER, 'role' => 'member'];
        }

        $data = $this->listing();

        self::assertCount(20, $data['joined']);
        self::assertSame(5, \BccRevokeWorld::providerCalls(), 'only the five groups that could be suggested were checked');
    }

    public function testTheListingNeverAsksAboutGroupsTheViewerOptedOutOf(): void
    {
        $this->gates(6);
        \BccRevokeWorld::$userMeta[self::USER]['bcc_gated_groups_optout'] = (string) json_encode([
            (string) (self::FIRST_GROUP + 0) => 0,
            (string) (self::FIRST_GROUP + 1) => 0,
        ]);

        $data = $this->listing();

        self::assertCount(2, $data['opted_out']);
        self::assertSame(4, \BccRevokeWorld::providerCalls());
    }

    public function testTheListingIsBoundedByItsBudget(): void
    {
        $this->gates(60);

        $data = $this->listing();

        self::assertSame(40, \BccRevokeWorld::providerCalls(), 'sixty candidate groups, forty reads, no more');
        self::assertSame([], $data['eligible_to_join'], 'a group the budget did not reach is not suggested');
    }

    public function testTheListingStillSuggestsAProvenHolder(): void
    {
        $this->gates(3);
        \BccRevokeWorld::$answers[self::WALLET . '|' . self::contract(1)] = ['exact', 1];

        $data = $this->listing();

        self::assertCount(1, $data['eligible_to_join']);
        self::assertSame(self::FIRST_GROUP + 1, $data['eligible_to_join'][0]['group_id']);
    }

    // ── Profile groups badge ────────────────────────────────────────────

    public function testTheProfileBadgeIsBoundedByItsBudget(): void
    {
        $this->gates(30);
        $contexts = [];
        foreach (array_keys(\BccRevokeWorld::$gateConfigs) as $gid) {
            $contexts[$gid] = new GroupContext($gid, GroupType::Nft, PeepSoPrivacy::Open, false, null, null, null);
        }

        $endpoint = (new \ReflectionClass(UserGroupsEndpoint::class))->newInstanceWithoutConstructor();
        $method   = new \ReflectionMethod(UserGroupsEndpoint::class, 'computeViewerHolderEligibility');
        $method->setAccessible(true);

        /** @var array<int, array{eligible: bool, min_balance: int, balance: int}> $badges */
        $badges = $method->invoke($endpoint, self::USER, $contexts);

        self::assertSame(20, \BccRevokeWorld::providerCalls());
        self::assertCount(30, $badges);
        foreach ($badges as $badge) {
            self::assertFalse($badge['eligible']);
        }
    }

    // ── Stance write ────────────────────────────────────────────────────

    public function testAStanceOnAChainThatCannotBeVerifiedIsRetryableNotADenial(): void
    {
        \BccRevokeWorld::linkWallet(self::USER, self::LINK, self::COSMOS, self::WALLET);
        \BccRevokeWorld::$chains[self::COSMOS]->is_active = 0;

        $result = CollectionStanceService::setStance(self::USER, self::COSMOS, self::contract(0), 'waitlist');

        self::assertSame(['ok' => false, 'error' => 'bcc_unavailable'], $result, 'was 403 bcc_nft_not_owned before PR 7.14');
        self::assertSame([], \BccRevokeWorld::$stanceWrites);
    }

    public function testAStanceWithACompleteZeroIsStillDenied(): void
    {
        $this->gates(1);

        $result = CollectionStanceService::setStance(self::USER, self::COSMOS, self::contract(0), 'waitlist');

        self::assertSame(['ok' => false, 'error' => 'bcc_nft_not_owned'], $result);
    }

    public function testAStanceWriteIsBoundedByItsBudget(): void
    {
        \BccRevokeWorld::$maxRequestsPerRead = 4;
        for ($i = 0; $i < 10; $i++) {
            $address = 'cosmos1' . str_pad((string) $i, 38, 's', STR_PAD_LEFT);
            \BccRevokeWorld::linkWallet(self::USER, self::LINK + $i, self::COSMOS, $address);
            \BccRevokeWorld::$answers[$address . '|' . self::contract(0)] = ['exact', 0];
        }

        $result = CollectionStanceService::setStance(self::USER, self::COSMOS, self::contract(0), 'waitlist');

        self::assertSame(7, \BccRevokeWorld::providerCalls(), 'thirty units at four per read');
        self::assertSame(['ok' => false, 'error' => 'bcc_unavailable'], $result, 'three wallets were never read, so this is not a denial');
        self::assertSame([], \BccRevokeWorld::$stanceWrites);
    }

    public function testAStanceRetryResumesWithTheWalletsTheBudgetDidNotReach(): void
    {
        // Seven wallets per attempt at four units; only wallet 9 holds it.
        \BccRevokeWorld::$maxRequestsPerRead = 4;
        for ($i = 0; $i < 10; $i++) {
            $address = 'cosmos1' . str_pad((string) $i, 38, 'r', STR_PAD_LEFT);
            \BccRevokeWorld::linkWallet(self::USER, self::LINK + $i, self::COSMOS, $address);
            \BccRevokeWorld::$answers[$address . '|' . self::contract(0)] = ['exact', $i === 8 ? 1 : 0];
        }

        $first = CollectionStanceService::setStance(self::USER, self::COSMOS, self::contract(0), 'waitlist');
        self::assertSame(['ok' => false, 'error' => 'bcc_unavailable'], $first);

        \BccRevokeWorld::$fetcherCalls = [];
        $second = CollectionStanceService::setStance(self::USER, self::COSMOS, self::contract(0), 'waitlist');

        self::assertSame(['ok' => true], $second);
        self::assertSame(2, \BccRevokeWorld::providerCalls(), 'the retry read wallets 8 and 9 only');
    }

    /**
     * THE CONSEQUENCE, stated as a test so it cannot drift silently: the
     * stance panel's stored rows have no freshness bound, and a stored row
     * still lets a user who has since SOLD the NFT write a stance, with no
     * provider call. That is testimony (waitlist / spam flag), not access —
     * NftRevocationFailSafeTest §7 proves the same row decides no join,
     * membership or revocation.
     */
    public function testAStaleStoredRowStillLetsASellerWriteAStance(): void
    {
        \BccRevokeWorld::linkWallet(self::USER, self::LINK, self::COSMOS, self::WALLET);
        \BccRevokeWorld::$storedRows[self::LINK] = [(object) ['contract_address' => self::contract(0), 'token_id' => '1']];
        \BccRevokeWorld::$answers[self::WALLET . '|' . self::contract(0)] = ['exact', 0];

        $result = CollectionStanceService::setStance(self::USER, self::COSMOS, self::contract(0), 'waitlist');

        self::assertSame(['ok' => true], $result);
        self::assertSame(0, \BccRevokeWorld::providerCalls());
        self::assertSame([self::LINK], \BccRevokeWorld::$storedReads);
    }

    public function testAStanceWithProofIsWritten(): void
    {
        $this->gates(1);
        \BccRevokeWorld::$answers[self::WALLET . '|' . self::contract(0)] = ['exact', 1];

        $result = CollectionStanceService::setStance(self::USER, self::COSMOS, self::contract(0), 'waitlist');

        self::assertSame(['ok' => true], $result);
        self::assertCount(1, \BccRevokeWorld::$stanceWrites);
    }
}
