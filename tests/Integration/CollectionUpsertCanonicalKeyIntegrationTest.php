<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Two wallets, one contract, one row — against REAL MySQL (#212).
 *
 * ── WHY THIS CANNOT BE A UNIT TEST ──────────────────────────────────────
 * The defect lives in the gap between what `upsert()` looked up
 * (wallet_link_id + chain_id + contract_address) and what the table
 * actually enforces (`uq_chain_contract (chain_id, contract_address)`).
 * A test double has no unique key, so it cannot reject the second wallet's
 * INSERT and cannot observe the failure. Only the real schema can. Every
 * assertion here would pass against a double whether or not the fix exists
 * — which is exactly why the bug survived the unit suite.
 *
 * ── THE FIXTURE MUST CONTEND ────────────────────────────────────────────
 * Wallet B writes the SAME (chain_id, contract_address) wallet A already
 * wrote. If the three-column lookup or the bare INSERT is restored, MySQL
 * rejects B's write, `$wpdb->last_error` fills in, and these tests fail.
 */
#[Group('integration')]
#[CoversClass(CollectionRepository::class)]
final class CollectionUpsertCanonicalKeyIntegrationTest extends TestCase
{
    private const CONTRACT = 'cosmos1sharedcontractaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const WALLET_A = 4242;
    private const WALLET_B = 4343;

    private int $chainId = 0;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . CollectionRepository::table() . '`');

        // listVerifiedByChain and friends INNER JOIN the chain registry, and
        // the installer seeds it; any active chain resolves the fixture.
        $chainId = (int) $wpdb->get_var(
            'SELECT id FROM `' . ChainRepository::table() . '` ORDER BY id ASC LIMIT 1'
        );
        self::assertGreaterThan(0, $chainId, 'the chain registry must be seeded');
        $this->chainId = $chainId;
        $wpdb->last_error = '';
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->query('DELETE FROM `' . CollectionRepository::table() . '`');
    }

    /** @return array<string, mixed> */
    private function payload(string $name, ?string $image = null, ?int $supply = null): array
    {
        return [
            'chain_id'         => $this->chainId,
            'contract_address' => self::CONTRACT,
            'collection_name'  => $name,
            'token_standard'   => 'CW-721',
            'total_supply'     => $supply,
            'image_url'        => $image,
        ];
    }

    private function rowCount(): int
    {
        return (int) $GLOBALS['wpdb']->get_var(
            'SELECT COUNT(*) FROM `' . CollectionRepository::table() . '` WHERE chain_id = ' . $this->chainId
        );
    }

    private function row(): ?object
    {
        return $GLOBALS['wpdb']->get_row(
            'SELECT * FROM `' . CollectionRepository::table() . '` WHERE chain_id = ' . $this->chainId
            . " AND contract_address = '" . self::CONTRACT . "' LIMIT 1"
        );
    }

    /**
     * THE CONTRACT. Wallet A creates; wallet B updates the same row. One
     * canonical row, no SQL error, and B's metadata actually landed.
     */
    public function testTwoWalletsSharingOneContractProduceOneRowAndNoError(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $first = CollectionRepository::upsert($this->payload('Alpha Collection'), self::WALLET_A);
        self::assertSame('created', $first['status'], 'the first wallet creates the canonical row');
        self::assertGreaterThan(0, $first['id']);
        self::assertSame('', (string) $wpdb->last_error, 'the first write left a SQL error behind');

        $second = CollectionRepository::upsert($this->payload('Alpha Collection v2'), self::WALLET_B);

        // The precise regression: pre-fix this returned false and MySQL logged
        // "Duplicate entry … for key 'uq_chain_contract'".
        self::assertSame('updated', $second['status'], 'the second wallet must UPDATE, never be rejected');
        self::assertSame($first['id'], $second['id'], 'both wallets resolve to the same canonical row');
        self::assertSame('', (string) $wpdb->last_error, 'a duplicate-key rejection would fill last_error');

        self::assertSame(1, $this->rowCount(), 'exactly one row per (chain_id, contract_address)');
        self::assertSame('Alpha Collection v2', (string) $this->row()->collection_name, "wallet B's refresh landed");
    }

    /**
     * Ownership is first-writer-wins and is NOT rewritten by a later wallet.
     * This is containment, not the ownership model: Phase 1 deliberately does
     * not record wallet B's association anywhere.
     */
    public function testOwnershipIsNotStolenByTheSecondWallet(): void
    {
        CollectionRepository::upsert($this->payload('Alpha'), self::WALLET_A);
        CollectionRepository::upsert($this->payload('Alpha again'), self::WALLET_B);

        self::assertSame(
            (string) self::WALLET_A,
            (string) $this->row()->wallet_link_id,
            'wallet_link_id must stay with the first writer — last-writer-wins would thrash every 4h'
        );
    }

    /** Operator decisions and curation survive an automated wallet refresh. */
    public function testProtectedFieldsSurviveTheUpdatePath(): void
    {
        $created = CollectionRepository::upsert($this->payload('Curated'), self::WALLET_A);
        $wpdb    = $GLOBALS['wpdb'];
        $table   = CollectionRepository::table();

        // Simulate the operator: verify it, hide it, mark it curated.
        $wpdb->query(
            "UPDATE `{$table}` SET is_verified = 1, show_on_profile = 0, source = 'manual' WHERE id = " . $created['id']
        );
        self::assertSame('', (string) $wpdb->last_error);

        $second = CollectionRepository::upsert($this->payload('Automated overwrite attempt'), self::WALLET_B);
        self::assertSame('updated', $second['status']);

        $row = $this->row();
        self::assertSame('1', (string) $row->is_verified, 'verification is an operator decision');
        self::assertSame('0', (string) $row->show_on_profile, 'visibility is an operator decision');
        self::assertSame('manual', (string) $row->source, "a curated row must not be demoted to 'discovery'");
        self::assertSame('Automated overwrite attempt', (string) $row->collection_name, 'metadata still refreshes');
    }

    /**
     * A sparse fetch may FILL a blank column but must never BLANK a populated
     * one — otherwise wallet B's thin response erases wallet A's richer data.
     */
    public function testASparseRefreshCannotErasePopulatedMetadata(): void
    {
        CollectionRepository::upsert(
            $this->payload('Rich', 'https://cdn.example/art.png', 5000),
            self::WALLET_A
        );

        // Wallet B knows the contract but returns no image and no supply.
        CollectionRepository::upsert($this->payload('Rich', null, null), self::WALLET_B);

        $row = $this->row();
        self::assertSame('https://cdn.example/art.png', (string) $row->image_url, 'artwork must not be nulled');
        self::assertSame('5000', (string) $row->total_supply, 'supply must not be nulled');
    }

    /**
     * No read-then-write means no window: writing the same key twice in a row
     * is a single-statement operation both times. Pre-fix, the second call
     * took the INSERT branch and was rejected.
     */
    public function testRepeatedWritesOfTheSameKeyStayAtOneRow(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $ids  = [];

        foreach ([self::WALLET_A, self::WALLET_B, self::WALLET_A, self::WALLET_B] as $i => $wallet) {
            $r = CollectionRepository::upsert($this->payload('Iteration ' . $i), $wallet);
            self::assertNotSame('failed', $r['status'], "write {$i} must not fail");
            self::assertSame('', (string) $wpdb->last_error, "write {$i} left a SQL error behind");
            $ids[] = $r['id'];
        }

        self::assertSame(1, $this->rowCount(), 'four writes, one row');
        self::assertCount(1, array_unique($ids), 'every write resolved to the same row id');
        self::assertSame('Iteration 3', (string) $this->row()->collection_name, 'the last write wins on metadata');
    }

    /** Malformed input is rejected as `failed` rather than silently ignored. */
    public function testMalformedInputReportsFailedAndWritesNothing(): void
    {
        $missingChain = CollectionRepository::upsert(
            ['contract_address' => self::CONTRACT],
            self::WALLET_A
        );
        self::assertSame('failed', $missingChain['status']);
        self::assertSame(0, $missingChain['id']);

        $missingContract = CollectionRepository::upsert(
            ['chain_id' => $this->chainId],
            self::WALLET_A
        );
        self::assertSame('failed', $missingContract['status']);

        self::assertSame(0, $this->rowCount(), 'nothing was written');
    }
}
