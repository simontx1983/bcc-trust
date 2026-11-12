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

    /** Chain id for the `_bcc_gate_chain_id` fixture (F-216). */
    private const CHAIN = 9931;

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

    // ── the SILENT failure: false with no last_error ─────────────────────

    /**
     * ⚠⚠⚠ THE CASE THAT JUSTIFIES `trustedRows()`, and the one a
     * `last_error` check cannot pass.
     *
     * `wpdb::query()` has failure paths that return false and record
     * NOTHING — the `! $this->ready` guard after a failed reconnect, and an
     * empty result from the `query` filter. They return BEFORE `flush()`, so
     * `last_result` still holds the PREVIOUS query's rows.
     *
     * Here only the MAIN read is silenced, so the COUNT runs first and
     * succeeds — which means `last_error` is genuinely EMPTY and
     * `last_result` is genuinely POPULATED (with the COUNT's own row) at the
     * moment the main read fails. The pre-`trustedRows()` guard would have
     * seen a clean error, trusted `get_results()`, and handed the caller a
     * row object made out of the COUNT — a bogus "page" carrying an `n`
     * property.
     *
     * So this asserts three separate things: the rows are refused, the total
     * is still honest, and `last_error` was empty the whole time (without
     * which the case would be passing through the OLD check and proving
     * nothing new).
     */
    public function testASilentFailureIsCaughtAndStaleRowsAreRefused(): void
    {
        $GLOBALS['wpdb']->failSilentlyMatching = '/' . preg_quote(self::ROWS_NEEDLE, '/') . '/';

        $result = self::query();

        self::assertSame(1, $result['total'], 'the COUNT ran and answered honestly');
        self::assertSame([], $result['rows'], 'the previous query\'s rows must NOT be served as this one\'s');
        self::assertSame(1, $GLOBALS['wpdb']->silentFailures, 'exactly the main read was silenced');
        self::assertSame(
            '',
            (string) $GLOBALS['wpdb']->last_error,
            'ANTI-VACUITY: last_error stayed empty, so only the query() return could have caught this'
        );
        self::assertSame('read-model rows', self::loggedFailure()['context']['what']);

        // ⚠⚠ FIXTURE FIDELITY, asserted rather than assumed. The danger is
        // not just a missed failure: the real wpdb paths return BEFORE
        // flush(), so the previous query's rows are still sitting in
        // `last_result` and a `last_error`-only guard would serve them.
        // If the harness cleared them, this case would be exercising
        // something milder than the fault it is named after, and would pass
        // for the wrong reason.
        self::assertNotSame(
            [],
            $GLOBALS['wpdb']->last_result,
            'the COUNT rows must still be in last_result — that is what makes a silent failure dangerous'
        );
    }

    /**
     * ⚠ The same for the COUNT. A silent failure there previously produced
     * `(int) null === 0` with nothing logged — the original F-197 defect,
     * reachable even after the `last_error` guard was added.
     */
    public function testASilentCountFailureIsCaught(): void
    {
        $GLOBALS['wpdb']->failSilentlyMatching = '/' . preg_quote(self::COUNT_NEEDLE, '/') . '/';

        $result = self::query();

        self::assertSame(self::EMPTY_PAYLOAD, $result, 'the payload is unchanged by design');
        self::assertSame(1, $GLOBALS['wpdb']->silentFailures);
        self::assertSame('read-model count', self::loggedFailure()['context']['what']);
    }

    /**
     * ⚠ And the finders too, since they went through the same conversion.
     * `findPublishedByIds()` is the one whose stale-row exposure mattered
     * most: its result is keyed by page id, so an unrelated query's rows
     * would have been indexed as though they were pages.
     */
    public function testASilentFailureIsCaughtInTheFinders(): void
    {
        $GLOBALS['wpdb']->failSilentlyMatching = '/' . preg_quote('SELECT ID, post_title, post_name', '/') . '/';
        self::assertSame([], PageDiscoveryRepository::findPublishedByIds([self::PAGE]));
        self::assertSame('published pages by id', self::loggedFailure()['context']['what']);

        \BCC\Core\Log\Logger::reset();
        $GLOBALS['wpdb']->clearFaultInjection();
        $GLOBALS['wpdb']->failSilentlyMatching = '/' . preg_quote('_bcc_gate_chain_id', '/') . '/';
        self::assertSame([], PageDiscoveryRepository::findPostIdsGatedByChain([self::PAGE], self::CHAIN));
        self::assertSame('chain-gated post ids', self::loggedFailure()['context']['what']);
    }

    /**
     * ⚠ ANTI-VACUITY for the silent mode itself: a zero-row result is NOT a
     * failure. `query()` returns 0 for an empty SELECT, which is falsy but
     * not `=== false`, so an honestly empty page must still come back as a
     * successful empty read with nothing logged.
     */
    public function testAnHonestlyEmptyResultIsNotTreatedAsAFailure(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->posts}");

        $result = self::query();

        self::assertSame(self::EMPTY_PAYLOAD, $result);
        self::assertSame([], \BCC\Core\Log\Logger::$lines, 'no rows is an answer, not a failure');
    }

    /**
     * ⚠⚠ `=== false`, NEVER FALSY — and this is the only case that pins it.
     *
     * For a SELECT, `query()` returns the ROW COUNT, so a legitimately empty
     * result returns **0**. A `!$ok` test would read that as a failed read
     * and log one, turning "these ids are not published" into a fabricated
     * error.
     *
     * ⚠ The two list queries cannot exercise this: `COUNT(… ) AS n` always
     * returns exactly one row, and when the total is 0 the main read is
     * skipped entirely — so NO statement in those paths ever returns zero
     * rows. A finder asked about an id that does not exist is the one read
     * that does. (A mutation to `!$ok` survived the whole suite until this
     * case existed.)
     */
    public function testAZeroRowFinderResultIsNotAFailure(): void
    {
        $absent = self::PAGE + 4242;

        $out = PageDiscoveryRepository::findPublishedByIds([$absent]);

        self::assertSame([], $out, 'an absent id yields no rows');
        self::assertSame(
            [],
            \BCC\Core\Log\Logger::$lines,
            'zero rows is an ANSWER: query() returned 0, which is falsy but not false'
        );
    }

    // ── F-216: the two bulk finders ─────────────────────────────────────

    /**
     * ⚠ ANTI-VACUITY for the two finder cases below: healthy, each one finds
     * its seeded row and logs nothing. Without these, a finder that always
     * returned `[]` would satisfy both failure assertions.
     */
    public function testHealthyFindersReturnTheirRowsAndLogNothing(): void
    {
        $published = PageDiscoveryRepository::findPublishedByIds([self::PAGE]);
        $gated     = PageDiscoveryRepository::findPostIdsGatedByChain([self::PAGE], self::CHAIN);

        self::assertSame([self::PAGE], array_keys($published));
        self::assertSame([self::PAGE], $gated);
        self::assertSame([], \BCC\Core\Log\Logger::$lines, 'healthy finders are silent');
    }

    /**
     * ⚠⚠ `findPublishedByIds()` used `$rows ?: []`, so a failed read read as
     * "none of these pages is published". Its caller
     * (`FeedHydrationPipeline`) then drops page context for a whole feed
     * page. It still returns `[]` — fails closed, unchanged — but no longer
     * silently.
     */
    public function testAFailedPublishedLookupIsLoggedAndStillReturnsEmpty(): void
    {
        $this->failReadsMatching('SELECT ID, post_title, post_name');

        $out = PageDiscoveryRepository::findPublishedByIds([self::PAGE]);

        self::assertSame([], $out, 'fails closed, exactly as before');
        self::assertSame('published pages by id', self::loggedFailure()['context']['what']);
    }

    /**
     * ⚠⚠ `findPostIdsGatedByChain()` answering `[]` means "no post is gated
     * to this chain".
     *
     * ⭐ Its single caller (`GroupsDiscoveryEndpoint:454`) uses the result as
     * an ALLOW-LIST and returns `[]` when it is empty, so this fails CLOSED —
     * fewer groups listed, never a gated group exposed. That was checked at
     * the call site, not assumed: the opposite reading would have made this a
     * gating defect rather than a quiet degradation.
     */
    public function testAFailedGateLookupIsLoggedAndStillReturnsEmpty(): void
    {
        $this->failReadsMatching('_bcc_gate_chain_id');

        $out = PageDiscoveryRepository::findPostIdsGatedByChain([self::PAGE], self::CHAIN);

        self::assertSame([], $out, 'fails closed — an allow-list, so fewer groups, never more');
        self::assertSame('chain-gated post ids', self::loggedFailure()['context']['what']);
    }

    /**
     * ⚠⚠ THE WIDENED HELPER MUST NOT FABRICATE FACTS. F-216 replaced the
     * helper's `bool $verifiedOnly` parameter with a context array precisely
     * because the finders have no such notion — logging
     * `verified_only: false` for them would assert something untrue about a
     * query that never had the concept.
     *
     * So: the finders carry `ids` and NO `verified_only`, while the list
     * queries still carry `verified_only` and no `ids`. This also pins that
     * generalising the helper did not drop the key the earlier paths log.
     */
    public function testEachPathLogsOnlyTheFactsItHas(): void
    {
        $this->failReadsMatching('SELECT ID, post_title, post_name');
        PageDiscoveryRepository::findPublishedByIds([self::PAGE]);
        self::assertSame(['what', 'error', 'ids'], array_keys(self::loggedFailure()['context']));

        \BCC\Core\Log\Logger::reset();
        $this->failReadsMatching(self::COUNT_NEEDLE);
        self::query();
        self::assertSame(['what', 'error', 'verified_only'], array_keys(self::loggedFailure()['context']));
    }

    /**
     * ⚠ And `$context` cannot shadow the fixed keys — it is merged AFTER
     * them, so a caller cannot overwrite `what` or `error` with its own
     * value. Asserted through a real failure rather than by reading the
     * merge order.
     */
    public function testTheContextCannotOverwriteWhatOrError(): void
    {
        $this->failReadsMatching('_bcc_gate_chain_id');

        PageDiscoveryRepository::findPostIdsGatedByChain([self::PAGE], self::CHAIN);

        $line = self::loggedFailure();
        self::assertSame('chain-gated post ids', $line['context']['what']);
        self::assertNotSame('', (string) $line['context']['error'], 'the driver error survives the merge');
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

        // F-216: makes the page chain-gated, for findPostIdsGatedByChain().
        self::assertNotFalse(
            $wpdb->insert($wpdb->postmeta, [
                'post_id'    => self::PAGE,
                'meta_key'   => '_bcc_gate_chain_id',
                'meta_value' => (string) self::CHAIN,
            ]),
            'gate meta fixture: ' . $wpdb->last_error
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

        $wpdb->query("DELETE FROM {$wpdb->postmeta}");
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
