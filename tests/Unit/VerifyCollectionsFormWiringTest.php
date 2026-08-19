<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Structural proof for the per-row VC-A form wiring.
 *
 * The Remove and Test CW-721 buttons live inside the big verification form
 * and reach their own forms through the HTML5 `form=` attribute, because
 * HTML forbids nested forms. That only works if every id is unique and each
 * button points at exactly one matching form — properties a substring check
 * cannot establish, so these assertions run against a real DOM parse.
 */
#[CoversClass(VerifyCollectionsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class VerifyCollectionsFormWiringTest extends TestCase
{
    /** @var list<int> */
    private const ROWS = [7, 8, 42];

    /**
     * Which rows are already hidden. Row 8 is, so its row must offer
     * Unhide and NOT Hide — exactly one direction per row.
     *
     * @var array<int, bool>
     */
    private const HIDDEN = [7 => false, 8 => true, 42 => false];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/verify-collections-stubs.php';
        \BccAdminTestState::reset();
    }

    /**
     * Compose the exact structure render_page() emits: the big form (which
     * still carries the VC-B shared nonce), the row buttons inside it, the
     * form closing, then the per-row VC-A forms.
     */
    private function renderComposite(): \DOMDocument
    {
        ob_start();
        echo '<div class="wrap">';
        echo '<form method="post" action="">';
        // VC-B still rides the broad page nonce — unchanged by this batch.
        wp_nonce_field(VerifyCollectionsPage::NONCE_KEY, VerifyCollectionsPage::NONCE_NAME);
        echo '<table><tbody>';
        foreach (self::ROWS as $i => $id) {
            echo '<tr><td>';
            VerifyCollectionsPage::renderRowActionButtons($id, $i === 0);
            // VC-B1: the real renderer, so this asserts the markup
            // production emits rather than a copy of it.
            VerifyCollectionsPage::renderHideButton($id, self::HIDDEN[$id]);
            // Representative REMAINING VC-B control (deferred to VC-B3),
            // still on the shared page nonce and still inside this form.
            echo '<button type="submit" name="bcc_vc_action" value="cw_pause_' . $id . '">Pause</button>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</form>';
        VerifyCollectionsPage::renderRowActionForms(self::ROWS, 2, 'cosmos', '', true, self::HIDDEN);
        echo '</div>';
        $html = (string) ob_get_clean();

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();

        return $doc;
    }

    /** @return list<\DOMElement> */
    private function elements(\DOMDocument $doc, string $tag): array
    {
        $out = [];
        foreach ($doc->getElementsByTagName($tag) as $el) {
            if ($el instanceof \DOMElement) {
                $out[] = $el;
            }
        }
        return $out;
    }

    private function formById(\DOMDocument $doc, string $id): ?\DOMElement
    {
        $found = null;
        foreach ($this->elements($doc, 'form') as $form) {
            if ($form->getAttribute('id') === $id) {
                // Uniqueness is asserted separately; return the first here.
                $found = $found ?? $form;
            }
        }
        return $found;
    }

    private function hiddenValue(\DOMElement $form, string $name): ?string
    {
        foreach ($form->getElementsByTagName('input') as $input) {
            if ($input instanceof \DOMElement && $input->getAttribute('name') === $name) {
                return $input->getAttribute('value');
            }
        }
        return null;
    }

    private function nonceAction(\DOMElement $form): ?string
    {
        foreach ($form->getElementsByTagName('input') as $input) {
            if ($input instanceof \DOMElement && $input->hasAttribute('data-nonce-action')) {
                return $input->getAttribute('data-nonce-action');
            }
        }
        return null;
    }

    // ── Uniqueness and pairing ──────────────────────────────────────────

    public function testEveryFormIdIsUniqueAcrossRows(): void
    {
        $doc = $this->renderComposite();

        $ids = [];
        foreach ($this->elements($doc, 'form') as $form) {
            $id = $form->getAttribute('id');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        $this->assertSame(
            count($ids),
            count(array_unique($ids)),
            'Duplicate form ids would cross-wire one row button to another row form.'
        );
        // 3 rows × (delete + testquery + exactly one hide-or-unhide) = 9.
        $this->assertCount(9, $ids);
    }

    // ── VC-B1: Hide / Unhide wiring ─────────────────────────────────────

    public function testEachRowOffersExactlyOneHideDirection(): void
    {
        $doc = $this->renderComposite();

        foreach (self::ROWS as $id) {
            $hide   = $this->formById($doc, 'vc-b-hide-' . $id);
            $unhide = $this->formById($doc, 'vc-b-unhide-' . $id);

            if (self::HIDDEN[$id]) {
                $this->assertNull($hide, "row {$id} is hidden — a live Hide form would be meaningless");
                $this->assertNotNull($unhide, "row {$id} is hidden — it must offer Unhide");
            } else {
                $this->assertNotNull($hide, "row {$id} is visible — it must offer Hide");
                $this->assertNull($unhide, "row {$id} is visible — a live Unhide form would be meaningless");
            }
        }
    }

    public function testHideAndUnhideUseDifferentRoutesAndTargetScopedNonces(): void
    {
        $doc = $this->renderComposite();

        foreach (self::ROWS as $id) {
            $isHidden = self::HIDDEN[$id];
            $route    = $isHidden ? VerifyCollectionsPage::ACTION_UNHIDE : VerifyCollectionsPage::ACTION_HIDE;
            $form     = $this->formById($doc, VerifyCollectionsPage::hideFormId($id, $isHidden));

            $this->assertNotNull($form);
            $this->assertSame('post', strtolower($form->getAttribute('method')));
            $this->assertStringContainsString('admin-post.php', $form->getAttribute('action'));
            $this->assertSame($route, $this->hiddenValue($form, 'action'));
            $this->assertSame((string) $id, $this->hiddenValue($form, 'collection_id'));

            // Bound to BOTH the direction and the collection.
            $this->assertSame(
                $route . '_' . $id,
                $this->nonceAction($form),
                'a Hide nonce must not authorise Unhide, nor another collection'
            );
        }

        // The two directions never share a nonce action.
        $this->assertNotSame(
            VerifyCollectionsPage::ACTION_HIDE,
            VerifyCollectionsPage::ACTION_UNHIDE
        );
    }

    public function testHideFormsDoNotCarryTheSharedPageNonce(): void
    {
        $doc = $this->renderComposite();

        foreach (self::ROWS as $id) {
            $form = $this->formById($doc, VerifyCollectionsPage::hideFormId($id, self::HIDDEN[$id]));
            $this->assertNotNull($form);

            $this->assertNull(
                $this->hiddenValue($form, VerifyCollectionsPage::NONCE_NAME),
                'a VC-B1 form must not carry the broad page nonce field'
            );
            $this->assertNotSame(
                VerifyCollectionsPage::NONCE_KEY,
                $this->nonceAction($form),
                'a VC-B1 form must not use the broad page nonce action'
            );
        }
    }

    public function testTheHideButtonConfirmationIsTruthful(): void
    {
        $doc = $this->renderComposite();

        foreach ($this->elements($doc, 'button') as $button) {
            $target = $button->getAttribute('form');
            if (!str_starts_with($target, 'vc-b-')) {
                continue;
            }

            $onclick = $button->getAttribute('onclick');
            $this->assertStringContainsString('confirm(', $onclick);

            // Decode the JSON literal so these assert the SENTENCE an
            // operator reads, not an escaped fragment of it.
            $this->assertSame(1, preg_match('/confirm\((.*)\);?$/s', $onclick, $m));
            $text = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
            $this->assertIsString($text, 'the confirmation must be a well-formed JSON string literal');

            if (str_starts_with($target, 'vc-b-hide-')) {
                // What it applies…
                $this->assertStringContainsString('platform-wide DENY rule', $text);
                $this->assertStringContainsString('contract', $text);
                // …and what that means for visibility AND discovery.
                $this->assertStringContainsString('every user-facing surface', $text);
                $this->assertStringContainsString('discovery will no longer treat it as eligible', $text);
                // …and that it is not the same thing as Remove.
                $this->assertStringContainsString('Reversible with Unhide', $text);
                $this->assertStringContainsString('not the same as Remove', $text);
            } else {
                $this->assertStringContainsString('platform-wide ALLOW rule', $text);
                $this->assertStringContainsString('lifting the deny decision', $text);

                // CONDITIONAL, not absolute. Lifting the deny rule makes
                // visibility POSSIBLE; it does not guarantee it, because
                // verification and the other eligibility conditions are
                // untouched — which the same sentence goes on to say. An
                // unconditional promise here would contradict the two
                // "does NOT" clauses two lines below it.
                $this->assertStringContainsString('may become visible again', $text);
                $this->assertStringContainsString('where it otherwise qualifies', $text);
                $this->assertStringContainsString('discovery may consider it eligible', $text);

                foreach ([
                    'becomes visible to users again',
                    'The collection becomes visible',
                    'users see it again',
                    'will be visible',
                    'will become eligible',
                    'discovery will treat it as eligible',
                ] as $overclaim) {
                    $this->assertStringNotContainsString(
                        $overclaim,
                        $text,
                        "Unhide must not promise \"{$overclaim}\" — it only lifts the deny rule."
                    );
                }

                // The two things Unhide explicitly does NOT do.
                $this->assertStringContainsString('does NOT verify the collection', $text);
                $this->assertStringContainsString('does NOT create or provision its community', $text);
            }

            // The scanner cache is downstream bookkeeping. Naming it here
            // would imply it is what enforces the decision; it is not.
            $this->assertStringNotContainsString('scanner', strtolower($text));
            $this->assertStringNotContainsString('cache', strtolower($text));

            // The hover title must hold the same line as the confirmation.
            $title = $button->getAttribute('title');
            if (str_starts_with($target, 'vc-b-unhide-')) {
                $this->assertStringContainsString('may become visible again', $title);
                $this->assertStringContainsString('where it otherwise qualifies', $title);
                $this->assertStringNotContainsString('users see it again', $title);
            }
        }
    }

    public function testTheDeferredCosmwasmControlsKeepTheSharedNonce(): void
    {
        $doc = $this->renderComposite();

        $pauses = 0;
        foreach ($this->elements($doc, 'button') as $button) {
            if ($button->getAttribute('name') === 'bcc_vc_action') {
                $pauses++;
                $this->assertSame('', $button->getAttribute('form'), 'VC-B3 controls stay inside the big form');
                $this->assertStringStartsWith('cw_', $button->getAttribute('value'));
            }
        }

        $this->assertSame(count(self::ROWS), $pauses);
    }

    public function testEveryButtonFormAttributeResolvesToExactlyOneForm(): void
    {
        $doc = $this->renderComposite();

        $referenced = 0;
        foreach ($this->elements($doc, 'button') as $button) {
            $target = $button->getAttribute('form');
            if ($target === '') {
                continue; // VC-B hide button — intentionally has none.
            }
            $referenced++;

            $matches = 0;
            foreach ($this->elements($doc, 'form') as $form) {
                if ($form->getAttribute('id') === $target) {
                    $matches++;
                }
            }
            $this->assertSame(1, $matches, "form=\"{$target}\" must resolve to exactly one form.");
        }

        // 3 Remove + 1 Test CW-721 (only row 7 is cosmos)
        // + 3 hide-or-unhide (VC-B1, exactly one direction per row).
        $this->assertSame(7, $referenced);
    }

    public function testButtonsAreWiredToTheirOwnRowNotAnother(): void
    {
        $doc = $this->renderComposite();

        foreach ($this->elements($doc, 'button') as $button) {
            $target = $button->getAttribute('form');
            if ($target === '') {
                continue;
            }
            $form = $this->formById($doc, $target);
            $this->assertNotNull($form);

            // The id encodes the row; the form's collection_id must agree.
            preg_match('/(\d+)$/', $target, $m);
            $this->assertSame(
                $m[1],
                $this->hiddenValue($form, 'collection_id'),
                "Button {$target} is wired to a form carrying a different collection_id."
            );
        }
    }

    // ── Form shape ──────────────────────────────────────────────────────

    public function testEachRowFormPostsToAdminPostWithTargetScopedNonce(): void
    {
        $doc = $this->renderComposite();

        foreach (self::ROWS as $id) {
            foreach ([
                'vc-a-del-'  => VerifyCollectionsPage::ACTION_DELETE,
                'vc-a-test-' => VerifyCollectionsPage::ACTION_TESTQUERY,
            ] as $prefix => $expectedAction) {
                $form = $this->formById($doc, $prefix . $id);
                $this->assertNotNull($form, "{$prefix}{$id} missing");

                $this->assertSame('post', strtolower($form->getAttribute('method')));
                $this->assertStringContainsString('admin-post.php', $form->getAttribute('action'));
                $this->assertSame($expectedAction, $this->hiddenValue($form, 'action'));
                $this->assertSame((string) $id, $this->hiddenValue($form, 'collection_id'));

                // Target-scoped: the nonce action carries THIS collection id.
                $this->assertSame(
                    $expectedAction . '_' . $id,
                    $this->nonceAction($form),
                    'Nonce must be bound to the operation AND the collection.'
                );
            }
        }
    }

    public function testNoNestedFormsAreIntroduced(): void
    {
        $doc = $this->renderComposite();

        foreach ($this->elements($doc, 'form') as $form) {
            $this->assertCount(
                0,
                $this->elements($doc, 'form') === [] ? [] : iterator_to_array($form->getElementsByTagName('form')),
                'A form must never contain another form.'
            );
        }
    }

    // ── VC-A / VC-B separation in the markup ────────────────────────────

    public function testVcaRowFormsDoNotCarryTheOldSharedNonce(): void
    {
        $doc = $this->renderComposite();

        foreach (self::ROWS as $id) {
            foreach (['vc-a-del-', 'vc-a-test-'] as $prefix) {
                $form = $this->formById($doc, $prefix . $id);
                $this->assertNotNull($form);

                $this->assertNull(
                    $this->hiddenValue($form, VerifyCollectionsPage::NONCE_NAME),
                    'VC-A forms must not carry the broad page nonce field.'
                );
                $this->assertNotSame(
                    VerifyCollectionsPage::NONCE_KEY,
                    $this->nonceAction($form),
                    'VC-A forms must not use the broad page nonce action.'
                );
            }
        }
    }

    public function testVcbControlsRetainTheSharedNonceAndAreUntouched(): void
    {
        $doc = $this->renderComposite();

        // The big form still carries the broad VC-B nonce.
        $big = null;
        foreach ($this->elements($doc, 'form') as $form) {
            if ($form->getAttribute('id') === '') {
                $big = $form;
                break;
            }
        }
        $this->assertNotNull($big, 'The big verification form should still exist.');
        $this->assertSame(
            VerifyCollectionsPage::NONCE_KEY,
            $this->nonceAction($big),
            'VC-B still rides the shared nonce — deferred, deliberately unchanged.'
        );

        // The DEFERRED CosmWasm controls still submit the legacy
        // bcc_vc_action with no form attr. Hide/Unhide no longer do —
        // VC-B1 moved them to their own admin-post routes.
        $legacy = 0;
        foreach ($this->elements($doc, 'button') as $button) {
            if ($button->getAttribute('name') === 'bcc_vc_action') {
                $legacy++;
                $this->assertSame('', $button->getAttribute('form'));
                $this->assertStringStartsWith('cw_', $button->getAttribute('value'));
                $this->assertStringNotContainsString('hide_', $button->getAttribute('value'));
            }
        }
        $this->assertSame(count(self::ROWS), $legacy);
    }

    public function testNonCosmosRowsGetNoTestButton(): void
    {
        $doc = $this->renderComposite();

        $testButtons = 0;
        foreach ($this->elements($doc, 'button') as $button) {
            if (str_starts_with($button->getAttribute('form'), 'vc-a-test-')) {
                $testButtons++;
            }
        }

        // Only the first row was rendered as cosmos.
        $this->assertSame(1, $testButtons);
    }
}
