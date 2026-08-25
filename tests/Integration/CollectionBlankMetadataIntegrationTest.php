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
 * `bulkUpsert()` and `addManual()` must not blank populated metadata (#220).
 *
 * ── WHY THIS CANNOT BE A UNIT TEST ──────────────────────────────────────
 * The defect is the interaction between `wpdb::prepare('%s', null)` — which
 * yields `''`, not NULL — and a conflict clause that assigns unconditionally.
 * A test double has neither a unique key to conflict on nor MySQL's
 * NULL-vs-empty-string distinction, so it cannot see either half. Only the
 * real schema can.
 *
 * ── THE FIXTURE MUST HAVE SOMETHING TO LOSE ─────────────────────────────
 * Every case seeds a RICH row first, then writes a sparse one over it. If the
 * preservation clause is removed, the second write blanks the first and these
 * tests fail. Seeding an empty row would pass either way.
 */
#[Group('integration')]
#[CoversClass(CollectionRepository::class)]
final class CollectionBlankMetadataIntegrationTest extends TestCase
{
    private const CONTRACT = 'cosmos1blankmetadataaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const NAME     = 'Established Name';
    private const IMAGE    = 'https://cdn.example/established.png';

    private int $chainId = 0;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . CollectionRepository::table() . '`');

        $chainId = (int) $wpdb->get_var(
            'SELECT id FROM `' . ChainRepository::table() . '` ORDER BY id ASC LIMIT 1'
        );
        self::assertGreaterThan(0, $chainId, 'the chain registry must be seeded');
        $this->chainId    = $chainId;
        $wpdb->last_error = '';
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->query('DELETE FROM `' . CollectionRepository::table() . '`');
    }

    private function row(): ?object
    {
        return $GLOBALS['wpdb']->get_row(
            'SELECT * FROM `' . CollectionRepository::table() . '` WHERE chain_id = ' . $this->chainId
            . " AND contract_address = '" . self::CONTRACT . "' LIMIT 1"
        );
    }

    private function rowCount(): int
    {
        return (int) $GLOBALS['wpdb']->get_var(
            'SELECT COUNT(*) FROM `' . CollectionRepository::table() . '` WHERE chain_id = ' . $this->chainId
        );
    }

    /** @param array<string, mixed> $over @return array<string, mixed> */
    private function rich(array $over = []): array
    {
        return $over + [
            'chain_id'         => $this->chainId,
            'contract_address' => self::CONTRACT,
            'collection_name'  => self::NAME,
            'token_standard'   => 'CW-721',
            'image_url'        => self::IMAGE,
            'total_supply'     => 4200,
            'floor_price'      => 1.5,
            'unique_holders'   => 77,
        ];
    }

    private function seedRichViaBulk(): void
    {
        self::assertSame(1, CollectionRepository::bulkUpsert([$this->rich()]));
        $row = $this->row();
        self::assertNotNull($row, 'the fixture row must exist');
        self::assertSame(self::NAME, (string) $row->collection_name, 'the fixture must start populated');
        self::assertSame(self::IMAGE, (string) $row->image_url);
    }

    // ── bulkUpsert ──────────────────────────────────────────────────────

    /**
     * THE REGRESSION. Five shapes of "not reported", none of which may erase.
     *
     * @param string|null $blank
     */
    #[DataProvider('blankShapes')]
    public function testBulkUpsertCannotBlankPopulatedMetadata(string $label, $blank, bool $omit): void
    {
        $this->seedRichViaBulk();

        $sparse = $omit
            ? ['chain_id' => $this->chainId, 'contract_address' => self::CONTRACT]
            : [
                'chain_id'         => $this->chainId,
                'contract_address' => self::CONTRACT,
                'collection_name'  => $blank,
                'token_standard'   => $blank,
                'image_url'        => $blank,
            ];

        self::assertSame(1, CollectionRepository::bulkUpsert([$sparse]), "{$label}: the write must succeed");
        self::assertSame('', (string) $GLOBALS['wpdb']->last_error, "{$label}: left a SQL error behind");

        $row = $this->row();
        self::assertSame(self::NAME, (string) $row->collection_name, "{$label}: collection_name was erased");
        self::assertSame(self::IMAGE, (string) $row->image_url, "{$label}: image_url was erased");
        self::assertSame('CW-721', (string) $row->token_standard, "{$label}: token_standard was erased");
        self::assertSame(1, $this->rowCount(), "{$label}: still exactly one row");
    }

    /** Absent numerics must not null figures we already hold. */
    public function testBulkUpsertCannotNullPopulatedNumerics(): void
    {
        $this->seedRichViaBulk();

        CollectionRepository::bulkUpsert([[
            'chain_id' => $this->chainId, 'contract_address' => self::CONTRACT,
        ]]);

        $row = $this->row();
        self::assertSame('4200', (string) $row->total_supply, 'total_supply was nulled');
        self::assertSame(77, (int) $row->unique_holders, 'unique_holders was nulled');
        self::assertEqualsWithDelta(1.5, (float) $row->floor_price, 0.0001, 'floor_price was nulled');
    }

    /** A genuine zero is a value, not an absence — the blank rule must not eat it. */
    public function testBulkUpsertWritesGenuineNumericZero(): void
    {
        $this->seedRichViaBulk();

        CollectionRepository::bulkUpsert([[
            'chain_id'       => $this->chainId,
            'contract_address' => self::CONTRACT,
            'total_supply'   => 0,
            'unique_holders' => 0,
            'floor_price'    => 0,
        ]]);

        $row = $this->row();
        self::assertSame('0', (string) $row->total_supply, 'a real zero supply must persist');
        self::assertSame(0, (int) $row->unique_holders, 'a real zero holder count must persist');
        self::assertEqualsWithDelta(0.0, (float) $row->floor_price, 0.0001, 'a real zero floor must persist');
    }

    /** The string "0" is a value too — proof the rule is not empty(). */
    public function testBulkUpsertWritesTheStringZero(): void
    {
        $this->seedRichViaBulk();

        CollectionRepository::bulkUpsert([[
            'chain_id' => $this->chainId, 'contract_address' => self::CONTRACT,
            'collection_name' => '0',
        ]]);

        self::assertSame('0', (string) $this->row()->collection_name, 'empty() would have discarded "0"');
    }

    /** Absent string metadata must insert as SQL NULL, never as ''. */
    public function testBulkUpsertInsertsAbsentStringsAsNull(): void
    {
        CollectionRepository::bulkUpsert([[
            'chain_id' => $this->chainId, 'contract_address' => self::CONTRACT,
            'collection_name' => 'Named',
        ]]);

        $row = $this->row();
        self::assertNull($row->image_url, 'absent image_url must be NULL, not the empty string');
        self::assertNull($row->floor_currency, 'absent floor_currency must be NULL, not the empty string');
        self::assertNull($row->metadata_storage, 'absent metadata_storage must be NULL, not the empty string');
    }

    /** Operator curation outranks an automated sync. */
    public function testBulkUpsertPreservesManualSource(): void
    {
        CollectionRepository::addManual($this->rich());
        self::assertSame('manual', (string) $this->row()->source);

        CollectionRepository::bulkUpsert([$this->rich(['collection_name' => 'Synced'])]);

        $row = $this->row();
        self::assertSame('manual', (string) $row->source, "a 'manual' row must never be demoted by a sync");
        self::assertSame('Synced', (string) $row->collection_name, 'but metadata still refreshes');
    }

    /** A no-op replay is a success and does not disturb the row. */
    public function testBulkUpsertIdempotentReplay(): void
    {
        $this->seedRichViaBulk();
        $before = $this->row();

        self::assertSame(1, CollectionRepository::bulkUpsert([$this->rich()]));
        self::assertSame('', (string) $GLOBALS['wpdb']->last_error);

        $after = $this->row();
        self::assertSame((string) $before->id, (string) $after->id, 'same canonical row');
        self::assertSame(self::NAME, (string) $after->collection_name);
        self::assertSame(self::IMAGE, (string) $after->image_url);
        self::assertSame(1, $this->rowCount());
    }

    // ── addManual ───────────────────────────────────────────────────────

    /**
     * @param string|null $blank
     */
    #[DataProvider('blankShapes')]
    public function testAddManualCannotBlankPopulatedMetadata(string $label, $blank, bool $omit): void
    {
        self::assertNotFalse(CollectionRepository::addManual($this->rich()));

        $sparse = $omit
            ? ['chain_id' => $this->chainId, 'contract_address' => self::CONTRACT]
            : [
                'chain_id'         => $this->chainId,
                'contract_address' => self::CONTRACT,
                'collection_name'  => $blank,
                'token_standard'   => $blank,
                'image_url'        => $blank,
            ];

        self::assertNotFalse(CollectionRepository::addManual($sparse), "{$label}: the write must succeed");
        self::assertSame('', (string) $GLOBALS['wpdb']->last_error, "{$label}: left a SQL error behind");

        $row = $this->row();
        self::assertSame(self::NAME, (string) $row->collection_name, "{$label}: collection_name was erased");
        self::assertSame(self::IMAGE, (string) $row->image_url, "{$label}: image_url was erased");
        self::assertSame('CW-721', (string) $row->token_standard, "{$label}: token_standard was erased");
        self::assertSame(1, $this->rowCount(), "{$label}: still exactly one row");
    }

    public function testAddManualCannotNullPopulatedSupplyAndWritesRealZero(): void
    {
        CollectionRepository::addManual($this->rich());

        CollectionRepository::addManual(['chain_id' => $this->chainId, 'contract_address' => self::CONTRACT]);
        self::assertSame('4200', (string) $this->row()->total_supply, 'an absent supply must not null a known one');

        CollectionRepository::addManual([
            'chain_id' => $this->chainId, 'contract_address' => self::CONTRACT, 'total_supply' => 0,
        ]);
        self::assertSame('0', (string) $this->row()->total_supply, 'a real zero supply must persist');
    }

    /**
     * THE HIDDEN-ROW GUARD. `show_on_profile` is a visibility decision owned by
     * the member's showcase toggle and the operator's hide action. The Add
     * Collection form never submits it, so re-adding a contract used to
     * silently unhide a row somebody had deliberately hidden.
     */
    public function testAddManualDoesNotUnhideAHiddenRow(): void
    {
        $id = CollectionRepository::addManual($this->rich());
        self::assertNotFalse($id);

        $GLOBALS['wpdb']->query(
            'UPDATE `' . CollectionRepository::table() . '` SET show_on_profile = 0 WHERE id = ' . (int) $id
        );
        self::assertSame('0', (string) $this->row()->show_on_profile, 'the fixture must actually be hidden');

        // Exactly what the admin form sends: no show_on_profile key at all.
        CollectionRepository::addManual([
            'chain_id' => $this->chainId, 'contract_address' => self::CONTRACT, 'collection_name' => 'Re-added',
        ]);

        self::assertSame('0', (string) $this->row()->show_on_profile, 're-adding must not unhide the row');
        self::assertSame('Re-added', (string) $this->row()->collection_name, 'but the edit still applies');
    }

    /** When visibility IS explicitly provided, it is still honoured. */
    public function testAddManualStillAppliesAnExplicitVisibilityChange(): void
    {
        $id = CollectionRepository::addManual($this->rich());
        $GLOBALS['wpdb']->query(
            'UPDATE `' . CollectionRepository::table() . '` SET show_on_profile = 0 WHERE id = ' . (int) $id
        );

        CollectionRepository::addManual([
            'chain_id' => $this->chainId, 'contract_address' => self::CONTRACT, 'show_on_profile' => 1,
        ]);

        self::assertSame('1', (string) $this->row()->show_on_profile, 'an explicit request must still take effect');
    }

    /** A fresh manual row is visible and unverified by default. */
    public function testAddManualDefaultsToVisibleAndUnverifiedOnInsert(): void
    {
        CollectionRepository::addManual($this->rich());

        $row = $this->row();
        self::assertSame('1', (string) $row->show_on_profile, 'a new manual row defaults to visible');
        self::assertSame('0', (string) $row->is_verified, 'and to unverified');
        self::assertSame('manual', (string) $row->source);
    }

    public function testAddManualIdempotentReplay(): void
    {
        $first = CollectionRepository::addManual($this->rich());
        $again = CollectionRepository::addManual($this->rich());

        self::assertSame((int) $first, (int) $again, 'the same canonical row id comes back');
        self::assertSame('', (string) $GLOBALS['wpdb']->last_error);
        self::assertSame(1, $this->rowCount());
        self::assertSame(self::NAME, (string) $this->row()->collection_name);
    }

    /** @return array<string, array{0: string, 1: string|null, 2: bool}> */
    public static function blankShapes(): array
    {
        return [
            'empty string'   => ['empty string', '', false],
            'single space'   => ['single space', ' ', false],
            'whitespace run' => ['whitespace run', "  \t \n ", false],
            'explicit null'  => ['explicit null', null, false],
            'keys omitted'   => ['keys omitted', null, true],
        ];
    }
}
