<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Announcements\AnnouncementPostType;
use BCC\Trust\Core\Announcements\AnnouncementVisibility as Vis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The §4.32.1 three-predicate visibility rule, executed as REAL SQL
 * against REAL rows in a REAL database.
 *
 * ── ⚠⚠⚠ WHY THIS HAS TO BE AN INTEGRATION TEST ──────────────────────────
 * The predicate's whole job is to be wrong in ways a unit test cannot
 * see. `CAST('7abc' AS UNSIGNED)` is 7 in MySQL. `meta_value <= NOW()`
 * compares datetimes lexically if the column is text. A duplicated meta
 * key multiplies JOINed rows but not EXISTS ones. Every one of those is a
 * property of the ENGINE, not of the string, so the only test that means
 * anything executes the production SQL against seeded rows.
 *
 * ── ⚠⚠ THE THINGS THAT MUST NOT HAPPEN ──────────────────────────────────
 *  1. A ROW AT `publish` WITH A DUE DATE AND NO MARKER IS PUBLIC. This is
 *     predicate 3's entire reason for existing: a row moved to `publish`
 *     early — by hand, a plugin, a bad migration, a restored backup —
 *     must not become public when its date arrives, because it never
 *     passed the ownership recheck.
 *  2. A MARKER BELONGING TO SOMEBODY ELSE AUTHORIZES. The marker's
 *     user id must equal `post_author`. A mismatched pair is the shape a
 *     transferred or re-authored row leaves behind.
 *  3. A FUTURE MARKER AUTHORIZES. Writing tomorrow's date today would
 *     otherwise publish a row immediately.
 *  4. A MALFORMED MARKER CASTS INTO AGREEMENT. `'7abc'`, `' 7'`, `'7.9'`
 *     all become 7 under a bare CAST.
 *  5. A GENUINELY PUBLIC ANNOUNCEMENT IS WITHHELD. A predicate that
 *     refused everything would satisfy 1–4 and ship an empty feature.
 *     Every hostile case here has a correct counterpart in the same query.
 *  6. ARCHIVED ANNOUNCEMENTS DISAPPEAR. §4.32.6 — archival is a public
 *     record, not a deletion. The public predicate must NOT exclude them;
 *     the archival filters are separate and must be exact complements.
 *  7. AN ORDINARY POST CARRYING OUR META BECOMES AN ANNOUNCEMENT.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(Vis::class)]
final class AnnouncementVisibilityIntegrationTest extends TestCase
{
    // ⚠⚠ OWN FIXTURE TABLES, NOT wp_posts / wp_postmeta.
    //
    // The first version of this suite DROPPED and recreated the shared
    // tables with a reduced column set. It worked in isolation and
    // POISONED 57 UNRELATED INTEGRATION CASES in the same process —
    // including a chain-checkpoint suite that then reported
    // "Table 'wp_bcc_chain_checkpoints' already exists". A destructive
    // shared fixture is the same failure class as a mutation control that
    // is never restored: it passes for itself and breaks its neighbours.
    //
    // Naming them separately is only possible because the predicate takes
    // its meta table as a PARAMETER — the same change that removed its
    // `$wpdb` access for arch-guardrails. Two problems, one fix.
    private const POSTS = 'wp_bcc_vis_posts';
    private const META  = 'wp_bcc_vis_meta';

    protected function setUp(): void
    {
        global $wpdb;

        // The integration harness does not create wp_posts/wp_postmeta —
        // no BCC read path needed them until now. Built here, faithful in
        // the columns this predicate touches.
        $wpdb->query('DROP TABLE IF EXISTS `' . self::META . '`');
        $wpdb->query('DROP TABLE IF EXISTS `' . self::POSTS . '`');

        $posts = $wpdb->query(
            'CREATE TABLE `' . self::POSTS . '` (
                ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_author BIGINT UNSIGNED NOT NULL DEFAULT 0,
                -- WordPress ships a zero date here, which MySQL strict mode
                -- REJECTS ("Invalid default value"). The fixture then never
                -- created and EVERY case in this file failed on the MySQL job
                -- while passing on MariaDB -- an engine-specific false negative.
                -- Every seed supplies the value explicitly, so no default is
                -- needed.
                post_date_gmt DATETIME NOT NULL,
                post_title TEXT NOT NULL,
                post_status VARCHAR(20) NOT NULL DEFAULT \'publish\',
                post_password VARCHAR(255) NOT NULL DEFAULT \'\',
                post_type VARCHAR(20) NOT NULL DEFAULT \'post\',
                PRIMARY KEY (ID),
                KEY type_status_date (post_type, post_status, post_date_gmt)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        // ⚠ $wpdb->query() returns false SILENTLY, which would make every
        // assertion below run against a missing table and pass vacuously.
        self::assertNotFalse($posts, 'wp_posts fixture: ' . $wpdb->last_error);

        $meta = $wpdb->query(
            'CREATE TABLE `' . self::META . '` (
                meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                meta_key VARCHAR(255) DEFAULT NULL,
                meta_value LONGTEXT,
                PRIMARY KEY (meta_id),
                KEY post_id (post_id),
                KEY meta_key (meta_key(191))
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        self::assertNotFalse($meta, 'wp_postmeta fixture: ' . $wpdb->last_error);

        // The predicate takes its table name as an argument, so this suite
        // neither depends on nor disturbs the harness's idea of which
        // postmeta table is live.
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS `' . self::META . '`');
        $wpdb->query('DROP TABLE IF EXISTS `' . self::POSTS . '`');
    }

    /**
     * Seed one announcement. Every parameter can be made hostile
     * independently, which is the point — each must-not-happen case turns
     * exactly one knob.
     */
    private function seed(
        string $title,
        int $author = 7,
        string $status = 'publish',
        string $dateGmt = '2020-01-01 00:00:00',
        string $postType = AnnouncementPostType::POST_TYPE,
        ?string $authorizedAt = '2020-01-01 00:00:00',
        ?string $authorizedUserId = '7',
        ?string $archivedAt = null
    ): int {
        global $wpdb;

        $ok = $wpdb->insert(self::POSTS, [
            'post_author'   => $author,
            'post_date_gmt' => $dateGmt,
            'post_title'    => $title,
            'post_status'   => $status,
            'post_type'     => $postType,
        ]);
        self::assertNotFalse($ok, "seeding {$title}: " . $wpdb->last_error);
        $id = (int) $wpdb->insert_id;

        foreach ([
            Vis::META_AUTHORIZED_AT      => $authorizedAt,
            Vis::META_AUTHORIZED_USER_ID => $authorizedUserId,
            Vis::META_ARCHIVED_AT        => $archivedAt,
        ] as $key => $value) {
            if ($value === null) {
                continue;
            }
            self::assertNotFalse(
                $wpdb->insert(self::META, ['post_id' => $id, 'meta_key' => $key, 'meta_value' => $value]),
                "seeding meta {$key}: " . $wpdb->last_error
            );
        }

        return $id;
    }

    /**
     * Runs the REAL predicate as the WHERE of a list read.
     *
     * @return list<string> the post_title of every visible row
     */
    private function visible(string $extra = ''): array
    {
        global $wpdb;

        $pred = Vis::publicPredicateSql(self::META, 'p');

        // Sanity: the fragment under test really is what executes.
        self::assertStringContainsString('UTC_TIMESTAMP()', $pred);
        self::assertStringContainsString(Vis::META_AUTHORIZED_AT, $pred);
        self::assertStringContainsString(Vis::META_AUTHORIZED_USER_ID, $pred);

        $sql = 'SELECT p.post_title FROM `' . self::POSTS . '` p WHERE ' . $pred
             . ($extra !== '' ? ' AND ' . $extra : '')
             . ' ORDER BY p.ID';

        $rows = $wpdb->get_col($sql);
        self::assertSame('', (string) $wpdb->last_error, 'the predicate must be valid SQL: ' . $sql);

        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    /** @return list<string> everything in the table, so each case has a denominator. */
    private function all(): array
    {
        global $wpdb;
        $rows = $wpdb->get_col('SELECT post_title FROM `' . self::POSTS . '` ORDER BY ID');
        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    // ── 1. The three predicates, each removed independently ─────────────

    /** MUST-NOT-HAPPEN #5 first — the predicate admits a correct row. */
    public function testAFullyAuthorizedDueAnnouncementIsPublic(): void
    {
        $this->seed('properly published');
        self::assertSame(['properly published'], $this->visible());
    }

    /**
     * ⚠⚠⚠ MUST-NOT-HAPPEN #1 — the case predicate 3 exists for. Status is
     * `publish`, the date is long past, and the ONLY thing missing is the
     * marker. Both of the "obvious" predicates are satisfied.
     */
    public function testAPublishedDueRowWithNoMarkerIsNotPublic(): void
    {
        $this->seed('forced to publish', authorizedAt: null, authorizedUserId: null);
        $this->seed('properly published');

        self::assertCount(2, $this->all(), 'both rows must exist');
        self::assertSame(['properly published'], $this->visible());
    }

    public function testHalfAMarkerDoesNotAuthorize(): void
    {
        $this->seed('timestamp only', authorizedUserId: null);
        $this->seed('user id only', authorizedAt: null);
        $this->seed('properly published');

        self::assertCount(3, $this->all());
        self::assertSame(['properly published'], $this->visible());
    }

    public function testADraftOrFutureDatedRowIsNotPublic(): void
    {
        $this->seed('a draft', status: 'draft');
        $this->seed('scheduled', status: 'future', dateGmt: '2099-01-01 00:00:00');
        // ⚠ `publish` + a future date: the status says live, the clock
        // does not. Predicate 2 alone must hold it back.
        $this->seed('published but not due', dateGmt: '2099-01-01 00:00:00');
        $this->seed('properly published');

        self::assertCount(4, $this->all());
        self::assertSame(['properly published'], $this->visible());
    }

    // ── 2. Hostile markers — MUST-NOT-HAPPEN #2, #3, #4 ─────────────────

    /** MUST-NOT-HAPPEN #2 — the marker must belong to the author. */
    public function testAMarkerForADifferentUserDoesNotAuthorize(): void
    {
        $this->seed('authorized by someone else', author: 7, authorizedUserId: '9');
        $this->seed('properly published');

        self::assertSame(['properly published'], $this->visible());
    }

    /** MUST-NOT-HAPPEN #3 — a marker dated ahead must not publish now. */
    public function testAFutureMarkerDoesNotAuthorize(): void
    {
        $this->seed('marker in the future', authorizedAt: '2099-01-01 00:00:00');
        $this->seed('properly published');

        self::assertSame(['properly published'], $this->visible());
    }

    /**
     * ⚠⚠ MUST-NOT-HAPPEN #4 — the one the engine decides. Every value
     * below becomes 7 under a bare `CAST(... AS UNSIGNED)`, so without the
     * digits-only guard each of these would authorize as user 7.
     */
    public function testAMarkerThatOnlyCastsToTheAuthorDoesNotAuthorize(): void
    {
        foreach (['7abc', ' 7', '7.9', '7 ', '+7', '0x7', "7\n"] as $i => $value) {
            $this->seed('casts to seven ' . $i, authorizedUserId: $value);
        }
        $this->seed('properly published');

        self::assertCount(8, $this->all());
        self::assertSame(
            ['properly published'],
            $this->visible(),
            'a value that merely CASTS to the author id must not authorize'
        );
    }

    /** An empty marker timestamp is not a marker. */
    public function testAnEmptyMarkerValueDoesNotAuthorize(): void
    {
        $this->seed('empty timestamp', authorizedAt: '');
        $this->seed('properly published');

        self::assertSame(['properly published'], $this->visible());
    }

    /**
     * ⚠ Author 0 is never an operator. A row with `post_author = 0` and a
     * marker of `'0'` is internally CONSISTENT — the marker equals the
     * author — so the id comparison alone would pass it. The explicit
     * `post_author > 0` rung is what refuses it, and this is the case that
     * proves the rung is load-bearing rather than decorative.
     */
    public function testAnAuthorlessRowWithAConsistentZeroMarkerIsNotPublic(): void
    {
        $this->seed('nobody authorized this', author: 0, authorizedUserId: '0');
        $this->seed('properly published');

        self::assertSame(['properly published'], $this->visible());
    }

    /**
     * ⚠⚠ A DUPLICATED marker key — one wrong, one right — is the shape a
     * bad migration leaves behind. EXISTS answers "is there a row that
     * satisfies this", so the correct duplicate wins and the row is
     * public. That is the intended reading, and it is asserted here so a
     * future change to JOINs (which would multiply the row instead) is
     * caught: the count must be ONE, not two.
     */
    public function testADuplicatedMarkerDoesNotMultiplyTheRow(): void
    {
        global $wpdb;
        $id = $this->seed('duplicated marker');
        $wpdb->insert(self::META, [
            'post_id'    => $id,
            'meta_key'   => Vis::META_AUTHORIZED_USER_ID,
            'meta_value' => '9999',
        ]);

        self::assertSame(['duplicated marker'], $this->visible(), 'exactly one row, not two');
    }

    // ── 3. Type scoping — MUST-NOT-HAPPEN #7 ────────────────────────────

    public function testAnOrdinaryPostCarryingOurMetaIsNotAnAnnouncement(): void
    {
        $this->seed('an ordinary post', postType: 'post');
        $this->seed('a page', postType: 'page');
        $this->seed('properly published');

        self::assertCount(3, $this->all());
        self::assertSame(['properly published'], $this->visible());
    }

    // ── 4. Archival is NOT part of visibility — MUST-NOT-HAPPEN #6 ──────

    /**
     * ⚠⚠ §4.32.6: archival is a public record, not a deletion. An
     * archived announcement keeps its permalink and must still satisfy the
     * PUBLIC predicate — otherwise the Archived section is empty and the
     * permalink 404s.
     */
    public function testAnArchivedAnnouncementIsStillPubliclyVisible(): void
    {
        $this->seed('archived', archivedAt: '2026-01-01 00:00:00');
        $this->seed('active');

        self::assertSame(['archived', 'active'], $this->visible());
    }

    /** The two archival filters must partition the public set exactly. */
    public function testTheArchivalFiltersAreExactComplements(): void
    {
        $this->seed('archived one', archivedAt: '2026-01-01 00:00:00');
        $this->seed('archived two', archivedAt: '2026-02-02 00:00:00');
        $this->seed('active one');
        $this->seed('active two');

        $active   = $this->visible(Vis::notArchivedSql(self::META, 'p'));
        $archived = $this->visible(Vis::archivedSql(self::META, 'p'));

        self::assertSame(['active one', 'active two'], $active);
        self::assertSame(['archived one', 'archived two'], $archived);

        // Exhaustive and disjoint — no public row is in both or neither.
        self::assertSame([], array_intersect($active, $archived));
        self::assertCount(count($this->visible()), array_merge($active, $archived));
    }

    /**
     * ⚠ `update_post_meta($id, $key, '')` leaves a ROW behind. A bare
     * key-presence test would read that as archived forever, stranding the
     * announcement in the Archived section with no way back — and archival
     * is one-way, so "no way back" is permanent.
     */
    public function testAnEmptyArchivedAtIsNotArchived(): void
    {
        $this->seed('cleared archive flag', archivedAt: '');
        $this->seed('active');

        self::assertSame(
            ['cleared archive flag', 'active'],
            $this->visible(Vis::notArchivedSql(self::META, 'p')),
            'an empty meta value is not an archival'
        );
        self::assertSame([], $this->visible(Vis::archivedSql(self::META, 'p')));
    }

    // ── 5. The predicate composes, and refuses a bad alias ──────────────

    public function testThePredicateAndsOntoAnExistingWhereRatherThanReplacingIt(): void
    {
        $this->seed('author seven', author: 7, authorizedUserId: '7');
        $this->seed('author eight', author: 8, authorizedUserId: '8');

        self::assertSame(['author seven'], $this->visible('p.post_author = 7'));
    }

    /**
     * The alias reaches SQL as an identifier. Anything else is a
     * programming error, and it is refused loudly rather than escaped into
     * something that looks like it worked.
     */
    public function testANonIdentifierAliasIsRefused(): void
    {
        foreach (['p; DROP TABLE wp_posts', 'p OR 1=1', '', '1p', 'p p', 'p`'] as $bad) {
            try {
                Vis::publicPredicateSql(self::META, $bad);
                self::fail('alias [' . $bad . '] should have been refused');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        // And the archival filters guard it too — three producers, one rule.
        foreach (['notArchivedSql', 'archivedSql'] as $method) {
            try {
                Vis::$method(self::META, 'p;--');
                self::fail($method . ' should have refused the alias');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /**
     * ⚠⚠⚠ THE PREDICATE MUST NOT DEPEND ON ANY COLLATION.
     *
     * An earlier version compared `meta_value = CAST(post_author AS CHAR)`.
     * On MySQL 8 that raised `Illegal mix of collations
     * (utf8mb4_unicode_ci, IMPLICIT) and (utf8mb4_0900_ai_ci, IMPLICIT)` —
     * the predicate did not mis-answer, it **errored**, and it would have
     * done so in production wherever `wp_postmeta`'s collation differs
     * from the server default.
     *
     * So the marker comparison is numeric end to end. This case asserts
     * that structurally rather than hoping: no `COLLATE`, and no string
     * equality against `meta_value`.
     */
    public function testTheMarkerComparisonIsCollationIndependent(): void
    {
        $sql = Vis::publicPredicateSql(self::META, 'p');

        self::assertStringNotContainsStringIgnoringCase(
            'COLLATE',
            $sql,
            'a hardcoded collation is a guess about the deployment'
        );
        self::assertStringNotContainsString(
            'meta_value = CAST',
            $sql,
            'string equality against meta_value reintroduces the collation mix'
        );
        // The numeric round trip is what replaced it.
        self::assertStringContainsString('CAST(bcc_au.meta_value AS UNSIGNED)', $sql);
        self::assertStringContainsString('LENGTH(bcc_au.meta_value)', $sql);
    }

    /**
     * ⚠ And it must survive a table whose collation differs from the
     * server default — which is the condition that produced the error.
     * Seeded by re-creating the meta fixture under a DIFFERENT collation
     * and running the real predicate over it.
     */
    public function testThePredicateRunsAgainstAMetaTableWithADifferentCollation(): void
    {
        global $wpdb;

        $this->seed('collation check');

        // Rebuild the meta table under a deliberately different collation,
        // preserving its rows.
        $rows = $wpdb->get_results('SELECT post_id, meta_key, meta_value FROM `' . self::META . '`');
        $wpdb->query('DROP TABLE `' . self::META . '`');
        $created = $wpdb->query(
            'CREATE TABLE `' . self::META . '` (
                meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                meta_key VARCHAR(255) DEFAULT NULL,
                meta_value LONGTEXT,
                PRIMARY KEY (meta_id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'
        );
        self::assertNotFalse($created, 'collation fixture: ' . $wpdb->last_error);

        foreach ((array) $rows as $r) {
            $wpdb->insert(self::META, [
                'post_id'    => (int) $r->post_id,
                'meta_key'   => (string) $r->meta_key,
                'meta_value' => (string) $r->meta_value,
            ]);
        }

        self::assertSame(['collation check'], $this->visible());
    }

    /**
     * ⚠ The META TABLE is an identifier too, and it now arrives from the
     * caller rather than from `$wpdb`. So it gets the same validation as
     * the alias — a caller that threaded a request value through here
     * would otherwise have an injection point in a predicate whose whole
     * job is to be trustworthy.
     */
    public function testANonIdentifierMetaTableIsRefused(): void
    {
        foreach ([
            'wp_postmeta; DROP TABLE wp_posts',
            'wp_postmeta WHERE 1=1',
            '',
            '1meta',
            'wp postmeta',
            'wp_postmeta`',
        ] as $bad) {
            try {
                Vis::publicPredicateSql($bad, 'p');
                self::fail('meta table [' . $bad . '] should have been refused');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /**
     * ⚠ The predicate must work under any valid alias, because the four
     * read paths do not all name their posts table `p`.
     */
    public function testThePredicateWorksUnderAnAlternativeAlias(): void
    {
        global $wpdb;
        $this->seed('aliased');

        $rows = $wpdb->get_col(
            'SELECT posts.post_title FROM `' . self::POSTS . '` posts WHERE '
            . Vis::publicPredicateSql(self::META, 'posts')
        );
        self::assertSame('', (string) $wpdb->last_error);
        self::assertSame(['aliased'], is_array($rows) ? array_map('strval', $rows) : []);
    }
}
