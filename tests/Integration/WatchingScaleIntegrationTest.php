<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\PageFollowRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\WatchingRepository;
use BCC\Trust\Core\Repositories\WatchMetaRepository;
use BCC\Trust\Core\Services\ViewerWatchResolver;
use BCC\Trust\Core\Services\WatchingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-255 (user decision 2026-11-12): there is NO cap on how many things a
 * member can watch. 50 is a page size, nothing more.
 *
 * Real tables, a viewer watching 65 members (PeepSo follows) and 105
 * placeholder pages (bcc_page_follows), i.e. past both the old 50-row
 * page the client derived state from and the old 100-row page-follow cap:
 *   - the watchlist lists every one of the 170 exactly once across pages;
 *   - the 60th member and the 105th page carry their watch handle on the
 *     card (ViewerWatchResolver), and can be unwatched with it.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(WatchingService::class)]
#[CoversClass(ViewerWatchResolver::class)]
#[CoversClass(WatchingRepository::class)]
#[CoversClass(PageFollowRepository::class)]
final class WatchingScaleIntegrationTest extends TestCase
{
    private const VIEWER      = 9255000;
    private const MEMBER_BASE = 9255000; // members 9255001 … 9255065
    private const MEMBERS     = 65;
    private const PAGE_BASE   = 925500000; // pages 925500001 … 925500105
    private const PAGES       = 105;
    private const OWNED_PAGE  = 925600001; // a claimed page owned by member #60

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/watch-scale-stubs.php';
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

    /** @var list<string> tables this test created (dropped again in tearDown) */
    private array $createdTables = [];

    public function testEveryWatchIsListedExactlyOnceAcrossPages(): void
    {
        $service = $this->service();

        $seen  = [];
        $first = $service->getWatching(self::VIEWER, 1, 50);
        self::assertSame(self::MEMBERS + self::PAGES, $first['pagination']['total']);
        self::assertSame(4, $first['pagination']['total_pages']);

        for ($page = 1; $page <= $first['pagination']['total_pages']; $page++) {
            $out = $service->getWatching(self::VIEWER, $page, 50);
            self::assertLessThanOrEqual(50, count($out['items']), "page {$page} respects page_size");
            foreach ($out['items'] as $item) {
                $key = $item['follow_source'] . ':' . $item['follow_id'];
                self::assertArrayNotHasKey($key, $seen, "{$key} listed twice");
                $seen[$key] = true;
            }
        }

        self::assertCount(self::MEMBERS + self::PAGES, $seen, 'no watch is capped or skipped');
    }

    public function testThe60thMemberCardKnowsItIsWatchedAndUnwatches(): void
    {
        $member   = self::MEMBER_BASE + 60;
        $resolver = $this->resolver();

        $handle = $resolver->forMembers(self::VIEWER, [$member])[$member] ?? null;
        self::assertNotNull($handle, 'the 60th watched member reads as watched');
        self::assertSame('peepso', $handle['source']);
        self::assertSame($this->followIdOf($member), $handle['follow_id']);

        // A page this member owns IS watched too: watching a claimed page
        // is following its owner (the same rule watch() writes with).
        $pageHandle = $resolver->forPages(self::VIEWER, [self::OWNED_PAGE])[self::OWNED_PAGE] ?? null;
        self::assertSame($handle, $pageHandle);

        $result = $this->service()->unwatch(self::VIEWER, $handle['follow_id'], $handle['source']);
        self::assertSame('unwatched', $result['status'] ?? null, print_r($result, true));

        self::assertSame([], $resolver->forMembers(self::VIEWER, [$member]), 'no longer watched');
        self::assertSame(self::MEMBERS - 1, (new WatchingRepository())->countItemsForUser(self::VIEWER));
    }

    public function testThe105thPageCardKnowsItIsWatchedAndUnwatches(): void
    {
        $page     = self::PAGE_BASE + 105;
        $resolver = $this->resolver();

        $handle = $resolver->forPages(self::VIEWER, [$page])[$page] ?? null;
        self::assertNotNull($handle, 'a page follow past the old 100-row cap reads as watched');
        self::assertSame('page', $handle['source']);

        $result = $this->service()->unwatch(self::VIEWER, $handle['follow_id'], $handle['source']);
        self::assertSame('unwatched', $result['status'] ?? null, print_r($result, true));

        self::assertSame([], $resolver->forPages(self::VIEWER, [$page]));
    }

    public function testUnwatchedCardsAndAnonymousViewersReadAsNotWatching(): void
    {
        $resolver = $this->resolver();
        $stranger = self::MEMBER_BASE + 99; // never followed

        self::assertSame([], $resolver->forMembers(self::VIEWER, [$stranger]));
        self::assertSame([], $resolver->forMembers(0, [self::MEMBER_BASE + 1]));
        self::assertSame([], $resolver->forMembers(self::VIEWER, [self::VIEWER]), 'never one\'s own card');
    }

    // ── fixture ─────────────────────────────────────────────────────────

    private function service(): WatchingService
    {
        return new WatchingService(
            new WatchingRepository(),
            new WatchMetaRepository(),
            new ReputationRepository(),
            new PageFollowRepository()
        );
    }

    private function resolver(): ViewerWatchResolver
    {
        return new ViewerWatchResolver(new WatchingRepository(), new PageFollowRepository());
    }

    private function followIdOf(int $member): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT uf_id FROM {$wpdb->prefix}peepso_user_followers WHERE uf_active_user_id = %d AND uf_passive_user_id = %d",
            self::VIEWER,
            $member
        ));
    }

    private function seed(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        for ($i = 1; $i <= self::MEMBERS; $i++) {
            $uid = self::MEMBER_BASE + $i;
            self::assertNotFalse($wpdb->insert($wpdb->users, ['ID' => $uid, 'user_login' => 'f255_' . $i]), $wpdb->last_error);
            self::assertNotFalse($wpdb->insert($p . 'peepso_user_followers', [
                'uf_active_user_id'  => self::VIEWER,
                'uf_passive_user_id' => $uid,
                'uf_follow'          => 1,
            ]), $wpdb->last_error);
            self::assertNotFalse($wpdb->insert($p . 'bcc_watch_meta', [
                'follow_id'  => (int) $wpdb->insert_id,
                'watched_at' => sprintf('2026-01-01 00:%02d:00', $i % 60),
            ]), $wpdb->last_error);
        }

        for ($i = 1; $i <= self::PAGES; $i++) {
            $pid = self::PAGE_BASE + $i;
            self::assertNotFalse($wpdb->insert($wpdb->posts, [
                'ID' => $pid, 'post_type' => 'peepso-page', 'post_status' => 'publish', 'post_author' => 0,
                'post_title' => 'F255 page ' . $i, 'post_name' => 'f255-page-' . $i,
            ]), $wpdb->last_error);
            self::assertNotFalse($wpdb->insert($p . 'bcc_page_follows', [
                'user_id' => self::VIEWER, 'page_id' => $pid, 'card_kind' => 'validator',
                'created_at' => sprintf('2026-01-02 %02d:%02d:00', intdiv($i, 60), $i % 60),
            ]), $wpdb->last_error);
        }

        self::assertNotFalse($wpdb->insert($wpdb->posts, [
            'ID' => self::OWNED_PAGE, 'post_type' => 'peepso-page', 'post_status' => 'publish',
            'post_author' => self::MEMBER_BASE + 60, 'post_title' => 'F255 owned', 'post_name' => 'f255-owned',
        ]), $wpdb->last_error);
    }

    private function purge(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->query($wpdb->prepare(
            "DELETE m FROM {$p}bcc_watch_meta m
               JOIN {$p}peepso_user_followers f ON f.uf_id = m.follow_id
              WHERE f.uf_active_user_id = %d",
            self::VIEWER
        ));
        $wpdb->query($wpdb->prepare("DELETE FROM {$p}peepso_user_followers WHERE uf_active_user_id = %d", self::VIEWER));
        $wpdb->query($wpdb->prepare("DELETE FROM {$p}bcc_page_follows WHERE user_id = %d", self::VIEWER));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->users} WHERE ID BETWEEN %d AND %d",
            self::MEMBER_BASE + 1,
            self::MEMBER_BASE + self::MEMBERS
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE (ID BETWEEN %d AND %d) OR ID = %d",
            self::PAGE_BASE + 1,
            self::PAGE_BASE + self::PAGES,
            self::OWNED_PAGE
        ));
    }

    /**
     * Tables this test reads, created ONLY when absent and widened only
     * with the columns it needs: another suite creates stunted copies of
     * peepso_user_followers / bcc_watch_meta with IF NOT EXISTS, so the
     * schema here must not be assumed from the suite order.
     */
    private function ensureTables(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach (['peepso_user_followers', 'usermeta', 'bcc_watch_meta', 'bcc_page_follows'] as $suffix) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . $suffix)) !== $p . $suffix) {
                $this->createdTables[] = $p . $suffix;
            }
        }

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}peepso_user_followers` (
                uf_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                uf_passive_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uf_active_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uf_follow TINYINT(1) NOT NULL DEFAULT 1
            )"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}usermeta` (
                umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                meta_key VARCHAR(255) NULL,
                meta_value LONGTEXT NULL
            )"
        );
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . 'bcc_watch_meta')) !== $p . 'bcc_watch_meta') {
            require_once dirname(__DIR__, 2) . '/includes/database/schema-watch-meta.php';
            \bcc_trust_create_watch_meta_table();
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . 'bcc_page_follows')) !== $p . 'bcc_page_follows') {
            require_once dirname(__DIR__, 2) . '/includes/database/schema-page-follows.php';
            \bcc_trust_create_page_follows_table();
        }

        $this->ensureColumns($p . 'peepso_user_followers', [
            'uf_active_user_id' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'uf_follow'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        ]);
        $this->ensureColumns($p . 'bcc_watch_meta', [
            'tier_at_watch' => 'VARCHAR(20) DEFAULT NULL',
            'batch_id'      => 'VARCHAR(64) DEFAULT NULL',
            'watched_at'    => 'DATETIME NULL',
        ]);
        $this->ensureColumns($wpdb->posts, [
            'post_author' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
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
