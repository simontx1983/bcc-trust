<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\CelebrationsEndpoint;
use BCC\Trust\Core\REST\HallsEndpoint;
use BCC\Trust\Core\REST\HighlightsEndpoint;
use BCC\Trust\Core\REST\MessagesEndpoint;
use BCC\Trust\Core\REST\MyMessagesPrefsEndpoint;
use BCC\Trust\Core\REST\MyNotificationPrefsEndpoint;
use BCC\Trust\Core\REST\MyPrivacyEndpoint;
use BCC\Trust\Core\REST\MyProfileEndpoint;
use BCC\Trust\Core\REST\MyProfileFieldsEndpoint;
use BCC\Trust\Core\REST\MyProfilePrefsEndpoint;
use BCC\Trust\Core\REST\MyPushSubscriptionEndpoint;
use BCC\Trust\Core\REST\NotificationsEndpoint;
use BCC\Trust\Core\REST\OnboardingEndpoint;
use BCC\Trust\Core\REST\PostsEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-134 — cheap self-scoped write routes are rate limited per ACCOUNT.
 *
 * Eighteen /me write routes had no rate limit at all. Each now takes the
 * shared 'me_write' bucket (ME_WRITE_RATE_LIMIT / 60 s) keyed EXPLICITLY on
 * the signed-in account — right after the sign-in check, before any work.
 * PATCH /me/handle also takes 'me_handle' (HANDLE_CHANGE_RATE_LIMIT / hour).
 * Pinned against the REAL handlers (suspension-gate stubs: user 7, a
 * recording Throttle):
 *   - a denied bucket answers 429 bcc_rate_limited, before any other work;
 *   - the bucket, limit, window and per-account key are exactly as stated.
 * Not covered here, by design: avatar/cover uploads + deletes (F-58) and
 * POST /me/reports (ContentReportService already throttles it).
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/suspension-gate-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SelfWriteRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/suspension-gate-stubs.php';
        $GLOBALS['__bcc_susp_fixture'] = [
            'user_id'        => 7,
            'suspended'      => false,
            'user_meta'      => [],
            'bypass_args'    => [],
            'throttled'      => true,
            'throttle_calls' => [],
        ];
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function routes(): iterable
    {
        yield 'POST /me/celebrations/consume'             => [CelebrationsEndpoint::class, 'consume'];
        yield 'POST /me/conversations/{id}/read'           => [MessagesEndpoint::class, 'markRead'];
        yield 'POST /me/halls/{id}/primary'                => [HallsEndpoint::class, 'setPrimary'];
        yield 'DELETE /me/halls/primary'                   => [HallsEndpoint::class, 'clearPrimary'];
        yield 'PATCH /me/handle'                           => [OnboardingEndpoint::class, 'updateHandle'];
        yield 'POST /me/highlights/{id}'                   => [HighlightsEndpoint::class, 'dismiss'];
        yield 'PATCH /me/messages-prefs'                   => [MyMessagesPrefsEndpoint::class, 'patch'];
        yield 'PATCH /me/notification-prefs'               => [MyNotificationPrefsEndpoint::class, 'patch'];
        yield 'POST /me/notifications/mark-read'           => [NotificationsEndpoint::class, 'markRead'];
        yield 'POST /me/onboarding/complete'               => [OnboardingEndpoint::class, 'completeOnboarding'];
        yield 'PATCH /me/privacy'                          => [MyPrivacyEndpoint::class, 'patch'];
        yield 'PATCH /me/profile'                          => [MyProfileEndpoint::class, 'patch'];
        yield 'PATCH /me/profile-prefs'                    => [MyProfilePrefsEndpoint::class, 'patch'];
        yield 'PATCH /me/profile/cover/position'           => [MyProfileEndpoint::class, 'patchCoverPosition'];
        yield 'PATCH /me/profile/fields/{key}'             => [MyProfileFieldsEndpoint::class, 'patchValue'];
        yield 'PATCH /me/profile/fields/{key}/visibility'  => [MyProfileFieldsEndpoint::class, 'patchVisibility'];
        yield 'DELETE /me/push-subscriptions/{id}'         => [MyPushSubscriptionEndpoint::class, 'revoke'];
        yield 'DELETE /me/reviews/{id}'                    => [PostsEndpoint::class, 'removeReview'];
    }

    /** @param class-string $class */
    #[DataProvider('routes')]
    public function testADeniedBucketAnswers429BeforeAnyWork(string $class, string $method): void
    {
        $endpoint = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        $res = $endpoint->{$method}(new \WP_REST_Request());

        self::assertSame(429, $res->get_status());
        self::assertSame('bcc_rate_limited', $res->get_data()['error']['code'] ?? null);
        self::assertSame(
            [['me_write', AuthSupport::ME_WRITE_RATE_LIMIT, 60, 'me_write:7']],
            $GLOBALS['__bcc_susp_fixture']['throttle_calls'],
            'one per-ACCOUNT bucket, taken first'
        );
    }

    public function testAHandleChangeAlsoHasItsOwnHourlyCap(): void
    {
        // Nothing denied: record which buckets the handler takes, in order,
        // before it reaches its own validation.
        $GLOBALS['__bcc_susp_fixture']['throttled'] = false;
        $calls    = &$GLOBALS['__bcc_susp_fixture']['throttle_calls'];
        $endpoint = (new ReflectionClass(OnboardingEndpoint::class))->newInstanceWithoutConstructor();

        try {
            $endpoint->updateHandle(new \WP_REST_Request());
        } catch (\Throwable) {
            // Past the throttles the handler needs collaborators this stub
            // set does not provide; only the buckets taken matter here.
        }

        self::assertSame(['me_write', 'me_write:7'], [$calls[0][0] ?? null, $calls[0][3] ?? null]);
        self::assertSame(
            ['me_handle', AuthSupport::HANDLE_CHANGE_RATE_LIMIT, 3600, 'me_handle:7'],
            $calls[1] ?? null,
            'the handle bucket: per account, per hour'
        );
    }

    public function testTheLimitsAreTheApprovedOnes(): void
    {
        self::assertSame(120, AuthSupport::ME_WRITE_RATE_LIMIT);
        self::assertSame(10, AuthSupport::HANDLE_CHANGE_RATE_LIMIT);
    }
}
