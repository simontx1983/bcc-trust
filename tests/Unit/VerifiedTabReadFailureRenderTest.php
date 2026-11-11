<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\VerifiedRenderWorld;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * What Users ▸ Verified TELLS AN OPERATOR when a read did not run.
 *
 * Pre-fix, with the verifications table replaced by an invalid view, the page
 * read as a complete and healthy measurement:
 *
 *   Total Verified      0
 *   0 in last 30 days
 *   Avg Net Impact      0
 *   High Quality        0
 *   table body          "No verified users found."
 *
 * Five confident statements about a table nobody read, under a green
 * left-border card.
 *
 * ── ⚠⚠ WHY THIS SUITE EXISTS SEPARATELY FROM THE INTEGRATION ONE ────────
 * The integration suite proves the REPOSITORY tells the two states apart.
 * It cannot prove the page SAYS so, and the first version of this fix dashed
 * "Total Verified" alone — leaving the other three zeros standing beside a
 * dash, which is a worse page than either consistent alternative. Only a
 * render assertion catches that, and nothing in the repository suite did.
 *
 * ── THE PRESERVATION HALF ───────────────────────────────────────────────
 * "No verified users found." is CORRECT when the read ran and matched
 * nothing, and must still appear. A fix that removed it would trade one
 * wrong answer for another.
 *
 * ⚠ Separate processes with PreserveGlobalState(false) are required; see the
 * stub file for why a class_exists() guard cannot substitute.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class VerifiedTabReadFailureRenderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../Stubs/verified-tab-render-stubs.php';
        require_once __DIR__ . '/../../includes/admin/dashboard-verified.php';
    }

    protected function setUp(): void
    {
        VerifiedRenderWorld::reset();
        // ⚠ The tab reads $_GET. Cleared per test so a sort or page left by
        // one case cannot change what another renders.
        $_GET = [];
    }

    /** try/finally: a throwing render must not leak its buffer into the next test. */
    private function render(): string
    {
        ob_start();
        try {
            bcc_trust_render_verified_tab();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    private function fail_(): void
    {
        VerifiedRenderWorld::$panel['ok']               = false;
        VerifiedRenderWorld::$panel['total']            = 0;
        VerifiedRenderWorld::$panel['users']            = [];
        VerifiedRenderWorld::$panel['summary']['ok']    = false;
        foreach (['total_verified', 'avg_net_impact', 'high_quality_count', 'recent_verifications'] as $k) {
            // ⚠ ZERO, not null — this is the whole point. A failed read
            // reaches the view as the same 0 a quiet month produces, so the
            // fixture must hand the view exactly that and nothing weaker.
            VerifiedRenderWorld::$panel['summary'][$k] = 0;
        }
    }

    // ── 0. CONTROLS
    // ═══════════════════════════════════════════════════════════════════

    public function testAHealthyPageShowsItsFigures(): void
    {
        // ⚠ THE CONTROL that gives every later assertion its meaning: if the
        // page rendered nothing at all, every "must not contain" below would
        // pass forever.
        $html = $this->render();

        self::assertStringContainsString('1,234', $html, 'the headline figure must render');
        self::assertStringContainsString('7.3', $html, 'avg net impact, rounded to 1dp');
        self::assertStringContainsString('56', $html);
        self::assertStringContainsString('78 in last 30 days', $html);
        self::assertStringNotContainsString('could not be read', $html);
    }

    public function testAHealthyPageWithNoRowsStillSaysNoneFound(): void
    {
        // ⚠ THE PRESERVATION CASE. Read ran, matched nothing: the empty
        // sentence is the right answer and must survive the fix.
        VerifiedRenderWorld::$panel['total'] = 0;
        VerifiedRenderWorld::$panel['users'] = [];

        $html = $this->render();

        self::assertStringContainsString('No verified users found', $html);
        self::assertStringNotContainsString('could not be read', $html);
    }

    // ── 1. ⚠⚠ A FAILED READ SAYS SO, AT THE TOP
    // ═══════════════════════════════════════════════════════════════════

    public function testAFailedReadLeadsWithAPageLevelNotice(): void
    {
        // The in-table row sits below four stat cards and a filter bar. A
        // failure visible only after scrolling is a failure that gets missed,
        // so the notice has to be above the cards it invalidates.
        $this->fail_();

        $html = $this->render();

        self::assertStringContainsString('notice-error', $html);
        self::assertStringContainsString('This page could not be read', $html);
        self::assertLessThan(
            strpos($html, 'Total Verified'),
            strpos($html, 'This page could not be read'),
            'the notice must come BEFORE the figures it invalidates'
        );
    }

    public function testAFailedReadDoesNotSayNoVerifiedUsersFound(): void
    {
        $this->fail_();

        $html = $this->render();

        self::assertStringNotContainsString(
            'No verified users found',
            $html,
            'that asserts a fact about a table nobody read'
        );
        self::assertStringContainsString('The verified-user list could not be read', $html);
    }

    // ── 2. ⚠⚠ EVERY READ-DERIVED FIGURE, NOT JUST THE HEADLINE
    // ═══════════════════════════════════════════════════════════════════

    public function testEveryReadDerivedFigureBecomesADash(): void
    {
        // ⚠ THE CASE THE FIRST VERSION OF THIS FIX WOULD HAVE FAILED. It
        // dashed Total Verified and left three confident zeros beside it.
        $this->fail_();

        $html = $this->render();

        self::assertSame(
            4,
            substr_count($html, '—'),
            'all four read-derived figures must be dashes: total, recent, avg, high-quality'
        );
        self::assertStringContainsString('— in last 30 days', $html);
    }

    public function testNoStatCardShowsAZeroOnAFailedRead(): void
    {
        // Stated as an absence as well as a presence, because "contains a
        // dash" and "contains no confident zero" are different claims and
        // only the pair rules out a page showing both.
        $this->fail_();

        $html = $this->render();

        foreach (['>0</div>', '>0 </div>'] as $shape) {
            self::assertStringNotContainsString(
                $shape,
                $html,
                'a stat-card value of exactly 0 is the collapse this fix removes'
            );
        }
        self::assertStringNotContainsString('0 in last 30 days', $html);
    }

    public function testTheConfigDerivedCardIsNotDashed(): void
    {
        // ⚠ ANTI-OVER-REACH, and it is the half a removal test cannot cover.
        // Trust Boost Tiers comes from config.php, not from a read. Dashing
        // it would be the fix lying in the other direction — telling the
        // operator a constant is unavailable.
        $this->fail_();

        $html = $this->render();

        // ⚠ DERIVED FROM THE CONSTANTS, not hardcoded. The property is "the
        // config card still shows the config value", and a literal here just
        // pins today's weights: the first version asserted 20% against a real
        // config of 25% and failed as a stub defect dressed as a page defect.
        self::assertStringContainsString(
            'Elite: ' . intval(BCC_TRUST_WEIGHT_ELITE * 100) . '%',
            $html
        );
        self::assertStringContainsString(
            'Trusted: ' . intval(BCC_TRUST_WEIGHT_TRUSTED * 100) . '%',
            $html
        );
        // And the card is genuinely a number, so the assertion above cannot
        // pass by both sides being a dash.
        self::assertMatchesRegularExpression('/Elite: \d+%/', $html);
    }

    public function testPaginationDoesNotClaimZeroItems(): void
    {
        // total 0 makes total_pages 0, so the whole block is skipped and no
        // "0 items" is printed. Asserted rather than assumed, because the
        // guard is `> 1` and a later change to `>= 1` would start printing a
        // confident count from an unread table.
        $this->fail_();

        $html = $this->render();

        self::assertStringNotContainsString('items', $html);
        self::assertStringNotContainsString('<!--PAGINATE-->', $html);
    }

    // ── 3. THE TWO STATES ARE TOLD APART
    // ═══════════════════════════════════════════════════════════════════

    public function testTheEmptyAndFailedPagesDifferOnlyInWhatTheyClaim(): void
    {
        // ⚠ The pair. Both have total 0 and no rows; only the sentence and
        // the figures differ. Without this, a suite can pass while the two
        // states render identically.
        VerifiedRenderWorld::$panel['total'] = 0;
        VerifiedRenderWorld::$panel['users'] = [];
        $empty = $this->render();

        VerifiedRenderWorld::reset();
        $this->fail_();
        $broken = $this->render();

        self::assertStringContainsString('No verified users found', $empty);
        self::assertStringNotContainsString('No verified users found', $broken);
        self::assertStringNotContainsString('notice-error', $empty);
        self::assertStringContainsString('notice-error', $broken);
    }

    public function testAPageThatCannotBeReadRendersWithoutAPhpError(): void
    {
        // ⚠ round('—', 1) is a TypeError in PHP 8 and number_format('—') a
        // deprecation, so the dash has to be returned INSTEAD OF the
        // arithmetic rather than fed into it. This suite runs under
        // failOnWarning="true", so a notice here is a failure, not noise.
        $this->fail_();

        $html = $this->render();

        self::assertStringNotContainsString('Warning', $html);
        self::assertStringNotContainsString('Deprecated', $html);
        self::assertNotSame('', $html, 'and it must still render');
    }
}
