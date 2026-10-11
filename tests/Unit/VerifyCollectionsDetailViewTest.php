<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The READ-ONLY collection detail view.
 *
 * ── THE TWO PROPERTIES THAT MATTER MOST ─────────────────────────────────
 *
 * 1. ⚠⚠ OPENING IT MUST NOT ACT. It is reached by a GET, it takes no nonce
 *    because nothing it does needs one, and the one set of controls it
 *    shows is the EXISTING description review with its own per-row nonce
 *    and its own handler. A detail view that could mutate would be a
 *    CSRF-shaped surface reachable from a link.
 *
 * 2. ⚠⚠ THREE OUTCOMES, THREE MESSAGES. Found, provably absent, and "the
 *    read did not run" are different facts. A database fault rendered as
 *    "not found" invites an operator to conclude a collection was deleted;
 *    an unreadable community check rendered as "no community" invites them
 *    to delete one that still exists. Both are pinned below.
 */
#[CoversClass(VerifyCollectionsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class VerifyCollectionsDetailViewTest extends TestCase
{
    private const CHAIN_ID = 1;
    private const ROW_ID   = 42;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/verify-collections-render-isolation-stubs.php';

        \BccRenderWorld::reset();
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    /** @param array<string,mixed> $o */
    private function seedRow(array $o = []): void
    {
        \BccRenderWorld::$row = (object) ($o + [
            'id'                        => (string) self::ROW_ID,
            'contract_address'          => '0x' . str_repeat('a', 40),
            'canonical_identifier'      => '0x' . str_repeat('a', 40),
            'chain_id'                  => (string) self::CHAIN_ID,
            'collection_name'           => 'Detail Fixture',
            'collection_symbol'         => 'DFX',
            'token_standard'            => 'ERC-721',
            'total_supply'              => '1000',
            'unique_holders'            => null,
            'image_url'                 => null,
            'marketplace_url'           => null,
            'is_verified'               => '0',
            'source'                    => 'discovery',
            'show_on_profile'           => '1',
            'provisioning_state'        => 'none',
            'provisioning_requested_at' => null,
            'provisioning_requested_by' => null,
            'provisioning_failure_code' => null,
            'fetched_at'                => '2026-10-01 09:00:00',
            'expires_at'                => '2026-10-02 09:00:00',
            'metadata_state'            => 'complete',
            'metadata_checked_at'       => '2026-10-01 09:00:00',
            'chain_description'         => null,
            'chain_description_state'   => 'none',
            'chain_description_source'  => null,
            'chain_slug'                => 'ethereum',
            'chain_name'                => 'Ethereum',
            'chain_type'                => 'evm',
            'explorer_url'              => 'https://etherscan.io',
        ]);
    }

    private function render(): string
    {
        $_GET['page']       = 'bcc-verify-collections';
        $_GET['collection'] = $_GET['collection'] ?? (string) self::ROW_ID;

        ob_start();
        try {
            VerifyCollectionsPage::render_page();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private static function text(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  0. ANTI-VACUITY
    // ═══════════════════════════════════════════════════════════════════

    public function testTheHarnessActuallyRendersTheDetailViewAndNotTheList(): void
    {
        $this->seedRow();
        $html = $this->render();

        self::assertStringContainsString('Identity and chain', $html);
        // The list's table must NOT be what we are asserting against.
        self::assertStringNotContainsString('data-collection-id', $html);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. ⚠⚠ IT DOES NOT ACT
    // ═══════════════════════════════════════════════════════════════════

    public function testOpeningItPerformsNoWriteAndContactsNoProvider(): void
    {
        $this->seedRow();
        $this->render();

        self::assertSame([], \BccRenderWorld::$writes, 'no option, transient or cache write');
        self::assertSame([], \BccRenderWorld::$http, 'no outbound request');
    }

    public function testOpeningItIssuesExactlyTheFourReadsItNeeds(): void
    {
        // ⚠ Counted, not merely "no N+1": a detail view is one row, so the
        // honest bound is a fixed small number, and naming it means a new
        // read has to be a deliberate change.
        $this->seedRow();
        $this->render();

        self::assertSame(1, \BccRenderWorld::$detailReads, 'one collection read');
        self::assertLessThanOrEqual(1, \BccRenderWorld::$communityReads, 'at most one community lookup');
        self::assertLessThanOrEqual(1, \BccRenderWorld::$hideReads, 'at most one hide-rule read');
    }

    public function testItCarriesNoNonceOfItsOwn(): void
    {
        // The only nonces present belong to the EXISTING description review
        // forms. Nothing the detail view itself renders takes one, because
        // nothing it renders acts.
        $this->seedRow();
        $html = $this->render();

        self::assertStringNotContainsString('bcc_vc_detail', $html);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. ⚠⚠ THREE OUTCOMES, THREE MESSAGES
    // ═══════════════════════════════════════════════════════════════════

    public function testAMissingCollectionSaysNotFoundAndOffersAWayBack(): void
    {
        \BccRenderWorld::$row = null;
        $html = $this->render();
        $text = self::text($html);

        self::assertStringContainsString('Collection not found', $text);
        self::assertStringContainsString((string) self::ROW_ID, $text);
        self::assertStringContainsString('Back to the list', $text);
        self::assertStringNotContainsString('read failure', $text);
    }

    public function testAnUnreadableTableSaysSoAndNeverSaysNotFound(): void
    {
        // ⚠ The distinction the whole three-way read exists for. A database
        // fault must not read as "this collection was deleted".
        \BccRenderWorld::$detailThrows = true;
        $html = $this->render();
        $text = self::text($html);

        self::assertStringContainsString('could not be loaded', $text);
        self::assertStringContainsString('database read failure', $text);
        self::assertStringNotContainsString('not found', $text);
        self::assertStringNotContainsString('No collection with id', $text);
        self::assertStringContainsString('Back to the list', $text);
    }

    public function testAnUnreadableCommunityCheckNeverClaimsThereIsNoCommunity(): void
    {
        // ⚠⚠ The dangerous direction. "No community" is what makes a Remove
        // look safe, so an unreadable check must say it could not look.
        $this->seedRow();
        \BccRenderWorld::$communityThrows = true;
        $text = self::text($this->render());

        self::assertStringContainsString('Could not determine', $text);
        self::assertStringNotContainsString('No published holder community', $text);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. WHAT IT SHOWS
    // ═══════════════════════════════════════════════════════════════════

    public function testItShowsTheFullContractNotATruncatedOne(): void
    {
        $this->seedRow();
        $text = self::text($this->render());

        self::assertStringContainsString('0x' . str_repeat('a', 40), $text);
    }

    public function testANullHolderCountIsNotKnownRatherThanZero(): void
    {
        // The same defect #292 fixed in the table: `(int) null` is 0, and a
        // rendered zero states a fact BCC does not have.
        $this->seedRow(['unique_holders' => null]);
        $text = self::text($this->render());

        self::assertStringContainsString('not known', $text);
        self::assertStringNotContainsString('Marketplace holders 0', $text);
    }

    public function testTheMetadataStateNamesItsFamilyScope(): void
    {
        $this->seedRow(['metadata_state' => 'complete', 'chain_type' => 'cosmos']);
        $text = self::text($this->render());

        self::assertStringContainsString('Complete', $text);
        self::assertStringContainsString('cosmos', $text);
        self::assertStringContainsString('not applicable', $text);
    }

    public function testNeverCheckedIsDistinguishedFromCheckedAndFoundNothing(): void
    {
        $this->seedRow(['metadata_state' => 'unavailable', 'metadata_checked_at' => null]);
        $notChecked = self::text($this->render());

        self::assertStringContainsString('Not checked', $notChecked);
        self::assertStringContainsString('not a statement about the collection', $notChecked);

        \BccRenderWorld::reset();
        $this->seedRow(['metadata_state' => 'unavailable', 'metadata_checked_at' => '2026-10-01 09:00:00']);
        $checked = self::text($this->render());

        self::assertStringContainsString('Unavailable', $checked);
        self::assertStringNotContainsString('Not checked', $checked);
    }

    public function testAnUnrecognisedMetadataStateIsShownVerbatim(): void
    {
        $this->seedRow(['metadata_state' => 'quantum_state']);
        $text = self::text($this->render());

        self::assertStringContainsString('quantum_state', $text);
        self::assertStringContainsString('Unrecognised', $text);
    }

    public function testAMissingChainRowIsReportedRatherThanRenderedBlank(): void
    {
        // A LEFT JOIN that found no chain. The collection references a chain
        // this install does not have, which is worth saying out loud.
        $this->seedRow(['chain_name' => null, 'chain_slug' => null]);
        $text = self::text($this->render());

        self::assertStringContainsString('unknown chain', $text);
    }

    public function testItSurfacesTheExistingDescriptionReviewWhenThereIsText(): void
    {
        $this->seedRow([
            'chain_description'        => 'Imported text awaiting review.',
            'chain_description_state'  => 'pending',
            'chain_description_source' => 'alchemy',
        ]);
        $html = $this->render();

        self::assertStringContainsString('Imported text awaiting review.', $html);
        self::assertStringContainsString('Approve', $html);
        self::assertStringContainsString('Reject', $html);
    }

    public function testWithNoImportedTextItSaysThereIsNothingToReview(): void
    {
        $this->seedRow(['chain_description' => null]);
        $text = self::text($this->render());

        self::assertStringContainsString('No description has been imported', $text);
        self::assertStringNotContainsString('Approve', $text);
    }

    public function testHideStatusIsReportedButOffersNoControl(): void
    {
        // ⚠ Hide semantics are a separate decision. This view REPORTS; the
        // control stays on the list where its nonce and handler live.
        $this->seedRow();
        \BccRenderWorld::$hideRule = 'deny';
        $html = $this->render();

        self::assertStringContainsString('HIDDEN', $html);
        self::assertStringContainsString('operated from the list', self::text($html));
        self::assertStringNotContainsString('bcc_vc_hide', $html);
        self::assertStringNotContainsString('bcc_vc_unhide', $html);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  4. CONTEXT AND ACCESSIBILITY
    // ═══════════════════════════════════════════════════════════════════

    public function testTheBackLinkKeepsTheListContext(): void
    {
        $this->seedRow();
        $_GET['vstate']         = 'needs_attention';
        $_GET['s']              = 'Steel';
        $_GET['chain']          = 'ethereum';
        $_GET['token_standard'] = 'ERC-721';
        $_GET['paged']          = '3';

        $html = $this->render();

        foreach (['vstate=needs_attention', 's=Steel', 'chain=ethereum', 'token_standard=ERC-721', 'paged=3'] as $fragment) {
            self::assertStringContainsString(
                str_replace('=', '=', $fragment),
                html_entity_decode($html),
                "the Back link must preserve {$fragment}"
            );
        }
    }

    public function testTheBackLinkCarriesOnlyTheAllowlistedKeys(): void
    {
        // ⚠ An allowlist, not a passthrough. A Back link built from whatever
        // arrived would carry an attacker-chosen query string into an admin
        // URL.
        $this->seedRow();
        $_GET['vstate']  = 'needs_attention';
        $_GET['evil']    = 'injected';

        $html = html_entity_decode($this->render());

        self::assertStringContainsString('vstate=needs_attention', $html);
        self::assertStringNotContainsString('evil=injected', $html);
    }

    public function testItHasOneH1AndLabelledSections(): void
    {
        $this->seedRow();
        $html = $this->render();

        self::assertSame(1, substr_count($html, '<h1'), 'exactly one page heading');
        foreach (['Identity and chain', 'Validation and metadata', 'Imported description', 'Holder community', 'Hide status'] as $section) {
            self::assertMatchesRegularExpression(
                '/<h2[^>]*>\s*' . preg_quote($section, '/') . '/',
                $html,
                "{$section} must be an h2 landmark"
            );
        }
    }

    public function testEveryValueHasALabelledTerm(): void
    {
        // A <dl> so a screen reader announces each value with its term —
        // the reason this is not a bare table of divs.
        $this->seedRow();
        $html = $this->render();

        $dt = substr_count($html, '<dt');
        $dd = substr_count($html, '<dd');
        self::assertGreaterThan(5, $dt, 'the view describes several fields');
        self::assertSame($dt, $dd, 'every term has exactly one definition');
    }
}
