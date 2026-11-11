<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The `query($sql)` then `get_col(null)` idiom, against a real engine.
 *
 * ⚠ WHY THIS EXISTS. bcc-core's `PeepSoGroupRepository` reads its
 * non-open group ids with real `wpdb`'s documented two-step — run the
 * query, then project the already-fetched `last_result` without a second
 * round trip — at two call sites. The integration double declared
 * `get_col(string $sql)`, so every bcc-trust integration run after that
 * code landed died with
 * `TypeError: Argument #1 ($sql) must be of type string, null given`,
 * on EVERY branch including `main`, for bcc-core code that is correct in
 * production. The double was narrower than the thing it doubles.
 *
 * `IntegrationWpdbDoubleSignatureTest` guards the SIGNATURES in the unit
 * job. This guards the BEHAVIOUR, which that cannot: a correctly-typed
 * `get_col(null)` returning the wrong column, or stale rows from a
 * previous query, would satisfy every reflection assertion.
 *
 * ⚠ It uses a literal `SELECT ... UNION ALL SELECT ...` rather than a
 * fixture table. Deliberate: this suite shares a database with suites
 * that create and unconditionally drop tables on the shared schema, and
 * a double's own contract test has no business adding to that surface.
 * Literals give two rows and two columns with no DDL, no shared state
 * and nothing to tear down.
 *
 * ⚠ Values come back as strings — WordPress's `wpdb` does not use native
 * prepared statements and neither does this double, so mysqli returns
 * every column as a string on both engines. Assertions cast rather than
 * assume an int, which is also what production code reading these rows
 * has to do.
 */
#[CoversNothing]
#[Group('integration')]
#[Group('mariadb')]
final class WpdbDoubleLastResultIntegrationTest extends TestCase
{
    /** Two rows, two columns, no table. */
    private const TWO_BY_TWO = "SELECT 1 AS a, 'x' AS b UNION ALL SELECT 2 AS a, 'y' AS b";

    public function testASelectThroughQueryFillsLastResultAndReturnsTheRowCount(): void
    {
        global $wpdb;

        $out = $wpdb->query(self::TWO_BY_TWO);

        $this->assertNotFalse($out, 'A valid SELECT is not a failure.');
        $this->assertSame(2, $out, 'Real wpdb::query() returns the row count for a SELECT.');
        $this->assertCount(2, $wpdb->last_result);
        $this->assertSame('', $wpdb->last_error);
    }

    /** ⚠ THE EXACT IDIOM THAT WAS BROKEN. */
    public function testGetColNullProjectsLastResultWithoutRequerying(): void
    {
        global $wpdb;

        $wpdb->query(self::TWO_BY_TWO);
        $before = $wpdb->queryCount;

        $col = $wpdb->get_col(null);

        $this->assertSame(['1', '2'], array_map('strval', $col));
        $this->assertSame(
            $before,
            $wpdb->queryCount,
            'The null form must read the already-fetched rows, not issue a second query — that is the '
            . 'entire reason production calls it this way.'
        );
    }

    public function testGetColHonoursTheColumnIndex(): void
    {
        global $wpdb;

        $wpdb->query(self::TWO_BY_TWO);

        $this->assertSame(['x', 'y'], array_map('strval', $wpdb->get_col(null, 1)));
    }

    public function testGetVarNullHonoursColumnAndRow(): void
    {
        global $wpdb;

        $wpdb->query(self::TWO_BY_TWO);

        $this->assertSame('1', (string) $wpdb->get_var(null, 0, 0));
        $this->assertSame('x', (string) $wpdb->get_var(null, 1, 0));
        $this->assertSame('2', (string) $wpdb->get_var(null, 0, 1));
        $this->assertSame('y', (string) $wpdb->get_var(null, 1, 1));
        $this->assertNull($wpdb->get_var(null, 0, 99), 'A row past the end is null, not an error.');
    }

    /**
     * ⚠⚠ INVERTED, AND THE INVERSION IS THE POINT. An earlier revision of
     * this file asserted that `get_row(null)` projects `last_result`, and
     * the double obliged. Both were wrong. Real `wpdb::get_row()` opens
     * `if ( $query ) { ... } else { return null; }`, so the null form
     * returns NULL and never looks at `last_result` — only `get_var()`
     * and `get_col()` have no else branch.
     *
     * Kept and flipped rather than deleted: a double that is MORE
     * permissive than production is the same false-green class this
     * whole PR exists to close, and it is worth a test that would catch
     * someone "restoring" the convenience.
     */
    public function testGetRowNullReturnsNullAndNeverReadsLastResult(): void
    {
        global $wpdb;

        $wpdb->query(self::TWO_BY_TWO);
        $this->assertCount(2, $wpdb->last_result, 'precondition: rows ARE available');

        $this->assertNull($wpdb->get_row(null));
        $this->assertNull($wpdb->get_row(null, 'ARRAY_A', 1));
        $this->assertNull($wpdb->get_row(null, 'OBJECT', 0));

        // And the rows are still there — get_row(null) read nothing, it
        // did not consume or clear them.
        $this->assertCount(2, $wpdb->last_result);
    }

    /** `$output` and `$y` still work on the ORDINARY (SQL) form. */
    public function testGetRowHonoursOutputAndRowIndexWithSql(): void
    {
        global $wpdb;

        $obj = $wpdb->get_row(self::TWO_BY_TWO);
        $this->assertIsObject($obj);
        $this->assertSame('1', (string) $obj->a);

        $assoc = $wpdb->get_row(self::TWO_BY_TWO, 'ARRAY_A', 1);
        $this->assertIsArray($assoc);
        $this->assertSame(['a', 'b'], array_keys($assoc));
        $this->assertSame('2', (string) $assoc['a']);
        $this->assertSame('y', (string) $assoc['b']);

        $this->assertNull($wpdb->get_row(self::TWO_BY_TWO, 'OBJECT', 99));
    }

    /**
     * ⚠⚠ ALSO INVERTED, same reason as `get_row(null)` above:
     * `wpdb::get_results()` returns NULL for the null form. The previous
     * revision asserted it projected every output shape, which made the
     * double more capable than production.
     */
    public function testGetResultsNullReturnsNullAndNeverReadsLastResult(): void
    {
        global $wpdb;

        $wpdb->query(self::TWO_BY_TWO);
        $this->assertCount(2, $wpdb->last_result, 'precondition: rows ARE available');

        $this->assertNull($wpdb->get_results(null));
        $this->assertNull($wpdb->get_results(null, 'ARRAY_A'));
        $this->assertNull($wpdb->get_results(null, 'ARRAY_N'));

        $this->assertCount(2, $wpdb->last_result);
    }

    /** Every output shape still works on the ORDINARY (SQL) form. */
    public function testGetResultsProjectsEveryShapeWithSql(): void
    {
        global $wpdb;

        $this->assertSame(
            [['1', 'x'], ['2', 'y']],
            array_map(
                static fn(array $r): array => array_map('strval', $r),
                $wpdb->get_results(self::TWO_BY_TWO, 'ARRAY_N') ?? []
            )
        );
        $this->assertSame(
            [['a' => '1', 'b' => 'x'], ['a' => '2', 'b' => 'y']],
            array_map(
                static fn(array $r): array => array_map('strval', $r),
                $wpdb->get_results(self::TWO_BY_TWO, 'ARRAY_A') ?? []
            )
        );
        $this->assertContainsOnly('object', $wpdb->get_results(self::TWO_BY_TWO) ?? []);
    }

    /**
     * ⚠⚠ THE STALENESS CASE, and it is the one with teeth. Real `wpdb`
     * flushes `last_result` at the START of every query, so a failed or
     * non-SELECT query cannot leave the PREVIOUS query's rows readable.
     * Without that, a caller whose query failed and who then read
     * `get_col(null)` would silently receive the last successful query's
     * rows — and in `PeepSoGroupRepository` those rows are an EXCLUSION
     * LIST, so stale data there means excluding the wrong groups.
     */
    public function testANonSelectAndAFailedQueryBothClearLastResult(): void
    {
        global $wpdb;

        $wpdb->query(self::TWO_BY_TWO);
        $this->assertCount(2, $wpdb->last_result, 'precondition');

        // A statement that returns no result set.
        $wpdb->query('DO 1');
        $this->assertSame([], $wpdb->last_result, 'A non-SELECT must not leave the SELECT rows readable.');
        $this->assertSame([], $wpdb->get_col(null));

        $wpdb->query(self::TWO_BY_TWO);
        $this->assertCount(2, $wpdb->last_result, 'precondition');

        // A broken statement.
        $this->assertFalse($wpdb->query('SELECT * FROM a_table_that_does_not_exist_bcc'));
        $this->assertNotSame('', $wpdb->last_error);
        $this->assertSame([], $wpdb->last_result, 'A FAILED query must not leave the previous rows readable.');
        $this->assertSame([], $wpdb->get_col(null));
    }

    /**
     * ⚠⚠ THE OTHER STALENESS DOOR, and `query()` alone does not close
     * it. `get_results($sql)` and `get_var($sql)` reach their own
     * failure branches WITHOUT going through `query()` — an empty query
     * string, an injected fault, or a mysqli error — so each has to
     * flush `last_result` itself. Real `wpdb` cannot have this bug: every
     * one of its read paths goes through `query()`, which calls `flush()`
     * before anything can fail.
     *
     * Without the flush, a caller whose read FAILED and who then reads
     * `get_col(null)` silently receives the last SUCCESSFUL query's rows.
     * At the bcc-core call site those rows are an exclusion list.
     */
    public function testAFailedGetResultsClearsLastResult(): void
    {
        global $wpdb;

        $wpdb->query(self::TWO_BY_TWO);
        $this->assertCount(2, $wpdb->last_result, 'precondition');

        $this->assertSame([], $wpdb->get_results('SELECT * FROM a_table_that_does_not_exist_bcc'));
        $this->assertNotSame('', $wpdb->last_error);
        $this->assertSame([], $wpdb->last_result, 'A failed get_results must not leave the previous rows readable.');
        $this->assertSame([], $wpdb->get_col(null));
    }

    /** The empty-query branch is a separate early return, so it needs its own case. */
    public function testAnEmptyQueryToGetResultsClearsLastResult(): void
    {
        global $wpdb;

        $wpdb->query(self::TWO_BY_TWO);
        $this->assertCount(2, $wpdb->last_result, 'precondition');

        $this->assertSame([], $wpdb->get_results('   '));
        $this->assertSame([], $wpdb->last_result);
        $this->assertSame([], $wpdb->get_col(null));
    }

    public function testAFailedGetVarClearsLastResult(): void
    {
        global $wpdb;

        $wpdb->query(self::TWO_BY_TWO);
        $this->assertCount(2, $wpdb->last_result, 'precondition');

        $this->assertNull($wpdb->get_var('SELECT * FROM a_table_that_does_not_exist_bcc'));
        $this->assertNotSame('', $wpdb->last_error);
        $this->assertSame([], $wpdb->last_result, 'A failed get_var must not leave the previous rows readable.');
        $this->assertSame([], $wpdb->get_col(null));
    }

    /**
     * ⚠ And a SUCCESSFUL single-row `get_var($sql)` must leave exactly
     * that one row — not the previous query's two. Otherwise the fast
     * path is its own staleness door.
     */
    public function testASuccessfulGetVarReplacesLastResultWithItsOwnRow(): void
    {
        global $wpdb;

        $wpdb->query(self::TWO_BY_TWO);
        $this->assertCount(2, $wpdb->last_result, 'precondition');

        $this->assertSame('7', (string) $wpdb->get_var('SELECT 7 AS only_col'));
        $this->assertCount(1, $wpdb->last_result);
        $this->assertSame(['7'], array_map('strval', $wpdb->get_col(null)));
    }

    /**
     * ⚠ Anti-vacuity: every assertion above would also hold for a double
     * whose `get_*` always returned empty. A SELECT passed as SQL must
     * still work normally and must still count a query.
     */
    public function testTheOrdinarySqlFormStillWorksAndStillCountsAQuery(): void
    {
        global $wpdb;

        $before = $wpdb->queryCount;

        $this->assertSame(['1', '2'], array_map('strval', $wpdb->get_col(self::TWO_BY_TWO)));
        $this->assertGreaterThan($before, $wpdb->queryCount, 'Passing SQL must issue a query.');

        $this->assertSame('1', (string) $wpdb->get_var(self::TWO_BY_TWO));
        $this->assertSame(
            ['a' => '1', 'b' => 'x'],
            array_map('strval', (array) $wpdb->get_row(self::TWO_BY_TWO, 'ARRAY_A'))
        );
        $this->assertNotNull($wpdb->get_results(self::TWO_BY_TWO));
    }
}
