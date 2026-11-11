<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Services\BlogService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Who can see a group blog on the author's profile Blog tab — proved on
 * REAL ROWS, not on a statement's shape.
 *
 * This suite exists because the unit layers each prove half of the answer
 * and neither proves the whole: bcc-core pins the SQL against a rendering
 * `$wpdb` double, and bcc-trust pins which gate inputs the caller sends.
 * Both passed at a point when the failure detection underneath them was
 * dead code, because the double modelled an error the way the bug assumed
 * rather than the way WPDB reports it. So here the group rows, the
 * membership rows, the per-post markers, the deny list and the predicate
 * are all real and in MySQL, and the assertion is on WHICH POSTS COME BACK.
 *
 * Doubled: the session identity and the author display block only. Neither
 * is consulted by the gate.
 *
 * ⚠ Requires bcc-core's `getNonOpenGroupIdsStrict()` and the
 * `$groupGateMode` / `$viewerMemberGroupIds` / `$viewerId` params, i.e.
 * bcc-core at or after the group-blogs change, resolved from the adjacent
 * checkout the harness autoloads.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(BlogService::class)]
final class BlogTabGroupVisibilityIntegrationTest extends TestCase
{
    private const AUTHOR     = 501;
    private const MEMBER     = 502;
    private const NON_MEMBER = 503;

    private const OPEN_GROUP   = 9100;
    private const SECRET_GROUP = 9200;

    /** post ids, assigned in setUp */
    private int $pNoGroup       = 0;
    private int $pOpenMembers   = 0;
    private int $pOpenPublic    = 0;
    private int $pSecretPublic  = 0;
    private int $pSecretMembers = 0;

    protected function setUp(): void
    {
        require_once __DIR__ . '/blog-tab-visibility-integration-stubs.php';

        $this->buildTables();

        // Groups. Privacy '0' is open; '1' closed and '2' secret are the
        // two the non-open lookup treats as restricted.
        $this->insertGroup(self::OPEN_GROUP, 'open-group', '0');
        $this->insertGroup(self::SECRET_GROUP, 'secret-group', '2');

        // MEMBER belongs to both; NON_MEMBER to neither; the AUTHOR starts
        // as a member of both and leaves in the leaving case.
        $this->joinGroup(self::MEMBER, self::OPEN_GROUP);
        $this->joinGroup(self::MEMBER, self::SECRET_GROUP);
        $this->joinGroup(self::AUTHOR, self::OPEN_GROUP);
        $this->joinGroup(self::AUTHOR, self::SECRET_GROUP);

        // Five blogs by the same author, differing only in group and
        // visibility, so any difference in what comes back is the gate.
        $this->pNoGroup       = $this->insertBlog('no-group', 0, null);
        $this->pOpenMembers   = $this->insertBlog('open-members', self::OPEN_GROUP, 'members_only');
        $this->pOpenPublic    = $this->insertBlog('open-public', self::OPEN_GROUP, 'public_group');
        $this->pSecretPublic  = $this->insertBlog('secret-public', self::SECRET_GROUP, 'public_group');
        $this->pSecretMembers = $this->insertBlog('secret-members', self::SECRET_GROUP, 'members_only');

        // The lookup is generation-cached; each case re-reads real rows.
        PeepSoGroupRepository::bustNonOpenGroupIdsCache();
    }

    /**
     * ⚠ The next suite inherits this database — only the bootstrap rebuilds
     * it. Leftover group and activity rows read as real data to anything
     * that counts them.
     */
    protected function tearDown(): void
    {
        global $wpdb;

        $wpdb->query("DELETE FROM {$wpdb->postmeta}");
        $wpdb->query("DELETE FROM {$wpdb->posts}");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}peepso_activities");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}peepso_group_members");
        $wpdb->query(
            "ALTER TABLE {$wpdb->posts}
                DROP COLUMN post_author,
                DROP COLUMN post_content,
                DROP COLUMN post_excerpt,
                DROP COLUMN post_date_gmt"
        );
        PeepSoGroupRepository::bustNonOpenGroupIdsCache();
        unset($GLOBALS['__bcc_current_user']);
    }

    // ── the cases ────────────────────────────────────────────────────

    /**
     * A members_only blog in a group belongs to that group's members. The
     * author sees it on their own tab, and so does a fellow member.
     */
    public function testAMembersOnlyGroupBlogIsVisibleToTheAuthorAndToAMember(): void
    {
        $asAuthor = $this->blogIdsFor(self::AUTHOR);
        self::assertContains($this->pOpenMembers, $asAuthor, 'the author must see their own members_only blog');
        self::assertContains($this->pSecretMembers, $asAuthor);

        $asMember = $this->blogIdsFor(self::MEMBER);
        self::assertContains($this->pOpenMembers, $asMember, 'a member of the group must see it');
        self::assertContains($this->pSecretMembers, $asMember);
    }

    /**
     * THE M2 CASE. members_only means members_only even in an OPEN group:
     * a non-member can SEE the group and still must not read the post. The
     * deny list alone would have let this through, because an open group is
     * not restricted.
     */
    public function testAMembersOnlyBlogInAnOpenGroupIsHiddenFromANonMember(): void
    {
        $asNonMember = $this->blogIdsFor(self::NON_MEMBER);

        self::assertNotContains($this->pOpenMembers, $asNonMember);
        self::assertContains($this->pOpenPublic, $asNonMember, 'a public_group post in an open group is readable');
        self::assertContains($this->pNoGroup, $asNonMember, 'a blog in no group is unaffected');
    }

    public function testAMembersOnlyBlogInAnOpenGroupIsHiddenFromAnonymous(): void
    {
        $anon = $this->blogIdsFor(0);

        self::assertNotContains($this->pOpenMembers, $anon);
        self::assertContains($this->pNoGroup, $anon);
    }

    /**
     * THE DENY-LIST CASE. public_group is readable to a non-member of an
     * OPEN group, but a SECRET group's existence is not public, so the same
     * visibility must not leak out of it.
     */
    public function testAPublicGroupBlogInASecretGroupIsHiddenFromANonMember(): void
    {
        $asNonMember = $this->blogIdsFor(self::NON_MEMBER);

        self::assertNotContains($this->pSecretPublic, $asNonMember);
        self::assertNotContains($this->pSecretMembers, $asNonMember);
    }

    public function testAPublicGroupBlogInASecretGroupIsHiddenFromAnonymous(): void
    {
        $anon = $this->blogIdsFor(0);

        self::assertNotContains($this->pSecretPublic, $anon);
        self::assertNotContains($this->pSecretMembers, $anon);
    }

    public function testASecretGroupBlogIsVisibleToAMemberOfThatGroup(): void
    {
        self::assertContains($this->pSecretPublic, $this->blogIdsFor(self::MEMBER));
    }

    /**
     * THE LEAVING CASE. The author keeps their own writing. After leaving
     * the secret group the group is restricted FOR them, and their own post
     * must still appear on their own tab — which is what the author-scoped
     * mode is for, and why it may not be reduced to the deny list.
     */
    public function testTheAuthorStillSeesTheirOwnGroupBlogAfterLeavingTheGroup(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}peepso_group_members WHERE gm_user_id = %d",
            self::AUTHOR
        ));
        PeepSoGroupRepository::bustNonOpenGroupIdsCache();

        // Control: the group really is restricted for them now, which is
        // what a non-author in the same position would experience.
        self::assertNotContains(
            $this->pSecretMembers,
            $this->blogIdsFor(self::NON_MEMBER),
            'control: a non-member is refused the same row'
        );

        $asAuthor = $this->blogIdsFor(self::AUTHOR);
        self::assertContains($this->pSecretMembers, $asAuthor, 'the author does not lose their own writing');
        self::assertContains($this->pSecretPublic, $asAuthor);
    }

    /**
     * EVERY post is reachable by the author, so a later case asserting
     * absence is asserting the gate and not a broken fixture.
     */
    public function testTheFixtureIsSoundBecauseTheAuthorSeesAllFive(): void
    {
        $asAuthor = $this->blogIdsFor(self::AUTHOR);

        self::assertCount(5, array_intersect($asAuthor, [
            $this->pNoGroup,
            $this->pOpenMembers,
            $this->pOpenPublic,
            $this->pSecretPublic,
            $this->pSecretMembers,
        ]), 'if the author cannot see all five, an absence elsewhere proves nothing');
    }

    /**
     * A FAILED non-open lookup must leak nothing.
     *
     * ⚠ SIMULATED AT THE `$wpdb` BOUNDARY, deliberately and narrowly: the
     * decorator errors ONLY the non-open-groups query and passes every
     * other statement through to real MySQL. Dropping `wp_postmeta` would
     * destroy the fixtures along with the lookup and prove nothing.
     *
     * It fails the way WPDB actually fails — `query()` returns false and
     * `last_error` is set, while `get_col()` still returns an array — which
     * is precisely the behaviour a previous version of this work assumed
     * wrongly.
     */
    public function testAFailedNonOpenLookupLeaksNothing(): void
    {
        global $wpdb;

        $real = $wpdb;
        $GLOBALS['wpdb'] = new class ($real) {
            public string $last_error = '';

            public function __construct(private object $inner)
            {
            }

            public function __get(string $name): mixed
            {
                return $this->inner->$name;
            }

            /** @param mixed ...$args */
            public function __call(string $name, array $args): mixed
            {
                $sql = $args[0] ?? '';

                // The non-open-groups lookup, and only that one.
                if (is_string($sql) && str_contains($sql, 'peepso_group_privacy')) {
                    $this->last_error = 'WordPress database error: simulated failure';

                    return $name === 'query' ? false : [];
                }

                $out = $this->inner->$name(...$args);
                // Mirror the real handle's error state for everything else.
                $this->last_error = (string) ($this->inner->last_error ?? '');

                return $out;
            }
        };

        try {
            PeepSoGroupRepository::bustNonOpenGroupIdsCache();
            $ids = $this->blogIdsFor(self::NON_MEMBER);
        } finally {
            $GLOBALS['wpdb'] = $real;
            PeepSoGroupRepository::bustNonOpenGroupIdsCache();
        }

        // With the deny list unavailable the caller keeps the stricter
        // global gate, so only a post the author syndicated globally
        // (public_all) or one in no group at all can appear. Nothing from
        // either group may.
        self::assertNotContains($this->pSecretPublic, $ids, 'a failed lookup must not open a secret group');
        self::assertNotContains($this->pSecretMembers, $ids);
        self::assertNotContains($this->pOpenMembers, $ids);
        self::assertNotContains($this->pOpenPublic, $ids, 'degrading closed withholds even the readable one');
        self::assertContains($this->pNoGroup, $ids, 'a blog in no group is still served');
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * The wp_post ids the Blog tab returns for a given session viewer.
     *
     * @return list<int>
     */
    private function blogIdsFor(int $sessionUserId): array
    {
        $GLOBALS['__bcc_current_user'] = $sessionUserId;

        $service = new BlogService(new HiddenActivityRepository());
        $page    = $service->getUserBlog(self::AUTHOR, $sessionUserId, null, 20);

        $ids = [];
        foreach ($page['items'] as $item) {
            // `id` is "feed_<act_id>"; map back to the wp_post through the
            // activity row so the assertions name posts, not act ids.
            $actId = (int) substr((string) $item['id'], strlen('feed_'));
            global $wpdb;
            $postId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT act_external_id FROM {$wpdb->prefix}peepso_activities WHERE act_id = %d",
                $actId
            ));
            if ($postId > 0) {
                $ids[] = $postId;
            }
        }

        return $ids;
    }

    private function buildTables(): void
    {
        global $wpdb;

        $wanted = [
            'post_author'   => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'post_content'  => 'LONGTEXT NULL',
            'post_excerpt'  => 'TEXT NULL',
            'post_date_gmt' => "DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00'",
        ];
        $existing = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $wpdb->posts
        ));
        $adds = [];
        foreach ($wanted as $column => $definition) {
            if (!in_array($column, $existing, true)) {
                $adds[] = "ADD COLUMN {$column} {$definition}";
            }
        }

        $statements = [];
        if ($adds !== []) {
            $statements[] = "ALTER TABLE {$wpdb->posts} " . implode(', ', $adds);
        }
        $statements[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}peepso_activities (
            act_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            act_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            act_module_id VARCHAR(20) NOT NULL DEFAULT '0',
            act_external_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            act_access INT NOT NULL DEFAULT 10,
            act_comment_object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (act_id)
        ) DEFAULT CHARSET=utf8mb4";
        $statements[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}peepso_group_members (
            gm_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            gm_group_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            gm_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            gm_user_status VARCHAR(32) NOT NULL DEFAULT 'member',
            gm_joined DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00',
            PRIMARY KEY (gm_id)
        ) DEFAULT CHARSET=utf8mb4";

        foreach ($statements as $sql) {
            self::assertNotFalse($wpdb->query($sql), 'fixture: ' . $wpdb->last_error);
        }

        require_once dirname(__DIR__, 2) . '/includes/database/schema-hidden-activities.php';
    }

    private function insertGroup(int $groupId, string $slug, string $privacy): void
    {
        global $wpdb;

        self::assertNotFalse($wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->posts} (ID, post_type, post_status, post_name, post_title, post_author)
             VALUES (%d, 'peepso-group', 'publish', %s, %s, 0)",
            $groupId,
            $slug,
            $slug
        )), 'group fixture: ' . $wpdb->last_error);

        update_post_meta($groupId, 'peepso_group_privacy', $privacy);
    }

    private function joinGroup(int $userId, int $groupId): void
    {
        global $wpdb;

        self::assertNotFalse($wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}peepso_group_members
                (gm_group_id, gm_user_id, gm_user_status, gm_joined)
             VALUES (%d, %d, 'member', '2026-01-01 00:00:00')",
            $groupId,
            $userId
        )), 'membership fixture: ' . $wpdb->last_error);
    }

    /**
     * A published blog by AUTHOR, optionally inside a group with a given
     * per-post visibility. Returns the wp_post id.
     */
    private function insertBlog(string $slug, int $groupId, ?string $visibility): int
    {
        global $wpdb;

        self::assertNotFalse($wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->posts}
                (post_type, post_status, post_name, post_title, post_author, post_excerpt, post_content, post_date_gmt)
             VALUES ('peepso-post', 'publish', %s, %s, %d, 'excerpt', 'body', %s)",
            $slug,
            $slug,
            self::AUTHOR,
            '2026-02-01 12:00:00'
        )), 'blog fixture: ' . $wpdb->last_error);

        $postId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $postId);

        update_post_meta($postId, '_bcc_activity_module', 'blog');
        if ($groupId > 0) {
            update_post_meta($postId, 'peepso_group_id', (string) $groupId);
        }
        if ($visibility !== null) {
            update_post_meta($postId, '_bcc_post_visibility', $visibility);
        }

        self::assertNotFalse($wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}peepso_activities
                (act_owner_id, act_module_id, act_external_id, act_access, act_comment_object_id)
             VALUES (%d, 'blog', %d, 10, 0)",
            self::AUTHOR,
            $postId
        )), 'activity fixture: ' . $wpdb->last_error);

        return $postId;
    }
}
