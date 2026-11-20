<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\MemberSearchCandidates;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-432: member matching can say "I could not look", not only "nobody matched".
 *
 * MemberSearchCandidates::ids() matches through two WP_User_Query lookups
 * (display name, then @handle) plus a user-row read between them. WordPress
 * answers a failed lookup with [] and a `last_error`, so ids() used to answer
 * a database failure as "nobody matched", and bcc-search's users vertical
 * then answered 200 with no members.
 *
 * With the appended $throwOnReadFailure (bcc-search's caller passes it):
 *   - each of the three steps that did not run throws RepositoryReadFailure,
 *     which bcc-search's UserSearchController answers as 503;
 *   - both lookups run with `cache_results => false`. WP_User_Query caches
 *     even a FAILED lookup's [] in `user-queries`, and the persistent object
 *     cache then serves it until any user changes (measured on the Local
 *     site, WP 7.0.2);
 *   - an error left by an earlier, unrelated query is not blamed on it.
 * Without the flag, the /members directory and the @-mention picker
 * behave exactly as before: same arguments, same answer, no throw.
 *
 * The get_users() double models the two lookups as the matcher makes them
 * (as in MentionSearchNoLoginOracleTest) and fails the way WordPress does:
 * [] plus `last_error`.
 *
 * ⚠ F-456: every simulated query starts by CLEARING `last_error`, as
 * wpdb::flush() does before each real query. Without that, a test cannot
 * see a read whose error a later query wiped. That is how a single check
 * after the whole user-row step passed here while missing a failed users
 * prime, or a failed row read for any id but the last. cache_users() is two
 * queries (usermeta, then users); get_userdata() is a query only for a row
 * the prime did not load, and a cache read otherwise.
 */
#[CoversClass(MemberSearchCandidates::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MemberSearchCandidatesReadFailureTest extends TestCase
{
    private const DB_ERROR = 'Lost connection to MySQL server during query';

    protected function setUp(): void
    {
        parent::setUp();
        // id => [login, display_name, handle]
        $GLOBALS['__msc'] = [
            'users' => [
                7 => ['u_alice', 'Alice Smith', 'alice'],
                8 => ['u_bob', 'Bob Alison', 'bobby'],
            ],
            // The one simulated query that fails: 'name' | 'handle' (the two
            // lookups), 'prime_meta' | 'prime_users' (cache_users()'s two
            // queries), or 'row:<id>' (get_userdata()'s read of that row).
            'fail'     => null,
            // Ids cache_users() does NOT load (e.g. evicted before the read),
            // so get_userdata() has to query their rows one by one.
            'unprimed' => [],
            // Ids the name lookup returns that have no user row (a deleted user).
            'ghosts'   => [],
            'primed'   => [],
            'calls'    => [],
        ];
        if (!class_exists('BCC\\Core\\Log\\Logger', false)) {
            eval('namespace BCC\\Core\\Log; final class Logger { public static array $lines = []; public static function error(string $m, array $c = []): void { self::$lines[] = $m . " " . json_encode($c); } public static function warning(string $m, array $c = []): void {} public static function info(string $m, array $c = []): void {} }');
        }
        $GLOBALS['wpdb'] = new class () {
            public string $last_error = '';
        };
        eval(<<<'PHP'
            /** One simulated query: clears last_error first (wpdb::flush()), then fails if it is the configured one. */
            function msc_query(string $which): bool
            {
                $GLOBALS['wpdb']->last_error = '';
                if ($GLOBALS['__msc']['fail'] === $which) {
                    $GLOBALS['wpdb']->last_error = 'Lost connection to MySQL server during query';
                    return false;
                }
                return true;
            }
            function get_users(array $args = []): array
            {
                $GLOBALS['__msc']['calls'][] = $args;
                $lookup = isset($args['search']) ? 'name' : (($args['meta_key'] ?? '') === 'bcc_handle' ? 'handle' : 'other');
                // `users_pre_query` short-circuit (a plugin answering the lookup):
                // no SQL runs, so last_error keeps whatever an earlier query left.
                if (empty($GLOBALS['__msc']['pre_query']) && !msc_query($lookup)) {
                    return []; // what wpdb leaves after a failed query: no rows, and the error
                }
                $out = [];
                foreach ($GLOBALS['__msc']['users'] as $id => [$login, $display, $handle]) {
                    $hit = $lookup === 'name'
                        ? str_contains(strtolower($display), strtolower(trim((string) $args['search'], '*')))
                        : str_contains($handle, strtolower((string) ($args['meta_value'] ?? '')));
                    if ($hit) {
                        $out[] = (string) $id;
                    }
                }
                if ($lookup === 'name') {
                    foreach ($GLOBALS['__msc']['ghosts'] as $ghost) {
                        $out[] = (string) $ghost;
                    }
                }
                return $out;
            }
            function cache_users(array $ids): void
            {
                msc_query('prime_meta');
                if (!msc_query('prime_users')) {
                    return;
                }
                foreach ($ids as $id) {
                    if (isset($GLOBALS['__msc']['users'][(int) $id]) && !in_array((int) $id, $GLOBALS['__msc']['unprimed'], true)) {
                        $GLOBALS['__msc']['primed'][(int) $id] = true;
                    }
                }
            }
            function get_userdata($id)
            {
                $id = (int) $id;
                if (empty($GLOBALS['__msc']['primed'][$id]) && !msc_query('row:' . $id)) {
                    return false; // the row could not be read
                }
                $u = $GLOBALS['__msc']['users'][$id] ?? null;
                return $u === null ? false : (object) ['ID' => $id, 'user_login' => $u[0], 'display_name' => $u[1]];
            }
            PHP);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__msc'], $GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function testWithNothingFailingBothModesFindTheSameMembers(): void
    {
        self::assertSame([7, 8], self::sorted(MemberSearchCandidates::ids('ali')));
        self::assertSame([7, 8], self::sorted(MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true)));
    }

    public function testFailClosedTurnsTheUserQueryCacheOffForBothLookups(): void
    {
        MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true);

        self::assertCount(2, $GLOBALS['__msc']['calls'], 'the display-name lookup and the handle lookup');
        foreach ($GLOBALS['__msc']['calls'] as $args) {
            self::assertFalse($args['cache_results'] ?? null, 'a failed lookup must not be cached as "nobody matched"');
        }
    }

    /** The /members directory and the @-mention picker: arguments and answer exactly as before. */
    public function testByDefaultAFailedLookupStillAnswersAsBefore(): void
    {
        $GLOBALS['__msc']['fail'] = 'name';

        self::assertSame([7], MemberSearchCandidates::ids('ali'), 'the handle lookup still answers; no throw');
        foreach ($GLOBALS['__msc']['calls'] as $args) {
            self::assertArrayNotHasKey('cache_results', $args);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function failingSteps(): array
    {
        return [
            'the display-name lookup'          => ['name', 'display name lookup'],
            'the users prime (cache_users())'  => ['prime_users', 'user rows prime'],
            'the handle lookup'                => ['handle', 'handle lookup'],
        ];
    }

    /**
     * F-456: cache_users() runs a users query AFTER its usermeta query, and
     * get_userdata() then reads each unprimed row with its own query, so a
     * check at the end of the step saw only the last one. Here the prime
     * fails and the rows read after it succeed, wiping the error.
     */
    public function testAFailedUsersPrimeIsCaughtEvenWhenTheRowReadsAfterItSucceed(): void
    {
        $GLOBALS['__msc']['fail'] = 'prime_users';

        $this->expectException(RepositoryReadFailure::class);
        $this->expectExceptionMessage('ids: user rows prime');
        MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true);
    }

    /**
     * F-456: an unreadable row answers false, exactly like a missing user.
     * Alice's row fails and Bob's read right after it succeeds and clears the
     * error, so only a check at the read itself catches Alice being dropped.
     */
    public function testAFailedRowReadForAnyCandidateIsCaughtNotOnlyTheLast(): void
    {
        $GLOBALS['__msc']['unprimed'] = [7, 8];
        $GLOBALS['__msc']['fail']     = 'row:7';

        $this->expectException(RepositoryReadFailure::class);
        $this->expectExceptionMessage('ids: user row read');
        MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true);
    }

    /** The control for the row check: a user with no row is a genuine miss, dropped without a throw. */
    public function testAUserWithNoRowIsDroppedNotAFailure(): void
    {
        $GLOBALS['__msc']['ghosts'] = [9];

        self::assertSame([7, 8], self::sorted(MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true)));
    }

    /**
     * Not checked, on purpose: cache_users()'s usermeta query. Its users query
     * runs after it and clears the error, and nothing the matcher reads comes
     * from user meta, so the answer is still right.
     */
    public function testAFailedUsermetaPrimeLeavesTheAnswerRight(): void
    {
        $GLOBALS['__msc']['fail'] = 'prime_meta';

        self::assertSame([7, 8], self::sorted(MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true)));
    }

    /** By default (/members, the @-mention picker) a failed row read still just drops the candidate. */
    public function testByDefaultAFailedRowReadStillDropsTheCandidateSilently(): void
    {
        $GLOBALS['__msc']['unprimed'] = [7, 8];
        $GLOBALS['__msc']['fail']     = 'row:7';

        self::assertSame([7, 8], self::sorted(MemberSearchCandidates::ids('ali')), 'Alice is still found by her handle; no throw');
    }

    #[DataProvider('failingSteps')]
    public function testFailClosedThrowsWhenAStepDidNotRun(string $fail, string $step): void
    {
        $GLOBALS['__msc']['fail'] = $fail;

        try {
            MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true);
            self::fail('a lookup that did not run answered as "nobody matched"');
        } catch (RepositoryReadFailure $e) {
            self::assertStringContainsString('ids: ' . $step, $e->getMessage());
            self::assertStringContainsString(self::DB_ERROR, $e->getMessage());
        }
        self::assertNotSame([], \BCC\Core\Log\Logger::$lines, 'the failure is logged, not only thrown');
    }

    /**
     * A lookup answered without SQL (a `users_pre_query` short-circuit) clears
     * nothing, so only the matcher's own reset keeps an earlier, unrelated
     * error from being blamed on it.
     */
    public function testAnErrorLeftByAnEarlierQueryIsNotBlamedOnTheLookup(): void
    {
        $GLOBALS['__msc']['pre_query'] = true;
        $GLOBALS['wpdb']->last_error   = 'an unrelated query failed earlier';

        self::assertSame([7, 8], self::sorted(MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true)));
    }

    public function testNobodyMatchingIsAnAnswerNotAFailure(): void
    {
        self::assertSame([], MemberSearchCandidates::ids('zzz', MemberSearchCandidates::MAX_CANDIDATES, true));
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);
        return $ids;
    }
}
