<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\CollectionStanceService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * `holdings_status` on GET /me/collection-stances/panel: an empty list must
 * say WHY it is empty.
 *
 * ── THE DEFECT THIS FIELD EXISTS FOR ────────────────────────────────────
 * The panel returned one key, `items`. When the Cosmos source could not be
 * read, the old code flattened the failure with `?? []` and the frontend
 * rendered the result as a verdict: "No collections detected in your linked
 * wallets yet." A provider outage was presented to the user as a fact about
 * their wallet.
 *
 * ── THE CLOSED VOCABULARY ───────────────────────────────────────────────
 *   complete    — every source finished; an empty list is TRUSTWORTHY
 *   partial     — at least one source did not finish; ABSENCE PROVES NOTHING
 *   unavailable — nothing finished; no determination was possible
 *
 * `null` is not a member. The field is absent only from an older backend
 * mid-deploy, which is a deployment fact rather than a state.
 *
 * ⚠ ANTI-VACUITY. Every case here seeds REAL wallets and a fetcher that
 * WOULD answer, so an assertion of "no items" is a measured outcome and not
 * a fixture that had nothing to give.
 */
#[CoversClass(CollectionStanceService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CollectionStancePanelStatusTest extends TestCase
{
    private const USER = 7;

    private const HUB_WALLET = 'cosmos15y38ehvexp6275ptmm4jj3qdds379nk02heclj';
    private const EVM_WALLET = '0xab5801a7d398351b8be11c439e05c5b3259aec9b';

    private const HUB_CONTRACT = 'cosmos1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqzz';
    private const EVM_CONTRACT = '0xfeed000000000000000000000000000000000001';

    /**
     * A wallet on a chain family with NO driver at all. `FetcherFactory::
     * has_driver()` is false for it, which is the ONLY way
     * `walletHoldingsWithCompleteness()` returns null — the "we never
     * looked" case, as opposed to "we looked and the wallet was empty".
     */
    private const DOT_WALLET = '15oF4uVJwmo4TdGW7VfQxNLavjCXviqxT9S1MgbjMNHr6Sp5';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/stargaze-fanout-removed-stubs.php';

        \BccFanOutWorld::reset();
        \BCC\Trust\Onchain\Repositories\WalletRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::reset();
        \BCC\Trust\Onchain\Repositories\NftHoldingsRepository::reset();
        \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::reset();
        \BCC\Trust\Onchain\Repositories\CollectionSignalRepository::reset();

        \BCC\Trust\Onchain\Repositories\ChainRepository::$byId = [
            8 => (object) ['id' => 8, 'slug' => 'cosmos', 'chain_type' => 'cosmos', 'name' => 'Cosmos Hub', 'rest_url' => 'https://lcd.invalid'],
            1 => (object) ['id' => 1, 'slug' => 'ethereum', 'chain_type' => 'evm', 'name' => 'Ethereum', 'rpc_url' => 'https://rpc.invalid'],
        ];
    }

    /** @param list<object> $wallets */
    private function linkWallets(array $wallets): void
    {
        \BCC\Trust\Onchain\Repositories\WalletRepository::$byUser[self::USER] = $wallets;
    }

    private static function hubWallet(int $id = 11): object
    {
        return (object) ['id' => $id, 'chain_id' => 8, 'wallet_address' => self::HUB_WALLET, 'post_id' => 0];
    }

    private static function evmWallet(int $id = 12): object
    {
        return (object) ['id' => $id, 'chain_id' => 1, 'wallet_address' => self::EVM_WALLET, 'post_id' => 0];
    }

    /** Seed what the Cosmos LCD walk returns for the Hub wallet. */
    private static function hubWalk(bool $complete, bool $withCollection): void
    {
        \BccFanOutWorld::$holdings[self::HUB_WALLET] = [
            'items' => $withCollection
                ? [[
                    'contract_address' => self::HUB_CONTRACT,
                    'token_id'         => '1',
                    'chain_id'         => 8,
                    'collection_name'  => 'Atlas',
                    'image_url'        => null,
                ]]
                : [],
            'complete'  => $complete,
            'truncated' => false,
        ];
    }

    private static function storeEvmRow(int $walletLinkId = 12): void
    {
        \BCC\Trust\Onchain\Repositories\NftHoldingsRepository::$visible[$walletLinkId . '|1'] = [
            (object) [
                'contract_address' => self::EVM_CONTRACT,
                'collection_name'  => 'Founders',
                'image_url'        => null,
            ],
        ];
    }

    /** @return array{items: list<array<string, mixed>>, holdings_status: string} */
    private function panel(): array
    {
        /** @var array{items: list<array<string, mixed>>, holdings_status: string} $panel */
        $panel = CollectionStanceService::panelForUser(self::USER);

        return $panel;
    }

    /** @param list<array<string, mixed>> $items */
    private static function contracts(array $items): array
    {
        return array_map(static fn(array $r): string => (string) $r['contract_address'], $items);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  complete — and only complete licenses "you hold nothing"
    // ═══════════════════════════════════════════════════════════════════

    public function testNoLinkedWalletsIsComplete(): void
    {
        $panel = $this->panel();

        self::assertSame([], $panel['items']);
        self::assertSame(
            CollectionStanceService::STATUS_COMPLETE,
            $panel['holdings_status'],
            'nothing could fail, so the empty panel is trustworthy'
        );
    }

    public function testACompleteWalkWithNoCollectionsIsComplete(): void
    {
        $this->linkWallets([self::hubWallet()]);
        self::hubWalk(complete: true, withCollection: false);

        $panel = $this->panel();

        self::assertSame([], $panel['items']);
        self::assertSame(CollectionStanceService::STATUS_COMPLETE, $panel['holdings_status']);
    }

    public function testACompleteWalkWithCollectionsIsComplete(): void
    {
        $this->linkWallets([self::hubWallet()]);
        self::hubWalk(complete: true, withCollection: true);

        $panel = $this->panel();

        self::assertSame([self::HUB_CONTRACT], self::contracts($panel['items']));
        self::assertSame(CollectionStanceService::STATUS_COMPLETE, $panel['holdings_status']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  failure never becomes zero
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠ THE FABRICATED ZERO, IN ITS PUREST FORM.
     *
     * A chain with no driver is never read at all. If that silently drops
     * out of the tally, `$sourcesFailed` stays 0, the fold returns
     * `complete`, and an empty `items` becomes the sentence "you hold
     * nothing" — asserted about a wallet nobody ever looked at.
     *
     * ANTI-VACUITY: the wallet is seeded with a COMPLETE walk that holds a
     * real collection. If a driver existed, this panel would have rows. The
     * emptiness is caused by `has_driver()` refusing, and by nothing else.
     */
    public function testAChainWithNoDriverIsUnreadableNotAnEmptyWallet(): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::$byId[9] = (object) [
            'id' => 9, 'slug' => 'polkadot', 'chain_type' => 'polkadot', 'name' => 'Polkadot',
        ];
        \BccFanOutWorld::$holdings[self::DOT_WALLET] = [
            'items' => [[
                'contract_address' => self::HUB_CONTRACT,
                'token_id'         => '1',
                'chain_id'         => 9,
                'collection_name'  => 'Would Have Answered',
                'image_url'        => null,
            ]],
            'complete'  => true,
            'truncated' => false,
        ];
        $this->linkWallets([
            (object) ['id' => 19, 'chain_id' => 9, 'wallet_address' => self::DOT_WALLET, 'post_id' => 0],
        ]);

        $panel = $this->panel();

        self::assertSame([], $panel['items'], 'no driver means no rows can be proven');
        self::assertNotSame(
            'complete',
            $panel['holdings_status'],
            'an unreadable source must never be reported as complete: no driver means we never looked, '
            . 'and calling that complete fabricates a zero'
        );
        self::assertSame(
            'unavailable',
            $panel['holdings_status'],
            'nothing was readable, so no determination was possible'
        );
    }

    /**
     * The same unreadable source next to a source that DID answer. The
     * proven row must survive, and the overall answer must still refuse to
     * call itself complete.
     */
    public function testAnUnreadableSourceCannotBeHiddenByASourceThatSucceeded(): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::$byId[9] = (object) [
            'id' => 9, 'slug' => 'polkadot', 'chain_type' => 'polkadot', 'name' => 'Polkadot',
        ];
        self::storeEvmRow();
        $this->linkWallets([
            self::evmWallet(),
            (object) ['id' => 19, 'chain_id' => 9, 'wallet_address' => self::DOT_WALLET, 'post_id' => 0],
        ]);

        $panel = $this->panel();

        self::assertSame(
            [self::EVM_CONTRACT],
            self::contracts($panel['items']),
            'the proven EVM row survives an unreadable neighbour'
        );
        self::assertNotSame(
            'complete',
            $panel['holdings_status'],
            'an unreadable source must never be reported as complete, even when another source succeeded'
        );
        self::assertSame(
            'partial',
            $panel['holdings_status'],
            'one source answered and one was never read'
        );
    }

    public function testAnIncompleteWalkWithNothingResolvedIsUnavailableNotComplete(): void
    {
        $this->linkWallets([self::hubWallet()]);
        self::hubWalk(complete: false, withCollection: false);

        $panel = $this->panel();

        self::assertSame([], $panel['items']);
        self::assertSame(
            CollectionStanceService::STATUS_UNAVAILABLE,
            $panel['holdings_status'],
            'an unreadable source must never be reported as "you hold nothing"'
        );
        self::assertNotSame(CollectionStanceService::STATUS_COMPLETE, $panel['holdings_status']);
    }

    /**
     * ⚠ A walk can fail AFTER resolving some collections. Those rows are
     * real and must be returned — and `unavailable` would be a lie while we
     * are handing the caller proof.
     */
    public function testAnIncompleteWalkThatFoundSomethingIsPartialAndKeepsTheRows(): void
    {
        $this->linkWallets([self::hubWallet()]);
        self::hubWalk(complete: false, withCollection: true);

        $panel = $this->panel();

        self::assertSame([self::HUB_CONTRACT], self::contracts($panel['items']), 'positive evidence survives');
        self::assertSame(CollectionStanceService::STATUS_PARTIAL, $panel['holdings_status']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  one chain's outage may not erase another chain's evidence
    // ═══════════════════════════════════════════════════════════════════

    public function testAnUnavailableCosmosWalkDoesNotEraseEvmResults(): void
    {
        $this->linkWallets([self::hubWallet(), self::evmWallet()]);
        self::hubWalk(complete: false, withCollection: false);
        self::storeEvmRow();

        $panel = $this->panel();

        self::assertSame(
            [self::EVM_CONTRACT],
            self::contracts($panel['items']),
            'the EVM row is stored evidence and is unaffected by a Cosmos outage'
        );
        self::assertSame(
            CollectionStanceService::STATUS_PARTIAL,
            $panel['holdings_status'],
            'one source finished and one did not — that is exactly partial'
        );
    }

    public function testEverySourceHealthyAcrossTwoChainsIsComplete(): void
    {
        $this->linkWallets([self::hubWallet(), self::evmWallet()]);
        self::hubWalk(complete: true, withCollection: true);
        self::storeEvmRow();

        $panel = $this->panel();

        $contracts = self::contracts($panel['items']);
        sort($contracts);
        $expected = [self::EVM_CONTRACT, self::HUB_CONTRACT];
        sort($expected);

        self::assertSame($expected, $contracts);
        self::assertSame(CollectionStanceService::STATUS_COMPLETE, $panel['holdings_status']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  an incomplete read is never cached as complete
    // ═══════════════════════════════════════════════════════════════════

    public function testAnIncompleteWalkIsNotCached(): void
    {
        $this->linkWallets([self::hubWallet()]);
        self::hubWalk(complete: false, withCollection: true);

        $this->panel();

        $holdingsWrites = array_values(array_filter(
            \BccFanOutWorld::$transientWrites,
            static fn(array $w): bool => str_starts_with((string) $w['key'], 'bcc_holdings_w_')
        ));

        self::assertSame([], $holdingsWrites, 'an unresolved walk must not be stored as if it were the answer');
    }

    /** Anti-vacuity for the test above: a COMPLETE walk IS cached. */
    public function testACompleteWalkIsCached(): void
    {
        $this->linkWallets([self::hubWallet()]);
        self::hubWalk(complete: true, withCollection: true);

        $this->panel();

        $holdingsWrites = array_values(array_filter(
            \BccFanOutWorld::$transientWrites,
            static fn(array $w): bool => str_starts_with((string) $w['key'], 'bcc_holdings_w_')
        ));

        self::assertNotSame([], $holdingsWrites, 'the cache seam must be live, or the previous test proves nothing');
        self::assertTrue((bool) ($holdingsWrites[0]['value']['complete'] ?? false));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  no marketplace, no address leak
    // ═══════════════════════════════════════════════════════════════════

    public function testRenderingThePanelContactsNobody(): void
    {
        $this->linkWallets([self::hubWallet(), self::evmWallet()]);
        self::hubWalk(complete: true, withCollection: true);
        self::storeEvmRow();

        $this->panel();

        self::assertSame([], \BccFanOutWorld::$http, 'the panel must make no outbound request at all');
    }

    public function testNoWalletAddressAppearsInAnythingRecorded(): void
    {
        $this->linkWallets([self::hubWallet(), self::evmWallet()]);
        self::hubWalk(complete: false, withCollection: false);
        self::storeEvmRow();

        $panel    = $this->panel();
        $recorded = \BccFanOutWorld::allRecordedText();

        foreach ([self::HUB_WALLET, self::EVM_WALLET] as $address) {
            self::assertStringNotContainsString($address, $recorded, 'no wallet address may reach a log, cache key or request');
            self::assertStringNotContainsString($address, (string) json_encode($panel), 'nor the response body');
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  the shared contract fixture — vocabulary drift fails CI
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        $path = dirname(__DIR__) . '/Fixtures/collection-stance-panel.contract.json';
        self::assertFileExists($path);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testTheVocabularyMatchesTheSharedFixture(): void
    {
        $fixture = self::fixture();
        /** @var list<string> $vocabulary */
        $vocabulary = $fixture['response']['holdings_status']['vocabulary'];

        self::assertSame(
            $vocabulary,
            CollectionStanceService::HOLDINGS_STATUSES,
            'the backend vocabulary drifted from the fixture the frontend also pins'
        );
        self::assertSame(
            ['complete', 'partial', 'unavailable'],
            $vocabulary,
            'anti-vacuity: the fixture itself must still hold the three states'
        );
    }

    /** The checksum is over the canonical vocabulary, so a silent edit fails. */
    public function testTheFixtureChecksumMatchesItsOwnVocabulary(): void
    {
        $fixture = self::fixture();
        /** @var list<string> $vocabulary */
        $vocabulary = $fixture['response']['holdings_status']['vocabulary'];

        self::assertSame(
            hash('sha256', implode(',', $vocabulary)),
            $fixture['fixture_sha256'],
            'fixture_sha256 must be the digest of its own vocabulary, not a hand-written value'
        );
    }

    public function testNullIsNotAMemberOfTheVocabulary(): void
    {
        $fixture = self::fixture();

        self::assertFalse($fixture['response']['holdings_status']['nullable']);
        self::assertNotContains(null, CollectionStanceService::HOLDINGS_STATUSES);
        self::assertNotContains('', CollectionStanceService::HOLDINGS_STATUSES);
    }

    /** The `items` contract is unchanged — this is an ADDITIVE change. */
    public function testTheItemsShapeStillMatchesTheFixture(): void
    {
        $this->linkWallets([self::hubWallet()]);
        self::hubWalk(complete: true, withCollection: true);

        $panel = $this->panel();
        self::assertNotSame([], $panel['items'], 'denominator: a row must exist to compare');

        /** @var list<string> $expected */
        $expected = self::fixture()['response']['items']['fields'];
        $actual   = array_keys($panel['items'][0]);

        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual, 'the items row shape must not drift from the published contract');
    }
}
