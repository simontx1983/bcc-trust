<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANT: the community cell costs the same whether the page shows
 * five rows or fifty.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 * The render loop resolved each row's community cell by itself:
 *
 *   GatedGroupRepository::findGroupForCollection()   1 query per row
 *     (and a ChainRepository::getById() inside it, also per row)
 *   PeepSoGroupRepository::countGroupMembers()       1 query per row
 *   get_permalink()                                  1 post read per row
 *
 * At the 100-row page size that is up to ~300 reads for one screen.
 *
 * ⚠⚠ AND A COMMENT ABOVE THE LOOP SAID THE N+1 WAS "GONE". It was gone for
 * community EXISTENCE, which PR 6 projected into `listForAdminState()`.
 * These three were not existence and were not projected, so the comment
 * described a fix that covered none of them. That is why this file asserts
 * the counts directly instead of trusting a comment.
 *
 * ── WHY COUNTING AT TWO ROW COUNTS IS THE PROOF ─────────────────────────
 * A single render cannot distinguish "one batched read" from "one row that
 * happened to need one read". Rendering 5 rows and then 50 and requiring
 * the SAME counts is what makes the claim falsifiable: the old code's
 * counts would have moved with the row count, and any future change that
 * reintroduces per-row work fails here.
 *
 * `testTheCountersWouldCatchARestoredPerRowLookup` is the anti-vacuity
 * control — it drives the per-row methods directly and proves the counters
 * actually move, so a count of 0 means "not called" rather than "not
 * wired".
 */
#[CoversClass(VerifyCollectionsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class VerifyCollectionsCommunityCellPrefetchTest extends TestCase
{
    private const CHAIN_ID = 1;

    /** Column index of the community cell in a data row (0-based). */
    /**
     * Column index of the community cell in a data row (0-based).
     *
     * ⚠ 10, not 9. Column 9 is the marketplace-wide "Holders" cell, which
     * renders an em dash when upstream has no figure — so an index that is
     * off by one reads as "the community cell is empty" and every
     * behavioural assertion below fails for a reason that has nothing to do
     * with the community cell. Header order: Verified, image, Collection,
     * Source, Chain, Contract, Standard, State, Linked holders, Holders,
     * Community, Actions.
     */
    private const COMMUNITY_TD = 10;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/verify-collections-render-isolation-stubs.php';

        \BccRenderWorld::reset();
        \BccPostCacheSpy::reset();
        $this->resetCounters();

        $_GET     = [];
        $_POST    = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        \BccRenderWorld::$chains = [
            (object) [
                'id' => self::CHAIN_ID, 'slug' => 'ethereum', 'name' => 'Ethereum',
                'chain_type' => 'evm', 'is_active' => 1,
                'bcc_supports_nft_collections' => '1',
                'cosmwasm_nft_discovery_enabled' => '0',
            ],
        ];
    }

    private function resetCounters(): void
    {
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$perRowCalls  = 0;
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$batchCalls   = 0;
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$batchSizes   = [];
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$groupByRowId = [];
        \BCC\Core\Repositories\PeepSoGroupRepository::$perRowCalls   = 0;
        \BCC\Core\Repositories\PeepSoGroupRepository::$batchCalls    = 0;
        \BCC\Core\Repositories\PeepSoGroupRepository::$memberCounts  = [];
    }

    // ── fixture ─────────────────────────────────────────────────────────

    /**
     * $n rows, EVERY one of them carrying a community, each mapped to its
     * own gated group with its own member count. Worst case on purpose: if
     * any per-row work survives, it runs for every row.
     */
    private function seedRows(int $n): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $id       = 100 + $i;
            $groupId  = 9000 + $i;
            $contract = '0x' . str_pad((string) ($i + 1), 40, '0', STR_PAD_LEFT);

            $rows[] = (object) [
                'id'                        => $id,
                'contract_address'          => $contract,
                'canonical_identifier'      => $contract,
                'collection_name'           => 'Collection ' . $i,
                'image_url'                 => null,
                'token_standard'            => 'ERC-721',
                'chain_id'                  => self::CHAIN_ID,
                'chain_slug'                => 'ethereum',
                'chain_type'                => 'evm',
                'is_verified'               => 0,
                'is_hidden'                 => 0,
                'source'                    => 'discovery',
                'unique_holders'            => null,
                'has_community'             => 1,
                'provisioning_state'        => 'provisioned',
                'provisioning_failure_code' => null,
            ];

            \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$groupByRowId[$id] = $groupId;
            \BCC\Core\Repositories\PeepSoGroupRepository::$memberCounts[$groupId]    = ($i + 1) * 3;
        }

        \BccRenderWorld::$rows = $rows;
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

    /** @return array<int, string> collection id => trimmed community cell text */
    private static function communityCells(string $html): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();

        $cells = [];
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
            $cells[(int) $tr->getAttribute('data-collection-id')] =
                trim((string) ($tds[self::COMMUNITY_TD]->textContent ?? ''));
        }

        return $cells;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  0. ANTI-VACUITY — the counters have to be able to move
    // ═══════════════════════════════════════════════════════════════════

    public function testTheCountersWouldCatchARestoredPerRowLookup(): void
    {
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::findGroupForCollection(1, '0xabc');
        \BCC\Core\Repositories\PeepSoGroupRepository::countGroupMembers(9000);

        self::assertSame(
            1,
            \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$perRowCalls,
            'the per-row group lookup counter must be wired'
        );
        self::assertSame(
            1,
            \BCC\Core\Repositories\PeepSoGroupRepository::$perRowCalls,
            'the per-row member-count counter must be wired'
        );
    }

    public function testTheFixtureGivesEveryRowACommunityToResolve(): void
    {
        $this->seedRows(50);

        self::assertCount(50, \BccRenderWorld::$rows);
        foreach (\BccRenderWorld::$rows as $row) {
            self::assertSame(1, (int) $row->has_community);
        }
        self::assertCount(
            50,
            \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$groupByRowId,
            'every row must map to a group, or "no per-row calls" could be vacuous'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. THE QUERY COUNT DOES NOT GROW PER RENDERED ROW
    // ═══════════════════════════════════════════════════════════════════

    public function testOnePageIssuesOneSetBasedGroupLookupWhateverTheRowCount(): void
    {
        $fiveHtml   = $this->renderAgainAfterReseed(5);
        $fiveBatch  = \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$batchCalls;
        $fiveRows   = count(self::communityCells($fiveHtml));

        $fiftyHtml  = $this->renderAgainAfterReseed(50);
        $fiftyBatch = \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$batchCalls;
        $fiftyRows  = count(self::communityCells($fiftyHtml));

        // Both renders really happened, at different sizes — otherwise
        // "the counts match" would be trivially true.
        self::assertSame(5, $fiveRows, 'the 5-row render rendered 5 rows');
        self::assertSame(50, $fiftyRows, 'the 50-row render rendered 50 rows');

        self::assertSame(1, $fiveBatch, '5 rows must cost ONE set-based group lookup');
        self::assertSame(1, $fiftyBatch, '50 rows must cost ONE set-based group lookup');
        self::assertSame(
            $fiveBatch,
            $fiftyBatch,
            'ten times the rows must not cost more group lookups'
        );
    }

    /** Re-seeds and renders, returning the HTML — keeps the comparison honest. */
    private function renderAgainAfterReseed(int $n): string
    {
        $this->resetCounters();
        \BccPostCacheSpy::reset();
        $this->seedRows($n);
        return $this->render();
    }

    public function testOnePageIssuesOneSetBasedMemberCountWhateverTheRowCount(): void
    {
        $this->renderAgainAfterReseed(5);
        $small = \BCC\Core\Repositories\PeepSoGroupRepository::$batchCalls;

        $this->renderAgainAfterReseed(50);
        $large = \BCC\Core\Repositories\PeepSoGroupRepository::$batchCalls;

        self::assertSame(1, $small, '5 rows must cost ONE set-based member-count read');
        self::assertSame(1, $large, '50 rows must cost ONE set-based member-count read');
    }

    public function testNoPerRowLookupSurvivesInTheRenderLoop(): void
    {
        $this->renderAgainAfterReseed(50);

        self::assertSame(
            0,
            \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$perRowCalls,
            'findGroupForCollection() must not be called from the render loop'
        );
        self::assertSame(
            0,
            \BCC\Core\Repositories\PeepSoGroupRepository::$perRowCalls,
            'countGroupMembers() must not be called from the render loop'
        );
    }

    public function testTheSetBasedLookupReceivesEveryCommunityRowAtOnce(): void
    {
        $this->renderAgainAfterReseed(50);

        self::assertSame(
            [50],
            \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$batchSizes,
            'one call carrying all 50 pairs — not 50 calls, and not a partial batch'
        );
    }

    public function testThePostCacheIsPrimedOnceAndCoversEveryPermalinkRead(): void
    {
        $this->renderAgainAfterReseed(50);

        self::assertSame(1, \BccPostCacheSpy::$primes, 'the post cache must be primed exactly once');

        $read   = array_values(array_unique(\BccPostCacheSpy::$permalinks));
        $primed = \BccPostCacheSpy::$primed;
        self::assertNotSame([], $read, 'permalinks really were read, so priming is not vacuous');
        foreach ($read as $postId) {
            self::assertContains(
                $postId,
                $primed,
                "permalink for {$postId} was read without that id being primed"
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. DISPLAYED VALUES AND ORDER ARE UNCHANGED
    // ═══════════════════════════════════════════════════════════════════

    public function testEachRowShowsItsOwnBatchedMemberCount(): void
    {
        $html  = $this->renderAgainAfterReseed(5);
        $cells = self::communityCells($html);

        // Seeded counts are (i+1)*3 for row id 100+i.
        self::assertStringContainsString('3 members', $cells[100] ?? '');
        self::assertStringContainsString('6 members', $cells[101] ?? '');
        self::assertStringContainsString('9 members', $cells[102] ?? '');
        self::assertStringContainsString('12 members', $cells[103] ?? '');
        self::assertStringContainsString('15 members', $cells[104] ?? '');
    }

    public function testASingleMemberIsNotPluralised(): void
    {
        $this->resetCounters();
        \BccPostCacheSpy::reset();
        $this->seedRows(1);
        \BCC\Core\Repositories\PeepSoGroupRepository::$memberCounts[9000] = 1;

        $cells = self::communityCells($this->render());

        self::assertStringContainsString('1 member', $cells[100] ?? '');
        self::assertStringNotContainsString('1 members', $cells[100] ?? '');
    }

    public function testAnUnresolvableGroupStillSaysTheCommunityIsPresent(): void
    {
        $this->resetCounters();
        \BccPostCacheSpy::reset();
        $this->seedRows(3);
        // The set-based predicate found a community for row 101 that the
        // canonical lookup cannot resolve.
        unset(\BCC\Trust\Onchain\Repositories\GatedGroupRepository::$groupByRowId[101]);

        $cells = self::communityCells($this->render());

        self::assertStringContainsString('identity unresolved', $cells[101] ?? '');
        self::assertStringNotContainsString('No community', $cells[101] ?? '');
        // Its neighbours are unaffected.
        self::assertStringContainsString('3 members', $cells[100] ?? '');
        self::assertStringContainsString('9 members', $cells[102] ?? '');
    }

    public function testAFailedBatchReadNeverClaimsNoCommunityExists(): void
    {
        $this->resetCounters();
        \BccPostCacheSpy::reset();
        $this->seedRows(3);
        // Nothing resolves — the same visible outcome a failed single
        // lookup already produced, for every row.
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$groupByRowId = [];

        $cells = self::communityCells($this->render());

        foreach ([100, 101, 102] as $id) {
            self::assertStringContainsString('identity unresolved', $cells[$id] ?? '');
            self::assertStringNotContainsString('No community', $cells[$id] ?? '');
        }
    }

    public function testRowOrderIsUnchangedByThePrefetch(): void
    {
        $cells = self::communityCells($this->renderAgainAfterReseed(10));

        // ⚠ DESCENDING by id. That is the page's existing fallback order
        // (`$b->id <=> $a->id`), not something this change chose — and
        // asserting the ascending range would pin an order the page never
        // had.
        self::assertSame(
            array_reverse(range(100, 109)),
            array_keys($cells),
            'the prefetch must not reorder the page'
        );
    }
}
