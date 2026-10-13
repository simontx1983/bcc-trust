<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Announcements\AnnouncementPostType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The generic-comment-read exclusion, against REAL rows in a REAL database.
 *
 * `GET /wp/v2/comments` **without** `?post=` is the one surface
 * `show_in_rest => false` does not close: core skips the post loop and ends
 * by allowing `edit_comment` (→ `moderate_comments`), so an Editor or
 * Administrator could otherwise enumerate announcement comments — removed
 * ones included, with bodies and author identity — outside the audited
 * admin route. That surface is a `WP_Comment_Query`, and the
 * `comments_clauses` filter is the only thing closing it.
 *
 * WordPress core is not loadable in this suite, so this does not drive the
 * REST controller. What it does drive is **the exact SQL fragment the
 * production filter emits**, executed by MySQL/MariaDB against a faithful
 * `wp_comments` table — which is the part a reimplementation could get
 * wrong and a unit test cannot catch.
 *
 * ── ⚠⚠⚠ THE THINGS THAT MUST NOT HAPPEN ─────────────────────────────────
 *  1. AN ANNOUNCEMENT COMMENT SURVIVES A GENERIC READ. The whole point.
 *  2. A MALFORMED STORED RECORD SURVIVES. Nothing of ours writes
 *     `BCC_Announce_Comment ` with different case and trailing
 *     whitespace — a plugin, an import, a migration or hand-written SQL
 *     can. Privacy must not depend on the writer being well-behaved, so
 *     the predicate normalizes before comparing and these rows are seeded
 *     deliberately.
 *  3. ORDINARY COMMENTS ARE WITHHELD. A predicate that excluded everything
 *     would satisfy 1 and 2 while destroying the site's own discussion.
 *     Every case here has an ordinary-comment counterpart in the same
 *     query.
 *  4. CORE'S `note` COMMENTS ARE SWALLOWED. WordPress 7.0 uses `note` as
 *     an internal editorial channel and skips the post-permission check
 *     for it. It is not ours to hide.
 *  5. AN ORPHANED ANNOUNCEMENT COMMENT LEAKS. A row whose
 *     `comment_post_ID` no longer resolves still carries announcement
 *     content. The exclusion is keyed on the TYPE, not on a join, so it
 *     must hold with no parent at all.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(AnnouncementPostType::class)]
final class AnnouncementCommentExclusionIntegrationTest extends TestCase
{
    private const TABLE = 'wp_comments';

    protected function setUp(): void
    {
        global $wpdb;

        // The integration harness does not create wp_comments — no BCC read
        // path needed it until now. Built here, faithful in the columns this
        // predicate touches, following the house pattern for a table the
        // suite owns rather than the installer.
        $wpdb->query('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        $created = $wpdb->query(
            'CREATE TABLE `' . self::TABLE . '` (
                comment_ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                comment_post_ID BIGINT UNSIGNED NOT NULL DEFAULT 0,
                comment_author TINYTEXT NOT NULL,
                comment_content TEXT NOT NULL,
                comment_approved VARCHAR(20) NOT NULL DEFAULT \'1\',
                comment_type VARCHAR(20) NOT NULL DEFAULT \'comment\',
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (comment_ID),
                KEY comment_type (comment_type)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        // ⚠ $wpdb->query() returns false SILENTLY on failure, which would
        // make every assertion below run against a missing table.
        self::assertNotFalse($created, 'wp_comments fixture must be created: ' . $wpdb->last_error);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS `' . self::TABLE . '`');
    }

    private function seed(string $type, string $content, int $postId = 900): int
    {
        global $wpdb;
        $ok = $wpdb->insert(self::TABLE, [
            'comment_post_ID'  => $postId,
            'comment_author'   => 'fixture',
            'comment_content'  => $content,
            'comment_approved' => '1',
            'comment_type'     => $type,
            'user_id'          => 7,
        ]);
        self::assertNotFalse($ok, "seeding {$type} must succeed: " . $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    /**
     * Runs the REAL filter output as the WHERE of a generic comment read —
     * the shape `WP_Comment_Query` builds when no `?post=` narrows it.
     *
     * @return list<string> the comment_content of every visible row
     */
    private function visibleContent(): array
    {
        global $wpdb;

        /** @var array<string, string> $clauses */
        $clauses = AnnouncementPostType::excludeFromCommentQueries(
            ['where' => "comment_approved = '1'"]
        );
        $where = $clauses['where'];

        // Sanity: the fragment really is in the clause we are about to run.
        self::assertStringContainsString(
            AnnouncementPostType::commentExclusionSql(),
            $where,
            'the production fragment must be what is executed'
        );

        $rows = $wpdb->get_col(
            'SELECT comment_content FROM `' . self::TABLE . '` WHERE ' . $where . ' ORDER BY comment_ID'
        );
        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    /** Everything in the table, so each case can state its denominator. */
    private function allContent(): array
    {
        global $wpdb;
        $rows = $wpdb->get_col('SELECT comment_content FROM `' . self::TABLE . '` ORDER BY comment_ID');
        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    // ── 1. The core guarantee, with its counterpart ───────────────────────

    public function testAnAnnouncementCommentIsExcludedWhileOrdinaryCommentsSurvive(): void
    {
        $this->seed('bcc_announce_comment', 'SECRET announcement reply');
        $this->seed('comment', 'ordinary blog comment');
        $this->seed('', 'legacy empty-type comment');

        // Anti-vacuity: all three really are stored.
        self::assertCount(3, $this->allContent());

        $visible = $this->visibleContent();

        self::assertNotContains('SECRET announcement reply', $visible);
        self::assertContains('ordinary blog comment', $visible);
        self::assertContains('legacy empty-type comment', $visible);
        self::assertCount(2, $visible);
    }

    // ── 2. Malformed stored records — MUST-NOT-HAPPEN #2 ──────────────────

    /**
     * None of these spellings is written by anything of ours. They are
     * seeded precisely because "we never create those" is a statement
     * about the writer, not a property of the table.
     */
    public function testMalformedAnnouncementTypesAreAlsoExcluded(): void
    {
        $malformed = [
            'BCC_Announce_Comment'   => 'mixed case leak',
            'bcc_announce_comment '  => 'trailing space leak',
            ' bcc_announce_comment'  => 'leading space leak',
            'BCC_ANNOUNCE_COMMENT'   => 'upper case leak',
        ];
        foreach ($malformed as $type => $content) {
            $this->seed($type, $content);
        }
        $this->seed('comment', 'ordinary survives');

        self::assertCount(5, $this->allContent(), 'all five rows must be stored');

        $visible = $this->visibleContent();

        foreach ($malformed as $content) {
            self::assertNotContains($content, $visible, $content);
        }
        self::assertSame(['ordinary survives'], $visible);
    }

    // ── 3. Lookalikes are NOT ours — MUST-NOT-HAPPEN #3 ───────────────────

    /**
     * The predicate must be precise, not greedy. A type that merely
     * resembles ours belongs to whoever wrote it.
     */
    public function testLookalikeTypesAreNotSwallowed(): void
    {
        foreach ([
            'bcc_announcement'          => 'different type entirely',
            'bcc_announce_commen' => 'plural lookalike',
            'announcement_comment'      => 'unprefixed lookalike',
        ] as $type => $content) {
            $this->seed($type, $content);
        }

        $visible = $this->visibleContent();

        self::assertCount(3, $visible, 'none of these is ours to hide');
        self::assertContains('plural lookalike', $visible);
    }

    // ── 4. Core notes — MUST-NOT-HAPPEN #4 ────────────────────────────────

    /**
     * WordPress 7.0 special-cases `comment_type === 'note'` out of the
     * post-permission check entirely. Notes are core's editorial channel,
     * not ours, and hiding them would be a silent regression in a feature
     * we do not own.
     */
    public function testCoreNotesAreLeftVisible(): void
    {
        $this->seed('note', 'an editorial note');
        $this->seed('bcc_announce_comment', 'ours, hidden');

        $visible = $this->visibleContent();

        self::assertContains('an editorial note', $visible);
        self::assertNotContains('ours, hidden', $visible);
    }

    // ── 5. Orphans — MUST-NOT-HAPPEN #5 ───────────────────────────────────

    /**
     * The exclusion is keyed on the TYPE, never on a join to `wp_posts`.
     * An announcement comment whose parent is gone still carries
     * announcement content, and a join-based predicate would leak exactly
     * the rows nobody is maintaining.
     */
    public function testAnOrphanedAnnouncementCommentIsStillExcluded(): void
    {
        $this->seed('bcc_announce_comment', 'orphaned but still ours', 0);
        $this->seed('bcc_announce_comment', 'parent missing too', 999999);
        $this->seed('comment', 'orphaned ordinary comment', 0);

        $visible = $this->visibleContent();

        self::assertNotContains('orphaned but still ours', $visible);
        self::assertNotContains('parent missing too', $visible);
        self::assertContains('orphaned ordinary comment', $visible);
    }

    // ── 6. The clause composes with whatever core already built ───────────

    /**
     * `WP_Comment_Query` hands the filter a WHERE that is already
     * populated. The exclusion must AND onto it rather than replace it —
     * replacing would widen the read, which is the opposite failure and
     * would not be caught by any "is the announcement hidden" assertion.
     */
    public function testTheExistingWhereIsPreservedNotReplaced(): void
    {
        global $wpdb;

        $this->seed('comment', 'approved ordinary');
        $id = $this->seed('comment', 'UNAPPROVED ordinary');
        $wpdb->query('UPDATE `' . self::TABLE . "` SET comment_approved = '0' WHERE comment_ID = " . $id);
        $this->seed('bcc_announce_comment', 'ours');

        $visible = $this->visibleContent();

        self::assertSame(['approved ordinary'], $visible);
        self::assertContains('UNAPPROVED ordinary', $this->allContent(), 'the row exists; the clause hid it');
    }

    /** An empty table must not error, and must not return a phantom row. */
    public function testAnEmptyTableIsHandled(): void
    {
        self::assertSame([], $this->visibleContent());
    }
}
