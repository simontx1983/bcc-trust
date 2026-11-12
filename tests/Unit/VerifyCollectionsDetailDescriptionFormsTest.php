<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use BCC\Trust\Onchain\ValueObjects\ChainDescriptionState;
use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-309: the collection DETAIL view showed the description Approve / Reject
 * buttons, which submit by form="" id, but only the LIST page printed the
 * forms those ids name. On the detail view the confirm prompt appeared and
 * then nothing happened: the ids resolved to no form.
 *
 * Pinned: for a pending row, the buttons plus renderDescriptionReviewForms()
 * resolve every form="" to exactly one POST form (real DOM parse), and
 * render_detail() prints those forms right after the review block.
 */
#[CoversClass(VerifyCollectionsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class VerifyCollectionsDetailDescriptionFormsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/verify-collections-stubs.php';
        \BccAdminTestState::reset();
    }

    private static function pendingRow(int $id): object
    {
        return (object) [
            'id'                       => $id,
            'chain_description'        => 'Imported text to review.',
            'chain_description_state'  => ChainDescriptionState::PENDING,
            'chain_description_source' => 'metadata',
        ];
    }

    public function testTheReviewButtonsResolveToTheFormsPrintedForTheRow(): void
    {
        $row = self::pendingRow(77);
        ob_start();
        echo '<div>';
        VerifyCollectionsPage::renderDescriptionReview($row);
        VerifyCollectionsPage::renderDescriptionReviewForms([$row], 1, '', '', '');
        echo '</div>';
        $html = (string) ob_get_clean();

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $buttons = $xpath->query('//button[@form]');
        self::assertNotFalse($buttons);
        self::assertSame(2, $buttons->length, 'Approve and Reject');
        foreach ($buttons as $button) {
            self::assertInstanceOf(\DOMElement::class, $button);
            $id    = $button->getAttribute('form');
            $forms = $xpath->query('//form[@id="' . $id . '"][@method="post"]');
            self::assertNotFalse($forms);
            self::assertSame(1, $forms->length, "form=\"{$id}\" resolves to exactly one POST form");
        }
    }

    public function testRenderDetailPrintsTheFormsAfterTheReviewBlock(): void
    {
        $body = GateOrder::body(
            GateOrder::code('app/Domain/Onchain/Admin/VerifyCollectionsPage.php'),
            'render_detail'
        );
        GateOrder::assertOrder(
            $body,
            ['self::renderDescriptionReview($row)', 'self::renderDescriptionReviewForms('],
            'render_detail'
        );
    }
}
