<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\ChainsPage;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Chains ▸ NFT Discovery ▸ CosmWasm / CW-721 — the rendered sub-tab.
 *
 * ── WHY THE TAB IS NAMED FOR THE CAPABILITY ─────────────────────────────
 * NFT discovery is not a Cosmos concern. EVM NFTs have their own worker,
 * Solana arrives through Helius, and further standards may follow. The tab
 * therefore owns per-chain NFT-discovery CONFIGURATION and is organised
 * into engine-scoped sections; VC-B2 ships exactly one.
 *
 * The trap this file exists to catch is the OPPOSITE of a missing feature:
 * a page called "NFT Discovery" that silently applies one engine's verdicts
 * to every chain would tell an operator that Ethereum is "not eligible for
 * NFT discovery", which is false and unfixable from this screen. So the
 * assertions below check what is NOT said as carefully as what is.
 */
#[CoversClass(ChainsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ChainsNftDiscoveryTabTest extends TestCase
{
    private const COSMOS_A = 4;   // opted in
    private const COSMOS_B = 9;   // not opted in
    private const COSMOS_C = 12;  // not opted in, no wasm module

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/chains-cw-discovery-stubs.php';

        \BccAdminTestState::reset();
        ChainRepository::reset();
        CosmwasmDiscoveryHealthSnapshot::reset();

        $_GET  = [];
        $_POST = [];

        ChainRepository::seed(self::COSMOS_A, 'cosmos', true);
        ChainRepository::seed(self::COSMOS_B, 'juno', false);
        ChainRepository::seed(self::COSMOS_C, 'cryptoorg', false);

        // The snapshot is the ONE authoritative status source. Only
        // CosmWasm-engine candidates are ever in it — EVM and Solana chains
        // are not, which is why they cannot receive a CosmWasm verdict.
        CosmwasmDiscoveryHealthSnapshot::$chains = [
            CosmwasmDiscoveryHealthSnapshot::chainRow(self::COSMOS_A, 'cosmos', true),
            CosmwasmDiscoveryHealthSnapshot::chainRow(self::COSMOS_B, 'juno', false),
            CosmwasmDiscoveryHealthSnapshot::chainRow(self::COSMOS_C, 'cryptoorg', false, [
                'unsupported' => true,
                'eligibility' => CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_UNSUPPORTED,
                'eligibility_reason' => 'This chain reports no CosmWasm module.',
            ]),
        ];
    }

    private function render(): string
    {
        $m = new \ReflectionMethod(ChainsPage::class, 'render_nft_discovery_tab');
        $m->setAccessible(true);

        ob_start();
        $m->invoke(null);

        return (string) ob_get_clean();
    }

    private function dom(): \DOMDocument
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<!DOCTYPE html><html><body>' . $this->render() . '</body></html>');
        libxml_clear_errors();

        return $doc;
    }

    /** @return list<\DOMElement> */
    private function elements(\DOMDocument $doc, string $tag): array
    {
        $out = [];
        foreach ($doc->getElementsByTagName($tag) as $el) {
            if ($el instanceof \DOMElement) {
                $out[] = $el;
            }
        }

        return $out;
    }

    private function hiddenValue(\DOMElement $form, string $name): ?string
    {
        foreach ($form->getElementsByTagName('input') as $i) {
            if ($i instanceof \DOMElement && $i->getAttribute('name') === $name) {
                return $i->getAttribute('value');
            }
        }

        return null;
    }

    private function nonceAction(\DOMElement $form): ?string
    {
        foreach ($form->getElementsByTagName('input') as $i) {
            if ($i instanceof \DOMElement && $i->hasAttribute('data-nonce-action')) {
                return $i->getAttribute('data-nonce-action');
            }
        }

        return null;
    }

    /**
     * The two DISCOVERY opt-in forms, separated from the VC-B3b scanner
     * operations that now share the section.
     *
     * This file owns the opt-in control. Selecting by route rather than
     * counting every form on the page is what keeps its assertions honest
     * after the move: a bare `assertCount(3, $forms)` would have started
     * failing for a correct page, and "fixing" it by raising the number to
     * 5 would have quietly stopped checking that each chain offers exactly
     * one direction. Pause, Resume, Backfill and Retry are asserted in
     * ChainsCwScannerOperationsDomTest, which owns them.
     *
     * @return list<\DOMElement>
     */
    private function discoveryForms(\DOMDocument $doc): array
    {
        $routes = [ChainsPage::ACTION_CW_DISCOVERY_ENABLE, ChainsPage::ACTION_CW_DISCOVERY_DISABLE];

        $out = [];
        foreach ($this->elements($doc, 'form') as $form) {
            if (in_array((string) $this->hiddenValue($form, 'action'), $routes, true)) {
                $out[] = $form;
            }
        }

        return $out;
    }

    /** @return list<\DOMElement> */
    private function discoveryButtons(\DOMDocument $doc): array
    {
        $out = [];
        foreach ($this->discoveryForms($doc) as $form) {
            foreach ($form->getElementsByTagName('button') as $b) {
                if ($b instanceof \DOMElement) {
                    $out[] = $b;
                }
            }
        }

        return $out;
    }

    // ── Registration and identity ───────────────────────────────────────

    public function testTheCanonicalSlugIsNftDiscovery(): void
    {
        $this->assertSame('nft-discovery', ChainsPage::SUBTAB_NFT_DISCOVERY);
    }

    public function testTheSubtabIsRegisteredInTheChainsTabList(): void
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../../app/Domain/Onchain/Admin/ChainsPage.php'
        );

        // Accepted by the tab whitelist…
        $this->assertStringContainsString(
            "in_array(\$activeTab, ['validators', 'identity', self::SUBTAB_NFT_DISCOVERY], true)",
            $source
        );
        // …linked in the nav with the canonical `subtab=` parameter…
        $this->assertStringContainsString("add_query_arg('subtab', self::SUBTAB_NFT_DISCOVERY)", $source);
        // …labelled for the capability, not the engine…
        $this->assertStringContainsString('NFT Discovery', $source);
        // …and dispatched to its renderer.
        $this->assertStringContainsString('self::render_nft_discovery_tab()', $source);
    }

    public function testTheSectionHeadingNamesTheEngine(): void
    {
        $doc = $this->dom();

        $headings = [];
        foreach ($this->elements($doc, 'h2') as $h) {
            $headings[] = trim($h->textContent);
        }

        $this->assertContains('CosmWasm / CW-721 Discovery', $headings);
    }

    // ── Scope: what is and is not claimed about other chain families ────

    public function testTheCrossChainScopeExplanationIsPresent(): void
    {
        $text = preg_replace('/\s+/', ' ', strip_tags($this->render())) ?? '';

        $this->assertStringContainsString(
            'NFT discovery can use different engines for different chain families.',
            $text
        );
        $this->assertStringContainsString(
            'This section currently manages automatic CW-721 discovery for CosmWasm-enabled chains only.',
            $text
        );
        $this->assertStringContainsString(
            'It does not control EVM NFTs, Solana NFTs, Helius indexing, manual verification or community provisioning.',
            $text
        );
    }

    public function testNonCosmwasmChainsAreNeverDescribedAsNftIneligible(): void
    {
        $text = preg_replace('/\s+/', ' ', strip_tags($this->render())) ?? '';

        // The page must say the absence means "not managed here"…
        $this->assertStringContainsString('not being described as unable to support NFTs', $text);
        $this->assertStringContainsString('not managed by this engine', $text);

        // …and must never make the opposite claim.
        foreach ([
            'EVM chains are not eligible',
            'Solana is not eligible',
            'does not support NFTs',
            'cannot do NFT discovery',
            'unsupported for NFTs',
            'blocked from NFT discovery',
        ] as $slander) {
            $this->assertStringNotContainsString($slander, $text);
        }
    }

    public function testOnlyCosmwasmEngineCandidatesReceiveCosmwasmVerdicts(): void
    {
        // An EVM chain exists in the registry…
        ChainRepository::seed(77, 'ethereum', false);
        ChainRepository::$rows[77]->chain_type = 'evm';

        // …but is absent from the authoritative snapshot, so it can never
        // be handed a CosmWasm eligibility verdict.
        $text = strip_tags($this->render());

        $this->assertStringNotContainsString('ethereum', $text);
        $this->assertStringNotContainsString('77', $this->render());

        // Every rendered CHAIN row is a CosmWasm candidate.
        //
        // VC-B3a added a per-chain status detail row, which carries scanner
        // state rather than the chain identity — it is a continuation of the
        // row above, not a new chain. It is marked and skipped here rather
        // than weakening the check into something that would pass for a
        // genuinely mislabelled chain.
        $chainRows = 0;
        foreach ($this->elements($this->dom(), 'tr') as $tr) {
            if ($tr->getElementsByTagName('td')->length === 0) {
                continue;
            }
            // VC-B3a added a status detail row and VC-B3b an operations
            // row. Both are continuations of the identity row above them
            // and carry no chain identity of their own, so they are marked
            // and skipped rather than counted as chains.
            $class = $tr->getAttribute('class');
            if (str_contains($class, 'bcc-cw-status-row')
                || str_contains($class, 'bcc-cw-operations-row')
            ) {
                continue;
            }
            $chainRows++;
            $this->assertStringContainsString('cosmos', $tr->textContent);
        }

        $this->assertSame(3, $chainRows, 'one identity row per CosmWasm candidate');
    }

    public function testTheTabUsesTheSharedHealthSnapshotOnce(): void
    {
        CosmwasmDiscoveryHealthSnapshot::reset();
        CosmwasmDiscoveryHealthSnapshot::$chains = [
            CosmwasmDiscoveryHealthSnapshot::chainRow(self::COSMOS_A, 'cosmos', true),
        ];

        $this->render();

        $this->assertSame(
            1,
            CosmwasmDiscoveryHealthSnapshot::$summaryCalls,
            'the tab reads the shared snapshot exactly once per render'
        );
    }

    /**
     * THE STRONGER CLAIM: presentation does not recompute status.
     *
     * Counting snapshot calls proves the snapshot is used; it does not
     * prove nothing else is. This does: the section renderer is handed
     * COMPLETED rows carrying values no calculation would ever produce, and
     * every one of them has to survive to the output untouched. A renderer
     * that re-derived eligibility, capability or the reason sentence would
     * overwrite these and fail.
     */
    public function testTheSectionRendererPrintsSuppliedStatusUnchanged(): void
    {
        $rows = [[
            'chain_id'           => 4242,
            'slug'               => 'sentinel-slug',
            'name'               => 'Sentinel Chain',
            'discovery_opted_in' => true,
            'unsupported'        => false,
            'paused'             => true,
            'eligibility'        => CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ALLOWLIST_EXCLUDED,
            'eligibility_reason' => 'SENTINEL-REASON-9f3a: outside the canary allowlist.',
        ]];

        ob_start();
        ChainsPage::render_cw_discovery_section($rows);
        $html = (string) ob_get_clean();

        // The supplied identity, reason and flags all survive.
        $this->assertStringContainsString('Sentinel Chain', $html);
        $this->assertStringContainsString('sentinel-slug', $html);
        $this->assertStringContainsString('4242', $html);
        $this->assertStringContainsString('SENTINEL-REASON-9f3a', $html);
        $this->assertStringContainsString('scanner paused', $html);

        // The supplied VERDICT is the one shown — not one re-derived from
        // the opted-in flag, which would have said "Eligible".
        $this->assertStringContainsString(
            CosmwasmDiscoveryHealthSnapshot::eligibilityLabel(
                CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ALLOWLIST_EXCLUDED
            ),
            $html
        );
        $this->assertStringNotContainsString('Eligible<', $html);

        // Handed rows directly, the renderer needs no snapshot call at all.
        $this->assertSame(
            0,
            CosmwasmDiscoveryHealthSnapshot::$summaryCalls,
            'the renderer must not fetch status of its own accord'
        );
    }

    /**
     * A source guard for the same property, catching what a render test
     * cannot: a dependency added later that only fires on some other path.
     *
     * ── NARROWED IN VC-B3b, AND WHY THAT IS NOT A WEAKENING ─────────────
     * This used to forbid the gate symbols across the WHOLE file. VC-B3b
     * gave this page four mutating routes, and Backfill and Retry MUST
     * re-check `discoveryEnabled()` / `backfillEnabled()` server-side —
     * a disabled button is a UI hint, not authorization, and a crafted
     * POST has to reach the same fail-closed answer cron does. Keeping the
     * file-wide ban would have meant either an unguarded route or deleting
     * the guard, and both are worse than saying precisely where the rule
     * applies.
     *
     * The rule it actually encodes is about PRESENTATION: the renderers
     * must print the snapshot's verdict rather than compute a second one.
     * So the ban now applies to exactly the render methods, where a gate
     * call really would be a competing authority — and one authority-only
     * symbol stays banned file-wide, because no handler needs it either.
     */
    public function testTheRenderersHaveNoIndependentEligibilityDependency(): void
    {
        $code = $this->strippedSource();

        // Nothing anywhere on this page may derive its own eligibility.
        foreach (['CosmwasmScanEligibility', 'eligibleChainIds'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $code,
                "ChainsPage must not derive eligibility itself; `{$forbidden}` would be a second authority."
            );
        }

        // And no RENDER method may consult the environment gates.
        foreach ([
            'render_nft_discovery_tab',
            'render_cw_discovery_section',
            'render_cw_discovery_row',
            'render_cw_status_row',
            'render_cw_operation_control',
        ] as $method) {
            $body = $this->methodBody($code, $method);
            $this->assertNotSame('', $body, "method {$method} not found — update this guard");

            foreach (['CosmwasmDiscoveryGate', 'discoveryEnabled(', 'backfillEnabled('] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $body,
                    "{$method}() must print supplied status, not recompute it; `{$forbidden}` is a second authority."
                );
            }
        }
    }

    /**
     * The one renderer that IS allowed a gate call, stated positively so
     * the exception is deliberate rather than an omission from the list
     * above: it decides which controls to OFFER, and offering Backfill
     * where the constant is undefined would be a button that cannot work.
     */
    public function testOnlyTheOperationsRowConsultsTheEnvironmentGates(): void
    {
        $body = $this->methodBody($this->strippedSource(), 'render_cw_operations_row');

        $this->assertStringContainsString('discoveryEnabled(', $body);
        $this->assertStringContainsString('backfillEnabled(', $body);
    }

    /** ChainsPage source with all comments removed. */
    private function strippedSource(): string
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../../app/Domain/Onchain/Admin/ChainsPage.php'
        );

        // Strip comments — the docblocks legitimately NAME these classes to
        // explain why they are not used, and a substring check would hit
        // those instead of real calls.
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** Brace-balanced body of one method, or '' if absent. */
    private function methodBody(string $code, string $method): string
    {
        $at = strpos($code, 'function ' . $method . '(');
        if ($at === false) {
            return '';
        }

        $open = strpos($code, '{', $at);
        if ($open === false) {
            return '';
        }

        $depth = 0;
        $len   = strlen($code);
        for ($i = $open; $i < $len; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $open, $i - $open + 1);
                }
            }
        }

        return '';
    }

    // ── Form structure ──────────────────────────────────────────────────

    public function testEveryChainRendersExactlyOneCorrectlyScopedForm(): void
    {
        $doc   = $this->dom();
        $forms = $this->discoveryForms($doc);

        $this->assertCount(3, $forms, 'one discovery control per CosmWasm candidate, no more');

        $ids      = [];
        $expected = [
            self::COSMOS_A => ChainsPage::ACTION_CW_DISCOVERY_DISABLE, // opted in → offer Disable
            self::COSMOS_B => ChainsPage::ACTION_CW_DISCOVERY_ENABLE,
            self::COSMOS_C => ChainsPage::ACTION_CW_DISCOVERY_ENABLE,
        ];

        foreach ($forms as $form) {
            $ids[] = $form->getAttribute('id');

            $this->assertSame('post', strtolower($form->getAttribute('method')));
            $this->assertStringContainsString('admin-post.php', $form->getAttribute('action'));

            $chainId = (int) $this->hiddenValue($form, 'chain_id');
            $this->assertArrayHasKey($chainId, $expected);

            $route = $expected[$chainId];
            $this->assertSame($route, $this->hiddenValue($form, 'action'));

            // Direction AND chain scoped.
            $this->assertSame($route . '_' . $chainId, $this->nonceAction($form));

            // The form id encodes the direction, and agrees with the row.
            $this->assertSame(
                ChainsPage::cw_discovery_form_id($chainId, $route === ChainsPage::ACTION_CW_DISCOVERY_DISABLE),
                $form->getAttribute('id')
            );
        }

        $this->assertSame(count($ids), count(array_unique($ids)), 'form ids must be unique across chains');
    }

    public function testNoFormIsNested(): void
    {
        $doc = $this->dom();

        foreach ($this->elements($doc, 'form') as $form) {
            $this->assertCount(
                0,
                iterator_to_array($form->getElementsByTagName('form')),
                'a form must never contain another form'
            );
        }
    }

    public function testAnOptedInChainOffersOnlyDisableAndViceVersa(): void
    {
        $doc = $this->dom();

        $this->assertNotNull($this->formById($doc, 'cwd-disable-' . self::COSMOS_A));
        $this->assertNull($this->formById($doc, 'cwd-enable-' . self::COSMOS_A));

        $this->assertNotNull($this->formById($doc, 'cwd-enable-' . self::COSMOS_B));
        $this->assertNull($this->formById($doc, 'cwd-disable-' . self::COSMOS_B));
    }

    private function formById(\DOMDocument $doc, string $id): ?\DOMElement
    {
        foreach ($this->elements($doc, 'form') as $f) {
            if ($f->getAttribute('id') === $id) {
                return $f;
            }
        }

        return null;
    }

    /**
     * The literal is spelled out rather than referenced through
     * VerifyCollectionsPage: VC-B3b deleted those constants, because the
     * last thing that verified that token is gone. The string survives
     * here — and only here, in tests — as the token this page must never
     * mint.
     */
    public function testTheSharedVerifyCollectionsNonceIsAbsent(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('bcc_verify_collections_nonce', $html);
        $this->assertStringNotContainsString('_bcc_vc_nonce', $html);
        $this->assertStringNotContainsString('bcc_vc_action', $html);
    }

    public function testThereIsExactlyOneDiscoveryControlPerChain(): void
    {
        $doc = $this->dom();

        $buttons = 0;
        foreach ($this->discoveryButtons($doc) as $b) {
            if ($b->getAttribute('type') === 'submit') {
                $buttons++;
            }
        }

        $this->assertSame(3, $buttons, 'one Enable-or-Disable per chain, never both');
    }

    // ── Labels and confirmation copy ────────────────────────────────────

    public function testLabelsSayWhatTheyDoRatherThanOnOrOff(): void
    {
        $doc = $this->dom();

        $labels = [];
        foreach ($this->discoveryButtons($doc) as $b) {
            $labels[] = trim($b->textContent);
        }

        sort($labels);
        $this->assertSame(
            ['Disable automatic discovery', 'Enable automatic discovery', 'Enable automatic discovery'],
            $labels
        );

        foreach ($labels as $label) {
            $this->assertNotSame('On', $label);
            $this->assertNotSame('Off', $label);
            $this->assertNotSame('Pause', $label);
            $this->assertNotSame('Resume', $label);
        }
    }

    public function testTheConfirmationsAreTruthfulAndDistinguishDisableFromPause(): void
    {
        $doc = $this->dom();

        $seen = ['enable' => false, 'disable' => false];

        foreach ($this->discoveryButtons($doc) as $button) {
            $onclick = $button->getAttribute('onclick');
            $this->assertStringContainsString('confirm(', $onclick);

            $this->assertSame(1, preg_match('/confirm\((.*)\);?$/s', $onclick, $m));
            $text = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
            $this->assertIsString($text);

            if (str_contains($button->textContent, 'Disable')) {
                $seen['disable'] = true;

                $this->assertStringContainsString('opts the chain out of future automatic', $text);
                $this->assertStringContainsString('Nothing already found is removed', $text);
                $this->assertStringContainsString('scan progress and inventory are all kept', $text);
                // The distinction the whole design rests on.
                $this->assertStringContainsString('different from temporarily pausing the scanner', $text);
            } else {
                $seen['enable'] = true;

                $this->assertStringContainsString('does NOT start a scan now', $text);
                $this->assertStringContainsString('future scheduled passes', strtolower($text));
                $this->assertStringContainsString('safety gates', $text);
                $this->assertStringContainsString('arrives unverified', $text);
            }

            // Neither direction may claim to verify or provision anything.
            $lower = strtolower($text);
            $this->assertStringNotContainsString('will be verified', $lower);
            $this->assertStringNotContainsString('creates a community', $lower);
            $this->assertStringNotContainsString('provisions', $lower);
        }

        $this->assertTrue($seen['enable'] && $seen['disable'], 'both directions must be exercised');
    }

    public function testTheTabExplainsDisableIsNotPause(): void
    {
        $text = preg_replace('/\s+/', ' ', strip_tags($this->render())) ?? '';

        $this->assertStringContainsString('is not the same as pausing the scanner', $text);
        $this->assertStringContainsString('Pausing is a temporary hold', $text);
    }
}
