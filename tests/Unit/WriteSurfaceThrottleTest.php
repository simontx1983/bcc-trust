<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\MyPushSubscriptionEndpoint;
use BCC\Trust\Core\Services\AnnouncementCommentService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-133: two write surfaces had no throttle at all.
 *
 *  1. Announcement comments (AnnouncementCommentService::create): a spam
 *     and notification fan-out vector. Now 30 per 5 minutes per commenter,
 *     the same budget as direct messages (MessagesService::RATE_LIMIT_*).
 *  2. Push registration (MyPushSubscriptionEndpoint::register_): each call
 *     is a DNS resolution (SSRF gate) plus a row upsert, giving DNS-lookup
 *     amplification and row growth. Now 10 per hour per user (the frontend
 *     registers only on an explicit push-toggle gesture).
 *
 * Each throttle runs FIRST (throttle-before-credentials), so a denied
 * request reaches no lookup, DNS resolution or write.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WriteSurfaceThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/write-throttle-stubs.php';
        $GLOBALS['__bcc_wt'] = ['denied' => false, 'calls' => [], 'get_post' => 0];
    }

    private static function commentService(): AnnouncementCommentService
    {
        return (new ReflectionClass(AnnouncementCommentService::class))->newInstanceWithoutConstructor();
    }

    // ── 1. announcement comments ─────────────────────────────────────────

    public function testACommenterOverTheBudgetIsRefusedBeforeAnyLookup(): void
    {
        $GLOBALS['__bcc_wt']['denied'] = true;

        $result = self::commentService()->create(5, 7, 'hello', false);

        self::assertFalse($result['ok']);
        self::assertSame('bcc_rate_limited', $result['code']);
        self::assertSame(429, $result['status']);
        self::assertSame(0, $GLOBALS['__bcc_wt']['get_post'], 'no announcement lookup under throttle');
        self::assertSame(
            [['announcement_comment', 30, 300]],
            $GLOBALS['__bcc_wt']['calls'],
            'the same 30-per-5-minutes budget as direct messages'
        );
        self::assertSame(\BCC\Trust\Core\Services\MessagesService::RATE_LIMIT_PER_WINDOW, AnnouncementCommentService::RATE_LIMIT_PER_WINDOW);
        self::assertSame(\BCC\Trust\Core\Services\MessagesService::RATE_LIMIT_WINDOW_SECONDS, AnnouncementCommentService::RATE_LIMIT_WINDOW_SECONDS);
    }

    public function testACommenterWithinTheBudgetProceedsToTheLookup(): void
    {
        $result = self::commentService()->create(5, 7, 'hello', false);

        self::assertSame(1, $GLOBALS['__bcc_wt']['get_post'], 'past the throttle, the existence lookup runs');
        self::assertSame(404, $result['status'], 'and its existence-hiding 404 is unchanged');
    }

    // ── 2. push registration ─────────────────────────────────────────────

    public function testPushRegistrationOverTheBudgetIsRefusedBeforeAnyParsing(): void
    {
        $GLOBALS['__bcc_wt']['denied'] = true;
        $endpoint = (new ReflectionClass(MyPushSubscriptionEndpoint::class))->newInstanceWithoutConstructor();

        $response = $endpoint->register_(new \WP_REST_Request([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'keys'     => ['p256dh' => 'p', 'auth' => 'a'],
        ]));

        self::assertSame(429, $response->get_status());
        self::assertSame('bcc_rate_limited', $response->get_data()['error']['code'] ?? null);
        self::assertSame([['push_subscription_register', 10, 3600]], $GLOBALS['__bcc_wt']['calls']);
    }

    public function testPushRegistrationWithinTheBudgetIsNotRateLimited(): void
    {
        $endpoint = (new ReflectionClass(MyPushSubscriptionEndpoint::class))->newInstanceWithoutConstructor();

        // No VAPID key is configured in the unit environment, so the very
        // next gate answers. The point: it is NOT the throttle that answered.
        $response = $endpoint->register_(new \WP_REST_Request([]));

        self::assertNotSame(429, $response->get_status());
        self::assertSame([['push_subscription_register', 10, 3600]], $GLOBALS['__bcc_wt']['calls']);
    }
}
