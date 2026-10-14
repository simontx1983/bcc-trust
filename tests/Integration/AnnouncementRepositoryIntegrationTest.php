<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Announcements\AnnouncementCapabilityResolver as Caps;
use BCC\Trust\Core\Announcements\AnnouncementPostType;
use BCC\Trust\Core\Announcements\AnnouncementVisibility as Vis;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Repositories\AnnouncementRepository;
use BCC\Trust\Core\Support\KeysetCursor as Cursor;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority as Authority;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Announcement reads — REAL SQL against a REAL engine.
 *
 * ── ⚠⚠ WHY OWNED FIXTURE TABLES ARE MANDATORY HERE ──────────────────────
 * The harness's shared `wp_posts` is a REDUCED table — `ID, post_title,
 * post_name, post_type, post_status` only, with **no `post_author` and no
 * `post_date_gmt`** (`tests/Integration/bootstrap.php:707`). Two of the
 * three §4.32.1 predicates read exactly those missing columns, so this SQL
 * **cannot be tested against the shared table at all**.
 *
 * And dropping the shared tables is not an option either: an earlier
 * revision of the sibling visibility suite did that and POISONED 57
 * UNRELATED INTEGRATION CASES in the same process. So the repository takes
 * its table names as constructor arguments and this suite owns
 * `wp_bcc_annrepo_*`.
 *
 * ── ⚠⚠⚠ THE THINGS THAT MUST NOT HAPPEN ─────────────────────────────────
 *  1. AN UNAUTHORIZED ROW IS PUBLIC. §4.32.1's predicate 3 — the
 *     publication-authorization marker — is the one that stops a row moved
 *     to `publish` early from going public without the ownership recheck.
 *     Each of the three predicates is removed independently here, on EVERY
 *     public read path.
 *  2. A FAILED QUERY LOOKS LIKE "NO ANNOUNCEMENTS". An empty announcement
 *     bar looks completely normal, so a silent `[]` on error would be
 *     invisible. Every read must throw instead.
 *  3. A KEYSET PAGE SKIPS A ROW. Pinning moves a row to the front
 *     mid-scroll (§4.32.12). Paging must not lose anything — and a dedupe
 *     assertion would pass while a row was lost, so the test compares the
 *     paged set against the whole set.
 *  4. THE OWNER LIST LEAKS. It is the only read that returns unpublished
 *     rows. A non-operator decision, a half-decision, and another
 *     operator's drafts must all come back empty.
 *  5. AN OFFSET SNEAKS IN. `LIMIT/OFFSET` with no unique tiebreak is the
 *     recorded creator-gallery defect (313/313 rows tied), and dedupe
 *     cannot recover a skipped row.
 *  6. A COMMENT COUNT IS INVENTED. It is a real grouped query; an id with
 *     no comments is ABSENT from the map, never a fabricated zero.
 *
 * ⚠ Tagged `mariadb` as well as `integration` because the SQL is
 * engine-sensitive: #324 found a collation mix that ERRORED on MySQL 8 and
 * a strict-mode default rejection that failed only there.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(AnnouncementRepository::class)]
final class AnnouncementRepositoryIntegrationTest extends TestCase
{
    private const POSTS    = 'wp_bcc_annrepo_posts';
    private const META     = 'wp_bcc_annrepo_meta';
    private const COMMENTS = 'wp_bcc_annrepo_comments';

    private const PAGE_ID  = 4471;
    private const OPERATOR = 7;

    private AnnouncementRepository $repo;

    protected function setUp(): void
    {
        global $wpdb;

        foreach ([self::COMMENTS, self::META, self::POSTS] as $t) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $t . '`');
        }

        // ⚠ post_date_gmt has NO DEFAULT. WordPress ships
        // '0000-00-00 00:00:00', which MySQL strict mode REJECTS — the
        // fixture then never creates and every case fails on one engine
        // while passing on the other. Every seed supplies the value.
        $posts = $wpdb->query(
            'CREATE TABLE `' . self::POSTS . '` (
                ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_author BIGINT UNSIGNED NOT NULL DEFAULT 0,
                post_parent BIGINT UNSIGNED NOT NULL DEFAULT 0,
                post_date_gmt DATETIME NOT NULL,
                post_modified_gmt DATETIME NOT NULL,
                post_title TEXT NOT NULL,
                post_excerpt TEXT NOT NULL,
                post_content LONGTEXT NOT NULL,
                post_status VARCHAR(20) NOT NULL DEFAULT \'publish\',
                post_type VARCHAR(20) NOT NULL DEFAULT \'post\',
                PRIMARY KEY (ID),
                KEY type_parent (post_type, post_parent, post_status)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        // ⚠ $wpdb->query() returns false SILENTLY — without this assertion
        // every case below would run against a missing table and pass
        // vacuously.
        self::assertNotFalse($posts, 'posts fixture: ' . $wpdb->last_error);

        $meta = $wpdb->query(
            'CREATE TABLE `' . self::META . '` (
                meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                meta_key VARCHAR(255) DEFAULT NULL,
                meta_value LONGTEXT,
                PRIMARY KEY (meta_id),
                KEY post_key (post_id, meta_key(64))
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        self::assertNotFalse($meta, 'meta fixture: ' . $wpdb->last_error);

        $comments = $wpdb->query(
            'CREATE TABLE `' . self::COMMENTS . '` (
                comment_ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                comment_post_ID BIGINT UNSIGNED NOT NULL DEFAULT 0,
                comment_content TEXT NOT NULL,
                comment_approved VARCHAR(20) NOT NULL DEFAULT \'1\',
                comment_type VARCHAR(20) NOT NULL DEFAULT \'comment\',
                PRIMARY KEY (comment_ID),
                KEY post_type (comment_post_ID, comment_type)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        self::assertNotFalse($comments, 'comments fixture: ' . $wpdb->last_error);

        $this->repo = new AnnouncementRepository(self::POSTS, self::META, self::COMMENTS);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ([self::COMMENTS, self::META, self::POSTS] as $t) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $t . '`');
        }
    }

    /**
     * Seed one announcement. Every knob is independent so each
     * must-not-happen case turns exactly one.
     */
    private function seed(
        string $title,
        string $dateGmt = '2026-01-01 00:00:00',
        string $status = 'publish',
        int $author = self::OPERATOR,
        int $parent = self::PAGE_ID,
        string $postType = AnnouncementPostType::POST_TYPE,
        ?string $authorizedAt = '2026-01-01 00:00:00',
        ?string $authorizedUserId = '7',
        ?string $archivedAt = null,
        ?string $pinnedAt = null,
        ?string $commentsEnabled = '1',
        ?string $publishAt = null,
        ?string $modifiedGmt = null
    ): int {
        global $wpdb;

        self::assertNotFalse($wpdb->insert(self::POSTS, [
            'post_author'       => $author,
            'post_parent'       => $parent,
            'post_date_gmt'     => $dateGmt,
            'post_modified_gmt' => $modifiedGmt ?? $dateGmt,
            'post_title'        => $title,
            'post_excerpt'      => 'summary of ' . $title,
            'post_content'      => 'body of ' . $title,
            'post_status'       => $status,
            'post_type'         => $postType,
        ]), 'seeding ' . $title . ': ' . $wpdb->last_error);

        $id = (int) $wpdb->insert_id;

        foreach ([
            Vis::META_AUTHORIZED_AT                   => $authorizedAt,
            Vis::META_AUTHORIZED_USER_ID              => $authorizedUserId,
            Vis::META_ARCHIVED_AT                     => $archivedAt,
            Vis::META_COMMENTS_ENABLED                => $commentsEnabled,
            AnnouncementRepository::META_PINNED_AT    => $pinnedAt,
            AnnouncementRepository::META_PUBLISH_AT   => $publishAt,
        ] as $key => $value) {
            if ($value === null) {
                continue;
            }
            self::assertNotFalse(
                $wpdb->insert(self::META, ['post_id' => $id, 'meta_key' => $key, 'meta_value' => $value]),
                'seeding meta ' . $key . ': ' . $wpdb->last_error
            );
        }

        return $id;
    }

    /** @return list<string> titles, in the order returned */
    private function titles(array $page): array
    {
        return array_map(
            static fn (object $r): string => (string) $r->post_title,
            $page['rows'] ?? $page
        );
    }

    /** @return array{decision: string, reason: string|null, operator_user_id: int|null} */
    private static function operator(int $id = self::OPERATOR): array
    {
        return ['decision' => Authority::DECISION_OPERATOR, 'reason' => null, 'operator_user_id' => $id];
    }

    // ── 1. The three predicates, independently, on EVERY public read ─────

    public function testAFullyAuthorizedDueRowIsReturnedByEveryPublicRead(): void
    {
        $this->seed('properly published');

        self::assertSame(['properly published'], $this->titles($this->repo->listActive(self::PAGE_ID, 20, null)));
        self::assertSame(['properly published'], $this->titles($this->repo->rotator(self::PAGE_ID)));
    }

    /**
     * ⚠⚠⚠ MUST-NOT-HAPPEN #1. Status is `publish`, the date is long past,
     * and the ONLY thing missing is the authorization marker — both
     * "obvious" predicates are satisfied.
     */
    public function testARowWithNoAuthorizationMarkerIsInvisibleOnEveryPublicRead(): void
    {
        $this->seed('forced to publish', authorizedAt: null, authorizedUserId: null);
        $this->seed('properly published');

        self::assertSame(['properly published'], $this->titles($this->repo->listActive(self::PAGE_ID, 20, null)));
        self::assertSame(['properly published'], $this->titles($this->repo->rotator(self::PAGE_ID)));
        self::assertSame([], $this->titles($this->repo->listArchived(self::PAGE_ID, 20, null)));
    }

    public function testHalfAMarkerAndAMismatchedMarkerBothStayInvisible(): void
    {
        $this->seed('timestamp only', authorizedUserId: null);
        $this->seed('user id only', authorizedAt: null);
        $this->seed('marker for someone else', authorizedUserId: '9');
        $this->seed('marker in the future', authorizedAt: '2099-01-01 00:00:00');
        $this->seed('properly published');

        self::assertSame(['properly published'], $this->titles($this->repo->listActive(self::PAGE_ID, 20, null)));
    }

    public function testADraftOrNotYetDueRowIsInvisibleOnThePublicReads(): void
    {
        $this->seed('a draft', status: 'draft');
        $this->seed('scheduled', status: 'future', dateGmt: '2099-01-01 00:00:00');
        // publish + a future date: status says live, the clock does not.
        $this->seed('published but not due', dateGmt: '2099-01-01 00:00:00');
        $this->seed('properly published');

        self::assertSame(['properly published'], $this->titles($this->repo->listActive(self::PAGE_ID, 20, null)));
    }

    public function testRowsFromAnotherValidatorPageAreNotReturned(): void
    {
        $this->seed('ours');
        $this->seed('another page', parent: 9999);
        $this->seed('an ordinary post', postType: 'post');

        self::assertSame(['ours'], $this->titles($this->repo->listActive(self::PAGE_ID, 20, null)));
    }

    // ── 2. Ordering and archival (§4.32.6, §4.32.12) ─────────────────────

    public function testTheActiveListIsPinnedFirstThenNewestFirst(): void
    {
        $this->seed('oldest', dateGmt: '2026-01-01 00:00:00');
        $this->seed('newest', dateGmt: '2026-03-01 00:00:00');
        $this->seed('pinned but old', dateGmt: '2026-02-01 00:00:00', pinnedAt: '2026-05-01 00:00:00');

        self::assertSame(
            ['pinned but old', 'newest', 'oldest'],
            $this->titles($this->repo->listActive(self::PAGE_ID, 20, null))
        );
    }

    /** ⚠ An EMPTY pinned meta is not pinned — a cleared pin must not stick. */
    public function testAnEmptyPinnedMetaIsNotPinned(): void
    {
        $this->seed('cleared pin', dateGmt: '2026-01-01 00:00:00', pinnedAt: '');
        $this->seed('newest', dateGmt: '2026-03-01 00:00:00');

        self::assertSame(['newest', 'cleared pin'], $this->titles($this->repo->listActive(self::PAGE_ID, 20, null)));
    }

    /**
     * §4.32.6: archival is a public record, not a deletion. An archived
     * announcement must be absent from the active list AND present in the
     * archived list — the two must partition the public set exactly.
     */
    public function testTheArchivalFiltersPartitionThePublicSetExactly(): void
    {
        $this->seed('archived early', archivedAt: '2026-04-01 00:00:00');
        $this->seed('archived late', archivedAt: '2026-06-01 00:00:00');
        $this->seed('active one');
        $this->seed('active two', dateGmt: '2026-02-01 00:00:00');

        $active   = $this->titles($this->repo->listActive(self::PAGE_ID, 20, null));
        $archived = $this->titles($this->repo->listArchived(self::PAGE_ID, 20, null));

        self::assertSame(['active two', 'active one'], $active);
        // Newest archived_at first — a DIFFERENT column from the active list.
        self::assertSame(['archived late', 'archived early'], $archived);

        self::assertSame([], array_intersect($active, $archived));
        self::assertCount(4, array_merge($active, $archived));
    }

    public function testTheRotatorIsCappedAtFiveAndPinnedFirst(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->seed('ann ' . $i, dateGmt: sprintf('2026-01-%02d 00:00:00', $i));
        }
        $this->seed('pinned', dateGmt: '2026-01-01 00:00:00', pinnedAt: '2026-05-01 00:00:00');

        $rows = $this->repo->rotator(self::PAGE_ID);

        self::assertCount(AnnouncementRepository::ROTATOR_CAP, $rows);
        self::assertSame('pinned', (string) $rows[0]->post_title);
    }

    // ── 3. Keyset paging — MUST-NOT-HAPPEN #3 and #5 ─────────────────────

    /**
     * ⚠⚠ Walk every page and compare the UNION against the whole set. A
     * dedupe assertion would pass while a row was lost; only set equality
     * catches a skip.
     */
    public function testWalkingEveryPageReturnsEachRowExactlyOnce(): void
    {
        $expected = [];
        for ($i = 1; $i <= 11; $i++) {
            $title      = 'ann ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $expected[] = $title;
            $this->seed($title, dateGmt: sprintf('2026-01-%02d 00:00:00', $i));
        }

        $seen   = [];
        $cursor = null;
        $guard  = 0;

        do {
            $page = $this->repo->listActive(self::PAGE_ID, 3, $cursor);
            foreach ($this->titles($page) as $t) {
                $seen[] = $t;
            }

            $cursor = null;
            if ($page['has_more']) {
                $last   = $page['rows'][count($page['rows']) - 1];
                $cursor = Cursor::encode(
                    Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
                    '0|' . (string) $last->post_date_gmt,
                    (int) $last->ID
                );
            }
            self::assertLessThan(20, ++$guard, 'paging must terminate');
        } while ($cursor !== null);

        sort($expected);
        $sorted = $seen;
        sort($sorted);

        self::assertSame($expected, $sorted, 'every row exactly once — no skips, no repeats');
        self::assertCount(count($seen), array_unique($seen), 'and no duplicates');
    }

    /**
     * ⚠⚠ MUST-NOT-HAPPEN #3 in its sharpest form: pin a row BETWEEN page 1
     * and page 2. Pinning moves it to the front of an order the reader has
     * already passed, so a reader must not LOSE a row they had not seen.
     */
    public function testPinningBetweenPagesDoesNotSkipAnUnseenRow(): void
    {
        $ids = [];
        for ($i = 1; $i <= 6; $i++) {
            $ids[$i] = $this->seed('ann ' . $i, dateGmt: sprintf('2026-01-%02d 00:00:00', $i));
        }

        $first = $this->repo->listActive(self::PAGE_ID, 3, null);
        self::assertTrue($first['has_more']);
        $seen = $this->titles($first);

        // Pin a row the reader has NOT yet seen (ann 1 is oldest ⇒ last).
        global $wpdb;
        $wpdb->insert(self::META, [
            'post_id'    => $ids[1],
            'meta_key'   => AnnouncementRepository::META_PINNED_AT,
            'meta_value' => '2026-05-01 00:00:00',
        ]);

        $last   = $first['rows'][count($first['rows']) - 1];
        $cursor = Cursor::encode(
            Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            '0|' . (string) $last->post_date_gmt,
            (int) $last->ID
        );
        $second = $this->repo->listActive(self::PAGE_ID, 3, $cursor);

        $all = array_merge($seen, $this->titles($second));

        // 'ann 1' jumped to the front, so page 2 no longer contains it —
        // which is the documented cost of an operator-mutable order. What
        // must NOT happen is losing a row that was never pinned.
        foreach (['ann 2', 'ann 3', 'ann 4', 'ann 5', 'ann 6'] as $unmoved) {
            self::assertContains($unmoved, $all, $unmoved . ' must not be skipped');
        }
    }

    /** `limit + 1` over-fetch: has_more must be exact at the boundary. */
    public function testHasMoreIsExactAtThePageBoundary(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->seed('ann ' . $i, dateGmt: sprintf('2026-01-%02d 00:00:00', $i));
        }

        $exact = $this->repo->listActive(self::PAGE_ID, 3, null);
        self::assertCount(3, $exact['rows']);
        self::assertFalse($exact['has_more'], 'an exact-boundary page must NOT claim more');

        $short = $this->repo->listActive(self::PAGE_ID, 2, null);
        self::assertCount(2, $short['rows']);
        self::assertTrue($short['has_more']);
    }

    public function testTheLimitIsClampedToTheContractMaximum(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->seed('ann ' . $i, dateGmt: '2026-01-01 00:00:00');
        }

        self::assertCount(AnnouncementRepository::LIMIT_MAX, $this->repo->listActive(self::PAGE_ID, 999, null)['rows']);
        self::assertCount(
            AnnouncementRepository::LIMIT_DEFAULT,
            $this->repo->listActive(self::PAGE_ID, 0, null)['rows'],
            'a non-positive limit takes the default, not everything'
        );
    }

    // ── 4. The owner read — MUST-NOT-HAPPEN #4 ───────────────────────────

    public function testTheOwnerListReturnsDraftsAndScheduledRowsForTheOperator(): void
    {
        $this->seed('a draft', status: 'draft', authorizedAt: null, authorizedUserId: null);
        $this->seed(
            'scheduled',
            status: 'future',
            dateGmt: '2099-01-01 00:00:00',
            authorizedAt: null,
            authorizedUserId: null,
            publishAt: '2099-01-01 00:00:00'
        );
        $this->seed('published');

        $drafts = $this->repo->listForOwner(
            self::PAGE_ID,
            self::operator(),
            [Caps::STATUS_DRAFT],
            20,
            null
        );
        self::assertSame(['a draft'], $this->titles($drafts));

        $both = $this->repo->listForOwner(
            self::PAGE_ID,
            self::operator(),
            [Caps::STATUS_DRAFT, Caps::STATUS_SCHEDULED],
            20,
            null
        );
        self::assertCount(2, $both['rows']);
    }

    /** ⚠ MUST-NOT-HAPPEN #4 — every non-operator shape returns empty. */
    public function testTheOwnerListIsEmptyForEveryNonOperatorDecision(): void
    {
        $this->seed('a draft', status: 'draft', authorizedAt: null, authorizedUserId: null);

        $denials = [
            'denied'            => ['decision' => Authority::DECISION_DENIED, 'reason' => Authority::REASON_NOT_CLAIMER, 'operator_user_id' => null],
            'ambiguous'         => ['decision' => Authority::DECISION_DENIED, 'reason' => Authority::REASON_CLAIM_AMBIGUOUS, 'operator_user_id' => null],
            'empty'             => [],
            'bool decision'     => ['decision' => true, 'operator_user_id' => self::OPERATOR],
            'operator, no id'   => ['decision' => Authority::DECISION_OPERATOR, 'reason' => null, 'operator_user_id' => null],
            'operator, zero id' => ['decision' => Authority::DECISION_OPERATOR, 'reason' => null, 'operator_user_id' => 0],
        ];

        foreach ($denials as $label => $decision) {
            /** @phpstan-ignore-next-line deliberately hostile shapes */
            $page = $this->repo->listForOwner(self::PAGE_ID, $decision, [Caps::STATUS_DRAFT], 20, null);
            self::assertSame([], $this->titles($page), $label . ' must return nothing');
        }
    }

    /**
     * ⚠ Even a VALID operator decision must not read another operator's
     * drafts — the repository binds `post_author` to the resolved operator
     * as a consistency check on the row.
     */
    public function testAnOperatorCannotReadAnotherOperatorsDrafts(): void
    {
        $this->seed('mine', status: 'draft', author: self::OPERATOR, authorizedAt: null, authorizedUserId: null);
        $this->seed('theirs', status: 'draft', author: 99, authorizedAt: null, authorizedUserId: null);

        $page = $this->repo->listForOwner(self::PAGE_ID, self::operator(), [Caps::STATUS_DRAFT], 20, null);

        self::assertSame(['mine'], $this->titles($page));
    }

    /**
     * ⚠⚠ `blocked` is REFUSED today — reconciler arm C does not exist, so
     * nothing can distinguish it from an ordinary draft. Accepting it would
     * return ordinary drafts: a wrong answer that looks right.
     */
    public function testTheOwnerListRefusesBlockedAndPublishedStatuses(): void
    {
        $this->seed('a draft', status: 'draft', authorizedAt: null, authorizedUserId: null);
        $this->seed('published');

        foreach ([[Caps::STATUS_BLOCKED], [Caps::STATUS_PUBLISHED], ['nonsense'], []] as $statuses) {
            $page = $this->repo->listForOwner(self::PAGE_ID, self::operator(), $statuses, 20, null);
            self::assertSame([], $this->titles($page), 'unsupported status set must return nothing');
        }
    }

    public function testTheOwnerListOrdersByEffectiveDateNewestFirst(): void
    {
        $this->seed(
            'scheduled soon',
            status: 'future',
            dateGmt: '2099-01-01 00:00:00',
            authorizedAt: null,
            authorizedUserId: null,
            publishAt: '2026-02-01 00:00:00'
        );
        $this->seed(
            'draft modified later',
            status: 'draft',
            dateGmt: '2026-01-01 00:00:00',
            authorizedAt: null,
            authorizedUserId: null,
            modifiedGmt: '2026-03-01 00:00:00'
        );

        $page = $this->repo->listForOwner(
            self::PAGE_ID,
            self::operator(),
            [Caps::STATUS_DRAFT, Caps::STATUS_SCHEDULED],
            20,
            null
        );

        self::assertSame(['draft modified later', 'scheduled soon'], $this->titles($page));
    }

    // ── 5. Comment counts — MUST-NOT-HAPPEN #6 ───────────────────────────

    public function testCommentCountsAreGroupedAndOmitIdsWithNoComments(): void
    {
        global $wpdb;

        $a = $this->seed('has comments');
        $b = $this->seed('has none');

        foreach (['1', '1', '0'] as $approved) {
            $wpdb->insert(self::COMMENTS, [
                'comment_post_ID'  => $a,
                'comment_content'  => 'x',
                'comment_approved' => $approved,
                'comment_type'     => AnnouncementPostType::COMMENT_TYPE,
            ]);
        }
        // Wrong type, and spam — neither counts.
        $wpdb->insert(self::COMMENTS, [
            'comment_post_ID' => $a, 'comment_content' => 'x',
            'comment_approved' => '1', 'comment_type' => 'comment',
        ]);
        $wpdb->insert(self::COMMENTS, [
            'comment_post_ID' => $a, 'comment_content' => 'x',
            'comment_approved' => 'spam', 'comment_type' => AnnouncementPostType::COMMENT_TYPE,
        ]);

        $counts = $this->repo->commentCountsFor([$a, $b]);

        self::assertSame(3, $counts[$a] ?? null, 'approved + pending (tombstone-bearing) only');
        self::assertArrayNotHasKey($b, $counts, 'an id with no comments is ABSENT, never a fabricated zero');
    }

    public function testCommentCountsHandleAnEmptyAndHostileIdList(): void
    {
        self::assertSame([], $this->repo->commentCountsFor([]));
        self::assertSame([], $this->repo->commentCountsFor([0, -1]));
    }

    // ── 6. Fail-closed — MUST-NOT-HAPPEN #2 ──────────────────────────────

    /**
     * ⚠⚠⚠ An errored read must THROW, never return `[]`. An empty
     * announcement bar looks completely normal, so a silent empty on error
     * would be invisible — a validator's official notices missing from
     * their own page with nothing to show why.
     *
     * Injected by dropping the posts table mid-test, which is the cheapest
     * real failure the engine will produce.
     */
    public function testAFailedReadThrowsRatherThanLookingLikeNoAnnouncements(): void
    {
        global $wpdb;

        $this->seed('present');
        self::assertCount(1, $this->repo->listActive(self::PAGE_ID, 20, null)['rows']);

        $wpdb->query('DROP TABLE `' . self::POSTS . '`');

        $threw = false;
        try {
            $this->repo->listActive(self::PAGE_ID, 20, null);
        } catch (RepositoryException) {
            $threw = true;
        }

        self::assertTrue($threw, 'a failed read must not present as an empty list');
    }

    public function testAFailedCommentCountAlsoThrows(): void
    {
        global $wpdb;

        $a = $this->seed('present');
        self::assertSame([], $this->repo->commentCountsFor([$a]));

        $wpdb->query('DROP TABLE `' . self::COMMENTS . '`');

        $threw = false;
        try {
            $this->repo->commentCountsFor([$a]);
        } catch (RepositoryException) {
            $threw = true;
        }

        self::assertTrue($threw);
    }

    // ── 7. Identifier validation ─────────────────────────────────────────

    /**
     * Table names reach SQL as identifiers and cannot be bound, so they are
     * validated. A caller that threaded a request value through the
     * constructor would otherwise have an injection point.
     */
    public function testANonIdentifierTableNameIsRefused(): void
    {
        foreach (['wp_posts; DROP TABLE x', 'wp posts', '', '1posts', 'wp_posts`'] as $bad) {
            try {
                new AnnouncementRepository($bad, self::META, self::COMMENTS);
                self::fail('table name [' . $bad . '] should have been refused');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
