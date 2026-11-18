<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\TestCase;

/**
 * F-310 (M1): wp-admin › User Reports › Penalize said "Report marked as
 * penalized" even when the penalty failed. The report was claimed first and
 * the penalty ran in an ACTION listener that caught and logged every
 * failure, so the panel could not know. Pinned in source:
 *
 *   - the handler asks a FILTER whether the penalty landed, defaulting to
 *     false (no listener = not applied), and on "no" reopens the claim and
 *     redirects with an error BEFORE the success redirect;
 *   - the listener answers false on every failure path: no self-page, no
 *     score row changed, and any throwable.
 */
final class PenalizeReportsOnlyAppliedPenaltiesTest extends TestCase
{
    public function testTheHandlerReopensAndReportsAFailedPenaltyBeforeAnySuccess(): void
    {
        $body = GateOrder::body(
            GateOrder::code('app/Domain/Disputes/Admin/DisputeAdmin.php'),
            'handle_report_penalize'
        );
        GateOrder::assertOrder(
            $body,
            ["updateReportStatus(\$report_id, 'penalized')", "'bcc.trust.admin_report_penalty_applied'"],
            'handle_report_penalize: claim, then ask'
        );
        // From the question on: a "no" reopens and errors BEFORE the success
        // redirect. (The earlier load-failure branch also reopens, so the
        // order is pinned on the part of the body after the filter call.)
        $afterAsk = substr($body, (int) strpos($body, "'bcc.trust.admin_report_penalty_applied'"));
        GateOrder::assertOrder(
            $afterAsk,
            ['if (!$applied)', "reopenReport(\$report_id, 'penalized')", "'penalty_failed'", "'report_updated' => 'penalized'"],
            'handle_report_penalize: a "no" is handled before success'
        );
        self::assertStringNotContainsString("do_action('bcc.trust.admin_report_penalty'", $body, 'a fire-and-forget action cannot say it failed');
        self::assertMatchesRegularExpression(
            "/apply_filters\(\s*'bcc\.trust\.admin_report_penalty_applied',\s*false,/",
            $body,
            'no listener must mean "not applied"'
        );
    }

    public function testTheListenerAnswersFalseOnEveryFailurePath(): void
    {
        $code  = GateOrder::code('bcc-trust.php');
        $start = strpos($code, "add_filter('bcc.trust.admin_report_penalty_applied'");
        self::assertIsInt($start, 'the penalty listener is a filter');
        $end   = strpos($code, '}, 10, 4);', $start);
        self::assertIsInt($end);
        $listener = substr($code, $start, $end - $start);

        self::assertStringContainsString('if ($pageId <= 0)', $listener);
        self::assertStringContainsString('if ($updated <= 0)', $listener);
        self::assertSame(3, substr_count($listener, 'return false;'), 'no self-page, no row changed, and any throwable');
        self::assertSame(1, substr_count($listener, 'return true;'));
    }

    public function testTheOldFireAndForgetActionIsGone(): void
    {
        self::assertStringNotContainsString(
            "add_action('bcc.trust.admin_report_penalty'",
            GateOrder::code('bcc-trust.php')
        );
    }
}
