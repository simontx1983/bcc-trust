<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `metadata_state` is bounded at the REPOSITORY, against a real database.
 *
 * ── WHY THIS IS AN INTEGRATION TEST ─────────────────────────────────────
 * The unit-suite `CollectionRepository` is a double that records its arguments
 * — it cannot prove a bad value never reaches SQL, because it never issues any.
 * Only the real repository against a real table can show that an
 * out-of-vocabulary state is refused and that nothing is written.
 *
 * ── THE RULE ────────────────────────────────────────────────────────────
 * `complete | partial | unavailable`.
 *
 *  - key ABSENT           → legal; the column default applies.
 *  - key present, valid   → stored.
 *  - key present, invalid → **`addManual()` returns false before any SQL.**
 *
 * The last case is deliberately not silently ignored. Only BCC's own code sets
 * this column, so a value outside the vocabulary is a PROGRAMMING error — a
 * probe inventing a state, or a caller passing the wrong variable. Dropping it
 * quietly would write a row whose metadata state disagreed with what the caller
 * believed, with nothing reporting the divergence.
 */
final class CollectionMetadataStateIntegrationTest extends TestCase
{
    /**
     * A real bech32 address with a valid checksum. It must be real: the
     * canonicaliser verifies the checksum and refuses invented filler, so a
     * made-up address makes `addManual()` return false and every assertion here
     * would be about the wrong thing.
     */
    private const CONTRACT = 'cosmos18kg555ql6gxeg8t7gmvklymry8ecz8krwqn3hs3u7mnzv3a86c6qxleeww';

    private int $chainId = 0;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . CollectionRepository::table() . '`');

        $chainId = ChainRepository::resolveIdAnyState('cosmos');
        self::assertIsInt($chainId, 'the chain registry must be seeded');
        self::assertGreaterThan(0, $chainId);
        $this->chainId = $chainId;
    }

    private function rowCount(): int
    {
        $wpdb = $GLOBALS['wpdb'];

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . CollectionRepository::table() . '`');
    }

    private function storedState(string $contract): ?string
    {
        $wpdb = $GLOBALS['wpdb'];
        $v = $wpdb->get_var($wpdb->prepare(
            'SELECT metadata_state FROM `' . CollectionRepository::table()
            . '` WHERE chain_id = %d AND contract_address = %s LIMIT 1',
            $this->chainId,
            $contract
        ));

        return $v === null ? null : (string) $v;
    }

    private function checkedAt(string $contract): ?string
    {
        $wpdb = $GLOBALS['wpdb'];
        $v = $wpdb->get_var($wpdb->prepare(
            'SELECT metadata_checked_at FROM `' . CollectionRepository::table()
            . '` WHERE chain_id = %d AND contract_address = %s LIMIT 1',
            $this->chainId,
            $contract
        ));

        return $v === null ? null : (string) $v;
    }

    /** @return array<string, mixed>|null every column, for an exact before/after */
    private function rowSnapshot(string $contract): ?array
    {
        $wpdb = $GLOBALS['wpdb'];
        $row  = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM `' . CollectionRepository::table()
            . '` WHERE chain_id = %d AND contract_address = %s LIMIT 1',
            $this->chainId,
            $contract
        ));

        // ⚠ Not `ARRAY_A`: the integration harness does not define WordPress's
        // output-format constants, and an undefined constant in this namespace
        // is a fatal rather than a fallback. Casting the stdClass row gives the
        // same field-by-field comparison.
        return is_object($row) ? (array) $row : null;
    }

    // ── Valid states round-trip ─────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function validStates(): array
    {
        return [
            'complete'    => ['complete'],
            'partial'     => ['partial'],
            'unavailable' => ['unavailable'],
        ];
    }

    #[DataProvider('validStates')]
    public function testEachValidStateIsStored(string $state): void
    {
        $id = CollectionRepository::addManual([
            'chain_id'            => $this->chainId,
            'contract_address'    => self::CONTRACT,
            'metadata_state'      => $state,
            'metadata_checked_at' => '2026-09-29 12:00:00',
        ]);

        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);
        self::assertSame($state, $this->storedState(self::CONTRACT));
        self::assertNotNull($this->checkedAt(self::CONTRACT));
    }

    public function testAnAbsentStateTakesTheSafeColumnDefault(): void
    {
        $id = CollectionRepository::addManual([
            'chain_id'         => $this->chainId,
            'contract_address' => self::CONTRACT,
        ]);

        self::assertIsInt($id);
        self::assertSame(
            'unavailable',
            $this->storedState(self::CONTRACT),
            'a row nobody checked reads as unavailable, not as a successful empty read'
        );
    }

    // ── ⚠⚠⚠ An invalid state is REFUSED ─────────────────────────────────

    /** @return array<string, array{0: mixed}> */
    public static function invalidStates(): array
    {
        return [
            'unknown word'  => ['definitely-complete'],
            'empty string'  => [''],
            'wrong case'    => ['COMPLETE'],
            'leading space' => [' complete'],
            'sql fragment'  => ["complete'; DROP TABLE x; --"],
            'integer'       => [1],
            'null'          => [null],
            'array'         => [['complete']],
            'overlong'      => [str_repeat('a', 64)],
        ];
    }

    #[DataProvider('invalidStates')]
    public function testAnInvalidStateCreatesZeroRows(mixed $bad): void
    {
        $result = CollectionRepository::addManual([
            'chain_id'            => $this->chainId,
            'contract_address'    => self::CONTRACT,
            'metadata_state'      => $bad,
            'metadata_checked_at' => '2026-09-29 12:00:00',
        ]);

        self::assertFalse($result, 'addManual must refuse an out-of-vocabulary state');
        self::assertSame(0, $this->rowCount(), 'no row may be created');
        self::assertNull($this->storedState(self::CONTRACT));
    }

    public function testAnInvalidStateOnReAddChangesZeroFields(): void
    {
        CollectionRepository::addManual([
            'chain_id'            => $this->chainId,
            'contract_address'    => self::CONTRACT,
            'collection_name'     => 'Original Name',
            'metadata_state'      => 'complete',
            'metadata_checked_at' => '2026-09-29 12:00:00',
        ]);

        $before = $this->rowSnapshot(self::CONTRACT);
        self::assertNotNull($before);

        $result = CollectionRepository::addManual([
            'chain_id'         => $this->chainId,
            'contract_address' => self::CONTRACT,
            'collection_name'  => 'Should Not Land',
            'metadata_state'   => 'nonsense',
        ]);

        self::assertFalse($result);
        self::assertSame(
            $before,
            $this->rowSnapshot(self::CONTRACT),
            'a refused call must change zero fields — including ones unrelated to the bad state'
        );
    }

    public function testAnInvalidStateDoesNotWriteMetadataCheckedAt(): void
    {
        CollectionRepository::addManual([
            'chain_id'            => $this->chainId,
            'contract_address'    => self::CONTRACT,
            'metadata_state'      => 'nope',
            'metadata_checked_at' => '2026-09-29 12:00:00',
        ]);

        self::assertSame(0, $this->rowCount());
        self::assertNull($this->checkedAt(self::CONTRACT));
    }
}
