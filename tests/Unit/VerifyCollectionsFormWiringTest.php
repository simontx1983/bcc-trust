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
            // Representative VC-B control, left untouched.
            echo '<button type="submit" name="bcc_vc_action" value="hide_' . $id . '">Hide</button>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</form>';
        VerifyCollectionsPage::renderRowActionForms(self::ROWS, 2, 'cosmos', '', true);
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
        // 3 rows × (delete + testquery) = 6 identified forms.
        $this->assertCount(6, $ids);
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

        // 3 Remove buttons + 1 Test CW-721 (only row 7 is cosmos).
        $this->assertSame(4, $referenced);
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

        // Hide buttons still submit the legacy bcc_vc_action, with no form attr.
        $hides = 0;
        foreach ($this->elements($doc, 'button') as $button) {
            if ($button->getAttribute('name') === 'bcc_vc_action') {
                $hides++;
                $this->assertSame('', $button->getAttribute('form'));
                $this->assertStringStartsWith('hide_', $button->getAttribute('value'));
            }
        }
        $this->assertSame(count(self::ROWS), $hides);
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
