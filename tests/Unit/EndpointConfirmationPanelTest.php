<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\ChainsPage;
use BCC\Trust\Onchain\Services\CosmosEndpointReview;
use BCC\Trust\Onchain\Services\CosmosEndpointTransition;
use BCC\Trust\Onchain\Support\EndpointDescriptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The read-only confirmation screen: it must write nothing and leak nothing.
 *
 * ── WHY THE REAL SERVICE RUNS HERE ──────────────────────────────────────
 * Unlike the gate tests, this file does NOT fake `CosmosEndpointTransition`.
 * The decision about what may be printed lives inside `plan()` — the incumbent
 * is shown in full only when it is itself policy-approved, and redacted to
 * scheme and host otherwise — so faking it would test the fake's judgement
 * rather than the code's.
 *
 * ⚠ `wp_bcc_chains.rest_url` is a TAINTED CREDENTIAL COLUMN for
 * `scripts/endpoint-exposure-guard.php`. That guard is the mechanical check and
 * it runs in CI; this is the behavioural one, against a value that actually
 * carries a secret.
 */
#[CoversClass(ChainsPage::class)]
#[CoversClass(CosmosEndpointTransition::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EndpointConfirmationPanelTest extends TestCase
{
    private const CHAIN = 8;
    private const OPERATOR = 7;

    private const TARGET = 'https://cosmos-api.polkachu.com';

    /** An incumbent a hand edit could plausibly have left behind. */
    private const DIRTY = 'https://admin:sup3rsecret@rest.cosmos.directory/cosmoshub?apikey=abc123xyz';

    private const APPROVED_INCUMBENT = 'https://rest.cosmos.directory/cosmoshub';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/endpoint-admin-stubs.php';

        \BccEndpointAdminState::reset();
        $_POST = [];
        $_GET  = [];
    }

    private function renderPanel(): string
    {
        $m = new ReflectionMethod(ChainsPage::class, 'render_endpoint_review_panel');
        $m->setAccessible(true);

        ob_start();
        $m->invoke(null);

        return (string) ob_get_clean();
    }

    private function seedAndReview(?string $incumbent): void
    {
        \BccEndpointAdminState::seedChain(self::CHAIN, $incumbent);

        $r = CosmosEndpointTransition::review(self::CHAIN, self::TARGET, self::OPERATOR);
        self::assertTrue($r['ok'], 'test precondition: the review must be accepted — got ' . $r['reason']);
    }

    // ── Leak-freedom ────────────────────────────────────────────────────

    /**
     * ⚠⚠⚠ NO PART OF A CREDENTIAL-BEARING INCUMBENT MAY REACH THE HTML.
     */
    public function testACredentialBearingIncumbentIsNeverPrinted(): void
    {
        $this->seedAndReview(self::DIRTY);

        $html = $this->renderPanel();

        self::assertNotSame('', $html, 'anti-vacuity: the panel actually rendered');

        foreach ([self::DIRTY, 'sup3rsecret', 'admin:', 'apikey=abc123xyz', 'abc123xyz'] as $secret) {
            self::assertStringNotContainsString(
                $secret,
                $html,
                'the panel must not print any part of a credential-bearing endpoint'
            );
        }
    }

    /** The comparison token must never be rendered either. */
    public function testTheReviewFingerprintAndEndpointIdentityAreNeverPrinted(): void
    {
        $this->seedAndReview(self::DIRTY);

        $html = $this->renderPanel();

        self::assertStringNotContainsString(CosmosEndpointReview::fingerprint(self::DIRTY), $html);
        self::assertStringNotContainsString(EndpointDescriptor::identity(self::DIRTY), $html);
    }

    /**
     * ANTI-VACUITY. The same assertions, against a render that DOES contain the
     * value — so a panel that started printing it could not pass unnoticed.
     */
    public function testThePanelScanWouldCatchALeak(): void
    {
        $this->seedAndReview(self::DIRTY);

        $planted = $this->renderPanel() . '<input type="hidden" value="' . self::DIRTY . '">';

        self::assertStringContainsString(self::DIRTY, $planted);
        self::assertStringContainsString('sup3rsecret', $planted);
    }

    /** An off-policy incumbent is named only by scheme and host, with a marker. */
    public function testAnOffPolicyIncumbentIsRedactedAndLabelled(): void
    {
        $this->seedAndReview(self::DIRTY);

        $html = $this->renderPanel();

        self::assertStringContainsString('rest.cosmos.directory', $html, 'the host is useful and safe');
        self::assertStringContainsString('Off-policy value', $html, 'and the operator is told it is partial');
    }

    /**
     * ANTI-VACUITY for the redaction: an APPROVED incumbent is shown in full, so
     * the test above is measuring a decision rather than a blanket refusal.
     */
    public function testAnApprovedIncumbentIsShownInFull(): void
    {
        $this->seedAndReview(self::APPROVED_INCUMBENT);

        $html = $this->renderPanel();

        self::assertStringContainsString(self::APPROVED_INCUMBENT, $html);
        self::assertStringNotContainsString('Off-policy value', $html);
    }

    public function testANullIncumbentIsDescribedRatherThanPrintedAsEmpty(): void
    {
        $this->seedAndReview(null);

        $html = $this->renderPanel();

        self::assertStringContainsString('none set', $html);
    }

    // ── The render writes nothing ───────────────────────────────────────

    /**
     * ⚠ A GET MUST NOT HAVE A SIDE EFFECT. The review is minted by a POST
     * precisely so this render has none — an earlier draft of the design minted
     * it here, which is what made the screen a writer.
     */
    public function testRenderingThePanelWritesNothingAndMintsNothing(): void
    {
        $this->seedAndReview(self::APPROVED_INCUMBENT);

        $before = \BccEndpointAdminState::$transients;

        $this->renderPanel();
        $this->renderPanel();

        self::assertSame(
            $before,
            \BccEndpointAdminState::$transients,
            'rendering twice must not mint, consume or touch a single stored value'
        );
    }

    public function testTheReviewSurvivesRenderingSoItCanStillBeConfirmed(): void
    {
        $this->seedAndReview(self::APPROVED_INCUMBENT);
        $this->renderPanel();

        self::assertNotNull(
            CosmosEndpointReview::peek(self::OPERATOR),
            'a read-only screen must leave the pending review claimable'
        );
    }

    public function testNoPanelRendersWithoutAPendingReview(): void
    {
        \BccEndpointAdminState::seedChain(self::CHAIN, self::APPROVED_INCUMBENT);

        self::assertSame('', $this->renderPanel());
    }

    // ── The confirmation form ───────────────────────────────────────────

    public function testTheFormCarriesTheReviewIdAndTheChainAndNoTarget(): void
    {
        $this->seedAndReview(self::APPROVED_INCUMBENT);
        $review = CosmosEndpointReview::peek(self::OPERATOR);
        self::assertIsArray($review);

        $html = $this->renderPanel();

        self::assertStringContainsString('name="review_id"', $html);
        self::assertStringContainsString((string) $review['review_id'], $html);
        self::assertStringContainsString('name="chain_id"', $html);
        self::assertStringNotContainsString(
            'name="target_url"',
            $html,
            'the target comes from the review, not from the form'
        );
        self::assertStringNotContainsString(
            'name="plan_digest"',
            $html,
            'the digest is retired'
        );
    }

    /**
     * A review whose world moved is reported, not confirmable. The panel re-runs
     * `plan()` rather than trusting what it recorded.
     */
    public function testAReviewThatCanNoLongerExecuteSaysSoInsteadOfOfferingAButton(): void
    {
        $this->seedAndReview(self::APPROVED_INCUMBENT);

        // The chain becomes ungoverned under the operator's feet.
        \BccEndpointAdminState::$chains[self::CHAIN]->slug = 'not-governed-anywhere';

        $html = $this->renderPanel();

        self::assertStringContainsString('can no longer be confirmed', $html);
        self::assertStringNotContainsString('name="review_id"', $html, 'no button on a dead plan');
    }
}
