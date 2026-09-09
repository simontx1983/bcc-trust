<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * `bcc_hall_provision` no longer schedules itself, and cannot.
 *
 * ── WHAT WAS RETIRED ────────────────────────────────────────────────────
 * A daily recurring hook that swept `ChainRepository::getActive()` and
 * created one OPEN, PUBLIC PeepSo group per chain that lacked a Hall. The
 * consequence was that REGISTERING A CHAIN published a public space within
 * ~24h — no administrator decided that the space should exist, and no
 * capability was exercised at the moment of creation.
 *
 * A Hall is now an official, administrator-created, chain-connected group:
 * one named chain at a time, from
 * {@see \BCC\Trust\Onchain\Admin\ChainsPage::ACTION_HALL_CREATE}, behind
 * manage_options + a per-chain nonce + a POST check.
 *
 * ── WHY THREE SITES, NOT ONE ────────────────────────────────────────────
 * The schedule was created in an activation block AND a `plugins_loaded`
 * self-heal, with the handler bound separately. Removing any one of those
 * leaves the others to restore or serve the event on the next request. All
 * three are asserted individually.
 *
 * ⚠ COMMENTS ARE STRIPPED BEFORE ASSERTING ABSENCE. The retirement notes
 * left in `bcc-trust.php` and `cron-hooks.php` deliberately NAME the hook so
 * a future reader understands why it is gone — a raw string search would
 * match that prose and make the explanation itself the failure.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HallProvisionCronRetiredTest extends TestCase
{
    private const HOOK = 'bcc_hall_provision';

    /** Source with comments and docblocks removed. */
    private static function codeOf(string $relativePath): string
    {
        $src  = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        $code = '';

        foreach (token_get_all($src) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    // ── The registration sites are gone ─────────────────────────────────

    public function testNoBootstrapPathSchedulesTheHallSweep(): void
    {
        $code = self::codeOf('bcc-trust.php');

        self::assertSame(
            0,
            preg_match_all('/wp_schedule_event\([^;]*' . preg_quote(self::HOOK, '/') . '/', $code),
            'neither activation nor the self-heal may schedule Hall provisioning'
        );
    }

    public function testTheCronHandlerIsNoLongerBound(): void
    {
        $code = self::codeOf('bcc-trust.php');

        self::assertSame(
            0,
            preg_match_all("/add_action\(\s*'" . preg_quote(self::HOOK, '/') . "'/", $code),
            'the hook must have no handler — a fire must do nothing'
        );
    }

    /**
     * The bulk entry point itself is gone, not merely unscheduled.
     *
     * Leaving `provisionAll()` in place would keep a one-click mass creator
     * one caller away, which is the same coupling under a different trigger.
     */
    public function testTheBulkProvisionAllMethodIsDeleted(): void
    {
        $code = self::codeOf('app/Domain/Onchain/Services/HallProvisioningService.php');

        self::assertStringNotContainsString('function provisionAll', $code);
        self::assertStringContainsString('function provisionOne', $code);
    }

    public function testTheBulkAdminControlIsGone(): void
    {
        $code = self::codeOf('app/Domain/Onchain/Admin/HolderGroupsPage.php');

        self::assertStringNotContainsString('handle_provision_halls', $code);
        self::assertStringNotContainsString('bcc_hall_groups_provision', $code);
        self::assertStringNotContainsString('hallProvisioningService', $code);
    }

    // ── The retirement lists ────────────────────────────────────────────

    public function testTheHookIsInTheCleanupOnlyList(): void
    {
        $map = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';

        self::assertContains(self::HOOK, $map['cleanup_only'], 'must be cleared on deactivate/uninstall');
        self::assertArrayNotHasKey(self::HOOK, $map['recurring'], 'must not be an expected recurring hook');
    }

    public function testAMigrationEntryExistsWithItsOwnDoneOption(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/includes/database/migration-runner.php'
        );

        self::assertStringContainsString("'unschedule_hall_provision_v1'", $src);
        self::assertStringContainsString("'bcc_trust_hall_provision_unscheduled'", $src);
        self::assertStringContainsString("'bcc_trust_unschedule_hall_provision'", $src);

        // A DISTINCT done_option from the discovery retirements — reusing one
        // of theirs would skip this migration on every install that already
        // ran them, i.e. exactly the installs carrying the live event.
        self::assertStringNotContainsString(
            "'done_option' => 'bcc_trust_automatic_nft_discovery_unscheduled',\n                'callback'    => 'bcc_trust_unschedule_hall_provision'",
            $src
        );
    }

    public function testTheMigrationFileIsLoadedByTheBootstrap(): void
    {
        $code = self::codeOf('bcc-trust.php');

        self::assertStringContainsString(
            "includes/database/unschedule-hall-provision.php",
            $code,
            'an unregistered migration file never runs'
        );
    }

    public function testTheHookIsInTheSharedRetiredList(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/database/unschedule-hall-provision.php';

        self::assertContains(self::HOOK, bcc_trust_retired_hall_hooks());
    }

    // ── The migration itself ────────────────────────────────────────────

    public function testTheMigrationClearsAnExistingEventAndProvesIt(): void
    {
        require_once __DIR__ . '/../Stubs/discovery-retirement-stubs.php';
        require_once dirname(__DIR__, 2) . '/includes/database/unschedule-hall-provision.php';

        \BccRetirementState::reset();
        \BccRetirementState::schedule(self::HOOK, [], 'daily');

        self::assertNotFalse(\BccRetirementState::next(self::HOOK), 'precondition: the event exists');

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_unschedule_hall_provision());
        self::assertFalse(\BccRetirementState::next(self::HOOK), 'the event must be gone');
    }

    public function testTheMigrationIsIdempotentOnAFreshInstall(): void
    {
        require_once __DIR__ . '/../Stubs/discovery-retirement-stubs.php';
        require_once dirname(__DIR__, 2) . '/includes/database/unschedule-hall-provision.php';

        \BccRetirementState::reset();

        // Nothing scheduled: clearing is a no-op and the postcondition holds
        // immediately. Re-running must stay COMPLETE.
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_unschedule_hall_provision());
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_unschedule_hall_provision());
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_unschedule_hall_provision());
    }

    /**
     * ⚠ THE FAIL-CLOSED PATH.
     *
     * A migration that marked itself done on the strength of having CALLED
     * the clear would leave the daily event running with no remaining
     * attempt to remove it, and nothing would ever say so.
     */
    public function testAClearThatDidNotTakeReportsIncomplete(): void
    {
        require_once __DIR__ . '/../Stubs/discovery-retirement-stubs.php';
        require_once dirname(__DIR__, 2) . '/includes/database/unschedule-hall-provision.php';

        \BccRetirementState::reset();
        \BccRetirementState::schedule(self::HOOK, [], 'daily');
        \BccRetirementState::$refuseToClear = [self::HOOK];

        self::assertSame(
            BCC_TRUST_MIGRATION_INCOMPLETE,
            bcc_trust_unschedule_hall_provision(),
            'an unproven postcondition must not report COMPLETE'
        );
        self::assertNotFalse(\BccRetirementState::next(self::HOOK), 'and the event is still there');
    }

    /**
     * An argument-bearing event is a DIFFERENT identity and legitimately
     * survives `wp_clear_scheduled_hook($hook)`. Nothing ever scheduled one
     * of those for this hook, but pinning the behaviour keeps the migration's
     * correctness argument honest rather than assumed.
     */
    public function testAnArgumentBearingEventIsNotSilentlyClaimed(): void
    {
        require_once __DIR__ . '/../Stubs/discovery-retirement-stubs.php';
        require_once dirname(__DIR__, 2) . '/includes/database/unschedule-hall-provision.php';

        \BccRetirementState::reset();
        \BccRetirementState::schedule(self::HOOK, [], 'daily');
        \BccRetirementState::schedule(self::HOOK, [123], 'daily');

        bcc_trust_unschedule_hall_provision();

        self::assertFalse(\BccRetirementState::next(self::HOOK), 'the no-arg identity is cleared');
        self::assertNotFalse(
            \BccRetirementState::next(self::HOOK, [123]),
            'the argument-bearing identity is a different event'
        );
    }

    // ── Adding a chain must not create a Hall ───────────────────────────

    /**
     * The rule stated as a property of the whole tree: nothing outside the
     * admin action may reach the creator.
     */
    public function testOnlyTheAdminActionCallsTheHallCreator(): void
    {
        $root    = dirname(__DIR__, 2);
        $callers = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (str_contains($src, 'hallProvisioningService()')) {
                $callers[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }

        sort($callers);

        self::assertSame(
            [
                'app/Domain/Onchain/Admin/ChainsPage.php',
                'app/Domain/Onchain/OnchainPlugin.php',
            ],
            $callers,
            'only the admin action (and the DI accessor) may reach Hall creation'
        );
    }

    /** And the bootstrap — where cron and activation live — reaches it nowhere. */
    public function testTheBootstrapNeverReachesTheHallCreator(): void
    {
        $code = self::codeOf('bcc-trust.php');

        self::assertStringNotContainsString('hallProvisioningService', $code);
    }
}
