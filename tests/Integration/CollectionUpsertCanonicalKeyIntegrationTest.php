<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * "Not reported" comes in four shapes and NONE of them may clear a field.
     *
     * `''` and whitespace-only are the dangerous pair: they survive `isset()`,
     * and `wpdb::prepare('%s', …)` hands them to MySQL as a real string, so a
     * plain `COALESCE(VALUES(col), col)` treats them as a value and overwrites.
     * An automated refresh that says nothing about a field is reporting "I
     * don't know", never "clear it".
     *
     * @param string|null $blank
     */
    #[DataProvider('blankMetadataProvider')]
    public function testNoFormOfBlankMetadataCanEraseAPopulatedValue(string $label, $blank, bool $omitKeys): void
    {
        CollectionRepository::upsert(
            $this->payload('Established Name', 'https://cdn.example/established.png', 4200),
            self::WALLET_A
        );

        $sparse = $omitKeys
            ? ['chain_id' => $this->chainId, 'contract_address' => self::CONTRACT]
            : [
                'chain_id'         => $this->chainId,
                'contract_address' => self::CONTRACT,
                'collection_name'  => $blank,
                'image_url'        => $blank,
                'token_standard'   => $blank,
            ];

        $result = CollectionRepository::upsert($sparse, self::WALLET_B);
        self::assertSame('updated', $result['status'], "{$label}: the write itself must still succeed");
        self::assertSame('', (string) $GLOBALS['wpdb']->last_error, "{$label}: left a SQL error behind");

        $row = $this->row();
        self::assertSame('Established Name', (string) $row->collection_name, "{$label}: name was erased");
        self::assertSame('https://cdn.example/established.png', (string) $row->image_url, "{$label}: image_url was erased");
        self::assertSame('CW-721', (string) $row->token_standard, "{$label}: token_standard was erased");
        self::assertSame(1, $this->rowCount(), "{$label}: still one canonical row");
    }

    /** @return array<string, array{0: string, 1: string|null, 2: bool}> */
    public static function blankMetadataProvider(): array
    {
        return [
            'empty string'     => ['empty string', '', false],
            'single space'     => ['single space', ' ', false],
            'whitespace run'   => ['whitespace run', "  \t \n ", false],
            'explicit null'    => ['explicit null', null, false],
            'keys omitted'     => ['keys omitted', null, true],
        ];
    }

    /**
     * A genuine zero is NOT blank. The blank-to-null rule is scoped to string
     * columns precisely so a real "0 supply / 0 holders" still records as 0
     * rather than being discarded as "unknown".
     */
    public function testGenuineNumericZeroIsStillWritten(): void
    {
        CollectionRepository::upsert($this->payload('Zeroed', null, 4200), self::WALLET_A);

        $zeroed = [
            'chain_id'         => $this->chainId,
            'contract_address' => self::CONTRACT,
            'total_supply'     => 0,
            'unique_holders'   => 0,
            'floor_price'      => 0,
        ];
        self::assertSame('updated', CollectionRepository::upsert($zeroed, self::WALLET_B)['status']);

        $row = $this->row();
        self::assertSame('0', (string) $row->total_supply, 'a real zero supply must persist, not be discarded');
        self::assertSame('0', (string) $row->unique_holders, 'a real zero holder count must persist');
        self::assertSame(0.0, (float) $row->floor_price, 'a real zero floor must persist');
    }

    /**
     * EXACT replay: same wallet, same contract, byte-identical metadata. MySQL
     * reports ZERO affected rows because nothing changed — that is a success,
     * not a failure, and `wpdb::query()` returns int 0 which a loose check
     * would misread as false.
     */
    public function testIdenticalReplayWithZeroAffectedRowsStillSucceeds(): void
    {
        $wpdb    = $GLOBALS['wpdb'];
        $payload = $this->payload('Replay Fixture', 'https://cdn.example/replay.png', 7);

        $first = CollectionRepository::upsert($payload, self::WALLET_A);
        self::assertSame('created', $first['status']);

        $replay = CollectionRepository::upsert($payload, self::WALLET_A);

        self::assertSame(
            0,
            (int) $wpdb->rows_affected,
            'the fixture must actually produce a zero-affected replay, or this test proves nothing'
        );
        self::assertSame('updated', $replay['status'], 'a no-op replay is a success, not a failure');
        self::assertSame($first['id'], $replay['id'], 'the canonical id must survive a zero-affected write');
        self::assertGreaterThan(0, $replay['id']);
        self::assertSame('', (string) $wpdb->last_error, 'a no-op replay must leave no SQL error');
        self::assertSame(1, $this->rowCount());
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
