<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Contracts\ActivityReadFailedException;
use BCC\Core\Repositories\PeepSoActivityRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FeedActivityFixture.php';

/**
 * Guard: the harness copy of `peepso_activities` covers every activity
 * column bcc-core reads.
 *
 * Why it exists: bcc-core #137 widened `PeepSoActivityRepository::COLUMNS`
 * with `act_repost_id`. The harness tables lacked it, so every activity read
 * failed on an unknown column, and callers that pass nothing read that as
 * "no rows". Trust's integration jobs went red with feeds, blogs and notices
 * coming back EMPTY, which looks like a visibility bug, not a fixture gap.
 * This suite turns the next widening into one named failure here.
 *
 *   - The column check reads bcc-core's real source: the COLUMNS constant
 *     plus every `a.act_*` its SQL names (WHERE clauses too, e.g. the
 *     comment-row filter on `a.act_comment_object_id`).
 *   - The read check runs bcc-core's real row read and feed read against the
 *     harness table, with the F-275 flag on, so a failed read throws instead
 *     of passing as an empty result.
 *   - A control proves the read check bites: it drops one column, and the
 *     same read must throw.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversNothing]
final class PeepSoActivitiesHarnessSchemaIntegrationTest extends TestCase
{
    use FeedActivityFixture;

    private const ACT_ID  = 2751000001;
    private const POST_ID = 2751000001;
    private const AUTHOR  = 975101;

    protected function tearDown(): void
    {
        $this->removeFeedActivities();
        parent::tearDown();
    }

    public function testEveryActivityColumnBccCoreReadsIsInTheHarnessTable(): void
    {
        $read = self::activityColumnsBccCoreReads();

        // Vacuity guard: an empty or broken scan would pass the check below
        // for nothing. These two are the columns that have bitten so far.
        self::assertContains('act_repost_id', $read, 'the scan must see the column bcc-core #137 added');
        self::assertContains('act_comment_object_id', $read, 'the scan must see WHERE-clause columns too');

        $missing = array_values(array_diff($read, array_keys(PeepSoActivitiesTable::COLUMNS)));
        self::assertSame(
            [],
            $missing,
            'bcc-core reads activity columns the harness table lacks. Add each to PeepSoActivitiesTable::COLUMNS '
            . "with PeepSo's own type and default. Until then every activity read in the integration suites fails."
        );
    }

    public function testEnsureWidensACopyAnOlderFixtureBuilt(): void
    {
        global $wpdb;

        $table = PeepSoActivitiesTable::name();
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            self::markTestSkipped('peepso_activities already exists; this case must build the old copy itself');
        }
        // The five-column copy FeedActivityFixture used to build.
        self::assertNotFalse($wpdb->query(
            "CREATE TABLE `{$table}` (
                act_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                act_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                act_module_id VARCHAR(20) NOT NULL DEFAULT '0',
                act_external_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                act_access INT NOT NULL DEFAULT 10,
                PRIMARY KEY (act_id)
            ) DEFAULT CHARSET=utf8mb4"
        ), $wpdb->last_error);
        $this->activitiesWasOurs = true; // tearDown drops it

        self::assertFalse(PeepSoActivitiesTable::ensure(), 'an existing table is widened, never re-created');
        self::assertSame(
            [],
            array_values(array_diff(array_keys(PeepSoActivitiesTable::COLUMNS), PeepSoActivitiesTable::existingColumns())),
            'every harness column is present after ensure()'
        );
    }

    public function testBccCoresRowAndFeedReadsRunOnTheHarnessTable(): void
    {
        $this->seedFeedActivity(self::ACT_ID, self::POST_ID, '1', self::AUTHOR);

        $row = PeepSoActivityRepository::getById(self::ACT_ID, throwOnReadFailure: true);
        self::assertNotNull($row, 'the seeded activity reads back through bcc-core');
        self::assertSame(self::ACT_ID, (int) $row->act_id);

        // Throws ActivityReadFailedException if the SQL did not run.
        $feed = PeepSoActivityRepository::getActivities(authorIds: [self::AUTHOR], throwOnReadFailure: true);
        self::assertContains(
            self::ACT_ID,
            array_map(static fn (object $item): int => (int) $item->act_id, $feed),
            'the feed read runs AND finds the seeded post'
        );
    }

    public function testTheReadCheckFailsWhenAColumnBccCoreSelectsIsMissing(): void
    {
        global $wpdb;

        $this->seedFeedActivity(self::ACT_ID, self::POST_ID, '1', self::AUTHOR);
        if (!$this->activitiesWasOurs) {
            self::markTestSkipped('peepso_activities belongs to another suite; refusing to drop its column');
        }
        self::assertNotFalse(
            $wpdb->query('ALTER TABLE `' . PeepSoActivitiesTable::name() . '` DROP COLUMN act_repost_id'),
            $wpdb->last_error
        );

        $this->expectException(ActivityReadFailedException::class);
        PeepSoActivityRepository::getActivities(authorIds: [self::AUTHOR], throwOnReadFailure: true);
    }

    /** @return list<string> every `a.act_*` column bcc-core's activity repository reads */
    private static function activityColumnsBccCoreReads(): array
    {
        $class = new \ReflectionClass(PeepSoActivityRepository::class);
        $file  = str_replace('\\', '/', (string) $class->getFileName());
        // The REAL bcc-core class, not a unit-suite double.
        self::assertStringEndsWith('/src/Repositories/PeepSoActivityRepository.php', $file);
        self::assertStringContainsString('bcc-core', $file);

        $columns = $class->getConstant('COLUMNS');
        self::assertIsString($columns, 'PeepSoActivityRepository::COLUMNS moved; point this guard at its new home');
        self::assertStringContainsString('a.act_id', $columns);

        $source = (string) file_get_contents((string) $class->getFileName());
        preg_match_all('/\ba\.(act_[a-z_]+)\b/', $columns . "\n" . $source, $matches);

        $read = array_values(array_unique($matches[1]));
        sort($read);

        return $read;
    }
}
