<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\CollectionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-313 (M15): a failed verification UPDATE must not read as "0 changed".
 *
 * setVerifiedBulk() used to cast `$wpdb->query()`'s `false` to 0, so its one
 * caller (CommunityRequestService::applyVerification, inside a transaction)
 * committed and the admin saw "✓" for a write that never happened. It now
 * throws, so the transaction rolls back and the caller reports failure.
 *
 * Real SQL. Owns one row, keyed by its own contract address, deleted in
 * setUp and tearDown; never touches anything else in the table.
 */
#[Group('integration')]
#[Group('mariadb')]
final class CollectionSetVerifiedBulkFailureIntegrationTest extends TestCase
{
    private const CONTRACT = '0xf313f313f313f313f313f313f313f313f313f313';

    private int $id = 0;

    protected function setUp(): void
    {
        $this->cleanup();
        global $wpdb;
        $table = CollectionRepository::table();
        $ok = $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$table}` (contract_address, canonical_identifier, chain_id, collection_name,
                                     token_standard, fetched_at, expires_at, is_verified)
             VALUES (%s, %s, %d, %s, %s, NOW(), NOW(), 0)",
            self::CONTRACT,
            'f313-canonical',
            1,
            'F-313 fixture',
            'ERC-721'
        ));
        self::assertSame(1, $ok, 'seed: ' . $wpdb->last_error);
        $this->id = (int) $wpdb->insert_id;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->cleanup();
    }

    public function testAFailedVerifyUpdateThrowsInsteadOfReportingZeroChanged(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $GLOBALS['wpdb']->failQueriesMatching = '/SET is_verified = 1/';

        try {
            CollectionRepository::setVerifiedBulk([$this->id], []);
            self::fail('a failed UPDATE must throw, not return 0');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('verify UPDATE failed', $e->getMessage());
        } finally {
            $GLOBALS['wpdb']->clearFaultInjection();
        }

        self::assertSame(0, $this->isVerified(), 'nothing was written');
    }

    public function testAFailedUnverifyUpdateThrowsToo(): void
    {
        self::assertSame(1, CollectionRepository::setVerifiedBulk([$this->id], []));
        $GLOBALS['wpdb']->clearFaultInjection();
        $GLOBALS['wpdb']->failQueriesMatching = '/SET is_verified = 0/';

        try {
            CollectionRepository::setVerifiedBulk([], [$this->id]);
            self::fail('a failed UPDATE must throw, not return 0');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('unverify UPDATE failed', $e->getMessage());
        } finally {
            $GLOBALS['wpdb']->clearFaultInjection();
        }

        self::assertSame(1, $this->isVerified());
    }

    /** Control: the healthy path still counts real changes, and 0 for a no-op. */
    public function testAHealthyUpdateCountsRealChangesOnly(): void
    {
        self::assertSame(1, CollectionRepository::setVerifiedBulk([$this->id], []));
        self::assertSame(1, $this->isVerified());
        self::assertSame(0, CollectionRepository::setVerifiedBulk([$this->id], []), 'already verified: a genuine 0');
    }

    private function isVerified(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT is_verified FROM `' . CollectionRepository::table() . '` WHERE id = %d',
            $this->id
        ));
    }

    private function cleanup(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM `' . CollectionRepository::table() . '` WHERE contract_address = %s',
            self::CONTRACT
        ));
    }
}
