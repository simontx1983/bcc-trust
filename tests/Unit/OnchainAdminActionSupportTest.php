<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\AdminActionSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Pins the request-boundary contract every on-chain admin handler relies on.
 *
 * These are the behaviours the Batch 1 hardening is built out of, so they are
 * tested once here rather than re-asserted in every handler test:
 *
 *   - a capability failure HALTS (403) instead of returning silently;
 *   - a nonce failure HALTS (403) and never reaches the write path;
 *   - a raw Throwable message never leaves the process as operator-facing text;
 *   - a failure still produces a durable audit row + a correlation ID;
 *   - audit action names cannot silently truncate into a different event.
 */
#[CoversClass(AdminActionSupport::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OnchainAdminActionSupportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/onchain-admin-action-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
    }

    public function testCapabilityFailureHaltsWith403(): void
    {
        \BccAdminTestState::$can = false;

        try {
            AdminActionSupport::requireCapability();
            $this->fail('Expected wp_die on capability failure.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status);
        }
    }

    public function testCapabilitySuccessFallsThrough(): void
    {
        \BccAdminTestState::$can = true;

        AdminActionSupport::requireCapability();

        $this->assertTrue(true, 'requireCapability() returns normally when permitted.');
    }

    public function testNonceFailureHalts(): void
    {
        \BccAdminTestState::$validNonceAction = 'some_other_action';

        $this->expectException(\BccAdminDie::class);
        AdminActionSupport::requireNonce('the_expected_action');
    }

    public function testNonceIsCheckedAgainstTheExactActionRequested(): void
    {
        \BccAdminTestState::$validNonceAction = 'the_expected_action';

        AdminActionSupport::requireNonce('the_expected_action', 'custom_arg');

        $this->assertSame(
            [['action' => 'the_expected_action', 'arg' => 'custom_arg']],
            \BccAdminTestState::$nonceChecks
        );
    }

    public function testCorrelationIdIsShortAndPrefixed(): void
    {
        $id = AdminActionSupport::correlationId();

        $this->assertMatchesRegularExpression('/^bcc-[0-9a-f]{8}$/', $id);
        $this->assertNotSame($id, AdminActionSupport::correlationId(), 'IDs must not repeat.');
    }

    public function testAuditWritesDurableRowAndFileLog(): void
    {
        AdminActionSupport::audit('admin_chain_identity_saved', 'chain', 42, ['fields_rejected' => 0]);

        $rows = \BCC\Trust\Core\Security\AuditLogger::$rows;
        $this->assertCount(1, $rows);
        $this->assertSame('admin_chain_identity_saved', $rows[0]['action']);
        $this->assertSame('chain', $rows[0]['targetType']);
        $this->assertSame(42, $rows[0]['targetId']);

        // The durable table has no meta column, so the structured context has
        // to survive in the file log — that is the documented trade-off.
        $info = \BCC\Core\Log\Logger::ofLevel('info');
        $this->assertCount(1, $info);
        $this->assertSame(0, $info[0]['context']['fields_rejected']);
        $this->assertSame(7, $info[0]['context']['operator']);
    }

    public function testEveryAuditActionFitsTheActionColumn(): void
    {
        // wp_bcc_trust_activity.action is VARCHAR(50); a longer name would be
        // truncated by MySQL into a DIFFERENT action, silently merging events.
        $actions = [
            'admin_onchain_sweep_validators',
            'admin_onchain_sweep_collections',
            'admin_onchain_sweep_enrichment',
            'admin_onchain_sweep_all',
            'admin_onchain_sweep_failed',
            'admin_nft_indexer_run',
            'admin_nft_indexer_run_failed',
            'admin_nft_indexer_paused',
            'admin_nft_indexer_resumed',
            'admin_nft_indexer_state_failed',
            'admin_helius_webhook_provisioned',
            'admin_helius_webhook_provision_failed',
            'admin_helius_addresses_resynced',
            'admin_helius_resync_noop',
            'admin_helius_resync_failed',
            'admin_nft_spam_rule_added',
            'admin_nft_spam_rule_removed',
            'admin_nft_spam_rule_failed',
            'admin_chain_identity_saved',
            'admin_chain_identity_failed',
            'admin_chain_validators_refreshed',
            'admin_chain_validators_refresh_failed',
            'admin_chain_collections_refreshed',
            'admin_chain_collections_refresh_failed',
        ];

        foreach ($actions as $action) {
            $this->assertLessThanOrEqual(
                AdminActionSupport::MAX_ACTION_LENGTH,
                strlen($action),
                $action . ' exceeds the action column width.'
            );
            $this->assertStringStartsWith('admin_', $action, $action . ' must be namespaced as an admin action.');
        }
    }

    public function testOverlongAuditActionThrowsUnderDebug(): void
    {
        define('WP_DEBUG', true);

        $this->expectException(\LengthException::class);
        AdminActionSupport::audit(str_repeat('a', 51), 'chain', 1);
    }

    public function testFailureLogsExceptionInternallyAndReturnsCorrelationId(): void
    {
        $e = new \RuntimeException('SQLSTATE[42S02]: Base table wp_bcc_chains missing at /var/www/secret.php');

        $ref = AdminActionSupport::failure($e, 'admin_nft_indexer_run_failed', 'chain', 9, ['slug' => 'ethereum']);

        $this->assertMatchesRegularExpression('/^bcc-[0-9a-f]{8}$/', $ref);

        // Full detail goes to the file log...
        $errors = \BCC\Core\Log\Logger::ofLevel('error');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('wp_bcc_chains missing', $errors[0]['context']['message']);
        $this->assertSame($ref, $errors[0]['context']['correlation_id']);

        // ...and a durable failure row is still written.
        $this->assertSame(['admin_nft_indexer_run_failed'], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    public function testFailureMessageLeaksNothingFromTheException(): void
    {
        $e = new \RuntimeException('SQLSTATE[42S02]: Base table wp_bcc_chains missing at /var/www/secret.php');

        $ref     = AdminActionSupport::failure($e, 'admin_nft_indexer_run_failed', 'chain', 9);
        $message = AdminActionSupport::failureMessage($ref);

        $this->assertStringContainsString($ref, $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('wp_bcc_chains', $message);
        $this->assertStringNotContainsString('/var/www', $message);
        $this->assertStringNotContainsString('RuntimeException', $message);
    }

    public function testRedirectTerminatesTheRequest(): void
    {
        try {
            AdminActionSupport::redirect(['page' => 'bcc-onchain-chains', 'bcc_result' => 'ok']);
            $this->fail('redirect() must terminate the request.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('bcc-onchain-chains', $r->args['page']);
            $this->assertSame('ok', $r->args['bcc_result']);
        }
    }
}
