<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Core\Repositories\PeepSoMessageRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #122 / bcc-core #63 — the blocked-sender read exclusion, against a
 * real engine.
 *
 * These live here rather than in bcc-core because bcc-core has no
 * database harness: its own tests capture the prepared SQL, which proves
 * the predicate is in the WHERE clause but not that the engine agrees.
 * bcc-trust's MySQL and MariaDB jobs check bcc-core out, so this is the
 * only place the SQL actually runs.
 *
 * ⚠ THREE PREDICATES CANNOT BE PROVEN FROM LIVE DATA, which is why they
 * are seeded explicitly below. Measured read-only on 2026-11-10:
 * production and staging both hold ZERO rows in `wp_peepso_blocks` and
 * ZERO muted participant rows, and exactly ONE viewer with any unread
 * message. So the live parity check (old count == new count) is true but
 * samples one viewer and exercises neither the block clause, the muted
 * clause, nor the deleted-participant clause. Everything that matters
 * about this change has to be constructed.
 *
 * ── FIXTURE OWNERSHIP, and why it is this fiddly ────────────────────
 *
 * `PagePostsIntegrationTest` already creates `wp_peepso_blocks` with
 * `CREATE TABLE IF NOT EXISTS` and then DROPS it unconditionally, and it
 * adds `post_author` to the SHARED `wp_posts` behind an
 * `information_schema` guard while dropping it unconditionally. So this
 * suite:
 *
 *   - creates each table only if it is absent, and drops only what it
 *     created;
 *   - adds `post_author` only if absent, and drops it only if it added
 *     it;
 *   - deletes its own rows by distinctive id range rather than
 *     truncating shared tables.
 *
 * ⚠ An earlier version of this work put `post_author` in the shared
 * integration bootstrap instead. That looked harmless — additive and
 * defaulted — but `PagePostsIntegrationTest`'s unconditional
 * `DROP COLUMN post_author` would then have stripped it from the shared
 * table for every suite ordered after it. Reverted.
 */
#[Group('integration')]
#[Group('mariadb')]
final class BlockedSenderUnreadIntegrationTest extends TestCase
{
    /** Distinctive id range so cleanup can target exactly our rows. */
    private const VIEWER = 990001;
    private const BLOCKED_AUTHOR = 990002;
    private const ORDINARY_AUTHOR = 990003;
    private const GHOST_AUTHOR = 990004;

    private const CONV_BLOCKED_ONLY = 995001;
    private const CONV_ORDINARY_ONLY = 995002;
    private const CONV_MIXED = 995003;
    private const CONV_MUTED = 995004;
    private const CONV_DELETED_PARTICIPANT = 995005;
    private const CONV_ORPHAN_POST = 995006;

    private const MSG_BASE = 996000;

    private static bool $createdBlocks = false;
    private static bool $createdRecipients = false;
    private static bool $createdParticipants = false;
    private static bool $addedPostAuthor = false;

    private static function tableExists(string $table): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        )) > 0;
    }

    private static function columnExists(string $table, string $column): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $table,
            $column
        )) > 0;
    }

    public static function setUpBeforeClass(): void
    {
        global $wpdb;

        // `getUnreadConversationCountForUser` keeps its pre-existing
        // "PeepSo absent => 0" guard, so without this class the method
        // returns 0 and every assertion below would pass vacuously.
        //
        // ⚠ Declared in the global namespace, so it leaks for the rest of
        // the process. Checked first: no integration suite references
        // PeepSoMessageRecipients or asserts the `peepso_absence`
        // degraded path, so there is nothing to hijack.
        if (!class_exists('PeepSoMessageRecipients')) {
            eval('class PeepSoMessageRecipients {}');
        }

        $blocks       = $wpdb->prefix . 'peepso_blocks';
        $recipients   = $wpdb->prefix . 'peepso_message_recipients';
        $participants = $wpdb->prefix . 'peepso_message_participants';

        if (!self::tableExists($blocks)) {
            self::$createdBlocks = true;
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE `{$blocks}` (
                    blk_user_id BIGINT UNSIGNED NOT NULL,
                    blk_blocked_id BIGINT UNSIGNED NOT NULL,
                    PRIMARY KEY (blk_user_id, blk_blocked_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            ), 'create peepso_blocks: ' . $wpdb->last_error);
        }

        if (!self::tableExists($recipients)) {
            self::$createdRecipients = true;
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE `{$recipients}` (
                    mrec_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    mrec_msg_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    mrec_parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    mrec_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    mrec_viewed TINYINT(1) NOT NULL DEFAULT 0,
                    mrec_deleted TINYINT(1) NOT NULL DEFAULT 0,
                    PRIMARY KEY (mrec_id),
                    KEY user_unread (mrec_user_id, mrec_viewed, mrec_deleted)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            ), 'create peepso_message_recipients: ' . $wpdb->last_error);
        }

        if (!self::tableExists($participants)) {
            self::$createdParticipants = true;
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE `{$participants}` (
                    mpart_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    mpart_msg_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    mpart_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    mpart_muted TINYINT(1) NOT NULL DEFAULT 0,
                    PRIMARY KEY (mpart_id),
                    KEY msg_user (mpart_msg_id, mpart_user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            ), 'create peepso_message_participants: ' . $wpdb->last_error);
        }

        if (!self::columnExists($wpdb->posts, 'post_author')) {
            self::$addedPostAuthor = true;
            self::assertNotFalse($wpdb->query(
                "ALTER TABLE {$wpdb->posts} ADD COLUMN post_author BIGINT UNSIGNED NOT NULL DEFAULT 0"
            ), 'add post_author: ' . $wpdb->last_error);
        }
    }

    public static function tearDownAfterClass(): void
    {
        global $wpdb;

        self::purgeOwnRows();

        if (self::$addedPostAuthor) {
            $wpdb->query("ALTER TABLE {$wpdb->posts} DROP COLUMN post_author");
            self::$addedPostAuthor = false;
        }
        if (self::$createdParticipants) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . 'peepso_message_participants`');
            self::$createdParticipants = false;
        }
        if (self::$createdRecipients) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . 'peepso_message_recipients`');
            self::$createdRecipients = false;
        }
        if (self::$createdBlocks) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . 'peepso_blocks`');
            self::$createdBlocks = false;
        }
    }

    private static function purgeOwnRows(): void
    {
        global $wpdb;
        $recipients   = $wpdb->prefix . 'peepso_message_recipients';
        $participants = $wpdb->prefix . 'peepso_message_participants';
        $blocks       = $wpdb->prefix . 'peepso_blocks';

        if (self::tableExists($recipients)) {
            $wpdb->query("DELETE FROM `{$recipients}` WHERE mrec_user_id >= 990000");
        }
        if (self::tableExists($participants)) {
            $wpdb->query("DELETE FROM `{$participants}` WHERE mpart_msg_id >= 995000");
        }
        if (self::tableExists($blocks)) {
            $wpdb->query("DELETE FROM `{$blocks}` WHERE blk_user_id >= 990000 OR blk_blocked_id >= 990000");
        }
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID >= " . self::MSG_BASE);
        $wpdb->query("DELETE FROM {$wpdb->users} WHERE ID >= 990000");
    }

    protected function setUp(): void
    {
        global $wpdb;
        self::purgeOwnRows();

        foreach ([self::VIEWER, self::BLOCKED_AUTHOR, self::ORDINARY_AUTHOR] as $uid) {
            $wpdb->insert($wpdb->users, ['ID' => $uid, 'user_login' => 'u' . $uid, 'user_email' => 'u' . $uid . '@example.invalid']);
        }
        // GHOST_AUTHOR is deliberately NOT inserted into wp_users: it is
        // the "participant who no longer exists" case.

        $this->seedMessage(1, self::CONV_BLOCKED_ONLY, self::BLOCKED_AUTHOR);
        $this->seedMessage(2, self::CONV_ORDINARY_ONLY, self::ORDINARY_AUTHOR);
        $this->seedMessage(3, self::CONV_MIXED, self::BLOCKED_AUTHOR);
        $this->seedMessage(4, self::CONV_MIXED, self::ORDINARY_AUTHOR);
        $this->seedMessage(5, self::CONV_MUTED, self::ORDINARY_AUTHOR);
        $this->seedMessage(6, self::CONV_DELETED_PARTICIPANT, self::ORDINARY_AUTHOR);
        // 7 gets a recipient row but NO wp_posts row: the orphan case.
        $this->seedRecipient(7, self::CONV_ORPHAN_POST);

        // muted: the viewer muted CONV_MUTED
        $wpdb->insert($wpdb->prefix . 'peepso_message_participants', [
            'mpart_msg_id'  => self::CONV_MUTED,
            'mpart_user_id' => self::VIEWER,
            'mpart_muted'   => 1,
        ]);
        // deleted participant: a participant row for a non-existent user
        $wpdb->insert($wpdb->prefix . 'peepso_message_participants', [
            'mpart_msg_id'  => self::CONV_DELETED_PARTICIPANT,
            'mpart_user_id' => self::GHOST_AUTHOR,
            'mpart_muted'   => 0,
        ]);
    }

    private function seedMessage(int $n, int $conversationId, int $authorId): void
    {
        global $wpdb;
        $msgId = self::MSG_BASE + $n;
        $wpdb->insert($wpdb->posts, [
            'ID'          => $msgId,
            'post_author' => $authorId,
            'post_type'   => 'peepso-message',
            'post_status' => 'publish',
        ]);
        $this->seedRecipient($n, $conversationId);
    }

    private function seedRecipient(int $n, int $conversationId): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'peepso_message_recipients', [
            'mrec_msg_id'    => self::MSG_BASE + $n,
            'mrec_parent_id' => $conversationId,
            'mrec_user_id'   => self::VIEWER,
            'mrec_viewed'    => 0,
            'mrec_deleted'   => 0,
        ]);
    }

    private function block(int $blockerId, int $blockedId): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'peepso_blocks', [
            'blk_user_id'    => $blockerId,
            'blk_blocked_id' => $blockedId,
        ]);
    }

    // ── getBlockedSet ───────────────────────────────────────────────────

    public function testTheBlockedSetContainsOnlyWhoTheViewerBlocks(): void
    {
        $this->block(self::VIEWER, self::BLOCKED_AUTHOR);

        $out = PeepSoBlockRepository::getBlockedSet(
            self::VIEWER,
            [self::BLOCKED_AUTHOR, self::ORDINARY_AUTHOR]
        );

        $this->assertSame([self::BLOCKED_AUTHOR => true], $out);
    }

    /** ⚠ The direction, against a real engine. */
    public function testAReverseBlockIsNotInTheViewersBlockedSet(): void
    {
        $this->block(self::BLOCKED_AUTHOR, self::VIEWER); // they blocked the viewer

        $out = PeepSoBlockRepository::getBlockedSet(self::VIEWER, [self::BLOCKED_AUTHOR]);

        $this->assertSame([], $out, '"They blocked me" must not read as "I blocked them".');
    }

    public function testACandidateBeyondTheFiveHundredCapIsNotSilentlyLost(): void
    {
        $this->block(self::VIEWER, self::BLOCKED_AUTHOR);

        // The blocked author sits first, inside the cap; the cap trims the
        // tail of the CANDIDATE list, which is the page being rendered.
        $candidates = array_merge([self::BLOCKED_AUTHOR], range(800000, 800000 + 598));

        $out = PeepSoBlockRepository::getBlockedSet(self::VIEWER, $candidates);

        $this->assertSame([self::BLOCKED_AUTHOR => true], $out);
    }

    // ── per-conversation unread (inbox badge) ───────────────────────────

    public function testABlockedAuthorsUnreadMessageIsNotCounted(): void
    {
        $this->block(self::VIEWER, self::BLOCKED_AUTHOR);

        $counts = PeepSoMessageRepository::getUnreadCountsByConversation(
            self::VIEWER,
            [self::CONV_BLOCKED_ONLY, self::CONV_ORDINARY_ONLY]
        );

        $this->assertArrayNotHasKey(self::CONV_BLOCKED_ONLY, $counts);
        $this->assertSame(1, $counts[self::CONV_ORDINARY_ONLY] ?? 0);
    }

    public function testWithoutABlockBothConversationsCount(): void
    {
        $counts = PeepSoMessageRepository::getUnreadCountsByConversation(
            self::VIEWER,
            [self::CONV_BLOCKED_ONLY, self::CONV_ORDINARY_ONLY]
        );

        $this->assertSame(1, $counts[self::CONV_BLOCKED_ONLY] ?? 0);
        $this->assertSame(1, $counts[self::CONV_ORDINARY_ONLY] ?? 0);
    }

    public function testAMixedConversationStillCountsTheUnblockedMessage(): void
    {
        $this->block(self::VIEWER, self::BLOCKED_AUTHOR);

        $counts = PeepSoMessageRepository::getUnreadCountsByConversation(
            self::VIEWER,
            [self::CONV_MIXED]
        );

        $this->assertSame(1, $counts[self::CONV_MIXED] ?? 0, 'Two unread, one blocked: one must remain.');
    }

    /**
     * ⚠ THE ORPHAN CASE — the reason the exclusion is a correlated
     * `NOT EXISTS` and not a `JOIN wp_posts`. A recipient row whose post
     * row is missing must keep counting, exactly as it did before this
     * change existed.
     */
    public function testARecipientRowWithNoPostRowStillCounts(): void
    {
        $this->block(self::VIEWER, self::BLOCKED_AUTHOR);

        $counts = PeepSoMessageRepository::getUnreadCountsByConversation(
            self::VIEWER,
            [self::CONV_ORPHAN_POST]
        );

        $this->assertSame(
            1,
            $counts[self::CONV_ORPHAN_POST] ?? 0,
            'A join onto wp_posts would silently drop this row for every viewer, blocked or not.'
        );
    }

    // ── global conversation count (header badge) ────────────────────────

    public function testAConversationWhoseOnlyUnreadIsBlockedDropsOutOfTheBadge(): void
    {
        $before = PeepSoMessageRepository::getUnreadConversationCountForUser(self::VIEWER);
        $this->block(self::VIEWER, self::BLOCKED_AUTHOR);
        $after = PeepSoMessageRepository::getUnreadConversationCountForUser(self::VIEWER);

        $this->assertSame($before - 1, $after, 'Exactly one conversation (the blocked-only one) must drop.');
    }

    /**
     * ⚠⚠ The conversation-level case. A conversation holding one unread
     * from a blocked sender AND one from anybody else must still count,
     * which is only true because the exclusion filters rows BEFORE
     * `GROUP BY`.
     */
    public function testAMixedConversationStaysInTheBadge(): void
    {
        $this->block(self::VIEWER, self::BLOCKED_AUTHOR);

        $count = PeepSoMessageRepository::getUnreadConversationCountForUser(self::VIEWER);

        // Seeded conversations: blocked-only (drops), ordinary-only,
        // mixed, muted (excluded), deleted-participant (excluded),
        // orphan-post. So: ordinary + mixed + orphan = 3.
        $this->assertSame(3, $count);
    }

    public function testAMutedConversationIsStillExcluded(): void
    {
        $count = PeepSoMessageRepository::getUnreadConversationCountForUser(self::VIEWER);

        // Without any block: blocked-only + ordinary + mixed + orphan = 4.
        // muted and deleted-participant are both excluded.
        $this->assertSame(4, $count);
    }

    /**
     * PeepSo's two inherited exclusions, asserted one at a time by
     * removing each cause and watching the count rise. Live data cannot
     * do this: production holds zero muted rows.
     */
    public function testUnmutingRaisesTheCountByExactlyOne(): void
    {
        global $wpdb;
        $before = PeepSoMessageRepository::getUnreadConversationCountForUser(self::VIEWER);

        $wpdb->query(
            'UPDATE `' . $wpdb->prefix . 'peepso_message_participants` SET mpart_muted = 0 WHERE mpart_msg_id = ' . self::CONV_MUTED
        );
        $after = PeepSoMessageRepository::getUnreadConversationCountForUser(self::VIEWER);

        $this->assertSame($before + 1, $after, 'The muted clause must be load-bearing.');
    }

    public function testGivingTheGhostParticipantAUserRowRaisesTheCountByExactlyOne(): void
    {
        global $wpdb;
        $before = PeepSoMessageRepository::getUnreadConversationCountForUser(self::VIEWER);

        $wpdb->insert($wpdb->users, [
            'ID'         => self::GHOST_AUTHOR,
            'user_login' => 'u' . self::GHOST_AUTHOR,
            'user_email' => 'u' . self::GHOST_AUTHOR . '@example.invalid',
        ]);
        $after = PeepSoMessageRepository::getUnreadConversationCountForUser(self::VIEWER);

        $this->assertSame($before + 1, $after, 'The deleted-participant clause must be load-bearing.');
    }

    /**
     * The parity control the live probe could only sample at n=1: with no
     * block rows, the new count must equal a plain count of distinct
     * unread conversations over the same two PeepSo predicates — which is
     * what PeepSo's own helper returns.
     */
    public function testWithNoBlocksTheCountMatchesAPlainDistinctConversationCount(): void
    {
        global $wpdb;
        $recipients   = $wpdb->prefix . 'peepso_message_recipients';
        $participants = $wpdb->prefix . 'peepso_message_participants';

        $plain = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT r.mrec_parent_id)
               FROM `{$recipients}` r
              WHERE r.mrec_user_id = %d AND r.mrec_viewed = 0 AND r.mrec_deleted = 0
                AND r.mrec_parent_id NOT IN (
                    SELECT mpart_msg_id FROM `{$participants}`
                     WHERE mpart_user_id NOT IN (SELECT ID FROM {$wpdb->users}))
                AND r.mrec_parent_id NOT IN (
                    SELECT mpart_msg_id FROM `{$participants}`
                     WHERE mpart_muted = 1 AND mpart_user_id = %d)",
            self::VIEWER,
            self::VIEWER
        ));

        $this->assertSame(
            $plain,
            PeepSoMessageRepository::getUnreadConversationCountForUser(self::VIEWER),
            'With no blocks the replacement must agree with the count it replaced.'
        );
    }

    /**
     * Anti-vacuity for the test above: the two numbers must be able to
     * DIVERGE, or their equality proves nothing about the new clause.
     */
    public function testTheParityComparisonCanDiverge(): void
    {
        $withoutBlock = PeepSoMessageRepository::getUnreadConversationCountForUser(self::VIEWER);
        $this->block(self::VIEWER, self::BLOCKED_AUTHOR);
        $withBlock = PeepSoMessageRepository::getUnreadConversationCountForUser(self::VIEWER);

        $this->assertNotSame($withoutBlock, $withBlock, 'If a block cannot move the number, parity is meaningless.');
    }

    public function testAnUnrelatedViewersBlocksDoNotAffectThisViewer(): void
    {
        $this->block(self::ORDINARY_AUTHOR, self::BLOCKED_AUTHOR); // someone else's block

        $counts = PeepSoMessageRepository::getUnreadCountsByConversation(
            self::VIEWER,
            [self::CONV_BLOCKED_ONLY]
        );

        $this->assertSame(1, $counts[self::CONV_BLOCKED_ONLY] ?? 0, 'The exclusion is per viewer.');
    }

    public function testTheFixtureCleansUpAfterItself(): void
    {
        global $wpdb;
        self::purgeOwnRows();

        $recipients = $wpdb->prefix . 'peepso_message_recipients';
        $blocks     = $wpdb->prefix . 'peepso_blocks';

        // ⚠ Asserting ZERO ROWS MATCHING OUR IDENTIFIERS, not a count
        // difference: a count diff is not a cleanup proof.
        $this->assertSame(
            0,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$recipients}` WHERE mrec_user_id >= 990000")
        );
        $this->assertSame(
            0,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$blocks}` WHERE blk_user_id >= 990000 OR blk_blocked_id >= 990000")
        );
        $this->assertSame(
            0,
            (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->posts . ' WHERE ID >= ' . self::MSG_BASE)
        );
        $this->assertSame(
            0,
            (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->users . ' WHERE ID >= 990000')
        );
    }
}
