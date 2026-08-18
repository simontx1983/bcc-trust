<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\Views\NftIndexerStatusView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The NFT-indexer controls were four GET action families handled during
 * render, with silent returns on nonce/capability failure and the strongest
 * raw-exception-to-browser path in the on-chain admin. Two of them mutate
 * EXTERNAL Helius state.
 *
 * Pins:
 *   - POST-only dispatch through named admin-post handlers;
 *   - per-chain AND per-target-state nonce scoping (a Pause nonce cannot
 *     drive a Resume, and a chain-4 nonce cannot touch chain 9);
 *   - chain ids must resolve through the repository, not merely be > 0;
 *   - an unknown state is rejected BEFORE a nonce is built from it;
 *   - success, no-op and failure are three distinguishable audit outcomes;
 *   - the worker's own bounds are reached unchanged (the handler passes the
 *     chain id straight through and adds no batching of its own).
 */
#[CoversClass(NftIndexerStatusView::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OnchainAdminIndexerActionsTest extends TestCase
{
    private const CHAIN_ID = 4;
    private const OTHER_CHAIN_ID = 9;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/onchain-admin-action-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::reset();
        \BCC\Trust\Onchain\Workers\NftEthIndexerWorker::reset();
        \BCC\Trust\Onchain\Services\HeliusSubscriptionManager::reset();

        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN_ID, 'ethereum');
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::OTHER_CHAIN_ID, 'base');

        $_POST = [];
        $_GET  = [];
    }

    // ── Registration ────────────────────────────────────────────────────────

    public function testRegistersOnlyAdminPostHandlers(): void
    {
        $GLOBALS['bcc_test_registered_actions'] = [];

        NftIndexerStatusView::register_actions();

        $this->assertSame([
            'admin_post_bcc_nft_indexer_run',
            'admin_post_bcc_nft_indexer_set_state',
            'admin_post_bcc_helius_provision',
            'admin_post_bcc_helius_resync',
        ], $GLOBALS['bcc_test_registered_actions']);
    }

    // ── Run now ─────────────────────────────────────────────────────────────

    public function testRunRequiresCapability(): void
    {
        \BccAdminTestState::$can = false;
        $_POST['chain_id'] = self::CHAIN_ID;

        $this->expectException(\BccAdminDie::class);
        try {
            NftIndexerStatusView::handleRun();
        } finally {
            $this->assertSame([], \BCC\Trust\Onchain\Workers\NftEthIndexerWorker::$runs);
        }
    }

    public function testRunRejectsNonceMintedForAnotherChain(): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        \BccAdminTestState::$validNonceAction = 'bcc_nft_indexer_run_' . self::OTHER_CHAIN_ID;

        $this->expectException(\BccAdminDie::class);
        try {
            NftIndexerStatusView::handleRun();
        } finally {
            $this->assertSame([], \BCC\Trust\Onchain\Workers\NftEthIndexerWorker::$runs);
        }
    }

    public function testRunRejectsUnknownChainOnlyAfterProvingTheRequestAuthentic(): void
    {
        $_POST['chain_id'] = 999; // positive shape, but not in the repository
        \BccAdminTestState::$validNonceAction = 'bcc_nft_indexer_run_999';

        try {
            NftIndexerStatusView::handleRun();
            $this->fail('Expected a 400 halt for an unresolvable chain.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(400, $e->status);
        }

        $this->assertSame([], \BCC\Trust\Onchain\Workers\NftEthIndexerWorker::$runs);

        // Ordering contract: the nonce IS verified before the repository is
        // consulted. Resolving the chain first would let an unauthenticated
        // request probe which chain ids exist.
        $this->assertSame(
            [['action' => 'bcc_nft_indexer_run_999', 'arg' => '_wpnonce']],
            \BccAdminTestState::$nonceChecks
        );
    }

    public function testRunWithABadNonceNeverTouchesTheRepository(): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        \BccAdminTestState::$validNonceAction = null;

        $this->expectException(\BccAdminDie::class);
        try {
            NftIndexerStatusView::handleRun();
        } finally {
            // No domain work at all for a CSRF-failed request.
            $this->assertSame([], \BCC\Trust\Onchain\Workers\NftEthIndexerWorker::$runs);
            $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
        }
    }

    public function testRunWithZeroChainIdIsRejectedBeforeAnyNonceIsBuilt(): void
    {
        $_POST['chain_id'] = 0;

        try {
            NftIndexerStatusView::handleRun();
            $this->fail('Expected a 400 halt.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(400, $e->status);
        }

        // A zero id cannot produce a meaningful target-scoped nonce action,
        // so the shape check precedes it.
        $this->assertSame([], \BccAdminTestState::$nonceChecks);
        $this->assertSame([], \BCC\Trust\Onchain\Workers\NftEthIndexerWorker::$runs);
    }

    public function testRunDispatchesOnceAndRedirects(): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        \BccAdminTestState::$validNonceAction = 'bcc_nft_indexer_run_' . self::CHAIN_ID;

        try {
            NftIndexerStatusView::handleRun();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('bcc-onchain-signals', $r->args['page']);
            $this->assertSame('nft', $r->args['tab']);
            $this->assertSame('ran', $r->args['bcc_nft_result']);
        }

        // Exactly once — the handler adds no retry of its own, and the
        // worker's advisory lock / CU budget / BLOCKS_PER_TICK are untouched.
        $this->assertSame([self::CHAIN_ID], \BCC\Trust\Onchain\Workers\NftEthIndexerWorker::$runs);
        $this->assertSame(['admin_nft_indexer_run'], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    public function testRunFailureHidesExceptionAndAuditsFailure(): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        \BccAdminTestState::$validNonceAction = 'bcc_nft_indexer_run_' . self::CHAIN_ID;
        \BCC\Trust\Onchain\Workers\NftEthIndexerWorker::$throws =
            new \RuntimeException('Alchemy 429 at /var/www/html/wp-content/plugins/bcc-trust/x.php:88');

        try {
            NftIndexerStatusView::handleRun();
            $this->fail('Expected PRG redirect after failure.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('run_failed', $r->args['bcc_nft_result']);
            $this->assertMatchesRegularExpression('/^bcc-[0-9a-f]{8}$/', $r->args['bcc_ref']);
            $this->assertStringNotContainsString('Alchemy 429', $r->url);
            $this->assertStringNotContainsString('/var/www', $r->url);
        }

        $this->assertSame(['admin_nft_indexer_run_failed'], \BCC\Trust\Core\Security\AuditLogger::actions());
        $this->assertStringContainsString(
            'Alchemy 429',
            \BCC\Core\Log\Logger::ofLevel('error')[0]['context']['message']
        );
    }

    // ── Pause / Resume ──────────────────────────────────────────────────────

    public function testPauseAndResumeUseDistinctNonces(): void
    {
        // Pause and Resume deliberately share one route, so the nonce must be
        // bound to the requested STATE as well as the chain — otherwise a
        // Pause nonce would silently authorize a Resume.
        $_POST['chain_id'] = self::CHAIN_ID;
        $_POST['state']    = 'healthy';
        \BccAdminTestState::$validNonceAction = 'bcc_nft_indexer_set_state_' . self::CHAIN_ID . '_disabled';

        $this->expectException(\BccAdminDie::class);
        try {
            NftIndexerStatusView::handleSetState();
        } finally {
            $this->assertSame([], \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$stateWrites);
        }
    }

    public function testPauseWritesDisabledStateAndAudits(): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        $_POST['state']    = 'disabled';
        \BccAdminTestState::$validNonceAction = 'bcc_nft_indexer_set_state_' . self::CHAIN_ID . '_disabled';

        try {
            NftIndexerStatusView::handleSetState();
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('paused', $r->args['bcc_nft_result']);
        }

        $this->assertSame(
            [['chain_id' => self::CHAIN_ID, 'state' => 'disabled']],
            \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$stateWrites
        );
        $this->assertSame(['admin_nft_indexer_paused'], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    public function testResumeIsAuditedDistinctlyFromPause(): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        $_POST['state']    = 'healthy';
        \BccAdminTestState::$validNonceAction = 'bcc_nft_indexer_set_state_' . self::CHAIN_ID . '_healthy';

        try {
            NftIndexerStatusView::handleSetState();
        } catch (\BccAdminRedirect) {
            // expected
        }

        $this->assertSame(['admin_nft_indexer_resumed'], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    public function testUnknownStateIsRejectedBeforeAnyNonceIsBuilt(): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        $_POST['state']    = 'obliterated';
        \BccAdminTestState::$validNonceAction = 'bcc_nft_indexer_set_state_' . self::CHAIN_ID . '_obliterated';

        try {
            NftIndexerStatusView::handleSetState();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('state_invalid', $r->args['bcc_nft_result']);
        }

        $this->assertSame([], \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$stateWrites);
        $this->assertSame([], \BccAdminTestState::$nonceChecks);
        $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
    }

    // ── Helius (external provider state) ────────────────────────────────────

    public function testHeliusProvisionRequiresItsOwnNonce(): void
    {
        // A resync nonce must not authorize creating a new remote webhook.
        \BccAdminTestState::$validNonceAction = 'bcc_helius_resync';

        $this->expectException(\BccAdminDie::class);
        try {
            NftIndexerStatusView::handleHeliusProvision();
        } finally {
            $this->assertSame(0, \BCC\Trust\Onchain\Services\HeliusSubscriptionManager::$provisionCalls);
        }
    }

    public function testHeliusProvisionSuccessAudits(): void
    {
        \BccAdminTestState::$validNonceAction = 'bcc_helius_provision';

        try {
            NftIndexerStatusView::handleHeliusProvision();
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('helius_provisioned', $r->args['bcc_nft_result']);
        }

        $this->assertSame(1, \BCC\Trust\Onchain\Services\HeliusSubscriptionManager::$provisionCalls);
        $this->assertSame(['admin_helius_webhook_provisioned'], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    public function testHeliusProvisionNullResultIsAuditedAsFailureNotSuccess(): void
    {
        \BccAdminTestState::$validNonceAction = 'bcc_helius_provision';
        \BCC\Trust\Onchain\Services\HeliusSubscriptionManager::$provisionResult = null;

        try {
            NftIndexerStatusView::handleHeliusProvision();
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('helius_provision_failed', $r->args['bcc_nft_result']);
        }

        $this->assertSame(
            ['admin_helius_webhook_provision_failed'],
            \BCC\Trust\Core\Security\AuditLogger::actions()
        );
    }

    public function testHeliusResyncDistinguishesAppliedFromNoOp(): void
    {
        \BccAdminTestState::$validNonceAction = 'bcc_helius_resync';
        \BCC\Trust\Onchain\Services\HeliusSubscriptionManager::$resyncResult =
            ['applied' => false, 'remote_count' => 3, 'local_count' => 3];

        try {
            NftIndexerStatusView::handleHeliusResync();
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('helius_resync_noop', $r->args['bcc_nft_result']);
            $this->assertSame('3', $r->args['bcc_remote']);
        }

        // A no-op must not read as a state change in the audit trail.
        $this->assertSame(['admin_helius_resync_noop'], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    public function testHeliusResyncAppliedIsAudited(): void
    {
        \BccAdminTestState::$validNonceAction = 'bcc_helius_resync';

        try {
            NftIndexerStatusView::handleHeliusResync();
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('helius_resynced', $r->args['bcc_nft_result']);
        }

        $this->assertSame(['admin_helius_addresses_resynced'], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    public function testHeliusResyncExceptionIsSanitised(): void
    {
        \BccAdminTestState::$validNonceAction = 'bcc_helius_resync';
        \BCC\Trust\Onchain\Services\HeliusSubscriptionManager::$resyncThrows =
            new \RuntimeException('helius api key sk_live_ABCDEF rejected');

        try {
            NftIndexerStatusView::handleHeliusResync();
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('helius_resync_failed', $r->args['bcc_nft_result']);
            $this->assertStringNotContainsString('sk_live_ABCDEF', $r->url);
        }

        $this->assertSame(['admin_helius_resync_failed'], \BCC\Trust\Core\Security\AuditLogger::actions());
    }
}
