<?php
/**
 * F-469 (L1): the rate limiter caches only a user_info answer the read gave.
 *
 * A failed read used to come back from getByUserId() as null ("no row"), and
 * getAdjustedLimit() cached that null for 5 minutes, so the member's fraud
 * adjustment was missing for the whole window. The read now goes through
 * getByUserIdOrThrow(): a failure gives that one call the base limit and is
 * not cached, so the next call reads again.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Security\RateLimiter;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RateLimiterUserInfoCacheTest extends TestCase
{
    private const BASE = 100;

    /** BASE under the critical-risk multiplier (0.2). */
    private const PENALISED = 20;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/rate-limiter-user-info-stubs.php';
        $GLOBALS['__rl_cache'] = [];
    }

    public function testAFailedReadIsNotCachedAndTheNextCallReadsAgain(): void
    {
        $repo = self::userInfo([self::readFailure(), self::flagged()]);

        self::assertSame(self::BASE, self::limit(), 'the failed call: base limit');
        self::assertSame([], $GLOBALS['__rl_cache'], 'the failure is not cached');
        self::assertSame(self::PENALISED, self::limit(), 'the next call reads again and applies the adjustment');
        self::assertSame(2, $repo->reads);
    }

    public function testAnAnswerIsCachedForTheWindow(): void
    {
        $repo = self::userInfo([self::flagged()]);

        self::assertSame(self::PENALISED, self::limit());
        self::assertSame(self::PENALISED, self::limit());
        self::assertSame(1, $repo->reads, 'served from the cache');
    }

    public function testNoRowIsStillCachedAndGetsTheBaseLimit(): void
    {
        $repo = self::userInfo([null]);

        self::assertSame(self::BASE, self::limit());
        self::assertSame(self::BASE, self::limit());
        self::assertSame(1, $repo->reads, '"no row" is an answer, cached as before');
    }

    // ── Builders ────────────────────────────────────────────────────────

    /** getAdjustedLimit() for member 7, on an action with no trust boost. */
    private static function limit(): int
    {
        /** @var int $limit */
        $limit = (new \ReflectionMethod(RateLimiter::class, 'getAdjustedLimit'))->invoke(null, 7, self::BASE, 'claim');

        return $limit;
    }

    private static function flagged(): object
    {
        return (object) ['fraud_score' => BCC_TRUST_FRAUD_CRITICAL, 'is_verified' => 0, 'trust_rank' => 0.0];
    }

    private static function readFailure(): RepositoryException
    {
        return new RepositoryException('user_info read failed');
    }

    /**
     * A UserInfoRepository answering $answers in order: a row, null (no row)
     * or an exception (the read failed). The lenient getByUserId() is the
     * real one, which turns the exception into null.
     *
     * @param list<object|null|RepositoryException> $answers
     * @return UserInfoRepository&object{reads: int}
     */
    private static function userInfo(array $answers): UserInfoRepository
    {
        $repo = new class ($answers) extends UserInfoRepository {
            public int $reads = 0;

            /** @param list<object|null|RepositoryException> $answers */
            public function __construct(private array $answers)
            {
            }

            public function getByUserIdOrThrow(int $userId): ?object
            {
                $answer = $this->answers[$this->reads] ?? null;
                $this->reads++;
                if ($answer instanceof RepositoryException) {
                    throw $answer;
                }

                return $answer;
            }
        };
        (new \ReflectionProperty(RateLimiter::class, 'userInfoRepo'))->setValue(null, $repo);

        return $repo;
    }
}
