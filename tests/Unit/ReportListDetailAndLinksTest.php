<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit {

    use BCC\Trust\Disputes\Admin\ReportListTable;
    use BCC\Trust\Disputes\DTO\AdminReportRowDTO;
    use BCC\Trust\Tests\Support\GateOrder;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-311 (M14, M5).
     *
     * M14: wp-admin › User Reports cut the member's description to 80
     * characters (up to 1000 are accepted, and the full text lived only in
     * the admin email), and showed both people as plain "name (#id)". Long
     * details now expand in place, and both names link to the member's
     * wp-admin moderation page.
     *
     * M5 (backend half): the report throttle ran FIRST, so a refusal the
     * member could fix still burned the 60 s window.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class ReportListDetailAndLinksTest extends TestCase
    {
        protected function setUp(): void
        {
            // Child process only (see the stub file's header).
            require_once dirname(__DIR__) . '/Stubs/report-list-render-stubs.php';
        }

        private static function row(string $detail): AdminReportRowDTO
        {
            if (!class_exists(\BCC\Core\DTO\DTOAssert::class)) {
                require_once \BCC\Trust\Tests\Support\CoreSibling::require('src/DTO/DTOAssert.php');
            }
            return new AdminReportRowDTO(41, 7, 8, 'other', $detail, 'open', '2026-11-10 00:00:00', null, 'Reported <b>', 'Reporter');
        }

        public function testALongDetailIsFullyReadableAndStillEscaped(): void
        {
            $full = str_repeat('a', 90) . ' <script>x</script> the rest of the description';
            $html = (string) (new ReportListTable())->column_default(self::row($full), 'reason_detail');

            self::assertStringContainsString('<details>', $html);
            self::assertStringContainsString('the rest of the description', $html, 'the full text is on the page');
            self::assertStringNotContainsString('<script>', $html);
        }

        public function testAShortDetailIsShownAsIs(): void
        {
            $html = (string) (new ReportListTable())->column_default(self::row('short reason'), 'reason_detail');

            self::assertSame('short reason', $html);
        }

        public function testBothPeopleLinkToTheirModerationPage(): void
        {
            $table = new ReportListTable();
            $reported = (string) $table->column_default(self::row('x'), 'reported_name');
            $reporter = (string) $table->column_default(self::row('x'), 'reporter_name');

            self::assertStringContainsString('page=bcc-trust-moderation&amp;user_id=7', $reported);
            self::assertStringContainsString('page=bcc-trust-moderation&amp;user_id=8', $reporter);
            self::assertStringContainsString('Reported &lt;b&gt;', $reported, 'the name stays escaped');
        }

        public function testTheThrottleRunsOnlyAfterTheFixableRefusals(): void
        {
            $body = GateOrder::body(
                GateOrder::code('app/Domain/Disputes/Controllers/DisputeController.php'),
                'report_user'
            );
            GateOrder::assertOrder(
                $body,
                ["'cannot_self_report'", "'detail_required'", "'already_reported'", "\$this->throttle('report_user'", 'UserReportRepository::createReport('],
                'report_user'
            );
        }

        /**
         * #669 review: user_not_found says whether an account id exists, so it
         * sits BEHIND the per-minute throttle; ahead of it, anyone signed in
         * could probe user ids at full speed.
         */
        public function testTheUserLookupIsBehindTheThrottle(): void
        {
            $body = GateOrder::body(
                GateOrder::code('app/Domain/Disputes/Controllers/DisputeController.php'),
                'report_user'
            );
            GateOrder::assertOrder(
                $body,
                ["\$this->throttle('report_user'", 'get_userdata( $reported_id )', "'user_not_found'"],
                'report_user'
            );
        }
    }
}
