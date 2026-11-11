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

    public function testGetRowNullHonoursOutputAndRowIndex(): void
    {
        global $wpdb;

        $wpdb->query(self::TWO_BY_TWO);

        $obj = $wpdb->get_row(null);
        $this->assertIsObject($obj);
        $this->assertSame('1', (string) $obj->a);

        $assoc = $wpdb->get_row(null, 'ARRAY_A', 1);
        $this->assertIsArray($assoc);
        $this->assertSame(['a', 'b'], array_keys($assoc));
        $this->assertSame('2', (string) $assoc['a']);
        $this->assertSame('y', (string) $assoc['b']);

        $this->assertNull($wpdb->get_row(null, 'OBJECT', 99));
    }

    public function testGetResultsNullProjectsEveryShape(): void
    {
        global $wpdb;

        $wpdb->query(self::TWO_BY_TWO);

        $this->assertSame(
            [['1', 'x'], ['2', 'y']],
            array_map(static fn(array $r): array => array_map('strval', $r), $wpdb->get_results(null, 'ARRAY_N'))
        );
        $this->assertSame(
            [['a' => '1', 'b' => 'x'], ['a' => '2', 'b' => 'y']],
            array_map(static fn(array $r): array => array_map('strval', $r), $wpdb->get_results(null, 'ARRAY_A'))
        );
        $this->assertContainsOnly('object', $wpdb->get_results(null));
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
    }
}
