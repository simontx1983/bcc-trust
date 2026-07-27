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
}
