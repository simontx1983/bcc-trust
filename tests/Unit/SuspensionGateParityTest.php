<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\LocalsEndpoint;
use BCC\Trust\Core\REST\MyGroupsEndpoint;
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
 *   - POST /me/locals/{id}/membership   (LocalsEndpoint::join)
 *   - POST /me/holder-groups/{id}/join  (HolderGroupsEndpoint::postJoin)
 *   - POST /me/groups                   (MyGroupsEndpoint::postCreate)
 *   - NftGroupGateService::reconcileForUser (cron auto-join — the server
 *     itself must not re-add a suspended holder who has auto_join on)
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
            'user_id'     => 7,
            'suspended'   => true,
            'user_meta'   => [],
            'bypass_args' => [],
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

    public function testLocalsJoinRejectsSuspendedUser(): void
    {
        $response = self::endpoint(LocalsEndpoint::class)->join(new \WP_REST_Request());
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
