<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

/**
 * PeepSo's `peepso_blocks` table for suites whose code reads it (F-296: the
 * notification reads exclude blocked members and FAIL CLOSED, so a missing
 * table is a read failure, not "no blocks").
 *
 * Same ownership rule as FeedActivityFixture and BlockedSenderUnread: the
 * table is created only when absent and dropped only when this fixture
 * created it (PagePostsIntegrationTest and others create and drop it too).
 * Columns match PeepSo's (blk_user_id blocks blk_blocked_id).
 */
trait PeepSoBlocksTableFixture
{
    private bool $peepSoBlocksWasOurs = false;

    /** @var list<array{0: int, 1: int}> [blocker, blocked] rows seeded here */
    private array $seededBlocks = [];

    private function ensurePeepSoBlocksTable(): void
    {
        global $wpdb;

        $blocks = $wpdb->prefix . 'peepso_blocks';
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $blocks)) === $blocks;
        if ($exists) {
            return;
        }
        self::assertNotFalse($wpdb->query(
            "CREATE TABLE `{$blocks}` (
                blk_user_id BIGINT UNSIGNED NOT NULL,
                blk_blocked_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (blk_user_id, blk_blocked_id),
                KEY blk_blocked_id (blk_blocked_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ), 'create peepso_blocks: ' . $wpdb->last_error);
        $this->peepSoBlocksWasOurs = true;
    }

    private function seedBlock(int $blocker, int $blocked): void
    {
        global $wpdb;

        self::assertNotFalse($wpdb->query($wpdb->prepare(
            'INSERT INTO `' . $wpdb->prefix . 'peepso_blocks` (blk_user_id, blk_blocked_id) VALUES (%d, %d)',
            $blocker,
            $blocked
        )), $wpdb->last_error);
        $this->seededBlocks[] = [$blocker, $blocked];
    }

    private function removePeepSoBlocksTable(): void
    {
        global $wpdb;

        $blocks = $wpdb->prefix . 'peepso_blocks';
        if ($this->peepSoBlocksWasOurs) {
            $wpdb->query("DROP TABLE IF EXISTS `{$blocks}`");
            $this->peepSoBlocksWasOurs = false;
        } else {
            foreach ($this->seededBlocks as [$blocker, $blocked]) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM `{$blocks}` WHERE blk_user_id = %d AND blk_blocked_id = %d",
                    $blocker,
                    $blocked
                ));
            }
        }
        $this->seededBlocks = [];
    }
}
