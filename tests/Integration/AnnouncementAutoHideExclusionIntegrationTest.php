<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Announcements\AnnouncementModerationPolicy as Policy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Module 205 is excluded from auto-hide — WITH A REAL ACTIVITY TABLE, and
 * with an ordinary module proving the distinction.
 *
 * ── ⚠⚠ WHY THIS EXISTS ──────────────────────────────────────────────────
 * §4.32.16 accepted reportability WITHOUT automatic hiding, and
 * `AutoHideService` hides at a threshold of 3 reports. The exclusion is
 * therefore the whole safety property, and it is a DISTINCTION: announcements
 * are refused, everything else keeps following the existing policy.
 *
 * The sibling unit suite doubles `PeepSoActivityRepository`, so it proves the
 * branch logic but not that the lookup resolves. And a probe on a
 * plugin-only install cannot prove the distinction at all: with no
 * `peepso_activities` table, `getModuleIdByActId()` returns null for EVERY
 * id, so every decision is `BLOCKED_UNRESOLVED` — announcements and ordinary
 * content alike. Asserting "an announcement is not hideable" there proves
 * only that an unresolvable activity is not hideable, which is a different
 * and much weaker claim. This suite seeds the real table so both halves are
 * exercised against real SQL on both engines.
 *
 * ── ⚠⚠ IT NEVER TOUCHES DATA IT DID NOT CREATE ──────────────────────────
 * `peepso_activities` is a SHARED table name: the repository resolves
 * `$wpdb->prefix . 'peepso_activities'` itself and cannot be pointed at a
 * fixture table. So three rules, all of them load-bearing:
 *
 *   1. The table is CREATED only when absent, and DROPPED only when this
 *      suite created it. Where PeepSo really is installed, the table is used
 *      as found and left exactly as found.
 *   2. Rows are NEVER bulk-deleted. An earlier revision of this file ran
 *      `DELETE FROM <shared table>` in setUp — which on a real install would
 *      have destroyed every activity row in the database. It now deletes only
 *      ids it inserted, tracked individually.
 *   3. Every id is RESERVED before use: if a chosen id already exists, the
 *      test SKIPS rather than overwriting a real activity. Dropping a shared
 *      table is what poisoned 57 unrelated cases in this project once; this
 *      is the same hazard one layer down.
 *
 * ⚠ Cleanup survives failure. `tearDown()` runs after any failing assertion
 * in a test body, and setUp deliberately performs the minimum — an existence
 * check and, at most, one CREATE — so a setUp that throws has left nothing
 * behind to clean.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(Policy::class)]
final class AnnouncementAutoHideExclusionIntegrationTest extends TestCase
{
    /**
     * ⚠ Deliberately far above any plausible real `act_id`, and still
     * reserved before use rather than assumed free.
     */
    private const ID_BASE = 2000000001;

    private string $table;

    /** True only when THIS suite created the table. */
    private bool $tableWasOurs = false;

    /** @var list<int> ids this test inserted, and the only ones it may delete */
    private array $inserted = [];

    protected function setUp(): void
    {
        global $wpdb;

        $this->table    = $wpdb->prefix . 'peepso_activities';
        $this->inserted = [];

        $exists = (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $this->table)
        ) === $this->table;

        if ($exists) {
            // Use it as found. Nothing is created, nothing will be dropped.
            $this->tableWasOurs = false;

            return;
        }

        // ⚠ Only the two columns the lookup reads. A fuller replica would
        // invite drift against the real PeepSo schema, which this suite has
        // no business asserting.
        $created = $wpdb->query(
            'CREATE TABLE ' . $this->table . ' (
                act_id BIGINT UNSIGNED NOT NULL,
                act_module_id VARCHAR(20) NOT NULL,
                PRIMARY KEY (act_id)
            ) DEFAULT CHARSET=utf8mb4'
        );

        // ⚠ `$wpdb->query()` returns false SILENTLY, so the result is
        // asserted rather than assumed — otherwise every case below would
        // pass vacuously against a table that is not there.
        self::assertNotFalse(
            $created,
            'could not create the fixture activity table: ' . $wpdb->last_error
        );

        $this->tableWasOurs = true;
    }

    protected function tearDown(): void
    {
        global $wpdb;

        if ($this->tableWasOurs) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $this->table);
            $this->tableWasOurs = false;
            $this->inserted     = [];

            return;
        }

        // A pre-existing table: remove ONLY the rows this test inserted.
        foreach ($this->inserted as $actId) {
            $wpdb->delete($this->table, ['act_id' => $actId]);
        }
        $this->inserted = [];
    }

    /**
     * Reserve an id, then seed it.
     *
     * ⚠ Skips rather than overwrites. On an install where PeepSo really is
     * present, an id collision means a real activity row — and silently
     * replacing it to make a test pass would be the worst outcome available.
     */
    private function seedActivity(int $offset, string $moduleId): int
    {
        global $wpdb;

        $actId = self::ID_BASE + $offset;

        $taken = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $this->table . ' WHERE act_id = %d',
            $actId
        ));
        if ($taken > 0) {
            self::markTestSkipped(
                'act_id ' . $actId . ' already exists on this install; refusing to overwrite a real row'
            );
        }

        self::assertNotFalse(
            $wpdb->insert($this->table, ['act_id' => $actId, 'act_module_id' => $moduleId]),
            'seeding act ' . $actId . ': ' . $wpdb->last_error
        );
        $this->inserted[] = $actId;

        return $actId;
    }

    /** ⚠⚠ THE DISTINCTION, both directions, against real rows. */
    public function testAnAnnouncementIsRefusedWhileAnOrdinaryModuleIsAllowed(): void
    {
        $announcement = $this->seedActivity(1, Policy::ANNOUNCEMENT_MODULE_ID);   // '205'
        $ordinary     = $this->seedActivity(2, '201');

        self::assertSame(
            Policy::AUTO_HIDE_BLOCKED_ANNOUNCEMENT,
            Policy::autoHideDecision($announcement),
            'module 205 must be refused BY NAME, not merely left unresolved'
        );
        self::assertFalse(Policy::mayAutoHide($announcement));

        self::assertSame(
            Policy::AUTO_HIDE_ALLOWED,
            Policy::autoHideDecision($ordinary),
            'an ordinary module must keep following the existing policy'
        );
        self::assertTrue(
            Policy::mayAutoHide($ordinary),
            'if this fails the exclusion is hiding everything, not just announcements'
        );
    }

    /**
     * Every ordinary reportable module still follows the existing policy.
     * This is the half a missing activity table silently turns green.
     */
    #[DataProvider('ordinaryModules')]
    public function testEveryOrdinaryReportableModuleRemainsHideable(string $moduleId): void
    {
        $actId = $this->seedActivity(10, $moduleId);

        self::assertSame(Policy::AUTO_HIDE_ALLOWED, Policy::autoHideDecision($actId), 'module ' . $moduleId);
        self::assertTrue(Policy::mayAutoHide($actId), 'module ' . $moduleId . ' must stay hideable');
    }

    /** @return iterable<string, array{string}> */
    public static function ordinaryModules(): iterable
    {
        yield 'status (201)' => ['201'];
        yield 'review (202)' => ['202'];
        yield 'blog (204)'   => ['204'];
        yield 'an unknown-but-present module' => ['299'];
    }

    /**
     * ⚠ The trim-ORDER defect, now against a real row. `autoHideDecision()`
     * trims BEFORE testing emptiness; an earlier revision tested the RAW
     * value and trimmed only for the comparison, so a whitespace-only module
     * passed the emptiness check, failed the 205 comparison, and came back
     * ALLOWED — an unidentifiable activity declared hideable.
     */
    public function testAWhitespaceOnlyModuleIsRefusedNotAllowed(): void
    {
        $actId = $this->seedActivity(20, '   ');

        self::assertSame(Policy::AUTO_HIDE_BLOCKED_UNRESOLVED, Policy::autoHideDecision($actId));
        self::assertFalse(Policy::mayAutoHide($actId), 'an unidentifiable activity must never be hideable');
    }

    /** A padded 205 is still an announcement — the trim applies to the comparison too. */
    public function testAPaddedAnnouncementModuleIsStillRefused(): void
    {
        $actId = $this->seedActivity(30, ' 205 ');

        self::assertSame(Policy::AUTO_HIDE_BLOCKED_ANNOUNCEMENT, Policy::autoHideDecision($actId));
        self::assertFalse(Policy::mayAutoHide($actId));
    }

    /**
     * ⚠ A module that merely CONTAINS 205 is NOT an announcement. A prefix or
     * substring match would exclude unrelated content from auto-hide — the
     * exclusion failing open for everything that happens to share digits.
     */
    #[DataProvider('lookalikeModules')]
    public function testALookalikeModuleIsNotTreatedAsAnAnnouncement(string $moduleId): void
    {
        $actId = $this->seedActivity(40, $moduleId);

        self::assertSame(
            Policy::AUTO_HIDE_ALLOWED,
            Policy::autoHideDecision($actId),
            $moduleId . ' is not module 205 and must keep the ordinary policy'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function lookalikeModules(): iterable
    {
        yield '1205' => ['1205'];
        yield '2050' => ['2050'];
        yield '20'   => ['20'];
        yield '5'    => ['5'];
    }

    /** A row that is simply not there stays fail-closed, with a real table present. */
    public function testAMissingActivityIsRefusedEvenWithTheTablePresent(): void
    {
        $present = $this->seedActivity(50, '201');
        $missing = self::ID_BASE + 999999;

        self::assertSame(Policy::AUTO_HIDE_BLOCKED_UNRESOLVED, Policy::autoHideDecision($missing));
        self::assertFalse(Policy::mayAutoHide($missing));
        self::assertTrue(Policy::mayAutoHide($present), 'and the seeded neighbour is unaffected');
    }

    /** An empty stored module is unidentifiable, not ordinary. */
    public function testAnEmptyStoredModuleIsRefused(): void
    {
        $actId = $this->seedActivity(60, '');

        self::assertSame(Policy::AUTO_HIDE_BLOCKED_UNRESOLVED, Policy::autoHideDecision($actId));
        self::assertFalse(Policy::mayAutoHide($actId));
    }

    /** Non-positive ids never reach SQL at all. */
    #[DataProvider('impossibleIds')]
    public function testANonPositiveIdIsRefusedWithoutAQuery(int $actId): void
    {
        self::assertSame(Policy::AUTO_HIDE_BLOCKED_UNRESOLVED, Policy::autoHideDecision($actId));
        self::assertFalse(Policy::mayAutoHide($actId));
    }

    /** @return iterable<string, array{int}> */
    public static function impossibleIds(): iterable
    {
        yield 'zero'          => [0];
        yield 'negative'      => [-1];
        yield 'very negative' => [-999999];
    }

    /**
     * ⚠ The cleanup contract itself, asserted rather than trusted: after a
     * seed, the row exists; `tearDown()` is what removes it. This case exists
     * so a future edit that stops tracking inserted ids fails here instead of
     * leaving rows in a shared table.
     */
    public function testSeededRowsAreTrackedForCleanup(): void
    {
        global $wpdb;

        $a = $this->seedActivity(70, '201');
        $b = $this->seedActivity(71, '205');

        self::assertSame([$a, $b], $this->inserted, 'every seeded id must be tracked');
        self::assertSame(
            2,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $this->table . ' WHERE act_id IN (%d, %d)',
                $a,
                $b
            ))
        );
    }
}
