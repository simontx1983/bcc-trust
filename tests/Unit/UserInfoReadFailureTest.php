<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\UserInfoRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A FAILED user_info read must never be cached as "no row".
 *
 * getByUserId() feeds Permissions::is_not_suspended() through
 * TrustReadService::isSuspended(). get_row() returns null on a database error
 * exactly as it does for a user with no row, and that null used to be cached
 * for BCC_TRUST_CACHE_USER (5 minutes). One blip therefore made a SUSPENDED
 * user read as not suspended for five minutes, on every gate.
 *
 * A failed read still returns null (the gates fail open on a read error, on
 * purpose), but it is not cached, so the next call retries and sees the real
 * row. Both failure signals are covered: query() returning false WITH
 * last_error, and returning false WITHOUT it (wpdb's not-ready path), where the
 * previous query's rows are still in last_result.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserInfoReadFailureTest extends TestCase
{
    private const USER = 140;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/user-info-read-stubs.php';
        $GLOBALS['__bcc_cache'] = [];
        $GLOBALS['__bcc_logs']  = [];
    }

    private static function suspendedRow(): object
    {
        return (object) ['user_id' => (string) self::USER, 'is_suspended' => '1'];
    }

    public function testAFailedReadIsNotCachedSoTheNextCallSeesTheRealRow(): void
    {
        global $wpdb;
        $wpdb->script = [
            ['error' => 'Lost connection to MySQL server during query'],
            ['rows' => [self::suspendedRow()]],
        ];
        $repo = new UserInfoRepository();

        self::assertNull($repo->getByUserId(self::USER), 'a failed read answers null');
        self::assertSame([], $GLOBALS['__bcc_cache'], 'and is NOT cached');
        self::assertCount(1, $GLOBALS['__bcc_logs']);

        $row = $repo->getByUserId(self::USER);
        self::assertNotNull($row, 'the next call retries the database');
        self::assertSame('1', $row->is_suspended);
    }

    /**
     * query() can fail without setting last_error. get_row(null) would then
     * hand back the PREVIOUS query's row, here another user's, and the old
     * code would cache it under this user's key.
     */
    public function testASilentFailureDoesNotReturnOrCacheAStaleRow(): void
    {
        global $wpdb;
        $other = (object) ['user_id' => '999', 'is_suspended' => '0'];
        $wpdb->script = [['rows' => [$other]], ['silent' => true]];
        $repo = new UserInfoRepository();
        $repo->getByUserId(999);
        $GLOBALS['__bcc_cache'] = [];

        self::assertNull($repo->getByUserId(self::USER));
        self::assertSame([], $GLOBALS['__bcc_cache']);
    }

    /**
     * The suspension path needs "could not read" told apart from "no row":
     * getByUserIdOrThrow() throws on a failed read (and caches nothing),
     * while getByUserId() keeps answering null for its other callers.
     */
    public function testOrThrowThrowsOnAFailedReadAndCachesNothing(): void
    {
        global $wpdb;
        $wpdb->script = [['error' => 'Deadlock found when trying to get lock']];

        try {
            (new UserInfoRepository())->getByUserIdOrThrow(self::USER);
            self::fail('a failed read must throw, not answer "no row"');
        } catch (\BCC\Trust\Core\Exceptions\RepositoryException) {
            // expected
        }
        self::assertSame([], $GLOBALS['__bcc_cache']);
    }

    public function testOrThrowReturnsNullForAGenuinelyMissingRow(): void
    {
        global $wpdb;
        $wpdb->script = [['rows' => []]];

        self::assertNull((new UserInfoRepository())->getByUserIdOrThrow(self::USER));
    }

    public function testAGenuinelyMissingRowIsStillCached(): void
    {
        global $wpdb;
        $wpdb->script = [['rows' => []]];
        $repo = new UserInfoRepository();

        self::assertNull($repo->getByUserId(self::USER));
        self::assertSame(['bcc_trust:user_info_' . self::USER => ''], $GLOBALS['__bcc_cache']);

        self::assertNull($repo->getByUserId(self::USER));
        self::assertSame(1, $wpdb->queries, 'the cached "no row" is served without a second query');
    }

    public function testAFoundRowIsCachedAndReturned(): void
    {
        global $wpdb;
        $wpdb->script = [['rows' => [self::suspendedRow()]]];

        $row = (new UserInfoRepository())->getByUserId(self::USER);

        self::assertSame('1', $row?->is_suspended);
        self::assertEquals(self::suspendedRow(), $GLOBALS['__bcc_cache']['bcc_trust:user_info_' . self::USER] ?? null);
    }

    // ── suspendedAmongOrUnknown (F-90: the feed's reactor faces) ─────────

    public function testSuspendedAmongReturnsTheSuspendedIds(): void
    {
        global $wpdb;
        $wpdb->script = [['rows' => [(object) ['user_id' => '22']]]];

        self::assertSame([22], (new UserInfoRepository())->suspendedAmongOrUnknown([21, 22, 23]));
    }

    public function testSuspendedAmongIsNullNotEmptyWhenTheReadFails(): void
    {
        global $wpdb;
        $wpdb->script = [['error' => 'Lost connection to MySQL server during query']];

        self::assertNull((new UserInfoRepository())->suspendedAmongOrUnknown([21, 22, 23]));
    }

    public function testSuspendedAmongIsNullOnASilentFailureWithAStaleBuffer(): void
    {
        global $wpdb;
        $wpdb->script = [['rows' => [(object) ['user_id' => '99']]], ['silent' => true]];
        $repo = new UserInfoRepository();
        $repo->suspendedAmongOrUnknown([99]);

        self::assertNull($repo->suspendedAmongOrUnknown([21]), 'a stale previous row must not be returned');
    }

    public function testSuspendedAmongSkipsTheQueryForNoIds(): void
    {
        global $wpdb;

        self::assertSame([], (new UserInfoRepository())->suspendedAmongOrUnknown([0, -3]));
        self::assertSame(0, $wpdb->queries);
    }
}
