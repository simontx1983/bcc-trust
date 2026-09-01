<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\AuditLogRepository;
use BCC\Trust\Core\Security\AuditMeta;
use PHPUnit\Framework\TestCase;

/**
 * Durable audit metadata, against a REAL MySQL.
 *
 * The unit suite cannot observe any of this: its `$wpdb` double returns
 * queued fixtures regardless of query text, so a claim about a column
 * existing, a NULL round-tripping, or an INSERT…SELECT carrying a column is
 * meaningless there.
 *
 * The headline case is {@see testArchiveBatchCarriesMetaAcrossTheNinetyDayBoundary}.
 * `archiveBatch()` copies an EXPLICIT column list, and the archive table is
 * created `LIKE` the live table — structure copied once, at creation. Get
 * either of those wrong and metadata works perfectly for ninety days and then
 * disappears, which is the worst possible shape for a data-loss bug: invisible
 * in every test that does not wind the clock forward.
 */
final class AuditMetaPersistenceIntegrationTest extends TestCase
{
    private const COLUMN = 'meta';

    private static string $activity = '';
    private static string $archive  = '';

    public static function setUpBeforeClass(): void
    {
        self::$activity = TableRegistry::activity();
        self::$archive  = TableRegistry::activityArchive();
    }

    protected function tearDown(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query("DELETE FROM `" . self::$activity . "` WHERE action LIKE 'itest\\_%'");
        $wpdb->query("DELETE FROM `" . self::$archive . "` WHERE action LIKE 'itest\\_%'");
    }

    // ---------------------------------------------------------------
    // Schema — BOTH tables, which is the whole point
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function bothTables(): array
    {
        return [
            'live activity table' => ['activity'],
            'archive table'       => ['archive'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothTables')]
    public function testMetaColumnExistsOnBothTables(string $which): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = $which === 'activity' ? self::$activity : self::$archive;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT DATA_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table,
            self::COLUMN
        ));

        self::assertNotNull(
            $row,
            "`meta` is missing from {$table}. The archive is created with CREATE TABLE … LIKE, "
            . 'which copies structure ONCE at creation — it does not inherit later columns, so it '
            . 'needs its own explicit ALTER.'
        );
        self::assertSame('longtext', strtolower((string) $row->DATA_TYPE));
        self::assertSame('YES', strtoupper((string) $row->IS_NULLABLE), 'meta must be nullable — NULL means "no metadata"');
    }

    /**
     * The bootstrap installs a FRESH schema, so the tests above only prove the
     * CREATE TABLE path. Production has neither table fresh — every environment
     * runs the ALTER path instead. Drop the column and make the migration earn
     * it back, on both tables, twice.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothTables')]
    public function testMigrationAddsTheColumnToAnExistingTableAndIsIdempotent(string $which): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = $which === 'activity' ? self::$activity : self::$archive;

        $present = static function () use ($wpdb, $table): bool {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'meta'",
                $table
            )) > 0;
        };

        $wpdb->query('ALTER TABLE `' . $table . '` DROP COLUMN meta');
        self::assertFalse($present(), 'precondition: the column really was dropped');

        self::assertTrue(bcc_trust_add_activity_meta_column($table), 'migration reports success');
        self::assertTrue($present(), 'migration actually added the column');

        // Second pass must be a clean no-op, not an error — a deploy can run
        // the schema path more than once.
        self::assertTrue(bcc_trust_add_activity_meta_column($table), 'second pass is idempotent');
        self::assertTrue($present());
    }

    public function testMigrationReportsFalseForATableThatDoesNotExist(): void
    {
        // An absent table is not drift and must not be reported as success.
        // (The archive legitimately may not exist on a young environment.)
        self::assertFalse(bcc_trust_add_activity_meta_column('wp_bcc_definitely_not_a_table'));
    }

    // ---------------------------------------------------------------
    // Round trip
    // ---------------------------------------------------------------

    public function testMetaRoundTripsThroughARealInsert(): void
    {
        $repo = new AuditLogRepository();

        $encoded = AuditMeta::encode(['chain_slug' => 'solana', 'collection_id' => 100]);
        self::assertIsString($encoded['json']);

        $id = $repo->insertLogReturningId(
            $this->row('itest_roundtrip', $encoded['json']),
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        self::assertNotNull($id, 'insert must report a usable id');

        $wpdb   = $GLOBALS['wpdb'];
        $stored = $wpdb->get_var($wpdb->prepare(
            'SELECT meta FROM `' . self::$activity . '` WHERE id = %d',
            $id
        ));

        self::assertSame($encoded['json'], $stored);
        $decoded = json_decode((string) $stored, true);
        self::assertSame('solana', $decoded['chain_slug']);
    }

    public function testAbsentMetaIsStoredAsRealNullNotAnEmptyArrayLiteral(): void
    {
        $repo = new AuditLogRepository();

        $encoded = AuditMeta::encode([]);
        self::assertNull($encoded['json']);

        $id = $repo->insertLogReturningId(
            $this->row('itest_nullmeta', null),
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
        self::assertNotNull($id);

        $wpdb = $GLOBALS['wpdb'];

        // Asserted in SQL, not in PHP: `IS NULL` cannot be satisfied by the
        // string '[]' or '', which is exactly the confusion being ruled out.
        $isNull = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT meta IS NULL FROM `' . self::$activity . '` WHERE id = %d',
            $id
        ));

        self::assertSame(1, $isNull, '"no metadata" must be SQL NULL, distinguishable from an empty payload');
    }

    // ---------------------------------------------------------------
    // The regression this requirement exists to prevent
    // ---------------------------------------------------------------

    public function testArchiveBatchCarriesMetaAcrossTheNinetyDayBoundary(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $repo = new AuditLogRepository();

        $encoded = AuditMeta::encode(['run_id' => 'itest-run-1', 'before' => null, 'after' => 'x']);
        self::assertIsString($encoded['json']);

        $id = $repo->insertLogReturningId(
            $this->row('itest_archive', $encoded['json']),
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
        self::assertNotNull($id);

        // Wind this row past the retention boundary. archiveBatch() selects on
        // created_at < NOW() - 90 days, so without this the row is simply not
        // eligible and the test would pass while proving nothing.
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . self::$activity . '` SET created_at = DATE_SUB(NOW(), INTERVAL 200 DAY) WHERE id = %d',
            $id
        ));

        $copied = $repo->archiveBatch(500);

        self::assertGreaterThanOrEqual(1, $copied, 'the aged row should have been archived');

        $archivedMeta = $wpdb->get_var($wpdb->prepare(
            'SELECT meta FROM `' . self::$archive . '` WHERE id = %d',
            $id
        ));

        self::assertSame(
            $encoded['json'],
            $archivedMeta,
            'archiveBatch() copies an EXPLICIT column list; if `meta` is missing from either side of that '
            . 'list the metadata is silently dropped at the 90-day boundary.'
        );

        // And the original is gone from the live table, as archiving intends.
        $stillLive = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM `' . self::$activity . '` WHERE id = %d',
            $id
        ));
        self::assertSame(0, $stillLive);
    }

    public function testArchiveBatchPreservesANullMetaAsNull(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $repo = new AuditLogRepository();

        $id = $repo->insertLogReturningId(
            $this->row('itest_archivenull', null),
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
        self::assertNotNull($id);

        $wpdb->query($wpdb->prepare(
            'UPDATE `' . self::$activity . '` SET created_at = DATE_SUB(NOW(), INTERVAL 200 DAY) WHERE id = %d',
            $id
        ));

        $repo->archiveBatch(500);

        $isNull = $wpdb->get_var($wpdb->prepare(
            'SELECT meta IS NULL FROM `' . self::$archive . '` WHERE id = %d',
            $id
        ));

        self::assertSame(1, (int) $isNull, 'a NULL meta must archive as NULL, not as the string "NULL"');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $action, ?string $meta): array
    {
        return [
            'user_id'     => 1,
            'action'      => $action,
            'target_type' => 'itest',
            'target_id'   => 1,
            'ip_address'  => null,
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'meta'        => $meta,
        ];
    }
}
