<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Onchain\Services\HallProvisioningService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * HallProvisioningService guard paths.
 *
 * The provisioner fans out to `new \PeepSoGroup(...)` + get_users + the
 * chain registry, so an end-to-end create is exercised by the functional
 * / staging sweep. These unit cases pin the two fail-closed guards that
 * MUST short-circuit BEFORE any group creation, since a broken guard
 * would either fatal (PeepSo inactive) or create ownerless groups:
 *
 *   1. PeepSo Groups plugin inactive → no `\PeepSoGroup` class.
 *   2. No administrator to own the groups (groups cannot be ownerless).
 *
 * Each runs in its own subprocess so the `\PeepSoGroup` presence differs
 * per case without cross-contamination.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HallProvisioningServiceTest extends TestCase
{
    public function testProvisionAllReportsPeepSoAbsent(): void
    {
        // No `\PeepSoGroup` defined in this fresh subprocess.
        self::assertFalse(class_exists('\\PeepSoGroup', false), 'precondition: PeepSo absent');

        $result = (new HallProvisioningService())->provisionAll();

        self::assertSame(0, $result['created']);
        self::assertSame(0, $result['skipped']);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('PeepSoGroup class not available', $result['errors'][0]);
    }

    public function testProvisionAllReportsNoAdminOwner(): void
    {
        // `\PeepSoGroup` exists (passes the availability gate) but there is
        // no administrator, so the owner gate must stop the sweep.
        require __DIR__ . '/../Stubs/hall-provision-noowner-stubs.php';
        self::assertTrue(class_exists('\\PeepSoGroup', false), 'precondition: PeepSo present');

        $result = (new HallProvisioningService())->provisionAll();

        self::assertSame(0, $result['created']);
        self::assertSame(0, $result['skipped']);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('No administrator', $result['errors'][0]);
    }
}
