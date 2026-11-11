<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's tab navigation: which tab it says you are on, and what it
 * does when it cannot honour your selection.
 *
 * ── THREE DEFECTS THIS PINS ─────────────────────────────────────────────
 *  1. The active tab was marked by a CSS class ALONE, so a screen reader
 *     announced seven identical links with nothing to distinguish them.
 *  2. An unrecognised `?tab=` was silently rewritten to Overview. A dead
 *     bookmark was therefore indistinguishable from a working one.
 *  3. ⚠⚠ If a tab's renderer was missing, the view called
 *     `bcc_trust_render_overview_tab()` — so the nav highlighted, say,
 *     Signals while the panel below showed OVERVIEW'S TOTALS, with nothing
 *     saying so. Reading those numbers as the selected tab's is the obvious
 *     mistake, and the page invited it.
 *
 * ⚠ Separate processes: this suite includes `dashboard-view.php`, which
 * defines functions and reads superglobals, and it deliberately runs it more
 * than once with different `$_GET`. A shared process would carry the first
 * include's definitions into every later case.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DashboardNavigationTest extends TestCase
{
    /**
     * Renders the real nav with the real cluster map.
     *
     * `$defineRenderers` is the knob the third defect needs: with it false,
     * the tab functions do not exist, which is exactly the state a partially
     * deployed or truncated tab file leaves behind.
     */
    private function renderNav(string $tab, string $sub = '', bool $defineRenderers = true): string
    {
        require_once __DIR__ . '/../Stubs/dashboard-nav-stubs.php';

        if ($defineRenderers) {
            \BccNavStubs::defineRenderers();
        } else {
            // Only Overview exists. The view's fallback used to reach for it.
            \BccNavStubs::defineOverviewRendererOnly();
        }

        $_GET['tab'] = $tab;
        $_GET['sub'] = $sub;

        ob_start();
        try {
            include __DIR__ . '/../../includes/dashboard-view.php';
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** @return list<string> the cluster slugs marked aria-current in the cluster nav */
    private function currentClusters(string $html): array
    {
        if (!preg_match('~<nav class="nav-tab-wrapper">(.*?)</nav>~s', $html, $m)) {
            return [];
        }
        $out = [];
        foreach (preg_match_all('~<a\b[^>]*>~', $m[1], $a) ? $a[0] : [] as $anchor) {
            if (str_contains($anchor, 'aria-current="page"')
                && preg_match('~tab=([a-z-]+)~', $anchor, $t)) {
                $out[] = $t[1];
            }
        }

        return $out;
    }

    // ── 0. CONTROLS
    // ═══════════════════════════════════════════════════════════════════

    public function testTheHarnessRendersTheRealNav(): void
    {
        $html = $this->renderNav('overview');

        self::assertStringContainsString('nav-tab-wrapper', $html);
        self::assertStringContainsString('Overview', $html);
    }

    public function testEveryNavItemIsARealLink(): void
    {
        // Keyboard access rests entirely on these being anchors with hrefs:
        // focusable by Tab and activatable by Enter without any JavaScript.
        $html = $this->renderNav('signals', 'fraud');
        preg_match('~<nav class="nav-tab-wrapper">(.*?)</nav>~s', $html, $m);

        $anchors = preg_match_all('~<a\b[^>]*>~', $m[1], $a) ? $a[0] : [];
        self::assertNotEmpty($anchors);
        foreach ($anchors as $anchor) {
            self::assertStringContainsString('href=', $anchor, 'every nav item needs a real href');
            self::assertStringNotContainsString('tabindex="-1"', $anchor, 'nothing may leave the tab order');
            self::assertStringNotContainsString('onclick', $anchor, 'navigation must not be JS-only');
            self::assertDoesNotMatchRegularExpression('~href="(#|javascript:)~', $anchor);
        }
    }

    // ── 1. aria-current MARKS THE TAB YOU ARE ON
    // ═══════════════════════════════════════════════════════════════════

    public function testTheSelectedClusterIsMarkedAriaCurrent(): void
    {
        $html = $this->renderNav('signals', 'fraud');

        self::assertSame(['signals'], $this->currentClusters($html));
    }

    public function testExactlyOneClusterIsEverMarked(): void
    {
        // ⚠ Anti-vacuity: an attribute on every link conveys nothing.
        foreach (['overview', 'ecosystem', 'pages', 'users', 'signals', 'activity'] as $slug) {
            self::assertCount(
                1,
                $this->currentClusters($this->renderNav($slug)),
                "exactly one cluster must be current for ?tab={$slug}"
            );
        }
    }

    public function testTheSelectedSubIsMarkedAriaCurrent(): void
    {
        $html = $this->renderNav('signals', 'devices');

        preg_match('~<div class="bcc-subnav"(.*?)</div>~s', $html, $m);
        $marked = [];
        foreach (preg_match_all('~<a\b[^>]*>~', $m[1], $a) ? $a[0] : [] as $anchor) {
            if (str_contains($anchor, 'aria-current="page"') && preg_match('~sub=([a-z-]+)~', $anchor, $s)) {
                $marked[] = $s[1];
            }
        }

        self::assertSame(['devices'], $marked);
    }

    public function testTheClassAndTheAttributeAgree(): void
    {
        // If these ever disagreed, sighted and screen-reader users would be
        // told different things about the same page.
        $html = $this->renderNav('users', 'verified');
        preg_match('~<nav class="nav-tab-wrapper">(.*?)</nav>~s', $html, $m);

        foreach (preg_match_all('~<a\b[^>]*>~', $m[1], $a) ? $a[0] : [] as $anchor) {
            $hasClass = str_contains($anchor, 'nav-tab-active');
            $hasAria  = str_contains($anchor, 'aria-current="page"');
            self::assertSame($hasClass, $hasAria, "class and aria-current disagree on: {$anchor}");
        }
    }

    // ── 2. AN UNKNOWN TAB IS SAID OUT LOUD
    // ═══════════════════════════════════════════════════════════════════

    public function testAnUnknownTabSaysSoAndNamesTheSlug(): void
    {
        $html = $this->renderNav('does-not-exist');

        self::assertStringContainsString('There is no tab called', $html);
        self::assertStringContainsString('does-not-exist', $html);
    }

    public function testAnUnknownTabStillShowsOverviewAndMarksIt(): void
    {
        // Falling back is right; doing it silently was not. The nav must agree
        // with what is actually on screen.
        $html = $this->renderNav('does-not-exist');

        self::assertSame(['overview'], $this->currentClusters($html));
    }

    public function testTheUnknownTabSlugIsEscaped(): void
    {
        // It comes from the URL. sanitize_key() already narrows it, but the
        // notice must not be the one place that trusts it.
        $html = $this->renderNav('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testAKnownTabMakesNoUnknownTabClaim(): void
    {
        // ⚠ Anti-vacuity for the two cases above.
        self::assertStringNotContainsString(
            'There is no tab called',
            $this->renderNav('signals', 'fraud')
        );
    }

    public function testAnUnknownSubFallsBackWithoutClaimingAnUnknownTab(): void
    {
        // A valid cluster with a bad sub is a different case: the cluster
        // resolved, so only the sub falls back to its default.
        $html = $this->renderNav('signals', 'not-a-sub');

        self::assertSame(['signals'], $this->currentClusters($html));
        self::assertStringNotContainsString('There is no tab called', $html);
    }

    // ── 3. ⚠⚠ A MISSING RENDERER NEVER SHOWS ANOTHER TAB'S DATA
    // ═══════════════════════════════════════════════════════════════════

    public function testAMissingRendererSaysSoRatherThanShowingOverview(): void
    {
        $html = $this->renderNav('signals', 'fraud', false);

        self::assertStringContainsString('This tab could not be loaded', $html);
        self::assertStringContainsString('signals / fraud', $html);
        self::assertStringContainsString("No other tab", $html);
    }

    public function testAMissingRendererDoesNotRenderOverviewsContent(): void
    {
        // ⚠ THE ASSERTION THIS WHOLE CASE EXISTS FOR. The old fallback called
        // the Overview renderer, so its totals appeared under the selected
        // tab with nothing marking them as another tab's.
        $html = $this->renderNav('signals', 'fraud', false);

        self::assertStringNotContainsString(
            \BccNavStubs::OVERVIEW_MARKER,
            $html,
            "Overview's content must not be rendered in a failed tab's place"
        );
    }

    public function testAMissingRendererStillMarksTheTabTheOperatorChose(): void
    {
        // The selection is honoured, just empty. Silently moving the
        // highlight would hide that the operator's click did nothing.
        $html = $this->renderNav('signals', 'fraud', false);

        self::assertSame(['signals'], $this->currentClusters($html));
    }

    public function testAMissingRendererOffersAWayOut(): void
    {
        $html = $this->renderNav('signals', 'fraud', false);

        self::assertStringContainsString('Go to Overview', $html);
    }

    public function testAPresentRendererIsStillCalled(): void
    {
        // ⚠ Anti-vacuity for the three cases above: the honest-failure branch
        // must not have swallowed the normal one.
        $html = $this->renderNav('signals', 'fraud', true);

        self::assertStringContainsString(\BccNavStubs::FRAUD_MARKER, $html);
        self::assertStringNotContainsString('This tab could not be loaded', $html);
    }

    public function testNoDegradedNavStateEmitsPhpDiagnostics(): void
    {
        foreach ([
            ['does-not-exist', '', true],
            ['signals', 'not-a-sub', true],
            ['signals', 'fraud', false],
            ['overview', '', false],
        ] as [$tab, $sub, $define]) {
            $html = $this->renderNav($tab, $sub, $define);
            foreach (['Warning', 'TypeError', 'Deprecated', 'Fatal'] as $shape) {
                self::assertStringNotContainsString($shape, $html, "?tab={$tab}&sub={$sub} emitted {$shape}");
            }
        }
    }
}
