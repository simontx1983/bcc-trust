<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\ChainSweepActions;
use BCC\Trust\Onchain\Admin\ChainsPage;
use BCC\Trust\Onchain\Admin\Views\NftIndexerStatusView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the rendered CONTROLS, not just the handlers.
 *
 * A handler can be perfectly gated and still be unreachable-as-intended if
 * the form posts to the wrong place, carries the wrong nonce scope, or ships
 * a confirmation that lies about the blast radius. Three of these nine
 * controls previously had no confirmation at all, and eight of the nine were
 * GET links.
 *
 * Deliberately narrow: each test renders ONE control and asserts specific
 * properties. No whole-page snapshots.
 */
#[CoversClass(ChainsPage::class)]
#[CoversClass(NftIndexerStatusView::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OnchainAdminConfirmationRenderTest extends TestCase
{
    private const CHAIN_ID = 4;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/onchain-admin-render-stubs.php';

        \BccAdminTestState::reset();
        $_POST = [];
        $_GET  = [];
    }

    private function renderSweepBar(): string
    {
        ob_start();
        ChainsPage::render_sweep_bar();
        return (string) ob_get_clean();
    }

    private function renderChainActions(string $state): string
    {
        ob_start();
        NftIndexerStatusView::renderChainActionForms(self::CHAIN_ID, $state, 'ethereum');
        return (string) ob_get_clean();
    }

    private function renderHelius(string $webhookId): string
    {
        ob_start();
        NftIndexerStatusView::renderHeliusActionForm($webhookId);
        return (string) ob_get_clean();
    }

    // ── Structure: POST to admin-post.php, no GET links anywhere ────────────

    public function testEveryControlRendersAsAPostFormToAdminPost(): void
    {
        $markup = $this->renderSweepBar()
            . $this->renderChainActions('healthy')
            . $this->renderChainActions('disabled')
            . $this->renderHelius('')
            . $this->renderHelius('wh_123');

        // 4 sweeps + run + pause + run + resume + provision + resync = 10 forms.
        $this->assertSame(10, substr_count($markup, '<form method="post"'));
        $this->assertSame(10, substr_count($markup, 'admin-post.php'));

        // Not one anchor-as-button remains.
        $this->assertStringNotContainsString('<a class="button"', $markup);
        $this->assertStringNotContainsString('<a href=', $markup);
    }

    public function testOldGetMutationParametersAreAbsentFromTheMarkup(): void
    {
        $markup = $this->renderSweepBar()
            . $this->renderChainActions('healthy')
            . $this->renderHelius('')
            . $this->renderHelius('wh_123');

        foreach ([
            'bcc_run_index_all',
            'bcc_run_index_validators',
            'bcc_run_index_collections',
            'bcc_run_enrich_validators',
            'bcc_onchain_admin_trigger',
            'action=run',
            'action=set_state',
            'action=helius_provision',
            'action=helius_resync',
        ] as $legacy) {
            $this->assertStringNotContainsString($legacy, $markup, "Legacy GET token still rendered: {$legacy}");
        }
    }

    // ── The four sweeps ─────────────────────────────────────────────────────

    /** @return list<array{0: string, 1: string}> */
    public static function sweepMatrix(): array
    {
        return [
            [ChainSweepActions::ACTION_ALL, 'All (validators + collections + enrichment)'],
            [ChainSweepActions::ACTION_VALIDATORS, 'Validators only'],
            [ChainSweepActions::ACTION_COLLECTIONS, 'Collections only'],
            [ChainSweepActions::ACTION_ENRICHMENT, 'Enrichment only'],
        ];
    }

    #[DataProvider('sweepMatrix')]
    public function testEachSweepRendersItsActionAndItsOwnNonceScope(string $action, string $label): void
    {
        $markup = $this->renderSweepBar();

        $this->assertStringContainsString('value="' . $action . '"', $markup);
        $this->assertStringContainsString('data-nonce-action="' . $action . '"', $markup);
        $this->assertStringContainsString($label, $markup);
    }

    public function testSweepNoncesAreFourDistinctScopesNotOneShared(): void
    {
        preg_match_all('/data-nonce-action="([^"]+)"/', $this->renderSweepBar(), $m);

        $this->assertCount(4, $m[1]);
        $this->assertCount(4, array_unique($m[1]), 'The four sweeps must not share a nonce.');
        $this->assertNotContains('bcc_onchain_admin_trigger', $m[1]);
    }

    #[DataProvider('sweepMatrix')]
    public function testEverySweepCarriesAConfirmation(string $action, string $label): void
    {
        $markup = $this->renderSweepBar();

        // Four forms, four confirms — including the three that had none.
        $this->assertSame(4, substr_count($markup, 'onsubmit="return confirm('));
        $this->assertStringContainsString($label, $markup);
    }

    public function testSweepConfirmationsDescribeTheRealBlastRadius(): void
    {
        foreach (ChainsPage::sweepControls() as $control) {
            $confirm = $control['confirm'];

            $this->assertStringContainsStringIgnoringCase(
                'EVERY active chain',
                $confirm,
                $control['action'] . ' must disclose that it spans every active chain.'
            );
            $this->assertStringContainsStringIgnoringCase(
                'quota',
                $confirm,
                $control['action'] . ' must disclose provider-quota consumption.'
            );
        }
    }

    public function testTheFullSweepDisclosesThatItCannotBeCancelled(): void
    {
        $all = ChainsPage::sweepControls()[0];

        $this->assertSame(ChainSweepActions::ACTION_ALL, $all['action']);
        $this->assertStringContainsString('cannot be cancelled', $all['confirm']);
        $this->assertStringContainsString('synchronously', $all['confirm']);
    }

    // ── Run now / Pause / Resume ────────────────────────────────────────────

    public function testRunNowPostsWithAChainScopedNonce(): void
    {
        $markup = $this->renderChainActions('healthy');

        $this->assertStringContainsString('value="bcc_nft_indexer_run"', $markup);
        $this->assertStringContainsString('data-nonce-action="bcc_nft_indexer_run_4"', $markup);
        $this->assertStringContainsString('Run now', $markup);
    }

    public function testPauseAndResumeUseDistinctStateScopedNonces(): void
    {
        $pauseMarkup  = $this->renderChainActions('healthy');
        $resumeMarkup = $this->renderChainActions('disabled');

        $this->assertStringContainsString('data-nonce-action="bcc_nft_indexer_set_state_4_disabled"', $pauseMarkup);
        $this->assertStringContainsString('>Pause<', $pauseMarkup);

        $this->assertStringContainsString('data-nonce-action="bcc_nft_indexer_set_state_4_healthy"', $resumeMarkup);
        $this->assertStringContainsString('>Resume<', $resumeMarkup);

        // The two must not be interchangeable.
        $this->assertStringNotContainsString('set_state_4_healthy', $pauseMarkup);
        $this->assertStringNotContainsString('set_state_4_disabled', $resumeMarkup);
    }

    public function testRunAndStateControlsBothCarryConfirmations(): void
    {
        $this->assertSame(2, substr_count($this->renderChainActions('healthy'), 'onsubmit="return confirm('));
        $this->assertSame(2, substr_count($this->renderChainActions('disabled'), 'onsubmit="return confirm('));
    }

    public function testRunConfirmationDisclosesQuotaConsumption(): void
    {
        $confirms = NftIndexerStatusView::chainActionConfirmations('ethereum', false);

        $this->assertStringContainsString('ethereum', $confirms['run']);
        $this->assertStringContainsString('Alchemy', $confirms['run']);
        $this->assertStringContainsString('CU budget', $confirms['run']);
    }

    public function testPauseConfirmationDisclosesTheIndefiniteConsequence(): void
    {
        $pause = NftIndexerStatusView::chainActionConfirmations('ethereum', false)['state'];

        // Pause has no expiry and nothing resumes it — the copy must say so.
        $this->assertStringContainsString('indefinitely', $pause);
        $this->assertStringContainsString('no automatic resume', $pause);
        $this->assertStringContainsString('go stale', $pause);
    }

    public function testResumeConfirmationIsScopedToTheChain(): void
    {
        $resume = NftIndexerStatusView::chainActionConfirmations('ethereum', true)['state'];

        $this->assertStringContainsString('Resume indexing for ethereum', $resume);
    }

    // ── Helius (external provider state) ────────────────────────────────────

    public function testProvisionRendersOnlyWhenNoWebhookExists(): void
    {
        $none = $this->renderHelius('');
        $this->assertStringContainsString('value="bcc_helius_provision"', $none);
        $this->assertStringContainsString('data-nonce-action="bcc_helius_provision"', $none);
        $this->assertStringNotContainsString('bcc_helius_resync', $none);

        $existing = $this->renderHelius('wh_123');
        $this->assertStringContainsString('value="bcc_helius_resync"', $existing);
        $this->assertStringContainsString('data-nonce-action="bcc_helius_resync"', $existing);
        $this->assertStringNotContainsString('bcc_helius_provision', $existing);
    }

    public function testBothHeliusControlsCarryConfirmations(): void
    {
        $this->assertSame(1, substr_count($this->renderHelius(''), 'onsubmit="return confirm('));
        $this->assertSame(1, substr_count($this->renderHelius('wh_123'), 'onsubmit="return confirm('));
    }

    public function testHeliusConfirmationsDiscloseThatTheyMutateRemoteState(): void
    {
        $confirms = NftIndexerStatusView::heliusConfirmations();

        // "Provision" and "Resync" both read like local operations. The copy
        // has to say otherwise — these are the two controls in the whole
        // admin that change state on a third-party provider.
        $this->assertStringContainsString('on Helius', $confirms['provision']);
        $this->assertStringContainsString('billable external resource', $confirms['provision']);

        $this->assertStringContainsString('remote Helius webhook', $confirms['resync']);
        $this->assertStringContainsString('PATCHes the live webhook', $confirms['resync']);
    }

    public function testConfirmationTextSurvivesJsonEncodingIntoTheAttribute(): void
    {
        // The confirmations contain newlines and apostrophes; a broken encode
        // would silently drop the prompt and submit on click.
        $markup = $this->renderSweepBar();

        $this->assertStringContainsString('\n\n', $markup, 'Newlines must survive as JSON escapes.');
        $this->assertStringNotContainsString('onsubmit="return confirm();"', $markup);
        $this->assertStringNotContainsString('onsubmit="return confirm(&quot;&quot;);"', $markup);
    }
}
