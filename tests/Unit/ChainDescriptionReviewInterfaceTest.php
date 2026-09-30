<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use BCC\Trust\Onchain\ValueObjects\ChainDescriptionState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * DECISION 17 says the admin review interface IS the reader. This proves one exists.
 *
 * ── ⚠⚠⚠ THE DEFECT THIS EXISTS TO CLOSE ─────────────────────────────────
 * PR E added `ACTION_DESC_APPROVE` / `ACTION_DESC_REJECT`, registered them,
 * and wrote a careful handler with a per-row nonce, a compare-and-swap from
 * `pending` and a checked audit inside one transaction. **Nothing rendered
 * them.** Searching production code found those constants only in the
 * constant block, the `add_action` registrations and the handlers themselves:
 * no HTML carried the action, no nonce was minted, and no screen displayed
 * the pending text or its source.
 *
 * So the description could be imported and could never be reviewed, and the
 * claim "the admin review interface is the reader" was FALSE — the field was
 * unreadable by anyone, which is a different thing from unpublished.
 *
 * ── WHAT THIS IS NOT ────────────────────────────────────────────────────
 * Still no public reader. DECISION 17 is explicit that during scanner
 * retirement the text gets no public REST field and no view-model field, and
 * that "a public reader is not to be built merely to avoid the field looking
 * unused". This screen is the ONLY reader, and it is capability-gated.
 *
 * It is also never the Community Description: that is the PeepSo community
 * biography, written by community managers. Approving collection text here
 * must not touch it.
 */
#[CoversClass(VerifyCollectionsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ChainDescriptionReviewInterfaceTest extends TestCase
{
    private const HUB_CHAIN = 8;
    private const CONTRACT  = 'cosmos1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqzz';

    private const PENDING_ID  = 201;
    private const APPROVED_ID = 202;
    private const REJECTED_ID = 203;
    private const NONE_ID     = 204;

    private const PENDING_TEXT = 'A collection of hand-drawn goats living on chain.';
    private const PENDING_SRC  = 'cw721_contract_info';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/verify-collections-render-isolation-stubs.php';

        \BccRenderWorld::reset();
        $_GET     = [];
        $_POST    = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->seedWorld();
    }

    private function seedWorld(): void
    {
        \BccRenderWorld::$chains = [
            (object) [
                'id'                             => self::HUB_CHAIN,
                'slug'                           => 'cosmos',
                'name'                           => 'Cosmos Hub',
                'chain_type'                     => 'cosmos',
                'is_active'                      => 1,
                'rest_url'                       => 'https://cosmos-api.polkachu.com',
                'bcc_supports_nft_collections'   => '1',
                'cosmwasm_nft_discovery_enabled' => '1',
            ],
        ];

        \BccRenderWorld::$rows = [
            $this->row(self::PENDING_ID, 'Pending Goats', ChainDescriptionState::PENDING, self::PENDING_TEXT),
            $this->row(self::APPROVED_ID, 'Approved Goats', ChainDescriptionState::APPROVED, 'Approved text.'),
            $this->row(self::REJECTED_ID, 'Rejected Goats', ChainDescriptionState::REJECTED, 'Rejected text.'),
            $this->row(self::NONE_ID, 'Plain Goats', ChainDescriptionState::NONE, null),
        ];
    }

    private function row(int $id, string $name, string $state, ?string $description): object
    {
        return (object) [
            'id'                        => $id,
            'contract_address'          => self::CONTRACT,
            'canonical_identifier'      => self::CONTRACT,
            'collection_name'           => $name,
            'image_url'                 => null,
            'token_standard'            => 'CW-721',
            'chain_id'                  => self::HUB_CHAIN,
            'chain_slug'                => 'cosmos',
            'chain_type'                => 'cosmos',
            'is_verified'               => 0,
            'is_hidden'                 => 0,
            'source'                    => 'discovery',
            'unique_holders'            => null,
            'has_community'             => 0,
            'provisioning_state'        => 'none',
            'provisioning_failure_code' => null,
            // The three columns the review interface reads.
            'chain_description'         => $description,
            'chain_description_state'   => $state,
            'chain_description_source'  => $description === null ? null : self::PENDING_SRC,
        ];
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

    // ═══════════════════════════════════════════════════════════════════
    //  1. A PENDING DESCRIPTION IS ACTUALLY REVIEWABLE
    // ═══════════════════════════════════════════════════════════════════

    /** ⚠⚠ THE HEADLINE CASE: both actions reach the HTML for a pending row. */
    public function testAPendingDescriptionRendersBothTransitionActions(): void
    {
        $html = $this->render();

        self::assertStringContainsString(
            VerifyCollectionsPage::ACTION_DESC_APPROVE,
            $html,
            'the approve route must appear in rendered HTML, not only in the handler'
        );
        self::assertStringContainsString(
            VerifyCollectionsPage::ACTION_DESC_REJECT,
            $html,
            'the reject route must appear in rendered HTML, not only in the handler'
        );
    }

    /** The text itself is shown — otherwise there is nothing to review. */
    public function testThePendingTextAndItsSourceAreShown(): void
    {
        $html = $this->render();

        self::assertStringContainsString(self::PENDING_TEXT, $html);
        self::assertStringContainsString(self::PENDING_SRC, $html);
    }

    /** The current review state is visible for every row that has text. */
    public function testEachReviewStateIsLabelled(): void
    {
        $html = $this->render();

        foreach (['Pending review', 'Approved', 'Rejected'] as $label) {
            self::assertStringContainsString($label, $html, "the '{$label}' state must be legible");
        }
    }

    /**
     * ⚠⚠⚠ THE NONCE IS BOUND TO ACTION **AND** ID. The handler verifies
     * `$route . '_' . $collectionId`, so a nonce minted for one collection
     * must not approve another's text. A single shared nonce, or one bound to
     * the route alone, would make the per-row binding decorative.
     */
    public function testEachActionCarriesANonceBoundToTheRouteAndTheId(): void
    {
        $html = $this->render();

        $approve = \wp_create_nonce(VerifyCollectionsPage::ACTION_DESC_APPROVE . '_' . self::PENDING_ID);
        $reject  = \wp_create_nonce(VerifyCollectionsPage::ACTION_DESC_REJECT . '_' . self::PENDING_ID);

        self::assertNotSame($approve, $reject, 'the fixture nonce must discriminate by route');
        self::assertStringContainsString($approve, $html, 'the approve nonce must be bound to route+id');
        self::assertStringContainsString($reject, $html, 'the reject nonce must be bound to route+id');
    }

    /** A nonce minted for a DIFFERENT collection must not appear. */
    public function testNoNonceForAnotherCollectionIsRendered(): void
    {
        $html = $this->render();

        $foreign = \wp_create_nonce(VerifyCollectionsPage::ACTION_DESC_APPROVE . '_' . self::NONE_ID);

        self::assertStringNotContainsString(
            $foreign,
            $html,
            'a row with nothing pending must not have a usable approve nonce'
        );
    }

    /** The form posts the collection id the nonce is bound to. */
    public function testTheFormCarriesTheCollectionId(): void
    {
        $html = $this->render();

        self::assertMatchesRegularExpression(
            '/name="collection_id"\s+value="' . self::PENDING_ID . '"/',
            $html,
            'the posted id must be the reviewed row'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. A DECIDED DESCRIPTION IS READABLE BUT NOT RE-DECIDABLE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠ Same doctrine as Hide/Unhide and community intent: exactly the
     * transitions that are actually available get rendered. `approved` and
     * `rejected` are terminal — the handler's compare-and-swap is FROM
     * `pending` only, so a control on a decided row would be a button that
     * can never work, and an operator clicking it would get a warning notice
     * for an action the page implied was available.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function decidedRows(): array
    {
        return [
            'approved' => [self::APPROVED_ID, 'Approved text.'],
            'rejected' => [self::REJECTED_ID, 'Rejected text.'],
        ];
    }

    #[DataProvider('decidedRows')]
    public function testADecidedDescriptionStaysVisible(int $id, string $text): void
    {
        $html = $this->render();

        self::assertStringContainsString($text, $html, 'the administrator keeps the text for reference');
    }

    /**
     * ⚠⚠ ASSERTED ON THE BUTTON AS WELL AS THE FORM.
     *
     * A mutation control caught this: checking only for the hidden form's
     * nonce let a version through that rendered Approve/Reject BUTTONS on a
     * decided row whose `form=` target did not exist. The nonce was absent, so
     * the test passed — but the operator saw two live-looking buttons that
     * silently did nothing. A false affordance is a defect, so both halves of
     * the control are pinned.
     */
    #[DataProvider('decidedRows')]
    public function testADecidedDescriptionOffersNoRepeatTransition(int $id, string $text): void
    {
        $html = $this->render();

        foreach (
            [
                VerifyCollectionsPage::ACTION_DESC_APPROVE,
                VerifyCollectionsPage::ACTION_DESC_REJECT,
            ] as $route
        ) {
            self::assertStringNotContainsString(
                \wp_create_nonce($route . '_' . $id),
                $html,
                'a terminal state gets no submittable form'
            );
        }

        // And no button pointing at one either.
        foreach (['vc-desc-ok-' . $id, 'vc-desc-no-' . $id] as $formId) {
            self::assertStringNotContainsString(
                'form="' . $formId . '"',
                $html,
                'a terminal state gets no button — not even one wired to nothing'
            );
        }
    }

    /** The control IS present on a pending row — so the assertion above bites. */
    public function testThePendingRowDoesRenderBothButtons(): void
    {
        $html = $this->render();

        self::assertStringContainsString('form="vc-desc-ok-' . self::PENDING_ID . '"', $html);
        self::assertStringContainsString('form="vc-desc-no-' . self::PENDING_ID . '"', $html);
    }

    /** A row with no description at all renders no review block. */
    public function testARowWithNoDescriptionRendersNoReviewControl(): void
    {
        $html = $this->render();

        self::assertStringNotContainsString(
            \wp_create_nonce(VerifyCollectionsPage::ACTION_DESC_APPROVE . '_' . self::NONE_ID),
            $html
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. THE TEXT IS UNTRUSTED EVIDENCE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠⚠⚠ ESCAPED AS PLAIN TEXT. The description is imported from contract
     * metadata a contract author controls. It is the single most
     * attacker-controlled string on this screen, and it is rendered next to
     * live POST forms holding valid nonces — so an injected `<script>` would
     * be running in a page that can approve descriptions.
     */
    public function testTheDescriptionIsEscapedAsPlainText(): void
    {
        \BccRenderWorld::$rows = [
            $this->row(
                self::PENDING_ID,
                'Hostile Goats',
                ChainDescriptionState::PENDING,
                '<script>alert(1)</script><img src=x onerror=alert(2)>'
            ),
        ];

        $html = $this->render();

        // ⚠ Assert on the TAG, not on the attribute text. `esc_html()` leaves
        // `onerror=alert(2)` present as inert characters inside an encoded
        // entity string — what must never appear is an actual element the
        // browser will parse, so that is what is asserted.
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html, 'it is shown, entity-encoded');
        self::assertStringContainsString('&lt;img src=x', $html, 'and so is the image tag');
    }

    /** A hostile SOURCE string is escaped too — it is provider text as well. */
    public function testTheSourceLabelIsEscaped(): void
    {
        $row = $this->row(self::PENDING_ID, 'Goats', ChainDescriptionState::PENDING, 'Fine text.');
        $row->chain_description_source = '<script>alert(3)</script>';
        \BccRenderWorld::$rows = [$row];

        $html = $this->render();

        self::assertStringNotContainsString('<script>alert(3)</script>', $html);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  4. IT IS NOT THE COMMUNITY DESCRIPTION, AND NOT PUBLIC
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠ Rendering the review screen must not write a PeepSo group description
     * or any community field. The two descriptions are separate by decision,
     * and this screen is the one place both are in scope at once.
     */
    public function testRenderingTheReviewScreenWritesNoCommunityDescription(): void
    {
        $html = $this->render();

        // Anti-vacuity: prove the review block actually rendered, so an empty
        // write list means "it wrote nothing" and not "it did nothing".
        self::assertStringContainsString(VerifyCollectionsPage::ACTION_DESC_APPROVE, $html);

        foreach (\BccRenderWorld::$writes as $write) {
            foreach (['group', 'community', 'peepso', 'about'] as $forbidden) {
                self::assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $write,
                    'reviewing collection text never touches a community biography'
                );
            }
        }
    }

    /** And it sends nothing anywhere — a review screen is a read. */
    public function testRenderingTheReviewScreenContactsNoProvider(): void
    {
        $this->render();

        self::assertSame([], \BccRenderWorld::$http, 'rendering the review screen makes no request');
    }

    /**
     * No REST route, controller or DTO carries the description to a client.
     *
     * ── WHAT THIS COVERS, EXACTLY ───────────────────────────────────────
     * The outward-facing serialization layers of both domains: `REST/` (route
     * handlers and their payloads), `Controllers/` and `Core/DTO/` (the
     * view-model shapes). Those are the places a field becomes visible to a
     * client, and the failure mode this guards is somebody adding one later
     * rather than this render leaking it.
     *
     * ⚠ An earlier version scanned only the two `REST/` directories while its
     * name and message claimed view-model coverage as well. The name now says
     * what is scanned, and the scan now covers what the name says.
     *
     * ⚠⚠ THIS IS NOT THE AUTHORITATIVE NO-CONSUMER PROOF. That is
     * `PrEFailClosedBoundariesTest::…NoPublicConsumer…`, which token-walks the
     * WHOLE of `app/` for callers of
     * `CollectionRepository::findApprovedChainDescription()` with comments
     * stripped. This one is narrower and complementary: it catches the column
     * being read DIRECTLY off a row and serialized, which bypasses that
     * accessor entirely and so would not appear in a caller inventory.
     */
    public function testNoRestControllerOrDtoSerialisesTheDescription(): void
    {
        $roots = [
            __DIR__ . '/../../app/Domain/Onchain/REST',
            __DIR__ . '/../../app/Domain/Onchain/Controllers',
            __DIR__ . '/../../app/Domain/Core/REST',
            __DIR__ . '/../../app/Domain/Core/Controllers',
            __DIR__ . '/../../app/Domain/Core/DTO',
        ];

        $scanned = 0;
        $hits    = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $f) {
                if (!$f instanceof \SplFileInfo || $f->getExtension() !== 'php') {
                    continue;
                }
                $scanned++;
                $src = (string) file_get_contents($f->getPathname());
                if (
                    str_contains($src, 'chain_description')
                    || str_contains($src, 'findApprovedChainDescription')
                ) {
                    $hits[] = $f->getPathname();
                }
            }
        }

        // ⚠ Anti-vacuity: a typo'd path list would scan nothing and pass.
        self::assertGreaterThan(20, $scanned, 'the scan must actually reach the public layers');

        self::assertSame(
            [],
            $hits,
            'DECISION 17: no REST route, controller or DTO exposes the collection description'
        );
    }
}
