<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Repositories\SuspensionRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Services\Admin\ModerationService;
use BCC\Trust\Core\Support\JwtToken;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-112 / F-119 at the SERVICE level: every admin suspend path (single,
 * ring "suspend_all", bulk "suspend")
 *   1. syncs a missing user_info row FIRST, so a valid user is really
 *      suspended (the repository now throws on a missing row, which used
 *      to be a silent "User suspended" no-op);
 *   2. revokes the user's Bearer tokens after the suspension commits, so a
 *      token minted BEFORE the suspension is rejected everywhere AFTER it,
 *      not only at routes that check suspension;
 *   3. revokes NOTHING when the suspension itself fails.
 *
 * The real ModerationService and the real JwtToken encode/decode run; only
 * persistence is faked (see tests/Stubs/moderation-suspend-stubs.php).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ModerationSuspendRevokesTokensTest extends TestCase
{
    private const UID = 4100;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/moderation-suspend-stubs.php';
        $GLOBALS['__bcc_ms'] = ['tv' => [], 'rows' => [], 'synced' => [], 'suspended' => [], 'audit' => [], 'errors' => [], 'fail_suspend' => false];
    }

    private function service(): ModerationService
    {
        $svc = (new ReflectionClass(ModerationService::class))->newInstanceWithoutConstructor();

        $userInfo = new class extends UserInfoRepository {
            public function __construct()
            {
            }
            public function exists(int $userId): bool
            {
                return !empty($GLOBALS['__bcc_ms']['rows'][$userId]);
            }
            public function getFraudScore(int $userId): int
            {
                return 0;
            }
            public function suspendUser(int $userId, string $reason): void
            {
                // Mirrors the real repository: a missing row THROWS.
                if (empty($GLOBALS['__bcc_ms']['rows'][$userId]) || $GLOBALS['__bcc_ms']['fail_suspend']) {
                    throw new RepositoryException('no user_info row for user ' . $userId);
                }
                $GLOBALS['__bcc_ms']['suspended'][] = $userId;
            }
            public function unsuspendUser(int $userId): void
            {
                $GLOBALS['__bcc_ms']['unsuspended'][] = $userId;
            }
            public function getByUserIdOrThrow(int $userId): ?object
            {
                if (in_array($userId, $GLOBALS['__bcc_ms']['unknown'] ?? [], true)) {
                    throw new RepositoryException('user_info read failed for user ' . $userId);
                }
                if (empty($GLOBALS['__bcc_ms']['rows'][$userId])) {
                    return null;
                }
                $suspended = in_array($userId, $GLOBALS['__bcc_ms']['already'] ?? [], true) ? 1 : 0;
                return (object) ['user_id' => $userId, 'is_suspended' => (string) $suspended];
            }
            public function notifySuspensionChanged(int $userId): void
            {
                // F-114: record WHETHER the flush ran inside the transaction.
                $GLOBALS['__bcc_ms']['notified'][] = [$userId, \BCC\Trust\Core\Security\TransactionManager::isInRunTransaction()];
                if (!empty($GLOBALS['__bcc_ms']['notify_throws'])) {
                    throw new \RuntimeException('cache flush failed');
                }
            }
            public function updateFraudFields(int $userId, int $fraudScore, string $riskLevel, int $behaviorScore): void
            {
                $GLOBALS['__bcc_ms']['fraud_fields'][$userId] = [$fraudScore, $riskLevel, $behaviorScore];
            }
        };
        $suspensions = new class extends SuspensionRepository {
            public function __construct()
            {
            }
            public function create(int $userId, int $suspendedBy, string $reason, string $notes = '', int $fraudScoreAtTime = 0): int
            {
                $GLOBALS['__bcc_ms']['created'][$userId] = [$reason, $notes];
                return 1;
            }
            public function closeActive(int $userId, int $unsuspendedBy): int
            {
                return 1;
            }
        };

        foreach (['userInfoRepository' => $userInfo, 'suspensionRepository' => $suspensions] as $prop => $value) {
            $p = (new ReflectionClass(ModerationService::class))->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($svc, $value);
        }
        return $svc;
    }

    /**
     * The ring/bulk handlers end in `wp_safe_redirect(); exit;`. The stubbed
     * redirect throws RedirectSignal AFTER the loop has run, so reaching it
     * proves the handler completed. Missing it is a failure.
     */
    private static function runAdminHandler(callable $handler): void
    {
        ob_start();
        try {
            $handler();
            self::fail('the admin handler should end by redirecting');
        } catch (\BCC\Trust\Core\Services\Admin\RedirectSignal $e) {
            // expected: the handler finished its loop and redirected
        } finally {
            ob_end_clean();
        }
    }

    private static function mintToken(int $uid): string
    {
        $token = JwtToken::encode($uid, 'user' . $uid);
        self::assertTrue(JwtToken::decode($token)['ok'], 'control: a fresh token decodes before suspension');
        return $token;
    }

    private static function assertRevoked(string $token): void
    {
        $result = JwtToken::decode($token);
        self::assertFalse($result['ok'], 'a pre-suspension token must stop authenticating');
        self::assertSame(JwtToken::ERR_REVOKED, $result['error'] ?? null);
    }

    public function testSingleSuspendSyncsAMissingRowThenSuspendsAndRevokes(): void
    {
        $token = self::mintToken(self::UID);
        // No user_info row for this user at all.

        ob_start();
        $result = $this->service()->suspendUser(self::UID, 'manual_suspension');
        ob_end_clean();

        self::assertTrue($result['success'], $result['message']);
        self::assertSame([self::UID], $GLOBALS['__bcc_ms']['synced'], 'the missing row is synced first');
        self::assertSame([self::UID], $GLOBALS['__bcc_ms']['suspended'], 'and the user really is suspended');
        self::assertRevoked($token);
    }

    public function testAnExistingRowIsNotResynced(): void
    {
        $GLOBALS['__bcc_ms']['rows'][self::UID] = true;
        $token = self::mintToken(self::UID);

        ob_start();
        $this->service()->suspendUser(self::UID);
        ob_end_clean();

        self::assertSame([], $GLOBALS['__bcc_ms']['synced']);
        self::assertRevoked($token);
    }

    public function testRingSuspendAllRevokesEveryMember(): void
    {
        $tokens = [4201 => self::mintToken(4201), 4202 => self::mintToken(4202)];
        $GLOBALS['__bcc_ms']['rows'][4201] = true; // 4202 has no row

        self::runAdminHandler(fn () => $this->service()->handleRingAction([4201, 4202], 'suspend_all'));

        self::assertSame([4202], $GLOBALS['__bcc_ms']['synced']);
        self::assertSame([4201, 4202], $GLOBALS['__bcc_ms']['suspended']);
        foreach ($tokens as $t) {
            self::assertRevoked($t);
        }
    }

    public function testBulkSuspendRevokesEveryUser(): void
    {
        $tokens = [4301 => self::mintToken(4301), 4302 => self::mintToken(4302)];

        self::runAdminHandler(fn () => $this->service()->handleBulkAction([4301, 4302], 'suspend'));

        self::assertSame([4301, 4302], $GLOBALS['__bcc_ms']['suspended']);
        foreach ($tokens as $t) {
            self::assertRevoked($t);
        }
    }

    public function testAFailedSuspensionRevokesNothing(): void
    {
        $GLOBALS['__bcc_ms']['rows'][self::UID] = true;
        $GLOBALS['__bcc_ms']['fail_suspend'] = true;
        $token = self::mintToken(self::UID);

        ob_start();
        $result = $this->service()->suspendUser(self::UID);
        ob_end_clean();

        self::assertFalse($result['success']);
        self::assertTrue(JwtToken::decode($token)['ok'], 'no suspension landed, so the session must not be killed');
        self::assertSame([], $GLOBALS['__bcc_ms']['notified'] ?? [], 'a rolled-back change flushes and fans out nothing');
    }

    // ── F-114 ──────────────────────────────────────────────────────────

    /** The caches are flushed AFTER the commit, on every suspend/unsuspend path. */
    public function testEverySuspensionChangeFlushesAfterTheCommitNotInsideIt(): void
    {
        $GLOBALS['__bcc_ms']['rows'] += [self::UID => true, 4201 => true, 4301 => true];

        ob_start();
        $this->service()->suspendUser(self::UID);
        $this->service()->unsuspendUser(self::UID);
        ob_end_clean();
        self::runAdminHandler(fn () => $this->service()->handleRingAction([4201], 'suspend_all'));
        self::runAdminHandler(fn () => $this->service()->handleBulkAction([4301], 'suspend'));
        self::runAdminHandler(fn () => $this->service()->handleBulkAction([4301], 'unsuspend'));

        self::assertSame(
            [[self::UID, false], [self::UID, false], [4201, false], [4301, false], [4301, false]],
            $GLOBALS['__bcc_ms']['notified'] ?? [],
            'one flush per change, each outside the transaction'
        );
    }

    public function testNoSuspendPathSuspendsTheActingAdminAnAdministratorOrTheAlreadySuspended(): void
    {
        // get_current_user_id() is 1 in this world.
        $GLOBALS['__bcc_ms']['admins']  = [4402];
        $GLOBALS['__bcc_ms']['already'] = [4403];
        $GLOBALS['__bcc_ms']['rows']   += [1 => true, 4401 => true, 4402 => true, 4403 => true];

        foreach ([1 => 'yourself', 4402 => 'administrator', 4403 => 'already suspended'] as $uid => $says) {
            ob_start();
            $result = $this->service()->suspendUser($uid);
            ob_end_clean();
            self::assertFalse($result['success'], 'single suspend of ' . $uid);
            self::assertStringContainsString($says, $result['message']);
        }

        self::runAdminHandler(fn () => $this->service()->handleBulkAction([1, 4401, 4402, 4403], 'suspend'));
        self::assertSame(['bulk' => 1, 'bulk_skipped' => 3], $GLOBALS['__bcc_ms']['query']);

        self::runAdminHandler(fn () => $this->service()->handleRingAction([1, 4402, 4403], 'suspend_all', 'r'));
        self::assertSame(['tab' => 'rings', 'ring_msg' => 'suspended_0_skipped_3'], $GLOBALS['__bcc_ms']['query']);

        self::assertSame([4401], $GLOBALS['__bcc_ms']['suspended'], 'only the ordinary member was suspended, once');
        self::assertSame(
            [1 => 'self', 4402 => 'admin', 4403 => 'already_suspended'],
            $GLOBALS['__bcc_ms']['transients']['bcc_trust_suspend_skips_1'] ?? null,
            'who was skipped, and why, is kept for the next page view'
        );
    }

    /**
     * #488 review L2: a FAILED "already suspended?" read used to collapse to
     * "no" and re-suspend (a second suspensions row). Now that member is
     * skipped and named as unverifiable; nobody else is affected.
     */
    public function testAnUnreadableSuspendedCheckSkipsInsteadOfResuspending(): void
    {
        $GLOBALS['__bcc_ms']['unknown'] = [4701];
        $GLOBALS['__bcc_ms']['rows']   += [4701 => true, 4702 => true];

        ob_start();
        $result = $this->service()->suspendUser(4701);
        ob_end_clean();
        self::assertFalse($result['success']);
        self::assertStringContainsString('could not check whether they are already suspended', $result['message']);

        self::runAdminHandler(fn () => $this->service()->handleBulkAction([4701, 4702], 'suspend'));

        self::assertSame([4702], $GLOBALS['__bcc_ms']['suspended'], 'only the readable member is suspended');
        self::assertSame(['bulk' => 1, 'bulk_skipped' => 1], $GLOBALS['__bcc_ms']['query']);
        self::assertSame([4701 => 'unverifiable'], $GLOBALS['__bcc_ms']['transients']['bcc_trust_suspend_skips_1'] ?? null);
    }

    /**
     * #488 review L1: once the transaction commits, the suspension stands. A
     * failing after-commit step (cache flush) is logged, never reported as a
     * failed suspension, and the session revocation after it still runs.
     */
    public function testAFailedAfterCommitStepStillCountsTheSuspensionAndRevokes(): void
    {
        $GLOBALS['__bcc_ms']['notify_throws'] = true;
        $GLOBALS['__bcc_ms']['rows'] += [self::UID => true, 4801 => true];
        $single = self::mintToken(self::UID);
        $bulk   = self::mintToken(4801);

        ob_start();
        $result = $this->service()->suspendUser(self::UID);
        ob_end_clean();
        self::assertTrue($result['success'], $result['message']);
        self::assertRevoked($single);

        self::runAdminHandler(fn () => $this->service()->handleBulkAction([4801], 'suspend'));
        self::assertSame(['bulk' => 1, 'bulk_skipped' => 0], $GLOBALS['__bcc_ms']['query'], 'counted as suspended');
        self::assertRevoked($bulk);
        self::assertSame(
            ['bcc_trust:user_info_' . self::UID, 'bcc_trust:user_info_4801'],
            $GLOBALS['__bcc_ms']['cache_deleted'] ?? [],
            '#538 review: a failed flush still drops the row cache the suspend guard reads'
        );
    }

    public function testTheRingSuspensionKeepsTheAdminsReason(): void
    {
        $GLOBALS['__bcc_ms']['rows'][4501] = true;

        self::runAdminHandler(fn () => $this->service()->handleRingAction([4501], 'suspend_all', 'Coordinated upvotes on page 77'));

        self::assertSame(['vote_ring', 'Coordinated upvotes on page 77'], $GLOBALS['__bcc_ms']['created'][4501]);
    }

    public function testBulkReanalyzeRecomputesAndPersistsLikeTheSingleAction(): void
    {
        $GLOBALS['__bcc_ms']['fd_fail'] = [4602];

        self::runAdminHandler(fn () => $this->service()->handleBulkAction([4601, 4602, 4603], 'reanalyze'));

        self::assertSame(
            [4601 => [41, 'medium', 7], 4603 => [43, 'medium', 7]],
            $GLOBALS['__bcc_ms']['fraud_fields'] ?? [],
            'each user\'s fraud fields are written; the failure is skipped'
        );
        self::assertSame(['bulk' => 2, 'bulk_skipped' => 0], $GLOBALS['__bcc_ms']['query'], 'only real successes are counted');
    }
}
