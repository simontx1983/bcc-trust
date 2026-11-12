<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\PeepSoHashtagRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FeedActivityFixture.php';

/**
 * F-168 on REAL ROWS: GET /hashtags/trending counts only posts anyone can
 * see. PeepSo's `ht_count` counts every published post (secret-group,
 * members-only, private), so a tag used only in hidden posts was listed, and
 * a mixed tag's count included the hidden ones.
 *
 * Real MySQL runs bcc-core's recount (PeepSoHashtagRepository::
 * getTrendingPubliclyVisible) against seeded posts, activities, post-meta,
 * PeepSo users and the hashtag table; the assertion is on what comes back.
 *
 * ⚠ Requires bcc-core with getTrendingPubliclyVisible(), resolved from the
 * adjacent checkout the harness autoloads.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PublicTrendingHashtagsIntegrationTest extends TestCase
{
    use FeedActivityFixture;

    private const PUBLIC_AUTHOR  = 968101;
    private const PRIVATE_AUTHOR = 968102;
    private const SECRET_GROUP   = 968900;

    private const HIDDEN = 'f168hiddenonly';
    private const MIXED  = 'f168mixedtag';
    private const PLAIN  = 'f168plaintag';

    private bool $usersWasOurs    = false;
    private bool $hashtagsWasOurs = false;

    /** @var list<int> */
    private array $seededUsers = [];

    /** @var list<string> */
    private array $seededTags = [];

    private int $nextId = 968200;

    protected function setUp(): void
    {
        delete_transient(PeepSoHashtagRepository::PUBLIC_CACHE_KEY);
        $this->ensureTables();

        $this->seedUser(self::PUBLIC_AUTHOR, 10);
        $this->seedUser(self::PRIVATE_AUTHOR, 40);

        // PeepSo's counters say all three are hot; HIDDEN is the hottest.
        $this->seedTag(self::HIDDEN, 900003);
        $this->seedTag(self::MIXED, 900002);
        $this->seedTag(self::PLAIN, 900001);

        // HIDDEN: every post hidden from an anonymous viewer, one way each.
        $this->post('#' . self::HIDDEN . ' in a secret group', group: self::SECRET_GROUP, visibility: 'members_only');
        $this->post('#' . self::HIDDEN . ' group post, no visibility meta', group: self::SECRET_GROUP);
        $this->post('#' . self::HIDDEN . ' members only', access: 20);
        $this->post('#' . self::HIDDEN . ' private post', access: 40);
        $this->post('#' . self::HIDDEN . ' by a private profile', author: self::PRIVATE_AUTHOR);
        $this->post('#' . self::HIDDEN . ' a draft', status: 'draft');

        // MIXED: three public posts, three hidden.
        $this->post('plain public #' . self::MIXED);
        $this->post("tab-led\t#" . self::MIXED . "\nsyndicated from a group", group: self::SECRET_GROUP, visibility: 'public_all');
        $this->post('#' . self::MIXED . ' written with act_access 0', access: 0);
        $this->post('#' . self::MIXED . ' secret group', group: self::SECRET_GROUP, visibility: 'public_group');
        $this->post('#' . self::MIXED . ' private', access: 40);
        $this->post('#' . self::MIXED . ' private profile', author: self::PRIVATE_AUTHOR);

        // PLAIN: one public post (after a NBSP), plus near-misses that are not the tag.
        $this->post("hello\u{00A0}#" . self::PLAIN);
        $this->post('#' . self::PLAIN . 'x is another tag');
        $this->post('no-space#' . self::PLAIN . ' is not a hashtag');
    }

    protected function tearDown(): void
    {
        global $wpdb;
        delete_transient(PeepSoHashtagRepository::PUBLIC_CACHE_KEY);
        $this->removeFeedActivities();
        foreach ($this->seededGroups as $groupId) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $groupId));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->posts} WHERE ID = %d", $groupId));
        }
        foreach ($this->seededTags as $tag) {
            $wpdb->delete($wpdb->prefix . 'peepso_hashtags', ['ht_name' => $tag]);
        }
        foreach ($this->seededUsers as $userId) {
            $wpdb->delete($wpdb->prefix . 'peepso_users', ['usr_id' => $userId]);
        }
        if ($this->hashtagsWasOurs) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'peepso_hashtags');
        }
        if ($this->usersWasOurs) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'peepso_users');
        }
    }

    /** @return array<string, int> ours only: the shared harness may hold other tags */
    private static function ourTrending(): array
    {
        $out = [];
        foreach (PeepSoHashtagRepository::getTrendingPubliclyVisible(20) as $row) {
            if (in_array($row->ht_name, [self::HIDDEN, self::MIXED, self::PLAIN], true)) {
                $out[$row->ht_name] = $row->ht_count;
            }
        }
        return $out;
    }

    public function testATagUsedOnlyInHiddenPostsIsNotTrending(): void
    {
        self::assertArrayNotHasKey(self::HIDDEN, self::ourTrending());
    }

    public function testAMixedTagCountsOnlyItsPublicPosts(): void
    {
        $trending = self::ourTrending();

        self::assertSame(3, $trending[self::MIXED] ?? null, 'plain + public_all group post + act_access 0; the three hidden ones are not counted');
        self::assertSame(1, $trending[self::PLAIN] ?? null, "PeepSo's match: NBSP is a space, '#tagx' and 'x#tag' are not the tag");
        self::assertSame([self::MIXED, self::PLAIN], array_keys($trending), 'ranked by the public count, not ht_count');
    }

    public function testOneHiddenActivityRowHidesThePost(): void
    {
        global $wpdb;

        $postId = $this->post('#' . self::PLAIN . ' backed by a public AND a private activity row');
        self::assertSame(2, self::ourTrending()[self::PLAIN] ?? null, 'anti-vacuity: with only its public row the post counts');

        // A second activity row for the SAME post, private. seedFeedActivity()
        // refuses an existing post id, so this row is inserted directly and
        // registered for teardown under a post id the fixture already owns.
        $actId = $this->nextId++;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_activities', [
            'act_id' => $actId, 'act_module_id' => '1', 'act_external_id' => $postId, 'act_owner_id' => self::PUBLIC_AUTHOR, 'act_access' => 40,
        ]), $wpdb->last_error);
        $this->seededActivities[] = [$actId, $postId];
        delete_transient(PeepSoHashtagRepository::PUBLIC_CACHE_KEY);

        self::assertSame(1,self::ourTrending()[self::PLAIN] ?? null, 'a post with any non-public activity row is not counted');
    }

    /** A post carrying its group and visibility meta TWICE is still one post (no join fan-out inside the SUM). */
    public function testDuplicateGroupAndVisibilityMetaCountThePostOnce(): void
    {
        global $wpdb;

        $postId = $this->post('#' . self::PLAIN . ' with duplicated meta', group: self::SECRET_GROUP + 1, visibility: 'public_all');
        foreach ([['peepso_group_id', (string) (self::SECRET_GROUP + 2)], ['_bcc_post_visibility', 'public_all']] as [$key, $value]) {
            self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $postId, 'meta_key' => $key, 'meta_value' => $value]), $wpdb->last_error);
        }

        self::assertSame(2, self::ourTrending()[self::PLAIN] ?? null, 'the NBSP post + this one, once: not 1 + 2×2 join rows');
    }

    /**
     * F-23 / Gate 12 (the narrow rule, GroupSyndicationGate): in a group that
     * is NOW secret, a public_all post made while it was open is not counted,
     * nor is one with no record; one made public_all while the group was
     * ALREADY non-open still counts.
     */
    public function testANowSecretGroupDropsItsOldPostsButKeepsDeliberateSyndication(): void
    {
        $group = $this->seedGroup('2');

        $this->post('#' . self::PLAIN . ' made while the group was open', group: $group, visibility: 'public_all', privacyAtPost: '0');
        $this->post('#' . self::PLAIN . ' written before the record existed', group: $group, visibility: 'public_all');
        $this->post('#' . self::PLAIN . ' made public_all while already secret', group: $group, visibility: 'public_all', privacyAtPost: '2');

        self::assertSame(2, self::ourTrending()[self::PLAIN] ?? null, 'the NBSP post + the deliberate one; the other two are not counted');
    }

    /**
     * The feed's range ladder: an effective level below 10 is public. Both
     * the post's act_access AND the author's profile level must be below 10
     * for the stricter-of-the-two to land there; `= 10` dropped this post.
     */
    public function testAnEffectiveLevelBelowTenIsPublic(): void
    {
        $author = $this->nextId++;
        $this->seedUser($author, 5);
        $this->post('#' . self::PLAIN . ' stray levels below 10', author: $author, access: 5);

        self::assertSame(2, self::ourTrending()[self::PLAIN] ?? null);
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /** @var list<int> */
    private array $seededGroups = [];

    /** A `peepso-group` post with the given `peepso_group_privacy` (what the strict non-open lookup reads). */
    private function seedGroup(string $privacy): int
    {
        global $wpdb;

        $groupId = $this->nextId++;
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $groupId)) > 0) {
            self::markTestSkipped("{$wpdb->posts}.ID={$groupId} already exists; refusing to overwrite a real row");
        }
        self::assertNotFalse($wpdb->insert($wpdb->posts, ['ID' => $groupId, 'post_type' => 'peepso-group', 'post_status' => 'publish']), $wpdb->last_error);
        self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $groupId, 'meta_key' => 'peepso_group_privacy', 'meta_value' => $privacy]), $wpdb->last_error);
        $this->seededGroups[] = $groupId;
        return $groupId;
    }

    private function post(
        string $content,
        int $author = self::PUBLIC_AUTHOR,
        int $access = 10,
        int $group = 0,
        ?string $visibility = null,
        string $status = 'publish',
        ?string $privacyAtPost = null
    ): int {
        global $wpdb;

        $postId = $this->nextId++;
        $this->seedFeedActivity($this->nextId++, $postId, '1', $author);
        self::assertNotFalse($wpdb->update($wpdb->posts, ['post_content' => $content, 'post_status' => $status], ['ID' => $postId]), $wpdb->last_error);
        $this->setAccessOfLastActivity($access);

        if ($group > 0) {
            self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $postId, 'meta_key' => 'peepso_group_id', 'meta_value' => (string) $group]), $wpdb->last_error);
        }
        if ($privacyAtPost !== null) {
            self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $postId, 'meta_key' => '_bcc_post_group_privacy_at_post', 'meta_value' => $privacyAtPost]), $wpdb->last_error);
        }
        if ($visibility !== null) {
            self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $postId, 'meta_key' => '_bcc_post_visibility', 'meta_value' => $visibility]), $wpdb->last_error);
        }
        return $postId;
    }

    private function setAccessOfLastActivity(int $access): void
    {
        global $wpdb;
        $last = $this->seededActivities[count($this->seededActivities) - 1];
        self::assertNotFalse($wpdb->update($wpdb->prefix . 'peepso_activities', ['act_access' => $access], ['act_id' => $last[0]]), $wpdb->last_error);
    }

    private function seedUser(int $userId, int $profileAccess): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'peepso_users';
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE usr_id = %d", $userId)) > 0) {
            self::markTestSkipped("{$table}.usr_id={$userId} already exists; refusing to overwrite a real row");
        }
        self::assertNotFalse($wpdb->insert($table, ['usr_id' => $userId, 'usr_profile_acc' => $profileAccess]), $wpdb->last_error);
        $this->seededUsers[] = $userId;
    }

    private function seedTag(string $name, int $count): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'peepso_hashtags';
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE ht_name = %s", $name)) > 0) {
            self::markTestSkipped("{$table}.ht_name={$name} already exists");
        }
        self::assertNotFalse($wpdb->insert($table, ['ht_name' => $name, 'ht_count' => $count]), $wpdb->last_error);
        $this->seededTags[] = $name;
    }

    private function ensureTables(): void
    {
        global $wpdb;

        $users = $wpdb->prefix . 'peepso_users';
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $users)) !== $users) {
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE {$users} (
                    usr_id BIGINT UNSIGNED NOT NULL,
                    usr_profile_acc TINYINT NOT NULL DEFAULT 10,
                    PRIMARY KEY (usr_id)
                ) DEFAULT CHARSET=utf8mb4"
            ), $wpdb->last_error);
            $this->usersWasOurs = true;
        }

        $tags = $wpdb->prefix . 'peepso_hashtags';
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tags)) !== $tags) {
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE {$tags} (
                    ht_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    ht_name VARCHAR(64) NOT NULL,
                    ht_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (ht_id)
                ) DEFAULT CHARSET=utf8mb4"
            ), $wpdb->last_error);
            $this->hashtagsWasOurs = true;
        }

        // The shared wp_posts may predate post_content; widen only if missing.
        $has = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $wpdb->posts,
            'post_content'
        ));
        if ($has === 0) {
            self::assertNotFalse($wpdb->query("ALTER TABLE {$wpdb->posts} ADD COLUMN post_content LONGTEXT NULL"), $wpdb->last_error);
        }
    }
}
