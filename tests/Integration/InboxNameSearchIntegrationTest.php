<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\PeepSoMessageRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Structure A row 14: GET /me/conversations?q= on real tables, through
 * bcc-core's PeepSoMessageRepository::findConversationsMatchingOrUnknown()
 * and countConversationsMatchingOrUnknown().
 *
 * A conversation is found by a participant the viewer is SHOWN (F-512):
 *   - never the viewer themselves;
 *   - never a member either side has blocked (PeepSo's get_participants()
 *     leaves them out);
 *   - by @handle, or by display name only where others see it (never a
 *     hidden real name);
 *   - never by message text.
 * The count always counts the same conversations as the list.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InboxNameSearchIntegrationTest extends TestCase
{
    private const VIEWER  = 9315000; // "Viewer Vic", @vicv
    private const WREN    = 9315001; // "Wren Abbott", @heron-ops
    private const HIDDEN  = 9315002; // "Secret Person" (hidden), @sp2
    private const BLOCKED = 9315003; // "Blocked Bob", @bbob — the viewer blocks them
    private const BLOCKER = 9315004; // "Blocker Bea", @bbea — they block the viewer
    private const SHAPED  = 9315005; // "U_Former-Name": the card shows @shp5 instead

    private const C_WREN    = 931550001;
    private const C_HIDDEN  = 931550002;
    private const C_BLOCKED = 931550003;
    private const C_BLOCKER = 931550004;
    private const C_GROUP   = 931550005; // the viewer, Wren and Hidden
    private const C_SHAPED  = 931550006;

    /** @var list<string> */
    private array $createdTables = [];

    protected function setUp(): void
    {
        if (!method_exists(PeepSoMessageRepository::class, 'countConversationsMatchingOrUnknown')) {
            self::markTestSkipped('Needs bcc-core with the inbox search (structure A row 14, the core half).');
        }
        $this->ensureTables();
        $this->purge();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->purge();
        global $wpdb;
        foreach ($this->createdTables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    public function testNoQueryListsEveryConversationNewestFirst(): void
    {
        $rows = PeepSoMessageRepository::findConversationsForUserOrUnknown(self::VIEWER, 50, 0);

        self::assertSame(
            [self::C_GROUP, self::C_WREN, self::C_HIDDEN, self::C_BLOCKED, self::C_BLOCKER, self::C_SHAPED],
            self::ids($rows)
        );
        self::assertSame(6, PeepSoMessageRepository::countConversationsForUser(self::VIEWER));
        self::assertSame(self::ids($rows), self::ids(PeepSoMessageRepository::findConversationsMatchingOrUnknown(self::VIEWER, '  ', 50, 0)), 'an empty query is no filter');
    }

    /** @return iterable<string, array{string, list<int>}> */
    public static function searches(): iterable
    {
        yield 'a shown display name'                   => ['wren', [self::C_GROUP, self::C_WREN]];
        yield 'a handle, with its @'                   => ['@heron', [self::C_GROUP, self::C_WREN]];
        yield 'a hidden real name is never found'      => ['secret person', []];
        yield '... but that member\'s handle is'        => ['sp2', [self::C_GROUP, self::C_HIDDEN]];
        yield 'a member the viewer blocked: no name'   => ['blocked bob', []];
        yield '... and no handle'                      => ['bbob', []];
        yield 'a member blocking the viewer: no name'  => ['blocker bea', []];
        yield '... and no handle either'               => ['bbea', []];
        yield 'never the viewer\'s own name'           => ['viewer vic', []];
        yield '... or own handle'                      => ['vicv', []];
        yield 'never message text'                     => ['hello', []];
        yield 'a name the card replaces with the handle' => ['former-name', []];
        yield '... in any case'                        => ['U_FORMER', []];
        yield '... but their handle'                => ['shp5', [self::C_SHAPED]];
        yield 'never a login'                          => ['q15login1', []];
        yield 'a LIKE wildcard matches itself'         => ['%', []];
    }

    /** @param list<int> $expected */
    #[DataProvider('searches')]
    public function testAConversationIsFoundByAParticipantTheViewerIsShown(string $q, array $expected): void
    {
        $rows = PeepSoMessageRepository::findConversationsMatchingOrUnknown(self::VIEWER, $q, 50, 0);

        self::assertSame($expected, self::ids($rows), "q = '{$q}'");
        self::assertSame(count($expected), PeepSoMessageRepository::countConversationsMatchingOrUnknown(self::VIEWER, $q), 'the count counts the same conversations');
    }

    public function testTheSearchPagesLikeTheList(): void
    {
        self::assertSame([self::C_GROUP], self::ids(PeepSoMessageRepository::findConversationsMatchingOrUnknown(self::VIEWER, 'wren', 1, 0)));
        self::assertSame([self::C_WREN], self::ids(PeepSoMessageRepository::findConversationsMatchingOrUnknown(self::VIEWER, 'wren', 1, 1)));
    }

    // ── fixture ─────────────────────────────────────────────────────────

    /**
     * @param list<array{root_msg_id: int}>|null $rows
     * @return list<int>
     */
    private static function ids(?array $rows): array
    {
        self::assertIsArray($rows, 'the read ran');

        return array_map(static fn (array $r): int => (int) $r['root_msg_id'], $rows);
    }

    private function seed(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $users = [
            self::VIEWER  => ['q15login0', 'Viewer Vic', 'vicv', null],
            self::WREN    => ['q15login1', 'Wren Abbott', 'heron-ops', null],
            self::HIDDEN  => ['q15login2', 'Secret Person', 'sp2', '1'],
            self::BLOCKED => ['q15login3', 'Blocked Bob', 'bbob', null],
            self::BLOCKER => ['q15login4', 'Blocker Bea', 'bbea', null],
            self::SHAPED  => ['q15login5', 'U_Former-Name', 'shp5', null],
        ];
        foreach ($users as $uid => [$login, $name, $handle, $hidden]) {
            self::assertNotFalse($wpdb->insert($wpdb->users, ['ID' => $uid, 'user_login' => $login, 'display_name' => $name]), $wpdb->last_error);
            self::assertNotFalse($wpdb->insert($wpdb->usermeta, ['user_id' => $uid, 'meta_key' => 'bcc_handle', 'meta_value' => $handle]), $wpdb->last_error);
            if ($hidden !== null) {
                self::assertNotFalse($wpdb->insert($wpdb->usermeta, ['user_id' => $uid, 'meta_key' => 'bcc_privacy_real_name_hidden', 'meta_value' => $hidden]), $wpdb->last_error);
            }
        }

        $conversations = [
            self::C_WREN    => [[self::WREN], 0, '2026-01-01 00:00:05'],
            self::C_HIDDEN  => [[self::HIDDEN], 0, '2026-01-01 00:00:04'],
            self::C_BLOCKED => [[self::BLOCKED], 0, '2026-01-01 00:00:03'],
            self::C_BLOCKER => [[self::BLOCKER], 0, '2026-01-01 00:00:02'],
            self::C_GROUP   => [[self::WREN, self::HIDDEN], 1, '2026-01-01 00:00:06'],
            self::C_SHAPED  => [[self::SHAPED], 0, '2026-01-01 00:00:01'],
        ];
        foreach ($conversations as $root => [$others, $isGroup, $activity]) {
            // The root message, which the viewer received.
            self::assertNotFalse($wpdb->insert($wpdb->posts, [
                'ID' => $root, 'post_author' => $others[0], 'post_type' => 'peepso-message', 'post_status' => 'publish',
                'post_content' => 'hello hello', 'post_date_gmt' => $activity,
            ]), $wpdb->last_error);
            self::assertNotFalse($wpdb->insert($p . 'peepso_message_recipients', [
                'mrec_msg_id' => $root, 'mrec_parent_id' => 0, 'mrec_user_id' => self::VIEWER, 'mrec_viewed' => 0, 'mrec_deleted' => 0,
            ]), $wpdb->last_error);
            foreach ([self::VIEWER, ...$others] as $uid) {
                self::assertNotFalse($wpdb->insert($p . 'peepso_message_participants', [
                    'mpart_msg_id' => $root, 'mpart_user_id' => $uid, 'mpart_is_group' => $isGroup, 'mpart_last_activity' => $activity,
                ]), $wpdb->last_error);
            }
        }

        self::assertNotFalse($wpdb->insert($p . 'peepso_blocks', ['blk_user_id' => self::VIEWER, 'blk_blocked_id' => self::BLOCKED]), $wpdb->last_error);
        self::assertNotFalse($wpdb->insert($p . 'peepso_blocks', ['blk_user_id' => self::BLOCKER, 'blk_blocked_id' => self::VIEWER]), $wpdb->last_error);
    }

    private function purge(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->query($wpdb->prepare("DELETE FROM {$p}peepso_message_recipients WHERE mrec_msg_id BETWEEN %d AND %d", self::C_WREN, self::C_SHAPED));
        $wpdb->query($wpdb->prepare("DELETE FROM {$p}peepso_message_participants WHERE mpart_msg_id BETWEEN %d AND %d", self::C_WREN, self::C_SHAPED));
        $wpdb->query($wpdb->prepare("DELETE FROM {$p}peepso_blocks WHERE blk_user_id BETWEEN %d AND %d OR blk_blocked_id BETWEEN %d AND %d", self::VIEWER, self::BLOCKER, self::VIEWER, self::SHAPED));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->posts} WHERE ID BETWEEN %d AND %d", self::C_WREN, self::C_SHAPED));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE user_id BETWEEN %d AND %d", self::VIEWER, self::SHAPED));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->users} WHERE ID BETWEEN %d AND %d", self::VIEWER, self::SHAPED));
    }

    /**
     * PeepSo's three message tables and its blocks table, created ONLY when
     * absent and widened only with the columns the inbox read needs (another
     * suite may create stunted copies first). wp_users, wp_usermeta and
     * wp_posts come from the integration bootstrap.
     */
    private function ensureTables(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach (['peepso_message_participants', 'peepso_message_recipients', 'peepso_blocks'] as $suffix) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . $suffix)) !== $p . $suffix) {
                $this->createdTables[] = $p . $suffix;
            }
        }

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}peepso_message_participants` (
                mpart_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                mpart_msg_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                mpart_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                KEY msg_user (mpart_msg_id, mpart_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}peepso_message_recipients` (
                mrec_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                mrec_msg_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                mrec_parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                mrec_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}peepso_blocks` (
                blk_user_id BIGINT UNSIGNED NOT NULL,
                blk_blocked_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (blk_user_id, blk_blocked_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->ensureColumns($p . 'peepso_message_participants', [
            'mpart_is_group'      => 'TINYINT(1) NOT NULL DEFAULT 0',
            'mpart_last_activity' => 'DATETIME NULL',
            'mpart_muted'         => 'TINYINT(1) NOT NULL DEFAULT 0',
        ]);
        $this->ensureColumns($p . 'peepso_message_recipients', [
            'mrec_viewed'  => 'TINYINT(1) NOT NULL DEFAULT 0',
            'mrec_deleted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ]);
        $this->ensureColumns($wpdb->posts, [
            'post_author'   => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'post_date_gmt' => 'DATETIME NULL',
        ]);
    }

    /** @param array<string, string> $wanted */
    private function ensureColumns(string $table, array $wanted): void
    {
        global $wpdb;
        $existing = (array) $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));
        foreach ($wanted as $column => $definition) {
            if (!in_array($column, $existing, true)) {
                self::assertNotFalse($wpdb->query("ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}"), $wpdb->last_error);
            }
        }
    }
}
