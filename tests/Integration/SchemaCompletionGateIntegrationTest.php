<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\CollectionRepository;
use PHPUnit\Framework\TestCase;

/**
 * A migration that did not finish must not be recorded as one that did.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 * `bcc_trust_schema_version` is the ONLY thing deciding whether the
 * installer runs again:
 *
 *     stored === computed  ->  do nothing
 *     otherwise            ->  run every installer, then stamp
 *
 * and the stamp used to be unconditional. `bcc_onchain_ensure_schema()`
 * returned void, so a migration that bailed — an unreadable probe, a refused
 * ALTER, a backfill whose postcondition would not verify — was still
 * followed by a stamp saying the schema was current. The next request found
 * `stored === computed` and never tried again. The migration's "resumable"
 * claim was true only if something bumped the version again, and nothing
 * would: the version is a content hash of files that had not changed.
 *
 * ── WHY THIS IS AN INTEGRATION TEST ─────────────────────────────────────
 * Every claim is about what a real server does when a statement fails
 * mid-migration: whether a failed `INFORMATION_SCHEMA` probe is
 * distinguishable from a genuine zero, whether a refused `ALTER` is caught
 * by `=== false` rather than by falsiness (a successful DDL returns `0`),
 * and whether the column is still there afterwards. A `$wpdb` double that
 * answers from a queue regardless of the SQL can demonstrate none of it.
 *
 * Faults are injected by regex against the SQL text, so each case can break
 * exactly ONE statement and leave the rest of the migration working — which
 * is what makes "this step reported incomplete" a real observation instead
 * of "nothing worked".
 */
final class SchemaCompletionGateIntegrationTest extends TestCase
{
    private const OPTION = 'bcc_trust_schema_version';
    private const INDEX  = 'idx_provisioning_state_id';

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb']->clearFaultInjection();
        $GLOBALS['__bcc_test_options_frozen'] = false;
        $GLOBALS['__bcc_test_options'][self::OPTION] = 'BASELINE';
        \BCC\Core\Log\Logger::reset();
    }

    protected function tearDown(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        // Clear the fault FIRST — the repair below runs real DDL.
        $wpdb->clearFaultInjection();
        $GLOBALS['__bcc_test_options_frozen'] = false;

        // Any case that removed a column or index puts it back through the
        // production migration itself, so the rest of the suite sees the
        // schema it expects and the repair path is exercised for free.
        bcc_onchain_add_collections_provisioning_state();

        unset($GLOBALS['__bcc_test_options'][self::OPTION]);

        parent::tearDown();
    }

    private function storedVersion(): string
    {
        return (string) get_option(self::OPTION, '');
    }

    private function columnExists(string $name): bool
    {
        $wpdb = $GLOBALS['wpdb'];

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            CollectionRepository::table(),
            $name
        )) > 0;
    }

    private function indexExists(): bool
    {
        $wpdb = $GLOBALS['wpdb'];

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            CollectionRepository::table(),
            self::INDEX
        )) > 0;
    }

    /** @return list<string> the error lines the run produced */
    private function errorLines(): array
    {
        return array_map(
            static fn (array $l): string => (string) $l['message'],
            array_values(array_filter(
                \BCC\Core\Log\Logger::$lines,
                static fn (array $l): bool => $l['level'] === 'error'
            ))
        );
    }

    // ── Case 1: an unreadable probe ─────────────────────────────────────

    /**
     * A failed column probe is UNVERIFIED, never "absent" and never "done".
     *
     * This is the one that matters most. `bcc_onchain_probe_count()` returns
     * null on failure precisely so a broken `COUNT(*)` cannot be read as a
     * genuine zero — because a zero here means "the column is missing", and
     * acting on that would run an ALTER against a column that already
     * exists, or (worse, one layer up) report the schema complete.
     */
    public function testAFailedColumnProbeReportsIncompleteAndDoesNotStamp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = '/INFORMATION_SCHEMA\.COLUMNS/i';

        $complete = bcc_onchain_add_collections_provisioning_state();

        self::assertFalse($complete, 'an unverifiable probe cannot report completion');
        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');

        $stamped = bcc_trust_stamp_schema_version($complete, 'NEW-VERSION');

        self::assertFalse($stamped);
        self::assertSame('BASELINE', $this->storedVersion(), 'the version must not advance');

        $lines = $this->errorLines();
        self::assertNotSame([], $lines);
        self::assertStringContainsString('UNVERIFIED, not absent', implode("\n", $lines));
    }

    /**
     * …and the probe failure was NOT acted on as though the column were
     * gone. Clearing the fault and looking directly is the only way to know
     * the migration did not "repair" something that was never broken.
     */
    public function testAFailedProbeLeavesTheSchemaUntouched(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = '/INFORMATION_SCHEMA\.COLUMNS/i';

        bcc_onchain_add_collections_provisioning_state();

        $wpdb->clearFaultInjection();

        foreach (['provisioning_state', 'provisioning_requested_at',
                  'provisioning_requested_by', 'provisioning_failure_code'] as $column) {
            self::assertTrue($this->columnExists($column), $column . ' must still be there');
        }
    }

    /** The index probe is a separate step and reports separately. */
    public function testAFailedIndexProbeReportsIncompleteAndDoesNotStamp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = '/INFORMATION_SCHEMA\.STATISTICS/i';

        $complete = bcc_onchain_add_collections_provisioning_state();

        self::assertFalse($complete);
        self::assertFalse(bcc_trust_stamp_schema_version($complete, 'NEW-VERSION'));
        self::assertSame('BASELINE', $this->storedVersion());
    }

    // ── Case 2: a refused ALTER ─────────────────────────────────────────

    /**
     * A refused `ALTER` reports incomplete — and is detected by `=== false`,
     * not by falsiness.
     *
     * ⚠ `wpdb::query()` returns **`0`** for a successful DDL statement. A
     * `if (!$result)` check would therefore treat every successful ALTER in
     * this migration as a failure. The index is genuinely dropped here so
     * the ALTER is genuinely attempted; anything less would be asserting
     * against a branch that never runs.
     */
    public function testARefusedAlterReportsIncompleteAndDoesNotStamp(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $wpdb->query("ALTER TABLE `{$table}` DROP KEY " . self::INDEX);
        self::assertFalse($this->indexExists(), 'the index must really be gone for this to test anything');

        $wpdb->failQueriesMatching = '/ALTER TABLE .* ADD KEY/i';

        $complete = bcc_onchain_add_collections_provisioning_state();

        self::assertFalse($complete);
        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the ALTER must actually have been attempted');
        self::assertFalse(bcc_trust_stamp_schema_version($complete, 'NEW-VERSION'));
        self::assertSame('BASELINE', $this->storedVersion());

        $wpdb->clearFaultInjection();
        self::assertFalse($this->indexExists(), 'a refused ALTER must not be reported as applied');
    }

    /** And the same run, unobstructed, does complete — so the fault, not the code, was the cause. */
    public function testTheSameMigrationCompletesOnceTheFaultIsCleared(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $wpdb->query("ALTER TABLE `{$table}` DROP KEY " . self::INDEX);
        $wpdb->failQueriesMatching = '/ALTER TABLE .* ADD KEY/i';
        self::assertFalse(bcc_onchain_add_collections_provisioning_state());

        $wpdb->clearFaultInjection();

        self::assertTrue(
            bcc_onchain_add_collections_provisioning_state(),
            'the migration is resumable: the next pass finishes the job'
        );
        self::assertTrue($this->indexExists());
        self::assertTrue(bcc_trust_stamp_schema_version(true, 'NEW-VERSION'));
        self::assertSame('NEW-VERSION', $this->storedVersion());
    }

    // ── Case 3: the backfill ────────────────────────────────────────────

    /**
     * A backfill that could not WALK the table aborts without writing.
     *
     * `get_results()` hands back an empty array for a failed query exactly
     * as it does for a genuine no-rows result, so an empty batch alone is
     * not proof the walk finished.
     */
    public function testAFailedBackfillWalkReportsIncompleteAndDoesNotStamp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = '/ORDER BY pm_coll\.post_id ASC/i';

        $complete = bcc_onchain_add_collections_provisioning_state();

        self::assertFalse($complete);
        self::assertGreaterThan(0, $wpdb->injectedFailures);
        self::assertFalse(bcc_trust_stamp_schema_version($complete, 'NEW-VERSION'));
        self::assertSame('BASELINE', $this->storedVersion());
        self::assertStringContainsString('backfill read failed', implode("\n", $this->errorLines()));
    }

    /**
     * A postcondition that could not be VERIFIED is incomplete — it is not
     * quietly assumed to hold.
     */
    public function testAnUnverifiablePostconditionReportsIncompleteAndDoesNotStamp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = '/NOT EXISTS/i';

        $complete = bcc_onchain_add_collections_provisioning_state();

        self::assertFalse($complete);
        self::assertGreaterThan(0, $wpdb->injectedFailures);
        self::assertFalse(bcc_trust_stamp_schema_version($complete, 'NEW-VERSION'));
        self::assertSame('BASELINE', $this->storedVersion());
        self::assertStringContainsString(
            'could not verify the backfill postcondition',
            implode("\n", $this->errorLines())
        );
    }

    // ── Case 4: the clean path ──────────────────────────────────────────

    /**
     * The gate is not simply "never stamp". A clean run stamps, and a second
     * pass is a cheap idempotent no-op — which is the whole reason declining
     * to stamp is a safe response rather than an outage.
     */
    public function testACleanRunStampsAndIsIdempotent(): void
    {
        self::assertTrue(bcc_onchain_add_collections_provisioning_state());
        self::assertTrue(bcc_trust_stamp_schema_version(true, 'NEW-VERSION'));
        self::assertSame('NEW-VERSION', $this->storedVersion());

        self::assertTrue(
            bcc_onchain_add_collections_provisioning_state(),
            'every step is probe-guarded, so re-running changes nothing'
        );
        self::assertTrue(bcc_trust_stamp_schema_version(true, 'NEW-VERSION'));
        self::assertSame('NEW-VERSION', $this->storedVersion());
    }

    // ── Case 5: the write that did not stick ────────────────────────────

    /**
     * `update_option` returning true is not proof the next request will read
     * the new value.
     *
     * A stale persistent object cache makes the write succeed while
     * `get_option` keeps serving the old value. Without the re-read this
     * gate would report the version advanced, every request would keep
     * finding `stored !== computed`, and the installer would run forever —
     * a permanent per-request tax that nothing reports.
     */
    public function testAStampThatDoesNotStickIsReportedAsNotAdvanced(): void
    {
        $GLOBALS['__bcc_test_options_frozen'] = true;

        $stamped = bcc_trust_stamp_schema_version(true, 'NEW-VERSION');

        self::assertFalse($stamped, 'a write that cannot be read back has not advanced anything');
        self::assertSame('BASELINE', $this->storedVersion());
        self::assertStringContainsString('did not stick', implode("\n", $this->errorLines()));
    }

    /**
     * The refusal is logged with the computed version and nothing else.
     * No paths, no SQL, no provider text — the same bounded-evidence rule
     * the rest of PR 6 follows.
     */
    public function testTheRefusalLogsTheVersionAndNoRawDetail(): void
    {
        bcc_trust_stamp_schema_version(false, 'NEW-VERSION');

        $errors = array_values(array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn (array $l): bool => $l['level'] === 'error'
        ));

        self::assertCount(1, $errors);
        self::assertSame(['computed' => 'NEW-VERSION'], $errors[0]['context']);
    }
}
