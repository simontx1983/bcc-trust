<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\NftDiscoveryPage;
use BCC\Trust\Onchain\Services\NftDiscoveryControlPlaneSnapshot;
use BCC\Trust\Onchain\Support\NftChainCapability;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * WHAT THE OPERATOR ACTUALLY READS.
 *
 * Two properties, and the second is the reason this file is long:
 *
 *   1. Every refusal names the thing that would change it — or says
 *      plainly that nothing would. "No driver" and "not configured" look
 *      alike and send somebody to two different places, one of which does
 *      not exist.
 *
 *   2. NOTHING upstream reaches the page unredacted. Provider messages,
 *      endpoints and error text are the fields whose content we do not
 *      control, and `esc_html()` does nothing about a credential in a URL.
 */
#[CoversClass(NftDiscoveryPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NftDiscoveryRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/chains-cw-operations-stubs.php';

        \BccAdminTestState::reset();
        $_GET  = [];
        $_POST = [];
    }



    private static function text(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($html)));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  EVERY REFUSAL NAMES WHAT WOULD CHANGE IT
    // ═══════════════════════════════════════════════════════════════════




    /**
     * The unreadable-store reasons are told apart, because they are told
     * apart upstream and collapsing them here would waste that.
     */



    // ═══════════════════════════════════════════════════════════════════
    //  NOTHING UPSTREAM ARRIVES UNREDACTED
    // ═══════════════════════════════════════════════════════════════════


    /**
     * A provider's own words about a refused endpoint go through the
     * redactor before they are escaped.
     *
     * `esc_html()` stops markup executing. It does nothing whatsoever about
     * an API key sitting in a URL, and this is the one field on the page
     * whose content we do not write.
     */

    /** The same discipline for the run report's recorded reasons. */


    // ═══════════════════════════════════════════════════════════════════
    //  A PARTIAL RUN IS NEVER SHOWN AS A COMPLETE ONE
    // ═══════════════════════════════════════════════════════════════════








    // ═══════════════════════════════════════════════════════════════════
    //  EVM / SOLANA: A STRUCTURAL LIMIT, SAID PLAINLY
    // ═══════════════════════════════════════════════════════════════════



    /** Wallet refresh is named as a separate method, and offered no button. */
    public function testWalletRefreshIsDescribedAndNeverTriggered(): void
    {
        $m = new \ReflectionMethod(NftDiscoveryPage::class, 'render_wallet_refresh_method');
        $m->setAccessible(true);

        ob_start();
        $m->invoke(null, ['family' => 'evm', 'label' => 'EVM']);
        $html = (string) ob_get_clean();
        $text = self::text($html);

        self::assertStringContainsString('a separate method', $text);
        self::assertStringContainsString('not', $text);
        self::assertStringContainsString('finds only what a linked wallet happens to hold', $text);
        self::assertStringContainsString('unverified', $text);

        // NO control of any kind.
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<button', $html);
        self::assertStringNotContainsString('wp_nonce_field', $html);
        self::assertStringNotContainsString('admin-post.php', $html);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE OLD URL STILL WORKS
    // ═══════════════════════════════════════════════════════════════════

    /** @return array{args: array<string, string>}|null */
    private function redirect(): ?array
    {
        try {
            NftDiscoveryPage::maybe_redirect_legacy_url();
        } catch (\BccAdminRedirect $r) {
            return ['args' => $r->args];
        }

        return null;
    }

    public function testTheRetiredSubtabUrlForwardsToTheCosmosFamily(): void
    {
        \BccAdminTestState::$can = true;
        $_GET = ['page' => 'bcc-onchain-chains', 'subtab' => 'nft-discovery'];

        $r = $this->redirect();

        self::assertNotNull($r, 'the retired address must forward, not 404 into a fallback tab');
        self::assertSame(NftDiscoveryPage::PAGE_SLUG, $r['args']['page']);
        self::assertSame(NftDiscoveryControlPlaneSnapshot::FAMILY_COSMOS, $r['args']['family']);
    }

    /**
     * An in-flight PRG landing survives the move.
     *
     * A tab left open across the deploy submits, gets redirected to the old
     * address with its result code, and must still show its notice.
     */
    public function testTheThreeNoticeArgsAreCarriedThrough(): void
    {
        \BccAdminTestState::$can = true;
        $_GET = [
            'page'    => 'bcc-onchain-chains',
            'subtab'  => 'nft-discovery',
            'bcc_cwd' => 'enabled',
            'bcc_cwo' => 'paused',
            'bcc_ref' => 'bcc-deadbeef',
        ];

        $r = $this->redirect();

        self::assertNotNull($r);
        self::assertSame('enabled', $r['args']['bcc_cwd']);
        self::assertSame('paused', $r['args']['bcc_cwo']);
        self::assertSame('bcc-deadbeef', $r['args']['bcc_ref']);
    }

    /** Anything else on the old URL is dropped, not forwarded. */
    public function testNoOtherQueryArgSurvivesTheRedirect(): void
    {
        \BccAdminTestState::$can = true;
        $_GET = [
            'page'     => 'bcc-onchain-chains',
            'subtab'   => 'nft-discovery',
            'chain_id' => '4',
            'action'   => 'bcc_chain_cw_backfill',
            '_wpnonce' => 'abc123',
            'evil'     => '<script>',
        ];

        $r = $this->redirect();

        self::assertNotNull($r);
        self::assertSame(
            ['page', 'family'],
            array_keys($r['args']),
            'the forward must not become a way to smuggle values onto the new page'
        );
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function nonMatchingUrls(): array
    {
        return [
            'other subtab'  => [['page' => 'bcc-onchain-chains', 'subtab' => 'validators']],
            'no subtab'     => [['page' => 'bcc-onchain-chains']],
            'other page'    => [['page' => 'bcc-verify-collections', 'subtab' => 'nft-discovery']],
            'already there' => [['page' => 'bcc-onchain-nft-discovery', 'family' => 'cosmos']],
            'nothing'       => [[]],
        ];
    }

    /** Everything else is left alone — this must not become a catch-all. */
    #[DataProvider('nonMatchingUrls')]
    public function testUnrelatedAdminUrlsAreNotRedirected(array $query): void
    {
        \BccAdminTestState::$can = true;
        $_GET = $query;

        self::assertNull($this->redirect());
    }

    public function testALoggedOutVisitorIsNotHandedAMapOfTheAdminSurface(): void
    {
        \BccAdminTestState::$can = false;
        $_GET = ['page' => 'bcc-onchain-chains', 'subtab' => 'nft-discovery'];

        self::assertNull($this->redirect());
    }
}
