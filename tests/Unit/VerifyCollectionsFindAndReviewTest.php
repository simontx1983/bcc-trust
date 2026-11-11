<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The find-and-review slice: the metadata column, the empty states, and the
 * accessibility of the filter controls.
 *
 * ── THE THREE PROBLEMS THIS PINS ────────────────────────────────────────
 *
 * 1. ⚠ METADATA STATUS WAS INVISIBLE. `metadata_state` and
 *    `metadata_checked_at` have been written on intake since PR E and were
 *    read by NO admin view, so an operator could not see how much metadata
 *    BCC held for a row it was asking them to verify. The column also has to
 *    distinguish "never checked" from "checked and found nothing" — both
 *    store `unavailable`, and only `metadata_checked_at` tells them apart.
 *
 * 2. ⚠⚠ A FALSE EMPTY STATE PAST THE LAST PAGE. `paged` was clamped only at
 *    the bottom, so `&paged=99` produced an offset past the end: zero rows,
 *    and the message "No unverified collections. Add one from NFT
 *    Discovery" — beside a tab counter saying 38. The page contradicted
 *    itself and advised creating data that already existed. Reproduced in a
 *    browser before the fix.
 *
 * 3. ⚠ THE FILTERS WERE MOUSE-ONLY. Both selects carried
 *    `onchange="this.form.submit()"` and the only submit button lived in
 *    `<noscript>`. With JavaScript on — every real admin session — there was
 *    no submit control, and a keyboard user arrowing through the options
 *    fired a navigation on every keystroke.
 */
#[CoversClass(VerifyCollectionsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class VerifyCollectionsFindAndReviewTest extends TestCase
{
    private const CHAIN_ID = 1;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/verify-collections-render-isolation-stubs.php';

        \BccRenderWorld::reset();
        \BccPostCacheSpy::reset();
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        \BccRenderWorld::$chains = [
            (object) [
                'id' => self::CHAIN_ID, 'slug' => 'ethereum', 'name' => 'Ethereum',
                'chain_type' => 'evm', 'is_active' => 1,
                'bcc_supports_nft_collections' => '1', 'cosmwasm_nft_discovery_enabled' => '0',
            ],
        ];
    }

    /** @param array<string,mixed> $o */
    private function row(int $id, array $o = []): object
    {
        return (object) ($o + [
            'id'                        => $id,
            'contract_address'          => sprintf('0x%040x', $id),
            'canonical_identifier'      => sprintf('0x%040x', $id),
            'collection_name'           => 'Collection ' . $id,
            'image_url'                 => null,
            'token_standard'            => 'ERC-721',
            'chain_id'                  => self::CHAIN_ID,
            'chain_slug'                => 'ethereum',
            'chain_type'                => 'evm',
            'is_verified'               => 0,
            'is_hidden'                 => 0,
            'source'                    => 'discovery',
            'unique_holders'            => null,
            'has_community'             => 0,
            'provisioning_state'        => 'none',
            'provisioning_failure_code' => null,
            'metadata_state'            => 'unavailable',
            'metadata_checked_at'       => null,
        ]);
    }

    private function render(): string
    {
        ob_start();
        try {
            VerifyCollectionsPage::render_page();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** @return list<string> the Metadata cell of each rendered row */
    private static function metadataCells(string $html): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();

        $out = [];
        foreach ($doc->getElementsByTagName('tr') as $tr) {
            if (!$tr instanceof \DOMElement || !$tr->hasAttribute('data-collection-id')) {
                continue;
            }
            $tds = [];
            foreach ($tr->childNodes as $n) {
                if ($n instanceof \DOMElement && $n->tagName === 'td') {
                    $tds[] = $n;
                }
            }
            // ⚠ Index 11. The Metadata column sits AFTER Community (10) on
            // purpose: inserting it earlier would shift the index
            // VerifyCollectionsCommunityCellPrefetchTest pins.
            $out[] = trim((string) ($tds[11]->textContent ?? '(missing)'));
        }

        return $out;
    }

    private static function emptyCell(string $html): string
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        foreach ($doc->getElementsByTagName('td') as $td) {
            if ($td instanceof \DOMElement && $td->hasAttribute('colspan')) {
                return trim(preg_replace('/\s+/', ' ', (string) $td->textContent) ?? '');
            }
        }

        return '(no empty cell)';
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. THE METADATA COLUMN
    // ═══════════════════════════════════════════════════════════════════

    public function testTheMetadataColumnExistsAfterCommunity(): void
    {
        \BccRenderWorld::$rows = [$this->row(1)];
        $html = $this->render();

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();

        $heads = [];
        foreach ($doc->getElementsByTagName('th') as $th) {
            $heads[] = trim((string) $th->textContent);
        }

        $community = array_search('Community', $heads, true);
        $metadata  = array_search('Metadata', $heads, true);
        self::assertIsInt($community, 'the Community column must still exist');
        self::assertIsInt($metadata, 'the Metadata column must be rendered');
        self::assertGreaterThan(
            $community,
            $metadata,
            'Metadata must come AFTER Community so the pinned Community index does not shift'
        );
    }

    public function testEachMetadataStateRendersItsOwnLabel(): void
    {
        \BccRenderWorld::$rows = [
            $this->row(3, ['metadata_state' => 'complete',    'metadata_checked_at' => '2026-10-01 12:00:00']),
            $this->row(2, ['metadata_state' => 'partial',     'metadata_checked_at' => '2026-10-01 12:00:00']),
            $this->row(1, ['metadata_state' => 'unavailable', 'metadata_checked_at' => '2026-10-01 12:00:00']),
        ];

        $cells = self::metadataCells($this->render());

        self::assertCount(3, $cells);
        self::assertStringContainsString('Complete', $cells[0]);
        self::assertStringContainsString('Partial', $cells[1]);
        self::assertStringContainsString('Unavailable', $cells[2]);
    }

    public function testNeverCheckedIsDistinguishedFromCheckedAndFoundNothing(): void
    {
        // ⚠⚠ THE WHOLE POINT OF THE COLUMN. Both rows store `unavailable`;
        // only metadata_checked_at separates "we have not looked" from "we
        // looked and there is nothing". One is a gap in our work, the other
        // is a fact about the collection, and an operator needs to know
        // which.
        \BccRenderWorld::$rows = [
            $this->row(2, ['metadata_state' => 'unavailable', 'metadata_checked_at' => null]),
            $this->row(1, ['metadata_state' => 'unavailable', 'metadata_checked_at' => '2026-10-01 12:00:00']),
        ];

        $cells = self::metadataCells($this->render());

        self::assertStringContainsString('Not checked', $cells[0]);
        self::assertStringNotContainsString('Unavailable', $cells[0]);

        self::assertStringContainsString('Unavailable', $cells[1]);
        self::assertStringNotContainsString('Not checked', $cells[1]);
    }

    public function testAnUnrecognisedStateIsShownVerbatimNotCoercedToAKnownLabel(): void
    {
        \BccRenderWorld::$rows = [$this->row(1, ['metadata_state' => 'quantum_superposition'])];

        $cell = self::metadataCells($this->render())[0];

        self::assertStringContainsString('quantum_superposition', $cell);
        foreach (['Complete', 'Partial', 'Unavailable', 'Not checked'] as $known) {
            self::assertStringNotContainsString(
                $known,
                $cell,
                'a value from a newer build must not be displayed as a state it is not'
            );
        }
    }

    public function testAnAbsentStateRendersADashRatherThanGuessing(): void
    {
        \BccRenderWorld::$rows = [$this->row(1, ['metadata_state' => ''])];

        $cell = self::metadataCells($this->render())[0];

        self::assertStringNotContainsString('Complete', $cell);
        self::assertStringNotContainsString('Not checked', $cell);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. THE THREE EMPTY STATES ARE THREE DIFFERENT MESSAGES
    // ═══════════════════════════════════════════════════════════════════

    public function testPastTheLastPageSaysSoInsteadOfClaimingTheTabIsEmpty(): void
    {
        \BccRenderWorld::$rows = [$this->row(1), $this->row(2), $this->row(3)];
        $_GET['paged'] = '99';

        $cell = self::emptyCell($this->render());

        self::assertStringContainsString('past the end', $cell);
        self::assertStringContainsString('Nothing is missing', $cell);
        // ⚠ The old copy. It told the operator to create data that exists.
        self::assertStringNotContainsString('No unverified collections', $cell);
        self::assertStringNotContainsString('Add one from NFT Discovery', $cell);
    }

    public function testPastTheLastPageOffersAWayBack(): void
    {
        \BccRenderWorld::$rows = [$this->row(1)];
        $_GET['paged'] = '42';

        $cell = self::emptyCell($this->render());

        self::assertStringContainsString('Back to the first page', $cell);
    }

    public function testASearchMatchingNothingSaysSoRatherThanTellingYouToAddACollection(): void
    {
        \BccRenderWorld::$rows = [$this->row(1), $this->row(2)];
        $_GET['s'] = 'zzzz-no-such-collection';

        $cell = self::emptyCell($this->render());

        self::assertStringContainsString('match the current search or filters', $cell);
        self::assertStringContainsString('Clear filters', $cell);
        self::assertStringNotContainsString('Add one from NFT Discovery', $cell);
    }

    public function testAGenuinelyEmptyTabStillGetsItsOriginalPerTabMessage(): void
    {
        // The third case, and the only one the original copy was written for.
        // It must survive: a truly empty discovery queue SHOULD tell the
        // operator where collections come from.
        \BccRenderWorld::$rows = [];

        $cell = self::emptyCell($this->render());

        self::assertStringContainsString('No unverified collections', $cell);
        self::assertStringNotContainsString('past the end', $cell);
        self::assertStringNotContainsString('match the current search', $cell);
    }

    public function testPageOneOfAnEmptyTabIsNotReportedAsPastTheEnd(): void
    {
        // Anti-regression on the branch order: `paged=1` with no rows is the
        // genuinely-empty case, not the past-the-end case.
        \BccRenderWorld::$rows = [];
        $_GET['paged'] = '1';

        self::assertStringNotContainsString('past the end', self::emptyCell($this->render()));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. THE FILTER CONTROLS ARE USABLE WITHOUT A MOUSE
    // ═══════════════════════════════════════════════════════════════════

    public function testTheSearchFieldIsRenderedAndLabelled(): void
    {
        \BccRenderWorld::$rows = [$this->row(1)];
        $html = $this->render();

        self::assertMatchesRegularExpression('/<input[^>]+name="s"/', $html);
        self::assertMatchesRegularExpression('/<label[^>]+for="bcc-vc-search"/', $html);
    }

    public function testTheSearchFieldKeepsTheCurrentTerm(): void
    {
        \BccRenderWorld::$rows = [$this->row(1, ['collection_name' => 'Findable One'])];
        $_GET['s'] = 'Findable';

        self::assertStringContainsString('value="Findable"', $this->render());
    }

    public function testNeitherSelectAutoSubmitsOnChange(): void
    {
        // ⚠ The accessibility defect. Arrowing through a select fired a
        // navigation per keystroke, so the control could not be operated
        // from the keyboard at all.
        \BccRenderWorld::$rows = [$this->row(1)];
        $html = $this->render();

        self::assertMatchesRegularExpression('/<select[^>]+name="chain"/', $html);
        self::assertDoesNotMatchRegularExpression(
            '/<select[^>]*onchange/',
            $html,
            'no select may auto-submit the filter form'
        );
    }

    public function testAnAlwaysPresentSubmitButtonExistsOutsideNoscript(): void
    {
        \BccRenderWorld::$rows = [$this->row(1)];
        $html = $this->render();

        // Strip <noscript> so a button that only exists there cannot satisfy
        // this — that was the previous state.
        $withoutNoscript = preg_replace('#<noscript>.*?</noscript>#s', '', $html) ?? $html;

        self::assertMatchesRegularExpression(
            '/<button[^>]+type="submit"[^>]*>\s*Filter\s*</',
            $withoutNoscript,
            'the Filter submit must exist for JavaScript-enabled keyboard users'
        );
    }

    public function testClearFiltersAppearsOnlyWhenSomethingIsFiltered(): void
    {
        \BccRenderWorld::$rows = [$this->row(1)];

        self::assertStringNotContainsString('Clear filters', $this->render());

        $_GET['s'] = 'Collection';
        self::assertStringContainsString('Clear filters', $this->render());
    }
}
