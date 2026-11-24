<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\MemberSearchCandidates;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Tests\Support\UserMetaWpdb;
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
            // F-472: registered hooks, the lookup to bail, and the ids a
            // bail hands back from the previous statement's column.
            'hooks'    => [],
            'bail'     => null,
            'query_before_bail' => false,
            'requests' => [],
            'stale'    => [],
        ];
        if (!class_exists('BCC\\Core\\Log\\Logger', false)) {
            eval('namespace BCC\\Core\\Log; final class Logger { public static array $lines = []; public static function error(string $m, array $c = []): void { self::$lines[] = $m . " " . json_encode($c); } public static function warning(string $m, array $c = []): void {} public static function info(string $m, array $c = []): void {} }');
        }
        // F-512: the real-name flags are read through bcc-core's REAL
        // UserMetaRepository; this double answers that read from ->meta (no
        // flag rows by default) and keeps `last_error` for the simulated
        // queries below.
        require_once dirname(__DIR__) . '/Support/UserMetaWpdb.php';
        UserMetaWpdb::loadCore();
        $GLOBALS['wpdb'] = new UserMetaWpdb();
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
            /** The WP_User_Query a lookup builds; only `request` matters here. */
            function msc_new_query(): object
            {
                return new class { public string $request = ''; };
            }
            function add_action(string $hook, callable $cb, int $priority = 10, int $accepted = 1): bool
            {
                $GLOBALS['__msc']['hooks'][$hook][] = $cb;
                return true;
            }
            function remove_action(string $hook, callable $cb, int $priority = 10): bool
            {
                foreach ($GLOBALS['__msc']['hooks'][$hook] ?? [] as $i => $one) {
                    if ($one === $cb) {
                        unset($GLOBALS['__msc']['hooks'][$hook][$i]);
                    }
                }
                return true;
            }
            function get_users(array $args = []): array
            {
                $GLOBALS['__msc']['calls'][] = $args;
                $lookup = isset($args['search']) ? 'name' : (($args['meta_key'] ?? '') === 'bcc_handle' ? 'handle' : 'other');

                // prepare_query(): the query object exists and
                // `pre_user_query` fires (class-wp-user-query.php:772),
                // BEFORE `request` is assembled. That ordering is what lets
                // a caller capture the object and still learn, afterwards,
                // whether a statement was ever built.
                $query = msc_new_query();
                foreach ($GLOBALS['__msc']['hooks']['pre_user_query'] ?? [] as $cb) {
                    $cb($query);
                }

                // `users_pre_query` short-circuit (a plugin answering the lookup),
                // applied at :820 BEFORE `request` is assembled: no SQL runs,
                // `request` stays empty, and last_error keeps whatever an
                // earlier query left.
                if (!empty($GLOBALS['__msc']['pre_query'])) {
                    return msc_matches($args, $lookup);
                }

                // query(): the statement is assembled. With
                // `cache_results => false` the cache branch is skipped and
                // :843-847 always reaches get_col(), so from here a
                // statement either runs or the read did not happen.
                $query->request = 'SELECT ID FROM wp_users /* ' . $lookup . ' */';
                // Recorded so a test can reuse the exact string rather than
                // restating it and drifting from this shim.
                $GLOBALS['__msc']['requests'][$lookup] = $query->request;

                // ⚠ The pre-flush bail: built, NOT executed, no error
                // recorded, neither counter touched — and get_col() hands
                // back the PREVIOUS statement's column, so the lookup
                // answers with ids that are not its own.
                if (($GLOBALS['__msc']['bail'] ?? null) === $lookup) {
                    // ⚠ An UNRELATED statement between the caller's capture
                    // and its check, when asked for. Without it both halves
                    // of the caller's test are false and a mutant swapping
                    // && for || still refuses -- it survived until this
                    // existed. With it, a statement HAS run and it is not
                    // this lookup, so each half is load-bearing on its own.
                    if (!empty($GLOBALS['__msc']['query_before_bail'])) {
                        $GLOBALS['wpdb']->num_queries++;
                        $GLOBALS['wpdb']->last_query = 'SELECT 1 /* unrelated */';
                    }

                    return array_map('strval', $GLOBALS['__msc']['stale'] ?? []);
                }

                if (!msc_query($lookup)) {
                    return []; // what wpdb leaves after a failed query: no rows, and the error
                }
                $GLOBALS['wpdb']->num_queries++;
                $GLOBALS['wpdb']->last_query = $query->request;

                return msc_matches($args, $lookup);
            }
            /** @return list<string> */
            function msc_matches(array $args, string $lookup): array
            {
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

    // ── F-472: a lookup whose statement never ran ───────────────────────
    //
    // `last_error` cannot see this and no variant of it could:
    // `wpdb::query()` returns false ABOVE `flush()` on a failed reconnect
    // and on an emptied `query` filter, recording no error. And `get_col()`
    // never returns null, so the lookup answers with the PREVIOUS
    // statement's column — ids that are not its own.
    //
    // ⚠ The counter is only consulted alongside a BUILT request. An unbuilt
    // one means something answered through `users_pre_query`, which these
    // MATCHING lookups accept; see verifiedUserIds()'s docblock, and
    // testAnErrorLeftByAnEarlierQueryIsNotBlamedOnTheLookup above, which is
    // what pins that branch.

    public function testANameLookupThatDidNotRunThrowsRatherThanAnsweringWithAnotherStatementsIds(): void
    {
        $GLOBALS['__msc']['bail']  = 'name';
        // What the previous statement's column would hand back.
        $GLOBALS['__msc']['stale'] = [4242];

        try {
            MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true);
            self::fail('ids from another statement were answered as matches');
        } catch (RepositoryReadFailure $e) {
            self::assertStringContainsString('built but did not run', $e->getMessage());
        }

        // The premise, and why no error check could have caught it.
        self::assertSame('', $GLOBALS['wpdb']->last_error, 'premise: the bail records no error');
    }

    public function testAHandleLookupThatDidNotRunThrows(): void
    {
        $GLOBALS['__msc']['bail']  = 'handle';
        $GLOBALS['__msc']['stale'] = [4242];

        $this->expectException(RepositoryReadFailure::class);
        MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true);
    }

    /**
     * ⭐ The case where a statement DID run and it was not this lookup, so
     * each half of the check is load-bearing on its own.
     *
     * This test exists because a mutation control demanded it: with nothing
     * running between the capture and the check, BOTH halves are false, and
     * a mutant swapping `&&` for `||` still refused — it survived. Here a
     * statement has run, so only "it was not this lookup" refuses, and that
     * mutant now dies.
     *
     * ⚠ The unbuilt-request branch is NOT reached here: `request` was
     * assembled, so this is a built-and-unrun read, not a `users_pre_query`
     * answer. The two must not be conflated — one is a fault, the other is
     * a plugin doing something supported.
     */
    public function testALookupThatDidNotRunIsRefusedEvenWhenSomethingElseQueried(): void
    {
        $GLOBALS['__msc']['bail']              = 'name';
        $GLOBALS['__msc']['stale']             = [4242];
        $GLOBALS['__msc']['query_before_bail'] = true;

        $before = (int) $GLOBALS['wpdb']->num_queries;

        try {
            MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true);
            self::fail('an unrelated statement masked a lookup that never ran');
        } catch (RepositoryReadFailure $e) {
            self::assertStringContainsString('built but did not run', $e->getMessage());
            self::assertStringContainsString('a statement ran: yes', $e->getMessage());
            self::assertStringContainsString('it was this lookup: no', $e->getMessage());
        }

        // The premise of this test: the counter really did move.
        self::assertGreaterThan(
            $before,
            (int) $GLOBALS['wpdb']->num_queries,
            'the premise: an unrelated statement ran'
        );
    }

    /**
     * ⭐ And the mirror case: the IDENTICAL statement ran just before, so
     * the SQL comparison passes on its own and only the counter refuses.
     *
     * Also demanded by a mutation control — dropping the `$ran` requirement
     * survived until this existed, because every other case here has both
     * halves false or only the comparison false.
     *
     * Together with the test above, each half of the check is now pinned
     * by a case where it is the ONLY thing refusing.
     */
    public function testALookupThatDidNotRunIsRefusedEvenWhenTheIdenticalStatementRanBefore(): void
    {
        // One successful pass, to learn the lookup's own SQL from the shim
        // rather than restating it here.
        self::assertSame([7, 8], self::sorted(
            MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true)
        ));
        $nameRequest = (string) ($GLOBALS['__msc']['requests']['name'] ?? '');
        self::assertNotSame('', $nameRequest, 'the premise: the shim recorded the name lookup');

        // Now the preceding statement IS that identical SQL, and the lookup
        // bails: the comparison passes, the counter does not move.
        $GLOBALS['wpdb']->last_query = $nameRequest;
        $GLOBALS['__msc']['bail']    = 'name';
        $GLOBALS['__msc']['stale']   = [4242];
        $before = (int) $GLOBALS['wpdb']->num_queries;

        try {
            MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true);
            self::fail('an identical earlier statement masked a lookup that never ran');
        } catch (RepositoryReadFailure $e) {
            self::assertStringContainsString('a statement ran: no', $e->getMessage());
            self::assertStringContainsString('it was this lookup: yes', $e->getMessage());
        }

        self::assertSame(
            $before,
            (int) $GLOBALS['wpdb']->num_queries,
            'the premise of this test: the counter did NOT move, which is what caught it'
        );
    }

    /**
     * The fail-SAFE arm is unchanged: the same unrun lookup answers as
     * "nobody matched" rather than throwing, which is what the /members
     * directory and the @-mention picker rely on.
     *
     * ⚠ It does NOT answer with the stale ids either — the matcher keeps
     * only ids it can account for.
     */
    public function testByDefaultALookupThatDidNotRunStillAnswersWithoutThrowing(): void
    {
        $GLOBALS['__msc']['bail']  = 'name';
        $GLOBALS['__msc']['stale'] = [4242];

        $ids = MemberSearchCandidates::ids('ali');

        self::assertNotContains(4242, $ids, 'another statement\'s id reached the answer');
        self::assertSame([7], $ids, 'the handle lookup still answers; no throw');
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

    // ── F-512: any truthy real_name_hidden row hides the name ───────────
    //
    // 'ali' matches 7 (Alice Smith, handle alice) by name AND handle, and 8
    // (Bob Alison, handle bobby) by name only, so 8 is the one to watch.

    private static function flags(): UserMetaWpdb
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertInstanceOf(UserMetaWpdb::class, $wpdb);
        return $wpdb;
    }

    /** @return array<string, array{list<string>}> */
    public static function rowsThatHide(): array
    {
        return [
            "'1' then '0'"    => [['1', '0']],
            "'0' then '1'"    => [['0', '1']],
            "'1' only"        => [['1']],
            // Truthy as PrivacySettings::flag() parses it (FILTER_VALIDATE_BOOLEAN).
            "'true' then '0'" => [['true', '0']],
        ];
    }

    /** @param list<string> $rows */
    #[DataProvider('rowsThatHide')]
    public function testAnyTruthyRowKeepsTheNameOutOfSearch(array $rows): void
    {
        self::flags()->meta[8] = ['bcc_privacy_real_name_hidden' => $rows];

        self::assertSame([7], self::sorted(MemberSearchCandidates::ids('ali')));
        self::assertSame([7], self::sorted(MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true)));
    }

    /** @return array<string, array{list<string>}> */
    public static function rowsThatShow(): array
    {
        return [
            'no row'        => [[]],
            "'0' only"      => [['0']],
            "'0' twice"     => [['0', '0']],
            'an empty row'  => [['']],
        ];
    }

    /** @param list<string> $rows */
    #[DataProvider('rowsThatShow')]
    public function testOnlyFalsyRowsKeepTheNameSearchable(array $rows): void
    {
        self::flags()->meta[8] = ['bcc_privacy_real_name_hidden' => $rows];

        self::assertSame([7, 8], self::sorted(MemberSearchCandidates::ids('ali')));
    }

    /** One read for every name match, never one per member. */
    public function testTheFlagsAreReadOnceForAllNameMatches(): void
    {
        MemberSearchCandidates::ids('ali');

        self::assertSame(1, self::flags()->reads);
    }

    /**
     * An unreadable flag read keeps NO name match, so only handle matches
     * remain (7, through "alice"); a caller that opted in gets the throw.
     */
    public function testAnUnreadableFlagReadKeepsOnlyHandleMatches(): void
    {
        self::flags()->failRead = true;

        self::assertSame([7], MemberSearchCandidates::ids('ali'));

        $this->expectException(RepositoryReadFailure::class);
        MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true);
    }

    /** A pre-flush bail with an earlier query's rows still in last_result is unreadable too. */
    public function testAPreFlushBailIsAnUnreadableFlagRead(): void
    {
        self::flags()->last_result     = [];
        self::flags()->bailBeforeFlush = true;

        self::assertSame([7], MemberSearchCandidates::ids('ali'));
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
