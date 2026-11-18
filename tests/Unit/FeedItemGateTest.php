<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\FeedItemGate;
use BCC\Trust\Tests\Support\CoreSibling;
use BCC\Trust\Tests\Support\EngagementWriteRoutes;
use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-345 review: a review reply's feed_id ("feed_<act_id>", module 206) must
 * not take comments, reactions, stokes, helpful marks, a generic delete, view
 * recording or analytics. One shared rule (FeedItemGate) on bcc-core's
 * public-feed allowlist, the same one the feed, the permalink and the report
 * check enforce.
 *
 * Behaviour runs against the REAL bcc-core FeedItemNormalizer (the sibling
 * checkout), so "206 is off the allowlist" is bcc-core's own answer, not a
 * double's. The caller inventory below is what catches a gate that exists
 * and is not called (or is called and not honoured).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FeedItemGateTest extends TestCase
{
    private const REPLY_ACT  = 9206;
    private const STATUS_ACT = 9001;
    private const REVIEW_ACT = 9202;
    private const POLL_ACT   = 9030;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/feed-item-gate-stubs.php';
        require_once CoreSibling::require('src/Feed/FeedItemNormalizer.php');

        $GLOBALS['__bcc_fig_module_by_act'] = [
            self::REPLY_ACT  => '206',
            self::STATUS_ACT => '1',
            self::REVIEW_ACT => '202',
            self::POLL_ACT   => '30',
        ];
    }

    // ── behaviour ───────────────────────────────────────────────────────

    public function testAReviewReplyIsNotAFeedItemAndAnswersLikeAnUnknownPost(): void
    {
        $reply   = FeedItemGate::check(self::REPLY_ACT);
        $unknown = FeedItemGate::check(424242);

        self::assertNotNull($reply);
        self::assertSame(404, $reply->get_status());
        self::assertSame('bcc_not_found', $reply->get_data()['error']['code']);
        self::assertSame($unknown?->get_data(), $reply->get_data(), 'byte-identical to an unknown act id');
    }

    public function testOrdinaryFeedItemsPass(): void
    {
        self::assertNull(FeedItemGate::check(self::STATUS_ACT));
        self::assertNull(FeedItemGate::check(self::REVIEW_ACT));
    }

    public function testAnyOtherOffFeedModuleIsRefusedToo(): void
    {
        self::assertNotNull(FeedItemGate::check(self::POLL_ACT), 'an allowlist, not a 206 denylist');
    }

    public function testAnUnreadableModuleFailsClosed(): void
    {
        self::assertNotNull(FeedItemGate::check(0));
        self::assertNotNull(FeedItemGate::check(31337), 'no module row → refused, never admitted');
    }

    public function testThePredicateMatchesBccCoresAllowlist(): void
    {
        self::assertFalse(FeedItemGate::isFeedModule('206'));
        self::assertFalse(FeedItemGate::isFeedModule(206));
        self::assertFalse(FeedItemGate::isFeedModule(null));
        self::assertFalse(FeedItemGate::isFeedModule(''));
        self::assertFalse(FeedItemGate::isFeedModule('review_reply'));
        foreach (\BCC\Core\Feed\FeedItemNormalizer::publicFeedModuleIds() as $id) {
            self::assertTrue(FeedItemGate::isFeedModule((string) $id), "module {$id} is a feed item");
        }
    }

    // ── the callers (a gate nothing calls is a defect no behaviour test sees) ──

    public function testEveryFeedEngagementWritePathConsultsTheGateBeforeItWrites(): void
    {
        $checked = 0;
        foreach (EngagementWriteRoutes::HANDLERS as $h) {
            if ($h['file'] === 'CommentStokeEndpoint.php') {
                continue; // a comment target, judged by its own gate
            }
            $label = "{$h['file']}::{$h['method']}()";
            $body  = GateOrder::body(GateOrder::code(EngagementWriteRoutes::REST_DIR . $h['file']), $h['method']);

            GateOrder::assertOrder($body, ['FeedItemGate::check(', 'if ($notFeedItem !== null) {', 'return $notFeedItem;'], $label);
            GateOrder::assertNoWorkBefore($body, 'return $notFeedItem;', $h['writes'], $label);
            $checked++;
        }
        self::assertSame(5, $checked, 'reactions set/remove, stoke add/remove, helpful mark');
    }

    public function testTheServicePathsReadTheSamePredicate(): void
    {
        $expected = [
            // file => number of FeedItemGate::isFeedModule( call sites
            'CommentService.php'           => 2, // list + create
            'PostsService.php'             => 1, // DELETE /feed/{feed_id}
            'PostViewRecorder.php'         => 2, // record + recordProfileVisit
            'PostAnalyticsService.php'     => 1, // GET /me/posts/{id}/analytics
            'FeedItemReportVisibility.php' => 1, // the report check: one rule, not a copy
        ];
        foreach ($expected as $file => $want) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Services/' . $file);
            self::assertStringContainsString('class ', $src, $file);
            self::assertSame($want, substr_count($src, 'FeedItemGate::isFeedModule('), $file);
        }
        $visibility = GateOrder::code('app/Domain/Core/Services/FeedItemReportVisibility.php');
        self::assertStringNotContainsString('publicFeedModuleIds()', $visibility, 'the report check must not keep a second copy of the rule');
    }
}
