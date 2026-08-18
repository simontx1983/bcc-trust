<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\Views\NftSpamContractsView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Spam-contract rules decide what every user sees, and both mutations were
 * previously unlogged — adding a platform-wide DENY rule left no trace.
 *
 * Pins:
 *   - capability failure halts instead of returning silently;
 *   - the remove nonce is bound to the exact (chain, contract) pair;
 *   - the chain must resolve through the repository;
 *   - both mutations write a durable audit row;
 *   - "rule was already gone" is audited as a no-op, NOT as a removal.
 */
#[CoversClass(NftSpamContractsView::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OnchainAdminSpamRuleActionsTest extends TestCase
{
    private const CHAIN_ID = 4;
    private const CONTRACT = '0xdeadbeef';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/onchain-admin-action-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::reset();

        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN_ID, 'ethereum');

        $_POST = [];
        $_GET  = [];
    }

    private function seedAddPost(string $rule = 'deny'): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        $_POST['contract'] = self::CONTRACT;
        $_POST['rule']     = $rule;
        $_POST['reason']   = 'airdrop spam';
    }

    public function testRegistersOnlyAdminPostHandlers(): void
    {
        $GLOBALS['bcc_test_registered_actions'] = [];

        NftSpamContractsView::register_actions();

        $this->assertSame([
            'admin_post_bcc_nft_spam_add',
            'admin_post_bcc_nft_spam_remove',
        ], $GLOBALS['bcc_test_registered_actions']);
    }

    // ── Add ─────────────────────────────────────────────────────────────────

    public function testAddHaltsWithoutCapabilityInsteadOfReturningSilently(): void
    {
        \BccAdminTestState::$can = false;
        $this->seedAddPost();

        try {
            NftSpamContractsView::handleAdd();
            $this->fail('Expected a 403 halt.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertSame([], \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::$added);
    }

    public function testAddRejectsInvalidNonce(): void
    {
        $this->seedAddPost();
        \BccAdminTestState::$validNonceAction = null;

        $this->expectException(\BccAdminDie::class);
        try {
            NftSpamContractsView::handleAdd();
        } finally {
            $this->assertSame([], \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::$added);
        }
    }

    public function testAddRejectsRemoveNonce(): void
    {
        $this->seedAddPost();
        \BccAdminTestState::$validNonceAction = 'bcc_nft_spam_remove_4_0xdeadbeef';

        $this->expectException(\BccAdminDie::class);
        try {
            NftSpamContractsView::handleAdd();
        } finally {
            $this->assertSame([], \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::$added);
        }
    }

    public function testAddRejectsUnknownRuleValue(): void
    {
        $this->seedAddPost('obliterate');
        \BccAdminTestState::$validNonceAction = 'bcc_nft_spam_add';

        try {
            NftSpamContractsView::handleAdd();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('add_invalid', $r->args['bcc_spam_result']);
        }

        $this->assertSame([], \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::$added);
        $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
    }

    public function testAddRejectsUnresolvableChain(): void
    {
        $this->seedAddPost();
        $_POST['chain_id'] = 999;
        \BccAdminTestState::$validNonceAction = 'bcc_nft_spam_add';

        try {
            NftSpamContractsView::handleAdd();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('add_invalid', $r->args['bcc_spam_result']);
        }

        $this->assertSame([], \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::$added);
    }

    public function testAddSucceedsAuditsAndRedirects(): void
    {
        $this->seedAddPost();
        \BccAdminTestState::$validNonceAction = 'bcc_nft_spam_add';

        try {
            NftSpamContractsView::handleAdd();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('bcc-onchain-signals', $r->args['page']);
            $this->assertSame('spam', $r->args['tab']);
            $this->assertSame('add_ok', $r->args['bcc_spam_result']);
        }

        $added = \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::$added;
        $this->assertCount(1, $added);
        $this->assertSame('deny', $added[0]['rule']);

        $rows = \BCC\Trust\Core\Security\AuditLogger::$rows;
        $this->assertSame(['admin_nft_spam_rule_added'], \BCC\Trust\Core\Security\AuditLogger::actions());
        $this->assertSame('nft_spam_rule', $rows[0]['targetType']);
        $this->assertSame(self::CHAIN_ID, $rows[0]['targetId']);
        $this->assertSame(self::CONTRACT, $rows[0]['meta']['contract']);
    }

    public function testAddExceptionIsSanitisedAndAudited(): void
    {
        $this->seedAddPost();
        \BccAdminTestState::$validNonceAction = 'bcc_nft_spam_add';
        \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::$addThrows =
            new \RuntimeException('Duplicate entry for key wp_bcc_nft_spam_contracts.uniq');

        try {
            NftSpamContractsView::handleAdd();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('add_failed', $r->args['bcc_spam_result']);
            $this->assertStringNotContainsString('Duplicate entry', $r->url);
            $this->assertStringNotContainsString('wp_bcc_nft_spam_contracts', $r->url);
        }

        $this->assertSame(['admin_nft_spam_rule_failed'], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    // ── Remove ──────────────────────────────────────────────────────────────

    public function testRemoveNonceIsBoundToTheExactContract(): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        $_POST['contract'] = self::CONTRACT;
        // Nonce minted for a DIFFERENT contract on the same chain.
        \BccAdminTestState::$validNonceAction = 'bcc_nft_spam_remove_4_0xcafebabe';

        $this->expectException(\BccAdminDie::class);
        try {
            NftSpamContractsView::handleRemove();
        } finally {
            $this->assertSame([], \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::$removed);
        }
    }

    public function testRemoveSucceedsAndAudits(): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        $_POST['contract'] = self::CONTRACT;
        \BccAdminTestState::$validNonceAction = 'bcc_nft_spam_remove_4_0xdeadbeef';

        try {
            NftSpamContractsView::handleRemove();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('remove_ok', $r->args['bcc_spam_result']);
        }

        $this->assertCount(1, \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::$removed);
        $this->assertSame(['admin_nft_spam_rule_removed'], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    public function testRemoveOfAlreadyGoneRuleIsNotAuditedAsARemoval(): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        $_POST['contract'] = self::CONTRACT;
        \BccAdminTestState::$validNonceAction = 'bcc_nft_spam_remove_4_0xdeadbeef';
        \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::$removeResult = false;

        try {
            NftSpamContractsView::handleRemove();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('remove_missing', $r->args['bcc_spam_result']);
        }

        // A no-op must not leave a row implying state changed.
        $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    public function testRemoveWithMissingContractDoesNotReachTheNonceCheck(): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        $_POST['contract'] = '';

        try {
            NftSpamContractsView::handleRemove();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('remove_failed', $r->args['bcc_spam_result']);
        }

        $this->assertSame([], \BccAdminTestState::$nonceChecks);
        $this->assertSame([], \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::$removed);
    }
}
