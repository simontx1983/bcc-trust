<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\PageDiscoveryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-197 — "no page matches this filter" and "the read model was unreadable"
 * are different facts, on the discovery path that actually runs.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 * `queryFromReadModel()` read its COUNT with `(int) $wpdb->get_var()`.
 * `get_var()` answers null both for "no rows" and for "the statement did not
 * run", `(int) null` is 0, and the `$total === 0` branch returns an empty
 * page before the main query is built. So an unreadable read model was
 * reported to every caller as a legitimately empty result list.
 *
 * ⚠ This is the PRIMARY path. `PageDiscoveryService` chooses it whenever
 * `PageReadModelRepository::hasData()` is true, which is the normal state;
 * `queryFromPostsTable()` is the legacy fallback. F-144 fixed the same blind
 * spot one method down, where it was additionally hiding two SQL defects.
 *
 * ── WHAT THIS DOES NOT CHANGE ───────────────────────────────────────────
 * The guarded reads log and STILL RETURN EMPTY. "Empty on an unreadable
 * table" is the ratified behaviour for discovery, so the payload is
 * unchanged by design and these cases assert it stays that way. The only new
 * fact is the ERROR line, which is why the log is what they assert on.
 *
 * ── WHY INTEGRATION ─────────────────────────────────────────────────────
 * The distinction exists only against a real driver: it is the difference
 * between a query returning no rows and a query that never ran, and
 * `last_error` is the only thing separating them. A stubbed `$wpdb`
 * returning 0 twice proves nothing.
 *
 * ⚠ Faults are injected through the harness's `failQueriesMatching` rather
 * than by renaming or dropping a table — a missing table is re-created EMPTY
 * by the next schema pass, after which the read SUCCEEDS and returns a
 * genuine 0, so the test would quietly stop exercising this path. Matching on
 * query text also lets the COUNT fail while the row read would have
 * succeeded, and the reverse, which is what proves the verdict is per-read.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(PageDiscoveryRepository::class)]
final class PageDiscoveryReadFailureIntegrationTest extends TestCase
{
    private const PAGE = 993101;

    /** Matches the COUNT statement only. */
    private const COUNT_NEEDLE = 'SELECT COUNT(*)';

    /** In the main SELECT list, and absent from the COUNT. */
    private const ROWS_NEEDLE = 'rm.owner_id';

    private const EMPTY_PAYLOAD = ['rows' => [], 'total' => 0, 'pages' => 0, 'page' => 1];

    protected function setUp(): void
    {
        $this->ensureTables();

        $GLOBALS['wpdb']->clearFaultInjection();
        \BCC\Core\Log\Logger::reset();

        $this->purge();
        $this->seed();
    }

    protected function tearDown(): void
    {
        // A failed case must not leave a fault armed for the next one.
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
    }

    // ── control ─────────────────────────────────────────────────────────

    /**
     * ⚠ ANTI-VACUITY, and the case the others depend on. With no fault
     * armed the query finds the seeded page and logs NOTHING — so a guard
     * that logged unconditionally, or a fixture that was silently empty,
     * cannot make the failure cases below pass for the wrong reason.
     */
    public function testAHealthyReadFindsThePageAndLogsNothing(): void
    {
        $result = self::query();

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['rows']);
        self::assertSame(self::PAGE, (int) $result['rows'][0]->ID);
        self::assertSame([], \BCC\Core\Log\Logger::$lines, 'a healthy read is silent');
    }

    // ── the two reads, failing independently ────────────────────────────

    /**
     * ⚠⚠ A FAILED COUNT IS NO LONGER A CREDIBLE ZERO. The payload is
     * deliberately identical to a genuine empty result — that is the
     * ratified behaviour — so the ERROR line is the entire difference, and
     * before this change there was none.
     */
    public function testAFailedCountIsLoggedAndStillReturnsEmpty(): void
    {
        $this->failReadsMatching(self::COUNT_NEEDLE);

        $result = self::query();

        self::assertSame(self::EMPTY_PAYLOAD, $result, 'the payload is unchanged by design');
        self::assertSame(1, $GLOBALS['wpdb']->injectedFailures, 'exactly the COUNT was broken');
        self::assertSame('read-model count', self::loggedFailure()['context']['what']);
    }

    /**
     * ⚠⚠ THE ROW READ IS CHECKED SEPARATELY, and this is the case that
     * shows why. The COUNT succeeded here and reported a truthful total of
     * 1 while the rows came back empty — a blanket "did anything fail in
     * this request" check would have blamed the wrong statement, and an
     * honest total beside an empty row set is exactly the signature that
     * hid F-144's second defect.
     */
    public function testAFailedRowReadIsLoggedWhileTheCountSucceeds(): void
    {
        $this->failReadsMatching(self::ROWS_NEEDLE);

        $result = self::query();

        self::assertSame(1, $result['total'], 'the COUNT ran and answered honestly');
        self::assertSame([], $result['rows'], 'the rows are empty because the read failed');
        self::assertSame(1, $GLOBALS['wpdb']->injectedFailures, 'exactly the row read was broken');
        self::assertSame('read-model rows', self::loggedFailure()['context']['what']);
    }

    /**
     * ⚠ The two discovery paths are labelled distinctly. Both methods guard
     * their reads and both log through the same helper, so a shared label
     * would leave an operator unable to tell which query failed — and they
     * are chosen by a runtime condition (`hasData()`), not by the caller.
     */
    public function testTheLabelNamesTheReadModelPathNotTheFallback(): void
    {
        $this->failReadsMatching(self::COUNT_NEEDLE);
        self::query();

        $what = self::loggedFailure()['context']['what'];
        self::assertStringStartsWith('read-model ', $what);
        self::assertStringNotContainsString('discovery ', $what, 'that label belongs to the posts-table fallback');
    }

    // ── the log must not carry the statement ─────────────────────────────

    /**
     * ⚠⚠ NO SQL, AND NO SEARCH TERM. By the time either read fails the
     * statement has been through `wpdb::prepare()`, which SUBSTITUTES values
     * rather than leaving placeholders — so it carries the caller's
     * `$search` verbatim, which is free-text member input. `Logger` scrubs
     * context by KEY (`token`, `secret`, `wallet`, `address`, …) and a key
     * named `sql` matches none of them.
     *
     * The same pin exists on the fallback path. It is repeated here because
     * this method logs through the same helper but from its own call sites,
     * and a future edit could re-add the statement at one and not the other.
     */
    public function testTheFailureLogCarriesNoSqlAndNoSearchTerm(): void
    {
        $this->failReadsMatching(self::COUNT_NEEDLE);

        PageDiscoveryRepository::queryFromReadModel(
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

        self::assertSame(['what', 'error', 'verified_only'], array_keys($context), 'exactly these three keys');
        self::assertStringNotContainsStringIgnoringCase(
            'burt macklin',
            json_encode($context, JSON_THROW_ON_ERROR),
            'the search term must not reach the log'
        );
    }

    // ── fixture ─────────────────────────────────────────────────────────

    /** @return array{rows: list<object>, total: int, pages: int, page: int} */
    private static function query(): array
    {
        /** @var array{rows: list<object>, total: int, pages: int, page: int} $out */
        $out = PageDiscoveryRepository::queryFromReadModel(
            [],      // types
            'trust', // sort — the default ranking; no validator join
            true,    // verified_only → rm.is_verified = 1
            '',      // tier
            0.0,     // min_confidence
            false,   // new_only
            0,       // max_votes
            20,      // limit
            1,       // page
            ''       // search
        );

        return $out;
    }

    private function failReadsMatching(string $needle): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $GLOBALS['wpdb']->failQueriesMatching = '/' . preg_quote($needle, '/') . '/';
    }

    /**
     * ⚠ The read model is installed by the PLUGIN'S OWN installer. A
     * hand-rolled stand-in would shadow the real schema for every suite
     * sharing this database — a mistake already made once in this
     * directory, per ListTabsReadFailureIntegrationTest.
     *
     * `wp_posts` is widened idempotently via information_schema because it
     * is shared and a second ADD of the same column errors; the columns are
     * NOT dropped in teardown, or a sibling suite that added them for itself
     * would find them gone.
     */
    private function ensureTables(): void
    {
        global $wpdb;

        $readModel = TableRegistry::pageReadModel();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $readModel)) !== $readModel) {
            require_once dirname(__DIR__, 2) . '/includes/database/schema-project.php';
            \bcc_trust_create_page_tables();
        }
        self::assertSame(
            $readModel,
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $readModel)),
            'the read model must be installed by the plugin, not by this fixture'
        );

        $existing = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $wpdb->posts
        ));
        if (!in_array('post_date', $existing, true)) {
            self::assertNotFalse(
                $wpdb->query("ALTER TABLE {$wpdb->posts} ADD COLUMN post_date DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00'"),
                'posts fixture: ' . $wpdb->last_error
            );
        }
    }

    private function seed(): void
    {
        global $wpdb;

        self::assertNotFalse(
            $wpdb->insert($wpdb->posts, [
                'ID'          => self::PAGE,
                'post_title'  => 'Read Model Page',
                'post_name'   => 'read-model-page',
                'post_type'   => 'peepso-page',
                'post_status' => 'publish',
                'post_date'   => '2026-01-01 00:00:00',
            ]),
            'page fixture: ' . $wpdb->last_error
        );

        self::assertNotFalse(
            $wpdb->insert(TableRegistry::pageReadModel(), [
                'page_id'     => self::PAGE,
                'is_verified' => 1,
            ]),
            'read-model fixture: ' . $wpdb->last_error
        );

        // ⚠ Asserted, not assumed: a silently empty fixture reads exactly
        // like the defect under test, so every failure case below would pass
        // for the wrong reason.
        self::assertSame(
            1,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'peepso-page'"),
            'fixture precondition: exactly one page, and no stray from another suite'
        );
    }

    /**
     * ⚠ `wp_posts` is emptied WHOLESALE rather than by id: it is shared, and
     * another suite's leftover published `peepso-page` is a real match for
     * this filter and would break the exact-count assertions.
     */
    private function purge(): void
    {
        global $wpdb;

        $wpdb->query("DELETE FROM {$wpdb->posts}");
        $wpdb->query('DELETE FROM `' . TableRegistry::pageReadModel() . '` WHERE page_id = ' . self::PAGE);
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
}
