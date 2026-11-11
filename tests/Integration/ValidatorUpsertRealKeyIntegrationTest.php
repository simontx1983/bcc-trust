<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-141 — ValidatorRepository::upsert must look rows up by the table's REAL
 * unique key, uq_chain_operator (chain_id, operator_address).
 *
 * It used to include wallet_link_id in the lookup, so a validator the indexer
 * had already bulk-inserted (wallet_link_id NULL) was missed. The plain INSERT
 * then collided with the unique key, and the method returned a STALE
 * $wpdb->insert_id from an unrelated earlier insert. WalletSeedService (the
 * only caller) discarded the result, so the validator was silently never
 * linked to the verified wallet.
 *
 * #430 review M1 — ownership is read THROUGH wallet_links. Disconnecting a
 * wallet hard-deletes its wallet_links row but leaves
 * onchain_validators.wallet_link_id pointing at it; a dangling owner must
 * count as unlinked, or a re-claim after unlink is refused forever.
 *
 * Every link id below is a REAL wallet_links row: with the owner read through
 * the JOIN, a made-up id would be a dangling owner and silently test the
 * wrong branch.
 *
 * Runs the REAL repository against the REAL validators schema.
 */
#[Group('integration')]
#[Group('mariadb')]
final class ValidatorUpsertRealKeyIntegrationTest extends TestCase
{
    private const CHAIN = 990001; // a chain id no fixture elsewhere uses

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
        parent::tearDown();
    }

    public function testABulkIndexedValidatorIsClaimedByTheVerifiedWallet(): void
    {
        $bulkId = $this->insertBulkIndexed('cosmosvaloper1aaa');
        $link   = $this->link(41, 'cosmos1wallet41');

        $id = ValidatorRepository::upsert($this->data('cosmosvaloper1aaa', 'Alpha'), $link);

        self::assertSame($bulkId, $id, 'the existing bulk-indexed row is the one updated');
        $row = $this->row('cosmosvaloper1aaa');
        self::assertSame($link, (int) $row->wallet_link_id, 'and it is now linked to the verified wallet');
        self::assertSame('Alpha', $row->moniker);
        self::assertSame(1, $this->countRows(), 'no duplicate row');
    }

    public function testARowAlreadyLinkedToTheSameWalletIsRefreshed(): void
    {
        $link  = $this->link(42, 'cosmos1wallet42');
        $first = ValidatorRepository::upsert($this->data('cosmosvaloper1bbb', 'Old'), $link);
        $again = ValidatorRepository::upsert($this->data('cosmosvaloper1bbb', 'New'), $link);

        self::assertIsInt($first);
        self::assertSame($first, $again);
        self::assertSame('New', $this->row('cosmosvaloper1bbb')->moniker);
    }

    public function testARowLinkedToAnotherLiveWalletIsNotReHomed(): void
    {
        $ownerLink    = $this->link(43, 'cosmos1wallet43');
        $intruderLink = $this->link(44, 'cosmos1wallet44');
        $owner = ValidatorRepository::upsert($this->data('cosmosvaloper1ccc', 'Owner'), $ownerLink);
        self::assertIsInt($owner);

        $result = ValidatorRepository::upsert($this->data('cosmosvaloper1ccc', 'Intruder'), $intruderLink);

        self::assertFalse($result, 'another live link\'s validator is refused, not taken over');
        $row = $this->row('cosmosvaloper1ccc');
        self::assertSame($ownerLink, (int) $row->wallet_link_id);
        self::assertSame('Owner', $row->moniker);
    }

    /**
     * #430 M1 — link → unlink → re-link the same address. The disconnect
     * deletes the wallet_links row only; the validator keeps the dead id.
     */
    public function testAReClaimAfterUnlinkSucceeds(): void
    {
        $first = $this->link(47, 'cosmos1wallet47');
        $id    = ValidatorRepository::upsert($this->data('cosmosvaloper1fff', 'Before'), $first);
        self::assertIsInt($id);

        self::assertTrue(WalletRepository::delete($first, 47), 'fixture: the wallet is disconnected');
        self::assertSame($first, (int) $this->row('cosmosvaloper1fff')->wallet_link_id,
            'precondition: the validator still names the deleted link');

        $second = $this->link(47, 'cosmos1wallet47');
        self::assertNotSame($first, $second);

        $again = ValidatorRepository::upsert($this->data('cosmosvaloper1fff', 'After'), $second);

        self::assertSame($id, $again, 'the same row is re-claimed, not refused');
        $row = $this->row('cosmosvaloper1fff');
        self::assertSame($second, (int) $row->wallet_link_id, 'and the dangling owner is healed');
        self::assertSame('After', $row->moniker);
        self::assertSame(1, $this->countRows());
    }

    /** A dangling owner is claimable by ANY live link, not only the old user's. */
    public function testADanglingOwnerIsClaimableByAnotherUser(): void
    {
        $gone = $this->link(48, 'cosmos1wallet48');
        ValidatorRepository::upsert($this->data('cosmosvaloper1ggg', 'Gone'), $gone);
        WalletRepository::delete($gone, 48);

        $other  = $this->link(49, 'cosmos1wallet49');
        $result = ValidatorRepository::upsert($this->data('cosmosvaloper1ggg', 'New owner'), $other);

        self::assertIsInt($result);
        self::assertSame($other, (int) $this->row('cosmosvaloper1ggg')->wallet_link_id);
    }

    public function testANewValidatorIsInsertedAndItsOwnIdReturned(): void
    {
        $id = ValidatorRepository::upsert($this->data('cosmosvaloper1ddd', 'Delta'), $this->link(45, 'cosmos1wallet45'));

        self::assertIsInt($id);
        self::assertSame($id, (int) $this->row('cosmosvaloper1ddd')->id);
    }

    /**
     * A failed INSERT must return false — never the stale insert_id left over
     * from whatever was inserted last on the connection.
     */
    public function testAFailedInsertReturnsFalseNotAStaleId(): void
    {
        global $wpdb;
        $link    = $this->link(46, 'cosmos1wallet46');
        $staleId = $this->insertBulkIndexed('cosmosvaloper1zzz'); // sets insert_id

        $wpdb->failQueriesMatching = '/^\s*INSERT INTO `?\S*onchain_validators/i';
        $result = ValidatorRepository::upsert($this->data('cosmosvaloper1eee', 'Echo'), $link);
        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
        $wpdb->clearFaultInjection();

        self::assertFalse($result);
        self::assertNotSame($staleId, $result);
    }

    /** The ownership read failing is a failure, never "unowned, claim it". */
    public function testAFailedOwnershipReadReturnsFalse(): void
    {
        global $wpdb;
        $ownerLink = $this->link(50, 'cosmos1wallet50');
        ValidatorRepository::upsert($this->data('cosmosvaloper1hhh', 'Owner'), $ownerLink);
        $intruder = $this->link(51, 'cosmos1wallet51');

        $wpdb->failQueriesMatching = '/LEFT JOIN `?\S*wallet_links/i';
        $result = ValidatorRepository::upsert($this->data('cosmosvaloper1hhh', 'Intruder'), $intruder);
        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
        $wpdb->clearFaultInjection();

        self::assertFalse($result);
        self::assertSame($ownerLink, (int) $this->row('cosmosvaloper1hhh')->wallet_link_id);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function data(string $operator, string $moniker): array
    {
        return [
            'chain_id'         => self::CHAIN,
            'operator_address' => $operator,
            'moniker'          => $moniker,
            'status'           => 'active',
        ];
    }

    /** A REAL wallet_links row on the test chain; returns its id. */
    private function link(int $userId, string $address): int
    {
        global $wpdb;
        $wpdb->insert(
            WalletRepository::table(),
            [
                'user_id'        => $userId,
                'post_id'        => 0,
                'wallet_address' => $address,
                'chain_id'       => self::CHAIN,
            ],
            ['%d', '%d', '%s', '%d']
        );
        self::assertSame('', $wpdb->last_error, 'fixture: wallet link');
        return (int) $wpdb->insert_id;
    }

    private function insertBulkIndexed(string $operator): int
    {
        global $wpdb;
        $table = $this->table();
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$table}` (operator_address, chain_id, expires_at, wallet_link_id)
             VALUES (%s, %d, UTC_TIMESTAMP(), NULL)",
            $operator,
            self::CHAIN
        ));
        self::assertSame('', $wpdb->last_error, 'fixture: bulk-indexed validator');
        return (int) $wpdb->insert_id;
    }

    private function row(string $operator): object
    {
        global $wpdb;
        $table = $this->table();
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT id, wallet_link_id, moniker FROM `{$table}` WHERE chain_id = %d AND operator_address = %s",
            self::CHAIN,
            $operator
        ));
        self::assertIsObject($row);
        return $row;
    }

    private function countRows(): int
    {
        global $wpdb;
        $table = $this->table();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE chain_id = %d", self::CHAIN));
    }

    private function table(): string
    {
        return \BCC\Core\DB\DB::table('onchain_validators');
    }

    private function purge(): void
    {
        global $wpdb;
        $table = $this->table();
        $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE chain_id = %d", self::CHAIN));
        $wallets = WalletRepository::table();
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wallets}` WHERE chain_id = %d", self::CHAIN));
    }
}
