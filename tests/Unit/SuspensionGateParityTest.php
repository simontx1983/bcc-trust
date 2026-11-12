<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\CommentStokeEndpoint;
use BCC\Trust\Core\REST\HallsEndpoint;
use BCC\Trust\Core\REST\HelpfulMarkEndpoint;
use BCC\Trust\Core\REST\MyBlocksEndpoint;
use BCC\Trust\Core\REST\MyGroupsEndpoint;
use BCC\Trust\Core\REST\MyProfileEndpoint;
use BCC\Trust\Core\REST\MyProfileFieldsEndpoint;
use BCC\Trust\Core\REST\OnboardingEndpoint;
use BCC\Trust\Core\REST\PagesEndpoint;
use BCC\Trust\Core\REST\PhotoAltEndpoint;
use BCC\Trust\Core\REST\WatchingEndpoint;
use BCC\Trust\Onchain\Services\ClaimService;
use BCC\Trust\Core\REST\ReactionsEndpoint;
use BCC\Trust\Core\REST\StokeEndpoint;
use BCC\Trust\Core\Services\CommentService;
use BCC\Trust\Core\Services\ContentReportService;
use BCC\Trust\Core\Services\PostsService;
use BCC\Trust\Onchain\REST\HolderGroupsEndpoint;
use BCC\Trust\Onchain\Services\NftGroupGateService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Suspension-gate parity — [audit M — group-rejoin] follow-up.
 *
 * The audit fix gated POST /me/groups/{id}/join on
 * Permissions::is_not_suspended($userId, false) (bcc-trust PR #56). This
 * suite pins the SAME gate on the sibling membership-write doors that were
 * still open afterwards:
 *
 *   - POST /me/halls/{id}/membership    (HallsEndpoint::join)
 *   - POST /me/holder-groups/{id}/join  (HolderGroupsEndpoint::postJoin)
 *   - POST /me/groups                   (MyGroupsEndpoint::postCreate)
 *   - NftGroupGateService::reconcileForUser (cron auto-join — the server
 *     itself must not re-add a suspended holder who has auto_join on)
 *
 * …and (2026-08-06 sweep) on the participation-write surfaces that were
 * still ungated after #162's parity pass:
 *
 *   - POST/DELETE /reactions            (ReactionsEndpoint)
 *   - POST/DELETE /feed/{id}/stoke      (StokeEndpoint)
 *   - POST/DELETE /comments/{id}/stoke  (CommentStokeEndpoint)
 *   - POST /feed|comments/{id}/helpful  (HelpfulMarkEndpoint — the
 *     UN-mark path deliberately stays open: retracting your own prior
 *     endorsement never mints evidence)
 *   - PostsService::createStatus / CommentService::createComment /
 *     ContentReportService::fileReport (service-boundary array envelopes)
 *
 * Every REST case asserts the gate fires with admin bypass OFF (second
 * arg false) — a suspended account is blocked regardless of role.
 *
 * ## Isolation
 * Runs in its own subprocess; setUp() pulls in
 * tests/Stubs/suspension-gate-stubs.php which fakes
 * BCC\Core\Permissions\Permissions + GatedGroupRepository at their FQNs
 * and provides the minimal WP surface (get_current_user_id, get_user_meta,
 * WP_REST_Request/Response). Endpoints are instantiated without their
 * constructors — the gate path touches no instance state (it returns
 * before any service/repository access).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SuspensionGateParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/suspension-gate-stubs.php';
        $GLOBALS['__bcc_susp_fixture'] = [
            'user_id'        => 7,
            'suspended'      => true,
            'user_meta'      => [],
            'bypass_args'    => [],
            'throttled'      => false,
            'throttle_calls' => [],
        ];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function endpoint(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private static function assertSuspendedRejection(\WP_REST_Response $response): void
    {
        self::assertSame(403, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('bcc_forbidden', $data['error']['code'] ?? null);
        // Admin bypass must be OFF — a suspended account is blocked
        // regardless of role.
        self::assertSame([false], $GLOBALS['__bcc_susp_fixture']['bypass_args']);
    }

    public function testHallsJoinRejectsSuspendedUser(): void
    {
        $response = self::endpoint(HallsEndpoint::class)->join(new \WP_REST_Request());
        self::assertSuspendedRejection($response);
    }

    public function testHolderGroupJoinRejectsSuspendedUser(): void
    {
        $response = self::endpoint(HolderGroupsEndpoint::class)->postJoin(new \WP_REST_Request());
        self::assertSuspendedRejection($response);
    }

    public function testGroupCreateRejectsSuspendedUser(): void
    {
        $response = self::endpoint(MyGroupsEndpoint::class)->postCreate(new \WP_REST_Request());
        self::assertSuspendedRejection($response);
    }

    // ── 2026-08-06 sweep: participation-write surfaces ──────────────────

    public function testReactionSetRejectsSuspendedUser(): void
    {
        $response = self::endpoint(ReactionsEndpoint::class)->setReaction(new \WP_REST_Request());
        self::assertSuspendedRejection($response);
    }

    public function testReactionRemoveRejectsSuspendedUser(): void
    {
        $response = self::endpoint(ReactionsEndpoint::class)->removeReaction(new \WP_REST_Request());
        self::assertSuspendedRejection($response);
    }

    public function testStokeAddRejectsSuspendedUser(): void
    {
        $response = self::endpoint(StokeEndpoint::class)->addStoke(new \WP_REST_Request());
        self::assertSuspendedRejection($response);
    }

    public function testStokeRemoveRejectsSuspendedUser(): void
    {
        $response = self::endpoint(StokeEndpoint::class)->removeStoke(new \WP_REST_Request());
        self::assertSuspendedRejection($response);
    }

    public function testCommentStokeAddRejectsSuspendedUser(): void
    {
        $response = self::endpoint(CommentStokeEndpoint::class)->addStoke(new \WP_REST_Request());
        self::assertSuspendedRejection($response);
    }

    public function testHelpfulMarkAddRejectsSuspendedUser(): void
    {
        $response = self::endpoint(HelpfulMarkEndpoint::class)->addPostMark(new \WP_REST_Request());
        self::assertSuspendedRejection($response);
    }

    // ── F-109: page management, watch, and public profile content ───────

    public function testPageClaimRejectsSuspendedUser(): void
    {
        self::assertSuspendedRejection(self::endpoint(PagesEndpoint::class)->claim(new \WP_REST_Request()));
    }

    public function testPageClaimThrottleRunsBeforeSuspension(): void
    {
        $GLOBALS['__bcc_susp_fixture']['throttled'] = true;
        self::assertThrottledBeforeSuspension(self::endpoint(PagesEndpoint::class)->claim(new \WP_REST_Request()));
    }

    public function testPageAvatarUploadRejectsSuspendedUser(): void
    {
        self::assertSuspendedRejection(self::endpoint(PagesEndpoint::class)->uploadAvatar(new \WP_REST_Request()));
    }

    public function testPageAvatarDeleteRejectsSuspendedUser(): void
    {
        self::assertSuspendedRejection(self::endpoint(PagesEndpoint::class)->deleteAvatar(new \WP_REST_Request()));
    }

    public function testWatchRejectsSuspendedUser(): void
    {
        self::assertSuspendedRejection(self::endpoint(WatchingEndpoint::class)->watch(new \WP_REST_Request()));
    }

    public function testWatchThrottleRunsBeforeSuspension(): void
    {
        $GLOBALS['__bcc_susp_fixture']['throttled'] = true;
        self::assertThrottledBeforeSuspension(self::endpoint(WatchingEndpoint::class)->watch(new \WP_REST_Request()));
    }

    public function testProfileEditRejectsSuspendedUser(): void
    {
        self::assertSuspendedRejection(self::endpoint(MyProfileEndpoint::class)->patch(new \WP_REST_Request()));
    }

    public function testProfileAvatarUploadRejectsSuspendedUser(): void
    {
        self::assertSuspendedRejection(self::endpoint(MyProfileEndpoint::class)->uploadAvatar(new \WP_REST_Request()));
    }

    public function testProfileCoverUploadRejectsSuspendedUser(): void
    {
        self::assertSuspendedRejection(self::endpoint(MyProfileEndpoint::class)->uploadCover(new \WP_REST_Request()));
    }

    // F-58: profile image uploads were unthrottled.

    public function testProfileAvatarUploadThrottleRunsBeforeSuspension(): void
    {
        $GLOBALS['__bcc_susp_fixture']['throttled'] = true;
        self::assertThrottledBeforeSuspension(self::endpoint(MyProfileEndpoint::class)->uploadAvatar(new \WP_REST_Request()));
    }

    public function testProfileCoverUploadThrottleRunsBeforeSuspension(): void
    {
        $GLOBALS['__bcc_susp_fixture']['throttled'] = true;
        self::assertThrottledBeforeSuspension(self::endpoint(MyProfileEndpoint::class)->uploadCover(new \WP_REST_Request()));
    }

    /**
     * Avatar and cover share ONE per-user budget (a burst bucket and an
     * hourly bucket), so alternating the two routes cannot double the rate.
     */
    public function testAvatarAndCoverShareOnePerUserBudget(): void
    {
        $GLOBALS['__bcc_susp_fixture']['suspended'] = true; // stop right after the throttle
        self::endpoint(MyProfileEndpoint::class)->uploadAvatar(new \WP_REST_Request());
        self::endpoint(MyProfileEndpoint::class)->uploadCover(new \WP_REST_Request());

        $keys = array_map(static fn (array $c): string => (string) $c[0], $GLOBALS['__bcc_susp_fixture']['throttle_calls']);
        self::assertSame(
            ['profile_image:7:burst', 'profile_image:7:hourly', 'profile_image:7:burst', 'profile_image:7:hourly'],
            $keys
        );
    }

    public function testProfileCoverPositionRejectsSuspendedUser(): void
    {
        self::assertSuspendedRejection(self::endpoint(MyProfileEndpoint::class)->patchCoverPosition(new \WP_REST_Request()));
    }

    public function testProfileFieldValueRejectsSuspendedUser(): void
    {
        self::assertSuspendedRejection(self::endpoint(MyProfileFieldsEndpoint::class)->patchValue(new \WP_REST_Request()));
    }

    public function testPhotoAltRejectsSuspendedUser(): void
    {
        self::assertSuspendedRejection(self::endpoint(PhotoAltEndpoint::class)->patch(new \WP_REST_Request()));
    }

    public function testHandleChangeRejectsSuspendedUser(): void
    {
        self::assertSuspendedRejection(self::endpoint(OnboardingEndpoint::class)->updateHandle(new \WP_REST_Request()));
    }

    /**
     * Review M1: the operator profile (shared fields, per-page overrides,
     * logo) is public page content too.
     *
     * @return iterable<string, array{string}>
     */
    public static function operatorProfileWrites(): iterable
    {
        yield 'shared profile'       => ['updateShared'];
        yield 'page override'        => ['updatePageOverride'];
        yield 'page override reset'  => ['resetPageOverride'];
        yield 'logo upload'          => ['uploadLogo'];
        yield 'logo delete'          => ['deleteLogo'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('operatorProfileWrites')]
    public function testOperatorProfileWritesRejectSuspendedUser(string $method): void
    {
        $endpoint = self::endpoint(\BCC\Trust\Onchain\REST\OperatorProfileEndpoint::class);
        self::assertSuspendedRejection($endpoint->{$method}(new \WP_REST_Request()));
    }

    public function testClaimServiceRejectsSuspendedUserAtTheServiceBoundary(): void
    {
        $result = ClaimService::claim(7, 'validator', 1);

        self::assertFalse($result['success'] ?? null);
        // An explicit sentinel the claim route maps to reason `suspended` (F-303).
        self::assertSame('suspended', $result['error'] ?? null);
        self::assertSame('Your account is suspended.', $result['message'] ?? null);
        self::assertSame([false], $GLOBALS['__bcc_susp_fixture']['bypass_args']);
    }

    /**
     * DELIBERATELY OPEN while suspended: self-protection, retraction and
     * account hygiene. A suspended user must still be able to block
     * someone, unwatch, remove their own avatar/cover, hide profile
     * fields, and manage privacy, notification and account settings.
     * Pinned at source level, so gating one of these by accident fails here.
     *
     * @return iterable<string, array{class-string, string}>
     */
    public static function deliberatelyOpen(): iterable
    {
        yield 'block create'          => [MyBlocksEndpoint::class, 'create'];
        yield 'block remove'          => [MyBlocksEndpoint::class, 'remove'];
        yield 'unwatch'               => [WatchingEndpoint::class, 'unwatch'];
        yield 'own avatar delete'     => [MyProfileEndpoint::class, 'deleteAvatar'];
        yield 'own cover delete'      => [MyProfileEndpoint::class, 'deleteCover'];
        yield 'field visibility'      => [MyProfileFieldsEndpoint::class, 'patchVisibility'];
        yield 'privacy'               => [\BCC\Trust\Core\REST\MyPrivacyEndpoint::class, 'patch'];
        yield 'profile prefs'         => [\BCC\Trust\Core\REST\MyProfilePrefsEndpoint::class, 'patch'];
        yield 'email change'          => [\BCC\Trust\Core\REST\MyAccountEndpoint::class, 'patchEmail'];
        yield 'password change'       => [\BCC\Trust\Core\REST\MyAccountEndpoint::class, 'patchPassword'];
        yield 'account delete'        => [\BCC\Trust\Core\REST\MyAccountEndpoint::class, 'deleteAccount'];
    }

    /** @param class-string $class */
    #[\PHPUnit\Framework\Attributes\DataProvider('deliberatelyOpen')]
    public function testSelfProtectionRoutesStayOpenWhileSuspended(string $class, string $method): void
    {
        $ref   = new \ReflectionMethod($class, $method);
        $lines = file((string) $ref->getFileName());
        self::assertIsArray($lines);
        $body = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));

        self::assertStringNotContainsString(
            'is_not_suspended',
            $body,
            "{$class}::{$method} must stay usable by a suspended account"
        );
    }

    /**
     * Service-boundary gates return the array error envelope (the REST
     * layer maps bcc_forbidden → 403). Same admin-bypass-OFF assertion.
     *
     * @param array<string, mixed> $result
     */
    private static function assertSuspendedServiceRejection(array $result): void
    {
        self::assertSame('bcc_forbidden', $result['error'] ?? null);
        self::assertSame('Your account is suspended.', $result['message'] ?? null);
        self::assertSame([false], $GLOBALS['__bcc_susp_fixture']['bypass_args']);
    }

    public function testStatusPostRejectsSuspendedUser(): void
    {
        $service = (new ReflectionClass(PostsService::class))->newInstanceWithoutConstructor();
        self::assertSuspendedServiceRejection($service->createStatus(7, 'hello floor'));
    }

    public function testCommentCreateRejectsSuspendedUser(): void
    {
        $service = (new ReflectionClass(CommentService::class))->newInstanceWithoutConstructor();
        self::assertSuspendedServiceRejection($service->createComment('feed_1', 7, 'hello thread'));
    }

    public function testContentReportRejectsSuspendedUser(): void
    {
        $service = (new ReflectionClass(ContentReportService::class))->newInstanceWithoutConstructor();
        self::assertSuspendedServiceRejection($service->fileReport(7, 'feed_item', 5, 'spam', ''));
    }

    // ── Throttle-before-credentials ordering ────────────────────────────
    //
    // The rate limiter must run BEFORE the suspension gate: with BOTH
    // throttled and suspended, the response must be 429 (throttle won) and
    // the suspension permission read must never have been reached.

    private static function assertThrottledBeforeSuspension(\WP_REST_Response $response): void
    {
        self::assertSame(429, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('bcc_rate_limited', $data['error']['code'] ?? null);
        // Throttle evaluated first…
        self::assertNotSame([], $GLOBALS['__bcc_susp_fixture']['throttle_calls']);
        // …and the suspension gate was never reached.
        self::assertSame([], $GLOBALS['__bcc_susp_fixture']['bypass_args']);
    }

    public function testHallsJoinThrottleRunsBeforeSuspension(): void
    {
        $GLOBALS['__bcc_susp_fixture']['throttled'] = true;
        $response = self::endpoint(HallsEndpoint::class)->join(new \WP_REST_Request());
        self::assertThrottledBeforeSuspension($response);
    }

    public function testHolderGroupJoinThrottleRunsBeforeSuspension(): void
    {
        $GLOBALS['__bcc_susp_fixture']['throttled'] = true;
        $response = self::endpoint(HolderGroupsEndpoint::class)->postJoin(new \WP_REST_Request());
        self::assertThrottledBeforeSuspension($response);
    }

    public function testGroupJoinThrottleRunsBeforeSuspension(): void
    {
        $GLOBALS['__bcc_susp_fixture']['throttled'] = true;
        $response = self::endpoint(MyGroupsEndpoint::class)->postJoin(new \WP_REST_Request());
        self::assertThrottledBeforeSuspension($response);
    }

    public function testGroupCreateThrottleRunsBeforeSuspension(): void
    {
        $GLOBALS['__bcc_susp_fixture']['throttled'] = true;
        $response = self::endpoint(MyGroupsEndpoint::class)->postCreate(new \WP_REST_Request());
        self::assertThrottledBeforeSuspension($response);
    }

    public function testReactionSetThrottleRunsBeforeSuspension(): void
    {
        $GLOBALS['__bcc_susp_fixture']['throttled'] = true;
        $response = self::endpoint(ReactionsEndpoint::class)->setReaction(new \WP_REST_Request());
        self::assertThrottledBeforeSuspension($response);
    }

    public function testStokeAddThrottleRunsBeforeSuspension(): void
    {
        $GLOBALS['__bcc_susp_fixture']['throttled'] = true;
        $response = self::endpoint(StokeEndpoint::class)->addStoke(new \WP_REST_Request());
        self::assertThrottledBeforeSuspension($response);
    }

    public function testHelpfulMarkThrottleRunsBeforeSuspension(): void
    {
        $GLOBALS['__bcc_susp_fixture']['throttled'] = true;
        $response = self::endpoint(HelpfulMarkEndpoint::class)->addPostMark(new \WP_REST_Request());
        self::assertThrottledBeforeSuspension($response);
    }

    public function testHolderGroupListThrottledBeforeRepositoryRead(): void
    {
        $GLOBALS['__bcc_susp_fixture']['throttled']   = true;
        $GLOBALS['__bcc_susp_fixture']['suspended']   = false;
        $response = self::endpoint(HolderGroupsEndpoint::class)->getList(new \WP_REST_Request());

        self::assertSame(429, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('bcc_rate_limited', $data['error']['code'] ?? null);
        // The gated-group repository must never be reached under throttle.
        self::assertArrayNotHasKey('repo_reached', $GLOBALS['__bcc_susp_fixture']);
    }

    public function testReconcileSkipsSuspendedUserBeforeAnyRepositoryRead(): void
    {
        $GLOBALS['__bcc_susp_fixture']['user_meta'][7][NftGroupGateService::USER_META_AUTO_JOIN] = '1';

        $result = (new NftGroupGateService())->reconcileForUser(7);

        self::assertSame(['joined' => 0, 'skipped' => 0], $result);
        // The guard must fire BEFORE findEligibleGroups touches the
        // gated-group repository — a suspended holder is never even
        // evaluated for auto-join.
        self::assertArrayNotHasKey('repo_reached', $GLOBALS['__bcc_susp_fixture']);
        self::assertSame([false], $GLOBALS['__bcc_susp_fixture']['bypass_args']);
    }

    public function testReconcileProceedsForUnsuspendedOptedInUser(): void
    {
        $GLOBALS['__bcc_susp_fixture']['suspended'] = false;
        $GLOBALS['__bcc_susp_fixture']['user_meta'][7][NftGroupGateService::USER_META_AUTO_JOIN] = '1';

        $result = (new NftGroupGateService())->reconcileForUser(7);

        // Control case: with the suspension gate passing, reconcile reaches
        // the repository (which the stub answers with zero gated groups).
        self::assertSame(['joined' => 0, 'skipped' => 0], $result);
        self::assertTrue($GLOBALS['__bcc_susp_fixture']['repo_reached'] ?? false);
    }
}
