<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Announcements\AnnouncementCommentPolicy;
use BCC\Trust\Core\Announcements\AnnouncementPostType;
use BCC\Trust\Core\Announcements\AnnouncementVisibility;
use BCC\Trust\Core\Repositories\AnnouncementCommentRepository;
use BCC\Trust\Core\Support\KeysetCursor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A5 — the announcement comment repository, against real SQL, on both engines.
 *
 * ── ⚠⚠ WHY THIS SUITE IS THE ONE THAT MATTERS ───────────────────────────────
 * §4.32.7 requires "stable OLDEST-FIRST" order, and this is the ONLY ascending
 * surface in the whole announcement feature — every announcement list is newest
 * first. That inverts the keyset comparison from `<` to `>`, and the failure
 * mode of getting it wrong is NOT an error: a descending comparison against an
 * ascending ORDER BY serves THE FIRST PAGE FOREVER, because every row is
 * "before" a cursor taken from the end of the previous page. A reader paging
 * through a discussion would see the same comments repeat, which looks like a
 * UI bug rather than a cursor bug.
 *
 * So the paging cases below walk the WHOLE thread and assert that the pages are
 * disjoint and that their union is the complete set in order. Asserting only
 * "page 2 is non-empty" would pass for the broken comparison.
 *
 * ⚠ Injected tables (`wp_bcc_anncmt_*`), never the shared `wp_comments` /
 * `wp_posts` — forced, because the harness's shared tables do not carry every
 * column this SQL reads, and dropping a shared table once poisoned 57
 * unrelated cases.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(AnnouncementCommentRepository::class)]
final class AnnouncementCommentRepositoryIntegrationTest extends TestCase
{
    private const COMMENTS = 'wp_bcc_anncmt_comments';
    private const CMETA    = 'wp_bcc_anncmt_commentmeta';
    private const POSTS    = 'wp_bcc_anncmt_posts';
    private const PMETA    = 'wp_bcc_anncmt_postmeta';

    private const ANN   = 5501;
    private const OTHER = 5502;
    private const PAGE  = 9901;
    private const AUTH  = 31;

    private AnnouncementCommentRepository $repo;

    protected function setUp(): void
    {
        global $wpdb;

        foreach ([self::CMETA, self::COMMENTS, self::PMETA, self::POSTS] as $t) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $t . '`');
        }

        // ⚠ DATETIME carries NO DEFAULT: WordPress ships '0000-00-00 00:00:00',
        // which MySQL strict mode rejects, so a default would make this fixture
        // create on MariaDB and fail on MySQL.
        $ok = $wpdb->query(
            'CREATE TABLE `' . self::COMMENTS . '` (
                comment_ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                comment_post_ID BIGINT UNSIGNED NOT NULL DEFAULT 0,
                comment_author TINYTEXT NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                comment_date_gmt DATETIME NOT NULL,
                comment_date DATETIME NOT NULL,
                comment_content TEXT NOT NULL,
                comment_approved VARCHAR(20) NOT NULL DEFAULT \'1\',
                comment_type VARCHAR(20) NOT NULL DEFAULT \'comment\',
                comment_parent BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (comment_ID),
                KEY post_type_date (comment_post_ID, comment_type, comment_date_gmt)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        // ⚠ asserted: $wpdb->query() returns false SILENTLY, so without this
        // every case would run against a missing table and pass vacuously.
        self::assertNotFalse($ok, 'comments fixture: ' . $wpdb->last_error);

        $ok = $wpdb->query(
            'CREATE TABLE `' . self::CMETA . '` (
                meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                comment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                meta_key VARCHAR(255) DEFAULT NULL,
                meta_value LONGTEXT,
                PRIMARY KEY (meta_id),
                KEY comment_key (comment_id, meta_key(32))
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        self::assertNotFalse($ok, 'commentmeta fixture: ' . $wpdb->last_error);

        $ok = $wpdb->query(
            'CREATE TABLE `' . self::POSTS . '` (
                ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_author BIGINT UNSIGNED NOT NULL DEFAULT 0,
                post_parent BIGINT UNSIGNED NOT NULL DEFAULT 0,
                post_date_gmt DATETIME NOT NULL,
                post_status VARCHAR(20) NOT NULL DEFAULT \'publish\',
                post_type VARCHAR(20) NOT NULL DEFAULT \'post\',
                PRIMARY KEY (ID)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        self::assertNotFalse($ok, 'posts fixture: ' . $wpdb->last_error);

        $ok = $wpdb->query(
            'CREATE TABLE `' . self::PMETA . '` (
                meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                meta_key VARCHAR(255) DEFAULT NULL,
                meta_value LONGTEXT,
                PRIMARY KEY (meta_id),
                KEY post_key (post_id, meta_key(32))
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        self::assertNotFalse($ok, 'postmeta fixture: ' . $wpdb->last_error);

        // Two announcements, so cross-announcement isolation is testable.
        foreach ([self::ANN, self::OTHER] as $id) {
            $ok = $wpdb->insert(self::POSTS, [
                'ID'            => $id,
                'post_author'   => self::AUTH,
                'post_parent'   => self::PAGE,
                'post_date_gmt' => '2026-10-01 00:00:00',
                'post_status'   => 'publish',
                'post_type'     => AnnouncementPostType::POST_TYPE,
            ], ['%d', '%d', '%d', '%s', '%s', '%s']);
            self::assertNotFalse($ok, 'announcement seed: ' . $wpdb->last_error);
        }

        $this->repo = new AnnouncementCommentRepository(
            self::COMMENTS,
            self::CMETA,
            self::POSTS,
            self::PMETA
        );
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ([self::CMETA, self::COMMENTS, self::PMETA, self::POSTS] as $t) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $t . '`');
        }
    }

    /** Seed one comment at an explicit instant. Returns its id. */
    private function seed(int $announcementId, int $userId, string $body, string $atUtc): int
    {
        return $this->repo->create($announcementId, $userId, $body, $atUtc);
    }

    /** @param list<\stdClass> $rows @return list<int> */
    private static function ids(array $rows): array
    {
        return array_map(static fn (\stdClass $r): int => (int) $r->comment_ID, $rows);
    }

    // ── creation ─────────────────────────────────────────────────────────

    public function testACommentIsStoredWithTheAnnouncementCommentType(): void
    {
        global $wpdb;

        $id = $this->seed(self::ANN, 44, 'Thanks for the heads up.', '2026-10-09 15:01:02');
        self::assertGreaterThan(0, $id);

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT comment_type, comment_approved, comment_parent, comment_content, user_id
               FROM `' . self::COMMENTS . '` WHERE comment_ID = %d',
            $id
        ));

        self::assertNotNull($row);
        self::assertSame(AnnouncementPostType::COMMENT_TYPE, $row->comment_type);
        self::assertSame('1', $row->comment_approved, 'approval is NOT overloaded as a removal state');
        self::assertSame('0', (string) $row->comment_parent, 'v1 comments are flat');
        self::assertSame('Thanks for the heads up.', $row->comment_content);
        self::assertSame('44', (string) $row->user_id);
    }

    /**
     * ⚠ `comment_type` must NEVER be `note`: WordPress's
     * `check_read_permission()` opens with
     * `if ('note' !== $comment->comment_type && ...)`, skipping the post check
     * entirely — which would hand an announcement comment to anyone.
     */
    public function testTheStoredTypeIsNeverNote(): void
    {
        global $wpdb;

        $id   = $this->seed(self::ANN, 44, 'x', '2026-10-09 15:01:02');
        $type = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT comment_type FROM `' . self::COMMENTS . '` WHERE comment_ID = %d',
            $id
        ));

        self::assertNotSame('note', $type);
        self::assertSame('bcc_ann_comment', $type);
    }

    public function testCreateRefusesNonsenseWithoutWriting(): void
    {
        global $wpdb;

        self::assertSame(0, $this->repo->create(0, 44, 'x', '2026-10-09 15:01:02'));
        self::assertSame(0, $this->repo->create(self::ANN, 0, 'x', '2026-10-09 15:01:02'));
        self::assertSame(0, $this->repo->create(self::ANN, 44, '', '2026-10-09 15:01:02'));

        self::assertSame(
            '0',
            (string) $wpdb->get_var('SELECT COUNT(*) FROM `' . self::COMMENTS . '`'),
            'a refused create must write nothing'
        );
    }

    // ── ⚠⚠ ascending paging, walked end to end ───────────────────────────

    /**
     * ⚠⚠ THE CENTRAL CASE. Pages the whole thread and asserts the pages are
     * DISJOINT and their union is the complete set in ascending order.
     *
     * A descending comparison (`<`, pasted from the announcement lists) would
     * return page 1 again as page 2 — so a weaker assertion like "page 2 is
     * non-empty" would pass for the broken code. This one cannot.
     */
    public function testPagingWalksTheWholeThreadOldestFirstWithoutRepeating(): void
    {
        $expected = [];
        for ($i = 1; $i <= 7; $i++) {
            // Distinct seconds, so order is unambiguous for this case.
            $expected[] = $this->seed(self::ANN, 40 + $i, 'c' . $i, sprintf('2026-10-09 15:0%d:00', $i));
        }

        $seen   = [];
        $cursor = null;
        $guard  = 0;

        do {
            $page = $this->repo->listForAnnouncement(self::ANN, 3, $cursor);
            $ids  = self::ids($page['rows']);

            self::assertSame([], array_intersect($seen, $ids), 'pages must be disjoint — a repeated page means the comparison is inverted');

            $seen   = array_merge($seen, $ids);
            $cursor = $page['has_more'] ? $this->repo->nextCursor($page['rows']) : null;

            self::assertLessThan(10, ++$guard, 'paging did not terminate');
        } while ($cursor !== null);

        self::assertSame($expected, $seen, 'the union of the pages must be the whole thread, in ascending order');
    }

    /** The unique tiebreak: comments sharing a second still order deterministically. */
    public function testCommentsInTheSameSecondAreOrderedByIdAscending(): void
    {
        $a = $this->seed(self::ANN, 41, 'first', '2026-10-09 15:00:00');
        $b = $this->seed(self::ANN, 42, 'second', '2026-10-09 15:00:00');
        $c = $this->seed(self::ANN, 43, 'third', '2026-10-09 15:00:00');

        $page = $this->repo->listForAnnouncement(self::ANN, 20, null);
        self::assertSame([$a, $b, $c], self::ids($page['rows']));
    }

    /**
     * And paging ACROSS a tie must not skip or repeat — this is the shape the
     * recorded creator-gallery defect took (313 rows tied on one column).
     */
    public function testPagingAcrossATieNeitherSkipsNorRepeats(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->seed(self::ANN, 50 + $i, 'tied' . $i, '2026-10-09 15:00:00');
        }

        $p1 = $this->repo->listForAnnouncement(self::ANN, 2, null);
        self::assertTrue($p1['has_more']);
        $p2 = $this->repo->listForAnnouncement(self::ANN, 2, $this->repo->nextCursor($p1['rows']));
        $p3 = $this->repo->listForAnnouncement(self::ANN, 2, $this->repo->nextCursor($p2['rows']));

        $walked = array_merge(self::ids($p1['rows']), self::ids($p2['rows']), self::ids($p3['rows']));
        self::assertSame($ids, $walked);
    }

    public function testHasMoreIsFalseOnAnExactBoundaryPage(): void
    {
        // ⚠ The limit+1 over-fetch exists for exactly this: inferring the
        // cursor from count($rows) === $limit mints a false-positive cursor and
        // hands the reader an empty next page.
        for ($i = 0; $i < 3; $i++) {
            $this->seed(self::ANN, 60 + $i, 'c' . $i, sprintf('2026-10-09 15:0%d:00', $i));
        }

        $page = $this->repo->listForAnnouncement(self::ANN, 3, null);
        self::assertCount(3, $page['rows']);
        self::assertFalse($page['has_more'], 'an exact-boundary page has no next page');
        self::assertNull($this->repo->nextCursor([]), 'and an empty page mints no cursor');
    }

    public function testTheLimitIsClampedToTheDocumentedMaximum(): void
    {
        self::assertSame(20, AnnouncementCommentRepository::clampLimit(null));
        self::assertSame(20, AnnouncementCommentRepository::clampLimit(0));
        self::assertSame(20, AnnouncementCommentRepository::clampLimit(-5));
        self::assertSame(7, AnnouncementCommentRepository::clampLimit(7));
        self::assertSame(50, AnnouncementCommentRepository::clampLimit(50));
        self::assertSame(50, AnnouncementCommentRepository::clampLimit(10000));
    }

    /** A cursor minted for another surface must be ignored, not honoured. */
    public function testACursorFromAnotherSurfaceIsNotHonoured(): void
    {
        $a = $this->seed(self::ANN, 41, 'first', '2026-10-09 15:00:00');
        $this->seed(self::ANN, 42, 'second', '2026-10-09 15:00:01');

        $foreign = KeysetCursor::encode(
            KeysetCursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            '2026-10-09 15:00:00',
            $a
        );

        $page = $this->repo->listForAnnouncement(self::ANN, 20, $foreign);
        self::assertCount(2, $page['rows'], 'a foreign cursor is discarded, so the read starts from the beginning');
    }

    // ── isolation ────────────────────────────────────────────────────────

    public function testCommentsDoNotLeakBetweenAnnouncements(): void
    {
        $mine   = $this->seed(self::ANN, 41, 'mine', '2026-10-09 15:00:00');
        $theirs = $this->seed(self::OTHER, 42, 'theirs', '2026-10-09 15:00:01');

        self::assertSame([$mine], self::ids($this->repo->listForAnnouncement(self::ANN, 20, null)['rows']));
        self::assertSame([$theirs], self::ids($this->repo->listForAnnouncement(self::OTHER, 20, null)['rows']));
    }

    public function testAnOrdinaryCommentOnTheSamePostIsNotReturned(): void
    {
        global $wpdb;

        $this->seed(self::ANN, 41, 'announcement comment', '2026-10-09 15:00:00');

        // An ordinary comment, same post, different type.
        $wpdb->insert(self::COMMENTS, [
            'comment_post_ID'  => self::ANN,
            'comment_author'   => '',
            'user_id'          => 42,
            'comment_date_gmt' => '2026-10-09 15:00:01',
            'comment_date'     => '2026-10-09 15:00:01',
            'comment_content'  => 'ordinary',
            'comment_approved' => '1',
            'comment_type'     => 'comment',
            'comment_parent'   => 0,
        ], ['%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d']);

        $page = $this->repo->listForAnnouncement(self::ANN, 20, null);
        self::assertCount(1, $page['rows'], 'only announcement-typed comments belong to this thread');
    }

    public function testFindOneRefusesAnOrdinaryCommentId(): void
    {
        global $wpdb;

        $wpdb->insert(self::COMMENTS, [
            'comment_post_ID'  => self::ANN,
            'comment_author'   => '',
            'user_id'          => 42,
            'comment_date_gmt' => '2026-10-09 15:00:01',
            'comment_date'     => '2026-10-09 15:00:01',
            'comment_content'  => 'ordinary',
            'comment_approved' => '1',
            'comment_type'     => 'comment',
            'comment_parent'   => 0,
        ], ['%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d']);
        $ordinary = (int) $wpdb->insert_id;

        self::assertNull($this->repo->findOne($ordinary), 'an ordinary comment is not an announcement comment');
        self::assertNull($this->repo->findOne(0));
        self::assertNull($this->repo->findOne(999999));
    }

    // ── tombstones ───────────────────────────────────────────────────────

    public function testARemovedCommentKeepsItsPositionInTheThread(): void
    {
        $a = $this->seed(self::ANN, 41, 'first', '2026-10-09 15:00:00');
        $b = $this->seed(self::ANN, 42, 'middle', '2026-10-09 15:00:01');
        $c = $this->seed(self::ANN, 43, 'last', '2026-10-09 15:00:02');

        $this->repo->markRemoved($b, AnnouncementCommentPolicy::ACTOR_AUTHOR, '2026-10-09 15:02:11');

        $rows = $this->repo->listForAnnouncement(self::ANN, 20, null)['rows'];
        self::assertSame([$a, $b, $c], self::ids($rows), 'the tombstone stays in position');

        $middle = $rows[1];
        self::assertSame('2026-10-09 15:02:11', (string) $middle->removed_at);
        self::assertSame(AnnouncementCommentPolicy::ACTOR_AUTHOR, (string) $middle->removed_actor);
    }

    public function testTheActorTypeIsStoredSoTheLabelCanBeChosen(): void
    {
        $id = $this->seed(self::ANN, 41, 'x', '2026-10-09 15:00:00');
        $this->repo->markRemoved($id, AnnouncementCommentPolicy::ACTOR_VALIDATOR, '2026-10-09 15:02:11');

        $row = $this->repo->findOne($id);
        self::assertNotNull($row);
        self::assertSame(AnnouncementCommentPolicy::ACTOR_VALIDATOR, (string) $row->removed_actor);
        self::assertSame(
            AnnouncementCommentPolicy::LABEL_VALIDATOR,
            AnnouncementCommentPolicy::labelFor((string) $row->removed_actor)
        );
    }

    /**
     * ⚠ THE PRIVATE ORIGINAL SURVIVES. §4.32.7 gives an administrator
     * `GET /bcc/v1/admin/announcement-comments/:commentId` returning "the
     * private original", and a restore path. Both require the body to still be
     * in the row — which is the decisive reason the tombstone is META and not a
     * content overwrite.
     */
    public function testTheOriginalBodySurvivesRemovalForAdministratorRecovery(): void
    {
        $id = $this->seed(self::ANN, 41, 'the original words', '2026-10-09 15:00:00');
        $this->repo->markRemoved($id, AnnouncementCommentPolicy::ACTOR_AUTHOR, '2026-10-09 15:02:11');

        $row = $this->repo->findOne($id);
        self::assertNotNull($row);
        self::assertSame('the original words', (string) $row->comment_content, 'the original must be recoverable');
    }

    /**
     * ⚠ §4.32.7 requires `removed_at` not to move for a reader who may already
     * hold it, so a repeated removal is idempotent.
     */
    public function testARepeatedRemovalDoesNotMoveTheTimestamp(): void
    {
        $id = $this->seed(self::ANN, 41, 'x', '2026-10-09 15:00:00');

        $this->repo->markRemoved($id, AnnouncementCommentPolicy::ACTOR_AUTHOR, '2026-10-09 15:02:11');
        $this->repo->markRemoved($id, AnnouncementCommentPolicy::ACTOR_AUTHOR, '2026-10-09 15:02:11');

        global $wpdb;
        $n = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM `' . self::CMETA . '` WHERE comment_id = %d AND meta_key = %s',
            $id,
            AnnouncementCommentRepository::META_REMOVED_AT
        ));
        self::assertSame(1, $n, 'the marker must be upserted, not duplicated');

        $row = $this->repo->findOne($id);
        self::assertNotNull($row);
        self::assertSame('2026-10-09 15:02:11', (string) $row->removed_at);
    }

    public function testRestorationClearsBothMetaRows(): void
    {
        global $wpdb;

        $id = $this->seed(self::ANN, 41, 'x', '2026-10-09 15:00:00');
        $this->repo->markRemoved($id, AnnouncementCommentPolicy::ACTOR_VALIDATOR, '2026-10-09 15:02:11');

        self::assertTrue($this->repo->clearRemoved($id));

        $row = $this->repo->findOne($id);
        self::assertNotNull($row);
        self::assertNull($row->removed_at, 'restored means no tombstone marker');
        self::assertNull($row->removed_actor, 'and no stale actor left behind');

        self::assertSame(
            '0',
            (string) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM `' . self::CMETA . '` WHERE comment_id = %d',
                $id
            )),
            'both meta rows are gone'
        );
    }

    /** An empty stored marker is NOT a tombstone — presence of a value is the state. */
    public function testAnEmptyMarkerIsNotATombstone(): void
    {
        global $wpdb;

        $id = $this->seed(self::ANN, 41, 'x', '2026-10-09 15:00:00');
        $wpdb->insert(self::CMETA, [
            'comment_id' => $id,
            'meta_key'   => AnnouncementCommentRepository::META_REMOVED_AT,
            'meta_value' => '',
        ], ['%d', '%s', '%s']);

        $row = $this->repo->findOne($id);
        self::assertNotNull($row);
        self::assertNull($row->removed_at, 'NULLIF collapses an empty marker to null');
    }

    // ── comments_enabled ─────────────────────────────────────────────────

    /**
     * ⚠⚠ ABSENT MEANS ENABLED. The meta key is written only when an operator
     * turns comments OFF, so every announcement created before A5 — and every
     * one created without the field — has an open discussion. Defaulting the
     * other way would silently close every existing thread.
     */
    public function testCommentsAreEnabledWhenTheMetaIsAbsent(): void
    {
        self::assertTrue(
            $this->repo->commentsEnabled(self::ANN, AnnouncementVisibility::META_COMMENTS_ENABLED),
            'an announcement with no stored preference accepts comments'
        );
    }

    /** @param string $stored @param bool $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('enabledValues')]
    public function testTheStoredPreferenceIsReadAsAClosedFalsySet(string $stored, bool $expected): void
    {
        global $wpdb;

        $wpdb->insert(self::PMETA, [
            'post_id'    => self::ANN,
            'meta_key'   => AnnouncementVisibility::META_COMMENTS_ENABLED,
            'meta_value' => $stored,
        ], ['%d', '%s', '%s']);

        self::assertSame(
            $expected,
            $this->repo->commentsEnabled(self::ANN, AnnouncementVisibility::META_COMMENTS_ENABLED)
        );
    }

    /** @return array<string, array{string, bool}> */
    public static function enabledValues(): array
    {
        return [
            'one'      => ['1', true],
            'on'       => ['on', true],
            'true'     => ['true', true],
            'yes'      => ['yes', true],
            'zero'     => ['0', false],
            'off'      => ['off', false],
            'false'    => ['false', false],
            'no'       => ['no', false],
            'empty'    => ['', false],
            'OFF caps' => ['OFF', false],
            'padded'   => ['  off  ', false],
        ];
    }

    // ── fail-closed ──────────────────────────────────────────────────────

    /**
     * ⚠⚠ An errored read must THROW, never present as "no comments yet" — an
     * empty thread looks entirely normal, so the failure would be invisible.
     * This is the distinction the whole 503-not-200 design rests on.
     */
    public function testAnUnreadableThreadThrowsRatherThanLookingEmpty(): void
    {
        global $wpdb;

        // Remove the table from under the repository.
        $wpdb->query('DROP TABLE IF EXISTS `' . self::COMMENTS . '`');

        $this->expectException(\BCC\Trust\Core\Exceptions\RepositoryException::class);
        $this->repo->listForAnnouncement(self::ANN, 20, null);
    }

    public function testAnUnreadableSingleLookupAlsoThrows(): void
    {
        global $wpdb;

        $wpdb->query('DROP TABLE IF EXISTS `' . self::COMMENTS . '`');

        $this->expectException(\BCC\Trust\Core\Exceptions\RepositoryException::class);
        $this->repo->findOne(1);
    }

    public function testAnUnreadableEnabledFlagThrows(): void
    {
        global $wpdb;

        $wpdb->query('DROP TABLE IF EXISTS `' . self::PMETA . '`');

        $this->expectException(\BCC\Trust\Core\Exceptions\RepositoryException::class);
        $this->repo->commentsEnabled(self::ANN, AnnouncementVisibility::META_COMMENTS_ENABLED);
    }

    public function testAFailedWriteThrows(): void
    {
        global $wpdb;

        $wpdb->query('DROP TABLE IF EXISTS `' . self::COMMENTS . '`');

        $this->expectException(\BCC\Trust\Core\Exceptions\RepositoryException::class);
        $this->repo->create(self::ANN, 44, 'x', '2026-10-09 15:00:00');
    }

    /** A table name that is not an identifier is refused before any SQL is composed. */
    public function testAnInvalidTableIdentifierIsRefused(): void
    {
        $this->expectException(\BCC\Trust\Core\Exceptions\RepositoryException::class);
        new AnnouncementCommentRepository('wp_comments; DROP TABLE x', self::CMETA, self::POSTS, self::PMETA);
    }
}
