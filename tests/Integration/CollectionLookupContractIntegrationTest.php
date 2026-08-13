<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `CollectionRepository::findManyByIds()` against a REAL MySQL.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────
 * The Verify Collections hide/unhide button read `$rows[0]` from this
 * method. The method returns a MAP KEYED BY COLLECTION ID, so `[0]` was
 * null for every id the admin page can emit and every hide and unhide
 * answered "collection not found" while writing nothing at all.
 *
 * The unit suite could not catch it: the test double returned a
 * convenient zero-indexed list, so the wrong access looked right. Fixing
 * the double fixes today's blindness, but a double is only ever an
 * ASSERTION about production. This file is the EVIDENCE — the real SQL,
 * the real `get_results()`, the real re-keying loop — so the shape the
 * doubles copy is anchored to something that cannot drift silently.
 *
 * If you change how this method keys its result, this test is where the
 * contract is written down, and every caller listed on the method's
 * docblock has to move with it.
 */
#[Group('integration')]
#[CoversClass(CollectionRepository::class)]
final class CollectionLookupContractIntegrationTest extends TestCase
{
    private const CONTRACT_A = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CONTRACT_B = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private int $chainId = 0;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . CollectionRepository::table() . '`');

        // The installer seeds the chain registry; any active chain will do
        // — findManyByIds INNER JOINs it for slug + chain_type.
        $chainId = (int) $wpdb->get_var(
            'SELECT id FROM `' . ChainRepository::table() . '` ORDER BY id ASC LIMIT 1'
        );
        self::assertGreaterThan(0, $chainId, 'the chain registry must be seeded for the JOIN to resolve');
        $this->chainId = $chainId;
    }

    /** @return int the AUTO_INCREMENT id the row actually landed on */
    private function insertCollection(string $contract, string $name): int
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'INSERT INTO `' . CollectionRepository::table() . '`
                (contract_address, chain_id, collection_name, token_standard, expires_at)
             VALUES (%s, %d, %s, %s, %s)',
            $contract,
            $this->chainId,
            $name,
            'CW-721',
            '2099-01-01 00:00:00'
        ));
        self::assertSame('', (string) $wpdb->last_error, 'fixture insert failed');

        return (int) $wpdb->insert_id;
    }

    /**
     * THE CONTRACT. Keys are collection ids, and each row's own `id`
     * agrees with the key it is filed under.
     */
    public function testFindManyByIdsReturnsAMapKeyedByCollectionId(): void
    {
        $idA = $this->insertCollection(self::CONTRACT_A, 'Alpha');
        $idB = $this->insertCollection(self::CONTRACT_B, 'Beta');

        $rows = CollectionRepository::findManyByIds([$idA, $idB]);
        self::assertSame('', (string) $GLOBALS['wpdb']->last_error, 'findManyByIds left a SQL error behind');

        $keys = array_keys($rows);
        sort($keys);
        self::assertSame([$idA, $idB], $keys, 'the result is keyed by collection id');

        foreach ($rows as $key => $row) {
            self::assertSame($key, (int) $row->id, 'the key and the row must be the same collection');
        }

        self::assertSame('Alpha', (string) $rows[$idA]->collection_name);
        self::assertSame('Beta', (string) $rows[$idB]->collection_name);
    }

    /**
     * The precise access that was broken: real ids are large, so index 0
     * is absent from a real result. A caller reading `$rows[0]` gets
     * nothing — which is what the admin hide button did, forever.
     */
    public function testTheResultIsNotAPositionalList(): void
    {
        $id = $this->insertCollection(self::CONTRACT_A, 'Alpha');
        self::assertGreaterThan(0, $id, 'AUTO_INCREMENT never hands out 0');

        $rows = CollectionRepository::findManyByIds([$id]);

        self::assertArrayNotHasKey(0, $rows, 'position 0 is not the first row — it is nothing');
        self::assertArrayHasKey($id, $rows);
        self::assertNotSame(array_values($rows), $rows, 'a list would compare equal to its own values');
    }

    /**
     * Ids that matched nothing are simply ABSENT — the result is shorter
     * than the input and holds no null placeholders, so `?? null` on the
     * requested id is the correct read for a miss.
     */
    public function testUnknownIdsAreAbsentRatherThanNull(): void
    {
        $id      = $this->insertCollection(self::CONTRACT_A, 'Alpha');
        $missing = $id + 100_000;

        $rows = CollectionRepository::findManyByIds([$id, $missing]);

        self::assertCount(1, $rows);
        self::assertArrayNotHasKey($missing, $rows);
        self::assertNull($rows[$missing] ?? null);
    }

    public function testAnEmptyIdListNeverQueries(): void
    {
        $GLOBALS['wpdb']->last_error = '';

        self::assertSame([], CollectionRepository::findManyByIds([]));
        self::assertSame('', (string) $GLOBALS['wpdb']->last_error);
    }
}
