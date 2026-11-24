<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\PublicIndexRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\Services\PublicProfileIndex;
use BCC\Trust\Core\Support\PrivacySettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-518 on REAL ROWS: the public index the app sitemap is built from.
 *
 * Real MySQL runs PublicIndexRepository's SQL, bcc-core's UserMetaRepository
 * and openGroupsAfter(), and GroupContextResolver, against seeded users,
 * PeepSo user rows, user_info, usermeta, pages, groups and post meta. Every
 * exclusion has its own seeded row and its own assertion, and every seeded
 * member's `isIndexable()` must agree with whether the list carries them.
 *
 * Fixture ids live in 9518xx and are cleaned up by id; the shared schema is
 * only ever WIDENED (columns added when missing), never dropped.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PublicSitemapIndexIntegrationTest extends TestCase
{
    private const FIRST_ID = 951800;

    /**
     * Members whose ONLY exclusion is the legacy opt-out key. members()
     * gives the answer while the fold is not done (no marker, as on a fresh
     * install); once it is done these two are listed.
     */
    private const LEGACY_ONLY_EXCLUSIONS = [951825, 951829];

    /**
     * id => [handle|null, peepso row [role, acc]|null, user_info suspended|null,
     *        usermeta extra rows, listed?]
     *
     * @return array<int, array{0: ?string, 1: ?array{0: string, 1: int}, 2: ?int, 3: list<array{0: string, 1: string}>, 4: bool, 5: string}>
     */
    private static function members(): array
    {
        $hide   = 'peepso_is_hide_profile_from_user_listing';
        $member = ['member', 10];

        return [
            951801 => ['f518-public', $member, null, [], true, 'public, no user_info row'],
            951802 => ['f518-acc-zero', ['member', 0], null, [], true, 'access 0 (BCC writes it) is public'],
            951803 => ['f518-force-public', ['member', -1], null, [], true, 'access -1 (force public)'],
            951804 => ['f518-no-peepso', null, null, [], false, 'no PeepSo user row'],
            951805 => ['f518-register', ['register', 10], null, [], false, 'role register (email unvalidated)'],
            951806 => ['f518-verified', ['verified', 10], null, [], false, 'role verified (awaiting approval)'],
            951807 => ['f518-ban', ['ban', 10], null, [], false, 'role ban'],
            951808 => ['f518-acc-members', ['member', 20], null, [], false, 'access 20 (members only)'],
            951809 => ['f518-acc-friends', ['member', 30], null, [], false, 'access 30 (friends)'],
            951810 => ['f518-acc-private', ['member', 40], null, [], false, 'access 40 (owner only)'],
            951811 => ['f518-suspended', $member, 1, [], false, 'suspended'],
            951812 => ['f518-not-suspended', $member, 0, [], true, 'a user_info row that is not suspended'],
            951813 => ['f518-hidden-listing', $member, null, [[$hide, '1']], false, 'hidden from listings'],
            951814 => ['f518-hide-off', $member, null, [[$hide, '0']], true, 'hide-from-listings row reading 0'],
            951815 => ['f518-flag-hidden', $member, null, [['bcc_hidden', '1']], false, 'moderation: hidden'],
            951816 => ['f518-flag-shadow', $member, null, [['bcc_shadow_limited', 'true']], false, 'moderation: shadow-limited'],
            951817 => ['f518-flag-review', $member, null, [['bcc_under_review', 'yes']], false, 'moderation: under review'],
            951818 => ['f518-flag-false', $member, null, [['bcc_hidden', 'false']], true, 'a flag reading "false" is not set'],
            951819 => [null, $member, null, [], false, 'no handle'],
            951820 => ['alice@example.test', $member, null, [], false, 'legacy email-shaped handle'],
            951821 => ['member-951821', $member, null, [], false, 'the member-{id} fallback handle'],
            951822 => ['F518-Upper', $member, null, [], false, 'not the current handle shape (upper case)'],
            951823 => ['f518--double', $member, null, [], false, 'not the current handle shape (double hyphen)'],
            951824 => ['f518-two-hide-rows', $member, null, [[$hide, '0'], [$hide, '1']], false, 'any hide row reading 1 excludes'],
            // The shared listing opt-out fragment (F-519): the truthy set, and
            // the legacy key until the fold is done (LEGACY_ONLY_EXCLUSIONS).
            951825 => ['f518-legacy-optout', $member, null, [['bcc_privacy_discovery_optout', '1']], false, 'the legacy opt-out key, fold not done'],
            951826 => ['f518-hide-yes', $member, null, [[$hide, 'yes']], false, 'hide stored as "yes" (PeepSo saves the raw value)'],
            951827 => ['f518-hide-true-upper', $member, null, [[$hide, 'TRUE']], false, 'hide stored as "TRUE" (case-insensitive)'],
            951828 => ['f518-legacy-off', $member, null, [['bcc_privacy_discovery_optout', '0']], true, 'a legacy opt-out row reading 0'],
            951829 => ['f518-hide-off-legacy-on', $member, null, [[$hide, '0'], ['bcc_privacy_discovery_optout', '1']], false, "hide '0' but legacy '1', fold not done"],
            951830 => ['f518-hide-on', $member, null, [[$hide, 'on']], false, 'hide stored as "on"'],
            951831 => ['f518-hide-true', $member, null, [[$hide, 'true']], false, 'hide stored as "true"'],
            // A moderation flag is set when ANY of its rows reads as on, as on
            // the profile (UserViewService::resolveFlags()), whichever came first.
            951832 => ['f518-flag-off-then-on', $member, null, [['bcc_hidden', '0'], ['bcc_hidden', '1']], false, 'moderation: an on row after an off row'],
            951833 => ['f518-flag-on-then-off', $member, null, [['bcc_shadow_limited', 'yes'], ['bcc_shadow_limited', 'no']], false, 'moderation: an on row before an off row'],
            951834 => ['f518-flag-two-off', $member, null, [['bcc_under_review', '0'], ['bcc_under_review', 'false']], true, 'moderation: only off rows'],
        ];
    }

    /**
     * id => [post_type, status, slug, privacy rows, extra meta, expected path|null, why]
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: list<string>, 4: list<array{0: string, 1: string}>, 5: ?string, 6: string}>
     */
    private static function posts(): array
    {
        $page  = 'peepso-page';
        $group = 'peepso-group';

        return [
            // Entity pages.
            951851 => [$page, 'publish', 'f518-val', [], [['_bcc_page_type', 'validator']], '/v/f518-val', 'validator page'],
            951852 => [$page, 'publish', 'f518-proj', [], [], '/p/f518-proj', 'no page type is a project'],
            951853 => [$page, 'publish', 'f518-art', [], [['_bcc_page_type', 'nft']], '/c/f518-art', 'creator page'],
            951854 => [$page, 'publish', 'f518-secret-page', ['2'], [['_bcc_page_type', 'builder']], null, 'secret page'],
            951855 => [$page, 'draft', 'f518-draft-page', [], [['_bcc_page_type', 'builder']], null, 'draft page'],
            951856 => [$page, 'publish', '', [], [['_bcc_page_type', 'builder']], null, 'page with no slug'],
            951857 => [$page, 'publish', 'f518-mystery', [], [['_bcc_page_type', 'mystery']], null, 'unknown page type'],
            951858 => [$page, 'publish', 'f518-closed-page', ['1'], [['_bcc_page_type', 'builder']], '/p/f518-closed-page', 'closed page is public to read'],
            951859 => [$page, 'publish', 'f518-two-types', [], [['_bcc_page_type', 'validator'], ['_bcc_page_type', 'nft']], '/v/f518-two-types', 'two type rows: the first counts, as get_post_meta reads it'],
            // Groups.
            951871 => [$group, 'publish', 'f518-open', ['0'], [], '/communities/f518-open', 'open community'],
            951872 => [$group, 'publish', 'f518-hall', ['0'], [['_bcc_group_kind', 'hall']], '/halls/f518-hall', 'open Hall'],
            951873 => [$group, 'publish', 'f518-closed', ['1'], [], null, 'closed group'],
            951874 => [$group, 'publish', 'f518-secret', ['2'], [], null, 'secret group'],
            951875 => [$group, 'publish', 'f518-split-kind', ['0'], [['_bcc_group_kind', ''], ['_bcc_group_kind', 'hall']], null, 'disagreeing kind rows: the app cannot place it'],
            951876 => [$group, 'publish', 'f518-holders', ['0'], [['_bcc_group_kind', 'holders']], '/communities/f518-holders', 'open holder community'],
            951877 => [$group, 'draft', 'f518-draft-group', ['0'], [], null, 'draft group'],
            951878 => [$group, 'publish', 'f518-no-privacy', [], [], null, 'no privacy row'],
        ];
    }

    private bool $peepsoUsersWasOurs = false;

    protected function setUp(): void
    {
        require_once __DIR__ . '/blog-tab-visibility-integration-stubs.php';
        $this->ensureSchema();
        $this->removeFixtures();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();
        if ($this->peepsoUsersWasOurs) {
            global $wpdb;
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'peepso_users');
        }
    }

    private static function index(): PublicProfileIndex
    {
        return new PublicProfileIndex(
            new PublicIndexRepository(new UserInfoRepository()),
            new GroupContextResolver()
        );
    }

    // ── members ────────────────────────────────────────────────────────

    public function testEachMemberIsListedExactlyWhenEveryRuleAllowsIt(): void
    {
        $page = self::index()->membersAfter(self::FIRST_ID, PublicIndexRepository::PAGE_MAX);
        self::assertNotNull($page);

        $listed = self::ownPaths($page['items'], '/u/f518-');
        $expected = [];
        foreach (self::members() as [$handle, , , , $isListed]) {
            if ($isListed) {
                $expected[] = '/u/' . $handle;
            }
        }
        self::assertSame($expected, $listed);

        foreach (self::members() as $id => [$handle, , , , $isListed, $why]) {
            if ($handle === null || $isListed) {
                continue;
            }
            self::assertNotContains('/u/' . $handle, array_column($page['items'], 'path'), "excluded: {$why}");
        }
    }

    /** P1: the profile's `indexable` flag agrees with the list for every member. */
    public function testIsIndexableAgreesWithTheListForEveryMember(): void
    {
        $index = self::index();
        foreach (self::members() as $id => [, , , , $isListed, $why]) {
            self::assertSame($isListed, $index->isIndexable($id), "member {$id}: {$why}");
        }
        self::assertFalse($index->isIndexable(951899), 'no such user');
        self::assertFalse($index->isIndexable(0));
    }

    /**
     * Until fold_discovery_optout_v1 is done (no done-marker), the legacy
     * opt-out still excludes: a legacy '1' alone, and a legacy '1' beside
     * hide '0'. Nobody who opted out before the fold is listed while it runs.
     */
    public function testUntilTheFoldIsDoneTheLegacyOptOutStillExcludes(): void
    {
        unset($GLOBALS['__bcc_test_options'][PrivacySettings::FOLD_DONE_OPTION]);

        $index = self::index();
        $page  = $index->membersAfter(self::FIRST_ID, PublicIndexRepository::PAGE_MAX);
        self::assertNotNull($page);
        $paths = array_column($page['items'], 'path');

        foreach (self::LEGACY_ONLY_EXCLUSIONS as $id) {
            [$handle, , , , , $why] = self::members()[$id];
            self::assertNotContains('/u/' . $handle, $paths, "fold not done, listed: {$why}");
            self::assertFalse($index->isIndexable($id), "fold not done, indexable: {$why}");
        }
    }

    /**
     * Once the fold is done the legacy key is no longer read. The fold left
     * the legacy rows in place and the settings toggle now writes the hide
     * key alone, so a member who opted out the old way and later turned
     * listing back on holds hide '0' + legacy '1'. They must be LISTED and
     * indexable again. Every other exclusion is unchanged, the list and
     * `indexable` still agree for every member.
     */
    public function testOnceTheFoldIsDoneTheLegacyKeyNoLongerHidesAnyone(): void
    {
        // What the migration runner stores on completion: time().
        $GLOBALS['__bcc_test_options'][PrivacySettings::FOLD_DONE_OPTION] = 1_700_000_000;

        $index = self::index();
        $page  = $index->membersAfter(self::FIRST_ID, PublicIndexRepository::PAGE_MAX);
        self::assertNotNull($page);

        $expected = [];
        foreach (self::members() as $id => [$handle, , , , $isListed]) {
            if ($isListed || in_array($id, self::LEGACY_ONLY_EXCLUSIONS, true)) {
                $expected[] = '/u/' . $handle;
            }
        }
        self::assertSame($expected, self::ownPaths($page['items'], '/u/f518-'));
        self::assertContains('/u/f518-hide-off-legacy-on', $expected, 'the member who turned listing back on');

        foreach (self::members() as $id => [, , , , $isListed, $why]) {
            $listedNow = $isListed || in_array($id, self::LEGACY_ONLY_EXCLUSIONS, true);
            self::assertSame($listedNow, $index->isIndexable($id), "fold done, member {$id}: {$why}");
        }
    }

    public function testMembersWalkInKeysetPagesAndNextAfterIsTheLastScannedId(): void
    {
        $index = self::index();

        // The SQL pass keeps every member whose exclusion is usermeta (flags,
        // handle), so the first page of 5 scans 01, 02, 03, 12, 14.
        $first = $index->membersAfter(self::FIRST_ID, 5);
        self::assertNotNull($first);
        self::assertSame(
            ['/u/f518-public', '/u/f518-acc-zero', '/u/f518-force-public', '/u/f518-not-suspended', '/u/f518-hide-off'],
            array_column($first['items'], 'path')
        );
        self::assertSame(951814, $first['next_after']);

        // The next page scans 15..19: all dropped by the meta checks except
        // 18. A short page that still has a next one.
        $second = $index->membersAfter(951814, 5);
        self::assertNotNull($second);
        self::assertSame(['/u/f518-flag-false'], array_column($second['items'], 'path'));
        self::assertSame(951819, $second['next_after']);

        // Walking to the end collects exactly the listed set, once each.
        $walked = [];
        $after  = self::FIRST_ID;
        for ($guard = 0; $guard < 50; ++$guard) {
            $page = $index->membersAfter($after, 3);
            self::assertNotNull($page);
            foreach ($page['items'] as $item) {
                $walked[] = $item['path'];
            }
            if ($page['next_after'] === null) {
                break;
            }
            self::assertGreaterThan($after, $page['next_after'], 'the cursor always advances');
            $after = $page['next_after'];
        }
        $listed = self::index()->membersAfter(self::FIRST_ID, PublicIndexRepository::PAGE_MAX);
        self::assertSame(array_column($listed['items'] ?? [], 'path'), $walked);
    }

    // ── pages and groups ───────────────────────────────────────────────

    public function testEntityPagesListOnlyPublishedNonSecretTypedPagesWithTheirCanonicalPath(): void
    {
        $page = self::index()->pagesAfter(self::FIRST_ID, PublicIndexRepository::PAGE_MAX);
        self::assertNotNull($page);
        $this->assertPostPaths('peepso-page', $page['items']);
    }

    public function testGroupsListOnlyOpenGroupsTheAppCanPlace(): void
    {
        $page = self::index()->groupsAfter(self::FIRST_ID, 1000);
        self::assertNotNull($page);
        $this->assertPostPaths('peepso-group', $page['items']);
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * @param list<array{path: string}> $items
     */
    private function assertPostPaths(string $postType, array $items): void
    {
        $paths = array_column($items, 'path');
        $own   = array_values(array_filter($paths, static fn (string $p): bool => str_contains($p, '/f518-')));

        $expected = [];
        foreach (self::posts() as [$type, , , , , $path]) {
            if ($type === $postType && $path !== null) {
                $expected[] = $path;
            }
        }
        self::assertSame($expected, $own);

        foreach (self::posts() as [$type, , $slug, , , $path, $why]) {
            if ($type === $postType && $path === null && $slug !== '') {
                foreach ($paths as $p) {
                    self::assertFalse(str_ends_with($p, '/' . $slug), "excluded: {$why}");
                }
            }
        }
    }

    /**
     * @param list<array{path: string}> $items
     * @return list<string>
     */
    private static function ownPaths(array $items, string $prefix): array
    {
        return array_values(array_filter(
            array_column($items, 'path'),
            static fn (string $p): bool => str_starts_with($p, $prefix)
        ));
    }

    private function seed(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach (self::members() as $id => [$handle, $peepso, $suspended, $meta]) {
            self::assertNotFalse($wpdb->insert($wpdb->users, [
                'ID' => $id, 'user_login' => 'f518_' . $id, 'user_email' => $id . '@example.test', 'display_name' => 'F518 ' . $id,
            ]), $wpdb->last_error);
            if ($handle !== null) {
                self::assertNotFalse($wpdb->insert($wpdb->usermeta, ['user_id' => $id, 'meta_key' => 'bcc_handle', 'meta_value' => $handle]), $wpdb->last_error);
            }
            foreach ($meta as [$key, $value]) {
                self::assertNotFalse($wpdb->insert($wpdb->usermeta, ['user_id' => $id, 'meta_key' => $key, 'meta_value' => $value]), $wpdb->last_error);
            }
            if ($peepso !== null) {
                self::assertNotFalse($wpdb->insert($p . 'peepso_users', [
                    'usr_id' => $id, 'usr_role' => $peepso[0], 'usr_profile_acc' => $peepso[1],
                ]), $wpdb->last_error);
            }
            if ($suspended !== null) {
                self::assertNotFalse($wpdb->insert($p . 'bcc_trust_user_info', [
                    'user_id' => $id, 'user_login' => 'f518_' . $id, 'user_email' => $id . '@example.test',
                    'display_name' => 'F518 ' . $id, 'is_suspended' => $suspended,
                ]), $wpdb->last_error);
            }
        }

        foreach (self::posts() as $id => [$type, $status, $slug, $privacy, $meta]) {
            self::assertNotFalse($wpdb->insert($wpdb->posts, [
                'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_name' => $slug, 'post_title' => 'F518 ' . $id,
            ]), $wpdb->last_error);
            $privacyKey = $type === 'peepso-page' ? 'peepso_page_privacy' : 'peepso_group_privacy';
            foreach ($privacy as $value) {
                self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => $privacyKey, 'meta_value' => $value]), $wpdb->last_error);
            }
            foreach ($meta as [$key, $value]) {
                self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => $key, 'meta_value' => $value]), $wpdb->last_error);
            }
        }
    }

    private function removeFixtures(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach (array_keys(self::members()) as $id) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE user_id = %d", $id));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->users} WHERE ID = %d", $id));
            $wpdb->query($wpdb->prepare("DELETE FROM {$p}peepso_users WHERE usr_id = %d", $id));
            $wpdb->query($wpdb->prepare("DELETE FROM {$p}bcc_trust_user_info WHERE user_id = %d", $id));
        }
        foreach (array_keys(self::posts()) as $id) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $id));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->posts} WHERE ID = %d", $id));
        }
    }

    /**
     * PeepSo's user table with the three columns the index reads, and the
     * post columns the get_post() stub selects. Created or WIDENED, never
     * narrowed: other suites share these tables.
     */
    private function ensureSchema(): void
    {
        global $wpdb;

        $users = $wpdb->prefix . 'peepso_users';
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $users)) !== $users) {
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE {$users} (
                    usr_id BIGINT UNSIGNED NOT NULL,
                    usr_role VARCHAR(20) NOT NULL DEFAULT 'member',
                    usr_profile_acc TINYINT NOT NULL DEFAULT 10,
                    PRIMARY KEY (usr_id)
                ) DEFAULT CHARSET=utf8mb4"
            ), $wpdb->last_error);
            $this->peepsoUsersWasOurs = true;
        } else {
            $this->addMissingColumns($users, ['usr_role' => "VARCHAR(20) NOT NULL DEFAULT 'member'"]);
        }

        $this->addMissingColumns($wpdb->posts, [
            'post_author'   => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'post_excerpt'  => 'TEXT NULL',
            'post_date_gmt' => "DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00'",
        ]);
    }

    /** @param array<string, string> $wanted column => definition */
    private function addMissingColumns(string $table, array $wanted): void
    {
        global $wpdb;

        $existing = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));
        $adds = [];
        foreach ($wanted as $column => $definition) {
            if (!in_array($column, $existing, true)) {
                $adds[] = "ADD COLUMN {$column} {$definition}";
            }
        }
        if ($adds !== []) {
            self::assertNotFalse($wpdb->query("ALTER TABLE {$table} " . implode(', ', $adds)), $wpdb->last_error);
        }
    }
}
