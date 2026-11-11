<?php
/**
 * ProfileAccess::filterVisible — the batched list-visibility filter.
 *
 * What is worth testing here is NOT PeepSo's privacy ladder; that is
 * PeepSo's, and restating it in a stub would only assert that the stub
 * matches the stub. What is ours, and what this pins:
 *
 *   - the two exemptions PeepSo's SQL does NOT make (owner, admin),
 *     which is the whole reason this is not a bare passthrough;
 *   - the fail-closed branches;
 *   - that an oversized set is REFUSED rather than truncated;
 *   - that the third constructor argument stays EMPTY, so PeepSo's own
 *     search clause — which can reach `user_email` behind an option —
 *     never fires;
 *   - order preservation, since callers filter their own page with it.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\ProfileAccess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FilterVisibleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/filter-visible-stubs.php';
        $GLOBALS['__bcc_fv']        = ['visible' => [], 'admin' => false, 'constructed' => []];
        $GLOBALS['__bcc_fv_viewer'] = 0;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_fv'], $GLOBALS['__bcc_fv_viewer']);
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function constructed(): array
    {
        $c = $GLOBALS['__bcc_fv']['constructed'] ?? [];
        return is_array($c) ? $c : [];
    }

    // ── the happy path, and the anti-vacuity control ────────────────

    public function testKeepsOnlyTheIdsPeepSoAdmits(): void
    {
        $GLOBALS['__bcc_fv_viewer']           = 7;
        $GLOBALS['__bcc_fv']['visible'][11]   = true;
        $GLOBALS['__bcc_fv']['visible'][13]   = true;

        self::assertSame(
            [11, 13],
            ProfileAccess::filterVisible([11, 12, 13]),
            'ids PeepSo admits survive; 12 does not'
        );
    }

    public function testFilterCanActuallyRemoveAndActuallyKeep(): void
    {
        // The control: a filter that returned its input unchanged, or
        // always [], would pass half the assertions in this file. This
        // asserts both directions in one call.
        $GLOBALS['__bcc_fv_viewer']         = 7;
        $GLOBALS['__bcc_fv']['visible'][2]  = true;

        $out = ProfileAccess::filterVisible([1, 2, 3]);
        self::assertSame([2], $out);
        self::assertNotSame([1, 2, 3], $out, 'it must not be a passthrough');
        self::assertNotSame([], $out, 'nor a deny-all');
    }

    public function testPreservesCallerOrderAndCollapsesDuplicates(): void
    {
        $GLOBALS['__bcc_fv_viewer']        = 7;
        foreach ([5, 9, 4] as $id) {
            $GLOBALS['__bcc_fv']['visible'][$id] = true;
        }

        // Callers use the result to filter their own already-sorted page,
        // so the input order is the contract — not PeepSo's row order.
        self::assertSame([9, 4, 5], ProfileAccess::filterVisible([9, 4, 5, 9, 4]));
    }

    // ── the two exemptions PeepSo's SQL does not make ───────────────

    public function testViewerAlwaysSeesThemselvesEvenWhenPeepSoWouldNot(): void
    {
        $GLOBALS['__bcc_fv_viewer'] = 42;
        // 42 is deliberately NOT in `visible`: PeepSo's
        // `usr_profile_acc <> ACCESS_PRIVATE` clause is unconditional, so
        // a private member is absent from their own result set.
        $GLOBALS['__bcc_fv']['visible'][8] = true;

        self::assertSame(
            [42, 8],
            ProfileAccess::filterVisible([42, 8]),
            'a private member must not vanish from their OWN list — that '
            . 'reads as data loss rather than privacy'
        );
    }

    public function testAdminSeesEveryoneWithoutConsultingPeepSoSearch(): void
    {
        $GLOBALS['__bcc_fv_viewer']  = 3;
        $GLOBALS['__bcc_fv']['admin'] = true;
        // nothing in `visible` at all

        self::assertSame([1, 2, 3], ProfileAccess::filterVisible([1, 2, 3]));
        self::assertSame(
            [],
            $this->constructed(),
            'the admin short-circuit must not even build the search'
        );
    }

    public function testNonAdminDoesConsultPeepSoSearch(): void
    {
        // Control for the assertion above: proves `constructed` is
        // populated on the ordinary path, so an empty one is meaningful.
        $GLOBALS['__bcc_fv_viewer'] = 3;
        ProfileAccess::filterVisible([1, 2]);

        self::assertCount(1, $this->constructed());
    }

    // ── the search argument must stay empty ─────────────────────────

    public function testPassesAnEmptySearchStringAndBoundsTheQuery(): void
    {
        $GLOBALS['__bcc_fv_viewer'] = 5;
        ProfileAccess::filterVisible([21, 22]);

        $call = $this->constructed()[0] ?? [];
        self::assertSame(
            '',
            $call['search'] ?? null,
            'a non-empty search would fire PeepSo\'s OWN clause, which '
            . 'searches a name concat plus user_login — and user_email too '
            . 'behind members_email_searchable'
        );
        self::assertSame(5, $call['user_id'] ?? null, 'the viewer must reach PeepSo');
        self::assertSame([21, 22], $call['args']['include'] ?? null);
        self::assertSame(2, $call['args']['number'] ?? null, 'bounded by the input size');
    }

    // ── fail-closed and bounds ─────────────────────────────────────

    public function testEmptyAndNonPositiveInputYieldEmpty(): void
    {
        self::assertSame([], ProfileAccess::filterVisible([]));
        self::assertSame([], ProfileAccess::filterVisible([0, -3]));
        self::assertSame([], $this->constructed(), 'no query for nothing to ask about');
    }

    /**
     * ⚠ REFUSED, not truncated. Truncating would silently drop the tail
     * of a list: the caller gets a short page with no signal why, which
     * looks like missing data and cannot be diagnosed from the response.
     */
    public function testAnOversizedSetIsRefusedRatherThanTruncated(): void
    {
        $GLOBALS['__bcc_fv_viewer'] = 7;
        $ids = [];
        for ($i = 1; $i <= ProfileAccess::FILTER_VISIBLE_MAX + 1; $i++) {
            $ids[] = $i;
            $GLOBALS['__bcc_fv']['visible'][$i] = true;
        }

        self::assertSame([], ProfileAccess::filterVisible($ids));
        self::assertSame(
            [],
            $this->constructed(),
            'and it refuses BEFORE querying, so an oversized set costs nothing'
        );
    }

    public function testExactlyAtTheCapIsStillAllowed(): void
    {
        // Pins the boundary as <=, not <, so the cap is not off by one.
        $GLOBALS['__bcc_fv_viewer'] = 7;
        $ids = [];
        for ($i = 1; $i <= ProfileAccess::FILTER_VISIBLE_MAX; $i++) {
            $ids[] = $i;
            $GLOBALS['__bcc_fv']['visible'][$i] = true;
        }

        self::assertCount(ProfileAccess::FILTER_VISIBLE_MAX, ProfileAccess::filterVisible($ids));
    }
}
