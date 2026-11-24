<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\UserMetaRepository;
use BCC\Trust\Core\Repositories\PageFollowRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\WatchingRepository;
use BCC\Trust\Core\Repositories\WatchMetaRepository;
use BCC\Trust\Core\Services\WatchingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Structure A row 14: GET /me/watching?q= on real tables.
 *
 * The list is searched by what it SHOWS, and nothing else (F-512):
 *   - a followed member by @handle (or member-{id} with none), or by display
 *     name only where others see it: never a hidden real name (anything not
 *     plainly off hides it), never a name that is the login, never the login;
 *   - a followed member by the title or slug of one of their published,
 *     non-secret pages; never a secret page's title;
 *   - a followed placeholder page by its title or slug, when it is published
 *     and not secret.
 * `total` counts the narrowed list, and only the viewer's own list is read.
 *
 * The SQL is bcc-core's shared filter (UserMetaRepository::
 * publicNameMatchSql()), so this suite needs a bcc-core that has it.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(WatchingService::class)]
#[CoversClass(WatchingRepository::class)]
#[CoversClass(PageFollowRepository::class)]
final class WatchingNameSearchIntegrationTest extends TestCase
{
    private const VIEWER = 9314000;

    private const SHOWN_NAME    = 9314001; // handle redlion-op, name "Ada Quill"
    private const HIDDEN_NAME   = 9314002; // handle zed, name hidden ('1')
    private const LOGIN_NAME    = 9314003; // handle yax, display name = login
    private const PAGE_OWNER    = 9314004; // owns the published page "Kestrel Validator"
    private const SECRET_OWNER  = 9314005; // owns only a SECRET page "Osprey Secret"
    private const MAYBE_HIDDEN  = 9314006; // name hidden by a value that is not plainly off
    private const NO_HANDLE     = 9314007; // no bcc_handle row: shown as member-9314007
    private const SIGNUP_SHAPED = 9314008; // display name "U_OldHandle9": the card shows the handle
    private const NOT_FOLLOWED  = 9314009; // "Ada Quill Two", not on the viewer's list

    private const OWNED_PAGE    = 931400201;
    private const SECRET_PAGE   = 931400202;
    private const PLACEHOLDER   = 931400101; // "Heron Labs"
    private const SECRET_PLACE  = 931400102; // "Secret Heron"
    private const DRAFT_PLACE   = 931400103; // "Draft Heron"

    /** @var list<string> */
    private array $createdTables = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/watch-scale-stubs.php';
        if (!method_exists(UserMetaRepository::class, 'publicNameMatchSql')) {
            self::markTestSkipped('Needs bcc-core with UserMetaRepository::publicNameMatchSql() (structure A row 14, the core half).');
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

    /** @return iterable<string, array{string, list<string>}> query => the items it finds, by key */
    public static function searches(): iterable
    {
        yield 'a shown display name'                => ['ada', ['peepso:' . self::SHOWN_NAME]];
        yield 'a handle, with its @'                => ['@redlion', ['peepso:' . self::SHOWN_NAME]];
        yield 'a hidden real name is never found'   => ['hidden name', []];
        yield '... but its handle is'               => ['zed', ['peepso:' . self::HIDDEN_NAME]];
        yield 'a login is never found'              => ['q14login3', []];
        yield '... nor a name that is the login'    => ['Q14LOGIN3', []];
        yield 'a member found by their page title'  => ['kestrel', ['peepso:' . self::PAGE_OWNER]];
        yield '... or its slug'                     => ['kestrel-validator', ['peepso:' . self::PAGE_OWNER]];
        yield 'a secret page title is never found'  => ['osprey', []];
        yield 'not plainly off hides the name'      => ['maybe person', []];
        yield 'the handle fallback'                 => ['member-9314007', ['peepso:' . self::NO_HANDLE]];
        yield 'a name the card replaces with the handle' => ['oldhandle', []];
        yield '... in any case'                     => ['U_OLD', []];
        yield '... but their handle'             => ['newh8', ['peepso:' . self::SIGNUP_SHAPED]];
        yield 'a placeholder page by title'         => ['heron', ['page:' . self::PLACEHOLDER]];
        yield '... or slug'                         => ['heron-labs', ['page:' . self::PLACEHOLDER]];
        yield 'a LIKE wildcard matches itself'      => ['%', []];
        yield 'nothing matches'                     => ['no-such-thing', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('searches')]
    public function testTheListIsSearchedByWhatItShows(string $q, array $expected): void
    {
        $out = $this->service()->getWatching(self::VIEWER, 1, 50, false, $q);
        self::assertIsArray($out);

        $found = self::keys($out['items']);
        sort($found);
        sort($expected);
        self::assertSame($expected, $found, "q = '{$q}'");
        self::assertSame(count($expected), $out['pagination']['total'], 'total counts the narrowed list');
    }

    public function testNoQueryListsEverything(): void
    {
        $out = $this->service()->getWatching(self::VIEWER, 1, 50, false, '');
        self::assertIsArray($out);

        self::assertSame(11, $out['pagination']['total'], 'eight members and three placeholder pages');
        self::assertCount(11, $out['items']);
    }

    public function testOnlyTheViewersOwnListIsSearched(): void
    {
        $out = $this->service()->getWatching(self::VIEWER, 1, 50, false, 'ada quill');
        self::assertIsArray($out);

        self::assertSame(['peepso:' . self::SHOWN_NAME], self::keys($out['items']), 'not the member the viewer does not follow');
    }

    public function testAQueryThatIsNotUtf8FindsNothing(): void
    {
        $out = $this->service()->getWatching(self::VIEWER, 1, 50, false, "ad\xffa");
        self::assertIsArray($out);

        self::assertSame([], $out['items']);
        self::assertSame(0, $out['pagination']['total']);
    }

    // ── fixture ─────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $items
     * @return list<string>
     */
    private static function keys(array $items): array
    {
        return array_map(
            static fn (array $i): string => $i['follow_source'] . ':' . ($i['follow_source'] === 'page' ? $i['page_id'] : $i['card_id']),
            $items
        );
    }

    private function service(): WatchingService
    {
        return new WatchingService(
            new WatchingRepository(),
            new WatchMetaRepository(),
            new ReputationRepository(),
            new PageFollowRepository()
        );
    }

    private function seed(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $members = [
            self::SHOWN_NAME   => ['q14login1', 'Ada Quill', 'redlion-op', null],
            self::HIDDEN_NAME  => ['q14login2', 'Hidden Name', 'zed', '1'],
            self::LOGIN_NAME   => ['q14login3', 'q14login3', 'yax', null],
            self::PAGE_OWNER   => ['q14login4', 'Owner Four', 'owner4', null],
            self::SECRET_OWNER => ['q14login5', 'Owner Five', 'owner5', null],
            self::MAYBE_HIDDEN => ['q14login6', 'Maybe Person', 'mp6', 'maybe'],
            self::NO_HANDLE    => ['q14login7', 'Seven', null, null],
            self::SIGNUP_SHAPED => ['q14login8', 'U_OldHandle9', 'newh8', null],
        ];
        foreach ($members as $uid => [$login, $name, $handle, $hidden]) {
            self::assertNotFalse($wpdb->insert($wpdb->users, ['ID' => $uid, 'user_login' => $login, 'display_name' => $name]), $wpdb->last_error);
            if ($handle !== null) {
                self::assertNotFalse($wpdb->insert($wpdb->usermeta, ['user_id' => $uid, 'meta_key' => 'bcc_handle', 'meta_value' => $handle]), $wpdb->last_error);
            }
            if ($hidden !== null) {
                self::assertNotFalse($wpdb->insert($wpdb->usermeta, ['user_id' => $uid, 'meta_key' => 'bcc_privacy_real_name_hidden', 'meta_value' => $hidden]), $wpdb->last_error);
            }
            self::assertNotFalse($wpdb->insert($p . 'peepso_user_followers', [
                'uf_active_user_id'  => self::VIEWER,
                'uf_passive_user_id' => $uid,
                'uf_follow'          => 1,
            ]), $wpdb->last_error);
        }
        self::assertNotFalse($wpdb->insert($wpdb->users, ['ID' => self::NOT_FOLLOWED, 'user_login' => 'q14login9', 'display_name' => 'Ada Quill Two']), $wpdb->last_error);

        $pages = [
            self::OWNED_PAGE   => [self::PAGE_OWNER, 'Kestrel Validator', 'kestrel-validator', 'publish', false],
            self::SECRET_PAGE  => [self::SECRET_OWNER, 'Osprey Secret', 'osprey-secret', 'publish', true],
            self::PLACEHOLDER  => [0, 'Heron Labs', 'heron-labs', 'publish', false],
            self::SECRET_PLACE => [0, 'Secret Heron', 'secret-heron', 'publish', true],
            self::DRAFT_PLACE  => [0, 'Draft Heron', 'draft-heron', 'draft', false],
        ];
        foreach ($pages as $pid => [$author, $title, $slug, $status, $secret]) {
            self::assertNotFalse($wpdb->insert($wpdb->posts, [
                'ID' => $pid, 'post_type' => 'peepso-page', 'post_status' => $status, 'post_author' => $author,
                'post_title' => $title, 'post_name' => $slug,
            ]), $wpdb->last_error);
            if ($secret) {
                self::assertNotFalse($wpdb->insert($wpdb->postmeta, [
                    'post_id' => $pid, 'meta_key' => 'peepso_page_privacy', 'meta_value' => '2',
                ]), $wpdb->last_error);
            }
            if ($author === 0) {
                self::assertNotFalse($wpdb->insert($p . 'bcc_page_follows', [
                    'user_id' => self::VIEWER, 'page_id' => $pid, 'card_kind' => 'validator',
                    'created_at' => '2026-01-02 00:00:00',
                ]), $wpdb->last_error);
            }
        }
    }

    private function purge(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->query($wpdb->prepare("DELETE FROM {$p}peepso_user_followers WHERE uf_active_user_id = %d", self::VIEWER));
        $wpdb->query($wpdb->prepare("DELETE FROM {$p}bcc_page_follows WHERE user_id = %d", self::VIEWER));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE user_id BETWEEN %d AND %d", self::VIEWER, self::NOT_FOLLOWED));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->users} WHERE ID BETWEEN %d AND %d", self::VIEWER, self::NOT_FOLLOWED));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id BETWEEN %d AND %d", self::PLACEHOLDER, self::SECRET_PAGE));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->posts} WHERE ID BETWEEN %d AND %d", self::PLACEHOLDER, self::SECRET_PAGE));
    }

    /**
     * Tables this test reads, created ONLY when absent and widened only with
     * the columns it needs (another suite may create stunted copies first).
     */
    private function ensureTables(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        // wp_users, wp_usermeta, wp_posts and wp_postmeta come from the
        // integration bootstrap.
        foreach (['peepso_user_followers', 'bcc_watch_meta', 'bcc_page_follows'] as $suffix) {
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
            'post_title'  => 'TEXT NULL',
            'post_name'   => "VARCHAR(200) NOT NULL DEFAULT ''",
            'post_status' => "VARCHAR(20) NOT NULL DEFAULT 'publish'",
            'post_type'   => "VARCHAR(20) NOT NULL DEFAULT 'post'",
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
