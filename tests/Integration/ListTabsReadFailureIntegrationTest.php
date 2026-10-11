<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\AdminDashboardRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The list tabs — Pages (Top and All), Users and Activity — stop printing an
 * empty table as a statement about the data.
 *
 * ── WHAT THEY SAID WITH THE READ FAILING ────────────────────────────────
 * Measured in a disposable authenticated browser, each table replaced by an
 * invalid view:
 *
 *   Pages ▸ Top   "No pages scored yet."
 *   Pages ▸ All   "No pages found."
 *   Users ▸ Trust "No users found."
 *   Activity      "No activity found."
 *
 * Four rows became zero in every case, with no warning and no PHP
 * diagnostic. `wpdb::get_results()` returns `last_result`, which `flush()`
 * resets to `array()` on every query, so a query that never ran is
 * indistinguishable from one that matched nothing — `last_error` is the only
 * separator.
 *
 * ⚠ Pages ▸ All is the case worth knowing about: it reads `wp_posts` LEFT
 * JOIN the scores table, NOT the read model. Breaking the read model left it
 * legitimately empty, and "No pages found" was the right answer. Its honest
 * state only appears when the table it actually depends on fails — which is
 * why the fixture below targets the scores table for it, and why a test that
 * broke the obvious table would have passed while proving nothing.
 */
#[Group('integration')]
#[Group('mariadb')]
final class ListTabsReadFailureIntegrationTest extends TestCase
{
    private AdminDashboardRepository $repo;

    protected function setUp(): void
    {
        $this->ensureTables();

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        $p = $wpdb->prefix;

        $wpdb->query("DELETE FROM `{$p}bcc_trust_user_info`");
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$p}bcc_trust_user_info`
                (user_id, user_login, user_email, display_name, fraud_score, updated_at)
             VALUES (%d, %s, %s, %s, %d, NOW())",
            961,
            'listfixture961',
            'listfixture961@example.test',
            'List Fixture',
            10
        ));

        $wpdb->query("DELETE FROM `{$p}bcc_page_read_model`");
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$p}bcc_page_read_model` (page_id, reputation_tier) VALUES (%d, %s)",
            9101,
            'neutral'
        ));

        $wpdb->query("DELETE FROM `{$p}bcc_trust_activity`");
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$p}bcc_trust_activity` (user_id, action, target_type, target_id, created_at)
             VALUES (%d, %s, %s, %d, NOW())",
            961,
            'admin_list_fixture',
            'page',
            9101
        ));

        // ⚠ Preconditions asserted: $wpdb->query() reports a failed INSERT
        // only by returning false, and a silently empty fixture would make
        // every discrimination case below pass for the wrong reason.
        self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$p}bcc_trust_user_info`"));
        self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$p}bcc_page_read_model`"));
        self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$p}bcc_trust_activity`"));

        $this->repo = new AdminDashboardRepository();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
    }

    private function ensureTables(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $p    = $wpdb->prefix;
        $root = dirname(__DIR__, 2);

        // The read model is installed by the plugin's own installer — a
        // hand-rolled stand-in would shadow the real schema for every suite
        // sharing this database, which is a mistake already made once here.
        $readModel = \BCC\Trust\Core\Database\TableRegistry::pageReadModel();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $readModel)) !== $readModel) {
            require_once $root . '/includes/database/schema-project.php';
            \bcc_trust_create_page_tables();
        }
        self::assertSame(
            $readModel,
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $readModel)),
            'the read model must be installed by the plugin, not by this fixture'
        );

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}bcc_trust_activity` (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                action VARCHAR(64) NOT NULL DEFAULT '',
                target_type VARCHAR(32) NOT NULL DEFAULT '',
                target_id BIGINT UNSIGNED NULL,
                ip_address VARCHAR(45) NULL,
                created_at DATETIME NULL
            )"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}bcc_trust_page_scores` (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                trust_score FLOAT NULL
            )"
        );

        // ⚠ Pages ▸ All selects columns these fixtures did not have:
        // post_author and post_date from the bootstrap's deliberately
        // three-column wp_posts, and four score columns from the table above.
        // Without them the read genuinely FAILS, every verdict is false, and
        // the discrimination cases pass while proving nothing. The healthy
        // control is what caught it — which is the whole reason it exists.
        //
        // Columns are ADDED, never replaced: wp_posts is created by the
        // shared bootstrap, and widening it cannot break a suite that does
        // not select the new columns, whereas redefining it could.
        $addColumns = [
            'posts'                  => [
                'post_author' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
                'post_date'   => 'DATETIME NULL',
            ],
            'bcc_trust_page_scores' => [
                'total_score'      => 'FLOAT NULL',
                'reputation_tier'  => "VARCHAR(16) NOT NULL DEFAULT 'neutral'",
                'vote_count'       => 'INT NOT NULL DEFAULT 0',
                'confidence_score' => 'FLOAT NULL',
            ],
        ];
        foreach ($addColumns as $suffix => $columns) {
            $tableName = $p . $suffix;
            foreach ($columns as $column => $definition) {
                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
                    $tableName,
                    $column
                ));
                if ($exists === 0) {
                    $wpdb->query("ALTER TABLE `{$tableName}` ADD COLUMN `{$column}` {$definition}");
                }
            }
        }
    }

    private function failReadsMatching(string $needle): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $GLOBALS['wpdb']->failQueriesMatching = '/' . preg_quote($needle, '/') . '/';
    }

    // ── 0. CONTROLS
    // ═══════════════════════════════════════════════════════════════════

    public function testHealthyPagesDataIsOkAndFindsThePage(): void
    {
        $data = $this->repo->getPagesData();

        self::assertTrue($data['ok']);
        self::assertCount(1, $data['pages'], 'the seeded read-model row must be found');
    }

    public function testHealthyUsersDataIsOkAndFindsTheUser(): void
    {
        $data = $this->repo->getUsersData();

        self::assertTrue($data['ok']);
        self::assertCount(1, $data['users']);
    }

    public function testHealthyActivityDataIsOkAndFindsTheEntry(): void
    {
        $data = $this->repo->getActivityData();

        self::assertTrue($data['ok']);
        self::assertCount(1, $data['activity']);
    }

    public function testHealthyAllPagesDataIsOk(): void
    {
        // It reads wp_posts LEFT JOIN scores, so it may legitimately be empty
        // here. `ok` is the claim under test, not the row count.
        $data = $this->repo->getAllPagesData();

        self::assertTrue($data['ok']);
    }

    public function testAGenuinelyEmptyListIsEmptyAndStillOk(): void
    {
        // ⚠ The other half of the control: empty is a legitimate answer and
        // the fix must not start calling it a failure.
        $p = $GLOBALS['wpdb']->prefix;
        $GLOBALS['wpdb']->query("DELETE FROM `{$p}bcc_trust_activity`");

        $data = $this->repo->getActivityData();

        self::assertTrue($data['ok'], 'no rows is not a read failure');
        self::assertSame([], $data['activity']);
    }

    // ── 1. ⚠⚠ DISCRIMINATION
    // ═══════════════════════════════════════════════════════════════════

    public function testAFailedPagesReadIsNotOk(): void
    {
        $this->failReadsMatching('bcc_page_read_model');

        $data = $this->repo->getPagesData();

        self::assertFalse($data['ok'], '"No pages scored yet" must not be printable from this');
        self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures);
    }

    public function testAFailedUsersReadIsNotOk(): void
    {
        $this->failReadsMatching('bcc_trust_user_info');

        self::assertFalse($this->repo->getUsersData()['ok']);
    }

    public function testAFailedActivityReadIsNotOk(): void
    {
        $this->failReadsMatching('bcc_trust_activity');

        self::assertFalse($this->repo->getActivityData()['ok']);
    }

    public function testAFailedAllPagesReadIsNotOkWhenItsOwnDependencyFails(): void
    {
        // ⚠ The scores table, not the read model. Pages ▸ All does not read
        // the read model at all, so breaking that left it legitimately empty
        // and "No pages found" was correct — a test aimed at the obvious
        // table would have passed while proving nothing about this method.
        $this->failReadsMatching('bcc_trust_page_scores');

        $data = $this->repo->getAllPagesData();

        self::assertFalse($data['ok']);
        self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures);
    }

    public function testBreakingTheReadModelLeavesAllPagesOk(): void
    {
        // ⚠ The pair to the case above, and the reason it is written that
        // way: these two Pages views have different dependencies.
        $this->failReadsMatching('bcc_page_read_model');

        self::assertFalse($this->repo->getPagesData()['ok'], 'Top reads the read model');
        self::assertTrue($this->repo->getAllPagesData()['ok'], 'All does not');
    }

    public function testTheSameReadThatFailsWhenBrokenIsOkWhenReadable(): void
    {
        $this->failReadsMatching('bcc_trust_user_info');
        self::assertFalse($this->repo->getUsersData()['ok']);

        $GLOBALS['wpdb']->clearFaultInjection();

        $recovered = $this->repo->getUsersData();
        self::assertTrue($recovered['ok']);
        self::assertCount(1, $recovered['users'], 'and the row comes back');
    }

    public function testAnEmptyListAndAFailedReadCarryDifferentVerdicts(): void
    {
        $p = $GLOBALS['wpdb']->prefix;
        $GLOBALS['wpdb']->query("DELETE FROM `{$p}bcc_trust_activity`");
        $empty = $this->repo->getActivityData();

        $this->failReadsMatching('bcc_trust_activity');
        $broken = $this->repo->getActivityData();

        self::assertSame([], $empty['activity']);
        self::assertSame([], $broken['activity'], 'same value');
        self::assertTrue($empty['ok']);
        self::assertFalse($broken['ok'], 'different verdicts: that is the fix');
    }

    public function testTheVerdictIsPerMethodRatherThanBlanket(): void
    {
        // ⚠ Anti-vacuity: a fault on the activity table must leave USERS ok.
        $this->failReadsMatching('bcc_trust_activity');

        self::assertFalse($this->repo->getActivityData()['ok']);
        self::assertTrue($this->repo->getUsersData()['ok']);
    }

    public function testAnUnrelatedFaultLeavesEveryVerdictOk(): void
    {
        $this->failReadsMatching('a_table_no_list_tab_reads');

        self::assertTrue($this->repo->getPagesData()['ok']);
        self::assertTrue($this->repo->getUsersData()['ok']);
        self::assertTrue($this->repo->getActivityData()['ok']);
        self::assertSame(0, $GLOBALS['wpdb']->injectedFailures);
    }

    public function testTheAdminOnlyActivityReadIsGuardedEvenThoughItReturnsABareList(): void
    {
        // It cannot carry a verdict without a contract change, so the claim
        // here is narrower and exact: it still returns a list, and the fault
        // is detected (and therefore logged) rather than silent.
        $this->failReadsMatching('bcc_trust_activity');

        $rows = $this->repo->getAdminOnlyActivity(10);

        self::assertSame([], $rows, 'the contract is unchanged');
        self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'and the read really did fail');
    }
}
