<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Services\CollectionStateClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `GatedGroupRepository::findPublishedGroupIdForCollectionId()` against a
 * REAL MySQL.
 *
 * ── WHY THIS EXISTS ALONGSIDE THE UNIT TEST ─────────────────────────────
 * The unit test stubs the repository, so it pins the HANDLER's policy — that
 * a thrown read failure blocks the delete. It cannot prove the two things
 * that only SQL can answer:
 *
 *   1. that the new lookup and {@see CollectionStateClassifier::sqlHasCommunity()}
 *      really are two expressions of ONE rule. They are written in different
 *      places and neither references the other, which is a drift generator,
 *      so every row below is asked both ways and the answers must agree.
 *   2. that the production method actually FAILS CLOSED. The stub throws
 *      because the stub was told to; this forces a genuine SQL error.
 *
 * It also proves the fix end-to-end on the shape that caused it: a row whose
 * `contract_address` holds a legacy marketplace symbol, where the old
 * contract-keyed lookup answers "no community" without running a query.
 */
#[Group('integration')]
final class GatedGroupCollectionIdLookupIntegrationTest extends TestCase
{
    private const POST_BASE = 920000;

    private static int $chainId = 0;

    public static function setUpBeforeClass(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        // A Solana chain if the shipped registry has one — it is the family
        // whose canonicalisation refuses the legacy alias, which is the
        // whole point of case 5. Any chain will do for the rest.
        $id = $wpdb->get_var("SELECT id FROM `" . \BCC\Trust\Onchain\Repositories\ChainRepository::table() . "`
                               WHERE chain_type = 'solana' ORDER BY id ASC LIMIT 1");
        if ($id === null) {
            $id = $wpdb->get_var("SELECT id FROM `" . \BCC\Trust\Onchain\Repositories\ChainRepository::table() . "`
                                   ORDER BY id ASC LIMIT 1");
        }
        self::$chainId = (int) $id;
    }

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . CollectionRepository::table() . '`');
        $wpdb->query("DELETE FROM `{$wpdb->postmeta}` WHERE post_id >= " . self::POST_BASE);
        $wpdb->query("DELETE FROM `{$wpdb->posts}` WHERE ID >= " . self::POST_BASE);
    }

    /** Insert a collection row and return its id. */
    private function seedCollection(string $contract, ?string $canonical): int
    {
        $wpdb = $GLOBALS['wpdb'];

        $wpdb->query($wpdb->prepare(
            'INSERT INTO `' . CollectionRepository::table() . '`
                (contract_address, canonical_identifier, chain_id, fetched_at, expires_at, is_verified)
             VALUES (%s, ' . ($canonical === null ? 'NULL' : '%s') . ', %d, NOW(), NOW(), 0)',
            ...($canonical === null
                ? [$contract, self::$chainId]
                : [$contract, $canonical, self::$chainId])
        ));

        return (int) $wpdb->insert_id;
    }

    /**
     * Create a group post pointing at $collectionId via the AUTHORITATIVE
     * meta key, with a controllable post_status / kind.
     */
    private function seedGroup(
        int $collectionId,
        string $status = 'publish',
        string $kind = 'holders',
        string $type = 'peepso-group'
    ): int {
        $wpdb   = $GLOBALS['wpdb'];
        $postId = self::POST_BASE + $collectionId;

        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$wpdb->posts}` (ID, post_title, post_type, post_status)
             VALUES (%d, %s, %s, %s)",
            $postId,
            'Holders ' . $collectionId,
            $type,
            $status
        ));
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
            $postId,
            GatedGroupRepository::META_KIND,
            $kind
        ));
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
            $postId,
            GatedGroupRepository::META_COLLECTION,
            (string) $collectionId
        ));

        return $postId;
    }

    /** The admin listing's predicate, evaluated for one row. */
    private function hasCommunityPerClassifier(int $collectionId): int
    {
        $wpdb = $GLOBALS['wpdb'];
        $sql  = CollectionStateClassifier::sqlHasCommunity($wpdb->postmeta, $wpdb->posts);

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT (' . $sql . ') FROM `' . CollectionRepository::table() . '` c WHERE c.id = %d',
            $collectionId
        ));
    }

    public function testAPublishedGroupIsFoundAndAgreesWithTheListingPredicate(): void
    {
        $cid     = $this->seedCollection('0x' . str_repeat('a', 40), '0x' . str_repeat('a', 40));
        $groupId = $this->seedGroup($cid);

        $this->assertSame($groupId, GatedGroupRepository::findPublishedGroupIdForCollectionId($cid));
        $this->assertSame(1, $this->hasCommunityPerClassifier($cid), 'One rule, two expressions.');
    }

    public function testACollectionWithNoGroupIsNotFoundAndAgrees(): void
    {
        $cid = $this->seedCollection('0x' . str_repeat('b', 40), '0x' . str_repeat('b', 40));

        $this->assertNull(GatedGroupRepository::findPublishedGroupIdForCollectionId($cid));
        $this->assertSame(0, $this->hasCommunityPerClassifier($cid));
    }

    /**
     * The published-community policy, preserved. A trashed or draft group is
     * not a live community — counting one would strand the row behind a
     * block it can never clear.
     *
     * @return list<array{0: string}>
     */
    public static function unpublishedStatuses(): array
    {
        return [['trash'], ['draft'], ['pending'], ['private']];
    }

    #[DataProvider('unpublishedStatuses')]
    public function testAnUnpublishedGroupNeitherBlocksNorCounts(string $status): void
    {
        $cid = $this->seedCollection('0x' . str_repeat('c', 40), '0x' . str_repeat('c', 40));
        $this->seedGroup($cid, $status);

        $this->assertNull(
            GatedGroupRepository::findPublishedGroupIdForCollectionId($cid),
            "A '{$status}' group must not block a delete."
        );
        $this->assertSame(
            0,
            $this->hasCommunityPerClassifier($cid),
            "The listing predicate must agree that a '{$status}' group is not a community."
        );
    }

    public function testAGroupOfAnotherKindIsIgnored(): void
    {
        $cid = $this->seedCollection('0x' . str_repeat('d', 40), '0x' . str_repeat('d', 40));
        $this->seedGroup($cid, 'publish', 'interest');

        $this->assertNull(GatedGroupRepository::findPublishedGroupIdForCollectionId($cid));
        $this->assertSame(0, $this->hasCommunityPerClassifier($cid));
    }

    /**
     * THE REGRESSION. `contract_address` holds a Magic Eden symbol, which is
     * the shape eight production Solana gates actually stored. `_` is not in
     * the base58 alphabet, so canonicalising it refuses and the old
     * contract-keyed lookup returns null WITHOUT RUNNING A QUERY — reporting
     * "no community" for a live one and letting the row be deleted.
     *
     * The collection-id lookup has no string to canonicalise, so it finds
     * the group. Both lookups are exercised here so the difference is
     * recorded against real SQL, not against a comment.
     */
    public function testALegacyAliasRowIsProtectedEvenThoughTheContractLookupIsBlind(): void
    {
        $cid     = $this->seedCollection('alpha_gardener', '4fKR1UC2UA5R5m3ZGJwisZD4tkqQ2ZEPgGeZn51bB8uy');
        $groupId = $this->seedGroup($cid);

        $this->assertSame(
            $groupId,
            GatedGroupRepository::findPublishedGroupIdForCollectionId($cid),
            'The authoritative lookup must see the community.'
        );
        $this->assertSame(1, $this->hasCommunityPerClassifier($cid));

        // And the reason the guard had to move: the legacy lookup cannot see
        // it. If this ever starts returning the group id, the alias handling
        // changed and this test should be revisited rather than deleted.
        $this->assertNull(
            GatedGroupRepository::findGroupForCollection(self::$chainId, 'alpha_gardener'),
            'Documents the blindness the fix routes around.'
        );
    }

    /**
     * FAIL CLOSED, for real. A genuine SQL error must surface as an
     * exception, never as the null that the delete guard would read as
     * permission.
     */
    public function testAFailedReadThrowsRatherThanAnsweringNull(): void
    {
        $wpdb     = $GLOBALS['wpdb'];
        $cid      = $this->seedCollection('0x' . str_repeat('e', 40), '0x' . str_repeat('e', 40));
        $this->seedGroup($cid);

        $realPostmeta    = $wpdb->postmeta;
        $wpdb->postmeta  = 'bcc_no_such_postmeta_table_zzz';

        try {
            GatedGroupRepository::findPublishedGroupIdForCollectionId($cid);
            $this->fail('A failed read must throw RepositoryReadFailure, not return null.');
        } catch (RepositoryReadFailure $e) {
            $this->assertSame('findPublishedGroupIdForCollectionId', $e->repositoryMethod());
            $this->assertNotSame('', $e->dbError(), 'The DB error must be carried for the operator log.');
        } finally {
            $wpdb->postmeta     = $realPostmeta;
            $wpdb->last_error   = '';
        }
    }

    public function testANonPositiveIdIsAnsweredWithoutReading(): void
    {
        // A bad id is a substantive answer, not a fault: no row can have it.
        $this->assertNull(GatedGroupRepository::findPublishedGroupIdForCollectionId(0));
        $this->assertNull(GatedGroupRepository::findPublishedGroupIdForCollectionId(-1));
    }
}
