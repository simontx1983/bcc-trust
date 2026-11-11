<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\REST\PagesEndpoint;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Services\ClaimService;
use BCC\Trust\Onchain\Support\Bech32;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A valid signature for validator A must never grant validator B's page.
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────
 * POST /pages/:id/claim takes the page id from the path and entity_id from the
 * body, and never checked that the two belong together. The wallet proof
 * covers the entity only, so the operator of A could send B's page id with A's
 * entity id, pass the proof honestly, and collect B's page mirror row, its
 * authorship and its followers through `bcc_page_claimed`.
 *
 * These tests call the real endpoint with real wallets (bech32 pairs built
 * from the same bytes, as a Cosmos chain derives them) against a real database.
 * Each refusal asserts on the tables a claim writes, on the events claim
 * subscribers hang off, and on the `page_claimed` audit line.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(PagesEndpoint::class)]
#[CoversClass(ClaimService::class)]
final class PageClaimBindingIntegrationTest extends TestCase
{
    private const USER_ID       = 140;
    private const OTHER_USER_ID = 141;
    private const PAGE_A        = 3101;
    private const PAGE_B        = 3102;
    private const PAGE_UNBOUND  = 3103;

    private const BYTES_A = '0123456789abcdef0123456789abcdef01234567';
    private const BYTES_B = 'fedcba9876543210fedcba9876543210fedcba98';

    private int $chainOne = 0;
    private int $chainTwo = 0;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/page-claim-endpoint-stubs.php';

        $GLOBALS['__bcc_actions']      = [];
        $GLOBALS['__bcc_posts']        = [];
        $GLOBALS['__bcc_current_user'] = self::USER_ID;
        Logger::reset();

        global $wpdb;
        $chains = $wpdb->get_col(
            "SELECT id FROM `{$wpdb->prefix}bcc_chains` WHERE chain_type = 'cosmos' ORDER BY id ASC LIMIT 2"
        );
        self::assertCount(2, $chains, 'the bootstrap must seed at least two Cosmos chains');
        $this->chainOne = (int) $chains[0];
        $this->chainTwo = (int) $chains[1];

        foreach ([self::PAGE_A, self::PAGE_B, self::PAGE_UNBOUND] as $pageId) {
            $this->insertPage($pageId);
        }
    }

    // ── refusals ────────────────────────────────────────────────────────

    public function testSignatureForValidatorADoesNotGrantValidatorBsPage(): void
    {
        $validatorA = $this->insertValidator(self::BYTES_A, $this->chainOne);
        $validatorB = $this->insertValidator(self::BYTES_B, $this->chainOne);
        $this->bindPlaceholder(self::PAGE_A, $validatorA);
        $this->bindPlaceholder(self::PAGE_B, $validatorB);
        $this->insertWallet(self::USER_ID, self::BYTES_A, $this->chainOne);

        $before   = $this->snapshot();
        $response = $this->claim(self::PAGE_B, 'validator', $validatorA);

        $this->assertNotAttachedRefusal($response);
        $this->assertNoSideEffects($before);
    }

    /**
     * The idempotency pre-check returns 200 before ClaimService runs, so a
     * binding check placed after it would answer "already verified" with
     * page_id B to the operator of A.
     */
    public function testExistingOperatorOfAStillCannotNameBsPage(): void
    {
        $validatorA = $this->insertValidator(self::BYTES_A, $this->chainOne);
        $validatorB = $this->insertValidator(self::BYTES_B, $this->chainOne);
        $this->bindPlaceholder(self::PAGE_A, $validatorA);
        $this->bindPlaceholder(self::PAGE_B, $validatorB);
        $this->insertWallet(self::USER_ID, self::BYTES_A, $this->chainOne);
        self::assertSame(200, $this->claim(self::PAGE_A, 'validator', $validatorA)->get_status());

        $GLOBALS['__bcc_actions'] = [];
        Logger::reset();
        $before   = $this->snapshot();
        $response = $this->claim(self::PAGE_B, 'validator', $validatorA);

        $this->assertNotAttachedRefusal($response);
        $this->assertNoSideEffects($before);
    }

    public function testPageWithNoBindingCannotBeClaimedThroughAnyValidator(): void
    {
        $validatorA = $this->insertValidator(self::BYTES_A, $this->chainOne);
        $this->bindPlaceholder(self::PAGE_A, $validatorA);
        $this->insertWallet(self::USER_ID, self::BYTES_A, $this->chainOne);

        $before   = $this->snapshot();
        $response = $this->claim(self::PAGE_UNBOUND, 'validator', $validatorA);

        $this->assertNotAttachedRefusal($response);
        $this->assertNoSideEffects($before);
    }

    public function testWalletLinkBindingOutranksAStalePlaceholderBinding(): void
    {
        $validatorA = $this->insertValidator(self::BYTES_A, $this->chainOne);
        $validatorB = $this->insertValidator(
            self::BYTES_B,
            $this->chainOne,
            $this->insertLinkWallet(self::OTHER_USER_ID, self::PAGE_B)
        );
        $this->bindPlaceholder(self::PAGE_B, $validatorA);
        $this->insertWallet(self::USER_ID, self::BYTES_A, $this->chainOne);

        self::assertTrue(ClaimService::isEntityBoundToPage('validator', $validatorB, self::PAGE_B));

        $before   = $this->snapshot();
        $response = $this->claim(self::PAGE_B, 'validator', $validatorA);

        $this->assertNotAttachedRefusal($response);
        $this->assertNoSideEffects($before);
    }

    public function testCollectionMustBeLinkedToThatPage(): void
    {
        $linkedHere  = $this->insertCollection($this->insertLinkWallet(self::OTHER_USER_ID, self::PAGE_A));
        $linkedThere = $this->insertCollection($this->insertLinkWallet(self::OTHER_USER_ID, self::PAGE_B));
        $unlinked    = $this->insertCollection(null);

        self::assertTrue(ClaimService::isEntityBoundToPage('collection', $linkedHere, self::PAGE_A));

        foreach ([$linkedThere, $unlinked] as $collectionId) {
            $before   = $this->snapshot();
            $response = $this->claim(self::PAGE_A, 'collection', $collectionId);

            $this->assertNotAttachedRefusal($response);
            $this->assertNoSideEffects($before);
        }
    }

    /**
     * The pre-existing wallet gate still answers on a correctly bound pair, with
     * its own message. Without this, every assertion above could pass on an
     * endpoint that refuses everything.
     */
    public function testRightPageWithTheWrongWalletIsRefusedByTheWalletGate(): void
    {
        $validatorB = $this->insertValidator(self::BYTES_B, $this->chainOne);
        $this->bindPlaceholder(self::PAGE_B, $validatorB);
        $this->insertWallet(self::USER_ID, self::BYTES_A, $this->chainOne);

        $before   = $this->snapshot();
        $response = $this->claim(self::PAGE_B, 'validator', $validatorB);

        self::assertSame(403, $response->get_status());
        self::assertStringContainsString('does not match the operator', $this->errorOf($response)['message']);
        $this->assertNoSideEffects($before);
    }

    // ── successful claims ───────────────────────────────────────────────

    public function testValidClaimWritesBothRowsAndFiresEachEventOnce(): void
    {
        $validatorA = $this->insertValidator(self::BYTES_A, $this->chainOne);
        $this->bindPlaceholder(self::PAGE_A, $validatorA);
        $this->insertWallet(self::USER_ID, self::BYTES_A, $this->chainOne);

        // What the card offers as claim_target must pass the new gate.
        $target = ValidatorRepository::findFirstByPageId(self::PAGE_A);
        self::assertNotNull($target);
        self::assertSame($validatorA, (int) $target->validator_id);

        $response = $this->claim(self::PAGE_A, 'validator', (int) $target->validator_id);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data()['data'];
        self::assertSame('verified', $data['status']);
        self::assertSame(self::PAGE_A, $data['page_id']);
        self::assertSame($validatorA, $data['entity_id']);

        self::assertSame(
            [['validator', $validatorA, 'operator'], ['page', self::PAGE_A, 'operator']],
            $this->verifiedClaims(self::USER_ID)
        );
        self::assertSame(
            [
                ['bcc_onchain_claim_verified', self::USER_ID, 'validator', $validatorA, 'operator'],
                ['bcc_page_claimed', self::USER_ID, self::PAGE_A, 'validator', $validatorA, 'operator'],
            ],
            $GLOBALS['__bcc_actions']
        );
    }

    public function testResubmittingAValidClaimChangesNothing(): void
    {
        $validatorA = $this->insertValidator(self::BYTES_A, $this->chainOne);
        $this->bindPlaceholder(self::PAGE_A, $validatorA);
        $this->insertWallet(self::USER_ID, self::BYTES_A, $this->chainOne);
        self::assertSame(200, $this->claim(self::PAGE_A, 'validator', $validatorA)->get_status());

        // ⚠ Model what priority 20 does in production. This harness registers
        // no `bcc_page_claimed` subscribers, so without this the page stays
        // author 0 for ever and every re-submit legitimately re-fires the
        // event to finish the transfer — see the next case.
        $GLOBALS['__bcc_posts'][self::PAGE_A]->post_author = self::USER_ID;

        $GLOBALS['__bcc_actions'] = [];
        Logger::reset();
        $before   = $this->snapshot();
        $response = $this->claim(self::PAGE_A, 'validator', $validatorA);

        self::assertSame(200, $response->get_status());
        self::assertSame('already_verified', $response->get_data()['data']['status']);
        $this->assertNoSideEffects($before);
    }

    /**
     * The self-repair path, end to end against the real endpoint: the claim
     * row is verified but the ownership transfer never completed, so a
     * re-submit must re-fire `bcc_page_claimed` — exactly once, writing no
     * second claim row.
     *
     * The dispatch runs under the real `bcc_page_claim_event:<id>` advisory
     * lock here — bcc-core's AdvisoryLock is autoloaded in this harness, so
     * this is a real `GET_LOCK` against the test server. What that buys is
     * that the lock does not BREAK the claim path: it is acquired, the
     * dispatch proceeds, and repeated submits still behave.
     *
     * ⚠⚠ It does NOT prove the release, and a first version of this comment
     * wrongly said it did. Removing `AdvisoryLock::release()` leaves this
     * test green, measured — because `GET_LOCK` is REENTRANT within one
     * connection and every request in this suite shares one. The release is
     * pinned by PageClaimEventDispatchTest's trace ordering and its
     * throwing-subscriber case, both of which a mutation kills.
     *
     * ⚠⚠ Nor does it cover the IN-LOCK ownership re-check, also measured:
     * replacing that condition with `false` leaves this whole suite green.
     * It cannot be otherwise — the outer `$needsTransfer` fast path already
     * decides correctly whenever requests are sequential, and this harness
     * runs one request at a time on one connection. The re-check exists for
     * the case where the two disagree, which only concurrency produces, so
     * PageClaimEventDispatchTest (which drives the dispatcher directly) is
     * its only coverage.
     */
    public function testResubmittingAClaimWhoseTransferNeverCompletedRefiresTheEventOnce(): void
    {
        $validatorA = $this->insertValidator(self::BYTES_A, $this->chainOne);
        $this->bindPlaceholder(self::PAGE_A, $validatorA);
        $this->insertWallet(self::USER_ID, self::BYTES_A, $this->chainOne);
        self::assertSame(200, $this->claim(self::PAGE_A, 'validator', $validatorA)->get_status());

        // post_author is still 0 — the transfer never happened.
        self::assertSame(0, $GLOBALS['__bcc_posts'][self::PAGE_A]->post_author);

        $GLOBALS['__bcc_actions'] = [];
        $before   = $this->snapshot();
        $response = $this->claim(self::PAGE_A, 'validator', $validatorA);

        self::assertSame(200, $response->get_status());
        self::assertSame('already_verified', $response->get_data()['data']['status']);
        self::assertSame(
            [['bcc_page_claimed', self::USER_ID, self::PAGE_A, 'validator', $validatorA, 'operator']],
            $GLOBALS['__bcc_actions'],
            'the interrupted transfer must be re-driven exactly once'
        );
        self::assertSame(
            $before[$GLOBALS['wpdb']->prefix . 'bcc_onchain_claims'],
            $this->snapshot()[$GLOBALS['wpdb']->prefix . 'bcc_onchain_claims'],
            'a re-fire must not write a second claim row'
        );

        // And again: an unrepaired page keeps being re-driven on each submit,
        // one dispatch per submit, never two.
        $GLOBALS['__bcc_actions'] = [];
        $this->claim(self::PAGE_A, 'validator', $validatorA);
        self::assertCount(1, $GLOBALS['__bcc_actions'], 'one submit, one dispatch');

        // Once the transfer lands, the re-fire stops on its own.
        $GLOBALS['__bcc_posts'][self::PAGE_A]->post_author = self::USER_ID;
        $GLOBALS['__bcc_actions'] = [];
        $this->claim(self::PAGE_A, 'validator', $validatorA);
        self::assertSame([], $GLOBALS['__bcc_actions'], 'an owned page has nothing left to repair');
    }

    /**
     * One account, one key, a validator on each of two chains: each page is
     * claimable through its own validator and neither through the other's.
     */
    public function testOneOperatorClaimsPagesOnTwoChains(): void
    {
        $validatorOne = $this->insertValidator(self::BYTES_A, $this->chainOne);
        $validatorTwo = $this->insertValidator(self::BYTES_A, $this->chainTwo);
        $this->bindPlaceholder(self::PAGE_A, $validatorOne);
        $this->bindPlaceholder(self::PAGE_B, $validatorTwo);
        $this->insertWallet(self::USER_ID, self::BYTES_A, $this->chainOne);
        $this->insertWallet(self::USER_ID, self::BYTES_A, $this->chainTwo);

        $first  = $this->claim(self::PAGE_A, 'validator', $validatorOne);
        $second = $this->claim(self::PAGE_B, 'validator', $validatorTwo);

        self::assertSame('verified', $first->get_data()['data']['status']);
        self::assertSame('verified', $second->get_data()['data']['status']);
        self::assertSame(
            [
                ['page', self::PAGE_A, 'operator'],
                ['page', self::PAGE_B, 'operator'],
                ['validator', $validatorOne, 'operator'],
                ['validator', $validatorTwo, 'operator'],
            ],
            $this->verifiedClaims(self::USER_ID, true)
        );

        foreach ([[self::PAGE_A, $validatorTwo], [self::PAGE_B, $validatorOne]] as [$pageId, $validatorId]) {
            $GLOBALS['__bcc_actions'] = [];
            Logger::reset();
            $before   = $this->snapshot();
            $response = $this->claim($pageId, 'validator', $validatorId);

            $this->assertNotAttachedRefusal($response);
            $this->assertNoSideEffects($before);
        }
    }

    // ── suspension (F-109) ──────────────────────────────────────────────

    /**
     * Every other case here runs a user with NO user_info row, which must
     * read as NOT suspended (prod has users without one). This is the
     * complement: the same otherwise-valid claim, made by a user whose
     * row says is_suspended = 1, is refused before anything is written.
     */
    public function testASuspendedUserCannotClaimAnOtherwiseValidPage(): void
    {
        $validatorA = $this->insertValidator(self::BYTES_A, $this->chainOne);
        $this->bindPlaceholder(self::PAGE_A, $validatorA);
        $this->insertWallet(self::USER_ID, self::BYTES_A, $this->chainOne);

        global $wpdb;
        $userInfo = $wpdb->prefix . 'bcc_trust_user_info';
        $wpdb->query($wpdb->prepare("DELETE FROM `{$userInfo}` WHERE user_id = %d", self::USER_ID));
        $inserted = $wpdb->insert($userInfo, [
            'user_id'      => self::USER_ID,
            'user_login'   => 'user' . self::USER_ID,
            'user_email'   => 'user' . self::USER_ID . '@example.test',
            'display_name' => 'User ' . self::USER_ID,
            'is_suspended' => 1,
        ]);
        self::assertSame(1, $inserted, (string) $wpdb->last_error);

        try {
            $before   = $this->snapshot();
            $response = $this->claim(self::PAGE_A, 'validator', $validatorA);

            self::assertSame(403, $response->get_status());
            self::assertSame('bcc_forbidden', $this->errorOf($response)['code']);
            self::assertSame('Your account is suspended.', $this->errorOf($response)['message']);
            $this->assertNoSideEffects($before);
            self::assertSame([], $this->verifiedClaims(self::USER_ID));
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM `{$userInfo}` WHERE user_id = %d", self::USER_ID));
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function claim(int $pageId, string $entityType, int $entityId): \WP_REST_Response
    {
        return (new PagesEndpoint())->claim(new \WP_REST_Request([
            'id'          => $pageId,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
        ]));
    }

    /** @return array<string, mixed> */
    private function errorOf(\WP_REST_Response $response): array
    {
        $data = $response->get_data();
        self::assertIsArray($data['error'] ?? null, 'expected an error envelope');

        return $data['error'];
    }

    private function assertNotAttachedRefusal(\WP_REST_Response $response): void
    {
        self::assertSame(403, $response->get_status());
        $error = $this->errorOf($response);
        self::assertSame('bcc_forbidden', $error['code']);
        self::assertSame('That entity is not attached to this page.', $error['message']);
    }

    /** @param array<string, mixed> $before */
    private function assertNoSideEffects(array $before): void
    {
        self::assertSame($before, $this->snapshot(), 'a refused claim must not change any row');
        self::assertSame([], $GLOBALS['__bcc_actions'], 'a refused claim must not fire any event');

        $audits = array_filter(Logger::$lines, static fn (array $l): bool => $l['level'] === 'audit');
        self::assertSame([], array_values($audits), 'a refused claim must not write a claim audit line');
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
                $wpdb->prefix . 'bcc_onchain_validators'  => 'id',
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
    private function verifiedClaims(int $userId, bool $sorted = false): array
    {
        global $wpdb;
        $order = $sorted ? 'entity_type ASC, entity_id ASC' : 'id ASC';
        /** @var list<object{entity_type: string, entity_id: string, claim_role: string}> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT entity_type, entity_id, claim_role FROM `{$wpdb->prefix}bcc_onchain_claims`
              WHERE user_id = %d AND status = 'verified' ORDER BY {$order}",
            $userId
        ));

        return array_map(
            static fn (object $r): array => [$r->entity_type, (int) $r->entity_id, $r->claim_role],
            $rows
        );
    }

    private function insertPage(int $pageId): void
    {
        $post              = new \WP_Post();
        $post->ID          = $pageId;
        $post->post_type   = 'peepso-page';
        $post->post_status = 'publish';

        $GLOBALS['__bcc_posts'][$pageId] = $post;
    }

    private function bindPlaceholder(int $pageId, int $validatorId): void
    {
        self::assertTrue(update_post_meta($pageId, '_bcc_onchain_validator_id', (string) $validatorId));
    }

    private function insertValidator(string $hexBytes, int $chainId, ?int $walletLinkId = null): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
            'wallet_link_id'   => $walletLinkId,
            'operator_address' => $this->bech32('cosmosvaloper', $hexBytes),
            'chain_id'         => $chainId,
            'moniker'          => 'Fixture ' . $hexBytes,
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        self::assertNotFalse($ok, 'validator fixture: ' . $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    private function insertCollection(?int $walletLinkId): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_onchain_collections', [
            'wallet_link_id'   => $walletLinkId,
            'contract_address' => '0xfixture' . uniqid(),
            'chain_id'         => $this->chainOne,
            'collection_name'  => 'Fixture Collection',
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        self::assertNotFalse($ok, 'collection fixture: ' . $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    /** A verified wallet the way a completed wallet link leaves it: not page-bound. */
    private function insertWallet(int $userId, string $hexBytes, int $chainId): void
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
            'user_id'        => $userId,
            'post_id'        => 0,
            'wallet_address' => $this->bech32('cosmos', $hexBytes),
            'chain_id'       => $chainId,
            'wallet_type'    => 'user',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNotFalse($ok, 'wallet fixture: ' . $wpdb->last_error);
    }

    /** The legacy wallet-link leg of a page binding. */
    private function insertLinkWallet(int $userId, int $pageId): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
            'user_id'        => $userId,
            'post_id'        => $pageId,
            'wallet_address' => 'cosmos1linkfixture' . uniqid(),
            'chain_id'       => $this->chainOne,
            'wallet_type'    => 'validator',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNotFalse($ok, 'link wallet fixture: ' . $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    private function bech32(string $hrp, string $hexBytes): string
    {
        $address = Bech32::encode($hrp, (string) hex2bin($hexBytes));
        self::assertIsString($address);

        return $address;
    }
}
