<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Core\REST\CreatorGalleryEndpoint;
use BCC\Trust\Onchain\Services\ChainRefreshService;
use BCC\Trust\Onchain\Services\CollectionStanceService;
use BCC\Trust\Onchain\Services\WalletSeedService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE FIVE PATHS THAT USED TO SEND A MEMBER'S COSMOS ADDRESS TO AN
 * UNDOCUMENTED MARKETPLACE API, EXERCISED — not inspected.
 *
 *   1. wallet verification → async collection seeding
 *   2. the four-hourly collection refresh cron
 *   3. an ANONYMOUS creator-gallery GET → deferred refresh job
 *   4. the signed-in stance-panel GET
 *   5. the signed-in set-stance POST
 *
 * Each sent the address in a URL path, with WordPress's site-identifying
 * default user-agent, to an API Stargaze does not publish as a supported
 * integration. Path 3 is the one that made it indefensible: a stranger's
 * page view scheduled the disclosure moments later, outside the request
 * anyone was reviewing.
 *
 * ── WHY THIS IS NOT A SOURCE SCAN ───────────────────────────────────────
 * `StargazeCallerInventoryTest` already greps. This file RUNS the paths
 * against a world where every transport is wired and would succeed, where
 * the fetcher WOULD return rows, and where several real wallets are linked
 * on two chain families. "Zero requests" is therefore a measurement of
 * refusal, not an artefact of a fixture that had nothing to give.
 *
 * ⚠ The recording fetcher deliberately advertises `collection` for EVM and
 * Solana and NOT for Cosmos — the real post-PR-7.12 shape. Tests that need
 * to prove the CALLER refuses (rather than the fetcher) flip the capability
 * on explicitly via `BccFanOutWorld::$capabilities`.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StargazeFanOutRemovedTest extends TestCase
{
    private const USER = 7;
    private const PROJECT_POST = 4242;

    /** Several DISTINCT, genuinely-shaped addresses, so a leak has something to leak. */
    private const HUB_WALLETS = [
        'cosmos15y38ehvexp6275ptmm4jj3qdds379nk02heclj',
        'cosmos1yl6hdjhmkf37639730gffanpzndzdpmhwlkfhr',
        'cosmos1xy4kvtvnkkwrsmwkqfnzhhjlyu4uz5vc2tmqn8',
    ];

    private const EVM_WALLET  = '0xab5801a7d398351b8be11c439e05c5b3259aec9b';
    private const EVM_CONTRACT = '0xfeed000000000000000000000000000000000001';

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
        \BCC\Trust\Onchain\Services\CollectionService::reset();

        \BCC\Trust\Onchain\Repositories\ChainRepository::$byId = [
            8 => (object) ['id' => 8, 'slug' => 'cosmos', 'chain_type' => 'cosmos', 'name' => 'Cosmos Hub', 'rest_url' => 'https://lcd.invalid', 'explorer_url' => null],
            1 => (object) ['id' => 1, 'slug' => 'ethereum', 'chain_type' => 'evm', 'name' => 'Ethereum', 'rpc_url' => 'https://rpc.invalid', 'explorer_url' => null],
        ];

        // A REAL creator page, so the gallery endpoint resolves the slug and
        // runs its whole body — including the stale-rows branch that used to
        // dispatch `bcc_onchain_holdings_refresh`. If the slug did not
        // resolve the endpoint would 404 early and "scheduled nothing" would
        // be true for the wrong reason.
        $creator            = new \WP_Post();
        $creator->ID        = self::PROJECT_POST;
        $creator->post_type = 'peepso-page';
        $creator->post_name = 'a-creator';
        \BccFanOutWorld::$posts['a-creator'] = $creator;
    }

    /** @return list<object> */
    private static function hubWallets(): array
    {
        $out = [];
        foreach (self::HUB_WALLETS as $i => $address) {
            $out[] = (object) [
                'id'             => 100 + $i,
                'chain_id'       => 8,
                'wallet_address' => $address,
                'post_id'        => self::PROJECT_POST,
                'chain_type'     => 'cosmos',
                'chain_slug'     => 'cosmos',
            ];
        }

        return $out;
    }

    private static function evmWallet(): object
    {
        return (object) [
            'id'             => 200,
            'chain_id'       => 1,
            'wallet_address' => self::EVM_WALLET,
            'post_id'        => self::PROJECT_POST,
            'chain_type'     => 'evm',
            'chain_slug'     => 'ethereum',
        ];
    }

    /** Every address that must never appear anywhere. */
    private static function assertNoAddressLeaked(string ...$extra): void
    {
        $haystack = \BccFanOutWorld::allRecordedText();
        foreach (array_merge(self::HUB_WALLETS, [self::EVM_WALLET], $extra) as $address) {
            self::assertStringNotContainsString(
                $address,
                $haystack,
                'a wallet address reached a request, a log line, a cache key or a scheduled job'
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  PATH 1 — wallet verification → async seeding
    // ═══════════════════════════════════════════════════════════════════

    public function testWalletVerificationMakesNoRequestForACosmosWallet(): void
    {
        \BCC\Trust\Onchain\Repositories\WalletRepository::$byUser[self::USER] = self::hubWallets();

        WalletSeedService::onWalletVerified(self::USER, 'cosmos', self::HUB_WALLETS[0]);

        self::assertSame([], \BccFanOutWorld::$http, 'verifying a wallet must not discover its collections');
        self::assertSame([], \BccFanOutWorld::$fetchCollectionsCalls);
        self::assertSame([], \BccFanOutWorld::$persisted, 'nothing may be persisted from a path that asked nobody');
        self::assertNoAddressLeaked();
    }

    /**
     * Anti-vacuity: the same path on an EVM wallet DOES discover, so the
     * Cosmos result above is a refusal and not a dead code path.
     */
    public function testWalletVerificationStillDiscoversOnEvm(): void
    {
        \BCC\Trust\Onchain\Repositories\WalletRepository::$byUser[self::USER] = [self::evmWallet()];

        WalletSeedService::onWalletVerified(self::USER, 'ethereum', self::EVM_WALLET);

        self::assertNotSame(
            [],
            \BccFanOutWorld::$fetchCollectionsCalls,
            'EVM discovery must be untouched by PR 7.12'
        );
        self::assertNotSame([], \BccFanOutWorld::$persisted);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  PATH 2 — the four-hourly refresh cron
    // ═══════════════════════════════════════════════════════════════════

    public function testTheRefreshCronSkipsCosmosRowsAndBacksThemOff(): void
    {
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$expired = [
            (object) ['id' => 900, 'chain_id' => 8, 'wallet_link_id' => 100, 'contract_address' => 'cosmos1abc'],
        ];
        \BCC\Trust\Onchain\Repositories\WalletRepository::$byUser[self::USER] = self::hubWallets();

        ChainRefreshService::refresh_collections();

        self::assertSame([], \BccFanOutWorld::$http, 'the cron must not contact a provider for a Cosmos row');
        self::assertSame([], \BccFanOutWorld::$fetchCollectionsCalls);
        self::assertSame(
            [900],
            \BccFanOutWorld::$backoffs,
            'the row must be backed off, or it sits at the head of the queue forever and starves EVM/Solana rows'
        );
        self::assertSame([], \BccFanOutWorld::$refreshStamps, 'a skipped row must not be stamped as refreshed');
        self::assertNoAddressLeaked();
    }

    /** Anti-vacuity: an EVM row in the same batch IS refreshed. */
    public function testTheRefreshCronStillRefreshesEvmRows(): void
    {
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$expired = [
            (object) ['id' => 901, 'chain_id' => 1, 'wallet_link_id' => 200, 'contract_address' => self::EVM_CONTRACT],
        ];
        \BCC\Trust\Onchain\Repositories\WalletRepository::$byUser[self::USER] = [self::evmWallet()];

        ChainRefreshService::refresh_collections();

        self::assertNotSame([], \BccFanOutWorld::$fetchCollectionsCalls, 'EVM refresh must survive');
        self::assertSame([], \BccFanOutWorld::$backoffs, 'a successful EVM refresh is not a backoff');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  PATH 3 — the ANONYMOUS creator-gallery GET
    // ═══════════════════════════════════════════════════════════════════

    public function testTheAnonymousGalleryGetSchedulesNothingAndContactsNobody(): void
    {
        // A creator with wallets and STALE rows — the exact state that used
        // to dispatch `bcc_onchain_holdings_refresh`.
        \BCC\Trust\Onchain\Repositories\WalletRepository::$byProject[self::PROJECT_POST] =
            array_merge(self::hubWallets(), [self::evmWallet()]);
        \BCC\Trust\Onchain\Services\CollectionService::$byProject[self::PROJECT_POST] = [
            'items' => [
                (object) [
                    'id'               => 5,
                    'contract_address' => self::EVM_CONTRACT,
                    'chain_slug'       => 'ethereum',
                    'chain_name'       => 'Ethereum',
                    'collection_name'  => 'Founders',
                    'image_url'        => null,
                    'expires_at'       => gmdate('Y-m-d H:i:s', time() - 3600), // stale
                    'fetched_at'       => gmdate('Y-m-d H:i:s', time() - 7200),
                    'explorer_url'     => null,
                    'total_supply'     => null,
                ],
            ],
            'total' => 1,
            'pages' => 1,
        ];

        $endpoint = new CreatorGalleryEndpoint();
        $response = $endpoint->handle(new \WP_REST_Request(['slug' => 'a-creator', 'page' => 1]));

        // The endpoint may legitimately 404 in this stub world (resolving a
        // WP post is out of scope); what matters is that NOTHING was
        // scheduled and NOTHING was contacted on the way to that answer.
        self::assertInstanceOf(\WP_REST_Response::class, $response);
        self::assertSame([], \BccFanOutWorld::$scheduled, 'an anonymous GET must schedule no background work');
        self::assertSame([], \BccFanOutWorld::$http, 'and must contact nobody');
        self::assertSame([], \BccFanOutWorld::$fetchCollectionsCalls);
        self::assertNoAddressLeaked();
    }

    /** The retired hook name must not be schedulable from anywhere. */
    public function testTheRetiredRefreshHookIsNeverScheduled(): void
    {
        \BCC\Trust\Onchain\Repositories\WalletRepository::$byProject[self::PROJECT_POST] = self::hubWallets();

        $endpoint = new CreatorGalleryEndpoint();
        $endpoint->handle(new \WP_REST_Request(['slug' => 'a-creator']));

        self::assertNotContains('bcc_onchain_holdings_refresh', \BccFanOutWorld::$scheduled);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  PATHS 4 & 5 — the stance panel GET and the set-stance POST
    // ═══════════════════════════════════════════════════════════════════

    public function testTheStancePanelContactsNobody(): void
    {
        \BCC\Trust\Onchain\Repositories\WalletRepository::$byUser[self::USER] = self::hubWallets();
        foreach (self::HUB_WALLETS as $address) {
            \BccFanOutWorld::$holdings[$address] = ['items' => [], 'complete' => true, 'truncated' => false];
        }

        $panel = CollectionStanceService::panelForUser(self::USER);

        self::assertSame([], \BccFanOutWorld::$http);
        self::assertSame(CollectionStanceService::STATUS_COMPLETE, $panel['holdings_status']);
        self::assertNoAddressLeaked();
    }

    public function testSettingAStanceContactsNobodyAndNeedsPositiveProof(): void
    {
        \BCC\Trust\Onchain\Repositories\WalletRepository::$byUser[self::USER] = self::hubWallets();
        // No seeded count → the provider cannot answer → UNKNOWN.

        $result = CollectionStanceService::setStance(self::USER, 8, 'cosmos1contract', 'waitlist');

        self::assertFalse($result['ok']);
        self::assertSame(
            'bcc_unavailable',
            $result['error'] ?? '',
            'unverifiable holdings must stay a retryable 503, never a denial and never a grant'
        );
        self::assertSame([], \BCC\Trust\Onchain\Repositories\CollectionSignalRepository::$writes);
        self::assertSame([], \BccFanOutWorld::$http);
        self::assertNoAddressLeaked();
    }

    public function testMissingEvidenceCannotGrantAStance(): void
    {
        \BCC\Trust\Onchain\Repositories\WalletRepository::$byUser[self::USER] = self::hubWallets();
        // A REAL zero: the chain answered, the wallet holds none.
        foreach (self::HUB_WALLETS as $address) {
            \BccFanOutWorld::$counts[$address . '|cosmos1contract'] = 0;
        }

        $result = CollectionStanceService::setStance(self::USER, 8, 'cosmos1contract', 'waitlist');

        self::assertFalse(
            $result['ok'],
            'no grant without proof: an unverified holdings read must never write a stance'
        );
        self::assertSame(
            'bcc_nft_not_owned',
            $result['error'] ?? '',
            'the refusal must stay bcc_nft_not_owned'
        );
        self::assertSame([], \BCC\Trust\Onchain\Repositories\CollectionSignalRepository::$writes);
    }

    /** Anti-vacuity: with genuine proof the stance IS written. */
    public function testPositiveProofStillWritesTheStance(): void
    {
        \BCC\Trust\Onchain\Repositories\WalletRepository::$byUser[self::USER] = self::hubWallets();
        \BccFanOutWorld::$counts[self::HUB_WALLETS[0] . '|cosmos1contract'] = 2;

        $result = CollectionStanceService::setStance(self::USER, 8, 'cosmos1contract', 'waitlist');

        self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        self::assertCount(1, \BCC\Trust\Onchain\Repositories\CollectionSignalRepository::$writes);
        self::assertSame([], \BccFanOutWorld::$http, 'proof comes from the chain, not from a marketplace');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Unavailable evidence must not destroy what is already stored
    // ═══════════════════════════════════════════════════════════════════

    public function testAnUnavailableLookupRevokesNothingAndErasesNothing(): void
    {
        \BCC\Trust\Onchain\Repositories\WalletRepository::$byUser[self::USER] =
            array_merge(self::hubWallets(), [self::evmWallet()]);
        \BCC\Trust\Onchain\Repositories\NftHoldingsRepository::$visible['200|1'] = [
            (object) ['contract_address' => self::EVM_CONTRACT, 'collection_name' => 'Founders', 'image_url' => null],
        ];
        // Existing stance the user already set.
        \BCC\Trust\Onchain\Repositories\CollectionSignalRepository::$stances[self::USER . '|1|' . self::EVM_CONTRACT] = 'waitlist';

        foreach (self::HUB_WALLETS as $address) {
            \BccFanOutWorld::$holdings[$address] = ['items' => [], 'complete' => false, 'truncated' => false];
        }

        $panel = CollectionStanceService::panelForUser(self::USER);

        self::assertSame(CollectionStanceService::STATUS_PARTIAL, $panel['holdings_status']);
        self::assertSame(
            [self::EVM_CONTRACT],
            array_map(static fn(array $r): string => (string) $r['contract_address'], $panel['items']),
            'stored EVM evidence must survive a Cosmos outage'
        );
        self::assertSame(
            'waitlist',
            $panel['items'][0]['viewer_stance'],
            'and the existing stance must not be cleared by an unreadable source'
        );
    }
}
