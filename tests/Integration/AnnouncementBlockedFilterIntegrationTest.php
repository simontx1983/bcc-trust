<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Announcements\AnnouncementPostType;
use BCC\Trust\Core\Announcements\AnnouncementPublicationGuard;
use BCC\Trust\Core\Announcements\AnnouncementVisibility;
use BCC\Trust\Core\Repositories\AnnouncementRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The owner `?status=blocked` filter, against real SQL, on both engines.
 *
 * ── ⚠⚠ WHY THIS SUITE IS THE ONE THAT MATTERS ───────────────────────────────
 * `blocked` and `draft` share the SAME WordPress `post_status`: a blocked row
 * is a `draft` carrying reconciler arm C's marker. So the filter cannot be an
 * `IN` list over `post_status`, and the failure mode if it were is not an error
 * but a WRONG PAGE — `?status=blocked` would return every ordinary draft, which
 * looks exactly like a correct answer. That is precisely why the route refused
 * the parameter until arm C existed to stamp a marker.
 *
 * Every case below therefore asserts the DISTINCTION in both directions: the
 * blocked filter must not return ordinary drafts, and the draft filter must not
 * return blocked rows. Asserting only one direction would pass for a filter
 * that returns everything.
 *
 * ⚠ Injected tables (`wp_bcc_annblk_*`), never the shared `wp_posts` —
 * forced, because the harness's shared `wp_posts` has no `post_author` or
 * `post_date_gmt`, and dropping it once poisoned 57 unrelated cases.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(AnnouncementRepository::class)]
final class AnnouncementBlockedFilterIntegrationTest extends TestCase
{
    private const POSTS = 'wp_bcc_annblk_posts';
    private const META  = 'wp_bcc_annblk_meta';

    private const PAGE  = 7701;
    private const OWNER = 21;

    private AnnouncementRepository $repo;

    /** @var array{decision: string, reason: string|null, operator_user_id: int|null} */
    private array $decision;

    protected function setUp(): void
    {
        global $wpdb;

        foreach ([self::META, self::POSTS] as $t) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $t . '`');
        }

        // ⚠ post_date_gmt carries NO DEFAULT: WordPress ships
        // '0000-00-00 00:00:00', which MySQL strict mode rejects, so a default
        // would make the fixture create on MariaDB and fail on MySQL.
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
        // ⚠ asserted: $wpdb->query() returns false SILENTLY, so without this
        // every case would run against a missing table and pass vacuously.
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

        $this->repo     = new AnnouncementRepository(self::POSTS, self::META, null);
        $this->decision = [
            'decision'         => 'operator',
            'reason'           => null,
            'operator_user_id' => self::OWNER,
        ];
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ([self::META, self::POSTS] as $t) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $t . '`');
        }
    }

    /** @param array<string,string> $meta */
    private function seed(string $status, array $meta = [], string $date = '2026-10-01 00:00:00'): int
    {
        global $wpdb;

        self::assertNotFalse($wpdb->insert(self::POSTS, [
            'post_author'       => self::OWNER,
            'post_parent'       => self::PAGE,
            'post_date_gmt'     => $date,
            'post_modified_gmt' => $date,
            'post_title'        => 'seed ' . $status,
            'post_excerpt'      => '',
            'post_content'      => 'body',
            'post_status'       => $status,
            'post_type'         => AnnouncementPostType::POST_TYPE,
        ]), 'seed: ' . $wpdb->last_error);
        $id = (int) $wpdb->insert_id;

        foreach ($meta as $k => $v) {
            self::assertNotFalse($wpdb->insert(self::META, [
                'post_id'    => $id,
                'meta_key'   => $k,
                'meta_value' => $v,
            ]), 'meta: ' . $wpdb->last_error);
        }

        return $id;
    }

    /** @return list<int> */
    private function ownerIds(array $statuses): array
    {
        $page = $this->repo->listForOwner(self::PAGE, $this->decision, $statuses, 50, null);
        $out  = [];
        foreach ($page['rows'] ?? [] as $row) {
            $out[] = (int) $row->ID;
        }
        sort($out);

        return $out;
    }

    /**
     * ⚠⚠ THE DISTINCTION, BOTH DIRECTIONS. This is the whole suite in one case:
     * two rows with the SAME `post_status = 'draft'`, separated only by arm C's
     * marker.
     */
    public function testBlockedAndOrdinaryDraftsAreSeparatedDespiteSharingAPostStatus(): void
    {
        $ordinary = $this->seed('draft');
        $blocked  = $this->seed('draft', [
            AnnouncementPublicationGuard::META_BLOCKED_AT     => '2026-10-02 00:00:00',
            AnnouncementPublicationGuard::META_BLOCKED_REASON => AnnouncementPublicationGuard::REASON_AUTHORITY_LOST,
        ]);

        self::assertSame('draft', $this->statusOf($ordinary));
        self::assertSame('draft', $this->statusOf($blocked), 'both rows must share post_status, or the test proves nothing');

        self::assertSame([$ordinary], $this->ownerIds(['draft']), '?status=draft must EXCLUDE the blocked row');
        self::assertSame([$blocked], $this->ownerIds(['blocked']), '?status=blocked must return ONLY the blocked row');
    }

    /**
     * ⚠ The failure mode an `IN` list would produce: asking for `blocked` and
     * receiving every draft. Driven with several ordinary drafts so a filter
     * that ignores the marker cannot accidentally pass.
     */
    public function testAskingForBlockedNeverReturnsOrdinaryDrafts(): void
    {
        $this->seed('draft');
        $this->seed('draft');
        $this->seed('draft');
        $blocked = $this->seed('draft', [AnnouncementPublicationGuard::META_BLOCKED_AT => '2026-10-02 00:00:00']);

        self::assertSame([$blocked], $this->ownerIds(['blocked']));
        self::assertCount(3, $this->ownerIds(['draft']));
    }

    /** An empty blocked marker is NOT blocked — the empty-string trap. */
    public function testAnEmptyBlockedMarkerIsNotBlocked(): void
    {
        $empty = $this->seed('draft', [AnnouncementPublicationGuard::META_BLOCKED_AT => '']);

        self::assertSame([$empty], $this->ownerIds(['draft']), 'an empty marker leaves it an ordinary draft');
        self::assertSame([], $this->ownerIds(['blocked']));
    }

    /** A scheduled row is neither, and asking for blocked must not return it. */
    public function testAScheduledRowIsNeitherDraftNorBlocked(): void
    {
        $scheduled = $this->seed('future');
        $blocked   = $this->seed('draft', [AnnouncementPublicationGuard::META_BLOCKED_AT => '2026-10-02 00:00:00']);

        self::assertSame([$scheduled], $this->ownerIds(['scheduled']));
        self::assertSame([$blocked], $this->ownerIds(['blocked']));
        self::assertSame([], $this->ownerIds(['draft']));
    }

    /** Asking for several states returns the union, with no double-counting. */
    public function testAskingForAllThreeReturnsTheUnionExactlyOnce(): void
    {
        $draft     = $this->seed('draft');
        $scheduled = $this->seed('future');
        $blocked   = $this->seed('draft', [AnnouncementPublicationGuard::META_BLOCKED_AT => '2026-10-02 00:00:00']);

        $all = $this->ownerIds(['draft', 'scheduled', 'blocked']);
        sort($all);
        $expected = [$draft, $scheduled, $blocked];
        sort($expected);

        self::assertSame($expected, $all);
        // ⚠ The per-status clauses are OR'd, so an overlapping pair would
        // return a row twice. Asserting the COUNT is what catches that.
        self::assertCount(3, $all, 'the OR of per-status clauses must not double-count a row');
    }

    /**
     * ⚠ A PUBLISHED row is never returned by the owner list, whatever is asked
     * for — it is served by the public list. A blocked marker on a published
     * row (which arm C would never leave) must not smuggle it in either.
     */
    public function testAPublishedRowIsNeverReturnedByTheOwnerList(): void
    {
        $this->seed('publish', [
            AnnouncementVisibility::META_AUTHORIZED_AT      => '2026-10-01 00:00:00',
            AnnouncementVisibility::META_AUTHORIZED_USER_ID => (string) self::OWNER,
        ]);
        $this->seed('publish', [AnnouncementPublicationGuard::META_BLOCKED_AT => '2026-10-02 00:00:00']);

        self::assertSame([], $this->ownerIds(['draft']));
        self::assertSame([], $this->ownerIds(['blocked']));
        self::assertSame([], $this->ownerIds(['draft', 'scheduled', 'blocked']));
    }

    /** An unknown status is dropped rather than widening the result. */
    public function testAnUnknownStatusIsDroppedNotHonoured(): void
    {
        $draft = $this->seed('draft');
        $this->seed('draft', [AnnouncementPublicationGuard::META_BLOCKED_AT => '2026-10-02 00:00:00']);

        // `published` is not an owner status; the request collapses to `draft`.
        self::assertSame([$draft], $this->ownerIds(['draft', 'published']));
    }

    private function statusOf(int $id): string
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT post_status FROM `' . self::POSTS . '` WHERE ID = %d',
            $id
        ));
    }
}
