<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use BCC\Trust\Onchain\Services\CollectionStateClassifier;
use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-313 (M10): every Verify Collections action round-trips the list's search
 * term. returnArgs() already read `s` back, but no form posted it, so each
 * row action and the bulk Save dropped the search while keeping `paged` —
 * landing the admin on page N of the UNSEARCHED list.
 */
#[CoversClass(VerifyCollectionsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class VerifyCollectionsSearchRoundTripTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/verify-collections-stubs.php';
        \BccAdminTestState::reset();
    }

    protected function tearDown(): void
    {
        unset($_GET['s']);
        parent::tearDown();
    }

    private static function renderRowForms(): string
    {
        ob_start();
        VerifyCollectionsPage::renderRowActionForms(
            [7, 8],
            2,
            'cosmos',
            '',
            CollectionStateClassifier::TAB_VERIFIED_WITH_COMMUNITY,
            [7 => false, 8 => true]
        );
        return (string) ob_get_clean();
    }

    public function testEveryRowFormCarriesTheSearchTerm(): void
    {
        $_GET['s'] = 'steel apes';
        $html = self::renderRowForms();

        $forms = substr_count($html, '<form');
        self::assertGreaterThan(0, $forms);
        self::assertSame(
            $forms,
            substr_count($html, '<input type="hidden" name="s" value="steel apes">'),
            'each row form posts the search back alongside paged/chain/vstate'
        );
    }

    public function testNoSearchMeansNoEmptySearchField(): void
    {
        $html = self::renderRowForms();

        self::assertStringNotContainsString('name="s"', $html, 'an empty s would still be a harmless no-op, but none is printed');
    }

    public function testTheSearchIsEscapedAndBounded(): void
    {
        $_GET['s'] = '"><script>x</script>' . str_repeat('a', 300);
        $html = self::renderRowForms();

        self::assertStringNotContainsString('<script>', $html);
        preg_match('/name="s" value="([^"]*)"/', $html, $m);
        self::assertNotEmpty($m);
        self::assertLessThanOrEqual(200 * 6, strlen($m[1]), 'bounded at 200 characters before escaping');
    }

    public function testTheBulkSaveFormCarriesTheSearchToo(): void
    {
        $body = GateOrder::body(GateOrder::code('app/Domain/Onchain/Admin/VerifyCollectionsPage.php'), 'render_page');
        self::assertStringContainsString('<input type="hidden" name="s" value="<?php echo esc_attr($searchTerm); ?>">', $body);
    }
}
