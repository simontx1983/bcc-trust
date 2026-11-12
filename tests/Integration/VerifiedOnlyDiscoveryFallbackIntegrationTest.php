<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\PageDiscoveryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * "Verified only" discovery answered "there are no verified pages" — always.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 * The `$verified_only` branch of {@see PageDiscoveryRepository::queryFromPostsTable()}
 * appended two joins in the wrong order:
 *
 *     INNER JOIN …user_info ui ON ui.user_id = COALESCE(pm_owner.meta_value, p.post_author)
 *     LEFT  JOIN wp_postmeta pm_owner ON …
 *
 * SQL only lets a join's ON clause reference aliases introduced BEFORE it,
 * so the server rejected the statement with "Unknown column
 * 'pm_owner.meta_value' in 'on clause'". Not some rows — the whole query,
 * every time, for every caller.
 *
 * ⚠⚠ AND IT PRESENTED AS EMPTINESS, which is why it survived. The COUNT is
 * read with `get_var()`; a failed read answers null; `(int) null` is 0; and
 * the `$total === 0` branch returns an empty page before the main query is
 * ever built. A total outage of one filter was indistinguishable from an
 * honest "no page matches", with nothing thrown and nothing logged.
 *
 * ── WHY THESE ARE INTEGRATION TESTS ─────────────────────────────────────
 * The bug is in SQL, and only a real server has an opinion about it. A
 * `$wpdb` double returns whatever the test hands it, so it would answer
 * identically before and after the fix — asserting the fixture instead of
 * the behaviour. For the same reason the suite does not merely check that
 * the corrected query works: {@see testTheOldJoinOrderIsRejectedByTheServer}
 * runs the ORIGINAL join order against the same server and pins the error,
 * because a test that passes on both versions of the code proves nothing
 * about either.
 *
 * ── THE SECOND HALF ─────────────────────────────────────────────────────
 * The join fix makes this query work; the read-failure checks make the NEXT
 * one visible. Both are exercised against real failures injected by query
 * text through the harness's `failQueriesMatching`, which lets the COUNT
 * fail while the main read would have succeeded, and vice versa — the
 * property that shows the verdict is per-read rather than blanket.
 *
 * ⚠ Faults are injected rather than created by renaming or dropping a
 * table, following AdminDashboardReadFailureIntegrationTest: a missing
 * table is re-created EMPTY by the next schema pass, after which the read
 * SUCCEEDS and returns a genuine 0, so the test would quietly stop
 * exercising the failure path it was written for.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(PageDiscoveryRepository::class)]
final class VerifiedOnlyDiscoveryFallbackIntegrationTest extends TestCase
{
    /** Verified, and the OWNER of PAGE_OWNED via `_bcc_page_owner`. */
    private const VERIFIED = 992001;

    /** Not verified, and the post_author of every fixture page. */
    private const PLAIN = 992002;

    /** Owned by VERIFIED through meta; authored by PLAIN. */
    private const PAGE_OWNED = 992101;

    /** No owner meta; authored by PLAIN. Must NOT appear. */
    private const PAGE_PLAIN = 992102;

    /** No owner meta; authored by VERIFIED. Must appear via the COALESCE. */
    private const PAGE_AUTHORED = 992103;

    protected function setUp(): void
    {
        $this->widenPostsTable();

        \BCC\Core\Log\Logger::reset();
        $GLOBALS['wpdb']->clearFaultInjection();

        $this->purge();
        $this->seed();
    }

    protected function tearDown(): void
    {
        // A failed case must not leave a fault armed for the next one.
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
    }

    // ── the fix ─────────────────────────────────────────────────────────

    /**
     * ⚠⚠ THE HEADLINE. Before the join fix this returned an empty page for
     * every input, because the statement never ran.
     */
    public function testTheVerifiedOnlyFallbackReturnsVerifiedPages(): void
    {
        $result = self::query(true);

        self::assertSame(2, $result['total'], 'the COUNT must survive the join');
        self::assertSame(
            [self::PAGE_OWNED, self::PAGE_AUTHORED],
            self::ids($result['rows']),
            'both the meta-owned and the author-owned verified page'
        );
    }

    /**
     * ⚠ ANTI-VACUITY: the filter must still FILTER. A branch that returned
     * everything, or one that ignored `$verified_only` altogether, would
     * satisfy the case above — so the unverified owner's page must be absent
     * here and present with the flag off.
     */
    public function testTheFlagStillExcludesUnverifiedOwners(): void
    {
        $verifiedOnly = self::ids(self::query(true)['rows']);
        $everything   = self::ids(self::query(false)['rows']);

        self::assertNotContains(self::PAGE_PLAIN, $verifiedOnly, 'an unverified owner must be filtered out');
        self::assertSame(
            [self::PAGE_OWNED, self::PAGE_PLAIN, self::PAGE_AUTHORED],
            $everything,
            'with the flag off all three pages are listed'
        );
    }

    /**
     * ⚠⚠ THE OWNER META IS WHAT DECIDES, and this is the case that makes the
     * join ORDER matter rather than merely its presence.
     *
     * PAGE_OWNED is authored by the UNVERIFIED user and owned by the verified
     * one, so it can only qualify through `pm_owner.meta_value` — the column
     * the broken statement referenced before introducing it. PAGE_AUTHORED is
     * the mirror image: no owner meta, so it can only qualify through the
     * `COALESCE`'s fallback to `post_author`. Both arms of that `COALESCE`
     * are therefore load-bearing here, and a fix that dropped `pm_owner`
     * instead of reordering it would fail on the first.
     */
    public function testBothArmsOfTheOwnerCoalesceResolve(): void
    {
        $ids = self::ids(self::query(true)['rows']);

        self::assertContains(self::PAGE_OWNED, $ids, 'resolved through _bcc_page_owner, against an unverified author');
        self::assertContains(self::PAGE_AUTHORED, $ids, 'resolved through post_author, with no owner meta');
    }

    /**
     * The row shape callers read is intact — including `follower_count`,
     * whose correlated subquery over `wp_usermeta` only runs in the main
     * statement. A fix that repaired the COUNT alone would pass every case
     * above and still fail here.
     */
    public function testTheReturnedRowCarriesTheDiscoveryShape(): void
    {
        $rows = self::query(true)['rows'];
        self::assertNotSame([], $rows, 'precondition: the fallback returned rows');

        // ⚠ The row is looked up by id rather than taken as `$rows[0]`. With
        // no score rows seeded, every page scores identically under the
        // default ranking, so the ORDER BY is a tie with no unique tiebreak
        // and the first row is whichever one the server happened to return.
        $row = null;
        foreach ($rows as $candidate) {
            if ((int) $candidate->ID === self::PAGE_OWNED) {
                $row = $candidate;
                break;
            }
        }
        self::assertNotNull($row, 'the meta-owned page must be among the rows');

        self::assertSame(self::PAGE_OWNED, (int) $row->ID);
        self::assertSame('Owned By Verified', $row->post_title);
        self::assertSame(self::PLAIN, (int) $row->post_author);
        self::assertSame(7, (int) $row->follower_count, 'the usermeta subquery ran');
        foreach (['trust_score', 'reputation_tier', 'endorsements', 'unique_voters'] as $field) {
            self::assertObjectHasProperty($field, $row);
        }
    }

    /**
     * ⚠⚠ EVERY SORT OPTION MUST ACTUALLY EXECUTE, and the DEFAULT one did
     * not. It ordered this `SELECT DISTINCT` by an expression over `ps.*`
     * columns the statement does not select, which MySQL 8 rejects outright
     * ("… is not in SELECT list; this is incompatible with DISTINCT"), so
     * discovery's most common request — no explicit sort — returned nothing
     * at all. The join fix alone did not reach it: the COUNT has no ORDER BY
     * and reported an honest 2 while the row read failed.
     *
     * ⭐ This is the case that generalises. Asserting the default sort alone
     * would leave the next added sort free to reintroduce the fault, so every
     * accepted value is exercised and each must return the rows AND log
     * nothing.
     */
    public function testEverySortOptionExecutesAndReturnsRows(): void
    {
        foreach (['', 'trust', 'endorsements', 'newest', 'followers'] as $sort) {
            \BCC\Core\Log\Logger::reset();

            $result = PageDiscoveryRepository::queryFromPostsTable(
                [],
                $sort,
                true,
                '',
                0.0,
                false,
                0,
                20,
                1,
                ''
            );

            self::assertSame(2, $result['total'], "sort '{$sort}': the COUNT ran");
            self::assertSame(
                [self::PAGE_OWNED, self::PAGE_AUTHORED],
                self::ids($result['rows']),
                "sort '{$sort}': the rows came back"
            );
            self::assertSame([], \BCC\Core\Log\Logger::$lines, "sort '{$sort}': nothing failed");
        }
    }

    /**
     * ⚠ The ranking column is selected exactly when it is ordered by. Both
     * come out of ONE `match`, and this pins that pairing: a branch that
     * ordered by `ranking_score` without selecting it would fail on MySQL 8,
     * and one that selected it without ordering by it would compute a
     * per-row expression nothing reads.
     */
    public function testTheRankingColumnIsSelectedOnlyWhenItIsOrderedBy(): void
    {
        // ⚠ Both row sets are asserted non-empty before being indexed. Under
        // a regression that breaks the read, `['rows'][0]` would raise
        // "Undefined array key 0" — an ERROR that reads as a broken test
        // rather than as the caught defect it actually is.
        $default = self::query(true)['rows'];
        self::assertNotSame([], $default, 'precondition: the default sort returned rows');
        self::assertObjectHasProperty('ranking_score', $default[0], 'the default sort orders by it, so it must be selected');

        $byNewest = PageDiscoveryRepository::queryFromPostsTable(
            [],
            'newest',
            true,
            '',
            0.0,
            false,
            0,
            20,
            1,
            ''
        )['rows'];
        self::assertNotSame([], $byNewest, 'precondition: the newest sort returned rows');
        self::assertObjectNotHasProperty('ranking_score', $byNewest[0], 'no other sort needs it');
    }

    // ── proof the old order was broken ──────────────────────────────────

    /**
     * ⚠⚠ WITHOUT THIS, THE CASES ABOVE PROVE ONLY THAT THE CURRENT SQL
     * WORKS — not that the previous SQL was broken, and not that this file
     * would have failed before the fix.
     *
     * The two joins are issued here in their original order against the same
     * server and the same fixture, and the rejection is asserted. The null
     * return is asserted too, because `(int) null === 0` is the step that
     * turned a hard SQL error into a plausible-looking empty page.
     */
    public function testTheOldJoinOrderIsRejectedByTheServer(): void
    {
        global $wpdb;

        $out = $wpdb->get_var($this->countWithJoin($this->brokenJoin()));

        self::assertNull($out, 'a failed get_var() answers null — which (int) turns into 0');
        self::assertStringContainsStringIgnoringCase(
            'pm_owner',
            (string) $wpdb->last_error,
            'the error must name the alias the ON clause referenced too early'
        );
    }

    /**
     * ⚠ And the corrected order is accepted by that same server against that
     * same fixture — so the case above turns on the ORDERING, not on a
     * missing table, a bad fixture or an unrelated syntax error.
     */
    public function testTheCorrectedJoinOrderIsAccepted(): void
    {
        global $wpdb;

        $out = $wpdb->get_var($this->countWithJoin($this->fixedJoin()));

        self::assertSame('', (string) $wpdb->last_error, 'the corrected order must not error');
        self::assertSame(2, (int) $out);
    }

    // ── a failed read is not an empty result ────────────────────────────

    /**
     * ⚠⚠ THE COUNT'S FAILURE IS NOW LOGGED instead of being returned as a
     * credible zero. The behaviour deliberately does NOT change — the page
     * still comes back empty, because whether discovery should answer
     * "unavailable" rather than "empty" is a contract decision for the
     * route. What changes is that the next occurrence leaves a trace.
     */
    public function testAFailedCountIsLoggedRatherThanReturnedAsZero(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/COUNT\(DISTINCT p\.ID\)/';

        $result = self::query(true);

        self::assertSame(['rows' => [], 'total' => 0, 'pages' => 0, 'page' => 1], $result);
        self::assertSame(1, $GLOBALS['wpdb']->injectedFailures, 'exactly the COUNT was broken');
        self::assertSame('discovery count', self::loggedFailure()['context']['what']);
    }

    /**
     * ⚠ And the MAIN read's failure is caught separately, which is what
     * shows the check is per-read: the COUNT succeeded here and reported a
     * real total of 2, so a blanket "did anything fail in this request"
     * check would have attributed it to the wrong statement.
     */
    public function testAFailedRowReadIsLoggedWhileTheCountSucceeded(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/SELECT DISTINCT p\.ID/';

        $result = self::query(true);

        self::assertSame(2, $result['total'], 'the COUNT ran and answered honestly');
        self::assertSame([], $result['rows'], 'the rows are empty because the read failed, and that is now known');
        self::assertSame(1, $GLOBALS['wpdb']->injectedFailures, 'exactly the main read was broken');
        self::assertSame('discovery rows', self::loggedFailure()['context']['what']);
    }

    /**
     * ⚠⚠ THE LOG MUST NOT CARRY THE STATEMENT. By the time either read
     * fails, the SQL has been through `wpdb::prepare()`, which SUBSTITUTES
     * values rather than leaving placeholders — so the string holds the
     * caller's `$search` term verbatim, free-text member input. `Logger`
     * scrubs context by KEY (`token`, `secret`, `wallet`, `address`, …) and
     * a key named `sql` matches none of them.
     *
     * An earlier revision of the fix logged it. This case exists so that
     * re-adding it fails a test instead of shipping.
     */
    public function testTheFailureLogCarriesNoSqlAndNoSearchTerm(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/COUNT\(DISTINCT p\.ID\)/';

        PageDiscoveryRepository::queryFromPostsTable(
            [],
            'trust',
            true,
            '',
            0.0,
            false,
            0,
            20,
            1,
            'burt macklin'
        );

        $context = self::loggedFailure()['context'];

        self::assertSame(['what', 'error', 'verified_only'], array_keys($context), 'the context is exactly these three keys');
        self::assertTrue($context['verified_only'], 'the branch is identified by a bool, which leaks nothing');
        self::assertStringNotContainsStringIgnoringCase(
            'burt macklin',
            json_encode($context, JSON_THROW_ON_ERROR),
            'the search term must not reach the log'
        );
    }

    /**
     * ⚠ ANTI-VACUITY for the two cases above: with no fault armed, a
     * successful read logs NOTHING. Otherwise a guard that logged
     * unconditionally — or one wired to the wrong branch — would satisfy
     * them both.
     */
    public function testASuccessfulReadLogsNothing(): void
    {
        $result = self::query(true);

        self::assertSame(2, $result['total'], 'precondition: the read succeeded');
        self::assertSame([], \BCC\Core\Log\Logger::$lines, 'a healthy read is silent');
    }

    // ── fixture ─────────────────────────────────────────────────────────

    /** @return array{rows: list<object>, total: int, pages: int, page: int} */
    private static function query(bool $verifiedOnly): array
    {
        /** @var array{rows: list<object>, total: int, pages: int, page: int} $out */
        $out = PageDiscoveryRepository::queryFromPostsTable(
            [],             // types
            'trust',        // sort — the default ranking, which reads only ps.*
            $verifiedOnly,
            '',             // tier
            0.0,            // min_confidence
            false,          // new_only
            0,              // max_votes
            20,             // limit
            1,              // page
            ''              // search
        );

        return $out;
    }

    /**
     * The `$verified_only` joins as they were BEFORE the fix: the INNER JOIN
     * reads `pm_owner` before the LEFT JOIN introduces it.
     */
    private function brokenJoin(): string
    {
        global $wpdb;

        return ' INNER JOIN ' . TableRegistry::userInfo()
            . ' ui ON ui.user_id = COALESCE(pm_owner.meta_value, p.post_author) AND ui.is_verified = 1'
            . " LEFT JOIN {$wpdb->postmeta} pm_owner ON p.ID = pm_owner.post_id"
            . " AND pm_owner.meta_key = '_bcc_page_owner'";
    }

    /** The same two joins in the order the fix puts them. */
    private function fixedJoin(): string
    {
        global $wpdb;

        return " LEFT JOIN {$wpdb->postmeta} pm_owner ON p.ID = pm_owner.post_id"
            . " AND pm_owner.meta_key = '_bcc_page_owner'"
            . ' INNER JOIN ' . TableRegistry::userInfo()
            . ' ui ON ui.user_id = COALESCE(pm_owner.meta_value, p.post_author) AND ui.is_verified = 1';
    }

    private function countWithJoin(string $join): string
    {
        global $wpdb;

        return "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p{$join}"
            . " WHERE p.post_status = 'publish' AND p.post_type = 'peepso-page'";
    }

    /**
     * Add only what the shared bootstrap's `wp_posts` lacks, and create
     * `wp_usermeta`, which it does not create at all.
     *
     * ⚠ IDEMPOTENT BY INFORMATION_SCHEMA, NOT BY ASSUMPTION: `wp_posts` is
     * shared, other suites widen it with the same columns, and a second ADD
     * of one column is an error. For the same reason the columns are NOT
     * dropped in tearDown — a sibling suite that added them for itself would
     * find them gone.
     */
    private function widenPostsTable(): void
    {
        global $wpdb;

        $wanted = [
            'post_author' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'post_date'   => "DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00'",
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
        if ($adds !== []) {
            self::assertNotFalse(
                $wpdb->query("ALTER TABLE {$wpdb->posts} " . implode(', ', $adds)),
                'posts fixture: ' . $wpdb->last_error
            );
        }

        self::assertNotFalse(
            $wpdb->query(
                "CREATE TABLE IF NOT EXISTS {$wpdb->usermeta} (
                    umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    meta_key VARCHAR(255) DEFAULT NULL,
                    meta_value LONGTEXT,
                    PRIMARY KEY (umeta_id),
                    KEY user_id (user_id)
                ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            ),
            'usermeta fixture: ' . $wpdb->last_error
        );
    }

    private function seed(): void
    {
        global $wpdb;

        // ⚠ user_login / user_email / display_name are NOT NULL with no
        // default, so omitting them makes the INSERT fail — and an empty
        // fixture reads exactly like the bug this file is about. Every
        // fixture write is therefore asserted, and the counts are re-read
        // at the end.
        foreach ([[self::VERIFIED, 1], [self::PLAIN, 0]] as [$uid, $verified]) {
            self::assertNotFalse(
                $wpdb->query($wpdb->prepare(
                    'INSERT INTO `' . TableRegistry::userInfo() . '`
                        (user_id, user_login, user_email, display_name, is_verified)
                     VALUES (%d, %s, %s, %s, %d)',
                    $uid,
                    'fixture' . $uid,
                    'fixture' . $uid . '@example.invalid',
                    'Fixture ' . $uid,
                    $verified
                )),
                'user_info fixture: ' . $wpdb->last_error
            );
        }

        // ⚠ PAGE_OWNED and PAGE_PLAIN are authored by the UNVERIFIED user on
        // purpose: PAGE_OWNED can then only qualify through `_bcc_page_owner`,
        // which is the COALESCE arm the broken join read too early.
        $pages = [
            [self::PAGE_OWNED, 'Owned By Verified', 'owned-by-verified', self::PLAIN],
            [self::PAGE_PLAIN, 'Owned By Nobody', 'owned-by-nobody', self::PLAIN],
            [self::PAGE_AUTHORED, 'Authored By Verified', 'authored-by-verified', self::VERIFIED],
        ];
        foreach ($pages as [$pageId, $title, $slug, $author]) {
            self::assertNotFalse(
                $wpdb->insert($wpdb->posts, [
                    'ID'          => $pageId,
                    'post_author' => $author,
                    'post_title'  => $title,
                    'post_name'   => $slug,
                    'post_type'   => 'peepso-page',
                    'post_status' => 'publish',
                    'post_date'   => '2026-01-01 00:00:00',
                ]),
                'page fixture: ' . $wpdb->last_error
            );
        }

        self::assertNotFalse(
            $wpdb->insert($wpdb->postmeta, [
                'post_id'    => self::PAGE_OWNED,
                'meta_key'   => '_bcc_page_owner',
                'meta_value' => (string) self::VERIFIED,
            ]),
            'owner meta fixture: ' . $wpdb->last_error
        );

        // Feeds the follower_count subquery in the main statement.
        self::assertNotFalse(
            $wpdb->insert($wpdb->usermeta, [
                'user_id'    => self::PLAIN,
                'meta_key'   => 'peepso_followers_count',
                'meta_value' => '7',
            ]),
            'usermeta fixture: ' . $wpdb->last_error
        );

        self::assertSame(
            3,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'peepso-page'"),
            'fixture precondition: three pages, and no stray page from another suite'
        );
    }

    /**
     * ⚠ `wp_posts` and `wp_postmeta` are emptied WHOLESALE, not by id range:
     * they are shared across suites, and a published `peepso-page` left
     * behind by another one would be a real match for this filter and would
     * break the exact-set assertions. Rows this suite owns elsewhere are
     * removed by id.
     */
    private function purge(): void
    {
        global $wpdb;

        $wpdb->query("DELETE FROM {$wpdb->postmeta}");
        $wpdb->query("DELETE FROM {$wpdb->posts}");
        $wpdb->query('DELETE FROM `' . TableRegistry::userInfo() . '` WHERE user_id IN (' . self::VERIFIED . ', ' . self::PLAIN . ')');
        $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE user_id IN (" . self::VERIFIED . ', ' . self::PLAIN . ')');
    }

    /**
     * The single ERROR line the guarded read left behind.
     *
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    private static function loggedFailure(): array
    {
        $lines = array_values(array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn (array $line): bool => $line['level'] === 'error'
        ));

        self::assertCount(1, $lines, 'exactly one failure should be logged');

        return $lines[0];
    }

    /**
     * @param  list<object> $rows
     * @return list<int>
     */
    private static function ids(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = (int) $row->ID;
        }
        sort($out);

        return $out;
    }
}
