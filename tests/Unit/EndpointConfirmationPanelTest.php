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

    private function renderEntryForm(): string
    {
        $m = new ReflectionMethod(ChainsPage::class, 'render_endpoint_entry_form');
        $m->setAccessible(true);

        ob_start();
        $m->invoke(null, array_values(\BccEndpointAdminState::$chains));

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

    // ══ The panel describes what was REVIEWED ══════════════════════════

    /**
     * ⚠⚠ A STALE REVIEW IS REFUSED, NOT REDRAWN.
     *
     * An earlier revision recomputed `plan()` against the live row on every
     * render. So after a hand edit the panel showed the NEW endpoint while the
     * write still bound the fingerprint of the OLD one — and the copy said it
     * would move the chain "only if it is still on the endpoint shown above",
     * which was then false. Worse, the value it newly displayed could be a
     * credential-bearing one the operator never reviewed.
     */
    public function testAStaleReviewIsRefusedRatherThanShowingTheLiveValue(): void
    {
        $this->seedAndReview(self::APPROVED_INCUMBENT);

        // Someone hand-edits the row to an off-policy, credential-bearing value.
        \BccEndpointAdminState::$chains[self::CHAIN]->rest_url = self::DIRTY;

        $html = $this->renderPanel();

        self::assertStringContainsString('can no longer be confirmed', $html);
        self::assertStringContainsString(
            'endpoint_changed',
            $html,
            'and it names which thing moved'
        );
        self::assertStringNotContainsString(
            'name="review_id"',
            $html,
            'no confirm button on a review that cannot execute'
        );

        foreach ([self::DIRTY, 'sup3rsecret', 'apikey=abc123xyz'] as $secret) {
            self::assertStringNotContainsString(
                $secret,
                $html,
                'and the value it never reviewed is not printed either'
            );
        }
    }

    /**
     * ANTI-VACUITY for the test above: with nothing moved, the panel DOES show
     * the reviewed endpoint and DOES offer the button.
     */
    public function testAnUnmovedReviewShowsTheReviewedEndpointAndTheButton(): void
    {
        $this->seedAndReview(self::APPROVED_INCUMBENT);

        $html = $this->renderPanel();

        self::assertStringContainsString(self::APPROVED_INCUMBENT, $html);
        self::assertStringContainsString('name="review_id"', $html);
        self::assertStringNotContainsString('can no longer be confirmed', $html);
    }

    /** A renamed chain is a different kind of staleness, and says so. */
    public function testARenamedChainIsRefusedWithItsOwnReason(): void
    {
        $this->seedAndReview(self::APPROVED_INCUMBENT);

        \BccEndpointAdminState::$chains[self::CHAIN]->slug = 'renamed';

        $html = $this->renderPanel();

        self::assertStringContainsString('slug_changed', $html);
        self::assertStringNotContainsString('name="review_id"', $html);
    }

    /** A deactivated chain, likewise. */
    public function testADeactivatedChainIsRefusedWithItsOwnReason(): void
    {
        $this->seedAndReview(self::APPROVED_INCUMBENT);

        \BccEndpointAdminState::$chains[self::CHAIN]->is_active = 0;

        $html = $this->renderPanel();

        self::assertStringContainsString('active_changed', $html);
    }

    // ══ Step 1: the entry form ═════════════════════════════════════════

    /**
     * ⚠⚠ THIS CONTROL WAS MISSING ENTIRELY. Nothing rendered posted to
     * `ACTION_ENDPOINT_REVIEW`, so a review could only be minted by a
     * hand-crafted POST or WP-CLI and the two-step gesture could not be
     * performed from the admin at all.
     */
    public function testTheEntryFormPostsToTheReviewActionWithItsOwnNonce(): void
    {
        \BccEndpointAdminState::seedChain(self::CHAIN, self::APPROVED_INCUMBENT);

        $html = $this->renderEntryForm();

        self::assertStringContainsString('name="action"', $html);
        self::assertStringContainsString(ChainsPage::ACTION_ENDPOINT_REVIEW, $html);
        self::assertStringContainsString(
            'bcc_chain_endpoint_review_nonce',
            $html,
            'its own nonce field, not the switch route\'s'
        );
        self::assertStringNotContainsString(
            'bcc_chain_endpoint_nonce"',
            $html,
            'the two routes must not share a nonce field'
        );
        self::assertStringContainsString('name="chain_id"', $html);
        self::assertStringContainsString('method="post"', $html);
    }

    /**
     * ⚠ THE TARGET IS A CLOSED LIST. A free-text field would let an operator
     * type any URL; the handler would still refuse it, but the browser should
     * not offer the possibility. The current endpoint is excluded, because
     * switching to it is `already_current`.
     */
    public function testTheEntryFormOffersOnlyApprovedTargetsAndNoFreeTextInput(): void
    {
        \BccEndpointAdminState::seedChain(self::CHAIN, self::APPROVED_INCUMBENT);

        $html = $this->renderEntryForm();

        self::assertStringContainsString('<select name="target_url"', $html);
        self::assertStringContainsString(self::TARGET, $html, 'the approved alternative');
        self::assertStringNotContainsString(
            self::APPROVED_INCUMBENT,
            $html,
            'the endpoint it is already on is not offered'
        );
        self::assertStringNotContainsString('type="text"', $html, 'no free-text target');
        self::assertStringNotContainsString('type="url"', $html);
    }

    /** One pending review per operator, so the form stands down while one exists. */
    public function testTheEntryFormIsAbsentWhileAReviewIsPending(): void
    {
        $this->seedAndReview(self::APPROVED_INCUMBENT);

        self::assertSame('', $this->renderEntryForm());
    }

    public function testTheEntryFormIsAbsentWithoutTheCapability(): void
    {
        \BccEndpointAdminState::seedChain(self::CHAIN, self::APPROVED_INCUMBENT);
        \BccEndpointAdminState::$can = false;

        self::assertSame('', $this->renderEntryForm());
    }

    /** An ungoverned chain has no approved endpoints, so there is nothing to offer. */
    public function testTheEntryFormIgnoresAnUngovernedChain(): void
    {
        \BccEndpointAdminState::seedChain(self::CHAIN, self::APPROVED_INCUMBENT, 'not-governed');

        self::assertSame('', $this->renderEntryForm());
    }

    /** Rendering the form writes nothing — it is a GET surface. */
    public function testRenderingTheEntryFormWritesNothing(): void
    {
        \BccEndpointAdminState::seedChain(self::CHAIN, self::APPROVED_INCUMBENT);

        $before = \BccEndpointAdminState::$transients;
        $this->renderEntryForm();

        self::assertSame($before, \BccEndpointAdminState::$transients);
    }

    /**
     * ⚠⚠ A CACHED ROW MUST NOT MASK A CHANGED ONE.
     *
     * Found in a browser, against a real WordPress: the panel decided staleness
     * with `ChainRepository::getById()`, which serves from a 300-second cached
     * active set. After an out-of-band edit it therefore answered "nothing moved",
     * showed the reviewed values and offered the button — while the row held a
     * credential-bearing endpoint the operator had never reviewed. Flushing the
     * transient made the refusal appear, which is what identified the cause.
     *
     * The double is sticky here precisely so this test can fail.
     */
    public function testAStaleCacheDoesNotHideAChangedEndpointFromThePanel(): void
    {
        $this->seedAndReview(self::APPROVED_INCUMBENT);

        // Warm the modelled cache the way rendering the page would.
        \BCC\Trust\Onchain\Repositories\ChainRepository::getById(self::CHAIN);

        // Now the row changes out of band — no `updateRestUrl()`, so no cache bust.
        \BccEndpointAdminState::$chains[self::CHAIN]->rest_url = self::DIRTY;

        $html = $this->renderPanel();

        self::assertStringContainsString(
            'can no longer be confirmed',
            $html,
            'the panel must notice the change even with a warm cache'
        );
        self::assertStringNotContainsString('name="review_id"', $html);
        self::assertStringNotContainsString('sup3rsecret', $html);
    }

    /**
     * FIDELITY CONTROL. If the double were not sticky the test above would pass
     * against a repository in which the bug cannot occur.
     */
    public function testTheAdminDoubleCachesGetByIdButNotGetByIdUncached(): void
    {
        \BccEndpointAdminState::seedChain(self::CHAIN, self::APPROVED_INCUMBENT);
        $repo = \BCC\Trust\Onchain\Repositories\ChainRepository::class;

        self::assertSame(self::APPROVED_INCUMBENT, (string) $repo::getById(self::CHAIN)->rest_url);

        \BccEndpointAdminState::$chains[self::CHAIN]->rest_url = self::DIRTY;

        self::assertSame(
            self::APPROVED_INCUMBENT,
            (string) $repo::getById(self::CHAIN)->rest_url,
            'getById() must still answer from the cache'
        );
        self::assertSame(
            self::DIRTY,
            (string) $repo::getByIdUncached(self::CHAIN)->rest_url,
            'and getByIdUncached() must see the out-of-band write'
        );
    }
}
