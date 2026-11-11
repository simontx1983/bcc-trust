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
        };
        $suspensions = new class extends SuspensionRepository {
            public function __construct()
            {
            }
            public function create(int $userId, int $suspendedBy, string $reason, string $notes = '', int $fraudScoreAtTime = 0): int
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
    }
}
