<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ValidatorRepository;
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

        $id = ValidatorRepository::upsert($this->data('cosmosvaloper1aaa', 'Alpha'), 41);

        self::assertSame($bulkId, $id, 'the existing bulk-indexed row is the one updated');
        $row = $this->row('cosmosvaloper1aaa');
        self::assertSame(41, (int) $row->wallet_link_id, 'and it is now linked to the verified wallet');
        self::assertSame('Alpha', $row->moniker);
        self::assertSame(1, $this->countRows(), 'no duplicate row');
    }

    public function testARowAlreadyLinkedToTheSameWalletIsRefreshed(): void
    {
        $first = ValidatorRepository::upsert($this->data('cosmosvaloper1bbb', 'Old'), 42);
        $again = ValidatorRepository::upsert($this->data('cosmosvaloper1bbb', 'New'), 42);

        self::assertIsInt($first);
        self::assertSame($first, $again);
        self::assertSame('New', $this->row('cosmosvaloper1bbb')->moniker);
    }

    public function testARowLinkedToAnotherWalletIsNotReHomed(): void
    {
        $owner = ValidatorRepository::upsert($this->data('cosmosvaloper1ccc', 'Owner'), 43);
        self::assertIsInt($owner);

        $result = ValidatorRepository::upsert($this->data('cosmosvaloper1ccc', 'Intruder'), 44);

        self::assertFalse($result, 'another link\'s validator is refused, not taken over');
        $row = $this->row('cosmosvaloper1ccc');
        self::assertSame(43, (int) $row->wallet_link_id);
        self::assertSame('Owner', $row->moniker);
    }

    public function testANewValidatorIsInsertedAndItsOwnIdReturned(): void
    {
        $id = ValidatorRepository::upsert($this->data('cosmosvaloper1ddd', 'Delta'), 45);

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
        $staleId = $this->insertBulkIndexed('cosmosvaloper1zzz'); // sets insert_id

        $wpdb->failQueriesMatching = '/^\s*INSERT INTO `?\S*onchain_validators/i';
        $result = ValidatorRepository::upsert($this->data('cosmosvaloper1eee', 'Echo'), 46);
        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
        $wpdb->clearFaultInjection();

        self::assertFalse($result);
        self::assertNotSame($staleId, $result);
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
    }
}
