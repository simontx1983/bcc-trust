<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories\Tests;

use BCC\Trust\Core\Repositories\PostEngagementRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-346 — `viewerReposts()`: the member's own published reposts of a root.
 *
 * This is the read the once-only rule and the un-repost both stand on, so
 * three of its properties are load-bearing and each has its own case here:
 *
 *   1. it is keyed on `act_repost_id` AND `act_owner_id` — the same column
 *      `repostCount()` counts DISTINCT, so "already reposted" and "counted
 *      as a reposter" cannot answer differently for one row;
 *   2. it is PUBLISHED-ONLY, or un-repost would be a one-way door: a
 *      trashed repost would keep blocking a fresh one;
 *   3. `[]` and `null` are different answers — "has not reposted" versus
 *      "I could not find out". The endpoint turns the second into a 503
 *      rather than a write, and collapsing them here is exactly what would
 *      produce the duplicate.
 *
 * A real $wpdb is replaced with a recorder, the same way
 * {@see PostEngagementRepostCountSqlTest} does, so the prepared template
 * can be read back without a database.
 */
#[CoversClass(PostEngagementRepository::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PostEngagementViewerRepostsSqlTest extends TestCase
{
    private const ROOT  = 9091;
    private const OWNER = 42;

    protected function setUp(): void
    {
        // The isolated child inherits nothing, so it brings its own object
        // cache and WP constants rather than relying on another test file
        // having declared them — which would make this suite order-dependent.
        require_once dirname(__DIR__) . '/Stubs/repost-count-stubs.php';

        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';
            public string $posts  = 'wp_posts';
            /** @var list<string> */
            public array $prepared = [];
            /** @var list<array<string, mixed>> what the SELECT answers with */
            public array $rows = [];
            public bool $queryFails = false;
            public string $errorAfterQuery = '';
            public string $last_error = '';
            /** @var mixed */
            public $last_result = [];

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                // Substitute so the BOUND VALUES are observable too — the
                // owner and root predicates are half of what this test is
                // about, and a template alone would not show them.
                foreach ($args as $arg) {
                    $sql = preg_replace('/%[ds]/', (string) $arg, $sql, 1) ?? $sql;
                }
                $this->prepared[] = $sql;

                return $sql;
            }

            /** @param string $sql @return mixed */
            public function get_var($sql)
            {
                // TableRegistry::exists() probes with SHOW TABLES.
                if (str_contains((string) $sql, 'SHOW TABLES LIKE')) {
                    return 'wp_peepso_activities';
                }

                return null;
            }

            /**
             * ⚠ The read goes through `query()` + `last_result`, NOT
             * `get_results()`. That is the F-230 posture: a method that
             * cannot tell an error from an empty result is the wrong tool,
             * so the repository clears `last_error`, runs `query()`, and
             * reads the rows off `last_result`.
             *
             * @param string $sql
             * @return int|false
             */
            public function query($sql)
            {
                if ($this->queryFails) {
                    return false;
                }
                if ($this->errorAfterQuery !== '') {
                    $this->last_error = $this->errorAfterQuery;
                }
                $this->last_result = array_map(
                    static fn(array $row): object => (object) $row,
                    $this->rows
                );

                return count($this->rows);
            }
        };
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb = null;
    }

    private static function sql(): string
    {
        global $wpdb;
        foreach ($wpdb->prepared as $prepared) {
            if (str_contains($prepared, 'act_repost_id')) {
                return (string) preg_replace('/\s+/', ' ', trim($prepared));
            }
        }

        return '';
    }

    /** @param list<array<string, mixed>> $rows */
    private static function withRows(array $rows): void
    {
        global $wpdb;
        $wpdb->rows = $rows;
    }

    // ── the predicates ───────────────────────────────────────────────

    /** ⭐ Keyed on the ROOT and on the OWNER, and published only. */
    public function testTheQueryIsKeyedOnTheRootTheOwnerAndPublishedOnly(): void
    {
        PostEngagementRepository::viewerReposts(self::ROOT, self::OWNER);

        $sql = self::sql();
        self::assertNotSame('', $sql, 'no repost query was prepared');
        self::assertStringContainsString('a.act_repost_id = ' . self::ROOT, $sql);
        self::assertStringContainsString('a.act_owner_id = ' . self::OWNER, $sql);
        self::assertStringContainsString("p.post_status = 'publish'", $sql);
        self::assertStringContainsString('INNER JOIN wp_posts p ON p.ID = a.act_external_id', $sql);
    }

    /**
     * ⚠ KEYED ON `act_owner_id`, THE SAME COLUMN `repostCount()` COUNTS
     * DISTINCT. Pinned explicitly because the plausible-looking alternative
     * (`p.post_author`) would make the two reads disagree about who has
     * reposted, and nothing else in the suite would notice.
     */
    /**
     * ⭐ PLAIN reposts only (the X model, user decision 2026-11-18).
     *
     * This read answers TWO questions and both are plain-only: "has this
     * member already reposted" (the once-only rule, which caps plain
     * reposts and not quotes) and "which posts does DELETE trash". So a
     * member's QUOTE of the same root must not appear here — otherwise
     * their quote would block a plain repost they are entitled to, and
     * `DELETE /repost` would trash the quote along with it.
     *
     * The discriminator is PeepSo's own: `add_post()` refuses empty content
     * unless a repost is set, so empty content is reachable only as a plain
     * repost.
     */
    public function testTheReadIsPlainRepostsOnlyAndNeverAQuote(): void
    {
        PostEngagementRepository::viewerReposts(700, 42);

        $sql = self::sql();
        self::assertStringContainsString("p.post_content = ''", $sql);
        self::assertStringNotContainsString("p.post_content <> ''", $sql);
    }

    public function testTheOwnerPredicateIsNotOnThePostAuthor(): void
    {
        PostEngagementRepository::viewerReposts(self::ROOT, self::OWNER);

        self::assertStringNotContainsString('post_author', self::sql());
    }

    /** ⚠ §2 bounded — never an unbounded delete set. */
    public function testTheQueryIsBounded(): void
    {
        PostEngagementRepository::viewerReposts(self::ROOT, self::OWNER);

        self::assertStringContainsString('LIMIT', self::sql());
        self::assertStringContainsString('ORDER BY a.act_id ASC', self::sql());
    }

    // ── the three answers ────────────────────────────────────────────

    /** ⭐ Zero rows is `[]` — "has not reposted" — and never null. */
    public function testNoRowsIsAnEmptyListAndNotNull(): void
    {
        self::withRows([]);

        self::assertSame([], PostEngagementRepository::viewerReposts(self::ROOT, self::OWNER));
    }

    public function testRowsComeBackAsActIdAndPostIdPairs(): void
    {
        self::withRows([
            ['act_id' => '11', 'act_external_id' => '1100'],
            ['act_id' => 22, 'act_external_id' => 2200],
        ]);

        self::assertSame(
            [
                ['act_id' => 11, 'post_id' => 1100],
                ['act_id' => 22, 'post_id' => 2200],
            ],
            PostEngagementRepository::viewerReposts(self::ROOT, self::OWNER)
        );
    }

    /**
     * ⚠⚠ A FAILED READ IS NULL, NEVER `[]`. `[]` would read as "has not
     * reposted" and let the endpoint write a duplicate — the one outcome
     * the lock and the check exist to prevent.
     */
    public function testAFailedQueryIsNullAndNotAnEmptyList(): void
    {
        global $wpdb;
        $wpdb->queryFails = true;

        self::assertNull(PostEngagementRepository::viewerReposts(self::ROOT, self::OWNER));
    }

    /**
     * ⚠ And the other half of F-230: `query()` can return truthy while
     * `last_error` is set, which is why the repository clears the error
     * first and checks it afterwards instead of trusting the return value.
     */
    public function testATruthyQueryWithAnErrorIsAlsoNull(): void
    {
        global $wpdb;
        self::withRows([['act_id' => 11, 'act_external_id' => 1100]]);
        $wpdb->errorAfterQuery = 'MySQL server has gone away';

        self::assertNull(PostEngagementRepository::viewerReposts(self::ROOT, self::OWNER));
    }

    /** A non-positive id or owner is "unknown", never a confident `[]`. */
    public function testNonPositiveArgumentsAreUnknown(): void
    {
        self::assertNull(PostEngagementRepository::viewerReposts(0, self::OWNER));
        self::assertNull(PostEngagementRepository::viewerReposts(-1, self::OWNER));
        self::assertNull(PostEngagementRepository::viewerReposts(self::ROOT, 0));
        self::assertNull(PostEngagementRepository::viewerReposts(self::ROOT, -1));
    }

    /**
     * A row missing either id is dropped rather than returned as 0 — a
     * `post_id` of 0 handed to `wp_trash_post()` is not a no-op.
     */
    public function testRowsWithoutBothIdsAreDropped(): void
    {
        self::withRows([
            ['act_id' => 11, 'act_external_id' => 0],
            ['act_id' => 0,  'act_external_id' => 2200],
            ['act_id' => 33, 'act_external_id' => 3300],
        ]);

        self::assertSame(
            [['act_id' => 33, 'post_id' => 3300]],
            PostEngagementRepository::viewerReposts(self::ROOT, self::OWNER)
        );
    }

    // ── the sibling read must not have drifted ───────────────────────

    /**
     * ⚠ `readCount()` was refactored to delegate to the same `readRows()`
     * this method uses, so the repost COUNT must still behave. Its own
     * suite covers it in full; this is the seam check that the extraction
     * did not change the answer.
     */
    public function testTheExtractedReadStillServesTheCount(): void
    {
        self::withRows([['n' => '5']]);

        self::assertSame(5, PostEngagementRepository::repostCount(self::ROOT));
    }

    public function testTheExtractedReadStillFailsClosedForTheCount(): void
    {
        global $wpdb;
        $wpdb->queryFails = true;

        self::assertNull(PostEngagementRepository::repostCount(self::ROOT));
    }
}
