<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\REST\PagesEndpoint;
use BCC\Trust\Onchain\Services\ClaimService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-303 — holding an NFT never claims a collection's page.
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────
 * ClaimService::matchCollection() named a BlockchainQueryService class that
 * does not exist, so every collection claim fell through to "no match". That
 * typo was the ONLY thing keeping a takeover shut: matchCollection answers
 * `holder` for any wallet holding one token, PagesEndpoint mirrors whatever
 * succeeds as an EXCLUSIVE page claim and fires `bcc_page_claimed`, and
 * PeepSoIntegration::onPageClaimed hands an unowned page (post_author 0) and
 * its followers to the claimer.
 *
 * The fix resolves the real class AND refuses non-exclusive roles in
 * ClaimService::claim(), the page route's only entry. These cases run the real
 * endpoint against a real database, with only the on-chain role lookup
 * scripted (tests/Stubs/blockchain-query-role-stub.php, at the real FQN).
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(PagesEndpoint::class)]
#[CoversClass(ClaimService::class)]
final class CollectionHolderCannotClaimPageIntegrationTest extends TestCase
{
    private const USER_ID       = 150;
    private const OTHER_USER_ID = 151;
    private const PAGE          = 3201;

    private const HOLDER_WALLET  = '0x1111111111111111111111111111111111111111';
    private const CREATOR_WALLET = '0x2222222222222222222222222222222222222222';

    private int $evmChain = 0;

    protected function setUp(): void
    {
        // The scripted role lookup FIRST, before anything can autoload the real class.
        require_once dirname(__DIR__) . '/Stubs/blockchain-query-role-stub.php';
        require_once dirname(__DIR__) . '/Stubs/page-claim-endpoint-stubs.php';

        $GLOBALS['__bcc_actions']      = [];
        $GLOBALS['__bcc_posts']        = [];
        $GLOBALS['__bcc_current_user'] = self::USER_ID;
        $GLOBALS['__bcc_bqs_roles']    = [];
        $GLOBALS['__bcc_bqs_calls']    = [];
        Logger::reset();

        global $wpdb;
        $chain = $wpdb->get_var(
            "SELECT id FROM `{$wpdb->prefix}bcc_chains` WHERE chain_type = 'evm' ORDER BY id ASC LIMIT 1"
        );
        self::assertNotNull($chain, 'the bootstrap must seed an EVM chain');
        $this->evmChain = (int) $chain;

        $post              = new \WP_Post();
        $post->ID          = self::PAGE;
        $post->post_type   = 'peepso-page';
        $post->post_status = 'publish';
        $post->post_author = 0; // an unowned placeholder page: the takeover target
        $GLOBALS['__bcc_posts'][self::PAGE] = $post;
    }

    // ── the takeover, refused ───────────────────────────────────────────

    public function testAHolderIsRefusedAndNothingIsRecordedMirroredOrFired(): void
    {
        $collectionId = $this->boundCollection();
        $this->insertWallet(self::USER_ID, self::HOLDER_WALLET);
        $GLOBALS['__bcc_bqs_roles'][self::HOLDER_WALLET] = 'holder';

        $before   = $this->snapshot();
        $response = $this->claim($collectionId);

        self::assertNotSame([], $GLOBALS['__bcc_bqs_calls'], 'the real role lookup path must be reached (namespace fix)');

        self::assertSame(403, $response->get_status());
        $error = $this->errorOf($response);
        self::assertSame('bcc_forbidden', $error['code']);
        self::assertSame("Holding an NFT doesn't make you this collection's creator.", $error['message']);
        self::assertSame('holder_not_owner', $error['data']['reason'] ?? null);

        self::assertSame($before, $this->snapshot(), 'a holder refusal writes no claim row, collection or page');
        self::assertSame([], $GLOBALS['__bcc_actions'], 'no claim_verified, no page_claimed');
        self::assertSame(0, $GLOBALS['__bcc_posts'][self::PAGE]->post_author, 'the page stays unowned');

        $audits = array_filter(Logger::$lines, static fn (array $l): bool => $l['level'] === 'audit');
        self::assertSame([], array_values($audits), 'no page_claimed audit line');
    }

    /** The F-154 shape of the attack: many holders, still nobody owns the page. */
    public function testRepeatedHolderAttemptsNeverProduceAnOwner(): void
    {
        $collectionId = $this->boundCollection();
        $this->insertWallet(self::USER_ID, self::HOLDER_WALLET);
        $GLOBALS['__bcc_bqs_roles'][self::HOLDER_WALLET] = 'holder';

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(403, $this->claim($collectionId)->get_status());
        }

        self::assertSame([], $this->verifiedClaims(self::USER_ID));
        self::assertSame([], $GLOBALS['__bcc_actions']);
    }

    // ── the legitimate claim still works ────────────────────────────────

    public function testTheCreatorClaimsThePage(): void
    {
        $collectionId = $this->boundCollection();
        $this->insertWallet(self::USER_ID, self::CREATOR_WALLET);
        $GLOBALS['__bcc_bqs_roles'][self::CREATOR_WALLET] = 'creator';

        $response = $this->claim($collectionId);

        self::assertSame(200, $response->get_status());
        self::assertSame(
            [['collection', $collectionId, 'creator'], ['page', self::PAGE, 'creator']],
            $this->verifiedClaims(self::USER_ID)
        );
        self::assertSame(
            [
                ['bcc_onchain_claim_verified', self::USER_ID, 'collection', $collectionId, 'creator'],
                ['bcc_page_claimed', self::USER_ID, self::PAGE, 'collection', $collectionId, 'creator'],
            ],
            $GLOBALS['__bcc_actions']
        );
    }

    /**
     * Creator wins across ALL wallets: a holder wallet listed first must not
     * shadow the user's creator wallet (the old loop returned on the first
     * role it saw, which — now that holders are refused — would lock the real
     * creator out of their own collection).
     */
    public function testAHolderWalletListedFirstDoesNotShadowTheCreatorWallet(): void
    {
        $collectionId = $this->boundCollection();
        $this->insertWallet(self::USER_ID, self::HOLDER_WALLET);  // lower id → listed first
        $this->insertWallet(self::USER_ID, self::CREATOR_WALLET);
        $GLOBALS['__bcc_bqs_roles'][self::HOLDER_WALLET]  = 'holder';
        $GLOBALS['__bcc_bqs_roles'][self::CREATOR_WALLET] = 'creator';

        $response = $this->claim($collectionId);

        self::assertSame(200, $response->get_status());
        self::assertSame(
            [['collection', $collectionId, 'creator'], ['page', self::PAGE, 'creator']],
            $this->verifiedClaims(self::USER_ID)
        );
        // Prove the scenario: the holder wallet really was looked up FIRST.
        self::assertSame(self::HOLDER_WALLET, $GLOBALS['__bcc_bqs_calls'][0][1] ?? null);
    }

    /**
     * The fix makes collection matching LIVE: a synchronous RPC per wallet.
     * The per-attempt fan-out is capped at 3 wallets on the collection's
     * chain, so one request cannot hold a worker for minutes.
     */
    public function testCollectionMatchingChecksAtMostThreeWallets(): void
    {
        $collectionId = $this->boundCollection();
        $wallets = [
            '0x3333333333333333333333333333333333333333',
            '0x4444444444444444444444444444444444444444',
            '0x5555555555555555555555555555555555555555',
            self::CREATOR_WALLET, // 4th: past the cap
        ];
        foreach ($wallets as $w) {
            $this->insertWallet(self::USER_ID, $w);
        }
        $GLOBALS['__bcc_bqs_roles'][self::CREATOR_WALLET] = 'creator';

        $response = $this->claim($collectionId);

        self::assertCount(3, $GLOBALS['__bcc_bqs_calls'], 'exactly three role lookups');
        self::assertSame(403, $response->get_status());
        self::assertSame('not_operator', $this->errorOf($response)['data']['reason'] ?? null);
        self::assertSame([], $this->verifiedClaims(self::USER_ID));
    }

    // ── data.reason on the service-side refusals (v1.127) ───────────────

    /**
     * ClaimService::claim() has its own §M suspension gate. The endpoint's
     * gate normally answers first, but the service's refusal used to fall
     * through to the wallet-mismatch default — a suspended member told to try
     * another wallet. Each service refusal now maps to its own reason.
     */
    public function testServiceRefusalsMapToTheirOwnReasons(): void
    {
        $map = new \ReflectionMethod(PagesEndpoint::class, 'mapClaimFailure');
        $map->setAccessible(true);

        $cases = [
            'suspended'        => [['success' => false, 'error' => 'suspended', 'message' => 'Your account is suspended.'], 'Your account is suspended.'],
            'holder_not_owner' => [['success' => false, 'error' => 'holder_not_owner', 'message' => 'x'], "Holding an NFT doesn't make you this collection's creator."],
            'not_operator'     => [['success' => false, 'message' => 'No match.'], 'Your connected wallet does not match the operator for this entity.'],
        ];
        // A forbidden cause that is NOT the explicit sentinel must not be
        // labelled a suspension: it falls to the wallet-mismatch default.
        $cases['not_operator (unknown forbidden)'] = [['success' => false, 'error' => 'bcc_forbidden', 'message' => 'x'], 'Your connected wallet does not match the operator for this entity.'];

        foreach ($cases as $label => [$result, $message]) {
            $reason = explode(' ', $label)[0];
            /** @var \WP_REST_Response $response */
            $response = $map->invoke(null, $result);
            $error    = $this->errorOf($response);

            self::assertSame(403, $response->get_status(), $reason);
            self::assertSame('bcc_forbidden', $error['code'], $reason);
            self::assertSame($message, $error['message'], $reason);
            self::assertSame($reason, $error['data']['reason'] ?? null, $reason);
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function claim(int $collectionId): \WP_REST_Response
    {
        return (new PagesEndpoint())->claim(new \WP_REST_Request([
            'id'          => self::PAGE,
            'entity_type' => 'collection',
            'entity_id'   => $collectionId,
        ]));
    }

    /** A collection bound to PAGE through the wallet-link leg, as the page binding check reads it. */
    private function boundCollection(): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
            'user_id'        => self::OTHER_USER_ID,
            'post_id'        => self::PAGE,
            'wallet_address' => '0xlinkfixture' . uniqid(),
            'chain_id'       => $this->evmChain,
            'wallet_type'    => 'validator',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNotFalse($ok, 'link wallet fixture: ' . $wpdb->last_error);
        $linkId = (int) $wpdb->insert_id;

        $ok = $wpdb->insert($wpdb->prefix . 'bcc_onchain_collections', [
            'wallet_link_id'   => $linkId,
            'contract_address' => '0xc0ffee' . substr(md5(uniqid('', true)), 0, 34),
            'chain_id'         => $this->evmChain,
            'collection_name'  => 'Fixture Collection',
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        self::assertNotFalse($ok, 'collection fixture: ' . $wpdb->last_error);
        $collectionId = (int) $wpdb->insert_id;

        self::assertTrue(ClaimService::isEntityBoundToPage('collection', $collectionId, self::PAGE));

        return $collectionId;
    }

    private int $walletSeq = 0;

    /**
     * WalletRepository orders a user's wallets `is_primary DESC, created_at
     * ASC`. Fixtures inserted in the same second would TIE and come back in
     * any order, so each one gets a strictly later created_at: insertion order
     * is the order the matcher sees.
     */
    private function insertWallet(int $userId, string $address): void
    {
        global $wpdb;
        $this->walletSeq++;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
            'user_id'        => $userId,
            'post_id'        => 0,
            'wallet_address' => $address,
            'chain_id'       => $this->evmChain,
            'wallet_type'    => 'user',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
            'created_at'     => gmdate('Y-m-d H:i:s', time() - 3600 + $this->walletSeq * 60),
        ]);
        self::assertNotFalse($ok, 'wallet fixture: ' . $wpdb->last_error);
    }

    /** @return array<string, mixed> */
    private function errorOf(\WP_REST_Response $response): array
    {
        $data = $response->get_data();
        self::assertIsArray($data['error'] ?? null, 'expected an error envelope');

        return $data['error'];
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        global $wpdb;
        $out = [];
        foreach (
            [
                $wpdb->prefix . 'bcc_onchain_claims'      => 'id',
                $wpdb->prefix . 'bcc_wallet_links'        => 'id',
                $wpdb->prefix . 'bcc_onchain_collections' => 'id',
                $wpdb->postmeta                           => 'meta_id',
            ] as $table => $order
        ) {
            $rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY `{$order}` ASC", ARRAY_A);
            self::assertIsArray($rows, "snapshot of {$table} must really run");
            self::assertSame('', (string) $wpdb->last_error, "snapshot of {$table} must really run");
            $out[$table] = $rows;
        }

        return $out;
    }

    /** @return list<array{0: string, 1: int, 2: string}> */
    private function verifiedClaims(int $userId): array
    {
        global $wpdb;
        /** @var list<object{entity_type: string, entity_id: string, claim_role: string}> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT entity_type, entity_id, claim_role FROM `{$wpdb->prefix}bcc_onchain_claims`
              WHERE user_id = %d AND status = 'verified' ORDER BY id ASC",
            $userId
        ));

        return array_map(
            static fn (object $r): array => [$r->entity_type, (int) $r->entity_id, $r->claim_role],
            $rows
        );
    }
}
