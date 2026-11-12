<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #556 review follow-up: creating a user_info row flushes the row cache.
 *
 * Reads cache a MISSING row as '' for BCC_TRUST_CACHE_USER (5 minutes).
 * insert() did not flush (updateByUserId() already did), so a user read
 * before their row existed kept reading as "no row" for up to 5 minutes
 * after the sync created it. Real table, real object-cache double.
 */
#[Group('integration')]
#[Group('mariadb')]
final class UserInfoInsertFlushesCacheIntegrationTest extends TestCase
{
    private const UID = 991401;

    protected function setUp(): void
    {
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testARowCreatedAfterACachedMissIsReadImmediately(): void
    {
        $repo = new UserInfoRepository();

        self::assertNull($repo->getByUserId(self::UID), 'no row yet');
        self::assertSame('', wp_cache_get('user_info_' . self::UID, 'bcc_trust'), 'control: the miss IS cached');

        self::assertTrue($repo->insert(
            ['user_id' => self::UID, 'user_login' => 'ins' . self::UID, 'user_email' => 'ins' . self::UID . '@example.test', 'display_name' => 'Ins'],
            ['%d', '%s', '%s', '%s']
        ));

        $row = $repo->getByUserId(self::UID);
        self::assertNotNull($row, 'the new row is visible at once, not after the cache TTL');
        self::assertSame('ins' . self::UID, (string) $row->user_login);
    }

    public function testAFailedInsertLeavesTheCacheAlone(): void
    {
        $repo = new UserInfoRepository();
        $this->rawInsert();
        self::assertNotNull($repo->getByUserId(self::UID));
        $cached = wp_cache_get('user_info_' . self::UID, 'bcc_trust');

        // Duplicate user_id (UNIQUE KEY user_id): the insert fails, and
        // nothing changed to flush for.
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        try {
            self::assertFalse($repo->insert(['user_id' => self::UID, 'user_login' => 'dup'], ['%d', '%s']));
        } finally {
            $wpdb->suppress_errors($previous);
        }
        self::assertSame($cached, wp_cache_get('user_info_' . self::UID, 'bcc_trust'));
    }

    private function rawInsert(): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(TableRegistry::userInfo(), [
            'user_id' => self::UID, 'user_login' => 'raw' . self::UID,
            'user_email' => 'raw' . self::UID . '@example.test', 'display_name' => 'Raw',
        ]), $wpdb->last_error);
    }

    private function cleanup(): void
    {
        global $wpdb;
        $wpdb->query('DELETE FROM `' . TableRegistry::userInfo() . '` WHERE user_id = ' . self::UID);
        wp_cache_delete('user_info_' . self::UID, 'bcc_trust');
    }
}
