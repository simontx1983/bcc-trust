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
 * — it cannot prove that a bad value never reaches SQL, because it never
 * issues any. Only the real repository against a real table can show that an
 * out-of-vocabulary state is refused and the column keeps a value a reader can
 * interpret.
 *
 * ── THE RULE ────────────────────────────────────────────────────────────
 * `complete | partial | unavailable`, enforced before the INSERT. A caller
 * that supplies anything else is treated as having supplied NOTHING: the
 * column takes its safe default on insert and is left alone on update. That
 * is deliberately not an exception — a manual add should not fail because a
 * future caller passed a typo — but the bad value must never be stored.
 */
final class CollectionMetadataStateIntegrationTest extends TestCase
{
    /**
     * A real bech32 address with a valid checksum. It must be real: the
     * canonicaliser verifies the checksum and refuses invented filler, so a
     * made-up address makes `addManual()` return false and every assertion
     * here would be about the wrong thing.
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

    private function storedState(string $contract): ?string
    {
        $wpdb = $GLOBALS['wpdb'];
        $row  = $wpdb->get_row($wpdb->prepare(
            'SELECT metadata_state, metadata_checked_at FROM `' . CollectionRepository::table()
            . '` WHERE chain_id = %d AND contract_address = %s LIMIT 1',
            $this->chainId,
            $contract
        ));

        return $row === null ? null : (string) $row->metadata_state;
    }

    // ── The three valid values round-trip ───────────────────────────────

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
        $contract = self::CONTRACT;

        $id = CollectionRepository::addManual([
            'chain_id'            => $this->chainId,
            'contract_address'    => $contract,
            'metadata_state'      => $state,
            'metadata_checked_at' => '2026-09-29 12:00:00',
        ]);

        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);
        self::assertSame($state, $this->storedState($contract));
    }

    // ── ⚠⚠ Everything else is refused ───────────────────────────────────

    /** @return array<string, array{0: mixed}> */
    public static function invalidStates(): array
    {
        return [
            'unknown word'     => ['definitely-complete'],
            'empty string'     => [''],
            'wrong case'       => ['COMPLETE'],
            'leading space'    => [' complete'],
            'sql fragment'     => ["complete'; DROP TABLE x; --"],
            'integer'          => [1],
            'null'             => [null],
            'array'            => [['complete']],
            'overlong'         => [str_repeat('a', 64)],
        ];
    }

    /**
     * ⚠ The row is still created — a typo in one field must not lose the
     * operator's submission — but the bad value never reaches the column.
     */
    #[DataProvider('invalidStates')]
    public function testAnOutOfVocabularyStateIsNeverStored(mixed $bad): void
    {
        $contract = self::CONTRACT;

        $id = CollectionRepository::addManual([
            'chain_id'         => $this->chainId,
            'contract_address' => $contract,
            'metadata_state'   => $bad,
        ]);

        self::assertIsInt($id);
        $stored = $this->storedState($contract);

        self::assertContains(
            $stored,
            ['complete', 'partial', 'unavailable'],
            'whatever is stored must be a value a reader can interpret'
        );
        if (is_string($bad)) {
            self::assertNotSame($bad, $stored, 'the supplied value must not have been written');
        }
    }

    public function testAnOutOfVocabularyStateOnReAddDoesNotOverwriteAGoodOne(): void
    {
        $contract = self::CONTRACT;

        CollectionRepository::addManual([
            'chain_id'         => $this->chainId,
            'contract_address' => $contract,
            'metadata_state'   => 'complete',
        ]);
        self::assertSame('complete', $this->storedState($contract));

        // A later caller passes junk. The stored state must survive.
        CollectionRepository::addManual([
            'chain_id'         => $this->chainId,
            'contract_address' => $contract,
            'metadata_state'   => 'nonsense',
        ]);

        self::assertSame(
            'complete',
            $this->storedState($contract),
            'an unrecognised state is treated as not supplied, so it overwrites nothing'
        );
    }

    public function testAFreshInsertWithNoStateTakesTheSafeColumnDefault(): void
    {
        $contract = self::CONTRACT;

        CollectionRepository::addManual([
            'chain_id'         => $this->chainId,
            'contract_address' => $contract,
        ]);

        self::assertSame(
            'unavailable',
            $this->storedState($contract),
            'a row nobody checked reads as unavailable, not as a successful empty read'
        );
    }
}
