<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\ChainSweepActions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The "Run cron now" sweeps were the P1 finding in the BCC System audit:
 * four GET links sharing ONE nonce, three of them with no confirmation, each
 * fanning out a synchronous multi-provider sweep that a browser refresh
 * replayed.
 *
 * These tests pin the hardening AND the preservation:
 *   - dispatch is POST-only and the old GET path is gone;
 *   - each operation has its own nonce, so a "Validators only" nonce cannot
 *     drive the full "All" sweep;
 *   - the step SEQUENCE through ChainRefreshService is byte-identical to the
 *     admin_init closure it replaced (validators → enrichment);
 *   - a mid-sweep failure records which steps completed and does not leak the
 *     exception text;
 *   - every run ends in a redirect, so a refresh cannot replay it.
 */
#[CoversClass(ChainSweepActions::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OnchainAdminSweepActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/onchain-admin-action-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Onchain\Services\ChainRefreshService::reset();

        $_POST = [];
        $_GET  = [];
    }

    /** Simulate WordPress firing a given admin_post_ hook. */
    private function fire(string $action): void
    {
        $GLOBALS['bcc_test_current_action'] = 'admin_post_' . $action;
        ChainSweepActions::handle();
    }

    // ── Registration ────────────────────────────────────────────────────────

    public function testRegistersExactlyTheFourAdminPostActions(): void
    {
        $GLOBALS['bcc_test_registered_actions'] = [];

        ChainSweepActions::register();

        $this->assertSame([
            'admin_post_bcc_onchain_sweep_validators',
            'admin_post_bcc_onchain_sweep_enrichment',
            'admin_post_bcc_onchain_sweep_all',
        ], $GLOBALS['bcc_test_registered_actions']);
    }

    public function testRegistersNoAdminInitOrGetHandler(): void
    {
        $GLOBALS['bcc_test_registered_actions'] = [];

        ChainSweepActions::register();

        // The replaced implementation hung off admin_init and read $_GET.
        // Nothing may reintroduce a query-param dispatch path.
        foreach ($GLOBALS['bcc_test_registered_actions'] as $hook) {
            $this->assertStringStartsWith('admin_post_', $hook);
        }
    }

    // ── Authorization ───────────────────────────────────────────────────────

    public function testMissingCapabilityPerformsNoWork(): void
    {
        \BccAdminTestState::$can = false;
        \BccAdminTestState::$validNonceAction = ChainSweepActions::ACTION_ALL;

        try {
            $this->fire(ChainSweepActions::ACTION_ALL);
            $this->fail('Expected a 403 halt.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertSame([], \BCC\Trust\Onchain\Services\ChainRefreshService::$calls);
        $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
    }

    public function testInvalidNoncePerformsNoWork(): void
    {
        \BccAdminTestState::$validNonceAction = null;

        $this->expectException(\BccAdminDie::class);

        try {
            $this->fire(ChainSweepActions::ACTION_VALIDATORS);
        } finally {
            $this->assertSame([], \BCC\Trust\Onchain\Services\ChainRefreshService::$calls);
            $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
        }
    }

    public function testNonceForAnotherSweepIsRejected(): void
    {
        // The whole point of splitting the shared nonce: a nonce minted for
        // the cheap "Validators only" button must not authorize "All".
        \BccAdminTestState::$validNonceAction = ChainSweepActions::ACTION_VALIDATORS;

        $this->expectException(\BccAdminDie::class);

        try {
            $this->fire(ChainSweepActions::ACTION_ALL);
        } finally {
            $this->assertSame([], \BCC\Trust\Onchain\Services\ChainRefreshService::$calls);
        }
    }

    public function testClientSuppliedActionCannotWidenTheSweep(): void
    {
        // The operation is resolved from the admin_post_ hook that fired, not
        // from $_POST. A forged `action` field claiming the full sweep must
        // neither steer the nonce check nor widen the work performed.
        \BccAdminTestState::$validNonceAction = ChainSweepActions::ACTION_ENRICHMENT;
        $_POST['action'] = ChainSweepActions::ACTION_ALL;

        try {
            $this->fire(ChainSweepActions::ACTION_ENRICHMENT);
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('enrichment', $r->args['bcc_sweep']);
        }

        // Only the hook's own step ran — not validators.
        $this->assertSame(['enrichment'], \BCC\Trust\Onchain\Services\ChainRefreshService::$calls);
        $this->assertSame(
            [['action' => ChainSweepActions::ACTION_ENRICHMENT, 'arg' => '_wpnonce']],
            \BccAdminTestState::$nonceChecks
        );
    }

    // ── Behaviour preservation ──────────────────────────────────────────────

    /**
     * @param list<string> $expectedSteps
     */
    #[DataProvider('sweepMatrix')]
    public function testEachSweepRunsExactlyItsOriginalSteps(string $action, array $expectedSteps, string $slug): void
    {
        \BccAdminTestState::$validNonceAction = $action;

        try {
            $this->fire($action);
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('bcc-onchain-chains', $r->args['page']);
            $this->assertSame($slug, $r->args['bcc_sweep']);
            $this->assertSame('ok', $r->args['bcc_result']);
        }

        $this->assertSame($expectedSteps, \BCC\Trust\Onchain\Services\ChainRefreshService::$calls);
    }

    /** @return list<array{0: string, 1: list<string>, 2: string}> */
    public static function sweepMatrix(): array
    {
        return [
            [ChainSweepActions::ACTION_VALIDATORS,  ['validators'], 'validators'],
            [ChainSweepActions::ACTION_ENRICHMENT,  ['enrichment'], 'enrichment'],
            // Order matters: the replaced closure ran validators, then
            // then enrichment, in that sequence.
            [ChainSweepActions::ACTION_ALL, ['validators', 'enrichment'], 'all'],
        ];
    }

    public function testSuccessfulSweepWritesDurableAuditRow(): void
    {
        \BccAdminTestState::$validNonceAction = ChainSweepActions::ACTION_ALL;

        try {
            $this->fire(ChainSweepActions::ACTION_ALL);
        } catch (\BccAdminRedirect) {
            // expected
        }

        $rows = \BCC\Trust\Core\Security\AuditLogger::$rows;
        $this->assertCount(1, $rows);
        $this->assertSame('admin_onchain_sweep_all', $rows[0]['action']);
        $this->assertSame('onchain_sweep', $rows[0]['targetType']);
        $this->assertSame('validators,enrichment', $rows[0]['meta']['steps']);
    }

    // ── Failure handling ────────────────────────────────────────────────────

    public function testMidSweepFailureReportsCompletedStepsAndHidesTheException(): void
    {
        \BccAdminTestState::$validNonceAction = ChainSweepActions::ACTION_ALL;
        \BCC\Trust\Onchain\Services\ChainRefreshService::$throwOn['enrichment'] =
            new \RuntimeException('SQLSTATE[HY000] at /var/www/html/secret.php');

        try {
            $this->fire(ChainSweepActions::ACTION_ALL);
            $this->fail('Expected PRG redirect after failure.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('failed', $r->args['bcc_result']);
            $this->assertSame('enrichment', $r->args['bcc_failed_at']);
            $this->assertMatchesRegularExpression('/^bcc-[0-9a-f]{8}$/', $r->args['bcc_ref']);

            // No fragment of the exception may ride the redirect.
            $this->assertStringNotContainsString('SQLSTATE', $r->url);
            $this->assertStringNotContainsString('secret.php', $r->url);
        }

        // Validators completed before the failure — that must be visible.
        $this->assertSame(['validators'], \BCC\Trust\Onchain\Services\ChainRefreshService::$calls);

        $rows = \BCC\Trust\Core\Security\AuditLogger::$rows;
        $this->assertSame(['admin_onchain_sweep_failed'], \BCC\Trust\Core\Security\AuditLogger::actions());
        $this->assertSame('validators', $rows[0]['meta']['completed']);
        $this->assertSame('enrichment', $rows[0]['meta']['failed_at']);

        // Enrichment must NOT have run after the failure.
        $this->assertNotContains('enrichment', \BCC\Trust\Onchain\Services\ChainRefreshService::$calls);
    }

    public function testFailureOnFirstStepReportsNoCompletedWork(): void
    {
        \BccAdminTestState::$validNonceAction = ChainSweepActions::ACTION_ALL;
        \BCC\Trust\Onchain\Services\ChainRefreshService::$throwOn['validators'] = new \RuntimeException('boom');

        try {
            $this->fire(ChainSweepActions::ACTION_ALL);
        } catch (\BccAdminRedirect) {
            // expected
        }

        $rows = \BCC\Trust\Core\Security\AuditLogger::$rows;
        $this->assertSame('(none)', $rows[0]['meta']['completed']);
    }

    public function testFailureLogsFullExceptionInternally(): void
    {
        \BccAdminTestState::$validNonceAction = ChainSweepActions::ACTION_VALIDATORS;
        \BCC\Trust\Onchain\Services\ChainRefreshService::$throwOn['validators'] =
            new \RuntimeException('upstream 502 from https://eth-mainnet.example/v2/KEY');

        try {
            $this->fire(ChainSweepActions::ACTION_VALIDATORS);
        } catch (\BccAdminRedirect) {
            // expected
        }

        $errors = \BCC\Core\Log\Logger::ofLevel('error');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('upstream 502', $errors[0]['context']['message']);
    }

    // ── Metadata helpers used by the result notice ──────────────────────────

    public function testStepLabelsAndStepsForSlugStayInSyncWithTheOperations(): void
    {
        $this->assertSame(['validators', 'enrichment'], ChainSweepActions::stepsForSlug('all'));
        $this->assertSame(
            'validators + validator enrichment',
            ChainSweepActions::stepLabels(ChainSweepActions::stepsForSlug('all'))
        );
        $this->assertSame([], ChainSweepActions::stepsForSlug('not-a-sweep'));
    }
}
