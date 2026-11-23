<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\PostShortcodeRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * An unreadable table is not a missing post (F-472, completing F-438).
 *
 * ## The gap this closes
 *
 * `resolveActId()` already read its LOOKUP through
 * `runRead(..., failClosed: true)`, so a lookup that did not run threw and
 * the endpoint answered its retryable 503. The TABLE CHECK before it did
 * not have that property, and nothing could see it:
 *
 *   - `TableRegistry::exists()` is `existsOrUnknown() === true`, and
 *     `existsOrUnknown()` CATCHES `RepositoryReadFailure` and answers
 *     null. So a SHOW TABLES that did not run arrived as "no such table";
 *   - the `guardReadOrThrow()` meant to catch that inspects
 *     `$wpdb->last_error`, and a pre-flush bail never sets one —
 *     `wpdb::query()` returns false above `flush()`, records no error and
 *     leaves the previous statement's rows in `last_result`.
 *
 * So the method returned null, and the endpoint answered **404, "this post
 * does not exist"**, for a database that was merely unreadable. That is
 * precisely the confusion this method's own F-438 docblock claims to rule
 * out — true of the lookup, not yet true of the check in front of it.
 *
 * It now asks for the three-state answer and refuses on null. There is no
 * error string to read, so nothing less would do.
 *
 * ## Why one test asserts on what was NOT sent
 *
 * On a table-check bail the lookup must never run. Asserting only on the
 * throw would pass for code that read first and threw afterwards, which
 * would still have touched a table it could not trust.
 */
#[Group('unit')]
#[CoversClass(PostShortcodeRepository::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ShortcodeTableCheckTest extends TestCase
{
    private const SHOW_TABLES = 'SHOW TABLES LIKE';
    private const LOOKUP      = 'SELECT act_id';
    private const CODE        = 'abcdefgh';

    private \BccShortcodeTableCheckFakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../Stubs/shortcode-table-check-stubs.php';

        $GLOBALS['__bcc_psc_log'] = [];
        $this->wpdb               = new \BccShortcodeTableCheckFakeWpdb();
        $GLOBALS['wpdb']          = $this->wpdb;
    }

    /**
     * ⭐ The fix. A probe that did not run is UNKNOWN, never "missing".
     */
    public function testATableProbeThatDidNotRunIsRefused(): void
    {
        // The premise: some earlier statement left rows behind, and the
        // bail does not clear them. Without this the old code would also
        // have produced nothing and this test would pass against the
        // defect it exists to pin.
        $this->wpdb->last_result = [(object) ['act_id' => '999']];
        $this->wpdb->bailOn      = self::SHOW_TABLES;

        try {
            (new PostShortcodeRepository())->resolveActId(self::CODE);
            self::fail('an unreadable table was reported as a missing post');
        } catch (RepositoryReadFailure) {
            // expected
        }

        self::assertSame('', $this->wpdb->last_error, 'premise: the bail records no error');
        self::assertNotSame([], $this->wpdb->last_result, 'premise: stale rows were left readable');

        // And it refused BEFORE reading a table it could not trust.
        self::assertSame(
            [],
            $this->wpdb->sentMatching(self::LOOKUP),
            'the lookup ran against a table whose existence was never established'
        );
    }

    /**
     * The control that keeps the fix honest: a table that genuinely is not
     * there is still an ordinary "no such code", answered as null, with no
     * throw. A change that refused on `false` as well as on null would
     * pass the test above and turn every missing-table install into a 503.
     */
    public function testAGenuinelyMissingTableIsStillJustNoSuchCode(): void
    {
        // The probe runs and matches nothing.
        self::assertNull((new PostShortcodeRepository())->resolveActId(self::CODE));

        self::assertNotSame([], $this->wpdb->sentMatching(self::SHOW_TABLES), 'the probe never ran');
        self::assertSame([], $this->wpdb->sentMatching(self::LOOKUP), 'it read a table that is not there');
        self::assertSame([], $GLOBALS['__bcc_psc_log'], 'a missing table logged a read failure');
    }

    /** With the table present, the lookup answers as before. */
    public function testAPresentTableResolvesTheCode(): void
    {
        $this->seedTable();
        $this->wpdb->answers[self::LOOKUP] = [(object) ['act_id' => '42']];

        self::assertSame(42, (new PostShortcodeRepository())->resolveActId(self::CODE));
    }

    /** And an unknown code against a present table is null, not a throw. */
    public function testAPresentTableWithNoMatchingRowAnswersNull(): void
    {
        $this->seedTable();

        self::assertNull((new PostShortcodeRepository())->resolveActId(self::CODE));
        self::assertNotSame([], $this->wpdb->sentMatching(self::LOOKUP), 'the lookup never ran');
    }

    /**
     * The sibling half, already true before this change and pinned here so
     * the two cannot drift: a LOOKUP that bails is refused too.
     */
    public function testALookupThatDidNotRunIsRefused(): void
    {
        $this->seedTable();
        $this->wpdb->bailOn = self::LOOKUP;

        $this->expectException(RepositoryReadFailure::class);
        (new PostShortcodeRepository())->resolveActId(self::CODE);
    }

    /** A malformed code is rejected without touching the database at all. */
    public function testAMalformedCodeNeverReachesTheDatabase(): void
    {
        self::assertNull((new PostShortcodeRepository())->resolveActId('nope'));
        self::assertSame([], $this->wpdb->sent, 'a malformed code reached the database');
    }

    /**
     * Make the SHOW TABLES probe report the table as present.
     *
     * `existsOrUnknown()` accepts a row whose VALUES contain the table
     * name — LIKE treats `_` as a wildcard, so it checks every column of
     * every row rather than just the first.
     */
    private function seedTable(): void
    {
        $this->wpdb->answers[self::SHOW_TABLES] = [
            (object) ['Tables_in_db' => 'wp_bcc_post_shortcodes'],
        ];
    }
}
