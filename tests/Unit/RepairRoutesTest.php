<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\Admin\RepairRouteDied;
use BCC\Trust\Core\Services\Admin\RepairRouteStubState;
use BCC\Trust\Core\Services\Admin\RepairService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The Repair page's "Check Tables & Remove Orphans" and "Clean Up Fraud
 * Records" buttons post to admin-post routes that must (a) exist and
 * (b) stay behind the same gate as every other repair action:
 * BCC_REPAIR_ENABLED === true, manage_options, valid nonce — checked
 * BEFORE any repository is touched.
 *
 * Separate processes: BCC_REPAIR_ENABLED is a constant.
 */
#[CoversClass(RepairService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RepairRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/repair-route-stubs.php';
        RepairRouteStubState::$actions   = [];
        RepairRouteStubState::$canManage = true;
        RepairRouteStubState::$nonceOk   = true;
        $_POST = ['_wpnonce' => 'n'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    public function testBothButtonRoutesAreRegistered(): void
    {
        RepairService::registerActions();

        self::assertArrayHasKey('admin_post_bcc_trust_db_check', RepairRouteStubState::$actions);
        self::assertArrayHasKey('admin_post_bcc_trust_cleanup_fraud', RepairRouteStubState::$actions);
    }

    public function testRoutesDieWhenRepairIsNotEnabled(): void
    {
        RepairService::registerActions();

        foreach (['admin_post_bcc_trust_db_check', 'admin_post_bcc_trust_cleanup_fraud'] as $hook) {
            try {
                (RepairRouteStubState::$actions[$hook])();
                self::fail("{$hook} ran without BCC_REPAIR_ENABLED");
            } catch (RepairRouteDied $e) {
                self::assertStringContainsString('Repair is disabled', $e->getMessage());
            }
        }
    }

    public function testRoutesDieForNonAdmins(): void
    {
        define('BCC_REPAIR_ENABLED', true);
        RepairRouteStubState::$canManage = false;
        RepairService::registerActions();

        $this->expectException(RepairRouteDied::class);
        $this->expectExceptionMessage('Unauthorized');
        (RepairRouteStubState::$actions['admin_post_bcc_trust_db_check'])();
    }

    public function testRoutesDieOnGetEvenWithAValidNonce(): void
    {
        // F-91: a crafted GET link carrying a valid nonce must not run a repair.
        define('BCC_REPAIR_ENABLED', true);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        RepairService::registerActions();

        foreach (['admin_post_bcc_trust_db_check', 'admin_post_bcc_trust_cleanup_fraud', 'admin_post_bcc_trust_complete_page_repair'] as $hook) {
            try {
                (RepairRouteStubState::$actions[$hook])();
                self::fail("{$hook} ran on GET");
            } catch (RepairRouteDied $e) {
                self::assertStringContainsString('submitted as a form', $e->getMessage());
            }
        }
    }

    public function testCompletePageRepairHonoursTheKillSwitch(): void
    {
        // F-91: repairPages() used to skip BCC_REPAIR_ENABLED.
        RepairService::registerActions();

        $this->expectException(RepairRouteDied::class);
        $this->expectExceptionMessage('Repair is disabled');
        (RepairRouteStubState::$actions['admin_post_bcc_trust_complete_page_repair'])();
    }

    public function testRoutesDieOnBadNonce(): void
    {
        define('BCC_REPAIR_ENABLED', true);
        RepairRouteStubState::$nonceOk = false;
        RepairService::registerActions();

        $this->expectException(RepairRouteDied::class);
        $this->expectExceptionMessage('Security check failed');
        (RepairRouteStubState::$actions['admin_post_bcc_trust_cleanup_fraud'])();
    }
}
