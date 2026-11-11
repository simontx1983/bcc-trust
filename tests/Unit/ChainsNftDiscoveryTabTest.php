<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\NftDiscoveryPage;
use BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository;
use BCC\Trust\Onchain\Services\NftDiscoveryControlPlaneSnapshot;
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
#[CoversClass(NftDiscoveryPage::class)]
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
        ChainNftCapabilityRepository::reset();
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




    /** @return list<\DOMElement> */
    /** Every button in a fragment — used for the form-less preserved renderer. */
    private function buttonsIn(\DOMDocument $doc): array
    {
        $out = [];
        foreach ($this->elements($doc, 'button') as $b) {
            $out[] = $b;
        }

        return $out;
    }

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

    public function testTheCanonicalSlugIsTheNftDiscoveryPage(): void
    {
        $this->assertSame('bcc-onchain-nft-discovery', NftDiscoveryPage::PAGE_SLUG);
    }

    /**
     * The old sub-tab address is still an address.
     *
     * NFT Discovery was `?page=bcc-onchain-chains&subtab=nft-discovery` for
     * long enough to be bookmarked, linked from notes, and sitting in open
     * tabs. Promoting it to its own page is not a reason to break those,
     * so the retired location is kept as a named constant and forwarded.
     */
    public function testTheRetiredSubtabAddressIsStillNamedAndForwarded(): void
    {
        $this->assertSame('bcc-onchain-chains', NftDiscoveryPage::LEGACY_PAGE_SLUG);
        $this->assertSame('nft-discovery', NftDiscoveryPage::LEGACY_SUBTAB);

        $source = (string) file_get_contents(
            __DIR__ . '/../../app/Domain/Onchain/Admin/NftDiscoveryPage.php'
        );

        // On admin_init, because a submenu page callback runs after
        // wp-admin has already sent headers.
        $this->assertStringContainsString(
            "add_action('admin_init', [self::class, 'maybe_redirect_legacy_url'])",
            $source
        );
        $this->assertStringContainsString('wp_safe_redirect(', $source);
    }

    /**
     * The Chains page no longer offers the tab, in either direction.
     *
     * Both halves matter. A stale link in the nav would 404 into a fallback
     * tab; a stale entry in the allowlist would render an empty body for a
     * sub-tab whose renderer has gone.
     */
    public function testTheChainsPageNoLongerOffersTheSubtab(): void
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../../app/Domain/Onchain/Admin/ChainsPage.php'
        );

        $this->assertStringNotContainsString('SUBTAB_NFT_DISCOVERY', $source);
        $this->assertStringNotContainsString("add_query_arg('subtab', self::SUBTAB", $source);
        $this->assertStringNotContainsString('render_nft_discovery_tab', $source);

        // The ALLOWLIST is what makes an unknown sub-tab fall back rather than
        // render blank, so pin it exactly — a new tab is a deliberate act and
        // should have to say so here.
        //
        // `halls` joined it when Halls became administrator-created: the
        // per-chain "Create Chain Hall" control lives on this page.
        // `nft-discovery` must never be in it — NftDiscoveryPage owns that
        // surface and redirects the legacy URL on admin_init.
        $this->assertStringContainsString(
            "in_array(\$activeTab, ['validators', 'identity', 'halls'], true)",
            $source
        );

        $this->assertMatchesRegularExpression(
            "/in_array\\(\\\$activeTab, \\[(?:(?!nft-discovery).)*\\], true\\)/",
            $source,
            'nft-discovery must not be an accepted sub-tab'
        );
    }


    // ── Scope: what is and is not claimed about other chain families ────








    /** NftDiscoveryPage source with all comments removed. */
    private function strippedSource(): string
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../../app/Domain/Onchain/Admin/NftDiscoveryPage.php'
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




    private function formById(\DOMDocument $doc, string $id): ?\DOMElement
    {
        foreach ($this->elements($doc, 'form') as $f) {
            if ($f->getAttribute('id') === $id) {
                return $f;
            }
        }

        return null;
    }



    // ── Labels and confirmation copy ────────────────────────────────────



}
