<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories\Tests;

use BCC\Trust\Core\Repositories\PostEngagementRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-346 — `repostCount()` counts DISTINCT MEMBERS, not rows.
 *
 * ## Why this is asserted on the SQL
 *
 * Under correct behaviour `COUNT(*)` and `COUNT(DISTINCT act_owner_id)` return
 * the SAME number, because reposting is once per member per post. So no
 * black-box test over a well-behaved fixture can tell the two apart — which
 * is exactly the case the DISTINCT exists for. It is there for the row that
 * should not exist: nothing in the DATABASE can enforce once-per-member, so a
 * duplicate that slipped past the app-level check would silently inflate a
 * public number.
 *
 * Why no constraint is possible, so nobody "fixes" this with a unique key:
 * PeepSo's activities table has `act_owner_index(act_owner_id)` and
 * `act_repost_index(act_repost_id)` as two separate NON-unique single-column
 * indexes, and `UNIQUE (act_owner_id, act_repost_id)` is incompatible with
 * the schema anyway — `act_repost_id` defaults to 0 on every ordinary post,
 * so uniqueness would cap each member at one ordinary post ever.
 *
 * A real $wpdb is replaced with a recorder, the same way
 * {@see TrendingPagesPrivacySqlTest} does, so the prepared template can be
 * read back without a database.
 */
#[CoversClass(PostEngagementRepository::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PostEngagementRepostCountSqlTest extends TestCase
{
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
            /** @var list<string> */
            public array $queries = [];
            /** @var int|string|null what the COUNT query answers with */
            public $countAnswer = '3';
            public bool $queryFails = false;
            public string $errorAfterQuery = '';
            public string $last_error = '';
            /** @var mixed */
            public $last_result = [];

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                $this->prepared[] = $sql;

                return $sql;
            }

            /** @param string $sql @return mixed */
            public function get_var($sql)
            {
                // TableRegistry::exists() probes with SHOW TABLES and
                // compares the result to the table name, so answer that
                // branch with the name it is looking for.
                if (str_contains((string) $sql, 'SHOW TABLES LIKE')) {
                    return 'wp_peepso_activities';
                }

                return $this->countAnswer;
            }

            /**
             * ⚠ The COUNT goes through `query()` + `last_result`, NOT
             * `get_var()`. That is the F-230 posture: `get_var()` cannot tell
             * an error from a legitimately empty result, so the repository
             * clears `last_error`, runs `query()`, and reads the row off
             * `last_result` — and a double that only implemented `get_var`
             * would never exercise the real path.
             *
             * @param string $sql
             * @return int|false
             */
            public function query($sql)
            {
                $this->queries[] = (string) $sql;
                if ($this->queryFails) {
                    return false;
                }
                if ($this->errorAfterQuery !== '') {
                    $this->last_error = $this->errorAfterQuery;
                }
                $this->last_result = [(object) ['n' => $this->countAnswer]];

                return 1;
            }
        };
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb = null;
    }

    private static function countSql(): string
    {
        global $wpdb;
        foreach ($wpdb->prepared as $sql) {
            if (str_contains($sql, 'COUNT')) {
                return (string) preg_replace('/\s+/', ' ', trim($sql));
            }
        }

        return '';
    }

    /** ⭐ THE CHANGE. */
    public function testTheCountIsOverDistinctOwnersAndNotRows(): void
    {
        PostEngagementRepository::repostCount(9091);

        $sql = self::countSql();
        self::assertNotSame('', $sql, 'no COUNT query was prepared');
        self::assertStringContainsString('COUNT(DISTINCT a.act_owner_id)', $sql);
        self::assertStringNotContainsString('COUNT(*)', $sql);
    }

    /**
     * ⚠ The surrounding query must not have drifted while the aggregate
     * changed. The published-only filter is what makes a deleted repost stop
     * counting, and the act_repost_id predicate is what makes this a count of
     * reposts rather than of posts.
     */
    public function testTheRestOfTheQueryIsUnchanged(): void
    {
        PostEngagementRepository::repostCount(9091);

        $sql = self::countSql();
        self::assertStringContainsString('a.act_repost_id = %d', $sql);
        self::assertStringContainsString("p.post_status = 'publish'", $sql);
        self::assertStringContainsString('INNER JOIN wp_posts p ON p.ID = a.act_external_id', $sql);
    }

    // ── the X model: plain is capped, quotes are not ────────────────

    /**
     * ⭐ `repost_count` is PLAIN reposts only (user decision 2026-11-18:
     * one plain repost per member per root, unlimited quotes).
     *
     * The discriminator is `post_content`, and it is PeepSo's own rule
     * rather than a convention invented here: `PeepSoActivity::add_post()`
     * REFUSES empty content unless a repost is set, and stores the content
     * already trimmed. So among rows carrying `act_repost_id`, empty content
     * is reachable ONLY as a plain repost.
     */
    public function testTheRepostCountExcludesQuotes(): void
    {
        PostEngagementRepository::repostCount(9091);

        $sql = self::countSql();
        self::assertStringContainsString("p.post_content = ''", $sql, 'plain reposts only');
        self::assertStringNotContainsString("p.post_content <> ''", $sql);
    }

    /**
     * And the quote count is its mirror: a RAW total, not distinct members,
     * because a member may quote the same post as often as they like and
     * each quote is its own post.
     */
    public function testTheQuoteCountCountsQuoteRowsNotDistinctMembers(): void
    {
        PostEngagementRepository::quoteCount(9091);

        $sql = self::countSql();
        self::assertNotSame('', $sql, 'no COUNT query was prepared');
        self::assertStringContainsString("p.post_content <> ''", $sql, 'quotes only');
        self::assertStringContainsString('COUNT(*)', $sql);
        self::assertStringNotContainsString('COUNT(DISTINCT', $sql, 'quotes are not deduplicated by member');
        // The shared predicates must not have drifted either.
        self::assertStringContainsString('a.act_repost_id = %d', $sql);
        self::assertStringContainsString("p.post_status = 'publish'", $sql);
    }

    /**
     * ⚠ The two predicates must be EXCLUSIVE. If both read `= ''` or both
     * `<> ''`, one of the two numbers is silently the other, and no single
     * query's assertions above would notice.
     */
    public function testThePlainAndQuotePredicatesAreOpposites(): void
    {
        global $wpdb;

        PostEngagementRepository::repostCount(9091);
        $plainSql = self::countSql();

        // ⚠ countSql() returns the FIRST captured COUNT query, so the
        // capture has to be cleared between the two reads — without this
        // both variables hold the plain query and the comparison below
        // passes for the wrong reason.
        $wpdb->prepared = [];

        PostEngagementRepository::quoteCount(9091);
        $quoteSql = self::countSql();

        self::assertNotSame($plainSql, $quoteSql, 'the two counts must not run the same query');
        self::assertStringContainsString("p.post_content = ''", $plainSql);
        self::assertStringContainsString("p.post_content <> ''", $quoteSql);
    }

    public function testANonPositiveIdIsUnknownForTheQuoteCountToo(): void
    {
        self::assertNull(PostEngagementRepository::quoteCount(0));
        self::assertNull(PostEngagementRepository::quoteCount(-1));
    }

    /** A read still returns the number it was given. */
    public function testTheCountIsReturned(): void
    {
        self::assertSame(3, PostEngagementRepository::repostCount(9091));
    }

    /**
     * ⚠ FAIL-CLOSED, unchanged by this edit: a non-positive id is "unknown",
     * never a confident zero. The whole repository is built on that posture
     * (F-197 / F-216 / F-230) and an aggregate swap is exactly the kind of
     * edit that can quietly turn a null into a 0.
     */
    public function testANonPositiveIdIsUnknownAndNotZero(): void
    {
        self::assertNull(PostEngagementRepository::repostCount(0));
        self::assertNull(PostEngagementRepository::repostCount(-1));
    }

    /**
     * ⚠ A FAILED QUERY IS NULL, NEVER 0. This is the half of the F-230
     * posture that an aggregate swap could quietly break: "nobody reposted
     * this" and "I could not find out" must not render the same.
     */
    public function testAFailedQueryIsUnknownAndNotZero(): void
    {
        global $wpdb;
        $wpdb->queryFails = true;

        self::assertNull(PostEngagementRepository::repostCount(9091));
    }

    /**
     * ⚠ And the OTHER half: `query()` can return truthy while `last_error`
     * is set, which is why the repository clears the error first and checks
     * it afterwards instead of trusting the return value alone.
     */
    public function testATruthyQueryWithAnErrorIsAlsoUnknown(): void
    {
        global $wpdb;
        // Set by the stub's query() AFTER the repository clears it, which is
        // the real sequence: clear, run, then look.
        $wpdb->errorAfterQuery = 'MySQL server has gone away';

        self::assertNull(PostEngagementRepository::repostCount(9091));
    }
}
