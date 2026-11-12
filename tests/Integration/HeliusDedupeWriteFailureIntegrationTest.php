<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\HeliusSeenSignaturesRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-67 — HeliusSeenSignaturesRepository::markSeen() tells "already seen"
 * apart from "the write failed", against the REAL table.
 *
 * A DB error used to come back as `false` — the same value as a replay — so
 * the webhook skipped that transaction as already processed. With the
 * endpoint's always-200 posture and no Solana polling walker, its events
 * were then lost for good. It now comes back NULL (unknown).
 */
#[Group('integration')]
#[Group('mariadb')]
final class HeliusDedupeWriteFailureIntegrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $wpdb  = $GLOBALS['wpdb'];
        $table = HeliusSeenSignaturesRepository::table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            require_once dirname(__DIR__, 2) . '/includes/database/schema-helius-seen-signatures.php';
            bcc_onchain_create_helius_seen_signatures_table();
        }
        self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        $wpdb->query('DELETE FROM `' . HeliusSeenSignaturesRepository::table() . "` WHERE signature LIKE 'f67sig%'");
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $GLOBALS['wpdb']->query('DELETE FROM `' . HeliusSeenSignaturesRepository::table() . "` WHERE signature LIKE 'f67sig%'");
        parent::tearDown();
    }

    public function testANewSignatureIsTrueAndAReplayIsFalse(): void
    {
        self::assertTrue(HeliusSeenSignaturesRepository::markSeen('f67sig-new'));
        self::assertFalse(HeliusSeenSignaturesRepository::markSeen('f67sig-new'), 'the second sighting is a replay');
    }

    public function testAFailedWriteIsUnknownNotAReplay(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = '/INSERT IGNORE INTO `?\S*helius_seen_signatures/i';

        $result = HeliusSeenSignaturesRepository::markSeen('f67sig-fail');
        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
        $wpdb->clearFaultInjection();

        self::assertNull($result, 'a failed dedupe write is unknown, never "already seen"');
        self::assertTrue(
            HeliusSeenSignaturesRepository::markSeen('f67sig-fail'),
            'nothing was recorded, so the signature is still new once the DB recovers'
        );
    }

    public function testAnInvalidSignatureIsStillRefusedWithoutAWrite(): void
    {
        self::assertFalse(HeliusSeenSignaturesRepository::markSeen(''));
        self::assertFalse(HeliusSeenSignaturesRepository::markSeen(str_repeat('x', 97)));
    }
}
