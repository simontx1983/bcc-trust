<?php

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }
    if (!class_exists('WP_List_Table')) {
        class WP_List_Table
        {
            /** @param array<string, mixed> $args */
            public function __construct(array $args = [])
            {
            }
        }
    }
    foreach ([
        'esc_attr', 'esc_html', 'esc_url', 'esc_js', 'esc_html__', '__',
    ] as $fn) {
        if (!function_exists($fn)) {
            eval('function ' . $fn . '(string $t, string $d = ""): string { return htmlspecialchars($t, ENT_QUOTES); }');
        }
    }
    if (!function_exists('admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://example.test/wp-admin/' . $path;
        }
    }
    if (!function_exists('add_query_arg')) {
        /** @param array<string, string> $args */
        function add_query_arg(array $args, string $url): string
        {
            return $url . '?' . http_build_query($args);
        }
    }
    if (!function_exists('sanitize_key')) {
        function sanitize_key(string $key): string
        {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
        }
    }
    if (!function_exists('absint')) {
        function absint($n): int
        {
            return abs((int) $n);
        }
    }
    if (!function_exists('wp_create_nonce')) {
        function wp_create_nonce(string $action = '-1'): string
        {
            return 'nonce-' . md5($action);
        }
    }
    if (!function_exists('wp_nonce_field')) {
        function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
        {
            return '<input type="hidden" name="' . $name . '" value="nonce-' . md5($action) . '">';
        }
    }
}

namespace BCC\Trust\Tests\Unit {

    use BCC\Trust\Disputes\Admin\ReportListTable;
    use BCC\Trust\Disputes\DTO\AdminReportRowDTO;
    use BCC\Trust\Tests\Support\GateOrder;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-308: wp-admin ▸ User Reports renders its table inside an outer
     * <form method="get">. Each row used to print its own <form method="post">
     * there. The HTML parser drops a form nested in a form, and the dropped
     * form's </form> closes the outer one, so the FIRST row's Reviewed button
     * submitted the GET filter form: no confirm, no action, report still open.
     *
     * Pinned: the row cell holds NO <form>; every control it renders is bound
     * by form="" to a POST form printed by renderRowForms(); and the page
     * prints those forms after the outer form closes.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class ReportRowFormsOutsideOuterFormTest extends TestCase
    {
        private static function openRow(int $id): AdminReportRowDTO
        {
            // The row DTO validates through bcc-core's DTOAssert.
            if (!class_exists(\BCC\Core\DTO\DTOAssert::class)) {
                require_once \BCC\Trust\Tests\Support\CoreSibling::require('src/DTO/DTOAssert.php');
            }
            return new AdminReportRowDTO(
                $id,
                7,
                8,
                'spam',
                'detail',
                'open',
                '2026-11-10 00:00:00',
                null,
                'Reported',
                'Reporter'
            );
        }

        public function testTheRowCellHoldsNoFormAndEveryControlPointsAtARowForm(): void
        {
            $table = new ReportListTable();
            $cells = (string) $table->column_default(self::openRow(41), 'actions')
                . (string) $table->column_default(self::openRow(42), 'actions');

            self::assertStringNotContainsStringIgnoringCase('<form', $cells, 'a form nested in the outer GET form is dropped by the parser');

            ob_start();
            $table->renderRowForms();
            $forms = (string) ob_get_clean();

            preg_match_all('/form="([^"]+)"/', $cells, $refs);
            preg_match_all('/<form id="([^"]+)" method="post"/', $forms, $ids);
            self::assertNotEmpty($refs[1]);
            foreach (array_unique($refs[1]) as $ref) {
                self::assertContains($ref, $ids[1], "control bound to {$ref} has a POST form to submit");
            }
            self::assertCount(6, $ids[1], 'reviewed, dismissed and penalize for each of the two rows');

            // Every button in the cell submits a row form; none is left to submit the outer form.
            preg_match_all('/<button\b[^>]*>/', $cells, $buttons);
            self::assertCount(6, $buttons[0]);
            foreach ($buttons[0] as $button) {
                self::assertMatchesRegularExpression('/\bform="bcc-report-/', $button);
            }
        }

        public function testEachRowFormCarriesItsActionNonceAndConfirm(): void
        {
            $table = new ReportListTable();
            $table->column_default(self::openRow(41), 'actions');
            ob_start();
            $table->renderRowForms();
            $forms = (string) ob_get_clean();

            foreach (['reviewed' => 'bcc_report_action_41', 'dismissed' => 'bcc_report_action_41', 'penalize' => 'bcc_report_penalize_41'] as $action => $nonceAction) {
                self::assertMatchesRegularExpression(
                    '/<form id="bcc-report-' . $action . '-41" method="post"[^>]*onsubmit="return confirm\(/',
                    $forms,
                    "{$action} keeps its confirm prompt"
                );
                $start = strpos($forms, 'id="bcc-report-' . $action . '-41"');
                self::assertIsInt($start);
                $one = substr($forms, $start, (int) strpos($forms, '</form>', $start) - $start);
                self::assertStringContainsString('name="report_action" value="' . $action . '"', $one);
                self::assertStringContainsString('name="report_id" value="41"', $one);
                self::assertStringContainsString('nonce-' . md5($nonceAction), $one);
                self::assertStringContainsString('name="page" value="bcc-reports"', $one);
            }

            ob_start();
            $table->renderRowForms();
            self::assertSame('', (string) ob_get_clean(), 'forms print once');
        }

        /**
         * F-312 (M2): each row form posts the list's status filter and page
         * back, so the handler's redirect lands on the same view rather than
         * "All", page 1.
         */
        public function testEveryRowFormCarriesTheListFilterAndPage(): void
        {
            $_GET['report_status'] = 'open';
            $_GET['paged']         = '3';
            $table = new ReportListTable();
            $table->column_default(self::openRow(41), 'actions');
            ob_start();
            $table->renderRowForms();
            $forms = (string) ob_get_clean();
            unset($_GET['report_status'], $_GET['paged']);

            self::assertSame(3, substr_count($forms, '<input type="hidden" name="report_status" value="open">'));
            self::assertSame(3, substr_count($forms, '<input type="hidden" name="paged" value="3">'));
        }

        public function testTheHandlersRedirectBackToTheSameFilterAndPage(): void
        {
            $_POST['report_status'] = 'open';
            $_POST['paged']         = '3';
            $url = (new \ReflectionMethod(\BCC\Trust\Disputes\Admin\DisputeAdmin::class, 'reportsReturnUrl'))
                ->invoke(null, ['error' => 'already_resolved']);
            unset($_POST['report_status'], $_POST['paged']);

            parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $q);
            self::assertSame(
                ['page' => 'bcc-reports', 'report_status' => 'open', 'paged' => '3', 'error' => 'already_resolved'],
                $q
            );
        }

        /** The refusals the handlers redirect with are rendered, not dropped. */
        public function testTheReportsPageRendersTheErrorTheHandlersSend(): void
        {
            $body = GateOrder::body(GateOrder::code('app/Domain/Disputes/Admin/DisputeAdmin.php'), 'render_reports_page');
            self::assertStringContainsString("\$_GET['error']", $body);
            self::assertStringContainsString("'already_resolved'", $body);
            self::assertStringContainsString("'invalid_penalty'", $body);
        }

        public function testThePagePrintsTheRowFormsAfterTheOuterFormCloses(): void
        {
            $body = GateOrder::body(GateOrder::code('app/Domain/Disputes/Admin/DisputeAdmin.php'), 'render_reports_page');
            GateOrder::assertOrder(
                $body,
                ["echo '<form method=\"get\">'", '$table->display()', "echo '</form>'", '$table->renderRowForms()'],
                'render_reports_page'
            );
        }
    }
}
