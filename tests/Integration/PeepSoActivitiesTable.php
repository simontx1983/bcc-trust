<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use PHPUnit\Framework\Assert;

/**
 * The ONE harness definition of PeepSo's `peepso_activities` table.
 *
 * The integration bootstrap does not ship PeepSo's tables, so suites that
 * read activities build this one themselves. They used to inline their own
 * CREATE TABLE, each with a different column set, and whichever suite ran
 * first decided what every later suite saw. When bcc-core #137 widened
 * `PeepSoActivityRepository::COLUMNS` with `act_repost_id`, every activity
 * read against those copies failed on an unknown column. Callers that pass
 * nothing read that as "no rows", so feeds, blogs and notices came back
 * EMPTY rather than erroring.
 *
 * ⭐ ensure() always leaves the full column set:
 *   - absent table → created with every column below;
 *   - present table (another suite built it, possibly from an older
 *     definition) → only the columns it lacks are ADDED. Same idiom
 *     FeedActivityFixture already uses for the shared wp_posts.
 *
 * ⚠ Adding a column here is how a suite gets a new PeepSo column. Do not
 * ALTER the table from a test. PeepSoActivitiesHarnessSchemaIntegrationTest
 * fails when bcc-core reads an activity column this list lacks, so the next
 * COLUMNS widening fails loudly instead of emptying feeds.
 *
 * Columns mirror the real PeepSo schema for what BCC reads. Defaults match
 * PeepSo's: a non-repost stores 0 in act_repost_id, and a non-comment
 * stores 0 in the two comment columns.
 */
final class PeepSoActivitiesTable
{
    /** @var array<string, string> column => definition (act_id is the primary key) */
    public const COLUMNS = [
        'act_id'                => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'act_owner_id'          => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'act_module_id'         => "VARCHAR(20) NOT NULL DEFAULT '0'",
        'act_external_id'       => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'act_access'            => 'INT NOT NULL DEFAULT 10',
        'act_repost_id'         => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'act_comment_object_id' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'act_comment_module_id' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
    ];

    public static function name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'peepso_activities';
    }

    /**
     * Create the table, or add whatever columns an existing copy lacks.
     *
     * @return bool true when THIS call created the table. The caller then
     *              owns the DROP, under the same "drop only what you created"
     *              rule every fixture here follows.
     */
    public static function ensure(): bool
    {
        global $wpdb;

        $table = self::name();
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            $definitions = [];
            foreach (self::COLUMNS as $column => $definition) {
                $definitions[] = "{$column} {$definition}";
            }
            $definitions[] = 'PRIMARY KEY (act_id)';
            Assert::assertNotFalse(
                $wpdb->query("CREATE TABLE `{$table}` (" . implode(', ', $definitions) . ') DEFAULT CHARSET=utf8mb4'),
                'create peepso_activities: ' . $wpdb->last_error
            );

            return true;
        }

        $existing = self::existingColumns();
        $adds     = [];
        foreach (self::COLUMNS as $column => $definition) {
            // act_id is the key. A copy without it is not a table this helper can repair.
            if ($column !== 'act_id' && !in_array($column, $existing, true)) {
                $adds[] = "ADD COLUMN {$column} {$definition}";
            }
        }
        if ($adds !== []) {
            Assert::assertNotFalse(
                $wpdb->query("ALTER TABLE `{$table}` " . implode(', ', $adds)),
                'widen peepso_activities: ' . $wpdb->last_error
            );
        }

        return false;
    }

    /** @return list<string> the columns the live harness table has now */
    public static function existingColumns(): array
    {
        global $wpdb;

        $wpdb->last_error = '';
        $columns = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            self::name()
        ));
        // ⚠ get_col() answers [] on a failed query, never null. Without this
        // check a failed lookup reads as "no columns", and ensure() would try
        // to ADD columns that already exist.
        Assert::assertSame('', (string) $wpdb->last_error, 'read peepso_activities columns: ' . $wpdb->last_error);

        return array_values(array_map('strval', $columns));
    }
}
