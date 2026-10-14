<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Announcements\AnnouncementPostType;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Repositories\AnnouncementRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `AnnouncementRepository::pinnedIdsFor()` against real SQL, on both engines.
 *
 * ── WHY THIS SUITE EXISTS ───────────────────────────────────────────────────
 * The A3 write layer uses this read to hold §4.32.12's at-most-one-pin
 * invariant: before pinning, it clears every other pin on the page. The live
 * journey probe exercises that end to end, but the probe is hand-run and
 * CI-less, so the SQL itself had no coverage on either engine. This closes
 * that — and the SQL is the part most likely to differ between MySQL and
 * MariaDB, which is why both group tags are present.
 *
 * ⚠ It injects its OWN tables (`wp_bcc_annpin_*`) rather than touching the
 * shared `wp_posts`/`wp_postmeta`. That is forced, not tidiness: the
 * integration harness's shared `wp_posts` has no `post_author` and no
 * `post_date_gmt`, and dropping it is what poisoned 57 unrelated cases once
 * already.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(AnnouncementRepository::class)]
final class AnnouncementPinnedIdsIntegrationTest extends TestCase
{
    private const POSTS = 'wp_bcc_annpin_posts';
    private const META  = 'wp_bcc_annpin_meta';

    private const PAGE  = 9101;
    private const OTHER = 9102;
    private const OWNER = 11;

    private AnnouncementRepository $repo;

    protected function setUp(): void
    {
        global $wpdb;

        foreach ([self::META, self::POSTS] as $t) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $t . '`');
        }

        // ⚠ post_date_gmt has NO DEFAULT on purpose: WordPress ships
        // '0000-00-00 00:00:00', which MySQL strict mode REJECTS, so a default
        // would make the fixture create on MariaDB and fail on MySQL. Every
        // seed supplies the value.
        $posts = $wpdb->query(
            'CREATE TABLE `' . self::POSTS . '` (
                ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_author BIGINT UNSIGNED NOT NULL DEFAULT 0,
                post_parent BIGINT UNSIGNED NOT NULL DEFAULT 0,
                post_date_gmt DATETIME NOT NULL,
                post_title TEXT NOT NULL,
                post_content LONGTEXT NOT NULL,
                post_status VARCHAR(20) NOT NULL DEFAULT \'publish\',
                post_type VARCHAR(20) NOT NULL DEFAULT \'post\',
                PRIMARY KEY (ID),
                KEY type_parent (post_type, post_parent, post_status)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        // ⚠ $wpdb->query() returns false SILENTLY, so without this assertion
        // every case below would run against a missing table and pass
        // vacuously — which is the exact shape of a defect this project has
        // already shipped once.
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

        $this->repo = new AnnouncementRepository(self::POSTS, self::META, null);
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ([self::META, self::POSTS] as $t) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $t . '`');
        }
    }

    private function seed(int $page, string $status = 'publish', bool $pinned = false, string $type = AnnouncementPostType::POST_TYPE): int
    {
        global $wpdb;

        $ok = $wpdb->insert(self::POSTS, [
            'post_author'   => self::OWNER,
            'post_parent'   => $page,
            'post_date_gmt' => '2026-10-01 00:00:00',
            'post_title'    => 'seed',
            'post_content'  => 'body',
            'post_status'   => $status,
            'post_type'     => $type,
        ]);
        self::assertNotFalse($ok, 'seed insert: ' . $wpdb->last_error);
        $id = (int) $wpdb->insert_id;

        if ($pinned) {
            self::assertNotFalse($wpdb->insert(self::META, [
                'post_id'    => $id,
                'meta_key'   => AnnouncementRepository::META_PINNED_AT,
                'meta_value' => '2026-10-02 00:00:00',
            ]), 'pin insert: ' . $wpdb->last_error);
        }

        return $id;
    }

    public function testNoPinsOnAPageReturnsAnEmptyList(): void
    {
        $this->seed(self::PAGE);
        $this->seed(self::PAGE);

        self::assertSame([], $this->repo->pinnedIdsFor(self::PAGE));
    }

    public function testTheSinglePinnedRowIsReturned(): void
    {
        $this->seed(self::PAGE);
        $pinned = $this->seed(self::PAGE, 'publish', true);

        self::assertSame([$pinned], $this->repo->pinnedIdsFor(self::PAGE));
    }

    /**
     * ⚠⚠ THE CASE THE WRITER DEPENDS ON. A broken install can hold two pins,
     * and the writer clears every id this returns before setting its own. If
     * this returned only one, the writer would leave the page with two pins
     * and §4.32.12's ordering guarantee would be silently gone.
     */
    public function testEveryPinIsReturnedWhenAnInstallIsAlreadyBroken(): void
    {
        $a = $this->seed(self::PAGE, 'publish', true);
        $b = $this->seed(self::PAGE, 'publish', true);
        $c = $this->seed(self::PAGE, 'publish', true);

        $got = $this->repo->pinnedIdsFor(self::PAGE);
        sort($got);

        self::assertSame([$a, $b, $c], $got, 'all three pins must be visible to the writer');
    }

    /** ⚠ No cross-page leak: a pin on another page is not this page's problem. */
    public function testAPinOnAnotherPageIsNotReturned(): void
    {
        $mine = $this->seed(self::PAGE, 'publish', true);
        $this->seed(self::OTHER, 'publish', true);

        self::assertSame([$mine], $this->repo->pinnedIdsFor(self::PAGE));
    }

    /**
     * ⚠ Type-scoped. An ordinary post carrying the same meta key on the same
     * parent must not be returned — otherwise the writer would delete meta off
     * a row it does not own.
     */
    public function testAnOrdinaryPostWithTheSameMetaKeyIsNotReturned(): void
    {
        $ann = $this->seed(self::PAGE, 'publish', true);
        $this->seed(self::PAGE, 'publish', true, 'post');

        self::assertSame([$ann], $this->repo->pinnedIdsFor(self::PAGE));
    }

    /**
     * Status-agnostic by design: the writer refuses to pin a non-published row
     * itself, but a pin that already exists on a draft still has to be
     * CLEARABLE, so this read must see it.
     */
    public function testAPinnedDraftIsStillVisibleSoItCanBeCleared(): void
    {
        $draft = $this->seed(self::PAGE, 'draft', true);

        self::assertSame([$draft], $this->repo->pinnedIdsFor(self::PAGE));
    }

    public function testANonPositivePageIdReturnsEmptyWithoutQuerying(): void
    {
        $this->seed(self::PAGE, 'publish', true);

        self::assertSame([], $this->repo->pinnedIdsFor(0));
        self::assertSame([], $this->repo->pinnedIdsFor(-1));
    }

    /** The returned value is a re-indexed list, not a keyed array. */
    public function testTheReturnIsAList(): void
    {
        $this->seed(self::PAGE);
        $this->seed(self::PAGE, 'publish', true);
        $this->seed(self::PAGE);
        $this->seed(self::PAGE, 'publish', true);

        $got = $this->repo->pinnedIdsFor(self::PAGE);

        self::assertSame(array_keys($got), range(0, count($got) - 1), 'keys must be 0..n-1');
        self::assertContainsOnlyInt($got);
    }

    /**
     * ⚠⚠ FAIL CLOSED. A read failure must THROW, never come back as []. An
     * empty list means "nothing is pinned", and the writer acting on that would
     * add a second pin — so a swallowed error would break the invariant this
     * read exists to protect. Driven by dropping the meta table out from under
     * the query, which is a real failure rather than a mocked one.
     */
    public function testAReadFailureThrowsRatherThanReportingNoPins(): void
    {
        global $wpdb;

        $this->seed(self::PAGE, 'publish', true);
        $wpdb->query('DROP TABLE `' . self::META . '`');

        // ⚠ suppress_errors so the harness does not echo the SQL error; the
        // behaviour under test is the exception, not the output.
        $prior = $wpdb->suppress_errors(true);
        try {
            $this->expectException(RepositoryException::class);
            $this->repo->pinnedIdsFor(self::PAGE);
        } finally {
            $wpdb->suppress_errors($prior);
        }
    }
}
