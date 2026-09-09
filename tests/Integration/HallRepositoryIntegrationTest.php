<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\HallRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * HallRepository against a real MySQL — proves the meta-marker seam the
 * HallProvisioningService relies on for idempotency:
 *
 *   - findHallForChain resolves the Hall for a chain via the
 *     (`_bcc_group_kind='hall'` AND `_bcc_chain_tag=<id>`) meta pair, and
 *     is BOTH chain-scoped and kind-scoped (a holders group tagged with
 *     the same chain is NOT a Hall).
 *   - listAllHallIds returns only hall-kind, published peepso-group posts,
 *     ID-ASC.
 *
 * The provisioner's "create one Hall per chain, no dupes on re-run"
 * guarantee is exactly findHallForChain returning non-null on the second
 * sweep, so this pins the read that backs it.
 */
#[Group('integration')]
#[CoversClass(HallRepository::class)]
final class HallRepositoryIntegrationTest extends TestCase
{
    private function posts(): string
    {
        return $GLOBALS['wpdb']->posts;
    }

    private function postmeta(): string
    {
        return $GLOBALS['wpdb']->postmeta;
    }

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query(
            'CREATE TABLE IF NOT EXISTS `' . $this->posts() . '` (
                ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_type VARCHAR(20) NOT NULL DEFAULT \'\',
                post_status VARCHAR(20) NOT NULL DEFAULT \'\',
                PRIMARY KEY (ID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        $wpdb->query(
            'CREATE TABLE IF NOT EXISTS `' . $this->postmeta() . '` (
                meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                meta_key VARCHAR(255) DEFAULT NULL,
                meta_value LONGTEXT,
                PRIMARY KEY (meta_id),
                KEY post_id (post_id),
                KEY meta_key (meta_key(191))
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        $wpdb->query('TRUNCATE TABLE `' . $this->posts() . '`');
        $wpdb->query('TRUNCATE TABLE `' . $this->postmeta() . '`');
    }

    private function insertGroup(int $id, string $postType = 'peepso-group', string $postStatus = 'publish'): void
    {
        $GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare(
            'INSERT INTO `' . $this->posts() . '` (ID, post_type, post_status) VALUES (%d, %s, %s)',
            $id,
            $postType,
            $postStatus
        ));
    }

    private function insertMeta(int $postId, string $key, string $value): void
    {
        $GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare(
            'INSERT INTO `' . $this->postmeta() . '` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)',
            $postId,
            $key,
            $value
        ));
    }

    private function registerHall(int $groupId, int $chainId): void
    {
        $this->insertGroup($groupId);
        $this->insertMeta($groupId, HallRepository::META_KIND, HallRepository::KIND_HALL);
        $this->insertMeta($groupId, HallRepository::META_CHAIN_TAG, (string) $chainId);
    }

    public function testFindHallForChainResolvesTheHall(): void
    {
        $this->registerHall(600, 12);

        self::assertSame(600, HallRepository::findHallForChain(12));
    }

    public function testFindHallForChainIsChainScoped(): void
    {
        $this->registerHall(600, 12);

        // A different chain has no Hall yet → the provisioner would create one.
        self::assertNull(HallRepository::findHallForChain(99));
    }

    public function testFindHallForChainIsKindScoped(): void
    {
        // A holders group tagged with the same chain id is NOT a Hall.
        $this->insertGroup(700);
        $this->insertMeta(700, HallRepository::META_KIND, 'holders');
        $this->insertMeta(700, HallRepository::META_CHAIN_TAG, '12');

        self::assertNull(HallRepository::findHallForChain(12));
    }

    public function testFindHallForChainRejectsNonPositiveChain(): void
    {
        self::assertNull(HallRepository::findHallForChain(0));
        self::assertNull(HallRepository::findHallForChain(-5));
    }

    public function testListAllHallIdsReturnsOnlyHallsIdAsc(): void
    {
        $this->registerHall(30, 3);
        $this->registerHall(10, 1);
        $this->registerHall(20, 2);

        // Noise: a holders group, and an unpublished Hall — both excluded.
        $this->insertGroup(40);
        $this->insertMeta(40, HallRepository::META_KIND, 'holders');
        $this->insertMeta(40, HallRepository::META_CHAIN_TAG, '4');

        $this->insertGroup(50, 'peepso-group', 'draft');
        $this->insertMeta(50, HallRepository::META_KIND, HallRepository::KIND_HALL);
        $this->insertMeta(50, HallRepository::META_CHAIN_TAG, '5');

        self::assertSame([10, 20, 30], HallRepository::listAllHallIds());
    }

    // ── findHallsForChains — the admin listing's bulk lookup ────────────

    public function testFindHallsForChainsResolvesManyChainsInOneQuery(): void
    {
        $this->registerHall(600, 12);
        $this->registerHall(601, 13);
        $this->registerHall(602, 14);

        self::assertSame(
            [12 => 600, 13 => 601, 14 => 602],
            HallRepository::findHallsForChains([12, 13, 14])
        );
    }

    /**
     * Chains WITHOUT a Hall are simply absent — that absence is what the
     * admin page renders as "This chain does not have a Hall", so a lookup
     * that invented a zero would turn a create button into a view link.
     */
    public function testChainsWithoutAHallAreAbsentFromTheMap(): void
    {
        $this->registerHall(600, 12);

        $map = HallRepository::findHallsForChains([11, 12, 13]);

        self::assertSame([12 => 600], $map);
        self::assertArrayNotHasKey(11, $map);
        self::assertArrayNotHasKey(13, $map);
    }

    public function testFindHallsForChainsIsKindScoped(): void
    {
        // A holders group carrying the same chain tag is NOT a Hall.
        $this->insertGroup(700);
        $this->insertMeta(700, HallRepository::META_KIND, 'holders');
        $this->insertMeta(700, HallRepository::META_CHAIN_TAG, '12');

        self::assertSame([], HallRepository::findHallsForChains([12]));
    }

    public function testFindHallsForChainsExcludesUnpublishedAndForeignPostTypes(): void
    {
        $this->insertGroup(50, 'peepso-group', 'draft');
        $this->insertMeta(50, HallRepository::META_KIND, HallRepository::KIND_HALL);
        $this->insertMeta(50, HallRepository::META_CHAIN_TAG, '5');

        $this->insertGroup(60, 'post', 'publish');
        $this->insertMeta(60, HallRepository::META_KIND, HallRepository::KIND_HALL);
        $this->insertMeta(60, HallRepository::META_CHAIN_TAG, '6');

        self::assertSame([], HallRepository::findHallsForChains([5, 6]));
    }

    public function testFindHallsForChainsShortCircuitsOnEmptyAndInvalidInput(): void
    {
        $this->registerHall(600, 12);

        self::assertSame([], HallRepository::findHallsForChains([]));
        self::assertSame([], HallRepository::findHallsForChains([0, -3]));
        // Invalid ids are dropped; valid ones still resolve.
        self::assertSame([12 => 600], HallRepository::findHallsForChains([0, 12, -1]));
    }

    /**
     * One-Hall-per-chain is procedural, not a unique index, so a duplicate is
     * possible in principle. Picking the lowest post id makes the admin page
     * deterministic instead of letting row order decide which Hall it shows.
     */
    public function testADuplicateChainTagResolvesDeterministicallyToTheLowestId(): void
    {
        $this->registerHall(800, 12);
        $this->registerHall(700, 12);

        self::assertSame([12 => 700], HallRepository::findHallsForChains([12]));
    }

    /**
     * ⚠ REGRESSION: a duplicate must not push a LATER chain out of the result.
     *
     * With a flat `ORDER BY p.ID ASC LIMIT <chain count>` this returned only
     * chains 12 and 13 — chain 14's Hall fell off the end because chain 12's
     * duplicate consumed a row. The admin page reads a missing chain as "no
     * Hall" and offers Create, so the truncation would have invited an
     * operator to create a SECOND Hall for a chain that already had one.
     */
    public function testADuplicateDoesNotTruncateALaterChainOutOfTheResult(): void
    {
        $this->registerHall(700, 12);
        $this->registerHall(701, 12);   // the duplicate
        $this->registerHall(702, 13);
        $this->registerHall(703, 14);

        $map = HallRepository::findHallsForChains([12, 13, 14]);

        self::assertSame([12 => 700, 13 => 702, 14 => 703], $map);
        self::assertArrayHasKey(14, $map, 'the last chain must not be truncated away');
    }

    /**
     * The two readers must never disagree: the admin page decides "create" vs
     * "view" from the bulk map, and the service's idempotency check uses the
     * single lookup. A divergence would offer Create for a chain that already
     * has a Hall.
     */
    public function testFindHallsForChainsAgreesWithFindHallForChain(): void
    {
        $this->registerHall(600, 12);
        $this->registerHall(601, 13);

        $map = HallRepository::findHallsForChains([12, 13, 14]);

        foreach ([12, 13, 14] as $chainId) {
            self::assertSame(
                HallRepository::findHallForChain($chainId),
                $map[$chainId] ?? null,
                "bulk and single lookup disagree for chain {$chainId}"
            );
        }
    }
}
